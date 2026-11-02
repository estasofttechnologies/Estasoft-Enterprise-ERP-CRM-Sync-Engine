<?php
/**
 * Finds and creates Sage 200 customers, their contacts and delivery addresses.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\Text;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * A customer is found through the contact that holds the buyer's email. A new customer gets a reference of at most eight characters (the
 * account reference length of Sage 200 `[inferred]`) made from a prefix and the order number, so a pass that stopped half way finds the
 * customer it made instead of making another.
 */
final class Sage200Customers {

	/**
	 * Account reference length.
	 */
	private const REFERENCE_MAX = 8;

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage200Client  $client  Client.
	 * @param Sage200Lookups $lookups Setup lookups.
	 * @param Options        $options Connection options.
	 * @param string         $system  System name.
	 */
	public function __construct( private readonly Sage200Client $client, private readonly Sage200Lookups $lookups, private readonly Options $options, private readonly string $system ) {
	}

	/**
	 * A customer by the email of one of its contacts, ignoring case. A customer that is not active is skipped.
	 *
	 * @param string $email Email address.
	 * @return array{id:string,name:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $email ): ?array {
		$wanted = strtolower( trim( $email ) );
		if ( '' === $wanted ) {
			return null;
		}
		foreach ( $this->client->rows( 'customer_contacts', 'default_email eq ' . Sage200Client::literal( trim( $email ) ), 50 ) as $contact ) {
			if ( strtolower( trim( (string) ( $contact['default_email'] ?? '' ) ) ) !== $wanted || ! isset( $contact['customer_id'] ) || ! is_scalar( $contact['customer_id'] ) ) {
				continue;
			}
			$customer = $this->client->find( 'customers', (string) $contact['customer_id'] );
			if ( null !== $customer && $this->active( $customer ) ) {
				return array(
					'id'   => (string) $customer['id'],
					'name' => trim( (string) ( $customer['name'] ?? '' ) ),
				);
			}
		}
		return null;
	}

	/**
	 * The customer, and the delivery address when it differs, for a buyer.
	 *
	 * @param array<string,string> $partner  Billing customer fields (email required).
	 * @param array<string,string> $delivery Delivery address fields, or an empty array.
	 * @param string               $seed     Text with the order or customer number, for the reference of a new customer.
	 * @return array{id:string,name:string,created:bool,delivery:string}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( array $partner, array $delivery, string $seed ): array|ConnectorError {
		$policy = $this->options->choice( 'customer_policy', array( 'match_or_create', 'match_only' ), 'match_or_create' );
		$found  = $this->find( $partner['email'] ?? '' );
		$made   = false;
		if ( null === $found ) {
			if ( 'match_only' === $policy ) {
				return Problems::refused(
					$this->system,
					'partner.email',
					__( 'No customer has this email address and the customer setting is "match only". Create the customer or change the setting.', 'estasoft-erp-crm-sync-engine' )
				);
			}
			$created = $this->create( $partner, $seed );
			if ( $created instanceof ConnectorError ) {
				return $created;
			}
			$found = $created;
			$made  = true;
		}
		$address = '';
		if ( $this->differs( $partner, $delivery ) ) {
			$address = $this->deliveryAddress( $found['id'], $delivery, $partner );
		}
		return $found + array(
			'created'  => $made,
			'delivery' => $address,
		);
	}

	/**
	 * Create the customer (or take the one a stopped pass made) and its contact.
	 *
	 * @param array<string,string> $partner Billing customer fields.
	 * @param string               $seed    Text with the order or customer number.
	 * @return array{id:string,name:string}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( array $partner, string $seed ): array|ConnectorError {
		$reference = $this->reference( $seed );
		$name      = '' !== ( $partner['company_name'] ?? '' ) ? $partner['company_name'] : ( $partner['name'] ?? '' );
		$customer  = $this->client->first( 'customers', 'reference eq ' . Sage200Client::literal( $reference ) );
		if ( null === $customer ) {
			$body     = array(
				'reference'  => $reference,
				'name'       => Text::fit( $name, 60 ),
				'short_name' => Text::fit( $name, 15 ),
				'address_1'  => $partner['street'] ?? '',
				'city'       => $partner['city'] ?? '',
				'county'     => $partner['state'] ?? '',
				'postcode'   => $partner['zip'] ?? '',
			);
			$currency = $this->lookups->baseCurrencyId();
			if ( '' !== $currency ) {
				$body['currency_id'] = Sage200Client::id( $currency );
			}
			if ( '' !== $this->options->text( 'country_code_id' ) ) {
				$body['country_code_id'] = Sage200Client::id( $this->options->text( 'country_code_id' ) );
			}
			$tax = $this->defaultTax();
			if ( $tax instanceof ConnectorError ) {
				return $tax;
			}
			if ( '' !== $tax ) {
				$body['default_tax_code_id'] = Sage200Client::id( $tax );
			}
			$stored   = $this->client->create( 'customers', array_filter( $body, static fn( mixed $value ): bool => '' !== $value ) );
			$customer = isset( $stored['id'] ) ? $stored : $this->client->first( 'customers', 'reference eq ' . Sage200Client::literal( $reference ) );
		}
		if ( null === $customer || ! isset( $customer['id'] ) || ! is_scalar( $customer['id'] ) ) {
			return Problems::refused( $this->system, 'customer', __( 'Sage 200 did not return the new customer.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$this->client->create(
			'customer_contacts',
			array_filter(
				array(
					'customer_id'       => Sage200Client::id( (string) $customer['id'] ),
					'name'              => Text::fit( $partner['name'] ?? $name, 60 ),
					'default_email'     => $partner['email'] ?? '',
					'default_telephone' => $partner['phone'] ?? '',
				),
				static fn( mixed $value ): bool => '' !== $value
			)
		);
		return array(
			'id'   => (string) $customer['id'],
			'name' => $name,
		);
	}

	/**
	 * The id of the tax code named in the option `default_tax_code`, or an empty string when none is set.
	 *
	 * @throws ApiException On failure.
	 */
	private function defaultTax(): string|ConnectorError {
		$code = $this->options->text( 'default_tax_code' );
		if ( '' === $code ) {
			return '';
		}
		$taxes = $this->lookups->taxCodes();
		return isset( $taxes[ strtolower( $code ) ] ) ? $taxes[ strtolower( $code ) ]['id'] : Problems::missingTax( $this->system, $code );
	}

