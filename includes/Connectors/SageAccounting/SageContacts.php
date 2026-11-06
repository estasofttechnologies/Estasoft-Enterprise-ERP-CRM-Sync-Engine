<?php
/**
 * Customer contacts in Sage.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * A customer is found with the contacts search (text) and an exact comparison of the email or the reference: Sage's email filter looks at contact people
 * only [third-party], so the connector compares itself. A contact the connector creates carries the email as its reference (when it is 50 characters or fewer), so
 * the next search finds it. An invoice never goes to a different customer than the email says.
 */
final class SageContacts {

	/**
	 * Longest reference the connector writes.
	 */
	private const REFERENCE_LENGTH = 50;

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageApi $api Client.
	 */
	public function __construct( private readonly SageApi $api ) {
	}

	/**
	 * The customer with an email address.
	 *
	 * @param string $email Email.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findByEmail( string $email ): ?array {
		$wanted = strtolower( trim( $email ) );
		if ( '' === $wanted ) {
			return null;
		}
		foreach ( $this->api->select(
			'contacts',
			array(
				'search'          => $email,
				'contact_type_id' => 'CUSTOMER',
			)
		) as $contact ) {
			if ( strtolower( trim( SagePayloadReader::text( $contact['email'] ?? '' ) ) ) === $wanted || strtolower( trim( SagePayloadReader::text( $contact['reference'] ?? '' ) ) ) === $wanted ) {
				return $contact;
			}
		}
		return null;
	}

	/**
	 * The customer for a purchase: the one with that email, or a new one.
	 *
	 * @param array<string,string> $partner    Billing customer fields.
	 * @param array<string,string> $delivery   Delivery address fields.
	 * @param string               $idempotent Idempotency id for the create.
	 * @param array<int,string>    $warnings   Receives notes about values that were cut.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function resolve( array $partner, array $delivery, string $idempotent, array &$warnings ): array {
		$found = $this->findByEmail( $partner['email'] ?? '' );
		if ( null !== $found ) {
			return $found;
		}
		$body                   = $this->body( $partner, $delivery, $warnings );
		$body['idempotency_id'] = '' !== $idempotent ? $idempotent : md5( (string) wp_json_encode( $body ) );
		return $this->api->entity( $this->api->post( 'contacts', array( 'contact' => $body ) ), 'contact' );
	}

	/**
	 * The contact as Sage wants it.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param array<int,string>    $warnings Receives notes.
	 * @return array<string,mixed>
	 */
	private function body( array $partner, array $delivery, array &$warnings ): array {
		$cut   = false;
		$name  = '' !== ( $partner['company_name'] ?? '' ) ? $partner['company_name'] : ( $partner['name'] ?? '' );
		$body  = array(
			'contact_type_ids' => array( 'CUSTOMER' ),
			'name'             => SagePayloadReader::limit( $name, 200, $cut ),
		);
		$email = $partner['email'] ?? '';
		if ( '' !== $email ) {
			$body['email'] = SagePayloadReader::limit( $email, 255, $cut );
			if ( mb_strlen( $email ) <= self::REFERENCE_LENGTH ) {
				$body['reference'] = $email;
			}
		}
		$main = self::address( $partner, 'ACCOUNTS', $cut );
		if ( array() !== $main ) {
			$body['main_address'] = $main;
		}
		$ship = self::address( $delivery, 'DELIVERY', $cut );
		if ( array() !== $ship && array_diff_key( $ship, array( 'address_type_id' => true ) ) !== array_diff_key( $main, array( 'address_type_id' => true ) ) ) {
			$body['delivery_address'] = $ship;
		}
		if ( $cut ) {
			$warnings[] = __( 'A long customer value was shortened to fit Sage.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		return $body;
	}

	/**
	 * An address as Sage wants it.
	 *
	 * @param array<string,string> $source Address fields.
	 * @param string               $type   ACCOUNTS or DELIVERY.
	 * @param bool                 $cut    Set to true when a value was shortened.
	 * @return array<string,string> Empty when there is nothing in it.
	 */
	public static function address( array $source, string $type, bool &$cut ): array {
		$map     = array(
			'street'     => array( 'address_line_1', 50 ),
			'street2'    => array( 'address_line_2', 50 ),
			'city'       => array( 'city', 50 ),
			'state_id'   => array( 'region', 50 ),
			'zip'        => array( 'postal_code', 20 ),
			'country_id' => array( 'country_group_id', 50 ),
		);
		$address = array();
		foreach ( $map as $key => $target ) {
			if ( isset( $source[ $key ] ) ) {
				$address[ $target[0] ] = SagePayloadReader::limit( $source[ $key ], $target[1], $cut );
			}
		}
		return array() === $address ? array() : array( 'address_type_id' => $type ) + $address;
	}
}
