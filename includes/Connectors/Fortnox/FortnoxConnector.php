<?php
/**
 * The Fortnox connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Connectors\Support\ErrorBridge;
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
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Orders become customer invoices (or orders), customers become customers, refunds become credit invoices. Sign-in is OAuth 2 with the
 * merchant's own integration from the Fortnox Developer Portal; the refresh token rotates and the shared token manager keeps the new one
 * under a lock. The scopes asked for follow the connection options, because Fortnox fixes them at sign-in and ties each to a licence.
 * See docs/connectors/fortnox.md.
 */
final class FortnoxConnector extends AbstractApiConnector implements OAuthConnector, OAuthProvider {

	/**
	 * Connector id.
	 */
	public const ID = 'fortnox';

	/**
	 * Name used in messages.
	 */
	public const LABEL = 'Fortnox';

	/**
	 * Option keys read from a connection.
	 *
	 * @var array<int,string>
	 */
	private const OPTIONS = array( 'document', 'unknown_sku', 'customer_policy', 'due_days', 'bookkeep', 'record_payment', 'payment_mode', 'stock_source', 'total_mismatch' );

	/**
	 * Identity, flags, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: self::ID,
			label: self::LABEL,
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
			regions: array( 'SE' ),
			rateLimit: new RateLimitHints( 4.0, 1, 300 ),
			hosts: array( 'api.fortnox.se', 'apps.fortnox.se' )
		);
	}

	/**
	 * The API address.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return 'https://api.fortnox.se/3';
	}

	/**
	 * Bearer token from the shared OAuth engine.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( $this );
	}

	/**
	 * The connect form: the integration's Client ID and secret.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::clientId( 'client_id', __( 'Shown on the integration in the Fortnox Developer Portal.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::clientSecret(),
		);
	}

	/**
	 * The OAuth settings. The scopes depend on the options, because each scope needs its own Fortnox licence.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$options = $this->options( $connection );
		$scopes  = array( 'companyinformation', 'customer', 'article', 'invoice' );
		if ( 'order' === $options->choice( 'document', array( 'invoice', 'order' ), 'invoice' ) ) {
			$scopes[] = 'order';
		}
		if ( 'warehouse' === $options->choice( 'stock_source', array( 'article', 'warehouse' ), 'article' ) ) {
			$scopes[] = 'warehouse';
		}
		if ( 'yes' === $options->choice( 'record_payment', array( 'no', 'yes' ), 'no' ) ) {
			$scopes[] = 'payment';
		}
		return new OAuthConfig(
			authorizeUrl: 'https://apps.fortnox.se/oauth-v1/auth',
			tokenUrl: 'https://apps.fortnox.se/oauth-v1/token',
			scopes: $scopes,
			grant: 'authorization_code',
			usePkce: true,
			clientAuth: 'basic',
			extraAuthorizeParams: array( 'access_type' => 'offline' ),
			refreshTokenLifetimeDays: 45,
			accessTokenFallbackSeconds: 3600,
			allowedHosts: array( 'apps.fortnox.se' )
		);
	}

	/**
	 * This connector is its own OAuth provider.
	 */
	public function oauthProvider(): OAuthProvider {
		return $this;
	}

