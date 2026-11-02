<?php
/**
 * Finds or creates the NetSuite customer of an order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The customer is found by email (any case) first. A new one is created with the documented upsert on an external id made from the email,
 * so a retry after a lost answer finds the same record instead of making another.
 */
final class NetSuiteCustomers {

	/**
	 * Set up with what it depends on.
	 *
	 * @param NetSuiteGateway $gateway  Calls.
	 * @param NetSuiteLookups $lookups  Reads.
	 * @param NetSuiteOptions $options  Options.
	 * @param string          $system   System name for messages.
	 */
	public function __construct(
		private readonly NetSuiteGateway $gateway,
		private readonly NetSuiteLookups $lookups,
		private readonly NetSuiteOptions $options,
		private readonly string $system
	) {
	}

	/**
	 * The customer's internal id, created when needed and allowed.
	 *
	 * @param array<string,string> $partner    Billing customer fields (OrderData::$partner).
	 * @param string               $currencyId Internal id of the order currency, or empty.
	 * @param Warnings             $warnings   Receives notes about cut text.
	 * @return array{id:string,name:string,created:bool}
	 *
	 * @throws ApiException With SYNC-VAL-002 when no customer exists and none may be created; on any request failure.
	 */
	public function ensure( array $partner, string $currencyId, Warnings $warnings ): array {
		$email = strtolower( (string) ( $partner['email'] ?? '' ) );
		$found = $this->lookups->customerByEmail( $email );
		if ( null !== $found ) {
			return $found + array( 'created' => false );
		}
		if ( 'match_only' === $this->options->customerPolicy() ) {
			$problem = Problem::validation( $this->system, 'customer', __( 'NetSuite has no customer with this email and customer_policy is match_only.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$externalId = $this->externalId( $email );
		$body       = $this->body( $partner, $currencyId, $externalId, $warnings );
		$response   = $this->gateway->write( 'PUT', 'record/v1/customer/eid:' . rawurlencode( $externalId ), $body );
		$id         = $this->gateway->idFromLocation( $response );
		if ( '' === $id ) {
			$record = $this->gateway->byExternalId( 'customer', $externalId, array( 'id' ) );
			$id     = null === $record ? '' : (string) ( $record['id'] ?? '' );
		}
		if ( '' === $id ) {
			$problem = Problem::validation( $this->system, 'customer', __( 'NetSuite accepted the customer but did not say which record it is.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return array(
			'id'      => $id,
			'name'    => $this->displayName( $partner ),
			'created' => true,
		);
	}

	/**
	 * The external id of the customer made from an email: WC-C- and the first 20 hex digits of the SHA-1 of the lower-cased email.
	 *
	 * @param string $email Email.
	 */
	public function externalId( string $email ): string {
		return 'WC-C-' . substr( sha1( strtolower( trim( $email ) ) ), 0, 20 );
	}

	/**
	 * The REST body of a new customer.
	 *
	 * @param array<string,string> $partner    Billing customer fields.
	 * @param string               $currencyId Internal id of the currency.
	 * @param string               $externalId External id.
	 * @param Warnings             $warnings   Receives notes about cut text.
	 * @return array<string,mixed>
	 */
	public function body( array $partner, string $currencyId, string $externalId, Warnings $warnings ): array {
		$body = array(
			'externalId' => $externalId,
			'email'      => (string) ( $partner['email'] ?? '' ),
		);
		if ( '' !== $currencyId ) {
			$body['currency'] = array( 'id' => $currencyId );
		}
		$name    = (string) ( $partner['name'] ?? '' );
		$company = (string) ( $partner['company_name'] ?? '' );
		$parts   = preg_split( '/\s+/u', $name, 2 );
		if ( '' === $company && is_array( $parts ) && 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
			$body['isPerson']  = true;
			$body['firstName'] = $warnings->fit( 'firstName', $parts[0], 32 );
			$body['lastName']  = $warnings->fit( 'lastName', $parts[1], 32 );
		} else {
			$body['isPerson']    = false;
			$body['companyName'] = $warnings->fit( 'companyName', '' !== $company ? $company : $name, 83 );
		}
		if ( '' !== ( $partner['phone'] ?? '' ) ) {
			$body['phone'] = $warnings->fit( 'phone', $partner['phone'], 32 );
		}
		if ( '' !== ( $partner['vat'] ?? '' ) ) {
			$body['vatRegNumber'] = $warnings->fit( 'vatRegNumber', $partner['vat'], 20 );
		}
		if ( '' !== $this->options->subsidiary() ) {
			$body['subsidiary'] = array( 'id' => $this->options->subsidiary() );
		}
		return $body;
	}

	/**
	 * The name shown for a customer built from the payload.
	 *
	 * @param array<string,string> $partner Billing customer fields.
	 */
	private function displayName( array $partner ): string {
		$company = (string) ( $partner['company_name'] ?? '' );
		return '' !== $company ? $company : (string) ( $partner['name'] ?? '' );
	}
}
