<?php
/**
 * A connector that records what it is asked and refuses to send.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes;

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

/**
 * Read-only lookups answer from tables set by the test; send() and findExisting() throw, so a test that previews or builds a payload
 * fails loudly if anything tries to write to the system. Every call is recorded.
 */
final class FakeConnector implements ConnectorInterface {

	/**
	 * Calls made, as "method:argument".
	 *
	 * @var array<int,string>
	 */
	public array $calls = array();

	/**
	 * Products the system knows, by SKU.
	 *
	 * @var array<string,ExternalRef>
	 */
	public array $products = array();

	/**
	 * Customers the system knows, by lower-case email.
	 *
	 * @var array<string,ExternalRef>
	 */
	public array $customers = array();

	/**
	 * When set, product lookups return this error.
	 */
	public ?ConnectorError $lookupError = null;

	/**
	 * Fields offered by describeFields(), by mapping type.
	 *
	 * @var array<string,array<int,array{path:string,label:string,type:string,required:bool,group:string}>>
	 */
	public array $fields = array();

	public function id(): string {
		return 'fake';
	}

	public function label(): string {
		return 'Fake';
	}

	public function type(): ConnectorType {
		return ConnectorType::Erp;
	}

	public function contractVersion(): string {
		return ConnectorInterface::CONTRACT_VERSION;
	}

	public function capabilities(): array {
		return array();
	}

	public function credentialFields(): array {
		return array( new CredentialField( 'api_key', 'API key', true ) );
	}

	public function test( Connection $connection ): TestResult {
		return new TestResult( true );
	}

	public function ping( Connection $connection ): bool {
		return true;
	}

	public function describeFields( string $mappingType ): array {
		return $this->fields[ $mappingType ] ?? array();
	}

	public function defaultMapping( string $mappingType ): array {
		return array();
	}

	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$this->calls[] = 'findExisting';
		throw new \LogicException( 'findExisting must not be called while building or previewing.' );
	}

	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$this->calls[] = 'send';
		throw new \LogicException( 'send must not be called while building or previewing.' );
	}

	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$this->calls[] = 'lookupCustomer:' . $email;
		return $this->customers[ strtolower( $email ) ] ?? null;
	}

	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$this->calls[] = 'lookupProduct:' . $sku;
		return $this->lookupError ?? ( $this->products[ $sku ] ?? null );
	}

	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		return array();
	}

	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return array();
	}

	public function fetchTracking( Connection $connection, \DateTimeImmutable $since ): array|ConnectorError {
		return array();
	}

	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return null;
	}

	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return array();
	}

	public function classifyError( ConnectorError $error ): ?ErrorClass {
		return null;
	}

	public function rateLimitHints(): array {
		return array(
			'requests_per_second' => 1.0,
			'concurrency'         => 1,
		);
	}

	public function supportedEntities(): array {
		return array( EntityType::Order, EntityType::Refund, EntityType::Customer );
	}
}
