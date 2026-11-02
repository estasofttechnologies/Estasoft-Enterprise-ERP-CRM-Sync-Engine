<?php
/**
 * SMACC has no API: the connector cannot be selected, refuses to send, says why, and never touches the network.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Smacc;

use Estasoft\SyncEngine\Connectors\Smacc\SmaccConnector;
use Estasoft\SyncEngine\Connectors\Smacc\SmaccProvider;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportStore;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Smacc\SmaccConnector
 * @covers \Estasoft\SyncEngine\Connectors\Smacc\SmaccProvider
 */
final class SmaccConnectorTest extends TestCase {

	/**
	 * Temporary folder.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * The connector under test.
	 *
	 * @var SmaccConnector
	 */
	private SmaccConnector $connector;

	/**
	 * Build the connector over services that would fail the test if the network were touched.
	 */
	protected function setUp(): void {
		$this->dir       = FakeExportKit::folder( 'smacc' );
		$this->connector = new SmaccConnector( FakeExportKit::deps( $this->dir ) );
	}

	/**
	 * Remove the folder.
	 */
	protected function tearDown(): void {
		FakeExportKit::remove( $this->dir );
	}

	/**
	 * A connection to SMACC.
	 */
	private function connection(): \Estasoft\SyncEngine\Domain\Connection {
		return FakeExportKit::connection( 'smacc' );
	}

	/**
	 * The error a refused call carries.
	 *
	 * @param mixed $error Result of a call.
	 */
	private function assertRefusal( mixed $error ): void {
		$this->assertInstanceOf( ConnectorError::class, $error );
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertSame( ErrorClass::NeedsPerson, $error->errorClass(), 'A person has to act; the queue must not retry.' );
		$this->assertFalse( $error->code->retriesAutomatically() );
		$this->assertStringContainsString( 'SMACC publishes no public API yet, so the plugin cannot send orders to it.', $error->message() );
		$this->assertStringContainsString( 'SMACC rejected a field: connection.', $error->message() );
	}

	/**
	 * It cannot be selected, has no capability, entity, field, mapping or credential, and is an accounting system.
	 */
	public function test_it_cannot_be_selected_and_promises_nothing(): void {
		$this->assertSame( 'smacc', $this->connector->id() );
		$this->assertSame( 'SMACC', $this->connector->label() );
		$this->assertSame( ConnectorType::Accounting, $this->connector->type() );
		$this->assertFalse( $this->connector->available() );
		$this->assertSame( ConnectorInterface::CONTRACT_VERSION, $this->connector->contractVersion() );
		foreach ( $this->connector->capabilities() as $flag => $on ) {
			$this->assertFalse( $on, $flag . ' must not be promised: there is no API.' );
		}
		$this->assertSame( count( Capability::cases() ), count( $this->connector->capabilities() ) );
		$this->assertSame( array(), $this->connector->supportedEntities() );
		$this->assertSame( array(), $this->connector->credentialFields() );
		foreach ( array( 'orders', 'customers', 'products', 'refunds', 'stock', 'tracking' ) as $type ) {
			$this->assertSame( array(), $this->connector->describeFields( $type ) );
			$this->assertSame( array(), $this->connector->defaultMapping( $type ) );
		}
		$this->assertSame( array(), $this->connector->listTaxCodes( $this->connection() ) );
		$this->assertStringContainsString( 'no public API', $this->connector->unavailableReason() );
	}

