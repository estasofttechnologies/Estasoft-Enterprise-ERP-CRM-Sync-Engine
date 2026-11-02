<?php
/**
 * Customer cards in MYOB.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * A customer is found by the email of one of its addresses. MYOB's pages show no email filter, so the filter is tried first
 * (Addresses/any(a: a/Email eq '...')); when MYOB says it cannot run it (400 or 501) the connector reads the customer list page by page, up to
 * twenty pages, and compares the email itself. An invoice never goes to a different customer than the email says.
 */
final class MyobCustomers {

	/**
	 * Pages read when the filter is not available (20,000 customers).
	 */
	public const SCAN_PAGES = 20;

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobApi $api Client.
	 */
	public function __construct( private readonly MyobApi $api ) {
	}

	/**
	 * The customer card with an email address.
	 *
	 * @param string $email Email.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call, or when the list is too long to search without the filter.
	 */
	public function findByEmail( string $email ): ?array {
		$wanted = strtolower( trim( $email ) );
		if ( '' === $wanted ) {
			return null;
		}
		try {
			$rows = $this->api->select( 'Contact/Customer', 'Addresses/any(a: a/Email eq ' . MyobApi::quote( $email ) . ')', 1 );
		} catch ( ApiException $exception ) {
			if ( ! in_array( $exception->status(), array( 400, 501 ), true ) ) {
				throw $exception;
			}
			return $this->scan( $wanted );
		}
		return self::pick( $rows, $wanted );
	}

	/**
	 * The customer for a purchase: the one with that email, or a new one.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param array<int,string>    $warnings Receives notes about values that were cut.
	 * @return array<string,mixed> The card, with its UID.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function resolve( array $partner, array $delivery, array &$warnings ): array {
		$found = $this->findByEmail( $partner['email'] ?? '' );
		if ( null !== $found ) {
			return $found;
		}
		$response = $this->api->post( 'Contact/Customer', $this->body( $partner, $delivery, $warnings ) );
		$uid      = MyobApi::uidFromLocation( $response );
		if ( '' === $uid ) {
			$failure = MyobErrors::exception( MyobErrors::refused( 'Customer', __( 'MYOB did not say where the new customer is.', 'estasoft-erp-crm-sync-engine' ), $response->status() ), $response );
			throw $failure;
		}
		return $this->card( $uid );
	}

	/**
	 * One customer card.
	 *
	 * @param string $uid UID.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function card( string $uid ): array {
		$data = $this->api->get( 'Contact/Customer/' . rawurlencode( $uid ) )->json();
		return isset( $data['UID'] ) ? $data : array( 'UID' => $uid );
	}

	/**
	 * Read the list page by page and compare the emails.
	 *
	 * @param string $wanted Lower-case email.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException When the list has more pages than are read.
	 */
	private function scan( string $wanted ): ?array {
		for ( $page = 0; $page < self::SCAN_PAGES; ++$page ) {
			$rows  = MyobApi::items(
				$this->api->get(
					'Contact/Customer',
					array(
						'$top'  => MyobApi::PAGE_SIZE,
						'$skip' => $page * MyobApi::PAGE_SIZE,
					)
				)
			);
			$match = self::pick( $rows, $wanted );
			if ( null !== $match ) {
				return $match;
			}
			if ( count( $rows ) < MyobApi::PAGE_SIZE ) {
				return null;
			}
		}
		$failure = MyobErrors::exception( MyobErrors::refused( 'Customer', __( 'The company file has too many customers to search by email without a filter. Ask MYOB support to enable it, or link the customer by hand.', 'estasoft-erp-crm-sync-engine' ) ) );
		throw $failure;
	}

