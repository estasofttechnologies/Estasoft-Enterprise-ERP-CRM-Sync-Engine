<?php
/**
 * A scriptable connector for the pipeline tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Keeps a small "system" of documents keyed by client reference. `send()` follows a script: each entry in `$script` is
 * used once, in order, and may be a SendResult, a ConnectorError (a failure), a Throwable (thrown), a callable, or
 * `array('create_then_fail', ConnectorError)` which creates the document and then reports a failure (a lost answer).
 * With an empty script `send()` creates a document.
 */
final class CoreFakeConnector implements ConnectorInterface {

	/**
	 * Script for send().
	 *
	 * @var array<int,mixed>
	 */
	public array $script = array();

	/**
	 * Documents by client reference.
	 *
	 * @var array<string,ExternalRef>
	 */
	public array $documents = array();

	/**
	 * What happened, in order: array( method, item id or null ).
	 *
	 * @var array<int,array{0:string,1:?int}>
	 */
	public array $calls = array();

	/**
	 * Answer of ping().
	 */
	public bool $pingResult = true;

	/**
	 * Class returned by classifyError().
	 */
	public ?ErrorClass $classOverride = null;

	/**
	 * Error that findExisting() returns, when set.
	 */
	public ?ConnectorError $existingError = null;

	/**
	 * Exception that findExisting() throws, when set.
	 */
	public ?\Throwable $existingThrow = null;

	/**
	 * Called at the end of findExisting(), to make something happen while the system is being asked.
	 */
	public ?\Closure $afterFind = null;

	/**
	 * Seconds the fake clock moves during each send().
	 */
	public int $sendSeconds = 0;

	/**
	 * Capability flags.
	 *
	 * @var array<string,bool>
	 */
	public array $capabilityFlags = array(
		'orders'        => true,
		'shadow_lookup' => true,
	);

	/**
	 * Entities the connector supports.
	 *
	 * @var array<int,EntityType>
	 */
	public array $entities = array( EntityType::Order, EntityType::Refund );

	/**
	 * Contract version it reports.
	 */
	public string $version = '1.0.0';

	/**
	 * Next number for new documents.
	 */
	private int $counter = 1;

	/**
	 * @param CoreFakeClock|null $clock Clock to move during send().
	 * @param string             $id    Connector id.
	 */
	public function __construct( private readonly ?CoreFakeClock $clock = null, private readonly string $connectorId = 'fake' ) {
	}

	/**
	 * Number of calls to a method.
	 *
	 * @param string $method Method name.
	 */
	public function count( string $method ): int {
		return count( array_filter( $this->calls, static fn( array $call ): bool => $call[0] === $method ) );
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return $this->connectorId;
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return 'Fakesystem';
	}

	/**
	 * Type.
	 */
	public function type(): ConnectorType {
		return ConnectorType::Erp;
	}

	/**
	 * Contract version.
	 */
	public function contractVersion(): string {
		return $this->version;
	}

	/**
	 * Capabilities.
	 *
	 * @return array<string,bool>
	 */
	public function capabilities(): array {
		return $this->capabilityFlags;
	}

	/**
	 * Credential fields.
	 *
	 * @return array<int,\Estasoft\SyncEngine\Contract\CredentialField>
	 */
	public function credentialFields(): array {
		return array();
	}

	/**
	 * Test.
	 *
	 * @param Connection $connection Connection.
	 */
	public function test( Connection $connection ): TestResult {
		return new TestResult( true );
	}

	/**
	 * Ping.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		$this->calls[] = array( 'ping', null );
		return $this->pingResult;
	}

	/**
	 * Fields.
	 *
	 * @param string $mappingType Type.
	 * @return array<int,array<string,mixed>>
	 */
	public function describeFields( string $mappingType ): array {
		return array();
	}

	/**
	 * Default mapping.
	 *
	 * @param string $mappingType Type.
	 * @return array<int,array<string,mixed>>
	 */
	public function defaultMapping( string $mappingType ): array {
		return array();
	}

	/**
	 * Find a document by client reference.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$this->calls[] = array( 'findExisting', $item->id );
		if ( null !== $this->existingThrow ) {
			throw $this->existingThrow;
		}
		$found = null !== $this->existingError ? $this->existingError : ( $this->documents[ $item->clientReference() ] ?? null );
		if ( null !== $this->afterFind ) {
			( $this->afterFind )();
		}
		return $found;
	}

	/**
	 * Send an item, following the script.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$this->calls[] = array( 'send', $item->id );
		if ( null !== $this->clock && $this->sendSeconds > 0 ) {
			$this->clock->advance( $this->sendSeconds );
		}
		$step = array() === $this->script ? null : array_shift( $this->script );
		if ( $step instanceof \Throwable ) {
			throw $step;
		}
		if ( is_callable( $step ) ) {
			$step = $step( $item, $payload );
		}
		if ( $step instanceof SendResult ) {
			return $step;
		}
		if ( $step instanceof ConnectorError ) {
			return SendResult::failure( $step );
		}
		if ( is_array( $step ) && 'create_then_fail' === ( $step[0] ?? '' ) ) {
			$this->create( $item );
			return SendResult::failure( $step[1] );
		}
		return SendResult::created( $this->create( $item ) );
	}

	/**
	 * Lookup customer.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		return null;
	}

	/**
	 * Lookup product.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		return null;
	}

	/**
	 * Search products.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Query.
	 * @return array<int,ExternalRef>
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		return array();
	}

	/**
	 * Fetch stock.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouses.
	 * @return array<string,array{quantity:int,held:int}>
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return array();
	}

	/**
	 * Fetch tracking.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $since      Since.
	 * @return array<int,array<string,string>>
	 */
	public function fetchTracking( Connection $connection, \DateTimeImmutable $since ): array|ConnectorError {
		return array();
	}

	/**
	 * Fetch invoice.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        Reference.
	 * @return array<string,string>|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return null;
	}

	/**
	 * List for reconcile.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       From.
	 * @return array<string,ExternalRef>
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return $this->documents;
	}

	/**
	 * Class override.
	 *
	 * @param ConnectorError $error Failure.
	 */
	public function classifyError( ConnectorError $error ): ?ErrorClass {
		return $this->classOverride;
	}

	/**
	 * Rate limit hints.
	 *
	 * @return array{requests_per_second:float,concurrency:int}
	 */
	public function rateLimitHints(): array {
		return array(
			'requests_per_second' => 1.0,
			'concurrency'         => 1,
		);
	}

	/**
	 * Supported entities.
	 *
	 * @return array<int,EntityType>
	 */
	public function supportedEntities(): array {
		return $this->entities;
	}

	/**
	 * Create a document for an item.
	 *
	 * @param Item $item Item.
	 */
	private function create( Item $item ): ExternalRef {
		$number = $this->counter++;
		$ref    = new ExternalRef( (string) ( 1000 + $number ), 'S' . ( 90000 + $number ), 'https://erp.example.test/o/' . ( 1000 + $number ) );
		$this->documents[ $item->clientReference() ] = $ref;
		return $ref;
	}
}
