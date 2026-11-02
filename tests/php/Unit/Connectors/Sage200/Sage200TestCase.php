<?php
/**
 * Shared set-up for the Sage 200 connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage200;

use Estasoft\SyncEngine\Connectors\Sage200\Sage200Connector;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeSage200Server;
use PHPUnit\Framework\TestCase;

/**
 * A fake Sage 200 API, a connector wired to it (wrapped the way the registry wraps it), and items and payloads to send.
 */
abstract class Sage200TestCase extends TestCase {

	protected ApiTestKit $kit;

	protected FakeSage200Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->kit    = ApiTestKit::make();
		$this->server = new FakeSage200Server( $this->kit->http );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * The connector as production hands it out.
	 */
	protected function connector(): GuardedConnector {
		return GuardedConnector::wrap( new Sage200Connector( $this->kit->deps ), $this->kit->tokens );
	}

	/**
	 * A signed-in connection with a site and company chosen.
	 *
	 * @param array<string,mixed> $config Extra config (options).
	 */
	protected function connection( array $config = array() ): Connection {
		return $this->kit->connection(
			'sage200',
			$config + array(
				'client_id'    => FakeSage200Server::CLIENT_ID,
				'edition'      => 'standard',
				'site_id'      => FakeSage200Server::SITE,
				'company_id'   => FakeSage200Server::COMPANY,
				'company_name' => 'Dunes Trading Ltd (Head office)',
			),
			$this->server->signedIn( $this->kit->clock->now()->getTimestamp() )
		);
	}

	/**
	 * A queue item.
	 *
	 * @param EntityType $type Type.
	 * @param string     $id   Entity id.
	 */
	protected function item( EntityType $type, string $id ): Item {
		return new Item( 1, 1, $type, $id, 'created', $type->value . ':' . $id, hash( 'sha256', $id ), ItemState::Syncing );
	}

	/**
	 * A mapped order payload (pounds, a buyer in the UK).
	 *
	 * @param array<string,mixed> $override Values to merge over the default.
	 * @return array<string,mixed>
	 */
	protected function order( array $override = array() ): array {
		$payload = array(
			'client_ref' => 'WC-100',
			'currency'   => 'GBP',
			'date_order' => '2026-11-02',
			'partner'    => array(
				'name'    => 'Amelia Hart',
				'email'   => 'amelia@example.test',
				'phone'   => '+441632960001',
				'street'  => '4 Mill Lane',
				'city'    => 'Leeds',
				'zip'     => 'LS1 4AB',
				'country' => 'GB',
			),
			'order_line' => array(
				array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 2190, 'tax' => '1' ),
			),
			'total'      => 5256,
		);
		return array_replace_recursive( $payload, $override );
	}

	/**
	 * A mapped refund payload.
	 *
	 * @param array<string,mixed> $override Values to merge over the default.
	 * @return array<string,mixed>
	 */
	protected function refund( array $override = array() ): array {
		return array_replace(
			array(
				'order_ref'   => 'WC-100',
				'date'        => '2026-11-03',
				'reason'      => 'Damaged',
				'amount'      => 2628,
				'refund_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 2190 ) ),
			),
			$override
		);
	}

	/**
	 * Send an order and return the result.
	 *
	 * @param array<string,mixed> $config  Connection config.
	 * @param array<string,mixed> $payload Payload override.
	 * @param string              $id      Order id.
	 */
	protected function sendOrder( array $config = array(), array $payload = array(), string $id = '100' ) {
		return $this->connector()->send( $this->connection( $config ), $this->item( EntityType::Order, $id ), $this->order( $payload ) );
	}

	/**
	 * A failure message with its reason, for assertions.
	 *
	 * @param mixed $result Send result.
	 */
	protected function why( $result ): string {
		return null === $result->error ? '' : $result->error->message() . ' ' . (string) ( $result->error->context['reason'] ?? '' );
	}
}