	/**
	 * Fortnox signs one company in: the list holds that company.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function organisations( Connection $connection ): array {
		$company = ( new FortnoxApi( $this->api( $connection ) ) )->single( 'companyinformation', 'CompanyInformation' );
		$name    = trim( (string) ( $company['CompanyName'] ?? '' ) );
		$number  = (string) ( $company['DatabaseNumber'] ?? '' );
		return array(
			array(
				'id'   => '' !== $number ? $number : 'default',
				'name' => '' !== $name ? $name : 'Fortnox',
			),
		);
	}

	/**
	 * Remember the company.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         Id from organisations().
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On failure.
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		$name = 'Fortnox';
		foreach ( $this->organisations( $connection ) as $organisation ) {
			if ( $organisation['id'] === $id ) {
				$name = $organisation['name'];
			}
		}
		return array(
			'tenant_id'    => $id,
			'company_name' => $name,
		);
	}

	/**
	 * Test connection: company, and one read on customers, articles and the document type.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api         = new FortnoxApi( $this->api( $connection ) );
		$options     = $this->options( $connection );
		$company     = $api->single( 'companyinformation', 'CompanyInformation' );
		$orders      = 'order' === $options->choice( 'document', array( 'invoice', 'order' ), 'invoice' );
		$permissions = array(
			'read_customers' => $api->canRead( 'customers' ),
			'read_articles'  => $api->canRead( 'articles' ),
			'read_invoices'  => $api->canRead( 'invoices' ),
		);
		if ( $orders ) {
			$permissions['read_orders'] = $api->canRead( 'orders' );
		}
		$ok = array() === array_filter( $permissions, static fn( bool $allowed ): bool => ! $allowed );
		return new TestResult(
			$ok,
			'Fortnox API v3',
			trim( (string) ( $company['CompanyName'] ?? '' ) ),
			array(),
			$permissions,
			null,
			array(
				'document'  => $orders ? 'order' : 'invoice',
				'tenant_id' => (string) ( $company['DatabaseNumber'] ?? '' ),
				'scopes'    => $this->config( $connection )->scopes,
			)
		);
	}

	/**
	 * Cheap health probe: the company information.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return array() !== ( new FortnoxApi( $this->api( $connection ) ) )->single( 'companyinformation', 'CompanyInformation' );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Target fields for the mapping screens.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return FortnoxFields::catalog( $mappingType );
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return FortnoxMappings::rules( $mappingType );
	}

	/**
	 * Fortnox's error shapes.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return FortnoxErrors::map( $response );
	}

	/**
	 * Look for the finished document of an order or refund by its reference WC-{id}.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		if ( EntityType::Order !== $item->entityType && EntityType::Refund !== $item->entityType ) {
			return null;
		}
		return $this->guard(
			function () use ( $connection, $item ): ?ExternalRef {
				$docs   = $this->documents( $connection );
				$credit = EntityType::Refund === $item->entityType;
				$row    = $docs->find( $item->clientReference(), $credit );
				return null !== $row && $docs->isComplete( $row, $credit ) ? $docs->ref( $row, ! $credit && $docs->usesOrders() ) : null;
			}
		);
	}

	/**
	 * Send an order, a refund or a customer.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$today   = $this->deps->clock->now()->format( 'Y-m-d' );
		$options = $this->options( $connection );
		try {
			if ( EntityType::Order === $item->entityType ) {
				$order = FortnoxOrder::parse( $payload, $item, $today, 'free_text' === $options->choice( 'unknown_sku', array( 'fail', 'free_text' ), 'fail' ) );
				return $order instanceof ConnectorError ? SendResult::failure( $order ) : $this->documents( $connection )->sendOrder( $order );
			}
			if ( EntityType::Refund === $item->entityType ) {
				$refund = FortnoxRefund::parse( $payload, $item, $today );
				return $refund instanceof ConnectorError ? SendResult::failure( $refund ) : $this->documents( $connection )->sendRefund( $refund );
			}
			if ( EntityType::Customer === $item->entityType ) {
				return $this->sendCustomer( $connection, $item, $payload );
			}
			return SendResult::failure( FortnoxFailure::invalid( 'entity', __( 'This connector does not send that kind of item.', 'estasoft-enterprise-erpcrm-sync-engine' ) )->error );
		} catch ( FortnoxFailure $failure ) {
			return SendResult::failure( $failure->error );
		} catch ( \Throwable $throwable ) {
			return SendResult::failure( ErrorBridge::toError( $throwable, self::LABEL ) );
		}
	}

	/**
	 * Find a customer by email, ignoring case.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		return $this->guard(
			function () use ( $connection, $email ): ?ExternalRef {
				$row = $this->lookups( $connection )->customerByEmail( $email );
				return null === $row ? null : new ExternalRef( (string) ( $row['CustomerNumber'] ?? '' ), (string) ( $row['Name'] ?? '' ), null, array( 'email' => (string) ( $row['Email'] ?? '' ) ) );
			}
		);
	}

	/**
	 * Find an article by its number (the SKU).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		return $this->guard(
			function () use ( $connection, $sku ): ?ExternalRef {
				$row = $this->lookups( $connection )->article( $sku );
				return null === $row ? null : new ExternalRef( (string) ( $row['ArticleNumber'] ?? '' ), (string) ( $row['ArticleNumber'] ?? '' ), null, array( 'name' => (string) ( $row['Description'] ?? '' ) ) );
			}
		);
	}

	/**
	 * Search articles by description (the first page).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		return $this->guard(
			function () use ( $connection, $query ): array {
				$rows = $this->api( $connection )->get(
					'articles',
					array(
						'description' => trim( $query ),
						'limit'       => 20,
					)
				)->json()['Articles'] ?? array();
				$out  = array();
				foreach ( array_slice( is_array( $rows ) ? $rows : array(), 0, 20 ) as $row ) {
					if ( is_array( $row ) ) {
						$out[] = new ExternalRef( (string) ( $row['ArticleNumber'] ?? '' ), (string) ( $row['ArticleNumber'] ?? '' ), null, array( 'name' => (string) ( $row['Description'] ?? '' ) ) );
					}
				}
				return $out;
			}
		);
	}

	/**
	 * On-hand and reserved stock of articles.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       Article numbers.
	 * @param array<int,string> $warehouses Stock point codes that count (Lager only); empty means all.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return $this->guard( fn(): array => $this->reads( $connection )->stock( $skus, $warehouses ) );
	}

	/**
	 * Facts of an invoice.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The synced order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return $this->guard( fn(): ?array => $this->reads( $connection )->invoice( $ref ) );
	}

	/**
	 * Documents of the shop from a day on, for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return $this->guard( fn(): array => $this->reads( $connection )->reconcile( $from ) );
	}

	/**
	 * The VAT rates Fortnox accepts in Sweden, for the Tax mapping screen. The code is the percentage that goes into the row.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 */
	public function listTaxCodes( Connection $connection ): array {
		unset( $connection );
		$out = array();
		foreach ( array( '25', '12', '6', '0' ) as $rate ) {
			$out[] = array(
				'code'         => $rate,
				/* translators: %s: VAT rate in percent. */
				'name'         => sprintf( __( 'VAT %s %%', 'estasoft-enterprise-erpcrm-sync-engine' ), $rate ),
				'rate_percent' => $rate,
			);
		}
		return $out;
	}

