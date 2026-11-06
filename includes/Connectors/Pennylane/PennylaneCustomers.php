<?php
/**
 * Pennylane customers: found by a deterministic external reference, made when the policy allows.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pennylane;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Pennylane's customer filter fields are `id`, `customer_type`, `ledger_account_id`, `name` (prefix only), `external_reference` and `reg_no`; email is
 * not filterable [documented]. A returning buyer is therefore recognised by the external reference this connector writes: `WC-C-` and 16 hex
 * characters of the hash of the customer type and the lower-case email (a company and a person with the same email are two customers). Customers the merchant typed by hand are not found. A company customer and an individual customer are
 * different resources; the right one is always created, and a customer is never updated (calling the wrong update endpoint corrupts the record
 * [documented]).
 */
final class PennylaneCustomers {

	/**
	 * Set up the helper.
	 *
	 * @param PennylaneApi $pennylane Request helpers.
	 */
	public function __construct( private readonly PennylaneApi $pennylane ) {
	}

	/**
	 * The external reference for an email and a customer type (or, with `always_create`, for one order).
	 *
	 * @param string $email   Email.
	 * @param bool   $company True for a company customer, false for an individual.
	 * @param string $salt    Added to the hash when every order gets its own customer.
	 */
	public function referenceFor( string $email, bool $company = false, string $salt = '' ): string {
		return 'WC-C-' . substr( hash( 'sha256', ( $company ? 'company' : 'individual' ) . '|' . strtolower( trim( $email ) ) . ( '' === $salt ? '' : '|' . $salt ) ), 0, 16 );
	}

	/**
	 * The customer this connector made for an email, whichever type it is: the individual customer first, then the company customer.
	 *
	 * @param ApiClient $api   Client.
	 * @param string    $email Email.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function findByEmail( ApiClient $api, string $email ): ?array {
		return $this->find( $api, $this->referenceFor( $email, false ) ) ?? $this->find( $api, $this->referenceFor( $email, true ) );
	}

	/**
	 * The customer with an external reference, or null.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $reference External reference.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, string $reference ): ?array {
		$page = $this->pennylane->page(
			$api,
			'customers',
			array( array( 'external_reference', 'eq', $reference ) ),
			null
		);
		foreach ( $page['items'] as $customer ) {
			if ( PayloadReader::text( $customer['external_reference'] ?? '' ) === $reference ) {
				return $customer;
			}
		}
		return null;
	}

	/**
	 * The customer for an order.
	 *
	 * @param ApiClient            $api       Client.
	 * @param array<string,string> $customer  Customer block of the payload.
	 * @param Options              $options   Settings.
	 * @param string               $clientRef Client reference of the item (salt for `always_create`).
	 * @return array{id:string,created:bool}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( ApiClient $api, array $customer, Options $options, string $clientRef ): array|ConnectorError {
		$policy = $options->choice( 'customer_policy' );
		if ( 'collective' === $policy ) {
			$id = $options->text( 'collective_customer_id' );
			if ( '' === $id || ! $api->get( 'customers/' . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) )->isSuccess() ) {
				return Refusal::value( PennylaneConnector::LABEL, 'collective_customer_id', __( 'The collective customer is not set, or Pennylane has no customer with that id.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'collective customer missing' );
			}
			return array(
				'id'      => $id,
				'created' => false,
			);
		}
		$company   = '' !== $customer['company'];
		$reference = $this->referenceFor( $customer['email'], $company, 'always_create' === $policy ? $clientRef : '' );
		$found     = $this->find( $api, $reference );
		if ( null !== $found ) {
			return array(
				'id'      => PayloadReader::text( $found['id'] ?? '' ),
				'created' => false,
			);
		}
		if ( 'match_only' === $policy ) {
			return Refusal::value( PennylaneConnector::LABEL, 'customer.email', __( 'Pennylane has no customer for this buyer and the customer setting is match only.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no matching customer' );
		}
		$created = $api->post( $company ? 'company_customers' : 'individual_customers', array(), $this->body( $customer, $reference, $company ) )->json();
		$id      = PayloadReader::text( $created['id'] ?? '' );
		if ( '' === $id ) {
			return Refusal::value( PennylaneConnector::LABEL, 'customer', __( 'Pennylane did not return the new customer.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no customer id' );
		}
		return array(
			'id'      => $id,
			'created' => true,
		);
	}

	/**
	 * The create request for a company or an individual customer.
	 *
	 * @param array<string,string> $customer  Customer block.
	 * @param string               $reference External reference.
	 * @param bool                 $company   True for a company customer.
	 * @return array<string,mixed>
	 */
	public function body( array $customer, string $reference, bool $company ): array {
		$body = array( 'external_reference' => $reference );
		if ( $company ) {
			$body['name'] = $customer['company'];
			if ( '' !== $customer['vat_id'] ) {
				$body['vat_number'] = $customer['vat_id'];
			}
		} else {
			$body['first_name'] = '' !== $customer['first_name'] ? $customer['first_name'] : $customer['name'];
			$body['last_name']  = '' !== $customer['last_name'] ? $customer['last_name'] : $customer['name'];
		}
		if ( '' !== $customer['email'] ) {
			$body['emails'] = array( $customer['email'] );
		}
		if ( '' !== $customer['phone'] ) {
			$body['phone'] = $customer['phone'];
		}
		if ( '' !== $customer['country'] ) {
			$body['billing_address'] = array(
				'address'        => trim( $customer['street'] . ( '' !== $customer['supplement'] ? ', ' . $customer['supplement'] : '' ) ),
				'postal_code'    => $customer['zip'],
				'city'           => $customer['city'],
				'country_alpha2' => $customer['country'],
			);
		}
		return $body;
	}

	/**
	 * The name of a customer for display.
	 *
	 * @param array<string,mixed> $customer Customer from the API.
	 */
	public function nameOf( array $customer ): string {
		$name = PayloadReader::text( $customer['name'] ?? '' );
		return '' !== $name ? $name : trim( PayloadReader::text( $customer['first_name'] ?? '' ) . ' ' . PayloadReader::text( $customer['last_name'] ?? '' ) );
	}
}
