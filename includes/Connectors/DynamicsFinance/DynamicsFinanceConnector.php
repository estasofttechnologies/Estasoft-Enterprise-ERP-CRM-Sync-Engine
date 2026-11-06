<?php
/**
 * The Dynamics 365 Finance and Supply Chain connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects, not output.

use Estasoft\SyncEngine\Connectors\Microsoft\EntraCredentialFields;
use Estasoft\SyncEngine\Connectors\Microsoft\EntraTokenProvider;
use Estasoft\SyncEngine\Connectors\Microsoft\EnvironmentUrl;
use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\MicrosoftErrors;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
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
 * Connects the engine to the OData data entities of one Finance and Supply Chain environment, pinned to one legal entity: an order becomes
 * a sales order (`SalesOrderHeadersV2` and `SalesOrderLines`), a buyer a customer (`CustomersV3`). The Entra app signs in with client
 * credentials as a registered application with a service user. Orders are left open; refunds, stock, tracking and invoices are not part of
 * this connector (see docs/connectors/dynamics-finance.md for why).
 */
final class DynamicsFinanceConnector extends AbstractApiConnector {

	/**
	 * Identity, flags, sign-in style, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'dynamics-finance',
			label: 'Finance and Supply Chain',
			type: ConnectorType::Erp,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Customer ),
			auth: AuthStyle::OAuthClientCredentials,
			regions: array(),
			rateLimit: new RateLimitHints( 2.0, 1, 600 ),
			hosts: array( '*.dynamics.com' )
		);
	}

	/**
	 * Address of the OData service of the environment.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the environment address is not one of Dynamics 365.
	 */
	protected function baseUrl( Connection $connection ): string {
		return $this->environment( $connection ) . '/data';
	}

