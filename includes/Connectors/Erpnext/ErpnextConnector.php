<?php
/**
 * The ERPNext connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\ErpCommon\FieldSets;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\SiteAddress;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
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
 * Orders as Sales Orders (optionally with a Sales Invoice and a Payment Entry), refunds as credit notes, customers with contacts and
 * addresses; stock from Bin, tracking from Delivery Note, invoices and the nightly list from Sales Invoice and Sales Order. The Frappe
 * REST API (v1 routes) is the only wire: see docs/connectors/erpnext.md for every field and what is not verified.
 */
final class ErpnextConnector extends AbstractApiConnector {

	/**
	 * Name shown in messages.
	 */
	public const LABEL = 'ERPNext';

	/**
	 * Identity, flags, sign-in style and limits.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'erpnext',
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
			auth: AuthStyle::Key,
			regions: array(),
			rateLimit: new RateLimitHints( 2.0, 1 ),
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
		return SiteAddress::checked( (string) $connection->config( 'address', '' ), self::LABEL );
	}

	/**
	 * Token authentication.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new ErpnextTokenAuth();
	}

	/**
	 * Map the error body of a Frappe site.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new ErpnextErrorMapper() )->map( $response, self::LABEL );
	}

	/**
	 * Connect form: address, key and secret, then the settings.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$advanced = static fn( string $name, string $label, string $hint = '', string $type = 'text', array $options = array(), string $initial = '' ): CredentialField => new CredentialField( $name, $label, false, false, $hint, $initial, $type, $options, 'advanced' );
		return array(
			CredentialFields::address( 'address', __( 'Site address', 'estasoft-erp-crm-sync-engine' ), __( 'The https address of your ERPNext site, for example https://erp.example.com', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::apiKey( 'api_key', __( 'API key', 'estasoft-erp-crm-sync-engine' ), __( 'From the user: API Access, Generate Keys. Saved encrypted.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::apiKey( 'api_secret', __( 'API secret', 'estasoft-erp-crm-sync-engine' ), __( 'Shown once when the keys are generated. Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'company', __( 'Company', 'estasoft-erp-crm-sync-engine' ), __( 'Only needed when the site has several companies.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'documents',
				__( 'Documents to make', 'estasoft-erp-crm-sync-engine' ),
				'',
				'select',
				array(
					'order'                 => __( 'Sales Order', 'estasoft-erp-crm-sync-engine' ),
					'order_invoice'         => __( 'Sales Order and Sales Invoice', 'estasoft-erp-crm-sync-engine' ),
					'order_invoice_payment' => __( 'Sales Order, Sales Invoice and Payment Entry', 'estasoft-erp-crm-sync-engine' ),
				),
				'order'
			),
			$advanced(
				'customer_policy',
				__( 'Customers', 'estasoft-erp-crm-sync-engine' ),
				'',
				'select',
				array(
					'match_or_create' => __( 'Match by email, create when missing', 'estasoft-erp-crm-sync-engine' ),
					'match_only'      => __( 'Match by email only', 'estasoft-erp-crm-sync-engine' ),
					'always_create'   => __( 'Always create a customer', 'estasoft-erp-crm-sync-engine' ),
				),
				'match_or_create'
			),
			$advanced( 'customer_group', __( 'Customer group for new customers', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'territory', __( 'Territory for new customers', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'price_list', __( 'Selling price list', 'estasoft-erp-crm-sync-engine' ), __( 'Empty: the first enabled selling price list in the order currency.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'warehouse', __( 'Warehouse for order lines', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'delivery_days', __( 'Days from the order date to the delivery date', 'estasoft-erp-crm-sync-engine' ), '', 'number', array(), '0' ),
			$advanced( 'shipping_item', __( 'Item code for shipping', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'fee_item', __( 'Item code for fees', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'refund_item', __( 'Item code for refunds without lines', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'payment_account', __( 'Account that receives payments', 'estasoft-erp-crm-sync-engine' ), __( 'Needed for the Payment Entry option.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'mode_of_payment', __( 'Mode of payment', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'refund_state',
				__( 'Credit notes', 'estasoft-erp-crm-sync-engine' ),
				'',
				'select',
				array(
					'draft' => __( 'Leave as draft', 'estasoft-erp-crm-sync-engine' ),
					'post'  => __( 'Submit', 'estasoft-erp-crm-sync-engine' ),
				),
				'draft'
			),
			$advanced(
				'total_mismatch',
				__( 'When ERPNext totals differ from the shop', 'estasoft-erp-crm-sync-engine' ),
				'',
				'select',
				array(
					'fail' => __( 'Stop and leave a draft', 'estasoft-erp-crm-sync-engine' ),
					'warn' => __( 'Continue with a warning', 'estasoft-erp-crm-sync-engine' ),
				),
				'fail'
			),
		);
	}

	/**
	 * Test connection: who the key belongs to, the version, the company, the roles and the setup facts.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On failure.
	 */
	protected function identify( Connection $connection ): TestResult {
		$client = new ErpnextClient( $this->api( $connection ) );
		$user   = $client->call( 'frappe.auth.get_logged_user' );
		if ( ! is_string( $user ) || '' === $user || 'Guest' === $user ) {
			return new TestResult( false, '', '', array(), array(), new ConnectorError( ErrorCode::Auth001, array( 'system' => self::LABEL ), null, null, null, 'not signed in' ) );
		}
		$versions = $client->call( 'frappe.utils.change_log.get_versions', array(), array( 403, 404 ) );
		$frappe   = is_array( $versions ) && isset( $versions['frappe']['version'] ) ? (string) $versions['frappe']['version'] : '';
		$erpnext  = is_array( $versions ) && isset( $versions['erpnext']['version'] ) ? (string) $versions['erpnext']['version'] : '';
		if ( '' !== $frappe && (int) $frappe < 14 ) {
			return new TestResult(
				false,
				'' !== $erpnext ? 'ERPNext ' . $erpnext : 'Frappe ' . $frappe,
				'',
				array(),
				array(),
				Problems::refused( self::LABEL, 'version', __( 'Use ERPNext 14 or later.', 'estasoft-erp-crm-sync-engine' ) )
			);
		}

		$options = Options::of( $connection );
		$lookups = new ErpnextLookups( $client, $options, self::LABEL );
		$company = $lookups->company();
		if ( $company instanceof ConnectorError ) {
			return new TestResult( false, $this->versionText( $erpnext, $frappe ), '', array(), array(), $company );
		}

		$permissions = array();
		foreach ( array( 'Customer', 'Item', 'Sales Order', 'Sales Invoice', 'Payment Entry', 'Bin', 'Delivery Note', 'Sales Taxes and Charges Template', 'Price List' ) as $doctype ) {
			try {
				$client->first( $doctype, array(), array( 'name' ) );
				$permissions[ 'read ' . $doctype ] = true;
			} catch ( ApiException $exception ) {
				if ( 403 !== $exception->status() ) {
					throw $exception;
				}
				$permissions[ 'read ' . $doctype ] = false;
			}
		}

		$warehouses = array();
		foreach ( $client->rows( 'Warehouse', array( array( 'company', '=', $company['name'] ), array( 'disabled', '=', 0 ), array( 'is_group', '=', 0 ) ), array( 'name' ), 'name asc', 50 ) as $row ) {
			$warehouses[] = array(
				'id'   => (string) $row['name'],
				'name' => (string) $row['name'],
			);
		}
		$lists = array();
		foreach ( $client->rows( 'Price List', array( array( 'enabled', '=', 1 ), array( 'selling', '=', 1 ) ), array( 'name', 'currency' ), 'name asc', 50 ) as $row ) {
			$lists[] = $row['name'] . ' (' . $row['currency'] . ')';
		}
		$templates = ( new ErpnextTaxes( $client, self::LABEL ) )->codes( $company['name'] );

		return new TestResult(
			true,
			$this->versionText( $erpnext, $frappe ),
			$company['name'],
			$warehouses,
			$permissions,
			null,
			array(
				'user'             => $user,
				'currency'         => $company['currency'],
				'price_lists'      => $lists,
				'tax_templates'    => array_column( $templates, 'code' ),
				'documents'        => $options->choice( 'documents', array( 'order', 'order_invoice', 'order_invoice_payment' ), 'order' ),
				'write_not_probed' => true,
			)
		);
	}

