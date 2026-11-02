<?php
/**
 * Lexware Office contacts: find by email, create.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\LexwareOffice;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * `GET /v1/contacts?email=` is a case-insensitive substring match over contact and contact-person emails, at least three characters, with `_` and
 * `%` as wildcards unless escaped [documented]. The result is therefore filtered to an exact (case-insensitive) match and read page by page.
 * Existing contacts are never changed: an update needs the contact's `version` and replaces whole lists.
 */
final class LexwareContacts {

	/**
	 * Most pages read when matching by email.
	 */
	private const MAX_PAGES = 20;

	/**
	 * Contacts whose email is exactly the address, oldest customer number first.
	 *
	 * @param ApiClient $api   Client.
	 * @param string    $email Email address.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function findByEmail( ApiClient $api, string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( mb_strlen( $email ) < 3 ) {
			return array();
		}
		$found = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$json = $api->get(
				'contacts',
				array(
					'email'    => $this->escape( $email ),
					'customer' => true,
					'page'     => $page,
					'size'     => 250,
				)
			)->json();
			foreach ( (array) ( $json['content'] ?? array() ) as $contact ) {
				if ( is_array( $contact ) && $this->hasEmail( $contact, $email ) ) {
					$found[] = $contact;
				}
			}
			if ( ! empty( $json['last'] ) || array() === (array) ( $json['content'] ?? array() ) ) {
				break;
			}
		}
		usort(
			$found,
			static fn( array $a, array $b ): int => (int) ( $a['roles']['customer']['number'] ?? PHP_INT_MAX ) <=> (int) ( $b['roles']['customer']['number'] ?? PHP_INT_MAX )
		);
		return $found;
	}

	/**
	 * The contact for an order's customer, made when the policy allows.
	 *
	 * @param ApiClient            $api      Client.
	 * @param array<string,string> $customer Customer block of the payload.
	 * @param Options              $options  Settings.
	 * @return array{id:string,created:bool,address_differs:bool}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( ApiClient $api, array $customer, Options $options ): array|ConnectorError {
		$policy  = $options->choice( 'customer_policy' );
		$matches = $this->findByEmail( $api, $customer['email'] );
		if ( array() !== $matches ) {
			if ( count( $matches ) > 1 && $options->is( 'duplicate_customer', 'fail' ) ) {
				return Refusal::value(
					LexwareOfficeConnector::LABEL,
					'customer.email',
					__( 'More than one contact has this email address. Merge them in Lexware Office or set the duplicate setting to oldest.', 'estasoft-erp-crm-sync-engine' ),
					'duplicate customer'
				);
			}
			$first = $matches[0];
			return array(
				'id'              => (string) $first['id'],
				'created'         => false,
				'address_differs' => $this->addressDiffers( $first, $customer ),
			);
		}
		if ( 'match_only' === $policy ) {
			return Refusal::value(
				LexwareOfficeConnector::LABEL,
				'customer.email',
				__( 'Lexware Office has no contact with this email address and the customer setting is match only.', 'estasoft-erp-crm-sync-engine' ),
				'no matching contact'
			);
		}
		$body = $this->body( $customer );
		if ( $body instanceof ConnectorError ) {
			return $body;
		}
		$created = $api->post( 'contacts', array(), $body )->json();
		$id      = (string) ( $created['id'] ?? '' );
		if ( '' === $id ) {
			return Refusal::value( LexwareOfficeConnector::LABEL, 'contact', __( 'Lexware Office did not return the new contact.', 'estasoft-erp-crm-sync-engine' ), 'no contact id' );
		}
		return array(
			'id'              => $id,
			'created'         => true,
			'address_differs' => false,
		);
	}

	/**
	 * The create request for a contact.
	 *
	 * @param array<string,string> $customer Customer block.
	 * @return array<string,mixed>|ConnectorError
	 */
	public function body( array $customer ): array|ConnectorError {
		$last  = '' !== $customer['last_name'] ? $customer['last_name'] : $customer['name'];
		$first = $customer['first_name'];
		$body  = array(
			'version' => 0,
			'roles'   => array( 'customer' => new \stdClass() ),
		);
		if ( '' !== $customer['company'] ) {
			$person = array(
				'lastName' => $last,
				'primary'  => true,
			);
			if ( '' !== $first ) {
				$person['firstName'] = $first;
			}
			if ( '' !== $customer['email'] ) {
				$person['emailAddress'] = $customer['email'];
			}
			if ( '' !== $customer['phone'] ) {
				$person['phoneNumber'] = $customer['phone'];
			}
			$company = array(
				'name'           => $customer['company'],
				'contactPersons' => array( $person ),
			);
			if ( '' !== $customer['vat_id'] ) {
				$company['vatRegistrationId'] = $customer['vat_id'];
			}
			$body['company'] = $company;
		} else {
			$body['person'] = array( 'lastName' => $last );
			if ( '' !== $first && '' !== $customer['last_name'] ) {
				$body['person']['firstName'] = $first;
			}
		}
		$list = '' !== $customer['company'] ? 'business' : 'private';
		if ( '' !== $customer['email'] ) {
			$body['emailAddresses'] = array( $list => array( $customer['email'] ) );
		}
		if ( '' !== $customer['phone'] ) {
			$body['phoneNumbers'] = array( $list => array( $customer['phone'] ) );
		}
		if ( '' !== $customer['country'] ) {
			$address = array( 'countryCode' => $customer['country'] );
			foreach ( array(
				'street'     => 'street',
				'supplement' => 'supplement',
				'zip'        => 'zip',
				'city'       => 'city',
			) as $from => $to ) {
				if ( '' !== $customer[ $from ] ) {
					$address[ $to ] = $customer[ $from ];
				}
			}
			$body['addresses'] = array( 'billing' => array( $address ) );
		}
		if ( '' !== $customer['note'] ) {
			$body['note'] = mb_substr( $customer['note'], 0, 1000 );
		}
		return $body;
	}

