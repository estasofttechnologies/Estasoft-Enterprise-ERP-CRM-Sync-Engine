<?php
/**
 * The Exact Online connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

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
use Estasoft\SyncEngine\Domain\Item;

/**
 * Orders become sales invoices (or sales orders), customers become accounts, refunds become credit notes. Sign-in is OAuth 2 with the
 * merchant's own app, one app per country; the refresh token rotates and the shared token manager keeps the new one under a lock.
 * See docs/connectors/exact-online.md.
 */
final class ExactConnector extends AbstractApiConnector implements OAuthConnector, OAuthProvider {

	/**
	 * Connector id.
	 */
	public const ID = 'exact-online';

	/**
	 * Name used in messages.
	 */
	public const LABEL = 'Exact Online';

	/**
	 * Option keys read from a connection.
	 *
	 * @var array<int,string>
	 */
	private const OPTIONS = array( 'document', 'journal', 'credit_journal', 'process_invoice', 'customer_policy', 'shipping_item', 'fee_item', 'discount_item', 'refund_item', 'total_mismatch' );

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
			regions: array( 'NL', 'BE', 'DE', 'FR', 'GB', 'ES', 'US' ),
			rateLimit: new RateLimitHints( 1.0, 1, 60, 5000 ),
			hosts: ExactRegions::allHosts()
		);
	}

	/**
	 * The API of the region the connection was made for. An unknown region gives an address the host policy refuses.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		$host = ExactRegions::host( (string) $connection->config( 'region', 'nl' ) );
		return 'https://' . ( $host ?? 'start.exactonline.invalid' ) . '/api/v1';
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
	 * The connect form: region, then the app's Client ID and secret.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::choice(
				'region',
				__( 'Region', 'estasoft-erp-crm-sync-engine' ),
				ExactRegions::choices(),
				'nl',
				__( 'The country of the Exact Online site you sign in to. An app works only in the country it was made in.', 'estasoft-erp-crm-sync-engine' )
			),
			CredentialFields::clientId( 'client_id', __( 'Shown on the Develop your app tab of your app.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::clientSecret(),
		);
	}

	/**
	 * Values to remove from every message besides the secrets: the administration (division) number.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values   = parent::redactionValues( $connection );
		$division = $connection->config( 'division' );
		if ( is_string( $division ) || is_int( $division ) ) {
			$values[] = (string) $division;
		}
		return $values;
	}

	/**
	 * The OAuth settings: sign-in and token address of the connection's country.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$host = ExactRegions::host( (string) $connection->config( 'region', 'nl' ) ) ?? 'start.exactonline.invalid';
		return new OAuthConfig(
			authorizeUrl: 'https://' . $host . '/api/oauth2/auth',
			tokenUrl: 'https://' . $host . '/api/oauth2/token',
			scopes: array(),
			grant: 'authorization_code',
			usePkce: false,
			clientAuth: 'body',
			extraAuthorizeParams: array( 'force_login' => '0' ),
			refreshTokenLifetimeDays: 30,
			accessTokenFallbackSeconds: 600,
			allowedHosts: ExactRegions::allHosts()
		);
	}

	/**
	 * This connector is its own OAuth provider.
	 */
	public function oauthProvider(): OAuthProvider {
		return $this;
	}

	/**
	 * The administrations (divisions) the signed-in person can choose between.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function organisations( Connection $connection ): array {
		$client  = $this->api( $connection );
		$current = (string) ( ( new ExactApi( $client, '0' ) )->me()['CurrentDivision'] ?? '' );
		if ( '' === $current ) {
			return array();
		}
		$out = array();
		foreach ( ( new ExactApi( $client, $current ) )->rows( 'system/Divisions', array( '$select' => 'Code,Description' ) ) as $row ) {
			$code = (string) ( $row['Code'] ?? '' );
			if ( '' !== $code ) {
				$out[] = array(
					'id'   => $code,
					'name' => '' !== trim( (string) ( $row['Description'] ?? '' ) ) ? trim( (string) $row['Description'] ) : $code,
				);
			}
		}
		return $out;
	}

	/**
	 * Remember the administration the person chose.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         Division code.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On failure.
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		$name = $id;
		foreach ( $this->organisations( $connection ) as $organisation ) {
			if ( $organisation['id'] === $id ) {
				$name = $organisation['name'];
			}
		}
		return array(
			'division'      => $id,
			'division_name' => $name,
		);
	}

	/**
	 * Test connection: user, administration, reads on accounts, items and the document type, VAT codes and the journal.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$client   = $this->api( $connection );
		$division = (string) $connection->config( 'division', '' );
		$me       = ( new ExactApi( $client, '0' ) )->me();
		if ( '' === $division ) {
			return new TestResult( false, '', '', array(), array(), ExactFailure::invalid( 'division', __( 'Choose the administration (division) that should receive the orders, then test again.', 'estasoft-erp-crm-sync-engine' ) )->error );
		}
		$api         = new ExactApi( $client, $division );
		$options     = $this->options( $connection );
		$docs        = new ExactDocuments( $api, new ExactLookups( $api, $options ), $options );
		$orders      = $docs->usesOrders();
		$checks      = array(
			'read_customers' => 'crm/Accounts',
			'read_items'     => 'logistics/Items',
			'read_vat_codes' => 'vat/VATCodes',
			'read_journals'  => 'financial/Journals',
			'read_documents' => $orders ? 'salesorder/SalesOrders' : 'salesinvoice/SalesInvoices',
		);
		$permissions = array();
		foreach ( $checks as $name => $endpoint ) {
			$permissions[ $name ] = $this->canRead( $api, $endpoint );
		}
		$detected = array(
			'division'  => $division,
			'document'  => $orders ? 'order' : 'invoice',
			'user'      => (string) ( $me['FullName'] ?? '' ),
			'vat_codes' => 0,
		);
		if ( $permissions['read_vat_codes'] ) {
			$detected['vat_codes'] = count( ( new ExactLookups( $api, $options ) )->salesVatCodes() );
		}
		if ( $permissions['read_journals'] && ! $orders ) {
			try {
				$detected['journal'] = ( new ExactLookups( $api, $options ) )->journal( false );
			} catch ( ExactFailure $failure ) {
				$detected['journal']              = '';
				$permissions['has_sales_journal'] = false;
				unset( $failure );
			}
		}
		$company = (string) $connection->config( 'division_name', '' );
		if ( '' === $company ) {
			$company = (string) ( $me['DivisionCustomerName'] ?? '' );
		}
		$ok = array() === array_filter( $permissions, static fn( bool $allowed ): bool => ! $allowed );
		return new TestResult( $ok, 'Exact Online REST API v1', $company, array(), $permissions, null, $detected );
	}

	/**
	 * Cheap health probe: the current user.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return array() !== ( new ExactApi( $this->api( $connection ), '0' ) )->me();
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
		return ExactFields::catalog( $mappingType );
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ExactMappings::rules( $mappingType );
	}

	/**
	 * Exact's error shapes.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ExactErrors::map( $response, $this->deps->clock );
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
				return null !== $row && $docs->isComplete( $row, $credit ) ? $docs->ref( $row ) : null;
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
		$today = $this->deps->clock->now()->format( 'Y-m-d' );
		try {
			if ( EntityType::Order === $item->entityType ) {
				$order = ExactOrder::parse( $payload, $item, $today );
				return $order instanceof ConnectorError ? SendResult::failure( $order ) : $this->documents( $connection )->sendOrder( $order );
			}
			if ( EntityType::Refund === $item->entityType ) {
				$refund = ExactRefund::parse( $payload, $item, $today );
				return $refund instanceof ConnectorError ? SendResult::failure( $refund ) : $this->documents( $connection )->sendRefund( $refund );
			}
			if ( EntityType::Customer === $item->entityType ) {
				return $this->sendCustomer( $connection, $item, $payload );
			}
			return SendResult::failure( ExactFailure::invalid( 'entity', __( 'This connector does not send that kind of item.', 'estasoft-erp-crm-sync-engine' ) )->error );
		} catch ( ExactFailure $failure ) {
			return SendResult::failure( $failure->error );
		} catch ( \Throwable $throwable ) {
			return SendResult::failure( ErrorBridge::toError( $throwable, self::LABEL ) );
		}
	}

	/**
	 * Find a customer account by email, ignoring case.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		return $this->guard(
			function () use ( $connection, $email ): ?ExternalRef {
				$row = $this->lookups( $connection )->accountByEmail( $email );
				return null === $row ? null : new ExternalRef(
					(string) ( $row['ID'] ?? '' ),
					(string) ( $row['Name'] ?? '' ),
					null,
					array(
						'email' => (string) ( $row['Email'] ?? '' ),
						'code'  => trim( (string) ( $row['Code'] ?? '' ) ),
					)
				);
			}
		);
	}

	/**
	 * Find an item by its code (the SKU).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		return $this->guard(
			function () use ( $connection, $sku ): ?ExternalRef {
				$row = $this->lookups( $connection )->item( $sku );
				return null === $row ? null : new ExternalRef( (string) ( $row['ID'] ?? '' ), trim( (string) ( $row['Code'] ?? '' ) ), null, array( 'name' => (string) ( $row['Description'] ?? '' ) ) );
			}
		);
	}

	/**
	 * Search items by code or description (the first 20).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$result = $this->guard(
			function () use ( $connection, $query ): array {
				$api    = $this->exactApi( $connection );
				$quoted = ExactApi::str( trim( $query ) );
				$rows   = $api->rows(
					'logistics/Items',
					array(
						'$filter' => 'substringof(' . $quoted . ', Code) eq true or substringof(' . $quoted . ', Description) eq true',
						'$select' => 'ID,Code,Description',
						'$top'    => 20,
					)
				);
				$out    = array();
				foreach ( array_slice( $rows, 0, 20 ) as $row ) {
					$out[] = new ExternalRef( (string) ( $row['ID'] ?? '' ), trim( (string) ( $row['Code'] ?? '' ) ), null, array( 'name' => (string) ( $row['Description'] ?? '' ) ) );
				}
				return $out;
			}
		);
		return $result;
	}

	/**
	 * On-hand and held stock of items, summed over the chosen warehouses.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       Item codes.
	 * @param array<int,string> $warehouses Warehouse codes that count; empty means all.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return $this->guard( fn(): array => $this->reads( $connection )->stock( $skus, $warehouses ) );
	}

	/**
	 * Facts of the document a synced order became.
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
	 * VAT codes for sales, for the Tax mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		return $this->guard(
			function () use ( $connection ): array {
				$out = array();
				foreach ( $this->lookups( $connection )->salesVatCodes() as $row ) {
					$out[] = array(
						'code'         => trim( (string) ( $row['Code'] ?? '' ) ),
						'name'         => trim( (string) ( $row['Description'] ?? '' ) ),
						'rate_percent' => ExactTax::percentText( $row['Percentage'] ?? null ),
					);
				}
				return $out;
			}
		);
	}

	/**
	 * Find or create the account of a customer item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Payload.
	 * @throws ExactFailure When the email or name is missing.
	 */
	private function sendCustomer( Connection $connection, Item $item, array $payload ): SendResult {
		$partner = array();
		$raw     = is_array( $payload['partner'] ?? null ) ? $payload['partner'] : array();
		foreach ( array( 'name', 'email', 'company_name', 'phone', 'street', 'city', 'zip', 'country_id', 'vat' ) as $key ) {
			$value = ExactPayload::text( $raw[ $key ] ?? '' );
			if ( '' !== $value ) {
				$partner[ $key ] = $value;
			}
		}
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return SendResult::failure(
				new ConnectorError(
					\Estasoft\SyncEngine\Domain\ErrorCode::Map003,
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
			ExactFailure::refuse( 'partner.name', __( 'The customer name is empty.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$lookups = $this->lookups( $connection );
		$row     = $lookups->accountByEmail( $partner['email'] );
		$created = false;
		if ( null === $row ) {
			if ( 'match_only' === $this->options( $connection )->choice( 'customer_policy', array( 'match_or_create', 'match_only' ), 'match_or_create' ) ) {
				ExactFailure::refuse( 'partner.email', __( 'No customer with this email address exists in Exact Online.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$row     = $lookups->createAccount( $partner );
			$created = true;
		}
		$ref = new ExternalRef( (string) ( $row['ID'] ?? '' ), (string) ( $row['Name'] ?? '' ), null, array( 'email' => $partner['email'] ) );
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
		} catch ( ExactFailure $failure ) {
			return $failure->error;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, self::LABEL );
		}
	}

	/**
	 * Whether the sign-in may read a resource.
	 *
	 * @param ExactApi $api      API.
	 * @param string   $endpoint Resource.
	 *
	 * @throws ApiException For a lapsed sign-in.
	 */
	private function canRead( ExactApi $api, string $endpoint ): bool {
		try {
			$api->rows(
				$endpoint,
				array(
					'$top'    => 1,
					'$select' => 'Division',
				)
			);
			return true;
		} catch ( ApiException $exception ) {
			if ( 401 === $exception->response()?->status() ) {
				throw $exception;
			}
			return false;
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
	 * The API of the connection's division.
	 *
	 * @param Connection $connection Connection.
	 * @throws ExactFailure When no administration was chosen.
	 */
	private function exactApi( Connection $connection ): ExactApi {
		$division = trim( (string) $connection->config( 'division', '' ) );
		if ( '' === $division ) {
			ExactFailure::refuse( 'division', __( 'Choose the administration (division) that should receive the orders.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return new ExactApi( $this->api( $connection ), $division );
	}

	/**
	 * Lookups for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function lookups( Connection $connection ): ExactLookups {
		return new ExactLookups( $this->exactApi( $connection ), $this->options( $connection ) );
	}

	/**
	 * The document helper of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function documents( Connection $connection ): ExactDocuments {
		$api     = $this->exactApi( $connection );
		$options = $this->options( $connection );
		return new ExactDocuments( $api, new ExactLookups( $api, $options ), $options );
	}

	/**
	 * The read helper of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	private function reads( Connection $connection ): ExactReads {
		$api     = $this->exactApi( $connection );
		$options = $this->options( $connection );
		return new ExactReads( $api, new ExactDocuments( $api, new ExactLookups( $api, $options ), $options ) );
	}
}
