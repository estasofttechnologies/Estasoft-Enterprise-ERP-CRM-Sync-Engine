<?php
/**
 * The Acumatica connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\ErpCommon\FieldSets;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
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
 * Orders as Sales Orders, refunds as credit memos, customers with their main contact; stock from StockItem, tracking from Shipment,
 * invoice status from SalesInvoice and the nightly list from SalesOrder. The contract-based REST API is the only wire: see
 * docs/connectors/acumatica.md for every field and what is not verified.
 */
final class AcumaticaConnector extends AbstractApiConnector implements OAuthConnector {

	/**
	 * Name shown in messages.
	 */
	public const LABEL = 'Acumatica';

	/**
	 * What Test connection reads to prove each field name: entity, then fields.
	 *
	 * @var array<string,string>
	 */
	private const PROBES = array(
		'SalesOrder'   => 'OrderType,OrderNbr,CustomerOrder,Status,OrderTotal,CurrencyID,CustomerID,Date',
		'Customer'     => 'CustomerID,CustomerName,Status',
		'SalesInvoice' => 'Type,ReferenceNbr,Status,Description,Amount,Currency,Date',
		'StockItem'    => 'InventoryID,ItemStatus',
		'TaxCategory'  => 'TaxCategoryID,Description,Active',
		'Shipment'     => 'ShipmentNbr,Status,ShipmentDate',
	);

	/**
	 * Identity, flags, sign-in style and limits.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'acumatica',
			label: self::LABEL,
			type: ConnectorType::Erp,
			capabilities: array(
				Capability::Orders,
				Capability::Customers,
				Capability::Refunds,
				Capability::StockIn,
				Capability::TrackingIn,
				Capability::InvoiceReadback,
				Capability::Reconciliation,
				Capability::ShadowLookup,
			),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthAuthorizationCode,
			regions: array(),
			rateLimit: new RateLimitHints( 1.0, 1, 50 ),
			hosts: array(),
			merchantHost: true
		);
	}

	/**
	 * The site address the merchant typed, cleaned.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is not a web address.
	 */
	protected function baseUrl( Connection $connection ): string {
		return AcumaticaSite::address( $connection, self::LABEL );
	}

	/**
	 * Bearer tokens of the merchant's own connected application.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( $this->oauthProvider() );
	}

	/**
	 * The branch the calls are made in.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		$branch = trim( (string) $connection->config( 'branch', '' ) );
		return '' === $branch ? array() : array( 'PX-CbApiBranch' => $branch );
	}

	/**
	 * Map the error body of an Acumatica site.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new AcumaticaErrorMapper() )->map( $response, self::LABEL );
	}

	/**
	 * The tenant is removed from every message besides the secrets.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values = parent::redactionValues( $connection );
		$tenant = AcumaticaSite::tenant( $connection );
		if ( '' !== $tenant ) {
			$values[] = $tenant;
		}
		return $values;
	}

	/**
	 * The sign-in settings (the provider is a small class of its own).
	 */
	public function oauthProvider(): OAuthProvider {
		return new AcumaticaOAuth();
	}

	/**
	 * The one organisation a site gives: the tenant named in the client id.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>
	 */
	public function organisations( Connection $connection ): array {
		$tenant = AcumaticaSite::tenant( $connection );
		return array(
			array(
				'id'   => '' !== $tenant ? $tenant : 'default',
				'name' => '' !== $tenant ? $tenant : self::LABEL,
			),
		);
	}