	/**
	 * The delivery address of a customer: the one that already matches, or a new one.
	 *
	 * @param string               $customerId Customer id.
	 * @param array<string,string> $delivery   Delivery fields.
	 * @param array<string,string> $partner    Billing fields, for the name.
	 * @return string The address id, or an empty string when the address is too short to store.
	 *
	 * @throws ApiException On failure.
	 */
	private function deliveryAddress( string $customerId, array $delivery, array $partner ): string {
		$line = $delivery['street'] ?? '';
		if ( '' === $line ) {
			return '';
		}
		foreach ( $this->client->rows( 'customer_delivery_addresses', 'customer_id eq ' . $customerId, 100 ) as $row ) {
			if ( strtolower( trim( (string) ( $row['address_1'] ?? '' ) ) ) === strtolower( $line ) && strtolower( trim( (string) ( $row['postcode'] ?? '' ) ) ) === strtolower( $delivery['zip'] ?? '' ) && isset( $row['id'] ) && is_scalar( $row['id'] ) ) {
				return (string) $row['id'];
			}
		}
		$body   = array(
			'customer_id'     => Sage200Client::id( $customerId ),
			'name'            => Text::fit( $delivery['name'] ?? ( $partner['name'] ?? __( 'Delivery', 'estasoft-erp-crm-sync-engine' ) ), 60 ),
			'address_1'       => $line,
			'city'            => $delivery['city'] ?? '',
			'county'          => $delivery['state'] ?? '',
			'postcode'        => $delivery['zip'] ?? '',
			'country_code_id' => '' === $this->options->text( 'country_code_id' ) ? '' : Sage200Client::id( $this->options->text( 'country_code_id' ) ),
		);
		$stored = $this->client->create( 'customer_delivery_addresses', array_filter( $body, static fn( mixed $value ): bool => '' !== $value ) );
		return isset( $stored['id'] ) && is_scalar( $stored['id'] ) ? (string) $stored['id'] : '';
	}

	/**
	 * The account reference of a new customer: a prefix and the number in base 36, eight characters at most.
	 *
	 * @param string $seed Text with the order or customer number.
	 */
	public function reference( string $seed ): string {
		$prefix = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', '' !== $this->options->text( 'customer_reference_prefix' ) ? $this->options->text( 'customer_reference_prefix' ) : 'WEB' ) );
		$prefix = substr( '' === $prefix ? 'WEB' : $prefix, 0, 3 );
		$digits = (string) preg_replace( '/\D/', '', $seed );
		$number = '' === $digits ? (int) sprintf( '%u', crc32( $seed ) ) : (int) substr( $digits, -9 );
		$text   = strtoupper( base_convert( (string) ( $number % 60466176 ), 10, 36 ) );
		return substr( $prefix . str_pad( $text, self::REFERENCE_MAX - strlen( $prefix ), '0', STR_PAD_LEFT ), 0, self::REFERENCE_MAX );
	}

	/**
	 * Whether a customer account can take orders: not closed, inactive or dormant.
	 *
	 * @param array<string,mixed> $customer Customer record.
	 */
	private function active( array $customer ): bool {
		$status = strtolower( (string) ( $customer['account_status_type'] ?? '' ) );
		return '' === $status || ( str_contains( $status, 'active' ) && ! str_contains( $status, 'inactive' ) );
	}

	/**
	 * Whether the delivery address is another place than the billing address.
	 *
	 * @param array<string,string> $billing  Billing fields.
	 * @param array<string,string> $delivery Delivery fields.
	 */
	public function differs( array $billing, array $delivery ): bool {
		if ( '' === ( $delivery['street'] ?? '' ) || '' === ( $delivery['city'] ?? '' ) ) {
			return false;
		}
		foreach ( array( 'street', 'city', 'zip', 'country' ) as $key ) {
			if ( strtolower( $billing[ $key ] ?? '' ) !== strtolower( $delivery[ $key ] ?? '' ) && '' !== ( $delivery[ $key ] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}
