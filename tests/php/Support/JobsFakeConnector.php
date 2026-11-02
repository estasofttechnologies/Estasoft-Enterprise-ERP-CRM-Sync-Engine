<?php
/**
 * A connector whose answers the test decides.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\Item;

final class JobsFakeConnector implements ConnectorInterface {

	/**
	 * Capability flags.
	 *
	 * @var array<string,bool>
	 */
	public array $flags = array(
		'orders'           => true,
		'stock_in'         => true,
		'tracking_in'      => true,
		'invoice_readback' => true,
		'reconciliation'   => true,
		'shadow_lookup'    => true,
		'webhooks'         => false,
	);

	/**
	 * Stock answer by SKU: array( quantity, held ).
	 *
	 * @var array<string,array{quantity:int,held:int}>
	 */
	public array $stock = array();

	/**
	 * Delivery rows.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $deliveries = array();

	/**
	 * Invoice facts by the system's order id (null: no invoice yet).
	 *
	 * @var array<string,array<string,string>|null>
	 */
	public array $invoiceFor = array();

	/**
	 * What the system holds, by client reference.
	 *
	 * @var array<string,ExternalRef>
	 */
	public array $documents = array();

	/**
	 * Result of test().
	 */
	public ?TestResult $testResult = null;

	/**
	 * What each test() call was given: address and the api key.
	 *
	 * @var array<int,array{address:mixed,api_key:string}>
	 */
	public array $testedWith = array();

	/**
	 * Default mapping rules by type.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $defaults = array();

	/**
	 * Failures to return or throw, by method name.
	 *
	 * @var array<string,ConnectorError|\Throwable>
	 */
	public array $fail = array();

	/**
	 * Called with the method name on every call that can fail (the tests use it to let time pass or to change the shop).
	 */
	public ?\Closure $afterCall = null;

	/**
	 * Whether a failure in `$fail` is used once and then forgotten.
	 */
	public bool $failOnce = false;

	/**
	 * Calls made: method and a short argument.
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	public array $calls = array();

	/**
	 * Warehouses asked for in the last stock call.
	 *
	 * @var array<int,string>
	 */
	public array $lastWarehouses = array();

	/**
	 * @param string $connectorId Id.
	 */
	public function __construct( private readonly string $connectorId = 'fake' ) {
	}

	/**
	 * Calls of one method.
	 *
	 * @param string $method Method name.
	 */
	public function count( string $method ): int {
		return count( array_filter( $this->calls, static fn( array $call ): bool => $call[0] === $method ) );
	}

	public function id(): string {
		return $this->connectorId;
	}

	public function label(): string {
		return 'Fakesystem';
	}

	public function type(): ConnectorType {
		return ConnectorType::Erp;
	}

	public function contractVersion(): string {
		return '1.0.0';
	}

	public function capabilities(): array {
		return $this->flags;
	}

	public function credentialFields(): array {
		return array(
			new CredentialField( 'address', 'Address', false, true ),
			new CredentialField( 'user', 'User', false, false ),
			new CredentialField( 'api_key', 'API key', true, true ),
		);
	}

	public function test( Connection $connection ): TestResult {
		$this->calls[] = array( 'test', $connection->config( 'address' ) );
		$this->testedWith[] = array(
			'address' => $connection->config( 'address' ),
			'api_key' => $connection->secret( 'api_key' ),
		);
		$failure = $this->failure( 'test' );
		if ( null !== $failure ) {
			return new TestResult( false, '', '', array(), array(), $failure );
		}
		return $this->testResult ?? new TestResult(
			true,
			'Fakesystem 18.0',
			'Cedarstone Home',
			array(
				array(
					'id'   => '1',
					'name' => 'Dubai warehouse',
				),
				array(
					'id'   => '2',
					'name' => 'Riyadh warehouse',
				),
			),
			array( 'create_orders' => true ),
			null,
			array(
				'currency'              => 'AED',
				'currency_decimals'     => 2,
				'product_count'         => 1204,
				'credential_expires_at' => '2027-02-01T00:00:00Z',
			)
		);
	}

	public function ping( Connection $connection ): bool {
		return true;
	}

	public function describeFields( string $mappingType ): array {
		return array();
	}

	public function defaultMapping( string $mappingType ): array {
		return $this->defaults[ $mappingType ] ?? array();
	}

	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$this->calls[] = array( 'findExisting', $item->clientReference() );
		$failure = $this->failure( 'findExisting' );
		if ( null !== $failure ) {
			return $failure;
		}
		return $this->documents[ $item->clientReference() ] ?? null;
	}

	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$this->calls[] = array( 'send', $item->id );
		return SendResult::created( new ExternalRef( '900', 'S900' ) );
	}

	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		return null;
	}

	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		return null;
	}

	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		return array();
	}

	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		$this->calls[]        = array( 'fetchStock', $skus );
		$this->lastWarehouses = $warehouses;
		$failure = $this->failure( 'fetchStock' );
		if ( null !== $failure ) {
			return $failure;
		}
		return array_intersect_key( $this->stock, array_flip( $skus ) );
	}

	public function fetchTracking( Connection $connection, \DateTimeImmutable $since ): array|ConnectorError {
		$this->calls[] = array( 'fetchTracking', $since->format( 'c' ) );
		$failure = $this->failure( 'fetchTracking' );
		if ( null !== $failure ) {
			return $failure;
		}
		return $this->deliveries;
	}

	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		$this->calls[] = array( 'fetchInvoice', $ref->id );
		$failure = $this->failure( 'fetchInvoice' );
		if ( null !== $failure ) {
			return $failure;
		}
		return $this->invoiceFor[ $ref->id ] ?? null;
	}

	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		$this->calls[] = array( 'listForReconcile', $from->format( 'c' ) );
		$failure = $this->failure( 'listForReconcile' );
		if ( null !== $failure ) {
			return $failure;
		}
		return $this->documents;
	}

	public function classifyError( ConnectorError $error ): ?ErrorClass {
		return null;
	}

	public function rateLimitHints(): array {
		return array(
			'requests_per_second' => 4.0,
			'concurrency'         => 2,
		);
	}

	public function supportedEntities(): array {
		return array( EntityType::Order, EntityType::Refund );
	}

	/**
	 * The failure set for a method: thrown when it is an exception, returned when it is a connector error.
	 *
	 * @param string $method Method name.
	 *
	 * @throws \Throwable The failure, when it is an exception.
	 */
	private function failure( string $method ): ?ConnectorError {
		if ( null !== $this->afterCall ) {
			( $this->afterCall )( $method );
		}
		$failure = $this->fail[ $method ] ?? null;
		if ( $this->failOnce ) {
			unset( $this->fail[ $method ] );
		}
		if ( $failure instanceof \Throwable ) {
			throw $failure;
		}
		return $failure;
	}
}
