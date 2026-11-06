<?php
/**
 * Finds and makes sevDesk contacts through a deterministic customer number.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * The sevDesk API documents no search by email (the `GET /Contact` filters are `depth` and `customerNumber`), so a returning buyer is recognised by the
 * customer number this connector gives: the prefix plus the first 12 hex characters of the hash of the lower-case email. Contacts the merchant typed by
 * hand are not found. The postal address and the email are stored on the contact in two extra calls that never fail the order.
 */
final class SevdeskContacts {

	/**
	 * Set up the helper.
	 *
	 * @param SevdeskReference $reference Reference data.
	 */
	public function __construct( private readonly SevdeskReference $reference ) {
	}

	/**
	 * The customer number for an email (or, with `always_create`, for one order).
	 *
	 * @param string  $email   Email.
	 * @param Options $options Settings.
	 * @param string  $salt    Added to the hash when every order gets its own contact.
	 */
	public function numberFor( string $email, Options $options, string $salt = '' ): string {
		$prefix = $options->text( 'customer_number_prefix' );
		return $prefix . substr( hash( 'sha256', strtolower( trim( $email ) ) . ( '' === $salt ? '' : '|' . $salt ) ), 0, 12 );
	}

	/**
	 * The contact with a customer number, or null.
	 *
	 * @param ApiClient $api    Client.
	 * @param string    $number Customer number.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, string $number ): ?array {
		foreach ( SevdeskJson::rows(
			$api->get(
				'Contact',
				array(
					'customerNumber' => $number,
					'depth'          => 1,
				)
			)->json()
		) as $contact ) {
			if ( (string) ( $contact['customerNumber'] ?? '' ) === $number ) {
				return $contact;
			}
		}
		return null;
	}

	/**
	 * The contact for a customer.
	 *
	 * @param ApiClient            $api       Client.
	 * @param array<string,string> $customer  Customer block of the payload.
	 * @param Options              $options   Settings.
	 * @param string               $clientRef Client reference of the item (salt for `always_create`).
	 * @return array{id:string,created:bool,warnings:array<int,string>}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( ApiClient $api, array $customer, Options $options, string $clientRef ): array|ConnectorError {
		$policy = $options->choice( 'customer_policy' );
		if ( 'collective' === $policy ) {
			$id = $options->text( 'collective_contact_id' );
			if ( '' === $id || null === SevdeskJson::first( $api->get( 'Contact/' . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) )->json() ) ) {
				return Refusal::value(
					SevdeskConnector::LABEL,
					'collective_contact_id',
					__( 'The collective customer is not set, or sevDesk has no contact with that id.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'collective contact missing'
				);
			}
			return array(
				'id'       => $id,
				'created'  => false,
				'warnings' => array(),
			);
		}
		$number = $this->numberFor( $customer['email'], $options, 'always_create' === $policy ? $clientRef : '' );
		$found  = $this->find( $api, $number );
		if ( null !== $found ) {
			return array(
				'id'       => (string) $found['id'],
				'created'  => false,
				'warnings' => array(),
			);
		}
		if ( 'match_only' === $policy ) {
			return Refusal::value(
				SevdeskConnector::LABEL,
				'customer.email',
				__( 'sevDesk has no contact for this customer and the customer setting is match only.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'no matching contact'
			);
		}
		return $this->create( $api, $customer, $options, $number );
	}

	/**
	 * Make a contact, then its address and email.
	 *
	 * @param ApiClient            $api      Client.
	 * @param array<string,string> $customer Customer block.
	 * @param Options              $options  Settings.
	 * @param string               $number   Customer number.
	 * @return array{id:string,created:bool,warnings:array<int,string>}|ConnectorError
	 *
	 * @throws ApiException On failure of the contact itself.
	 */
	private function create( ApiClient $api, array $customer, Options $options, string $number ): array|ConnectorError {
		$body = array(
			'category'       => SevdeskJson::ref( $options->int( 'contact_category_id' ), 'Category' ),
			'customerNumber' => $number,
			'status'         => 1000,
		);
		if ( '' !== $customer['company'] ) {
			$body['name'] = $customer['company'];
		} else {
			$body['surename']   = $customer['first_name'];
			$body['familyname'] = '' !== $customer['last_name'] ? $customer['last_name'] : $customer['name'];
		}
		if ( '' !== $customer['vat_id'] ) {
			$body['vatNumber'] = $customer['vat_id'];
		}
		$created = SevdeskJson::first( $api->post( 'Contact', array(), $body )->json() );
		$id      = null === $created ? '' : (string) ( $created['id'] ?? '' );
		if ( '' === $id ) {
			return Refusal::value( SevdeskConnector::LABEL, 'contact', __( 'sevDesk did not return the new contact.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no contact id' );
		}
		$warnings = array();
		try {
			$this->details( $api, $id, $customer, $options );
		} catch ( ApiException $exception ) {
			if ( 429 === $exception->status() ) {
				throw $exception;
			}
			$warnings[] = 'contact_details_failed';
		}
		return array(
			'id'       => $id,
			'created'  => true,
			'warnings' => $warnings,
		);
	}

	/**
	 * The postal address and the email of a new contact.
	 *
	 * @param ApiClient            $api      Client.
	 * @param string               $id       Contact id.
	 * @param array<string,string> $customer Customer block.
	 * @param Options              $options  Settings.
	 *
	 * @throws ApiException On failure.
	 */
	private function details( ApiClient $api, string $id, array $customer, Options $options ): void {
		$countryId = $this->reference->countryId( $api, $customer['country'] );
		if ( '' !== $countryId && '' !== $customer['zip'] ) {
			$street = trim( $customer['street'] . ( '' !== $customer['supplement'] ? "\n" . $customer['supplement'] : '' ) );
			$api->post(
				'ContactAddress',
				array(),
				array(
					'contact' => SevdeskJson::ref( $id, 'Contact' ),
					'street'  => $street,
					'zip'     => $customer['zip'],
					'city'    => $customer['city'],
					'country' => SevdeskJson::ref( $countryId, 'StaticCountry' ),
				)
			);
		}
		if ( '' !== $customer['email'] ) {
			$api->post(
				'CommunicationWay',
				array(),
				array(
					'contact' => SevdeskJson::ref( $id, 'Contact' ),
					'type'    => 'EMAIL',
					'value'   => $customer['email'],
					'key'     => SevdeskJson::ref( $options->int( 'communication_key_id' ), 'CommunicationWayKey' ),
					'main'    => true,
				)
			);
		}
	}
}
