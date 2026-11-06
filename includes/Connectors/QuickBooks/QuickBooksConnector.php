<?php
/**
 * The QuickBooks Online connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Connects the engine to a QuickBooks Online company through the merchant's own Intuit app (OAuth 2 authorization code). Orders become invoices,
 * refunds become credit memos, customers become customers. See docs/connectors/quickbooks.md for every endpoint, field and limit, and what is not verified.
 */
final class QuickBooksConnector extends AbstractApiConnector implements OAuthConnector, OAuthProvider {

	/**
	 * Production API host.
	 */
	private const PRODUCTION = 'https://quickbooks.api.intuit.com';

	/**
	 * Sandbox API host.
	 */
	private const SANDBOX = 'https://sandbox-quickbooks.api.intuit.com';

	/**
	 * Helpers per connection, kept for the length of the request.
	 *
	 * @var array<string,QuickBooksServices>
	 */
	private array $services = array();

	/**
	 * Identity, flags, sign-in style, regions, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'quickbooks',
			label: 'QuickBooks Online',
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
			regions: array( 'US', 'GB', 'CA', 'AU' ),
			rateLimit: new RateLimitHints( requestsPerSecond: 4.0, concurrency: 1, requestsPerMinute: 450 ),
			hosts: array( 'quickbooks.api.intuit.com', 'sandbox-quickbooks.api.intuit.com', 'appcenter.intuit.com', 'oauth.platform.intuit.com' )
		);
	}

	/**
	 * The connect form: the app's id and secret, the key set (production or sandbox) and the company id, which sign-in fills in.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::clientId(),
			CredentialFields::clientSecret(),
			CredentialFields::choice(
				'environment',
				__( 'Key set', 'estasoft-enterprise-erpcrm-sync-engine' ),
				array(
					'production' => __( 'Production', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'sandbox'    => __( 'Sandbox (development keys)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'production',
				__( 'Development keys work with sandbox companies only; production keys need Intuit\'s app review.', 'estasoft-enterprise-erpcrm-sync-engine' )
			),
			new CredentialField( 'realm_id', __( 'Company ID', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Filled in when you sign in. Leave empty.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
		);
	}

	/**
	 * The API root of the company: the host of the key set and /v3/company/{realmId}.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When no company is chosen yet.
	 */
	protected function baseUrl( Connection $connection ): string {
		$realm = QuickBooksCompany::realmOf( $connection );
		if ( '' === $realm ) {
			$failure = QuickBooksErrors::exception( QuickBooksErrors::refused( 'realm_id', __( 'No company is chosen yet. Sign in with QuickBooks and choose the company.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
			throw $failure;
		}
		return ( 'sandbox' === $connection->config( 'environment' ) ? self::SANDBOX : self::PRODUCTION ) . '/v3/company/' . $realm;
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
	 * Remove the company's name and id from every message.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values = parent::redactionValues( $connection );
		foreach ( array( QuickBooksCompany::realmOf( $connection ), QuickBooksPayloadReader::text( $connection->config( 'company_name' ) ) ) as $value ) {
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}
		return $values;
	}

	/**
	 * Map QuickBooks' own fault shapes.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return QuickBooksErrors::fromResponse( $response );
	}

	/**
	 * This connector is its own provider of OAuth settings.
	 */
	public function oauthProvider(): OAuthProvider {
		return $this;
	}

	/**
	 * OAuth settings: Intuit's sign-in page and token endpoint, the accounting scope, Basic client authentication, no PKCE.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		unset( $connection );
		return new OAuthConfig(
			authorizeUrl: 'https://appcenter.intuit.com/connect/oauth2',
			tokenUrl: 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
			scopes: array( 'com.intuit.quickbooks.accounting' ),
			grant: 'authorization_code',
			usePkce: false,
			clientAuth: 'basic',
			refreshTokenLifetimeDays: 100,
			accessTokenFallbackSeconds: 3600,
			allowedHosts: array( 'appcenter.intuit.com', 'oauth.platform.intuit.com' )
		);
	}

	/**
	 * The company the person signed in to. QuickBooks names it in the sign-in redirect (realmId); no call lists companies.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function organisations( Connection $connection ): array {
		$realm = QuickBooksCompany::realmOf( $connection );
		$name  = $this->servicesFor( $connection )->company->name();
		return array(
			array(
				'id'   => $realm,
				'name' => $name,
			),
		);
	}

	/**
	 * Remember the company.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         Realm id from organisations().
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		$config = array( 'realm_id' => preg_replace( '/\D/', '', $id ) );
		foreach ( $this->organisations( $connection ) as $company ) {
			if ( $company['id'] === $config['realm_id'] ) {
				$config['company_name'] = $company['name'];
			}
		}
		return $config;
	}

	/**
	 * Test connection: the company, its country and currency, whether the shop reference will be kept, the tax codes, the items named in the options.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On a failed call.
	 */
	protected function identify( Connection $connection ): TestResult {
		$services    = $this->servicesFor( $connection );
		$company     = $services->company;
		$name        = $company->name();
		$codes       = $services->reader->taxCodes();
		$permissions = array(
			'read_company'               => true,
			'custom_transaction_numbers' => $company->customNumbers(),
			'tax_codes_found'            => array() !== $codes,
		);
		foreach ( array( 'generic', 'shipping', 'fee', 'discount' ) as $kind ) {
			$value = $services->options->text( $kind . '_item' );
			if ( '' !== $value ) {
				$permissions[ $kind . '_item_found' ] = null !== $services->items->named( $value );
			}
		}
		$detected = array(
			'country'        => $company->country(),
			'home_currency'  => $company->homeCurrency(),
			'multi_currency' => $company->multiCurrency(),
			'tax_codes'      => count( $codes ),
			'minor_version'  => $services->options->minorVersion(),
		);
		return new TestResult( true, 'QuickBooks Online API v3 (minor version ' . $services->options->minorVersion() . ')', $name, array(), $permissions, null, $detected );
	}

	/**
	 * One cheap read for the circuit breaker.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->servicesFor( $connection )->company->probe();
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
		return ( new QuickBooksFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new QuickBooksDefaultMappings() )->rules( $mappingType );
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
			EntityType::Order  => $services->orders->find( $item ),
			EntityType::Refund => $services->refunds->find( $item ),
			default            => null,
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
			EntityType::Customer => $services->customerSender->send( $item, $payload ),
			default              => SendResult::failure( QuickBooksErrors::refused( 'entity', __( 'QuickBooks takes orders, refunds and customers only.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) ),
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
		$customer = $this->servicesFor( $connection )->customers->findByEmail( $email );
		return null === $customer ? null : new ExternalRef( QuickBooksPayloadReader::text( $customer['Id'] ?? '' ), QuickBooksPayloadReader::text( $customer['DisplayName'] ?? '' ) );
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
	 * Search products by SKU or name.
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
	 * @param array<int,string> $warehouses Ignored: QuickBooks has no warehouses.
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
	 * Tax codes for the tax mapping screen.
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
	 *
	 * @throws ApiException When no company is chosen.
	 */
	private function servicesFor( Connection $connection ): QuickBooksServices {
		$key = $connection->id . ':' . md5( (string) wp_json_encode( $connection->config ) );
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = new QuickBooksServices( $this->api( $connection ), $connection );
		}
		return $this->services[ $key ];
	}

	/**
	 * Write notes a send carried to the log (a cut value). Nothing here holds a secret.
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
						'connector' => 'quickbooks',
					)
				);
			}
		}
	}
}
