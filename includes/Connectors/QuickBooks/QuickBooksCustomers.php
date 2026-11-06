<?php
/**
 * Finds and creates QuickBooks customers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * A customer is found by email (the filter on PrimaryEmailAddr, then an exact case-insensitive comparison, over every page) and created when none
 * exists. QuickBooks wants a DisplayName that is unique across customers, vendors and employees; when it answers error 6240 the customer is created
 * once more with the email after the name.
 */
final class QuickBooksCustomers {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksApi $api Client.
	 */
	public function __construct( private readonly QuickBooksApi $api ) {
	}

	/**
	 * The customer with an email address. In a company that uses more than one currency a customer has one currency that cannot be changed, so a customer is only
	 * the one wanted when its currency is the order's (a customer with no currency is in the home currency).
	 *
	 * @param string      $email    Email.
	 * @param string|null $currency Currency the customer must have, or null to take the customer whatever its currency.
	 * @param string      $home     Home currency of the company (the currency of a customer that names none).
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findByEmail( string $email, ?string $currency = null, string $home = '' ): ?array {
		$wanted = strtolower( trim( $email ) );
		if ( '' === $wanted ) {
			return null;
		}
		try {
			$rows = $this->api->select( 'Customer', 'PrimaryEmailAddr = ' . QuickBooksApi::quote( $wanted ) );
		} catch ( ApiException $exception ) {
			if ( 400 !== $exception->status() ) {
				throw $exception;
			}
			$rows = $this->api->select( 'Customer', 'DisplayName = ' . QuickBooksApi::quote( $email ) );
		}
		foreach ( $rows as $customer ) {
			if ( strtolower( trim( QuickBooksPayloadReader::text( $customer['PrimaryEmailAddr']['Address'] ?? '' ) ) ) !== $wanted ) {
				continue;
			}
			if ( null !== $currency ) {
				$own = strtoupper( QuickBooksPayloadReader::text( $customer['CurrencyRef']['value'] ?? '' ) );
				if ( ( '' !== $own ? $own : strtoupper( $home ) ) !== strtoupper( $currency ) ) {
					continue;
				}
			}
			return $customer;
		}
		return null;
	}

	/**
	 * The customer for a purchase: the one with that email (and currency), or a new one. A new customer is made in the order's currency when the company uses more than
	 * one. A name that is taken (error 6240) is tried again with the email after it, and then with the currency too.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param array<int,string>    $warnings Receives notes about values that were cut.
	 * @param string               $currency Currency of the order.
	 * @param string               $home     Home currency of the company.
	 * @param bool                 $multi    Whether the company uses more than one currency.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function resolve( array $partner, array $delivery, array &$warnings, string $currency = '', string $home = '', bool $multi = false ): array {
		$found = $this->findByEmail( $partner['email'] ?? '', $multi ? $currency : null, $home );
		if ( null !== $found ) {
			return $found;
		}
		$customer = $this->body( $partner, $delivery, $warnings );
		if ( $multi && '' !== $currency ) {
			$customer['CurrencyRef'] = array( 'value' => $currency );
		}
		$email = $partner['email'] ?? '';
		$name  = (string) $customer['DisplayName'];
		$names = array(
			$name,
			QuickBooksPayloadReader::limit( $name, max( 1, 500 - mb_strlen( $email ) - 3 ) ) . ' (' . $email . ')',
		);
		if ( $multi && '' !== $currency ) {
			$names[] = QuickBooksPayloadReader::limit( $name, max( 1, 500 - mb_strlen( $email ) - 8 ) ) . ' (' . $email . ', ' . $currency . ')';
		}
		$last = count( $names ) - 1;
		foreach ( $names as $index => $try ) {
			$customer['DisplayName'] = $try;
			try {
				return $this->create( $customer );
			} catch ( ApiException $exception ) {
				if ( $index === $last || ! QuickBooksErrors::has( $exception->response(), '6240' ) ) {
					throw $exception;
				}
			}
		}
		$failure = QuickBooksErrors::exception( QuickBooksErrors::refused( 'DisplayName', __( 'QuickBooks has no free name for this customer.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		throw $failure;
	}

	/**
	 * Send the create call.
	 *
	 * @param array<string,mixed> $customer Customer.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	private function create( array $customer ): array {
		$id = 'wc-customer-' . substr( sha1( (string) wp_json_encode( $customer ) ), 0, 30 );
		return $this->api->entity( $this->api->post( 'customer', $customer, array(), $id ), 'Customer' );
	}

	/**
	 * The customer as QuickBooks wants it.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param array<int,string>    $warnings Receives notes.
	 * @return array<string,mixed>
	 */
	private function body( array $partner, array $delivery, array &$warnings ): array {
		$cut    = false;
		$person = $partner['name'] ?? trim( ( $partner['first_name'] ?? '' ) . ' ' . ( $partner['last_name'] ?? '' ) );
		$name   = trim( (string) preg_replace( '/[\x00-\x1F:]/', ' ', $partner['company_name'] ?? $person ) );
		$body   = array( 'DisplayName' => QuickBooksPayloadReader::limit( $name, 500, $cut ) );
		if ( isset( $partner['first_name'] ) || isset( $partner['last_name'] ) ) {
			$body['GivenName']  = QuickBooksPayloadReader::limit( $partner['first_name'] ?? '', 25, $cut );
			$body['FamilyName'] = QuickBooksPayloadReader::limit( $partner['last_name'] ?? '', 15, $cut );
		}
		if ( isset( $partner['company_name'] ) ) {
			$body['CompanyName'] = QuickBooksPayloadReader::limit( $partner['company_name'], 255, $cut );
		}
		if ( isset( $partner['email'] ) ) {
			$body['PrimaryEmailAddr'] = array( 'Address' => QuickBooksPayloadReader::limit( $partner['email'], 100, $cut ) );
		}
		if ( isset( $partner['phone'] ) ) {
			$body['PrimaryPhone'] = array( 'FreeFormNumber' => QuickBooksPayloadReader::limit( $partner['phone'], 21, $cut ) );
		}
		$billing = self::address( $partner, $cut );
		if ( null !== $billing ) {
			$body['BillAddr'] = $billing;
		}
		$shipping = self::address( $delivery, $cut );
		if ( null !== $shipping && $shipping !== $billing ) {
			$body['ShipAddr'] = $shipping;
		}
		if ( $cut ) {
			$warnings[] = __( 'A long customer value was shortened to fit QuickBooks.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		return $body;
	}

	/**
	 * An address as QuickBooks wants it, or null when there is nothing in it.
	 *
	 * @param array<string,string> $source Address fields.
	 * @param bool                 $cut    Set to true when a value was shortened.
	 * @return array<string,string>|null
	 */
	public static function address( array $source, bool &$cut ): ?array {
		$address = array();
		$map     = array(
			'street'     => array( 'Line1', 500 ),
			'street2'    => array( 'Line2', 500 ),
			'city'       => array( 'City', 255 ),
			'state_id'   => array( 'CountrySubDivisionCode', 255 ),
			'zip'        => array( 'PostalCode', 30 ),
			'country_id' => array( 'Country', 255 ),
		);
		foreach ( $map as $key => $target ) {
			if ( isset( $source[ $key ] ) ) {
				$address[ $target[0] ] = QuickBooksPayloadReader::limit( $source[ $key ], $target[1], $cut );
			}
		}
		return array() === $address ? null : $address;
	}
}
