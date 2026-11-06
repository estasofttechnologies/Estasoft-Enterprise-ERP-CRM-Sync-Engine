<?php
/**
 * Moneybird contacts: found by email through the free-text search, made when the policy allows.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Moneybird;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * `GET contacts.json?query=` is a free-text search over name, contact people, email, phone and customer id, paged with `page` and `per_page` (1 to
 * 100) [documented]. The answer is filtered to contacts whose email is exactly the address (case-insensitive) and read to the last page.
 * Creating needs `company_name`, or `firstname` and `lastname` [documented]. Existing contacts are never changed.
 */
final class MoneybirdContacts {

	/**
	 * Rows per page.
	 */
	private const PER_PAGE = 100;

	/**
	 * Most pages read.
	 */
	private const MAX_PAGES = 20;

	/**
	 * Contacts whose email is exactly the address, oldest id first.
	 *
	 * @param ApiClient $api   Client.
	 * @param string    $email Email address.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function findByEmail( ApiClient $api, string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$found = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$rows = $api->get(
				'contacts.json',
				array(
					'query'    => $email,
					'page'     => $page,
					'per_page' => self::PER_PAGE,
				)
			)->json();
			$rows = \Estasoft\SyncEngine\Core\Support\Lists::isList( $rows ) ? $rows : array();
			foreach ( $rows as $contact ) {
				if ( is_array( $contact ) && $this->hasEmail( $contact, $email ) ) {
					$found[] = $contact;
				}
			}
			if ( count( $rows ) < self::PER_PAGE ) {
				break;
			}
		}
		usort( $found, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
		return $found;
	}

	/**
	 * The contact for an order's customer, made when the policy allows.
	 *
	 * @param ApiClient            $api      Client.
	 * @param array<string,string> $customer Customer block of the payload.
	 * @param Options              $options  Settings.
	 * @return array{id:string,created:bool}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( ApiClient $api, array $customer, Options $options ): array|ConnectorError {
		$policy = $options->choice( 'customer_policy' );
		if ( 'always_create' !== $policy ) {
			$matches = $this->findByEmail( $api, $customer['email'] );
			if ( array() !== $matches ) {
				if ( count( $matches ) > 1 && $options->is( 'duplicate_customer', 'fail' ) ) {
					return Refusal::value(
						MoneybirdConnector::LABEL,
						'customer.email',
						__( 'More than one contact has this email address. Merge them in Moneybird or set the duplicate setting to oldest.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'duplicate customer'
					);
				}
				return array(
					'id'      => (string) $matches[0]['id'],
					'created' => false,
				);
			}
			if ( 'match_only' === $policy ) {
				return Refusal::value(
					MoneybirdConnector::LABEL,
					'customer.email',
					__( 'Moneybird has no contact with this email address and the customer setting is match only.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'no matching contact'
				);
			}
		}
		$created = $api->post( 'contacts.json', array(), array( 'contact' => $this->body( $customer ) ) )->json();
		$id      = PayloadReader::text( $created['id'] ?? '' );
		if ( '' === $id ) {
			return Refusal::value( MoneybirdConnector::LABEL, 'contact', __( 'Moneybird did not return the new contact.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no contact id' );
		}
		return array(
			'id'      => $id,
			'created' => true,
		);
	}

	/**
	 * The create request for a contact.
	 *
	 * @param array<string,string> $customer Customer block.
	 * @return array<string,string>
	 */
	public function body( array $customer ): array {
		$body = array();
		if ( '' !== $customer['company'] ) {
			$body['company_name'] = $customer['company'];
		}
		$first = $customer['first_name'];
		$last  = '' !== $customer['last_name'] ? $customer['last_name'] : ( '' === $customer['company'] ? $customer['name'] : '' );
		if ( '' !== $last ) {
			$body['firstname'] = $first;
			$body['lastname']  = $last;
		}
		$map = array(
			'street'     => 'address1',
			'supplement' => 'address2',
			'zip'        => 'zipcode',
			'city'       => 'city',
			'country'    => 'country',
			'phone'      => 'phone',
			'vat_id'     => 'tax_number',
		);
		foreach ( $map as $from => $to ) {
			if ( '' !== $customer[ $from ] ) {
				$body[ $to ] = $customer[ $from ];
			}
		}
		if ( '' !== $customer['email'] ) {
			$body['email']                  = $customer['email'];
			$body['send_invoices_to_email'] = $customer['email'];
		}
		return $body;
	}

	/**
	 * The name of a contact for display.
	 *
	 * @param array<string,mixed> $contact Contact from the API.
	 */
	public function nameOf( array $contact ): string {
		$company = PayloadReader::text( $contact['company_name'] ?? '' );
		if ( '' !== $company ) {
			return $company;
		}
		return trim( PayloadReader::text( $contact['firstname'] ?? '' ) . ' ' . PayloadReader::text( $contact['lastname'] ?? '' ) );
	}

	/**
	 * Whether a contact carries the email.
	 *
	 * @param array<string,mixed> $contact Contact.
	 * @param string              $email   Lower-case email.
	 */
	private function hasEmail( array $contact, string $email ): bool {
		foreach ( array( 'email', 'send_invoices_to_email' ) as $key ) {
			if ( strtolower( trim( PayloadReader::text( $contact[ $key ] ?? '' ) ) ) === $email ) {
				return true;
			}
		}
		return false;
	}
}
