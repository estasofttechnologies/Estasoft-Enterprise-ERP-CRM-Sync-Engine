<?php
/**
 * Finds and creates the Holded contact of a customer.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * No email filter is documented for contacts, so the contact list is read page by page, kept for ten minutes in memory and matched on the
 * email, case-insensitively. A contact created here is added to the kept list, so the next order of the same customer does not create it twice.
 */
final class HoldedContacts {

	/**
	 * Seconds a contact list is kept.
	 */
	public const TTL = 600;

	/**
	 * Contacts by connection: [expires at, contacts by lower-case email].
	 *
	 * @var array<int,array{0:int,1:array<string,array<string,mixed>>}>
	 */
	private array $contacts = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedPager $pager Reads every page of a list.
	 * @param Clock       $clock Time source.
	 */
	public function __construct( private readonly HoldedPager $pager, private readonly Clock $clock ) {
	}

	/**
	 * Find the contact of an email address.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $email        Email address.
	 * @return array<string,mixed>|null The contact, or null.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, int $connectionId, string $email ): ?array {
		$email = strtolower( trim( $email ) );
		return '' === $email ? null : ( $this->all( $api, $connectionId )[ $email ] ?? null );
	}

	/**
	 * The id of the contact: the existing one, or a new one.
	 *
	 * @param ApiClient            $api          Client.
	 * @param int                  $connectionId Connection id.
	 * @param array<string,string> $customer     Customer fields from the payload.
	 * @return string|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( ApiClient $api, int $connectionId, array $customer ): string|ConnectorError {
		$existing = $this->find( $api, $connectionId, $customer['email'] );
		if ( null !== $existing && '' !== HoldedPayloadReader::text( $existing['id'] ?? '' ) ) {
			return HoldedPayloadReader::text( $existing['id'] );
		}
		return $this->create( $api, $connectionId, $customer );
	}

	/**
	 * Create a contact.
	 *
	 * @param ApiClient            $api          Client.
	 * @param int                  $connectionId Connection id.
	 * @param array<string,string> $customer     Customer fields.
	 * @return string|ConnectorError The new id.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( ApiClient $api, int $connectionId, array $customer ): string|ConnectorError {
		$name    = '' !== $customer['company'] ? $customer['company'] : $customer['name'];
		$contact = array(
			'name'  => HoldedPayloadReader::shorten( $name, 200 ),
			'email' => $customer['email'],
			'type'  => 'client',
		);
		if ( '' !== $customer['phone'] ) {
			$contact['phone'] = $customer['phone'];
		}
		if ( '' !== $customer['tax_number'] ) {
			$contact['code'] = $customer['tax_number'];
		}
		$address = array_filter(
			array(
				'address'     => $customer['address'],
				'city'        => $customer['city'],
				'province'    => $customer['state'],
				'postalCode'  => $customer['postcode'],
				'countryCode' => 1 === preg_match( '/^[A-Z]{2}$/', $customer['country'] ) ? $customer['country'] : '',
			),
			static fn( string $value ): bool => '' !== $value
		);
		if ( array() !== $address ) {
			$contact['billAddress'] = $address;
		}
		try {
			$json = $api->post( 'invoicing/v1/contacts', array(), $contact )->json();
		} catch ( ApiException $exception ) {
			// The answer may have been lost after Holded made the contact: forget the kept list so the retry reads it again and finds the contact.
			unset( $this->contacts[ $connectionId ] );
			throw $exception;
		}
		$this->pager->guard( $json );
		$id = HoldedPayloadReader::text( $json['id'] ?? '' );
		if ( '' === $id ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => HoldedConnector::LABEL,
					'field'  => 'contact',
					'reason' => __( 'Holded did not return the id of the new contact.', 'estasoft-erp-crm-sync-engine' ),
				),
				null,
				null,
				null,
				'contact without id'
			);
		}
		if ( isset( $this->contacts[ $connectionId ] ) ) {
			$this->contacts[ $connectionId ][1][ strtolower( $customer['email'] ) ] = array(
				'id'    => $id,
				'name'  => $name,
				'email' => $customer['email'],
			);
		}
		return $id;
	}

	/**
	 * The contact list by lower-case email.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @return array<string,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	private function all( ApiClient $api, int $connectionId ): array {
		$now = $this->clock->now()->getTimestamp();
		if ( isset( $this->contacts[ $connectionId ] ) && $this->contacts[ $connectionId ][0] > $now ) {
			return $this->contacts[ $connectionId ][1];
		}
		$index = array();
		foreach ( $this->pager->all( $api, 'invoicing/v1/contacts' ) as $row ) {
			$email = strtolower( HoldedPayloadReader::text( $row['email'] ?? '' ) );
			if ( '' !== $email && ! isset( $index[ $email ] ) ) {
				$index[ $email ] = $row;
			}
		}
		$this->contacts[ $connectionId ] = array( $now + self::TTL, $index );
		return $index;
	}
}