	/**
	 * The name of a contact for display.
	 *
	 * @param array<string,mixed> $contact Contact from the API.
	 */
	public function nameOf( array $contact ): string {
		if ( isset( $contact['company']['name'] ) ) {
			return (string) $contact['company']['name'];
		}
		$person = (array) ( $contact['person'] ?? array() );
		return trim( (string) ( $person['firstName'] ?? '' ) . ' ' . (string) ( $person['lastName'] ?? '' ) );
	}

	/**
	 * Escape the characters Lexware treats as wildcards in a filter.
	 *
	 * @param string $text Search text.
	 */
	private function escape( string $text ): string {
		return str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $text );
	}

	/**
	 * Whether a contact carries the email, in any list or contact person.
	 *
	 * @param array<string,mixed> $contact Contact.
	 * @param string              $email   Lower-case email.
	 */
	private function hasEmail( array $contact, string $email ): bool {
		$lists = (array) ( $contact['emailAddresses'] ?? array() );
		foreach ( $lists as $addresses ) {
			foreach ( (array) $addresses as $address ) {
				if ( is_string( $address ) && strtolower( trim( $address ) ) === $email ) {
					return true;
				}
			}
		}
		foreach ( (array) ( $contact['company']['contactPersons'] ?? array() ) as $person ) {
			if ( is_array( $person ) && strtolower( trim( (string) ( $person['emailAddress'] ?? '' ) ) ) === $email ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the contact's billing address is not the order's.
	 *
	 * @param array<string,mixed>  $contact  Contact.
	 * @param array<string,string> $customer Customer block.
	 */
	private function addressDiffers( array $contact, array $customer ): bool {
		$billing = (array) ( ( (array) ( $contact['addresses']['billing'] ?? array() ) )[0] ?? array() );
		if ( array() === $billing || '' === $customer['zip'] ) {
			return false;
		}
		$same = 0 === strcasecmp( trim( (string) ( $billing['zip'] ?? '' ) ), $customer['zip'] )
			&& 0 === strcasecmp( trim( (string) ( $billing['street'] ?? '' ) ), $customer['street'] );
		return ! $same;
	}
}
