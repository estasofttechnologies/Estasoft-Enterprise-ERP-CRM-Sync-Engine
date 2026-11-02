<?php
/**
 * The Holded connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
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
 * Sends orders as invoices or sales orders, refunds as credit notes and customers as contacts to Holded (Spain and the EU). Connects with an API
 * key in the `key` header. See docs/connectors/holded.md for the objects, the flows and what could not be verified.
 */
final class HoldedConnector extends AbstractApiConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'holded';

	/**
	 * Name used in messages.
	 */
	public const LABEL = 'Holded';

	/**
	 * Call quota.
	 *
	 * @var HoldedQuota
	 */
	private HoldedQuota $quota;

	/**
	 * Shared amount helper.
	 *
	 * @var HoldedAmounts
	 */
	private HoldedAmounts $amounts;

	/**
	 * Contacts.
	 *
	 * @var HoldedContacts
	 */
	private HoldedContacts $contacts;

	/**
	 * Products and warehouses.
	 *
	 * @var HoldedCatalog
	 */
	private HoldedCatalog $catalog;

	/**
	 * Order flow.
	 *
	 * @var HoldedOrderSender
	 */
	private HoldedOrderSender $orders;

	/**
	 * Refund flow.
	 *
	 * @var HoldedRefundSender
	 */
	private HoldedRefundSender $refunds;

	/**
	 * Error mapping.
	 *
	 * @var HoldedErrorMapper
	 */
	private HoldedErrorMapper $errors;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorDependencies $deps  Shared services.
	 * @param HoldedIndexStore|null $store Where the indexes and the call quota are kept between requests (transients by default).
	 */
	public function __construct( ConnectorDependencies $deps, ?HoldedIndexStore $store = null ) {
		parent::__construct( $deps );
		$store          = $store ?? new HoldedTransientStore();
		$this->errors   = new HoldedErrorMapper();
		$this->quota    = new HoldedQuota( $deps->clock, $store );
		$pager          = new HoldedPager( $this->errors, $this->quota );
		$index          = new HoldedIndex( $pager, $deps->clock, $store );
		$ids            = new HoldedIds();
		$dates          = new HoldedDates( $deps->clock );
		$this->amounts  = new HoldedAmounts();
		$this->catalog  = new HoldedCatalog( $index );
		$this->contacts = new HoldedContacts( $pager, $index, $this->quota );
		$docs           = new HoldedDocuments( $pager, $ids, $this->quota );
		$lines          = new HoldedLines( $this->amounts );
		$totals         = new HoldedTotals( $this->amounts );
		$this->orders   = new HoldedOrderSender( $docs, $this->contacts, $this->catalog, $lines, $totals, $this->amounts, $ids, $dates );
		$this->refunds  = new HoldedRefundSender( $docs, $this->catalog, $lines, $totals, $this->amounts, $ids, $dates );
	}

	/**
	 * Identity, flags, limits and hosts. The shadow lookup is not flagged: the marker is the connector's own, so a document made by another
	 * tool cannot be matched to an order.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: self::ID,
			label: self::LABEL,
			type: ConnectorType::Erp,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array( 'ES', 'EU' ),
			rateLimit: new RateLimitHints( 1.0, 1, 60 ),
			hosts: array( 'api.holded.com' )
		);
	}

	/**
	 * The base address.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return 'https://api.holded.com/api/';
	}

	/**
	 * `key: <API key>` [third-party].
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new HeaderKeyAuth( 'key', 'api_key' );
	}

	/**
	 * Holded's own error answers.
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
			CredentialFields::apiKey( 'api_key', __( 'API key', 'estasoft-erp-crm-sync-engine' ), __( 'Create it in Holded under Settings, Developers. Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'document_type',
				__( 'Orders become', 'estasoft-erp-crm-sync-engine' ),
				__( 'An invoice is numbered at once and cannot be deleted by the plugin. A sales order can.', 'estasoft-erp-crm-sync-engine' ),
				'invoice',
				'select',
				array(
					'invoice'    => __( 'Invoices', 'estasoft-erp-crm-sync-engine' ),
					'salesorder' => __( 'Sales orders', 'estasoft-erp-crm-sync-engine' ),
				)
			),
			$advanced( 'warehouse_id', __( 'Warehouse', 'estasoft-erp-crm-sync-engine' ), __( 'The warehouse number documents are booked to. Empty: none.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'sales_channel_id', __( 'Sales channel', 'estasoft-erp-crm-sync-engine' ), __( 'The number of a sales channel in Holded. Empty: none.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'payment_method_id', __( 'Payment method', 'estasoft-erp-crm-sync-engine' ), __( 'The number of a payment method in Holded. Empty: none.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'unknown_sku',
				__( 'A product Holded does not know', 'estasoft-erp-crm-sync-engine' ),
				__( 'Stop the order, or send the line as text without a product.', 'estasoft-erp-crm-sync-engine' ),
				'refuse',
				'select',
				array(
					'refuse'    => __( 'Stop the order', 'estasoft-erp-crm-sync-engine' ),
					'free_text' => __( 'Send a text line', 'estasoft-erp-crm-sync-engine' ),
				)
			),
			$advanced(
				'total_mismatch',
				__( 'A total that differs from the shop', 'estasoft-erp-crm-sync-engine' ),
				__( 'Stop before the document is made, or make it and warn.', 'estasoft-erp-crm-sync-engine' ),
				'fail',
				'select',
				array(
					'fail' => __( 'Stop the document', 'estasoft-erp-crm-sync-engine' ),
					'warn' => __( 'Warn and go on', 'estasoft-erp-crm-sync-engine' ),
				)
			),
		);
	}

	/**
	 * The client of a connection, bound to it so the quota guard knows whose calls it counts.
	 *
	 * @param Connection $connection Connection.
	 */
	private function client( Connection $connection ): ApiClient {
		$api = $this->api( $connection );
		$this->quota->bind( $api, $connection->id );
		return $api;
	}

	/**
	 * Cheap health probe: one page of the warehouse list.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->client( $connection )->get( 'invoicing/v1/warehouse', array( 'page' => 1 ) );
			return true;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Test connection: the warehouses, whether contacts can be read, and what is left of the call quota.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api      = $this->client( $connection );
		$options  = HoldedOptions::fromConnection( $connection );
		$response = $api->get( 'invoicing/v1/warehouse', array( 'page' => 1 ) );
		$this->quota->note( $api, $response );
		$json    = $response->json();
		$refusal = $this->errors->answer( $json );
		if ( null !== $refusal ) {
			return new TestResult( false, 'API v1', self::LABEL, array(), array( 'read_warehouses' => false ), $refusal );
		}
		$warehouse = array();
		foreach ( array_is_list( $json ) ? $json : array() as $row ) {
			if ( is_array( $row ) ) {
				$warehouse[] = array(
					'id'   => HoldedPayloadReader::text( $row['id'] ?? '' ),
					'name' => HoldedPayloadReader::text( $row['name'] ?? '' ),
				);
			}
		}
		$api->get( 'invoicing/v1/contacts', array( 'page' => 1 ) );
		$perms = array( 'read_contacts' => true );
		if ( '' !== $options->warehouseId() ) {
			$perms['warehouse_id'] = in_array( $options->warehouseId(), array_column( $warehouse, 'id' ), true );
		}
		$detected  = array( 'warehouses' => count( $warehouse ) );
		$remaining = $response->header( 'X-RateLimit-Remaining' );
		$window    = $response->header( 'X-RateLimit-Window' );
		if ( null !== $remaining && is_numeric( $remaining ) ) {
			$detected['calls_left'] = (int) $remaining;
		}
		if ( null !== $window && in_array( strtolower( $window ), array( 'minute', 'month' ), true ) ) {
			$detected['calls_window'] = strtolower( $window );
		}
		$error = in_array( false, $perms, true )
			? new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => self::LABEL,
					'field'  => 'warehouse_id',
					'reason' => __( 'Holded has no warehouse with this number.', 'estasoft-erp-crm-sync-engine' ),
				),
				null,
				null,
				null,
				'warehouse unknown'
			)
			: null;
		return new TestResult( null === $error, 'API v1', self::LABEL, $warehouse, $perms, $error, $detected );
	}

	/**
	 * Fields for the mapping screens.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ( new HoldedFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new HoldedDefaultMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the finished document of an item, by the engine's link or by the marker among recent documents.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$api = $this->client( $connection );
		return match ( $item->entityType ) {
			EntityType::Order  => $this->orders->find( $api, $connection, $item ),
			EntityType::Refund => $this->refunds->find( $api, $item ),
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
		$api = $this->client( $connection );
		return match ( $item->entityType ) {
			EntityType::Order    => $this->orders->send( $api, $connection, $item, $payload ),
			EntityType::Refund   => $this->refunds->send( $api, $connection, $item, $payload ),
			EntityType::Customer => $this->sendCustomer( $connection, $payload, $item ),
			default              => SendResult::failure(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => self::LABEL,
						'field'  => $item->entityType->value,
						'reason' => __( 'This connector does not send this kind of item.', 'estasoft-erp-crm-sync-engine' ),
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
	 * Send a customer: the existing contact, or a new one.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $payload    Mapped payload.
	 * @param Item                $item       Item.
	 */
	private function sendCustomer( Connection $connection, array $payload, Item $item ): SendResult {
		$customer = HoldedOrderPayload::customer( $payload['customer'] ?? null );
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
		$api      = $this->client( $connection );
		$existing = $this->contacts->find( $api, $connection->id, $customer['email'] );
		if ( null !== $existing ) {
			return SendResult::existing( $this->contactRef( $existing ) );
		}
		$id = $this->contacts->create( $api, $connection->id, $customer );
		if ( $id instanceof ConnectorError ) {
			return SendResult::failure( $id );
		}
		return SendResult::created( new ExternalRef( $id, '' !== $customer['company'] ? $customer['company'] : $customer['name'] ) );
	}

	/**
	 * Find a contact by email.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$found = $this->contacts->find( $this->client( $connection ), $connection->id, $email );
		return null === $found ? null : $this->contactRef( $found );
	}

	/**
	 * Find a product by SKU.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$found = $this->catalog->productBySku( $this->client( $connection ), $connection->id, $sku );
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
		foreach ( $this->catalog->search( $this->client( $connection ), $connection->id, $query ) as $product ) {
			$refs[] = $this->productRef( $product );
		}
		return $refs;
	}

	/**
	 * Tax codes for the tax mapping screen: usual Spanish rates as suggestions. Holded v1 has no tax list, and any percentage can be typed.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 */
	public function listTaxCodes( Connection $connection ): array {
		unset( $connection );
		$codes = array();
		foreach ( HoldedCatalog::SUGGESTED_RATES as $rate ) {
			$codes[] = array(
				'code'         => $rate,
				'name'         => sprintf(
					/* translators: %s: tax rate in percent. */
					__( 'VAT %s%%', 'estasoft-erp-crm-sync-engine' ),
					$rate
				),
				'rate_percent' => $this->amounts->decimalString( (int) $this->amounts->basisPoints( $rate ), 2 ),
			);
		}
		return $codes;
	}

	/**
	 * A reference to a contact.
	 *
	 * @param array<string,mixed> $contact Contact as Holded reports it.
	 */
	private function contactRef( array $contact ): ExternalRef {
		return new ExternalRef(
			HoldedPayloadReader::text( $contact['id'] ?? '' ),
			HoldedPayloadReader::text( $contact['name'] ?? '' ),
			null,
			array( 'email' => strtolower( HoldedPayloadReader::text( $contact['email'] ?? '' ) ) )
		);
	}

	/**
	 * A reference to a product.
	 *
	 * @param array<string,mixed> $product Product as Holded reports it.
	 */
	private function productRef( array $product ): ExternalRef {
		return new ExternalRef(
			HoldedPayloadReader::text( $product['id'] ?? '' ),
			HoldedPayloadReader::text( $product['sku'] ?? '' ),
			null,
			array( 'name' => HoldedPayloadReader::text( $product['name'] ?? '' ) )
		);
	}
}
