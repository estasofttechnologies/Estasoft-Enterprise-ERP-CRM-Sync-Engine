<?php
/**
 * Finds and creates Wafeq contacts.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Wafeq documents no email filter on contacts. A customer is found by `external_id` (a hash of the email, written by this connector) and,
 * failing that, by the `keyword` search, whose results are checked locally for an exact, case-insensitive email. Every page is read.
 */
final class WafeqContacts {

	/**
	 * Set up with what it depends on.
	 *
	 * @param WafeqPager $pager Reads every page of a list.
	 * @param WafeqIds   $ids   References and keys.
	 */
	public function __construct( private readonly WafeqPager $pager, private readonly WafeqIds $ids ) {
	}

	/**
	 * Find the contact of an email address.
	 *
	 * @param ApiClient $api   Client.
	 * @param string    $email Email address.
	 * @return array<string,mixed>|null The contact, or null.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, string $email ): ?array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		$external = $this->ids->contactExternalId( $email );
		foreach ( $this->pager->all( $api, 'contacts/', array( 'external_id' => $external ) ) as $row ) {
			if ( WafeqPayloadReader::text( $row['external_id'] ?? '' ) === $external ) {
				return $row;
			}
		}
		foreach ( $this->pager->all( $api, 'contacts/', array( 'keyword' => $email ) ) as $row ) {
			if ( strtolower( WafeqPayloadReader::text( $row['email'] ?? '' ) ) === $email ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The id of the contact of a customer: the existing one, or a new one.
	 *
	 * @param ApiClient            $api      Client.
	 * @param Item                 $item     Queue item (for the idempotency key).
	 * @param array<string,string> $customer Customer fields from the payload.
	 * @return string|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( ApiClient $api, Item $item, array $customer ): string|ConnectorError {
		$existing = $this->find( $api, $customer['email'] );
		if ( null !== $existing ) {
			$id = WafeqPayloadReader::text( $existing['id'] ?? '' );
			if ( '' !== $id ) {
				return $id;
			}
		}
		return $this->create( $api, $item, $customer );
	}

	/**
	 * Create a contact.
	 *
	 * @param ApiClient            $api      Client.
	 * @param Item                 $item     Queue item.
	 * @param array<string,string> $customer Customer fields.
	 * @return string|ConnectorError The new id.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( ApiClient $api, Item $item, array $customer ): string|ConnectorError {
		$name = '' !== $customer['company'] ? $customer['company'] : $customer['name'];
		$body = array(
			'name'         => WafeqPayloadReader::shorten( $name, 200 ),
			'email'        => $customer['email'],
			'external_id'  => $this->ids->contactExternalId( $customer['email'] ),
			'party_type'   => '' !== $customer['company'] || '' !== $customer['tax_number'] ? 'business' : 'individual',
			'relationship' => array( 'customer' ),
		);
		$map  = array(
			'phone'      => 'phone',
			'address'    => 'address',
			'city'       => 'city',
			'postcode'   => 'postal_code',
			'tax_number' => 'tax_registration_number',
		);
		foreach ( $map as $from => $to ) {
			if ( '' !== $customer[ $from ] ) {
				$body[ $to ] = $customer[ $from ];
			}
		}
		if ( 1 === preg_match( '/^[A-Z]{2}$/', $customer['country'] ) ) {
			$body['country'] = $customer['country'];
		}
		$response = $api->post( 'contacts/', array(), $body, array( 'idempotency_key' => $this->ids->idempotencyKey( $item, 'contact' ) ) );
		$id       = WafeqPayloadReader::text( $response->json()['id'] ?? '' );
		if ( '' === $id ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => WafeqConnector::LABEL,
					'field'  => 'contact',
					'reason' => __( 'Wafeq did not return the id of the new contact.', 'estasoft-erp-crm-sync-engine' ),
				),
				null,
				null,
				null,
				'contact without id'
			);
		}
		return $id;
	}
}