	/**
	 * Token from the Entra v2.0 endpoint of the merchant's tenant, for the environment.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( new EntraTokenProvider( fn( Connection $c ): string => $this->environment( $c ), $this->label() ) );
	}

	/**
	 * The connect form: the environment and legal entity, the Entra app and the options.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$choice = static fn( string $name, string $label, array $options ): CredentialField => EntraCredentialFields::option( $name, $label, $options, (string) array_key_first( $options ) );
		$text   = static fn( string $name, string $label, string $hint = '' ): CredentialField => EntraCredentialFields::text( $name, $label, $hint );
		return array_merge(
			array(
				new CredentialField( 'environment_url', __( 'Environment URL', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, __( 'The address you open Finance and Operations at, for example https://contoso.operations.dynamics.com.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'url' ),
				new CredentialField( 'legal_entity', __( 'Legal entity (company ID)', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, __( 'The company the orders go to, for example USMF. Every call is limited to it.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			),
			EntraCredentialFields::app(),
			array(
				$choice(
					'customer_policy',
					__( 'Customers', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'match_or_create' => __( 'Match by email, create when missing', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'match_only'      => __( 'Match by email only', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'fixed'           => __( 'One fixed customer for every order', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$text( 'fixed_customer', __( 'Fixed customer account', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Used when Customers is set to one fixed customer.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'customer_group', __( 'Customer group for new customers', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Needed to create customers: a customer group ID of the legal entity.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'shipping_item', __( 'Item for shipping', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'A service or non-stock item number.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'fee_item', __( 'Item for fees', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$choice(
					'order_numbering',
					__( 'Sales order numbers', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'automatic'      => __( 'Assigned by the number sequence of the legal entity', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'shop_reference' => __( 'Use the shop reference (WC-123) as the number', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$text( 'shipping_warehouse', __( 'Shipping warehouse', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$text( 'shipping_site', __( 'Shipping site', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
				$choice(
					'shipping_date',
					__( 'Requested shipping date', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'order' => __( 'Date of the shop order', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'today' => __( 'Today', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$choice(
					'total_mismatch',
					__( 'Total differs from the shop', 'estasoft-enterprise-erpcrm-sync-engine' ),
					array(
						'fail' => __( 'Stop and show an error (also when Finance and Operations gives no total)', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'warn' => __( 'Go on with a warning', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
			)
		);
	}

	/**
	 * Map the F&O error shapes the engine's default does not know.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return MicrosoftErrors::common( $response, $this->label() );
	}

	/**
	 * Test connection: the legal entity and what the app may read.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On failure.
	 */
	protected function identify( Connection $connection ): TestResult {
		$fo      = $this->client( $connection );
		$entity  = $fo->legalEntity();
		$company = strtoupper( $entity );
		$known   = $this->legalEntityRow( $fo );
		if ( false === $known ) {
			throw new ApiException( Failures::invalid( $this->label(), 'legal_entity', __( 'This environment has no legal entity with this ID.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'legal entity not found' ) );
		}
		if ( is_array( $known ) && '' !== (string) ( $known['Name'] ?? '' ) ) {
			$company = (string) $known['Name'];
		}
		$allowed = array();
		$probes  = array(
			'read_customers'    => 'CustomersV3',
			'read_sales_orders' => 'SalesOrderHeadersV2',
			'read_products'     => 'ReleasedProductsV2',
		);
		foreach ( $probes as $name => $entityName ) {
			try {
				$fo->first( $entityName, array(), array( '$top' => 1 ) );
				$allowed[ $name ] = true;
			} catch ( ApiException $exception ) {
				if ( 403 !== $exception->status() ) {
					throw $exception;
				}
				$allowed[ $name ] = false;
			}
		}
		$missing = array_keys( array_filter( $allowed, static fn( bool $ok ): bool => ! $ok ) );
		$error   = array() === $missing ? null : new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => $this->label(),
				'action' => __( 'read customers, sales orders and released products', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			403,
			null,
			null,
			'missing: ' . implode( ',', $missing )
		);
		return new TestResult(
			null === $error,
			'Finance and Operations',
			$company,
			array(),
			$allowed,
			$error,
			array(
				'legal_entity' => $entity,
				'api'          => 'OData',
			)
		);
	}

	/**
	 * Cheap health probe: can one customer row be read?
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->client( $connection )->first( 'CustomersV3', array(), array( '$top' => 1 ) );
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
		return ( new FoFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new FoDefaultMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the finished sales order of an order item.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 *
	 * @throws ApiException On failure.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		if ( EntityType::Order !== $item->entityType ) {
			return null;
		}
		return ( new FoOrderSender( $this->client( $connection ), FoOptions::fromConnection( $connection ), $this->label(), $this->deps->logger ) )->find( $item );
	}

	/**
	 * Send an order or a customer.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		if ( ! in_array( $item->entityType, array( EntityType::Order, EntityType::Customer ), true ) ) {
			return SendResult::failure( Failures::unsupported( $this->label(), $item->entityType->value ) );
		}
		if ( EntityType::Order === $item->entityType ) {
			$problem = FoOrderSender::precheck( $payload, $this->label() );
			if ( null !== $problem ) {
				return SendResult::failure( $problem );
			}
			return ( new FoOrderSender( $this->client( $connection ), FoOptions::fromConnection( $connection ), $this->label(), $this->deps->logger ) )->send( $item, $payload );
		}
		return $this->sendCustomer( $connection, $payload );
	}

	/**
	 * Find a customer by primary email, ignoring case.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$customers = new FoCustomers( $this->client( $connection ), $this->label(), $this->deps->logger );
		$matches   = $customers->findByEmail( $email );
		return array() === $matches ? null : $customers->ref( $matches[0] );
	}

	/**
	 * Find a released product by item number.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$products = new FoProducts( $this->client( $connection ) );
		$row      = $products->find( $sku );
		return null === $row ? null : $products->ref( $row );
	}

	/**
	 * Search released products by item number.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>
	 *
	 * @throws ApiException On failure.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$products = new FoProducts( $this->client( $connection ) );
		return array_map( fn( array $row ): ExternalRef => $products->ref( $row ), $products->search( $query ) );
	}

	/**
	 * Create or find a customer from the customer sync.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	private function sendCustomer( Connection $connection, array $payload ): SendResult {
		$partner = array();
		foreach ( is_array( $payload['partner'] ?? null ) ? $payload['partner'] : array() as $key => $value ) {
			if ( is_string( $key ) && ( is_string( $value ) || is_int( $value ) ) ) {
				$partner[ $key ] = trim( (string) $value );
			}
		}
		$name = '' !== ( $partner['company_name'] ?? '' ) ? $partner['company_name'] : trim( ( $partner['first_name'] ?? '' ) . ' ' . ( $partner['last_name'] ?? ( $partner['name'] ?? '' ) ) );
		if ( '' === ( $partner['email'] ?? '' ) || '' === $name ) {
			return SendResult::failure( Failures::invalid( $this->label(), 'partner.email', __( 'A customer needs a name and an email address.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'customer incomplete' ) );
		}
		$customers = new FoCustomers( $this->client( $connection ), $this->label(), $this->deps->logger );
		$matches   = $customers->findByEmail( $partner['email'] );
		if ( array() !== $matches ) {
			return SendResult::existing( $customers->ref( $matches[0] ) );
		}
		$currency = strtoupper( (string) ( $payload['currency'] ?? '' ) );
		$code     = 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '';
		$created  = $customers->create( $partner, $name, $code, FoOptions::fromConnection( $connection ) );
		return $created instanceof ConnectorError ? SendResult::failure( $created ) : SendResult::created( $customers->ref( $created ) );
	}

	/**
	 * The legal entity row, when the service exposes the legal entities entity (it is not required for syncing).
	 *
	 * @param FoClient $fo Client.
	 * @return array<string,mixed>|false|null The row, false when the entity is exposed and holds no such legal entity, null when the entity is not exposed.
	 *
	 * @throws ApiException On a failure other than the entity being absent.
	 */
	private function legalEntityRow( FoClient $fo ): array|false|null {
		try {
			$rows = ( new \Estasoft\SyncEngine\Connectors\Microsoft\ODataPages( $fo->api(), $this->label() ) )->all(
				OData::path(
					'LegalEntities',
					array(
						'cross-company' => true,
						'$filter'       => OData::eq( 'LegalEntityId', strtoupper( $fo->legalEntity() ) ),
					)
				)
			);
			return $rows[0] ?? false;
		} catch ( ApiException $exception ) {
			if ( ! in_array( $exception->status(), array( 400, 404 ), true ) ) {
				throw $exception;
			}
			return null;
		}
	}

	/**
	 * A client pinned to the legal entity of the connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the environment address or the legal entity is refused.
	 */
	private function client( Connection $connection ): FoClient {
		$entity = FoOptions::fromConnection( $connection )->legalEntity();
		if ( '' === $entity ) {
			throw new ApiException( Failures::invalid( $this->label(), 'legal_entity', __( 'The legal entity ID is one to four letters or digits, for example USMF.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'legal entity refused' ) );
		}
		return new FoClient( $this->api( $connection ), $entity, $this->label() );
	}

	/**
	 * The clean environment address of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when it is not an address of a Dynamics 365 environment.
	 */
	private function environment( Connection $connection ): string {
		$url = EnvironmentUrl::normalise( (string) $connection->config( 'environment_url', '' ) );
		if ( null === $url ) {
			throw new ApiException( Failures::invalid( $this->label(), 'environment_url', __( 'The environment URL must be an https address ending in dynamics.com, for example https://contoso.operations.dynamics.com.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'environment refused' ) );
		}
		return $url;
	}
}
