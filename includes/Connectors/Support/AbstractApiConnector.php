<?php
/**
 * Base class of the connectors that talk to a web API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthRedirect;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;

/**
 * A connector extends this class and implements what is abstract: meta(), baseUrl(), authStrategy(), identify(), fieldCatalog(),
 * mappings(), findExisting() and send(). Everything else has a sound default: identity and flags come from meta(), the connect form from
 * the sign-in style, Test connection from identify(), and the optional lookups answer "nothing" for a capability the connector does not flag.
 *
 * The HTTP client `api()` throws ApiException for every failure. In production (and in ApiConnectorContractTestCase) the connector is
 * wrapped in GuardedConnector, which turns the exception into the ConnectorError the engine expects, so connector methods can let it
 * propagate. Code that calls a connector directly can use ErrorBridge::toError().
 */
abstract class AbstractApiConnector implements ConnectorInterface {

	/**
	 * Cached meta().
	 *
	 * @var ConnectorMeta|null
	 */
	private ?ConnectorMeta $metaCache = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function __construct( protected readonly ConnectorDependencies $deps ) {
	}

	/**
	 * Identity, flags, sign-in style, regions, limits and hosts.
	 */
	abstract protected function meta(): ConnectorMeta;

	/**
	 * The base address of the API for a connection (checked by HostPolicy).
	 *
	 * @param Connection $connection Connection.
	 */
	abstract protected function baseUrl( Connection $connection ): string;

	/**
	 * How requests are authenticated.
	 *
	 * @param Connection $connection Connection.
	 */
	abstract protected function authStrategy( Connection $connection ): AuthStrategy;

	/**
	 * What Test connection shows: organisation or company, version or plan, permissions, missing scopes, tax codes found.
	 *
	 * @param Connection $connection Connection.
	 */
	abstract protected function identify( Connection $connection ): TestResult;

	/**
	 * Target fields for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	abstract protected function fieldCatalog( string $mappingType ): array;

	/**
	 * Version 1 mapping rules for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array<string,mixed>>
	 */
	abstract protected function mappings( string $mappingType ): array;

	/**
	 * Headers sent with every call.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		return array();
	}

	/**
	 * Map a vendor-specific error body (validation, duplicate, plan gate AUTH-006, unknown app AUTH-007, expired key AUTH-005). Return null to use the default mapping.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		unset( $response );
		return null;
	}

	/**
	 * The header that carries `$options['idempotency_key']`, for vendors that accept one.
	 */
	protected function idempotencyHeader(): string {
		return 'Idempotency-Key';
	}

