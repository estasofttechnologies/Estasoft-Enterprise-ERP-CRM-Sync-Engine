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
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * No email filter is documented for contacts, so a contact is found through the contact index (`HoldedIndex`): the contact list read page by
 * page, kept between requests, and matched on the email, case-insensitively. A finished index is trusted for a missing email for the hour it
 * lives, because the connector adds every contact it creates and a new customer is the usual case; a contact made in Holded by hand in that hour
 * may therefore be made a second time. If the answer to a create is lost, the index is dropped so the retry reads the list again and finds the
 * contact.
 */
final class HoldedContacts {

	/**
	 * Name of the contact index.
	 */
	public const INDEX = 'contacts';

	/**
	 * Path of the contact list.
	 */
	private const PATH = 'invoicing/v1/contacts';

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedPager $pager Checks answers.
	 * @param HoldedIndex $index Persistent indexes.
	 * @param HoldedQuota $quota Call quota.
	 */
	public function __construct( private readonly HoldedPager $pager, private readonly HoldedIndex $index, private readonly HoldedQuota $quota ) {
	}

	/**
	 * Find the contact of an email address.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $email        Email address.
	 * @return array{id:string,name:string,email:string}|null The contact, or null.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, int $connectionId, string $email ): ?array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		$value = $this->index->find(
			$api,
			$connectionId,
			self::INDEX,
			self::PATH,
			static function ( array $row ): ?array {
				$mail = strtolower( HoldedPayloadReader::text( $row['email'] ?? '' ) );
				$id   = HoldedPayloadReader::text( $row['id'] ?? '' );
				if ( '' === $mail || '' === $id ) {
					return null;
				}
				return array( $mail, $id . "\n" . HoldedPayloadReader::shorten( str_replace( array( "\r", "\n" ), ' ', HoldedPayloadReader::text( $row['name'] ?? '' ) ), 80 ) );
			},
			$email,
			HoldedIndex::TTL
		);
		if ( null === $value ) {
			return null;
		}
		$parts = explode( "\n", $value, 2 );
		return array(
			'id'    => $parts[0],
			'name'  => $parts[1] ?? '',
			'email' => $email,
		);
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
			$response = $api->post( self::PATH, array(), $contact );
		} catch ( ApiException $exception ) {
			// The answer may have been lost after Holded made the contact: forget the kept index so the retry reads the list again and finds it.
			$this->index->forget( $connectionId, self::INDEX );
			throw $exception;
		}
		$this->quota->note( $api, $response );
		$json = $response->json();
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
		$this->index->add( $connectionId, self::INDEX, strtolower( $customer['email'] ), $id . "\n" . HoldedPayloadReader::shorten( str_replace( array( "\r", "\n" ), ' ', $name ), 80 ) );
		return $id;
	}
}