	/**
	 * Sending an order, a refund or a customer is refused with the explanation.
	 *
	 * @dataProvider entities
	 *
	 * @param EntityType $type Entity type.
	 */
	public function test_send_is_refused_with_the_reason( EntityType $type ): void {
		$result = $this->connector->send( $this->connection(), FakeExportKit::item( 1, '1001', $type ), FakeExportKit::order() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertNull( $result->ref );
		$this->assertRefusal( $result->error );
	}

	/**
	 * Entity types.
	 *
	 * @return array<string,array{0:EntityType}>
	 */
	public static function entities(): array {
		return array(
			'order'    => array( EntityType::Order ),
			'refund'   => array( EntityType::Refund ),
			'customer' => array( EntityType::Customer ),
		);
	}

	/**
	 * Looking for an existing record is refused too, so a retry never reaches a made-up record.
	 */
	public function test_find_existing_is_refused(): void {
		$this->assertRefusal( $this->connector->findExisting( $this->connection(), FakeExportKit::item( 1, '1001' ) ) );
	}

	/**
	 * Test connection fails with the reason and ping says no.
	 */
	public function test_connection_test_and_ping(): void {
		$result = $this->connector->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertRefusal( $result->error );
		$this->assertFalse( $this->connector->ping( $this->connection() ) );
	}

	/**
	 * Lookups and read-backs have nothing to look in.
	 */
	public function test_lookups_return_nothing(): void {
		$connection = $this->connection();
		$this->assertNull( $this->connector->lookupCustomer( $connection, 'a@example.com' ) );
		$this->assertNull( $this->connector->lookupProduct( $connection, 'SKU' ) );
		$this->assertSame( array(), $this->connector->searchProducts( $connection, 'x' ) );
		$this->assertSame( array(), $this->connector->fetchStock( $connection, array( 'SKU' ), array() ) );
		$this->assertSame( array(), $this->connector->fetchTracking( $connection, new \DateTimeImmutable( '2026-11-01' ) ) );
		$this->assertNull( $this->connector->fetchInvoice( $connection, new \Estasoft\SyncEngine\Contract\ExternalRef( '1' ) ) );
		$this->assertSame( array(), $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2026-11-01' ) ) );
	}

	/**
	 * No call of any kind reaches the HTTP client, and no file is written.
	 */
	public function test_nothing_touches_the_network_or_the_disk(): void {
		$http = new FakeHttpClient();
		$deps = new \Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies(
			$http,
			new \Estasoft\SyncEngine\Tests\Support\CoreFakeClock(),
			( new \ReflectionClass( \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager::class ) )->newInstanceWithoutConstructor(),
			new ExportStore( static fn(): string => $this->dir, new \Estasoft\SyncEngine\Tests\Support\CoreFakeClock() ),
			( new \ReflectionClass( \Estasoft\SyncEngine\Core\Logging\Logger::class ) )->newInstanceWithoutConstructor()
		);
		$connector = new SmaccConnector( $deps );
		$connector->send( $this->connection(), FakeExportKit::item( 1, '1' ), array() );
		$connector->findExisting( $this->connection(), FakeExportKit::item( 1, '1' ) );
		$connector->test( $this->connection() );
		$connector->ping( $this->connection() );
		$connector->lookupCustomer( $this->connection(), 'a@example.com' );
		$this->assertSame( 0, $http->count() );
		$this->assertSame( array(), glob( $this->dir . '/*' ) ?: array() );
	}

	/**
	 * The provider builds this connector under the id the catalog uses.
	 */
	public function test_provider(): void {
		$provider = new SmaccProvider();
		$this->assertSame( 'smacc', $provider->id() );
		$this->assertInstanceOf( SmaccConnector::class, $provider->create( FakeExportKit::deps( $this->dir ) ) );
	}

	/**
	 * The front matter of the connector's document says the same: not available, no connection method, no host.
	 */
	public function test_document_front_matter_agrees(): void {
		$doc = (string) file_get_contents( dirname( __DIR__, 5 ) . '/docs/connectors/smacc.md' );
		$this->assertSame( 1, preg_match( '/\A---\n(.*?)\n---\n/s', $doc, $front ) );
		foreach ( array( 'id: smacc', 'available: false', 'connection_method: none', 'hosts: []', 'verified: false' ) as $line ) {
			$this->assertMatchesRegularExpression( '/^' . preg_quote( $line, '/' ) . '$/m', $front[1] );
		}
	}
}
