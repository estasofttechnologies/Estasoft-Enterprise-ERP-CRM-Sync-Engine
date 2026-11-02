<?php
/**
 * Finds and creates Xero contacts.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * A customer is found by email (case-insensitive, through Xero's searchTerm and then an exact comparison, over every page) and created
 * when none exists. Xero wants a unique contact name; when it says the name is taken the contact is created once more with the email
 * after the name.
 */
final class XeroContacts {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient  $api    Client for the connection.
	 * @param XeroPaging $paging Paging helper.
	 */
	public function __construct( private readonly ApiClient $api, private readonly XeroPaging $paging = new XeroPaging() ) {
	}

	/**
	 * The contact for a customer: the one with that email, or a new one.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param string               $number   Contact number to store on a new contact, or empty.
	 * @param array<int,string>    $warnings Receives notes about values that were cut or left out.
	 * @return array<string,mixed> The contact.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function resolve( array $partner, array $delivery, string $number, array &$warnings ): array {
		$found = $this->findByEmail( $partner['email'] ?? '' );
		if ( null !== $found ) {
			return $found;
		}
		return $this->create( $partner, $delivery, $number, $warnings );
	}

	/**
	 * The contact with an email address.
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
		$best = null;
		foreach ( $this->paging->all( $this->api, 'Contacts', array( 'searchTerm' => $wanted ), 'Contacts' ) as $contact ) {
			if ( strtolower( trim( XeroPayloadReader::text( $contact['EmailAddress'] ?? '' ) ) ) !== $wanted ) {
				continue;
			}
			if ( 'ARCHIVED' === ( $contact['ContactStatus'] ?? '' ) ) {
				continue;
			}
			if ( null === $best || ( ! empty( $contact['IsCustomer'] ) && empty( $best['IsCustomer'] ) ) ) {
				$best = $contact;
			}
		}
		return $best;
	}

	/**
	 * The contact with a contact number (the shop's reference of a customer).
	 *
	 * @param string $number Number, such as WC-12.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findByNumber( string $number ): ?array {
		$clean = str_replace( array( '"', '\\' ), '', $number );
		foreach ( $this->paging->all( $this->api, 'Contacts', array( 'where' => 'ContactNumber=="' . $clean . '"' ), 'Contacts' ) as $contact ) {
			if ( ( $contact['ContactNumber'] ?? '' ) === $number && 'ARCHIVED' !== ( $contact['ContactStatus'] ?? '' ) ) {
				return $contact;
			}
		}
		return null;
	}

	/**
	 * Create a contact.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param string               $number   Contact number to store, or empty.
	 * @param array<int,string>    $warnings Receives notes about values that were cut or left out.
	 * @return array<string,mixed> The new contact.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function create( array $partner, array $delivery, string $number, array &$warnings ): array {
		$contact = $this->body( $partner, $delivery, $number, $warnings );
		try {
			return $this->put( $contact );
		} catch ( ApiException $exception ) {
			$response = $exception->response();
			$messages = null === $response ? array() : XeroErrors::validationMessages( $response->json() );
			if ( ! XeroErrors::nameInUse( $messages ) ) {
				throw $exception;
			}
		}
		$email           = $partner['email'] ?? '';
		$contact['Name'] = XeroPayloadReader::limit( (string) $contact['Name'], max( 1, 255 - mb_strlen( $email ) - 3 ) ) . ' (' . $email . ')';
		return $this->put( $contact );
	}

	/**
	 * Send the create call.
	 *
	 * @param array<string,mixed> $contact Contact.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	private function put( array $contact ): array {
		$key      = 'wc-contact-' . substr( sha1( (string) wp_json_encode( $contact ) ), 0, 32 );
		$response = $this->api->put( 'Contacts', array( 'summarizeErrors' => 'true' ), array( 'Contacts' => array( $contact ) ), array( 'idempotency_key' => $key ) );
		return XeroAnswer::element( $response, 'Contacts' );
	}

	/**
	 * The contact as Xero wants it.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param string               $number   Contact number.
	 * @param array<int,string>    $warnings Receives notes.
	 * @return array<string,mixed>
	 */
	private function body( array $partner, array $delivery, string $number, array &$warnings ): array {
		$cut     = false;
		$person  = $partner['name'] ?? trim( ( $partner['first_name'] ?? '' ) . ' ' . ( $partner['last_name'] ?? '' ) );
		$name    = XeroPayloadReader::limit( $partner['company_name'] ?? $person, 255, $cut );
		$contact = array( 'Name' => $name );
		if ( isset( $partner['first_name'] ) || isset( $partner['last_name'] ) ) {
			$contact['FirstName'] = XeroPayloadReader::limit( $partner['first_name'] ?? '', 255, $cut );
			$contact['LastName']  = XeroPayloadReader::limit( $partner['last_name'] ?? '', 255, $cut );
		}
		$email = $partner['email'] ?? '';
		if ( '' !== $email ) {
			if ( 1 === preg_match( '/^[\x20-\x7E]+$/', $email ) ) {
				$contact['EmailAddress'] = XeroPayloadReader::limit( $email, 255, $cut );
			} else {
				$warnings[] = __( 'The email address has letters Xero does not accept, so the contact was saved without it.', 'estasoft-erp-crm-sync-engine' );
			}
		}
		if ( '' !== $number ) {
			$contact['ContactNumber'] = XeroPayloadReader::limit( $number, 50, $cut );
		}
		if ( isset( $partner['vat'] ) ) {
			$contact['TaxNumber'] = XeroPayloadReader::limit( $partner['vat'], 50, $cut );
		}
		if ( isset( $partner['phone'] ) ) {
			$contact['Phones'] = array(
				array(
					'PhoneType'   => 'DEFAULT',
					'PhoneNumber' => XeroPayloadReader::limit( $partner['phone'], 50, $cut ),
				),
			);
		}
		$addresses = array();
		$billing   = $this->address( 'POBOX', $partner, $cut );
		if ( null !== $billing ) {
			$addresses[] = $billing;
		}
		$shipping = $this->address( 'STREET', $delivery, $cut );
		if ( null !== $shipping && ( null === $billing || array_diff_assoc( $shipping, array( 'AddressType' => 'STREET' ) ) !== array_diff_assoc( $billing, array( 'AddressType' => 'POBOX' ) ) ) ) {
			$addresses[] = $shipping;
		}
		if ( array() !== $addresses ) {
			$contact['Addresses'] = $addresses;
		}
		if ( $cut ) {
			$warnings[] = __( 'A long customer value was shortened to fit Xero.', 'estasoft-erp-crm-sync-engine' );
		}
		return $contact;
	}

