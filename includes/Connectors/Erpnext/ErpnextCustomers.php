<?php
/**
 * Finds or creates the ERPNext customer of an order, with its contact and addresses.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * The customer is matched by email, ignoring case: first on the Customer's own `email_id`, then through the Contact that carries the
 * address (a Contact Email child row) and its link to a Customer. A new customer gets its Contact from ERPNext itself (the Customer
 * insert creates the primary Contact when it has an email), and its addresses as separate Address documents linked to it.
 */
final class ErpnextCustomers {

	/**
	 * Set up with the services it uses.
	 *
	 * @param ErpnextClient  $client  Client.
	 * @param ErpnextLookups $lookups Setup lookups.
	 * @param Options        $options Connection options.
	 * @param string         $system  System name.
	 */
	public function __construct( private readonly ErpnextClient $client, private readonly ErpnextLookups $lookups, private readonly Options $options, private readonly string $system ) {
	}

	/**
	 * A customer by email.
	 *
	 * @param string $email Email address.
	 * @return array{name:string,customer_name:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $email ): ?array {
		$wanted = strtolower( trim( $email ) );
		if ( '' === $wanted ) {
			return null;
		}
		$rows = $this->client->rows( 'Customer', array( array( 'email_id', '=', $email ), array( 'disabled', '=', 0 ) ), array( 'name', 'customer_name', 'email_id' ), 'creation asc', 20 );
		foreach ( $rows as $row ) {
			if ( strtolower( trim( (string) ( $row['email_id'] ?? '' ) ) ) === $wanted ) {
				return array(
					'name'          => (string) $row['name'],
					'customer_name' => (string) ( $row['customer_name'] ?? $row['name'] ),
				);
			}
		}
		$contacts = $this->client->rows( 'Contact', array( array( 'Contact Email', 'email_id', '=', $email ) ), array( 'name' ), 'creation asc', 20 );
		foreach ( $contacts as $contact ) {
			$doc = $this->client->get( 'Contact', (string) $contact['name'] );
			foreach ( (array) ( $doc['links'] ?? array() ) as $link ) {
				if ( is_array( $link ) && 'Customer' === ( $link['link_doctype'] ?? '' ) && '' !== (string) ( $link['link_name'] ?? '' ) ) {
					$customer = $this->client->get( 'Customer', (string) $link['link_name'] );
					if ( null !== $customer && 0 === (int) ( $customer['disabled'] ?? 0 ) ) {
						return array(
							'name'          => (string) $customer['name'],
							'customer_name' => (string) ( $customer['customer_name'] ?? $customer['name'] ),
						);
					}
				}
			}
		}
		return null;
	}

	/**
	 * The customer, its contact and its addresses for an order.
	 *
	 * @param OrderPayload $payload The order.
	 * @return array{customer:string,contact:string,billing:string,shipping:string,created:bool}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( OrderPayload $payload ): array|ConnectorError {
		return $this->resolveParts( $payload->partner, $payload->delivery );
	}

	/**
	 * The customer, its contact and its addresses for a buyer and an optional delivery address.
	 *
	 * @param array<string,string> $partner  Billing customer fields (email required).
	 * @param array<string,string> $delivery Delivery address fields, or an empty array.
	 * @return array{customer:string,contact:string,billing:string,shipping:string,created:bool}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolveParts( array $partner, array $delivery ): array|ConnectorError {
		$policy = $this->options->choice( 'customer_policy', array( 'match_or_create', 'match_only', 'always_create' ), 'match_or_create' );
		$found  = 'always_create' === $policy ? null : $this->find( $partner['email'] ?? '' );
		$name   = '';
		$create = false;
		$cust   = null;
		if ( null !== $found ) {
			$name = $found['name'];
			$cust = $found;
		} elseif ( 'match_only' === $policy ) {
			return Problems::refused(
				$this->system,
				'partner.email',
				__( 'No customer has this email address and the customer setting is "match only". Create the customer or change the setting.', 'estasoft-enterprise-erpcrm-sync-engine' )
			);
		} else {
			$created = $this->create( $partner );
			if ( $created instanceof ConnectorError ) {
				return $created;
			}
			$name   = $created['name'];
			$create = true;
			$cust   = $created;
		}

		$billing  = $this->address( $name, $partner, 'Billing', $partner['name'] ?? '' );
		$shipping = '';
		if ( array() !== $delivery && $this->differs( $partner, $delivery ) ) {
			$shipping = $this->address( $name, $delivery, 'Shipping', $delivery['name'] ?? ( $partner['name'] ?? '' ) );
		} elseif ( '' !== $billing ) {
			$shipping = $billing;
		}
		return array(
			'customer' => $name,
			'contact'  => (string) ( $cust['contact'] ?? '' ),
			'billing'  => $billing,
			'shipping' => $shipping,
			'created'  => $create,
		);
	}

	/**
	 * Insert a customer.
	 *
	 * @param array<string,string> $partner Billing customer fields.
	 * @return array{name:string,customer_name:string,contact:string}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( array $partner ): array|ConnectorError {
		$company  = $partner['company_name'] ?? '';
		$person   = $partner['name'] ?? '';
		$defaults = $this->lookups->customerDefaults();
		if ( '' === $defaults['customer_group'] || '' === $defaults['territory'] ) {
			return Problems::refused(
				$this->system,
				'customer_group',
				__( 'A new customer needs a customer group and a territory. Enter them in the connection settings, or set the defaults in Selling Settings.', 'estasoft-enterprise-erpcrm-sync-engine' )
			);
		}
		$doc = array(
			'customer_name'  => '' !== $company ? $company : $person,
			'customer_type'  => '' !== $company ? 'Company' : 'Individual',
			'customer_group' => $defaults['customer_group'],
			'territory'      => $defaults['territory'],
			'email_id'       => $partner['email'],
		);
		if ( isset( $partner['phone'] ) ) {
			$doc['mobile_no'] = $partner['phone'];
		}
		if ( isset( $partner['vat'] ) ) {
			$doc['tax_id'] = $partner['vat'];
		}
		if ( '' !== $company && '' !== $person ) {
			$parts = preg_split( '/\s+/u', $person, 2 );
			if ( is_array( $parts ) ) {
				$doc['first_name'] = (string) $parts[0];
				if ( isset( $parts[1] ) ) {
					$doc['last_name'] = (string) $parts[1];
				}
			}
		}
		$stored = $this->client->insert( 'Customer', $doc );
		$name   = (string) ( $stored['name'] ?? '' );
		if ( '' === $name ) {
			return Problems::refused( $this->system, 'customer', __( 'ERPNext did not return the new customer.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return array(
			'name'          => $name,
			'customer_name' => (string) ( $stored['customer_name'] ?? $name ),
			'contact'       => (string) ( $stored['customer_primary_contact'] ?? '' ),
		);
	}

	/**
	 * Find or insert an Address linked to the customer. An address without a street, a city or a known country is skipped.
	 *
	 * @param string               $customer Customer name.
	 * @param array<string,string> $source   Address fields of the payload.
	 * @param string               $type     Billing or Shipping.
	 * @param string               $title    Address title.
	 * @return string The Address name, or an empty string when none was made.
	 *
	 * @throws ApiException On failure.
	 */
	private function address( string $customer, array $source, string $type, string $title ): string {
		$line1   = $source['street'] ?? '';
		$city    = $source['city'] ?? '';
		$country = $this->lookups->country( $source['country'] ?? '' );
		if ( '' === $line1 || '' === $city || '' === $country ) {
			return '';
		}
		$existing = $this->client->first(
			'Address',
			array(
				array( 'Dynamic Link', 'link_doctype', '=', 'Customer' ),
				array( 'Dynamic Link', 'link_name', '=', $customer ),
				array( 'address_type', '=', $type ),
				array( 'address_line1', '=', $line1 ),
				array( 'city', '=', $city ),
			),
			array( 'name' )
		);
		if ( null !== $existing ) {
			return (string) $existing['name'];
		}
		$doc = array(
			'address_title' => '' !== $title ? $title : $customer,
			'address_type'  => $type,
			'address_line1' => $line1,
			'city'          => $city,
			'country'       => $country,
			'links'         => array(
				array(
					'link_doctype' => 'Customer',
					'link_name'    => $customer,
				),
			),
		);
		foreach ( array(
			'state' => 'state',
			'zip'   => 'pincode',
			'phone' => 'phone',
		) as $from => $to ) {
			if ( isset( $source[ $from ] ) ) {
				$doc[ $to ] = $source[ $from ];
			}
		}
		if ( 'Billing' === $type && isset( $source['email'] ) ) {
			$doc['email_id'] = $source['email'];
		}
		$stored = $this->client->insert( 'Address', $doc );
		return (string) ( $stored['name'] ?? '' );
	}

	/**
	 * Whether the delivery address is another place than the billing address.
	 *
	 * @param array<string,string> $billing  Billing fields.
	 * @param array<string,string> $delivery Delivery fields.
	 */
	private function differs( array $billing, array $delivery ): bool {
		foreach ( array( 'street', 'city', 'zip', 'country' ) as $key ) {
			if ( strtolower( $billing[ $key ] ?? '' ) !== strtolower( $delivery[ $key ] ?? '' ) && '' !== ( $delivery[ $key ] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}
