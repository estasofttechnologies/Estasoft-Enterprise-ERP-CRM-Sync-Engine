<?php
/**
 * A minimal connector for the REST tests.
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
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Answers searches and tax code lists from arrays, and never touches a network. It also has `listTaxes()`, the optional method the
 * tax screen looks for.
 */
class RestFakeConnector implements ConnectorInterface {

	/**
	 * What searchProducts() returns.
	 *
	 * @var array<int,ExternalRef>|ConnectorError
	 */
	public array|ConnectorError $search = array();

	/**
	 * What listTaxes() returns.
	 *
	 * @var array<int,array{code:string,label:string,rate_percent:float}>|ConnectorError
	 */
	public array|ConnectorError $taxes = array();

	/**
	 * Queries searchProducts() was called with.
	 *
	 * @var array<int,string>
	 */
	public array $searched = array();

	/**
	 * @param string $connectorId Connector id.
	 */
	public function __construct( private readonly string $connectorId = 'restfake' ) {
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
		return 'Rest Fake';
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
		return ConnectorInterface::CONTRACT_VERSION;
	}

	/**
	 * Capabilities.
	 *
	 * @return array<string,bool>
	 */
	public function capabilities(): array {
		return array();
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
		return true;
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
	 * Find existing.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		return null;
	}

	/**
	 * Send.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		return SendResult::created( new ExternalRef( '1', 'S1' ) );
	}

	/**
	 * Look up a customer.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		return null;
	}

	/**
	 * Look up a product.
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
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$this->searched[] = $query;
		return $this->search;
	}

	/**
	 * Sale taxes (optional method of a connector).
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,label:string,rate_percent:float}>|ConnectorError
	 */
	public function listTaxes( Connection $connection ): array|ConnectorError {
		return $this->taxes;
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
	 * @param ExternalRef $ref        Ref.
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return null;
	}

	/**
	 * List for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       From.
	 * @return array<string,ExternalRef>
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return array();
	}

	/**
	 * Classify.
	 *
	 * @param ConnectorError $error Error.
	 */
	public function classifyError( ConnectorError $error ): ?ErrorClass {
		return null;
	}

	/**
	 * Hints.
	 *
	 * @return array{requests_per_second:float,concurrency:int}
	 */
	public function rateLimitHints(): array {
		return array(
			'requests_per_second' => 4.0,
			'concurrency'         => 2,
		);
	}

	/**
	 * Entities.
	 *
	 * @return array<int,EntityType>
	 */
	public function supportedEntities(): array {
		return array( EntityType::Order );
	}
}
