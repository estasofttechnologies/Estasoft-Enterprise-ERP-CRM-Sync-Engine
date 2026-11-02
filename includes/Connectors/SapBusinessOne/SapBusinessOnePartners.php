<?php
/**
 * Business partners (customers) in SAP Business One.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * A customer is found by the card code this connector would give it, then by email (any case), and created only when none exists. The card
 * code is made from the email (WC and 13 hex digits of its SHA-1), so a create whose answer was lost is found again on the next pass, and a
 * "card code already exists" answer (error -10) is turned into a read instead of a failure.
 */
final class SapBusinessOnePartners {

	/**
	 * Set up with what it depends on.
	 *
	 * @param SapBusinessOneGateway $gateway Calls.
	 * @param SapBusinessOneOptions $options Options.
	 * @param string                $system  System name for messages.
	 */
	public function __construct(
		private readonly SapBusinessOneGateway $gateway,
		private readonly SapBusinessOneOptions $options,
		private readonly string $system
	) {
	}

	/**
	 * The customer with an email.
	 *
	 * @param string $email Email.
	 * @return array{id:string,name:string}|null Card code and name.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $email ): ?array {
		$email = strtolower( trim( $email ) );
		$known = $this->gateway->one( 'BusinessPartners', $this->gateway->literal( $this->cardCode( $email ) ), array( 'select' => 'CardCode,CardName,EmailAddress,CardType' ) );
		if ( null !== $known && 'cCustomer' === (string) ( $known['CardType'] ?? 'cCustomer' ) ) {
			return $this->summary( $known );
		}
		$rows = $this->gateway->list(
			'BusinessPartners',
			array(
				'filter'  => "CardType eq 'cCustomer' and Frozen eq 'tNO' and EmailAddress eq " . $this->gateway->literal( $email ),
				'select'  => 'CardCode,CardName,EmailAddress',
				'orderby' => 'CardCode',
			),
			5
		);
		foreach ( $rows as $row ) {
			if ( strtolower( trim( (string) ( $row['EmailAddress'] ?? '' ) ) ) === $email ) {
				return $this->summary( $row );
			}
		}
		return null;
	}

	/**
	 * The customer's card code, created when needed and allowed.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param Warnings             $warnings Receives notes about cut text.
	 * @return array{id:string,name:string,created:bool}
	 *
	 * @throws ApiException With SYNC-VAL-002 when none exists and none may be created; on a request failure.
	 */
	public function ensure( array $partner, array $delivery, Warnings $warnings ): array {
		$email = strtolower( (string) ( $partner['email'] ?? '' ) );
		$found = $this->find( $email );
		if ( null !== $found ) {
			return $found + array( 'created' => false );
		}
		if ( 'match_only' === $this->options->customerPolicy() ) {
			$problem = Problem::validation( $this->system, 'customer', __( 'SAP has no customer with this email and customer_policy is match_only.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$body = $this->body( $partner, $delivery, $email, $warnings );
		try {
			$created = $this->gateway->create( 'BusinessPartners', $body );
		} catch ( ApiException $exception ) {
			$again = $this->duplicate( $exception ) ? $this->find( $email ) : null;
			if ( null === $again ) {
				throw $exception;
			}
			return $again + array( 'created' => false );
		}
		$code = (string) ( $created['CardCode'] ?? ( $body['CardCode'] ?? '' ) );
		if ( '' === $code ) {
			$problem = Problem::validation( $this->system, 'CardCode', __( 'SAP created the customer but did not say its card code.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return array(
			'id'      => $code,
			'name'    => (string) $body['CardName'],
			'created' => true,
		);
	}

	/**
	 * The card code made from an email.
	 *
	 * @param string $email Email in lower case.
	 */
	public function cardCode( string $email ): string {
		return 'WC' . substr( sha1( strtolower( trim( $email ) ) ), 0, 13 );
	}

	/**
	 * The body of a new business partner.
	 *
	 * @param array<string,string> $partner  Billing customer fields.
	 * @param array<string,string> $delivery Delivery address fields.
	 * @param string               $email    Email in lower case.
	 * @param Warnings             $warnings Receives notes about cut text.
	 * @return array<string,mixed>
	 */
	public function body( array $partner, array $delivery, string $email, Warnings $warnings ): array {
		$name = '' !== ( $partner['company_name'] ?? '' ) ? $partner['company_name'] : (string) ( $partner['name'] ?? '' );
		$body = array(
			'CardName'     => $warnings->fit( 'CardName', $name, 100 ),
			'CardType'     => 'cCustomer',
			'EmailAddress' => $warnings->fit( 'EmailAddress', $email, 100 ),
			'Currency'     => '##',
		);
		if ( $this->options->partnerSeries() > 0 ) {
			$body['Series'] = $this->options->partnerSeries();
		} else {
			$body['CardCode'] = $this->cardCode( $email );
		}
		if ( '' !== ( $partner['phone'] ?? '' ) ) {
			$body['Phone1'] = $warnings->fit( 'Phone1', $partner['phone'], 20 );
		}
		if ( '' !== ( $partner['vat'] ?? '' ) ) {
			$body['FederalTaxID'] = $warnings->fit( 'FederalTaxID', $partner['vat'], 32 );
		}
		$addresses = array_values( array_filter( array( $this->address( 'Bill To', 'bo_BillTo', $partner ), $this->address( 'Ship To', 'bo_ShipTo', array() !== $delivery ? $delivery : $partner ) ) ) );
		if ( array() !== $addresses ) {
			$body['BPAddresses'] = $addresses;
		}
		return $body;
	}

	/**
	 * Whether a failure says the card code already exists (error -10).
	 *
	 * @param ApiException $exception The failure.
	 */
	private function duplicate( ApiException $exception ): bool {
		$json    = $exception->response()?->json() ?? array();
		$message = strtolower( (string) ( $json['error']['message']['value'] ?? ( $json['error']['message'] ?? '' ) ) );
		return 400 === $exception->status() && ( -10 === (int) ( $json['error']['code'] ?? 0 ) || str_contains( $message, 'already exist' ) || str_contains( $message, 'duplicate' ) );
	}

	/**
	 * One address of the new partner, or an empty array when the source has no street or city.
	 *
	 * @param string               $label  AddressName.
	 * @param string               $type   bo_BillTo or bo_ShipTo.
	 * @param array<string,string> $source Address fields.
	 * @return array<string,string>
	 */
	private function address( string $label, string $type, array $source ): array {
		if ( '' === ( $source['street'] ?? '' ) && '' === ( $source['city'] ?? '' ) ) {
			return array();
		}
		$address = array(
			'AddressName' => $label,
			'AddressType' => $type,
		);
		foreach ( array(
			'Street'  => 'street',
			'City'    => 'city',
			'ZipCode' => 'zip',
			'Country' => 'country',
		) as $field => $key ) {
			if ( '' !== ( $source[ $key ] ?? '' ) ) {
				$address[ $field ] = $source[ $key ];
			}
		}
		return $address;
	}

	/**
	 * A partner row as a small array.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{id:string,name:string}
	 */
	private function summary( array $row ): array {
		return array(
			'id'   => (string) ( $row['CardCode'] ?? '' ),
			'name' => (string) ( $row['CardName'] ?? '' ),
		);
	}
}
