<?php
/**
 * The Sage Business Cloud Accounting connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

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
 * Connects the engine to a Sage Business Cloud Accounting business through the merchant's own developer app (OAuth 2 authorization code with PKCE). Orders
 * become sales invoices, refunds become sales credit notes, customers become contacts. See docs/connectors/sage-accounting.md for every endpoint, field and limit, and
 * what is not verified.
 */
final class SageAccountingConnector extends AbstractApiConnector implements OAuthConnector, OAuthProvider {

	/**
	 * Helpers per connection, kept for the length of the request.
	 *
	 * @var array<string,SageServices>
	 */
	private array $services = array();

	/**
	 * Identity, flags, sign-in style, regions, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'sage-accounting',
			label: 'Sage Business Cloud Accounting',
			type: ConnectorType::Accounting,
			capabilities: array(
				Capability::Orders,
				Capability::Customers,
				Capability::Refunds,
				Capability::InvoiceReadback,
				Capability::Reconciliation,
				Capability::ShadowLookup,
			),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthAuthorizationCode,
			regions: array( 'GB', 'IE', 'CA', 'ES', 'FR' ),
			rateLimit: new RateLimitHints( requestsPerSecond: 3.0, concurrency: 1 ),
			hosts: array( 'api.accounting.sage.com', 'oauth.accounting.sage.com', 'www.sageone.com' )
		);
	}

	/**
	 * The connect form: the app's id and secret, the country of the business, and the business id, which sign-in fills in.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::clientId(),
			CredentialFields::clientSecret(),
			CredentialFields::choice(
				'country',
				__( 'Country of the business', 'estasoft-enterprise-erpcrm-sync-engine' ),
				array(
					'gb' => __( 'United Kingdom', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'ie' => __( 'Ireland', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'ca' => __( 'Canada', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'es' => __( 'Spain', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'fr' => __( 'France', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'gb',
				__( 'It must match the region of the business, or Sage refuses the sign-in.', 'estasoft-enterprise-erpcrm-sync-engine' )
			),
			new CredentialField( 'business_id', __( 'Business ID', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Filled in when you sign in. Leave empty.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
		);
	}

	/**
	 * The API root. Sage has one address for every region.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return 'https://api.accounting.sage.com/v3.1';
	}

	/**
	 * The business the call is about. Without the header Sage uses the person's lead business, which this connector never relies on.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		$business = SageBusiness::businessOf( $connection );
		return '' !== $business ? array( 'X-Business' => $business ) : array();
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
	 * Remove the business's id and name from every message.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values = parent::redactionValues( $connection );
		foreach ( array( SageBusiness::businessOf( $connection ), SagePayloadReader::text( $connection->config( 'business_name' ) ) ) as $value ) {
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}
		return $values;
	}

	/**
	 * Map Sage's own error shapes.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return SageErrors::fromResponse( $response );
	}

	/**
	 * This connector is its own provider of OAuth settings.
	 */
	public function oauthProvider(): OAuthProvider {
		return $this;
	}

	/**
	 * OAuth settings: Sage's sign-in page for the API version and country, its token endpoint, PKCE, the client id and secret in the body, full access. The access token lives five minutes, so the scheduled task leaves it to the next call.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		return new OAuthConfig(
			authorizeUrl: 'https://www.sageone.com/oauth2/auth/central',
			tokenUrl: 'https://oauth.accounting.sage.com/token',
			scopes: array( 'full_access' ),
			grant: 'authorization_code',
			usePkce: true,
			clientAuth: 'body',
			extraAuthorizeParams: array(
				'filter'  => 'apiv3.1',
				'country' => SageBusiness::countryOf( $connection ),
			),
			refreshTokenLifetimeDays: 31,
			accessTokenFallbackSeconds: 300,
			refreshOnDemand: true,
			allowedHosts: array( 'www.sageone.com', 'oauth.accounting.sage.com' )
		);
	}

	/**
	 * The businesses the signed-in person can open.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function organisations( Connection $connection ): array {
		$list = array();
		foreach ( $this->servicesFor( $connection, false )->business->businesses() as $business ) {
			$id = SagePayloadReader::text( $business['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$list[] = array(
				'id'   => $id,
				'name' => SagePayloadReader::text( $business['name'] ?? ( $business['displayed_as'] ?? $id ) ),
			);
		}
		return $list;
	}

	/**
	 * Remember the business.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         Business id from organisations().
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		$id     = trim( $id );
		$config = array( 'business_id' => 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ? $id : '' );
		foreach ( $this->organisations( $connection ) as $business ) {
			if ( $business['id'] === $id ) {
				$config['business_name'] = $business['name'];
			}
		}
		return $config;
	}

	/**
	 * Test connection: the business, its currency, its tax rates, and the accounts the options name.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On a failed call.
	 */
	protected function identify( Connection $connection ): TestResult {
		$services    = $this->servicesFor( $connection );
		$business    = $services->business;
		$entry       = $business->business();
		$name        = SagePayloadReader::text( $entry['name'] ?? $connection->config( 'business_name' ) );
		$currency    = $business->currency();
		$rates       = $business->taxList();
		$permissions = array(
			'read_business'   => null !== $entry,
			'tax_rates_found' => array() !== $rates,
		);
		$ledger      = $services->options->text( 'sales_ledger_account' );
		if ( '' !== $ledger ) {
			$permissions['sales_ledger_account_found'] = '' !== $business->ledgerId( $ledger );
		}
		$bank = $services->options->text( 'payment_bank_account' );
		if ( '' !== $bank ) {
			$permissions['payment_bank_account_found'] = '' !== $business->bankId( $bank );
		}
		$detected = array(
			'country'   => strtoupper( SageBusiness::countryOf( $connection ) ),
			'currency'  => $currency,
			'tax_rates' => count( $rates ),
		);
		return new TestResult( true, 'Sage Accounting API v3.1', $name, array(), $permissions, null, $detected );
	}

	/**
	 * One cheap read for the circuit breaker.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->servicesFor( $connection, false )->business->probe();
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
		return ( new SageFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new SageDefaultMappings() )->rules( $mappingType );
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
			default              => SendResult::failure( SageErrors::refused( 'entity', __( 'Sage takes orders, refunds and customers only.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) ),
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
		return null === $contact ? null : new ExternalRef( SagePayloadReader::text( $contact['id'] ?? '' ), SagePayloadReader::text( $contact['name'] ?? ( $contact['displayed_as'] ?? '' ) ) );
	}

	/**
	 * Find a product by item code.
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
	 * Search products by code or description.
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
	 * Tax rates for the tax mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		return $this->servicesFor( $connection )->business->taxList();
	}

	/**
	 * The helpers for a connection, built once for the same connection and options.
	 *
	 * @param Connection $connection      Connection.
	 * @param bool       $requireBusiness Whether a business must be chosen.
	 *
	 * @throws ApiException When a business is required and none is chosen.
	 */
	private function servicesFor( Connection $connection, bool $requireBusiness = true ): SageServices {
		if ( $requireBusiness && '' === SageBusiness::businessOf( $connection ) ) {
			$failure = SageErrors::exception( SageErrors::refused( 'business_id', __( 'No business is chosen yet. Sign in with Sage and choose the business.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
			throw $failure;
		}
		$key = $connection->id . ':' . md5( (string) wp_json_encode( $connection->config ) );
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = new SageServices( $this->api( $connection ), $connection );
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
						'connector' => 'sage-accounting',
					)
				);
			}
		}
	}
}