	/**
	 * The card among some rows whose address has the email.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows.
	 * @param string                         $wanted Lower-case email.
	 * @return array<string,mixed>|null
	 */
	private static function pick( array $rows, string $wanted ): ?array {
		foreach ( $rows as $card ) {
			foreach ( MyobPayloadReader::rows( $card['Addresses'] ?? null ) as $address ) {
				if ( strtolower( trim( MyobPayloadReader::text( $address['Email'] ?? '' ) ) ) === $wanted ) {
					return $card;
				}
			}
		}
		return null;
	}

	/**
	 * The customer card as MYOB wants it.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param array<int,string>    $warnings Receives notes.
	 * @return array<string,mixed>
	 */
	private function body( array $partner, array $delivery, array &$warnings ): array {
		$cut  = false;
		$body = array();
		if ( isset( $partner['company_name'] ) ) {
			$body['IsIndividual'] = false;
			$body['CompanyName']  = MyobPayloadReader::limit( $partner['company_name'], 50, $cut );
		} else {
			$first = $partner['first_name'] ?? '';
			$last  = $partner['last_name'] ?? '';
			if ( '' === $first && '' === $last ) {
				$parts = preg_split( '/\s+/', trim( $partner['name'] ?? '' ) );
				$parts = false === $parts ? array() : $parts;
				$last  = (string) array_pop( $parts );
				$first = implode( ' ', $parts );
			}
			$body['IsIndividual'] = true;
			$body['LastName']     = MyobPayloadReader::limit( $last, 30, $cut );
			if ( '' !== $first ) {
				$body['FirstName'] = MyobPayloadReader::limit( $first, 20, $cut );
			}
		}
		$addresses = array();
		$billing   = self::address( $partner, 1, $cut );
		if ( array() !== $billing ) {
			$addresses[] = $billing;
		}
		$shipping = self::address( $delivery, 2, $cut );
		$place    = array( 'Street', 'City', 'State', 'PostCode', 'Country' );
		if ( array() !== $shipping && array_intersect_key( $shipping, array_flip( $place ) ) !== array_intersect_key( $billing, array_flip( $place ) ) ) {
			$addresses[] = $shipping;
		}
		if ( array() !== $addresses ) {
			$body['Addresses'] = $addresses;
		}
		if ( $cut ) {
			$warnings[] = __( 'A long customer value was shortened to fit MYOB.', 'estasoft-erp-crm-sync-engine' );
		}
		return $body;
	}

	/**
	 * One address of the card.
	 *
	 * @param array<string,string> $source   Address fields.
	 * @param int                  $location 1 for the first address, 2 for the second.
	 * @param bool                 $cut      Set to true when a value was shortened.
	 * @return array<string,mixed> Empty when there is nothing in it. The first address also carries the phone, the email and the contact name.
	 */
	public static function address( array $source, int $location, bool &$cut ): array {
		$map     = array(
			'street'     => array( 'Street', 255 ),
			'city'       => array( 'City', 255 ),
			'state_id'   => array( 'State', 255 ),
			'zip'        => array( 'PostCode', 11 ),
			'country_id' => array( 'Country', 255 ),
		);
		$address = array();
		foreach ( $map as $key => $target ) {
			if ( isset( $source[ $key ] ) ) {
				$address[ $target[0] ] = MyobPayloadReader::limit( $source[ $key ], $target[1], $cut );
			}
		}
		if ( isset( $source['street2'] ) && isset( $address['Street'] ) ) {
			$address['Street'] = MyobPayloadReader::limit( $address['Street'] . "\n" . $source['street2'], 255, $cut );
		}
		if ( 1 === $location ) {
			if ( isset( $source['phone'] ) ) {
				$address['Phone1'] = MyobPayloadReader::limit( $source['phone'], 21, $cut );
			}
			if ( isset( $source['email'] ) ) {
				$address['Email'] = MyobPayloadReader::limit( $source['email'], 255, $cut );
			}
			if ( isset( $source['name'] ) ) {
				$address['ContactName'] = MyobPayloadReader::limit( $source['name'], 25, $cut );
			}
		}
		return array() === $address ? array() : array( 'Location' => $location ) + $address;
	}
}
