<?php
/**
 * The Qoyod connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\HeaderKeyAuth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
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
 * Sends orders as invoices, refunds as credit notes and customers as customers to Qoyod (Saudi Arabia), and reads product stock back.
 * Connects with an API key (header `API-KEY`). See docs/connectors/qoyod.md for the objects, the flows and what could not be verified.
 */
final class QoyodConnector extends AbstractApiConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'qoyod';

	/**
	 * Name used in messages.
	 */
	public const LABEL = 'Qoyod';

	/**
	 * Shared amount helper.
	 *
	 * @var QoyodAmounts
	 */
	private QoyodAmounts $amounts;

	/**
	 * Customers.
	 *
	 * @var QoyodContacts
	 */
	private QoyodContacts $contacts;

	/**
	 * Branches, products, tax codes.
	 *
	 * @var QoyodCatalog
	 */
	private QoyodCatalog $catalog;

	/**
	 * Order flow.
	 *
	 * @var QoyodOrderSender
	 */
	private QoyodOrderSender $orders;

	/**
	 * Refund flow.
	 *
	 * @var QoyodRefundSender
	 */
	private QoyodRefundSender $refunds;

	/**
	 * Stock reads.
	 *
	 * @var QoyodStockReader
	 */
	private QoyodStockReader $stock;

	/**
	 * Error mapping.
	 *
	 * @var QoyodErrorMapper
	 */
	private QoyodErrorMapper $errors;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function __construct( ConnectorDependencies $deps ) {
		parent::__construct( $deps );
		$pager          = new QoyodPager();
		$ids            = new QoyodIds();
		$this->amounts  = new QoyodAmounts();
		$this->catalog  = new QoyodCatalog( $pager, $deps->clock );
		$this->contacts = new QoyodContacts( $pager );
		$docs           = new QoyodDocuments( $pager );
		$lines          = new QoyodLines( $this->amounts );
		$totals         = new QoyodTotals( $this->amounts );
		$this->orders   = new QoyodOrderSender( $docs, $this->contacts, $this->catalog, $lines, $totals, $this->amounts, $ids, $deps->clock );
		$this->refunds  = new QoyodRefundSender( $docs, $this->catalog, $lines, $totals, $this->amounts, $ids, $deps->clock );
		$this->stock    = new QoyodStockReader( $this->catalog );
		$this->errors   = new QoyodErrorMapper();
	}

	/**
	 * Identity, flags, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: self::ID,
			label: self::LABEL,
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::StockIn, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array( 'SA' ),
			rateLimit: new RateLimitHints( 1.0, 1 ),
			hosts: array( 'api.qoyod.com' )
		);
	}

	/**
	 * The base address: the API version 2.0 on api.qoyod.com, the only host the documentation names.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return 'https://api.qoyod.com/2.0/';
	}

	/**
	 * `API-KEY: <key>`.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new HeaderKeyAuth( 'API-KEY', 'api_key' );
	}

	/**
	 * Qoyod's own error answers.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return $this->errors->map( $response );
	}

	/**
	 * The connect form: the key, then the advanced settings.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$advanced = static fn( string $name, string $label, string $hint, string $preset = '', string $type = 'text', array $options = array() ): CredentialField => new CredentialField( $name, $label, false, false, $hint, $preset, $type, $options, 'advanced' );
		return array(
			CredentialFields::apiKey( 'api_key', __( 'API key', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Make it under Settings, General Settings in Qoyod (Pro or Advanced plan). Saved encrypted. It is never shown again.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'inventory_id', __( 'Branch number', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'The branch (inventory) that documents are booked to. Empty: the only branch.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'currency', __( 'Account currency', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Qoyod makes invoices in this currency. Orders in another currency are stopped.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'SAR' ),
			$advanced(
				'approval',
				__( 'Invoices', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'Approved invoices are posted and sent to the tax authority by Qoyod. Drafts wait for a person.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'after_check',
				'select',
				array(
					'after_check' => __( 'Approve after the total is checked', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'draft'       => __( 'Leave as draft', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
			$advanced(
				'untaxed_rows',
				__( 'Lines without tax', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'A line the shop sent without a tax. Zero-rated and exempt sales are different in tax law, so choose; by default the order stops.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'refuse',
				'select',
				array(
					'refuse'     => __( 'Stop the order', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'zero_rated' => __( 'Zero-rated (code 2)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'exempt'     => __( 'Exempt (code 3)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
			$advanced(
				'record_payment',
				__( 'Record payments', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'Paid orders also create a payment in Qoyod. Needs the payment account number below.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'no',
				'select',
				array(
					'no'  => __( 'No', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'yes' => __( 'Yes', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
			$advanced( 'payment_account_id', __( 'Payment account number', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'The number of the bank or cash account that receives the money.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced(
				'line_tax_field',
				__( 'How tax is sent', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'Change this only if Qoyod rejects the tax of a line.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'tax_id',
				'select',
				array(
					'tax_id'      => __( 'By tax code', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'tax_percent' => __( 'By percentage', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
			$advanced( 'shipping_product_sku', __( 'Shipping product (SKU)', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Qoyod has no lines without a product. A service product that carries shipping.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'fee_product_sku', __( 'Fees product (SKU)', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'A service product that carries fees and other refunded amounts.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced(
				'total_mismatch',
				__( 'A total that differs from the shop', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'Stop before approving, or approve and warn.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'fail',
				'select',
				array(
					'fail' => __( 'Stop the invoice', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'warn' => __( 'Warn and go on', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
		);
	}

	/**
	 * Test connection: the branches, and whether the branch named in the settings exists.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api      = $this->api( $connection );
		$options  = QoyodOptions::fromConnection( $connection );
		$branches = $this->catalog->branches( $api, $connection->id );
		$branch   = $this->catalog->branchId( $api, $connection->id, $options->inventoryId() );
		$names    = array();
		$list     = array();
		foreach ( $branches as $row ) {
			$name    = QoyodPayloadReader::text( $row['name'] ?? ( $row['ar_name'] ?? '' ) );
			$names[] = $name;
			$list[]  = array(
				'id'   => QoyodPayloadReader::text( $row['id'] ?? '' ),
				'name' => $name,
			);
		}
		$permissions = array(
			'read_inventories' => true,
			'branch_selected'  => ! $branch instanceof ConnectorError,
		);
		if ( $options->recordPayment() ) {
			$permissions['payment_account'] = '' !== $options->paymentAccountId();
		}
		$company = array() === array_filter( $names ) ? self::LABEL : self::LABEL . ' (' . implode( ', ', array_filter( $names ) ) . ')';
		$error   = $branch instanceof ConnectorError ? $branch : null;
		return new TestResult(
			null === $error,
			'API 2.0',
			$company,
			$list,
			$permissions,
			$error,
			array(
				'branches' => count( $list ),
				'currency' => $options->currency(),
			)
		);
	}

	/**
	 * Cheap health probe: one page of the branch list.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->api( $connection )->get( 'inventories', array( 'page' => 1 ) );
			return true;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Fields for the mapping screens.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ( new QoyodFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new QoyodDefaultMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the record of an item, by its client reference.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$api = $this->api( $connection );
		return match ( $item->entityType ) {
			EntityType::Order  => $this->orders->find( $api, $connection, $item ),
			EntityType::Refund => $this->refunds->find( $api, $connection, $item ),
			default            => null,
		};
	}

	/**
	 * Send an item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$api = $this->api( $connection );
		return match ( $item->entityType ) {
			EntityType::Order    => $this->orders->send( $api, $connection, $item, $payload ),
			EntityType::Refund   => $this->refunds->send( $api, $connection, $item, $payload ),
			EntityType::Customer => $this->sendCustomer( $connection, $item, $payload ),
			default              => SendResult::failure(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => self::LABEL,
						'field'  => $item->entityType->value,
						'reason' => __( 'This connector does not send this kind of item.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					),
					null,
					null,
					null,
					'unsupported entity'
				)
			),
		};
	}

	/**
	 * Send a customer: the existing one, or a new one.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	private function sendCustomer( Connection $connection, Item $item, array $payload ): SendResult {
		$customer = QoyodOrderPayload::customer( $payload['customer'] ?? null );
		if ( '' === $customer['email'] ) {
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
		$api      = $this->api( $connection );
		$existing = $this->contacts->find( $api, $customer['email'] );
		if ( null !== $existing ) {
			return SendResult::existing( $this->contactRef( $existing ) );
		}
		$id = $this->contacts->create( $api, $customer );
		if ( $id instanceof ConnectorError ) {
			return SendResult::failure( $id );
		}
		return SendResult::created( new ExternalRef( $id, '' !== $customer['company'] ? $customer['company'] : $customer['name'] ) );
	}

	/**
	 * Find a customer by email.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$found = $this->contacts->find( $this->api( $connection ), $email );
		return null === $found ? null : $this->contactRef( $found );
	}

	/**
	 * Find a product by SKU.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$found = $this->catalog->productBySku( $this->api( $connection ), $sku );
		return null === $found ? null : $this->productRef( $found );
	}

	/**
	 * Search products by name or SKU.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$refs = array();
		foreach ( $this->catalog->search( $this->api( $connection ), $query ) as $product ) {
			$refs[] = $this->productRef( $product );
		}
		return $refs;
	}

	/**
	 * Stock of products, summed over the branches that count.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Branch ids that count; empty means all.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return $this->stock->fetch( $this->api( $connection ), $skus, $warehouses );
	}

	/**
	 * Tax codes for the tax mapping screen: the standard ones Qoyod documents (it has no tax resource).
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		unset( $connection );
		$codes = array();
		foreach ( $this->catalog->standardTaxes() as $code => $tax ) {
			$codes[] = array(
				'code'         => (string) $code,
				'name'         => $tax['name'],
				'rate_percent' => $this->amounts->decimalString( $tax['rate_bp'], 2 ),
			);
		}
		return $codes;
	}

	/**
	 * A reference to a customer.
	 *
	 * @param array<string,mixed> $contact Customer as Qoyod reports it.
	 */
	private function contactRef( array $contact ): ExternalRef {
		return new ExternalRef(
			QoyodPayloadReader::text( $contact['id'] ?? '' ),
			QoyodPayloadReader::text( $contact['name'] ?? '' ),
			null,
			array( 'email' => strtolower( QoyodPayloadReader::text( $contact['email'] ?? '' ) ) )
		);
	}

	/**
	 * A reference to a product.
	 *
	 * @param array<string,mixed> $product Product as Qoyod reports it.
	 */
	private function productRef( array $product ): ExternalRef {
		$name = QoyodPayloadReader::text( $product['name_en'] ?? '' );
		return new ExternalRef(
			QoyodPayloadReader::text( $product['id'] ?? '' ),
			QoyodPayloadReader::text( $product['sku'] ?? '' ),
			null,
			array( 'name' => '' !== $name ? $name : QoyodPayloadReader::text( $product['name_ar'] ?? '' ) )
		);
	}
}
