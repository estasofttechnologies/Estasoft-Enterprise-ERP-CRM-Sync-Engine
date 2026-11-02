<?php
/**
 * The Xero connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Connects the engine to a Xero organisation through the merchant's own Xero app (OAuth 2 authorization code). Orders become sales invoices,
 * refunds become credit notes, customers become contacts. See docs/connectors/xero.md for every endpoint, field and limit, and what is not verified.
 */
final class XeroConnector extends AbstractApiConnector implements OAuthConnector, OAuthProvider {

	/**
	 * The API root of the accounting API.
	 */
	private const API = 'https://api.xero.com/api.xro/2.0';

	/**
	 * The list of organisations the person allowed.
	 */
	private const CONNECTIONS = 'https://api.xero.com/connections';

	/**
	 * Helpers per connection, kept for the length of the request.
	 *
	 * @var array<string,XeroServices>
	 */
	private array $services = array();

	/**
	 * Identity, flags, sign-in style, regions, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'xero',
			label: 'Xero',
			type: ConnectorType::Accounting,
			capabilities: array(
				Capability::Orders,
				Capability::Customers,
				Capability::Refunds,
				Capability::StockIn,
				Capability::InvoiceReadback,
				Capability::Reconciliation,
				Capability::ShadowLookup,
			),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthAuthorizationCode,
			regions: array( 'AU', 'NZ', 'GB', 'US', 'AE', 'SG', 'ZA' ),
			rateLimit: new RateLimitHints(
				requestsPerSecond: 1.0,
				concurrency: 1,
				requestsPerMinute: 55,
				requestsPerDay: max( 100, (int) apply_filters( 'estasoft_sync_xero_daily_calls', 1000 ) )
			),
			hosts: array( 'api.xero.com', 'identity.xero.com', 'login.xero.com' )
		);
	}

	/**
	 * The API root.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return self::API;
	}

	/**
	 * Sign-in with the merchant's own app.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( $this->oauthProvider() );
	}

	/**
	 * The tenant header that pins every call to the chosen organisation.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		$tenant = $connection->config( 'tenant_id' );
		return is_string( $tenant ) && '' !== $tenant ? array( 'Xero-tenant-id' => $tenant ) : array();
	}

	/**
	 * Remove the organisation's name besides its id from every message.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values = parent::redactionValues( $connection );
		$name   = $connection->config( 'organisation_name' );
		if ( is_string( $name ) && '' !== $name ) {
			$values[] = $name;
		}
		return $values;
	}

	/**
	 * Map Xero's own error shapes.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return XeroErrors::fromResponse( $response );
	}

	/**
	 * This connector is its own provider of OAuth settings.
	 */
	public function oauthProvider(): OAuthProvider {
		return $this;
	}