	/**
	 * The fields of the connect form. The default follows the sign-in style; override it for vendor fields (data centre, tenant, region).
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return CredentialFields::forStyle( $this->connectorMeta()->auth );
	}

	/**
	 * The limits that apply to this connection. The default is the limits of `meta()`; override it when the documented caps depend on the
	 * plan or edition the merchant has (read it from `$connection->config()`), so the pacer and the daily cap use the real numbers.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function limitsFor( Connection $connection ): RateLimitHints {
		unset( $connection );
		return $this->connectorMeta()->limits();
	}

	/**
	 * Values to remove from every message besides the secrets: hostnames and identifiers of the account. The default takes the host of the base
	 * address and the config values named tenant_id, tenant, database, company_id, account_id, realm, instance and organisation_id.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values = array();
		foreach ( array( 'tenant_id', 'tenant', 'database', 'company_id', 'account_id', 'realm', 'instance', 'organisation_id', 'org_id' ) as $key ) {
			$value = $connection->config( $key );
			if ( is_string( $value ) || is_int( $value ) ) {
				$values[] = (string) $value;
			}
		}
		return $values;
	}

	/**
	 * Tax codes of the system for the tax mapping screen: code, name and rate in percent as a decimal string.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		unset( $connection );
		return array();
	}

	/**
	 * The tax codes in the shape the REST layer reads (Rest\Tax\ConnectorTaxCodes calls listTaxes()).
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,label:string,rate_percent:float}>|ConnectorError
	 */
	public function listTaxes( Connection $connection ): array|ConnectorError {
		try {
			$codes = $this->listTaxCodes( $connection );
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label() );
		}
		if ( $codes instanceof ConnectorError ) {
			return $codes;
		}
		$taxes = array();
		foreach ( $codes as $code ) {
			$taxes[] = array(
				'code'         => (string) $code['code'],
				'label'        => (string) ( $code['name'] ?? $code['code'] ),
				'rate_percent' => (float) ( $code['rate_percent'] ?? 0 ),
			);
		}
		return $taxes;
	}

	/**
	 * A client for a connection: base address, authentication, host policy, pacing and redaction are set up.
	 *
	 * @param Connection $connection Connection with its secrets.
	 *
	 * @throws ApiException When the base address is refused.
	 */
	protected function api( Connection $connection ): ApiClient {
		$meta = $this->connectorMeta();
		$base = $this->baseUrl( $connection );
		$auth = $this->authStrategy( $connection );
		if ( $auth instanceof OAuth2Auth ) {
			$auth = $auth->withTokens( $this->deps->tokens );
		}
		$policy = HostPolicy::fromMeta( $meta );
		$policy->assert( $base, $meta->label );

		$names = array();
		foreach ( $this->credentialDefinition() as $field ) {
			if ( $field->secret ) {
				$names[] = $field->name;
			}
		}
		$redaction = Redaction::forConnection( $connection, $names, $this->redactionValues( $connection ) )->addHost( $base );

		return new ApiClient(
			$this->deps->http,
			$connection,
			$base,
			$auth,
			$policy,
			$meta->label,
			$redaction,
			$this->limitsFor( $connection ),
			$this->deps->pacer,
			$this->defaultHeaders( $connection ),
			fn( ApiResponse $response ): ?ConnectorError => $this->vendorError( $response ),
			$this->idempotencyHeader()
		);
	}

	/**
	 * The meta of this connector, built once.
	 */
	final protected function connectorMeta(): ConnectorMeta {
		if ( null === $this->metaCache ) {
			$this->metaCache = $this->meta();
		}
		return $this->metaCache;
	}

	/**
	 * How this connector signs in (the catalog and the connect form follow it).
	 */
	public function authStyle(): AuthStyle {
		return $this->connectorMeta()->auth;
	}

	/**
	 * Stable id.
	 */
	public function id(): string {
		return $this->connectorMeta()->id;
	}

	/**
	 * Name shown to the person.
	 */
	public function label(): string {
		return $this->connectorMeta()->label;
	}

	/**
	 * Erp, accounting or crm.
	 */
	public function type(): ConnectorType {
		return $this->connectorMeta()->type;
	}

	/**
	 * The contract version the connector was written for.
	 */
	public function contractVersion(): string {
		return $this->connectorMeta()->contractVersion;
	}

	/**
	 * Capability flags.
	 *
	 * @return array<string,bool>
	 */
	public function capabilities(): array {
		return $this->connectorMeta()->flags();
	}

	/**
	 * Entity types the connector sends.
	 *
	 * @return array<int,EntityType>
	 */
	public function supportedEntities(): array {
		return $this->connectorMeta()->entities;
	}

	/**
	 * Limits of the system.
	 *
	 * @return array{requests_per_second:float,concurrency:int}
	 */
	public function rateLimitHints(): array {
		return $this->connectorMeta()->limits()->toArray();
	}

	/**
	 * The fields of the connect form, plus the redirect address a sign-in connector shows the merchant.
	 *
	 * @return array<int,CredentialField>
	 */
	public function credentialFields(): array {
		$fields = $this->credentialDefinition();
		if ( $this->connectorMeta()->auth->needsSignIn() ) {
			$fields[] = CredentialFields::redirectUri();
		}
		return $fields;
	}

	/**
	 * Test connection: identify() with every failure turned into a result.
	 *
	 * @param Connection $connection Connection.
	 */
	public function test( Connection $connection ): TestResult {
		try {
			return $this->identify( $connection );
		} catch ( \Throwable $throwable ) {
			return new TestResult( false, '', '', array(), array(), ErrorBridge::toError( $throwable, $this->label() ) );
		}
	}

	/**
	 * Cheap health probe. The default is identify(); override with a lighter call where the vendor has one.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->identify( $connection )->ok;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Target fields for the mapping screens.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	public function describeFields( string $mappingType ): array {
		return array_map( static fn( FieldDef $field ): array => $field->toArray(), $this->fieldCatalog( $mappingType ) );
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public function defaultMapping( string $mappingType ): array {
		return $this->mappings( $mappingType );
	}

	/**
	 * Find a customer by email. Default: not supported.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		unset( $connection, $email );
		return null;
	}

	/**
	 * Find a product by SKU. Default: not supported.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		unset( $connection, $sku );
		return null;
	}

	/**
	 * Search products. Default: none.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		unset( $connection, $query );
		return array();
	}

	/**
	 * Stock quantities. Default: none (Jobs never calls this without the stock_in flag).
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouse ids.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		unset( $connection, $skus, $warehouses );
		return array();
	}

	/**
	 * Delivery records. Default: none.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $since      UTC.
	 * @return array<int,array{order_ref:string,status:string,carrier:string,tracking:string,done_at:string}>|ConnectorError
	 */
	public function fetchTracking( Connection $connection, \DateTimeImmutable $since ): array|ConnectorError {
		unset( $connection, $since );
		return array();
	}

	/**
	 * Invoice facts. Default: none.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The synced order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		unset( $connection, $ref );
		return null;
	}

	/**
	 * Records for the nightly check. Default: none.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		unset( $connection, $from );
		return array();
	}

	/**
	 * Override the catalog classification of a failure. Default: keep it.
	 *
	 * @param ConnectorError $error Failure.
	 */
	public function classifyError( ConnectorError $error ): ?ErrorClass {
		unset( $error );
		return null;
	}
}
