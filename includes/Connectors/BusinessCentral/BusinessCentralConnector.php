<?php
/**
 * The Microsoft Dynamics 365 Business Central connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects, not output.

use Estasoft\SyncEngine\Connectors\Microsoft\EntraCredentialFields;
use Estasoft\SyncEngine\Connectors\Microsoft\EntraTokenProvider;
use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\MicrosoftErrors;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
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
 * Connects the engine to Business Central online through the standard API v2.0: orders become sales orders (or posted sales invoices),
 * refunds become sales credit memos, buyers become customers. The Entra app signs in with client credentials, so nobody signs in on a page.
 * Every call is pinned to one environment and one company. See docs/connectors/business-central.md.
 */
final class BusinessCentralConnector extends AbstractApiConnector {

	/**
	 * The one address every call goes to.
	 */
	private const API_HOST = 'api.businesscentral.dynamics.com';

	/**
	 * Companies already looked up in this request, by connection settings.
	 *
	 * @var array<string,array{id:string,name:string,version:string}>
	 */
	private array $companies = array();

	/**
	 * Identity, flags, sign-in style, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'business-central',
			label: 'Business Central',
			type: ConnectorType::Erp,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::InvoiceReadback, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthClientCredentials,
			regions: array(),
			rateLimit: new RateLimitHints( 4.0, 1, 300 ),
			hosts: array( self::API_HOST )
		);
	}

	/**
	 * Environment address of the API.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the environment name is not a name.
	 */
	protected function baseUrl( Connection $connection ): string {
		$environment = trim( (string) $connection->config( 'environment', 'production' ) );
		$environment = '' === $environment ? 'production' : $environment;
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $environment ) ) {
			throw new ApiException( Failures::invalid( $this->label(), 'environment', __( 'The environment name may hold letters, digits, dots, hyphens and underscores.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'environment refused' ) );
		}
		return 'https://' . self::API_HOST . '/v2.0/' . $environment . '/api/v2.0';
	}

	/**
	 * Token from the Entra v2.0 endpoint of the merchant's tenant.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( new EntraTokenProvider( static fn(): string => 'https://' . self::API_HOST, $this->label() ) );
	}

	/**
	 * The connect form: environment and company, the Entra app, and the options.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$choice = static fn( string $name, string $label, array $options, string $hint = '' ): CredentialField => EntraCredentialFields::option( $name, $label, $options, (string) array_key_first( $options ), $hint );
		$text   = static fn( string $name, string $label, string $hint = '' ): CredentialField => EntraCredentialFields::text( $name, $label, $hint );
		return array_merge(
			array(
				new CredentialField( 'environment', __( 'Environment name', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Shown in the Business Central admin center, for example production or sandbox.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'production' ),
				new CredentialField( 'company', __( 'Company (name or ID)', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Leave empty when the environment has one company.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			),
			EntraCredentialFields::app(),
			array(
				$choice(
					'documents',
					__( 'Documents', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'order'              => __( 'Sales order only', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'order_ship_invoice' => __( 'Sales order, shipped and invoiced', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'invoice'            => __( 'Posted sales invoice', 'estasoft-enterprise-erpcrm-sync-engine' ),
					),
					__( 'Shipping and invoicing posts to the books and cannot be undone.', 'estasoft-enterprise-erpcrm-sync-engine' )
				),
				$choice(
					'customer_policy',
					__( 'Customers', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'match_or_create' => __( 'Match by email, create when missing', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'match_only'      => __( 'Match by email only', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'fixed'           => __( 'One fixed customer for every order', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$text( 'fixed_customer', __( 'Fixed customer number', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Used when Customers is set to one fixed customer.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$choice(
					'unknown_sku',
					__( 'SKU not found as an item', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'refuse'  => __( 'Refuse the order', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'account' => __( 'Post the line to a G/L account', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$text( 'unknown_sku_account', __( 'G/L account for unknown SKUs', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$choice(
					'sku_field',
					__( 'Match SKU with', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'number' => __( 'Item number', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'gtin'   => __( 'GTIN', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$choice(
					'posting_date',
					__( 'Posting date', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'order' => __( 'Date of the shop order', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'today' => __( 'Business Central\'s own date', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$choice(
					'total_mismatch',
					__( 'Total differs from the shop', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'fail' => __( 'Stop and show an error', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'warn' => __( 'Go on with a warning', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$choice(
					'refund_state',
					__( 'Credit memos', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'draft' => __( 'Leave as a draft', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'post'  => __( 'Post them', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$text( 'shipping_item', __( 'Item for shipping', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'shipping_account', __( 'G/L account for shipping', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'fee_item', __( 'Item for fees', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'fee_account', __( 'G/L account for fees', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'discount_item', __( 'Item for discounts', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'discount_account', __( 'G/L account for discounts', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'refund_item', __( 'Item for refunds without lines', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'refund_account', __( 'G/L account for refunds without lines', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			)
		);
	}

	/**
	 * Map the Business Central error shapes the engine's default does not know.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		$common = MicrosoftErrors::common( $response, $this->label() );
		if ( null !== $common ) {
			return $common;
		}
		if ( 404 === $response->status() ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => $this->label(),
					'field'  => 'environment',
					'reason' => __( 'Business Central did not find this environment, company or record. Check the environment name and the company.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				404,
				null,
				null,
				'not found'
			);
		}
		return null;
	}

	/**
	 * Test connection: the company, the version, what the app may read, and the tax groups found.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On failure.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api     = $this->api( $connection );
		$company = $this->company( $api, $connection );
		$bc      = new BcClient( $api, $company['id'], $this->label() );
		$allowed = array();
		$probes  = array(
			'read_customers'    => 'customers',
			'read_items'        => 'items',
			'read_sales_orders' => 'salesOrders',
		);
		foreach ( $probes as $name => $collection ) {
			try {
				$bc->first(
					$bc->path( $collection ),
					array(
						'$top'    => 1,
						'$select' => 'id',
					)
				);
				$allowed[ $name ] = true;
			} catch ( ApiException $exception ) {
				if ( 403 !== $exception->status() ) {
					throw $exception;
				}
				$allowed[ $name ] = false;
			}
		}
		$groups  = $bc->rows( $bc->path( 'taxGroups' ), array( '$select' => 'code' ) );
		$missing = array_keys( array_filter( $allowed, static fn( bool $ok ): bool => ! $ok ) );
		$error   = array() === $missing ? null : new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => $this->label(),
				'action' => __( 'read customers, items and sales documents', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			403,
			null,
			null,
			'missing: ' . implode( ',', $missing )
		);
		return new TestResult(
			null === $error,
			'' !== $company['version'] ? 'Business Central ' . $company['version'] : 'Business Central',
			$company['name'],
			array(),
			$allowed,
			$error,
			array(
				'company_id'  => $company['id'],
				'environment' => (string) $connection->config( 'environment', 'production' ),
				'api'         => 'v2.0',
				'tax_groups'  => count( $groups ),
			)
		);
	}

	/**
	 * Cheap health probe: can the company list be read?
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			( new BcClient( $this->api( $connection ), '', $this->label() ) )->first( 'companies', array( '$top' => 1 ) );
			return true;
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
		return ( new BcFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new BcDefaultMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the finished document of an item (see BcOrderSender::find and BcRefundSender::find).
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 *
	 * @throws ApiException On failure.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		if ( EntityType::Order !== $item->entityType && EntityType::Refund !== $item->entityType ) {
			return null;
		}
		$bc      = $this->client( $connection );
		$options = BcOptions::fromConnection( $connection );
		return EntityType::Order === $item->entityType
			? ( new BcOrderSender( $bc, $options, $this->label(), $this->deps->logger ) )->find( $item )
			: ( new BcRefundSender( $bc, $options, $this->label() ) )->find( $item );
	}

	/**
	 * Send an order, a refund or a customer.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		if ( ! in_array( $item->entityType, array( EntityType::Order, EntityType::Refund, EntityType::Customer ), true ) ) {
			return SendResult::failure( Failures::unsupported( $this->label(), $item->entityType->value ) );
		}
		$problem = match ( $item->entityType ) {
			EntityType::Order  => BcOrderSender::precheck( $payload, $this->label() ),
			EntityType::Refund => BcRefundSender::precheck( $payload, $this->label() ),
			default            => null,
		};
		if ( null !== $problem ) {
			return SendResult::failure( $problem );
		}
		$bc      = $this->client( $connection );
		$options = BcOptions::fromConnection( $connection );
		return match ( $item->entityType ) {
			EntityType::Refund   => ( new BcRefundSender( $bc, $options, $this->label() ) )->send( $item, $payload ),
			EntityType::Customer => $this->sendCustomer( $bc, $payload ),
			default              => ( new BcOrderSender( $bc, $options, $this->label(), $this->deps->logger ) )->send( $item, $payload ),
		};
	}

	/**
	 * Find a customer by email, ignoring case.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$customers = new BcCustomers( $this->client( $connection ), $this->label(), $this->deps->logger );
		$matches   = $customers->findByEmail( $email );
		return array() === $matches ? null : $customers->ref( $matches[0] );
	}

	/**
	 * Find an item by SKU.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$items = new BcItems( $this->client( $connection ), BcOptions::fromConnection( $connection ), $this->label() );
		$row   = $items->find( $sku );
		return null === $row ? null : $items->ref( $row );
	}

	/**
	 * Search items by number or name.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>
	 *
	 * @throws ApiException On failure.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$items = new BcItems( $this->client( $connection ), BcOptions::fromConnection( $connection ), $this->label() );
		return array_map( fn( array $row ): ExternalRef => $items->ref( $row ), $items->search( $query ) );
	}

	/**
	 * The posted invoice of a synced order.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The synced document.
	 *
	 * @throws ApiException On failure.
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return ( new BcReader( $this->client( $connection ) ) )->invoice( $ref );
	}

	/**
	 * Orders, invoices and refunds of a window for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>
	 *
	 * @throws ApiException On failure.
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return ( new BcReader( $this->client( $connection ) ) )->reconcile( $from );
	}

	/**
	 * Tax groups for the tax mapping screen. Business Central does not hold the rates in this API, so the rate is empty.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		$bc    = $this->client( $connection );
		$codes = array();
		foreach ( $bc->rows( $bc->path( 'taxGroups' ), array( '$select' => 'code,displayName,taxType' ) ) as $row ) {
			$code = trim( (string) ( $row['code'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$codes[] = array(
				'code'         => $code,
				'name'         => trim( ( '' !== (string) ( $row['displayName'] ?? '' ) ? (string) $row['displayName'] : $code ) . ' ' . __( '(rate set in Business Central)', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				'rate_percent' => '',
			);
		}
		return $codes;
	}

	/**
	 * Create or find a customer from the customer sync.
	 *
	 * @param BcClient            $bc      Client.
	 * @param array<string,mixed> $payload Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	private function sendCustomer( BcClient $bc, array $payload ): SendResult {
		$partner = array();
		foreach ( is_array( $payload['partner'] ?? null ) ? $payload['partner'] : array() as $key => $value ) {
			if ( is_string( $key ) && ( is_string( $value ) || is_int( $value ) ) ) {
				$partner[ $key ] = trim( (string) $value );
			}
		}
		$name = '' !== ( $partner['company_name'] ?? '' ) ? $partner['company_name'] : ( $partner['name'] ?? trim( ( $partner['first_name'] ?? '' ) . ' ' . ( $partner['last_name'] ?? '' ) ) );
		if ( '' === ( $partner['email'] ?? '' ) || '' === $name ) {
			return SendResult::failure( Failures::invalid( $this->label(), 'partner.email', __( 'A customer needs a name and an email address.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'customer incomplete' ) );
		}
		$customers = new BcCustomers( $bc, $this->label(), $this->deps->logger );
		$matches   = $customers->findByEmail( $partner['email'] );
		if ( array() !== $matches ) {
			return SendResult::existing( $customers->ref( $matches[0] ) );
		}
		return SendResult::created( $customers->ref( $customers->create( $partner, $name ) ) );
	}

	/**
	 * A client pinned to the connection's company.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On failure.
	 */
	private function client( Connection $connection ): BcClient {
		$api = $this->api( $connection );
		return new BcClient( $api, $this->company( $api, $connection )['id'], $this->label() );
	}

	/**
	 * The company of a connection, looked up once per request.
	 *
	 * @param ApiClient  $api        Client.
	 * @param Connection $connection Connection.
	 * @return array{id:string,name:string,version:string}
	 *
	 * @throws ApiException On failure.
	 */
	private function company( ApiClient $api, Connection $connection ): array {
		$setting = trim( (string) $connection->config( 'company', '' ) );
		$key     = md5( implode( '|', array( (string) $connection->config( 'tenant_id', '' ), (string) $connection->config( 'client_id', '' ), (string) $connection->config( 'environment', '' ), $setting ) ) );
		if ( ! isset( $this->companies[ $key ] ) ) {
			$this->companies[ $key ] = ( new BcCompanies( $this->label() ) )->resolve( new BcClient( $api, '', $this->label() ), $setting );
		}
		return $this->companies[ $key ];
	}
}