	/**
	 * Find or create the customer of a customer item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Payload.
	 * @throws FortnoxFailure When the name is missing.
	 */
	private function sendCustomer( Connection $connection, Item $item, array $payload ): SendResult {
		$partner = array();
		$raw     = is_array( $payload['partner'] ?? null ) ? $payload['partner'] : array();
		foreach ( array( 'name', 'email', 'company_name', 'phone', 'street', 'city', 'zip', 'country_id', 'vat' ) as $key ) {
			$value = FortnoxPayload::text( $raw[ $key ] ?? '' );
			if ( '' !== $value ) {
				$partner[ $key ] = $value;
			}
		}
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return SendResult::failure(
				new ConnectorError(
					ErrorCode::Map003,
					array(
						'order'  => $item->entityId,
						'system' => self::LABEL,
					),
					null,
					null,
					null,
					'email missing'
				)
			);
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			FortnoxFailure::refuse( 'partner.name', __( 'The customer name is empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$lookups = $this->lookups( $connection );
		$row     = $lookups->customerByEmail( $partner['email'] );
		$created = false;
		if ( null === $row ) {
			if ( 'match_only' === $this->options( $connection )->choice( 'customer_policy', array( 'match_or_create', 'match_only' ), 'match_or_create' ) ) {
				FortnoxFailure::refuse( 'partner.email', __( 'No customer with this email address exists in Fortnox.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
			$row     = $lookups->createCustomer( $partner );
			$created = true;
		}
		$ref = new ExternalRef( (string) ( $row['CustomerNumber'] ?? '' ), (string) ( $row['Name'] ?? '' ), null, array( 'email' => $partner['email'] ) );
		return $created ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Run a read and turn every failure into a ConnectorError.
	 *
	 * @param \Closure $work Work to run.
	 * @return mixed The work's result, or a ConnectorError.
	 */
	private function guard( \Closure $work ): mixed {
		try {
			return $work();
		} catch ( FortnoxFailure $failure ) {
			return $failure->error;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, self::LABEL );
		}
	}

	/**
	 * The options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function options( Connection $connection ): CrmOptions {
		return CrmOptions::fromConnection( $connection, self::OPTIONS );
	}

	/**
	 * The API of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function fortnoxApi( Connection $connection ): FortnoxApi {
		return new FortnoxApi( $this->api( $connection ) );
	}

	/**
	 * Lookups for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function lookups( Connection $connection ): FortnoxLookups {
		return new FortnoxLookups( $this->fortnoxApi( $connection ) );
	}

	/**
	 * The document helper of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function documents( Connection $connection ): FortnoxDocuments {
		return new FortnoxDocuments( $this->fortnoxApi( $connection ), $this->lookups( $connection ), $this->options( $connection ) );
	}

	/**
	 * The read helper of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function reads( Connection $connection ): FortnoxReads {
		return new FortnoxReads( $this->fortnoxApi( $connection ), $this->lookups( $connection ), $this->documents( $connection ), $this->options( $connection ) );
	}
}
