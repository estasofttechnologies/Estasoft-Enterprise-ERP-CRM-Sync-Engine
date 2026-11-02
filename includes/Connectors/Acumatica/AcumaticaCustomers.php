<?php
/**
 * Finds and creates Acumatica customers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * A customer is found by the email of its main contact. When none is found and the policy allows it, one is created without a
 * CustomerID, which lets Acumatica number it from its own numbering sequence.
 */
final class AcumaticaCustomers {

	/**
	 * Set up with the services it uses.
	 *
	 * @param AcumaticaClient $client  Client.
	 * @param Options         $options Connection options.
	 * @param string          $system  System name.
	 */
	public function __construct( private readonly AcumaticaClient $client, private readonly Options $options, private readonly string $system ) {
	}

	/**
	 * A customer by email, ignoring case. A customer that is Inactive is skipped.
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
		$rows = $this->client->query(
			'Customer',
			array(
				'$filter' => 'MainContact/Email eq ' . AcumaticaValue::literal( trim( $email ) ),
				'$expand' => 'MainContact',
				'$select' => 'CustomerID,CustomerName,Status,MainContact/Email',
			),
			50
		);
		foreach ( $rows as $row ) {
			$contact = isset( $row['MainContact'] ) && is_array( $row['MainContact'] ) ? $row['MainContact'] : array();
			if ( strtolower( AcumaticaValue::text( $contact, 'Email' ) ) !== $wanted || 'inactive' === strtolower( AcumaticaValue::text( $row, 'Status' ) ) ) {
				continue;
			}
			$id = AcumaticaValue::text( $row, 'CustomerID' );
			if ( '' !== $id ) {
				return array(
					'id'   => $id,
					'name' => AcumaticaValue::text( $row, 'CustomerName' ),
				);
			}
		}
		return null;
	}

	/**
	 * The customer for a buyer: matched or created according to the policy.
	 *
	 * @param array<string,string> $partner Billing customer fields (email required).
	 * @return array{id:string,name:string,created:bool}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( array $partner ): array|ConnectorError {
		$policy = $this->options->choice( 'customer_policy', array( 'match_or_create', 'match_only', 'always_create' ), 'match_or_create' );
		$found  = 'always_create' === $policy ? null : $this->find( $partner['email'] ?? '' );
		if ( null !== $found ) {
			return $found + array( 'created' => false );
		}
		if ( 'match_only' === $policy ) {
			return Problems::refused(
				$this->system,
				'partner.email',
				__( 'No customer has this email address and the customer setting is "match only". Create the customer or change the setting.', 'estasoft-erp-crm-sync-engine' )
			);
		}
		$stored = $this->client->put( 'Customer', $this->body( $partner ) );
		$id     = AcumaticaValue::text( $stored, 'CustomerID' );
		if ( '' === $id ) {
			return Problems::refused( $this->system, 'customer', __( 'Acumatica did not return the new customer.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return array(
			'id'      => $id,
			'name'    => AcumaticaValue::text( $stored, 'CustomerName' ),
			'created' => true,
		);
	}

	/**
	 * The body of a new customer.
	 *
	 * @param array<string,string> $partner Billing customer fields.
	 * @return array<string,mixed>
	 */
	private function body( array $partner ): array {
		$company = $partner['company_name'] ?? '';
		$person  = $partner['name'] ?? '';
		$contact = array(
			'Email'  => $partner['email'] ?? '',
			'Phone1' => $partner['phone'] ?? '',
		);
		if ( '' !== $company && '' !== $person ) {
			$parts = preg_split( '/\s+/u', $person, 2 );
			if ( is_array( $parts ) ) {
				$contact['FirstName'] = (string) $parts[0];
				$contact['LastName']  = (string) ( $parts[1] ?? '' );
			}
		}
		$body                = AcumaticaValue::fields(
			array(
				'CustomerName'  => '' !== $company ? $company : $person,
				'CustomerClass' => $this->options->text( 'customer_class' ),
			)
		);
		$body['MainContact'] = AcumaticaValue::fields( $contact ) + array( 'Address' => $this->address( $partner ) );
		return $body;
	}

	/**
	 * The address block of a contact.
	 *
	 * @param array<string,string> $source Address fields of the payload.
	 * @return array<string,array{value:mixed}>
	 */
	public function address( array $source ): array {
		return AcumaticaValue::fields(
			array(
				'AddressLine1' => $source['street'] ?? '',
				'AddressLine2' => $source['street2'] ?? '',
				'City'         => $source['city'] ?? '',
				'State'        => $source['state'] ?? '',
				'PostalCode'   => $source['zip'] ?? '',
				'Country'      => strtoupper( $source['country'] ?? '' ),
			)
		);
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