	/**
	 * Remember the tenant.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         The id from organisations().
	 * @return array<string,mixed>
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		unset( $connection );
		return array( 'tenant' => 'default' === $id ? '' : $id );
	}

	/**
	 * Connect form: address, client id and secret, then the settings.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$advanced = static fn( string $name, string $label, string $hint = '', string $type = 'text', array $options = array(), string $initial = '' ): CredentialField => new CredentialField( $name, $label, false, false, $hint, $initial, $type, $options, 'advanced' );
		return array(
			CredentialFields::address( 'address', __( 'Site address', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'The https address of your Acumatica site, with its instance folder if it has one, for example https://acme.acumatica.com', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::clientId( 'client_id', __( 'The Client ID of the connected application. It ends with @ and your tenant name.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::clientSecret( 'client_secret', __( 'The shared secret you added to the connected application. Saved encrypted.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'branch', __( 'Branch', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Only needed when the user can work in several branches.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'endpoint_version', __( 'Endpoint version', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Version of the Default endpoint, 22.200.001 to 25.200.001. Empty: 24.200.001.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'order_type', __( 'Order type', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Empty: SO.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced(
				'customer_policy',
				__( 'Customers', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'',
				'select',
				array(
					'match_or_create' => __( 'Match by email, create when missing', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'match_only'      => __( 'Match by email only', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'always_create'   => __( 'Always create a customer', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'match_or_create'
			),
			$advanced( 'customer_class', __( 'Customer class for new customers', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'warehouse', __( 'Warehouse for order lines', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'shipping_item', __( 'Inventory ID for shipping', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'fee_item', __( 'Inventory ID for fees', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'discount_item', __( 'Inventory ID for discounts', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'refund_item', __( 'Inventory ID for refunds without lines', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced(
				'refund_state',
				__( 'Credit memos', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'',
				'select',
				array(
					'hold'    => __( 'Leave on hold', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'release' => __( 'Release', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'hold'
			),
			$advanced(
				'total_mismatch',
				__( 'When Acumatica totals differ from the shop', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'',
				'select',
				array(
					'fail' => __( 'Stop and leave on hold', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'warn' => __( 'Continue with a warning', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'fail'
			),
		);
	}

	/**
	 * Test connection: the endpoint, the fields the connector uses, the warehouses and the tax categories.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On failure.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api     = $this->api( $connection );
		$version = AcumaticaSite::version( $connection );
		$offered = $this->versions( $api->get( '/entity', array(), null, array( 'action' => 'read endpoints' ) )->json() );
		$label   = 'Acumatica Default/' . $version;
		if ( array() !== $offered && ! in_array( $version, $offered, true ) ) {
			$usable = array_values( array_filter( $offered, array( AcumaticaSite::class, 'supports' ) ) );
			rsort( $usable );
			$reason = array() === $usable
				? sprintf(
					/* translators: %s: versions the site offers. */
					__( 'The site offers only these Default endpoint versions: %s. This connector supports 22.200.001 to 25.200.001.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					implode( ', ', $offered )
				)
				: sprintf(
					/* translators: 1: version asked for, 2: version to enter. */
					__( 'The site does not offer Default endpoint %1$s. Enter %2$s as the endpoint version.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$version,
					$usable[0]
				);
			return new TestResult( false, $label, '', array(), array(), Problems::refused( self::LABEL, 'endpoint_version', $reason ) );
		}
		if ( ! AcumaticaSite::supports( $version ) ) {
			return new TestResult( false, $label, '', array(), array(), Problems::refused( self::LABEL, 'endpoint_version', __( 'This connector supports Default endpoint versions 22.200.001 to 25.200.001.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}

		$client      = $this->client( $connection );
		$permissions = array();
		$refused     = array();
		foreach ( self::PROBES as $entity => $fields ) {
			try {
				$client->first( $entity, array( '$select' => $fields ) );
				$permissions[ 'read ' . $entity ] = true;
			} catch ( ApiException $exception ) {
				if ( 403 === $exception->status() ) {
					$permissions[ 'read ' . $entity ] = false;
				} elseif ( in_array( (int) $exception->status(), array( 400, 404, 422, 500 ), true ) ) {
					$permissions[ 'read ' . $entity ] = false;
					$refused[]                        = $entity;
				} else {
					throw $exception;
				}
			}
		}
		if ( array() !== $refused ) {
			return new TestResult(
				false,
				$label,
				'',
				array(),
				$permissions,
				Problems::refused(
					self::LABEL,
					'endpoint_version',
					sprintf(
						/* translators: %s: entity names. */
						__( 'Acumatica did not accept the fields this connector reads from: %s. Check the endpoint version and that the user may open these forms.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						implode( ', ', $refused )
					)
				)
			);
		}

		$lookups    = new AcumaticaLookups( $client );
		$warehouses = array();
		try {
			$warehouses = $lookups->warehouses();
		} catch ( ApiException $exception ) {
			if ( 403 !== $exception->status() && 404 !== $exception->status() ) {
				throw $exception;
			}
		}
		$categories = array();
		if ( $permissions['read TaxCategory'] ) {
			$categories = array_column( $lookups->taxCategories(), 'id' );
		}
		$tenant = AcumaticaSite::tenant( $connection );
		return new TestResult(
			true,
			$label,
			'' !== $tenant ? $tenant : self::LABEL,
			$warehouses,
			$permissions,
			null,
			array(
				'endpoint_version' => $version,
				'branch'           => trim( (string) $connection->config( 'branch', '' ) ),
				'tax_categories'   => $categories,
				'write_not_probed' => true,
			)
		);
	}

	/**
	 * Cheap health probe: can a customer list still be read?
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->client( $connection )->first( 'Customer', array( '$select' => 'CustomerID' ) );
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
		return match ( $mappingType ) {
			'orders'    => FieldSets::orders( self::LABEL ),
			'customers' => FieldSets::customers(),
			'products'  => FieldSets::products( __( 'Inventory ID', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			'refunds'   => FieldSets::refunds( self::LABEL, __( 'Credit memo', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			'stock'     => FieldSets::stock( self::LABEL ),
			'tracking'  => FieldSets::tracking( self::LABEL ),
			default     => array(),
		};
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => DefaultRules::orders(),
			'customers' => DefaultRules::customers(),
			'products'  => DefaultRules::products(),
			'refunds'   => DefaultRules::refunds(),
			'stock'     => DefaultRules::stock(),
			'tracking'  => DefaultRules::tracking(),
			default     => array(),
		};
	}

	/**
	 * Look for the record of an item that its own send finished.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item being sent.
	 *
	 * @throws ApiException On failure.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$services = $this->services( $connection );
		if ( EntityType::Order === $item->entityType ) {
			return $services['orders']->finished( $item );
		}
		if ( EntityType::Refund === $item->entityType ) {
			return $services['refunds']->finished( $item );
		}
		return null;
	}

	/**
	 * Send an order, a refund or a customer.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item being sent.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$amounts = new Amounts();
		if ( EntityType::Order === $item->entityType ) {
			$parsed = OrderPayload::parse( $payload, $item, self::LABEL, $amounts );
			if ( $parsed instanceof ConnectorError ) {
				return SendResult::failure( $parsed );
			}
			return $this->services( $connection )['orders']->send( $item, $parsed );
		}
		if ( EntityType::Refund === $item->entityType ) {
			$parsed = RefundPayload::parse( $payload, $item, self::LABEL, $amounts );
			if ( $parsed instanceof ConnectorError ) {
				return SendResult::failure( $parsed );
			}
			return $this->services( $connection )['refunds']->send( $item, $parsed );
		}
		if ( EntityType::Customer === $item->entityType ) {
			return $this->sendCustomer( $connection, $payload, $item );
		}
		return SendResult::failure( Problems::refused( self::LABEL, 'entity', __( 'This kind of item cannot be sent to Acumatica.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'unsupported entity' ) );
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
		$found = $this->services( $connection )['customers']->find( $email );
		if ( null === $found ) {
			return null;
		}
		return new ExternalRef( $found['id'], $found['name'], $this->services( $connection )['orders']->link( 'AR303000', 'AcctCD=' . rawurlencode( $found['id'] ) ), array( 'email' => $email ) );
	}

	/**
	 * Find an item by its Inventory ID (the SKU).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		$items = ( new AcumaticaLookups( $this->client( $connection ) ) )->items( array( $sku ) );
		$item  = $items[ strtolower( $sku ) ] ?? null;
		if ( null === $item ) {
			return null;
		}
		return new ExternalRef( $item['id'], $item['id'], $this->services( $connection )['orders']->link( 'IN202500', 'InventoryCD=' . rawurlencode( $item['id'] ) ), array( 'stock' => $item['stock'] ) );
	}

	/**
	 * Search stock items by Inventory ID or description.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$literal = AcumaticaValue::literal( $query );
		$rows    = $this->client( $connection )->query(
			'StockItem',
			array(
				'$filter' => 'substringof(' . $literal . ',InventoryID) or substringof(' . $literal . ',Description)',
				'$select' => 'InventoryID,Description,ItemStatus',
			),
			20
		);
		$found   = array();
		foreach ( $rows as $row ) {
			$id = AcumaticaValue::text( $row, 'InventoryID' );
			if ( '' !== $id ) {
				$found[] = new ExternalRef( $id, $id, null, array( 'name' => AcumaticaValue::text( $row, 'Description' ) ) );
			}
		}
		return $found;
	}

	/**
	 * Quantities per SKU from the warehouse details of the stock items.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouse IDs; empty means all.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return $this->services( $connection )['reader']->stock( $skus, $warehouses );
	}

	/**
	 * Shipments since a time.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $since      UTC.
	 * @return array<int,array{order_ref:string,status:string,carrier:string,tracking:string,done_at:string}>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function fetchTracking( Connection $connection, \DateTimeImmutable $since ): array|ConnectorError {
		return $this->services( $connection )['reader']->tracking( $since );
	}

	/**
	 * Invoice facts for a synced order.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The synced order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 *
	 * @throws ApiException On failure.
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return $this->services( $connection )['reader']->invoice( $ref );
	}

	/**
	 * Orders for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return $this->services( $connection )['reader']->reconcile( $from );
	}

	/**
	 * Tax categories for the tax mapping screen. The rate is set by the tax zone, so it is not known here.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		$codes = array();
		foreach ( ( new AcumaticaLookups( $this->client( $connection ) ) )->taxCategories() as $category ) {
			$name    = '' !== $category['name'] ? $category['name'] : $category['id'];
			$codes[] = array(
				'code'         => $category['id'],
				'name'         => $name . ' (' . __( 'rate set by the tax zone', 'estasoft-enterprise-erpcrm-sync-engine' ) . ')',
				'rate_percent' => '0',
			);
		}
		return $codes;
	}

	/**
	 * The client of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function client( Connection $connection ): AcumaticaClient {
		return new AcumaticaClient( $this->api( $connection ), AcumaticaSite::version( $connection ), $this->baseUrl( $connection ) );
	}

	/**
	 * The services of a connection, built for one call.
	 *
	 * @param Connection $connection Connection.
	 * @return array{orders:AcumaticaOrderSender,refunds:AcumaticaRefundSender,reader:AcumaticaReader,customers:AcumaticaCustomers}
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function services( Connection $connection ): array {
		$client    = $this->client( $connection );
		$options   = Options::of( $connection );
		$amounts   = new Amounts();
		$lookups   = new AcumaticaLookups( $client );
		$customers = new AcumaticaCustomers( $client, $options, self::LABEL );
		$orders    = new AcumaticaOrderSender( $client, $options, $amounts, $lookups, $customers, self::LABEL, $this->baseUrl( $connection ) );
		return array(
			'orders'    => $orders,
			'refunds'   => new AcumaticaRefundSender( $client, $options, $amounts, $lookups, $orders, self::LABEL ),
			'reader'    => new AcumaticaReader( $client, $amounts, $orders ),
			'customers' => $customers,
		);
	}

	/**
	 * The Default endpoint versions in the answer of `GET /entity`.
	 *
	 * @param array<string,mixed> $json Decoded answer.
	 * @return array<int,string>
	 */
	private function versions( array $json ): array {
		$out = array();
		foreach ( (array) ( $json['endpoints'] ?? array() ) as $endpoint ) {
			if ( is_array( $endpoint ) && 'default' === strtolower( (string) ( $endpoint['name'] ?? '' ) ) && is_string( $endpoint['version'] ?? null ) ) {
				$out[] = $endpoint['version'];
			}
		}
		return $out;
	}

	/**
	 * Create or match a customer from the customer sync.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $payload    Mapped payload.
	 * @param Item                $item       The queue item.
	 *
	 * @throws ApiException On failure.
	 */
	private function sendCustomer( Connection $connection, array $payload, Item $item ): SendResult {
		$partner = PayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return SendResult::failure( Problems::missingEmail( $item->entityId ) );
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			return SendResult::failure( Problems::refused( self::LABEL, 'partner.name', __( 'The customer name is empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		$services = $this->services( $connection );
		$resolved = $services['customers']->resolve( $partner );
		if ( $resolved instanceof ConnectorError ) {
			return SendResult::failure( $resolved );
		}
		$ref = new ExternalRef( $resolved['id'], $resolved['name'], $services['orders']->link( 'AR303000', 'AcctCD=' . rawurlencode( $resolved['id'] ) ), array( 'email' => $partner['email'] ) );
		return $resolved['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}
}
