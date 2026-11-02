<?php
/**
 * The contract rules of the specification, run against the Sage 200 connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage200;

use Estasoft\SyncEngine\Connectors\Sage200\Sage200Connector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeSage200Server;

final class Sage200ConnectorContractTest extends ApiConnectorContractTestCase {

	private FakeSage200Server $server;

	private ?Connection $working = null;

	protected function setUp(): void {
		parent::setUp();
		$this->server  = new FakeSage200Server( $this->kit->http );
		$this->working = null;
	}

	protected function apiConnector(): AbstractApiConnector {
		return new Sage200Connector( $this->kit->deps );
	}

	/**
	 * Config of a connection that has chosen its site and company.
	 *
	 * @return array<string,string>
	 */
	private function config(): array {
		return array(
			'client_id'  => FakeSage200Server::CLIENT_ID,
			'edition'    => 'standard',
			'site_id'    => FakeSage200Server::SITE,
			'company_id' => FakeSage200Server::COMPANY,
		);
	}

	protected function workingConnection(): Connection {
		if ( null === $this->working ) {
			$this->working = $this->kit->connection( 'sage200', $this->config(), $this->server->signedIn( $this->kit->clock->now()->getTimestamp() ), 1 );
		}
		return $this->working;
	}

	protected function brokenConnection(): Connection {
		$secrets = array(
			'client_secret'       => 'sage-wrong-secret-00000000',
			'subscription_key'    => 'sage-wrong-subscription-key',
			'oauth_access_token'  => 'sage-stale-access-token',
			'oauth_refresh_token' => 'sage-stale-refresh-token',
			'oauth_expires_at'    => (string) ( $this->kit->clock->now()->getTimestamp() - 600 ),
		);
		return $this->kit->connection( 'sage200', $this->config(), $secrets, 2 );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item    = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, hash( 'sha256', $orderId ), ItemState::Syncing );
		$payload = array(
			// Sage 200 takes orders only in the base currency of the company, so the currency the contract asks for is not used; the amounts are.
			'currency'   => 'GBP',
			'date_order' => '2026-11-02',
			'partner'    => array(
				'name'    => (string) ( $options['name'] ?? 'Amelia Hart' ),
				'email'   => (string) ( $options['email'] ?? 'buyer' . $orderId . '@example.test' ),
				'street'  => (string) ( $options['street'] ?? '4 Mill Lane' ),
				'city'    => 'Leeds',
				'country' => 'GB',
			),
			'order_line' => array(
				array(
					'product'    => 'CS-BOWL-02',
					'quantity'   => $options['quantity'] ?? 1,
					'price_unit' => $options['unit_minor'] ?? 2190,
				),
			),
		);
		return array( $item, $payload );
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		$this->server->failNextWrite( $kind, $seconds );
	}

	protected function secretValues(): array {
		return array( FakeSage200Server::CLIENT_SECRET, FakeSage200Server::SUBSCRIPTION_KEY, 'sage-wrong-secret-00000000', 'sage-wrong-subscription-key', 'sage-stale-access-token', 'sage-stale-refresh-token' );
	}
}