	/**
	 * OAuth settings: Xero's sign-in page and token endpoint, granular scopes, Basic client authentication, no PKCE.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		unset( $connection );
		return new OAuthConfig(
			authorizeUrl: 'https://login.xero.com/identity/connect/authorize',
			tokenUrl: 'https://identity.xero.com/connect/token',
			scopes: array( 'offline_access', 'accounting.invoices', 'accounting.payments', 'accounting.contacts', 'accounting.settings.read' ),
			grant: 'authorization_code',
			usePkce: false,
			clientAuth: 'basic',
			refreshTokenLifetimeDays: 60,
			accessTokenFallbackSeconds: 1800,
			allowedHosts: array( 'login.xero.com', 'identity.xero.com' )
		);
	}

	/**
	 * The organisations the signed-in person allowed.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function organisations( Connection $connection ): array {
		$rows = $this->api( $connection )->get( self::CONNECTIONS )->json();
		$list = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || 'ORGANISATION' !== ( $row['tenantType'] ?? 'ORGANISATION' ) ) {
				continue;
			}
			$id = XeroPayloadReader::text( $row['tenantId'] ?? '' );
			if ( '' !== $id ) {
				$list[] = array(
					'id'   => $id,
					'name' => XeroPayloadReader::text( $row['tenantName'] ?? '' ),
				);
			}
		}
		return $list;
	}

	/**
	 * Remember the chosen organisation.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         Tenant id from organisations().
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		$config = array( 'tenant_id' => $id );
		foreach ( $this->organisations( $connection ) as $organisation ) {
			if ( $organisation['id'] === $id ) {
				$config['organisation_name'] = $organisation['name'];
			}
		}
		return $config;
	}

	/**
	 * Test connection: the organisation, its currency and region, the tax rates found, the accounts named in the options.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On a failed call.
	 */
	protected function identify( Connection $connection ): TestResult {
		if ( '' === XeroPayloadReader::text( $connection->config( 'tenant_id' ) ) ) {
			return new TestResult( false, '', '', array(), array(), XeroErrors::refused( 'tenant_id', __( 'No organisation is chosen yet. Sign in with Xero and choose one.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$services    = $this->servicesFor( $connection );
		$facts       = $services->organisation->facts();
		$rates       = $services->reader->taxCodes();
		$permissions = array(
			'read_organisation' => true,
			'tax_rates_found'   => array() !== $rates,
		);
		foreach ( array( 'sales_account', 'payment_account' ) as $option ) {
			$code = $services->options->text( $option );
			if ( '' !== $code ) {
				$permissions[ $option . '_exists' ] = $this->accountExists( $services, $code );
			}
		}
		$detected = array(
			'base_currency' => $services->organisation->baseCurrency(),
			'region'        => XeroPayloadReader::text( $facts['Version'] ?? '' ),
			'tax_rates'     => count( $rates ),
		);
		if ( null !== $services->organisation->dailyLeft() ) {
			$detected['daily_calls_left'] = $services->organisation->dailyLeft();
		}
		$version = 'Xero Accounting API 2.0';
		return new TestResult( true, $version, XeroPayloadReader::text( $facts['Name'] ?? '' ), array(), $permissions, null, $detected );
	}

	/**
	 * One cheap read for the circuit breaker.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->servicesFor( $connection )->organisation->probe();
			return true;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Target fields for the mapping screens.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ( new XeroFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new XeroDefaultMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the record of an item before sending (finished records only).
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$services = $this->servicesFor( $connection );
		return match ( $item->entityType ) {
			EntityType::Order    => $services->orders->find( $item ),
			EntityType::Refund   => $services->refunds->find( $item ),
			EntityType::Customer => $services->customers->find( $item ),
			default              => null,
		};
	}

	/**
	 * Send an item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$services = $this->servicesFor( $connection );
		$result   = match ( $item->entityType ) {
			EntityType::Order    => $services->orders->send( $item, $payload ),
			EntityType::Refund   => $services->refunds->send( $item, $payload ),
			EntityType::Customer => $services->customers->send( $item, $payload ),
			default              => SendResult::failure( XeroErrors::refused( 'entity', __( 'Xero takes orders, refunds and customers only.', 'estasoft-erp-crm-sync-engine' ) ) ),
		};
		$this->logWarnings( $result, $item );
		return $result;
	}

	/**
	 * Find a customer by email.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$contact = $this->servicesFor( $connection )->contacts->findByEmail( $email );
		return null === $contact ? null : new ExternalRef( XeroPayloadReader::text( $contact['ContactID'] ?? '' ), XeroPayloadReader::text( $contact['Name'] ?? '' ) );
	}

	/**
	 * Find a product by SKU.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		return $this->servicesFor( $connection )->reader->product( $sku );
	}

	/**
	 * Search products by code or name.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Text.
	 * @return array<int,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		return $this->servicesFor( $connection )->reader->searchProducts( $query );
	}

	/**
	 * Stock of tracked items.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Ignored: Xero has no warehouses.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		unset( $warehouses );
		return $this->servicesFor( $connection )->reader->stock( $skus );
	}

	/**
	 * Facts about a synced order's invoice.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The invoice.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return $this->servicesFor( $connection )->reader->invoiceFacts( $ref );
	}

	/**
	 * Invoices for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       Window start (UTC).
	 * @return array<string,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return $this->servicesFor( $connection )->reader->reconcile( $from );
	}

	/**
	 * Tax types for the tax mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		return $this->servicesFor( $connection )->reader->taxCodes();
	}

	/**
	 * The helpers for a connection, built once for the same connection and options.
	 *
	 * @param Connection $connection Connection.
	 */
	private function servicesFor( Connection $connection ): XeroServices {
		$key = $connection->id . ':' . md5( (string) wp_json_encode( $connection->config ) );
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = new XeroServices( $this->api( $connection ), $connection );
		}
		return $this->services[ $key ];
	}

	/**
	 * Whether an account code exists in Xero.
	 *
	 * @param XeroServices $services Helpers.
	 * @param string       $code     Account code.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function accountExists( XeroServices $services, string $code ): bool {
		$clean = str_replace( array( '"', '\\' ), '', $code );
		$data  = $services->api->get( 'Accounts', array( 'where' => 'Code=="' . $clean . '"' ) )->json();
		foreach ( (array) ( $data['Accounts'] ?? array() ) as $account ) {
			if ( is_array( $account ) && ( $account['Code'] ?? '' ) === $code && 'ARCHIVED' !== ( $account['Status'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Write notes a send carried to the log (a cut value, a changed date). Nothing here holds a secret.
	 *
	 * @param SendResult $result Result.
	 * @param Item       $item   Item.
	 */
	private function logWarnings( SendResult $result, Item $item ): void {
		$warnings = $result->ref?->extra['warnings'] ?? array();
		if ( ! is_array( $warnings ) || array() === $warnings ) {
			return;
		}
		foreach ( $warnings as $warning ) {
			if ( is_string( $warning ) && '' !== $warning ) {
				$this->deps->logger->warning(
					$warning,
					array(
						'item'      => $item->id,
						'connector' => 'xero',
					)
				);
			}
		}
	}
}
