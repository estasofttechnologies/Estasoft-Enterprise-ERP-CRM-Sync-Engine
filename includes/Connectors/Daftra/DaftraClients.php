<?php
/**
 * Finds and creates Daftra clients.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Daftra documents a `keywords` search on clients and no email filter. A client is found by searching the email as keywords and checking every
 * page of the answer locally for an exact, case-insensitive email; a miss creates the client.
 */
final class DaftraClients {

	/**
	 * Set up with what it depends on.
	 *
	 * @param DaftraPager $pager Reads every page of a list.
	 */
	public function __construct( private readonly DaftraPager $pager ) {
	}

	/**
	 * Find the client of an email address.
	 *
	 * @param ApiClient $api   Client.
	 * @param string    $email Email address.
	 * @return array<string,mixed>|null The client, or null.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, string $email ): ?array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		foreach ( $this->pager->all( $api, 'clients.json', array( 'keywords' => $email ), 'Client' ) as $row ) {
			if ( strtolower( DaftraPayloadReader::text( $row['email'] ?? '' ) ) === $email ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The id of the client: the existing one, or a new one.
	 *
	 * @param ApiClient            $api      Client.
	 * @param array<string,string> $customer Customer fields from the payload.
	 * @param string               $currency Currency of the order.
	 * @return string|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( ApiClient $api, array $customer, string $currency ): string|ConnectorError {
		$existing = $this->find( $api, $customer['email'] );
		if ( null !== $existing && '' !== DaftraPayloadReader::text( $existing['id'] ?? '' ) ) {
			return DaftraPayloadReader::text( $existing['id'] );
		}
		return $this->create( $api, $customer, $currency );
	}

	/**
	 * Create a client.
	 *
	 * @param ApiClient            $api      Client.
	 * @param array<string,string> $customer Customer fields.
	 * @param string               $currency Currency of the order.
	 * @return string|ConnectorError The new id.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( ApiClient $api, array $customer, string $currency ): string|ConnectorError {
		$name   = '' !== $customer['company'] ? $customer['company'] : $customer['name'];
		$client = array(
			'business_name' => DaftraPayloadReader::shorten( $name, 200 ),
			'email'         => $customer['email'],
		);
		$map    = array(
			'phone'      => 'phone1',
			'address'    => 'address1',
			'city'       => 'city',
			'state'      => 'state',
			'postcode'   => 'postal_code',
			'tax_number' => 'bn1',
		);
		foreach ( $map as $from => $to ) {
			if ( '' !== $customer[ $from ] ) {
				$client[ $to ] = $customer[ $from ];
			}
		}
		if ( 1 === preg_match( '/^[A-Z]{2}$/', $customer['country'] ) ) {
			$client['country_code'] = $customer['country'];
		}
		if ( '' !== $currency ) {
			$client['default_currency_code'] = $currency;
		}
		$id = DaftraPayloadReader::text( $api->post( 'clients.json', array(), array( 'Client' => $client ) )->json()['id'] ?? '' );
		if ( '' === $id ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => DaftraConnector::LABEL,
					'field'  => 'Client',
					'reason' => __( 'Daftra did not return the id of the new client.', 'estasoft-erp-crm-sync-engine' ),
				),
				null,
				null,
				null,
				'client without id'
			);
		}
		return $id;
	}
}
