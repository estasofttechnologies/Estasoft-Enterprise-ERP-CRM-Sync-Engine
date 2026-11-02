<?php
/**
 * Wraps a connector so exceptions become ConnectorErrors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Connector methods let ApiException propagate (the client throws on every failure). This decorator catches it at the boundary and returns
 * the ConnectorError the engine expects, with the status, wait and short redacted detail the failure carried. The registry hands out the
 * wrapped connector; `inner()` gives the real one. ConnectorsModule creates it for every AbstractApiConnector.
 */
class GuardedConnector implements ConnectorInterface {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorInterface $inner The connector.
	 */
	public function __construct( protected readonly ConnectorInterface $inner ) {
	}

	/**
	 * Wrap a connector.
	 *
	 * @param ConnectorInterface     $connector Connector.
	 * @param OAuthTokenManager|null $tokens    Token manager; with it a connector that signs in on the vendor's page is wrapped so the sign-in routes find it.
	 */
	public static function wrap( ConnectorInterface $connector, ?OAuthTokenManager $tokens = null ): self {
		if ( $connector instanceof self ) {
			return $connector;
		}
		if ( $connector instanceof OAuthConnector && $connector instanceof AbstractApiConnector && $connector->authStyle()->needsSignIn() && null !== $tokens ) {
			return new GuardedSignInConnector( $connector, $tokens );
		}
		return new self( $connector );
	}

	/**
	 * The real connector.
	 */
	public function inner(): ConnectorInterface {
		return $this->inner;
	}

	/**
	 * Forward calls to connector methods that are not part of the interface (listTaxes, listTaxCodes, exportFormat).
	 *
	 * @param string           $name      Method.
	 * @param array<int,mixed> $arguments Arguments.
	 * @return mixed
	 *
	 * @throws \BadMethodCallException When the connector has no such method.
	 */
	public function __call( string $name, array $arguments ): mixed {
		if ( ! method_exists( $this->inner, $name ) ) {
			throw new \BadMethodCallException( 'Unknown connector method ' . $name . '.' );
		}
		return $this->inner->$name( ...$arguments );
	}

	/**
	 * Tax codes for the tax mapping screen (Rest\Tax\ConnectorTaxCodes looks for this method with method_exists).
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,label:string,rate_percent:float}>|ConnectorError
	 */
	public function listTaxes( Connection $connection ): array|ConnectorError {
		if ( ! method_exists( $this->inner, 'listTaxes' ) ) {
			return array();
		}
		try {
			return $this->inner->listTaxes( $connection );
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Whether the real connector has a method (used where code checks method_exists on the registry's connector).
	 *
	 * @param string $name Method.
	 */
	public function has( string $name ): bool {
		return method_exists( $this->inner, $name );
	}

	/**
	 * Stable id.
	 */
	public function id(): string {
		return $this->inner->id();
	}

	/**
	 * Name shown to the person.
	 */
	public function label(): string {
		return $this->inner->label();
	}

	/**
	 * Type.
	 */
	public function type(): ConnectorType {
		return $this->inner->type();
	}

	/**
	 * Contract version.
	 */
	public function contractVersion(): string {
		return $this->inner->contractVersion();
	}

	/**
	 * Capability flags.
	 *
	 * @return array<string,bool>
	 */
	public function capabilities(): array {
		return $this->inner->capabilities();
	}

	/**
	 * Connect form fields.
	 *
	 * @return array<int,CredentialField>
	 */
	public function credentialFields(): array {
		return $this->inner->credentialFields();
	}

	/**
	 * Test connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public function test( Connection $connection ): TestResult {
		try {
			return $this->inner->test( $connection );
		} catch ( \Throwable $throwable ) {
			return new TestResult( false, '', '', array(), array(), $this->error( $throwable ) );
		}
	}

	/**
	 * Health probe.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->inner->ping( $connection );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Target fields.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	public function describeFields( string $mappingType ): array {
		return $this->inner->describeFields( $mappingType );
	}

	/**
	 * Default mapping.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	public function defaultMapping( string $mappingType ): array {
		return $this->inner->defaultMapping( $mappingType );
	}

	/**
	 * Look for an existing record.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		try {
			return $this->inner->findExisting( $connection, $item );
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Send an item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		try {
			return $this->inner->send( $connection, $item, $payload );
		} catch ( \Throwable $throwable ) {
			return SendResult::failure( $this->error( $throwable ) );
		}
	}

	/**
	 * Look up a customer.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		try {
			return $this->inner->lookupCustomer( $connection, $email );
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Look up a product.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		try {
			return $this->inner->lookupProduct( $connection, $sku );
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Search products.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		try {
			return $this->inner->searchProducts( $connection, $query );
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Stock quantities.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouses.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		try {
			return $this->inner->fetchStock( $connection, $skus, $warehouses );
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Delivery records.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $since      UTC.
	 * @return array<int,array{order_ref:string,status:string,carrier:string,tracking:string,done_at:string}>|ConnectorError
	 */
	public function fetchTracking( Connection $connection, \DateTimeImmutable $since ): array|ConnectorError {
		try {
			return $this->inner->fetchTracking( $connection, $since );
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Invoice facts.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        Reference.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		try {
			return $this->inner->fetchInvoice( $connection, $ref );
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Records for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		try {
			return $this->inner->listForReconcile( $connection, $from );
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Classification override.
	 *
	 * @param ConnectorError $error Failure.
	 */
	public function classifyError( ConnectorError $error ): ?ErrorClass {
		return $this->inner->classifyError( $error );
	}

	/**
	 * Limits.
	 *
	 * @return array{requests_per_second:float,concurrency:int}
	 */
	public function rateLimitHints(): array {
		return $this->inner->rateLimitHints();
	}

	/**
	 * Entity types.
	 *
	 * @return array<int,\Estasoft\SyncEngine\Domain\EntityType>
	 */
	public function supportedEntities(): array {
		return $this->inner->supportedEntities();
	}

	/**
	 * The error for a throwable.
	 *
	 * @param \Throwable $throwable Whatever was thrown.
	 */
	protected function error( \Throwable $throwable ): ConnectorError {
		return ErrorBridge::toError( $throwable, $this->inner->label() );
	}
}
