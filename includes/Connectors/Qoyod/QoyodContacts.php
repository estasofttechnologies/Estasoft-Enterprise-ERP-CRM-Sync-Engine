<?php
/**
 * Finds and creates Qoyod customers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * A customer is found with a Ransack search on the email (`q[email_cont]`), and every page of the answer is checked locally for an exact,
 * case-insensitive match, because `cont` also matches longer addresses.
 */
final class QoyodContacts {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QoyodPager $pager Reads every page of a list.
	 */
	public function __construct( private readonly QoyodPager $pager ) {
	}

	/**
	 * Find the customer of an email address.
	 *
	 * @param ApiClient $api   Client.
	 * @param string    $email Email address.
	 * @return array<string,mixed>|null The customer, or null.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, string $email ): ?array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		foreach ( $this->pager->all( $api, 'customers', array( 'q' => array( 'email_cont' => $email ) ), 'contacts', static fn( array $row ): bool => str_contains( strtolower( QoyodPayloadReader::text( $row['email'] ?? '' ) ), $email ) ) as $row ) {
			if ( strtolower( QoyodPayloadReader::text( $row['email'] ?? '' ) ) === $email ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The id of the customer: the existing one, or a new one.
	 *
	 * @param ApiClient            $api      Client.
	 * @param array<string,string> $customer Customer fields from the payload.
	 * @return string|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( ApiClient $api, array $customer ): string|ConnectorError {
		$existing = $this->find( $api, $customer['email'] );
		if ( null !== $existing && '' !== QoyodPayloadReader::text( $existing['id'] ?? '' ) ) {
			return QoyodPayloadReader::text( $existing['id'] );
		}
		return $this->create( $api, $customer );
	}

	/**
	 * Create a customer.
	 *
	 * @param ApiClient            $api      Client.
	 * @param array<string,string> $customer Customer fields.
	 * @return string|ConnectorError The new id.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( ApiClient $api, array $customer ): string|ConnectorError {
		$name    = '' !== $customer['company'] ? $customer['company'] : $customer['name'];
		$contact = array(
			'name'   => QoyodPayloadReader::shorten( $name, 200 ),
			'email'  => $customer['email'],
			'status' => 'Active',
		);
		if ( '' !== $customer['company'] && $customer['name'] !== $customer['company'] ) {
			$contact['organization'] = $customer['company'];
		}
		$map = array(
			'phone'      => 'phone_number',
			'tax_number' => 'tax_number',
			'address'    => 'billing_address',
			'city'       => 'billing_city',
			'state'      => 'billing_state',
			'postcode'   => 'billing_zip',
			'country'    => 'billing_country',
		);
		foreach ( $map as $from => $to ) {
			if ( '' !== $customer[ $from ] ) {
				$contact[ $to ] = $customer[ $from ];
			}
		}
		$json = $api->post( 'customers', array(), array( 'contact' => $contact ) )->json();
		$id   = QoyodRecord::unwrap( $json, 'contact' )->id();
		if ( '' === $id ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => QoyodConnector::LABEL,
					'field'  => 'contact',
					'reason' => __( 'Qoyod did not return the id of the new customer.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				null,
				null,
				null,
				'contact without id'
			);
		}
		return $id;
	}
}
