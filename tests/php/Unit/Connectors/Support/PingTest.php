<?php
/**
 * The cheap health probe of the circuit breaker.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceBooksConnector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector
 * @covers \Estasoft\SyncEngine\Connectors\Support\ConnectorMeta
 */
final class PingTest extends TestCase {

	private ApiTestKit $kit;

	protected function setUp(): void {
		$this->kit = ApiTestKit::make();
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function withPingPath( ?string $path ): ReferenceBooksConnector {
		return new class( $this->kit->deps, $path ) extends ReferenceBooksConnector {
			public function __construct( \Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies $deps, private readonly ?string $path ) {
				parent::__construct( $deps );
			}
			protected function meta(): ConnectorMeta {
				return new ConnectorMeta(
					id: 'reference-books',
					label: 'Acme Books',
					type: ConnectorType::Accounting,
					capabilities: array( Capability::Orders ),
					entities: array( EntityType::Order ),
					auth: AuthStyle::Key,
					hosts: array( 'api.acme-books.test' ),
					pingPath: $this->path
				);
			}
		};
	}

	private function connection(): \Estasoft\SyncEngine\Domain\Connection {
		return $this->kit->connection( 'reference-books', array(), array( 'api_key' => 'k-0123456789abcdef' ) );
	}

	public function test_a_declared_ping_path_makes_the_probe_one_get_and_nothing_else(): void {
		$this->kit->http->fallback( static fn() => FakeHttpClient::json( array( 'ok' => true ) ) );
		$connector = $this->withPingPath( '/me' );

		$this->assertTrue( $connector->ping( $this->connection() ) );

		$this->assertSame( 1, $this->kit->http->count(), 'One call, not the four to six of identify().' );
		$this->assertSame( 'GET', $this->kit->http->last()['method'] );
		$this->assertSame( 'https://api.acme-books.test/v1/me', $this->kit->http->last()['url'] );
		$this->assertSame( 'k-0123456789abcdef', $this->kit->http->last()['headers']['X-Api-Key'], 'Authenticated like any call.' );
	}

	public function test_a_failing_probe_is_false_for_every_kind_of_failure_and_still_one_call(): void {
		foreach ( array( FakeHttpClient::status( 401, '{}' ), FakeHttpClient::status( 403, '{}' ), FakeHttpClient::status( 404, '{}' ), FakeHttpClient::status( 500, '{}' ), FakeHttpClient::timeout() ) as $answer ) {
			$before = $this->kit->http->count();
			$this->kit->http->queue( $answer );
			$this->assertFalse( $this->withPingPath( '/me' )->ping( $this->connection() ) );
			$this->assertSame( $before + 1, $this->kit->http->count() );
		}
	}

	public function test_without_a_ping_path_the_probe_falls_back_to_identify(): void {
		$this->kit->http->route( 'GET', '#/company$#', FakeHttpClient::json( array( 'version' => '2', 'name' => 'Acme', 'plan' => 'pro', 'currency' => 'AED', 'permissions' => array( 'invoices:write' => true ) ) ) );
		$this->kit->http->route( 'GET', '#/tax-rates$#', FakeHttpClient::json( array( 'items' => array( array( 'id' => 1 ) ) ) ) );

		$this->assertTrue( $this->withPingPath( null )->ping( $this->connection() ) );
		$this->assertSame( 2, $this->kit->http->count(), 'identify() makes two calls here.' );
		$this->assertTrue( $this->withPingPath( '' )->ping( $this->connection() ), 'An empty path is no path.' );
		$this->assertSame( 4, $this->kit->http->count() );
	}

	public function test_the_probe_goes_through_the_guarded_connector_and_a_missing_key_is_false_without_a_call(): void {
		$this->kit->http->fallback( static fn() => FakeHttpClient::json( array() ) );
		$guarded = GuardedConnector::wrap( $this->withPingPath( '/me' ) );
		$this->assertTrue( $guarded->ping( $this->connection() ) );
		$this->assertSame( 1, $this->kit->http->count() );

		$noKey = $this->kit->connection( 'reference-books', array(), array(), 2 );
		$this->assertFalse( $guarded->ping( $noKey ) );
		$this->assertSame( 1, $this->kit->http->count(), 'No key, nothing sent.' );
	}
}