	/**
	 * Cheap health probe: does the key still belong to a user?
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$user = ( new ErpnextClient( $this->api( $connection ) ) )->call( 'frappe.auth.get_logged_user' );
			return is_string( $user ) && '' !== $user && 'Guest' !== $user;
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
			'products'  => FieldSets::products( __( 'Item code', 'estasoft-erp-crm-sync-engine' ) ),
			'refunds'   => FieldSets::refunds( self::LABEL, __( 'Credit note', 'estasoft-erp-crm-sync-engine' ) ),
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
			return $services['orders']->finished( $item, true );
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
		return SendResult::failure( Problems::refused( self::LABEL, 'entity', __( 'This kind of item cannot be sent to ERPNext.', 'estasoft-erp-crm-sync-engine' ), 'unsupported entity' ) );
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
		$client = new ErpnextClient( $this->api( $connection ) );
		$found  = ( new ErpnextCustomers( $client, new ErpnextLookups( $client, Options::of( $connection ), self::LABEL ), Options::of( $connection ), self::LABEL ) )->find( $email );
		if ( null === $found ) {
			return null;
		}
		return new ExternalRef( $found['name'], $found['customer_name'], rtrim( $this->baseUrl( $connection ), '/' ) . '/app/customer/' . rawurlencode( $found['name'] ), array( 'email' => $email ) );
	}

	/**
	 * Find an item by its code (the SKU).
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
		$client = new ErpnextClient( $this->api( $connection ) );
		$items  = ( new ErpnextLookups( $client, Options::of( $connection ), self::LABEL ) )->items( array( $sku ) );
		$item   = $items[ strtolower( $sku ) ] ?? null;
		if ( null === $item ) {
			return null;
		}
		return new ExternalRef( (string) $item['item_code'], (string) $item['item_code'], rtrim( $this->baseUrl( $connection ), '/' ) . '/app/item/' . rawurlencode( (string) $item['item_code'] ), array( 'name' => (string) ( $item['item_name'] ?? '' ) ) );
	}

	/**
	 * Search items by code or name.
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
		$client = new ErpnextClient( $this->api( $connection ) );
		$found  = array();
		foreach ( array( 'item_code', 'item_name' ) as $field ) {
			foreach ( $client->rows( 'Item', array( array( $field, 'like', '%' . $query . '%' ), array( 'disabled', '=', 0 ) ), array( 'item_code', 'item_name' ), 'item_code asc', 20 ) as $row ) {
				$found[ (string) $row['item_code'] ] = new ExternalRef( (string) $row['item_code'], (string) $row['item_code'], null, array( 'name' => (string) ( $row['item_name'] ?? '' ) ) );
			}
		}
		return array_slice( array_values( $found ), 0, 20 );
	}

	/**
	 * Quantities per SKU from the Bin records.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouse names; empty means all.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return $this->services( $connection )['reader']->stock( $skus, $warehouses );
	}

	/**
	 * Delivery Notes since a time.
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
	 * Sales Taxes and Charges Templates for the tax mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		$client  = new ErpnextClient( $this->api( $connection ) );
		$company = ( new ErpnextLookups( $client, Options::of( $connection ), self::LABEL ) )->company();
		if ( $company instanceof ConnectorError ) {
			return $company;
		}
		return ( new ErpnextTaxes( $client, self::LABEL ) )->codes( $company['name'] );
	}

	/**
	 * The services of a connection, built for one call.
	 *
	 * @param Connection $connection Connection.
	 * @return array{orders:ErpnextOrderSender,refunds:ErpnextRefundSender,reader:ErpnextReader,customers:ErpnextCustomers}
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function services( Connection $connection ): array {
		$client    = new ErpnextClient( $this->api( $connection ) );
		$options   = Options::of( $connection );
		$amounts   = new Amounts();
		$lookups   = new ErpnextLookups( $client, $options, self::LABEL );
		$taxes     = new ErpnextTaxes( $client, self::LABEL );
		$customers = new ErpnextCustomers( $client, $lookups, $options, self::LABEL );
		$address   = $this->baseUrl( $connection );
		$orders    = new ErpnextOrderSender( $client, $options, $amounts, $lookups, $customers, $taxes, self::LABEL, $address );
		return array(
			'orders'    => $orders,
			'refunds'   => new ErpnextRefundSender( $client, $options, $amounts, $lookups, $taxes, $orders, self::LABEL ),
			'reader'    => new ErpnextReader( $client, $amounts, $lookups, $orders, $address ),
			'customers' => $customers,
		);
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
			return SendResult::failure( Problems::refused( self::LABEL, 'partner.name', __( 'The customer name is empty.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$resolved = $this->services( $connection )['customers']->resolveParts( $partner, array() );
		if ( $resolved instanceof ConnectorError ) {
			return SendResult::failure( $resolved );
		}
		$ref = new ExternalRef( $resolved['customer'], $resolved['customer'], rtrim( $this->baseUrl( $connection ), '/' ) . '/app/customer/' . rawurlencode( $resolved['customer'] ), array( 'email' => $partner['email'] ) );
		return $resolved['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Human text of the installed versions.
	 *
	 * @param string $erpnext ERPNext version.
	 * @param string $frappe  Frappe version.
	 */
	private function versionText( string $erpnext, string $frappe ): string {
		if ( '' !== $erpnext && '' !== $frappe ) {
			return 'ERPNext ' . $erpnext . ' (Frappe ' . $frappe . ')';
		}
		if ( '' !== $erpnext ) {
			return 'ERPNext ' . $erpnext;
		}
		return '' !== $frappe ? 'Frappe ' . $frappe : 'ERPNext';
	}
}