	/**
	 * One address block, or null when there is nothing to put in it.
	 *
	 * @param string               $type    POBOX or STREET.
	 * @param array<string,string> $source  Address fields.
	 * @param bool                 $cut     Set to true when a value was shortened.
	 * @return array<string,string>|null
	 */
	private function address( string $type, array $source, bool &$cut ): ?array {
		$address = array( 'AddressType' => $type );
		$map     = array(
			'street'   => array( 'AddressLine1', 500 ),
			'street2'  => array( 'AddressLine2', 500 ),
			'city'     => array( 'City', 255 ),
			'state_id' => array( 'Region', 255 ),
			'zip'      => array( 'PostalCode', 50 ),
		);
		foreach ( $map as $key => $target ) {
			if ( isset( $source[ $key ] ) ) {
				$address[ $target[0] ] = XeroPayloadReader::limit( $source[ $key ], $target[1], $cut );
			}
		}
		if ( isset( $source['country_id'] ) ) {
			$country = preg_replace( '/[^A-Za-z]/', '', $source['country_id'] ) ?? '';
			if ( '' !== $country ) {
				$address['Country'] = substr( $country, 0, 50 );
			}
		}
		return array() === array_diff_key( $address, array( 'AddressType' => true ) ) ? null : $address;
	}
}
