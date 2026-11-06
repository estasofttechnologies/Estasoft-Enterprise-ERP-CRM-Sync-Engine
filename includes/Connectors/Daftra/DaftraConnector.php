<?php
/**
 * The Daftra connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
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
 * Sends orders as invoices, refunds as credit notes and customers as clients to Daftra (Egypt and the Gulf), and reads product stock back.
 * Connects with an API key (header `apikey`) to the account's own address. See docs/connectors/daftra.md for the objects, the flows and what
 * could not be verified.
 */
final class DaftraConnector extends AbstractApiConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'daftra';

	/**
	 * Name used in messages.
	 */
	public const LABEL = 'Daftra';

	/**
	 * Shared amount helper.
	 *
	 * @var DaftraAmounts
	 */
	private DaftraAmounts $amounts;

	/**
	 * Clients.
	 *
	 * @var DaftraClients
	 */
	private DaftraClients $clients;

	/**
	 * Taxes, products, warehouses.
	 *
	 * @var DaftraCatalog
	 */
	private DaftraCatalog $catalog;

	/**
	 * Order flow.
	 *
	 * @var DaftraOrderSender
	 */
	private DaftraOrderSender $orders;

	/**
	 * Refund flow.
	 *
	 * @var DaftraRefundSender
	 */
	private DaftraRefundSender $refunds;

	/**
	 * Stock reads.
	 *
	 * @var DaftraStockReader
	 */
	private DaftraStockReader $stock;

	/**
	 * Error mapping.
	 *
	 * @var DaftraErrorMapper
	 */
	private DaftraErrorMapper $errors;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function __construct( ConnectorDependencies $deps ) {
		parent::__construct( $deps );
		$pager         = new DaftraPager();
		$ids           = new DaftraIds();
		$this->amounts = new DaftraAmounts();
		$this->catalog = new DaftraCatalog( $pager, $deps->clock, $this->amounts );
		$this->clients = new DaftraClients( $pager );
		$docs          = new DaftraDocuments( $pager );
		$lines         = new DaftraLines( $this->amounts );
		$totals        = new DaftraTotals( $this->amounts );
		$this->orders  = new DaftraOrderSender( $docs, $this->clients, $this->catalog, $lines, $totals, $this->amounts, $ids, $deps->clock );
		$this->refunds = new DaftraRefundSender( $docs, $this->catalog, $lines, $totals, $this->amounts, $ids, $deps->clock );
		$this->stock   = new DaftraStockReader( $this->catalog );
		$this->errors  = new DaftraErrorMapper();
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
			regions: array( 'EG', 'SA', 'AE' ),
			rateLimit: new RateLimitHints( 1.0, 1 ),
			hosts: array( '*.daftara.com', '*.daftra.com' )
		);
	}

	/**
	 * The base address: the account's own host and the `api2` path.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the account name is not a plain name.
	 */
	protected function baseUrl( Connection $connection ): string {
		$options = DaftraOptions::fromConnection( $connection );
		if ( '' === $options->subdomain() ) {
			$error = new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => self::LABEL,
					'field'  => 'subdomain',
					'reason' => __( 'Enter only the name before .daftara.com, with letters, digits and hyphens.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				null,
				null,
				null,
				'bad account name'
			);
			throw new ApiException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return 'https://' . $options->subdomain() . '.' . $options->domain() . '/api2/';
	}

	/**
	 * `apikey: <key>`. The Authorization header is left off: Daftra answers an authentication error to an empty Bearer header [documented].
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new HeaderKeyAuth( 'apikey', 'api_key' );
	}

	/**
	 * Daftra's own error answers.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return $this->errors->map( $response );
	}

	/**
	 * The account name is not a secret but must not appear in messages either.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		return array_merge( parent::redactionValues( $connection ), array( DaftraOptions::fromConnection( $connection )->subdomain() ) );
	}

	/**
	 * The connect form: the account address, the key, then the advanced settings.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$advanced = static fn( string $name, string $label, string $hint, string $preset = '', string $type = 'text', array $options = array() ): CredentialField => new CredentialField( $name, $label, false, false, $hint, $preset, $type, $options, 'advanced' );
		return array(
			new CredentialField( 'subdomain', __( 'Account name', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, __( 'The part of your Daftra address before .daftara.com or .daftra.com.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::choice(
				'domain',
				__( 'Address ends with', 'estasoft-enterprise-erpcrm-sync-engine' ),
				array(
					'daftara.com' => 'daftara.com',
					'daftra.com'  => 'daftra.com',
				),
				'daftara.com',
				__( 'Daftra writes both. Use the one your account address ends with.', 'estasoft-enterprise-erpcrm-sync-engine' )
			),
			CredentialFields::apiKey( 'api_key', __( 'API key', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Copy it from Settings, API in Daftra. Saved encrypted. It is never shown again.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced( 'store_id', __( 'Warehouse number', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'The warehouse that documents are booked to. Empty: the account default.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced(
				'invoice_state',
				__( 'Invoices', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'Issued invoices get their official number. Drafts wait for a person to issue them in Daftra.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'issued',
				'select',
				array(
					'issued' => __( 'Issue after the total is checked', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'draft'  => __( 'Leave as draft', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
			$advanced(
				'record_payment',
				__( 'Record payments', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'Paid orders also create a payment in Daftra. Needs the payment method below.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'no',
				'select',
				array(
					'no'  => __( 'No', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'yes' => __( 'Yes', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
			$advanced( 'payment_method', __( 'Payment method', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'The key of an active payment method in Daftra, for example bank or cash.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			$advanced(
				'unknown_sku',
				__( 'A product Daftra does not know', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'Stop the order, or send the line as text without a product.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'refuse',
				'select',
				array(
					'refuse'    => __( 'Stop the order', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'free_text' => __( 'Send a text line', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
			$advanced(
				'link_credit_notes',
				__( 'Link credit notes to the invoice', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'Daftra says the link may need checking on your account. The credit note always names the order.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'no',
				'select',
				array(
					'no'  => __( 'No', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'yes' => __( 'Yes', 'estasoft-enterprise-erpcrm-sync-engine' ),
				)
			),
			$advanced(
				'total_mismatch',
				__( 'A total that differs from the shop', 'estasoft-enterprise-erpcrm-sync-engine' ),
				__( 'Stop before issuing, or issue and warn.', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
	 * Test connection: the business, its currency, its warehouses and taxes, and whether the subscription is running.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api     = $this->api( $connection );
		$options = DaftraOptions::fromConnection( $connection );
		$site    = DaftraRecord::fromAnswer( $api->get( 'site_info.json' )->json(), 'Site' )->data();
		$stores  = array();
		foreach ( $this->catalog->stores( $api ) as $store ) {
			$stores[] = array(
				'id'   => DaftraPayloadReader::text( $store['id'] ?? '' ),
				'name' => DaftraPayloadReader::text( $store['name'] ?? '' ),
			);
		}
		$taxes   = $this->catalog->taxes( $api, $connection->id );
		$expired = DaftraPayloadReader::flag( $site['is_expired'] ?? null, false );
		$name    = DaftraPayloadReader::text( $site['business_name'] ?? '' );
		$perms   = array(
			'read_account'        => true,
			'taxes_found'         => array() !== $taxes,
			'subscription_active' => ! $expired,
		);
		if ( $options->recordPayment() ) {
			$perms['payment_method'] = '' !== $options->paymentMethod();
		}
		$error = $expired
			? new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => self::LABEL,
					'field'  => 'subscription',
					'reason' => __( 'The Daftra subscription has expired.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				null,
				null,
				null,
				'expired'
			)
			: null;
		return new TestResult(
			null === $error,
			'API 2',
			'' !== $name ? $name : self::LABEL,
			$stores,
			$perms,
			$error,
			array(
				'taxes'    => count( $taxes ),
				'currency' => strtoupper( DaftraPayloadReader::text( $site['currency_code'] ?? '' ) ),
				'plan'     => DaftraPayloadReader::text( $site['package_id'] ?? '' ),
			)
		);
	}

	/**
	 * Cheap health probe: the one call that names the account.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->api( $connection )->get( 'site_info.json' );
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
		return ( new DaftraFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new DaftraDefaultMappings() )->rules( $mappingType );
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
	 * Send a customer: the existing client, or a new one.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	private function sendCustomer( Connection $connection, Item $item, array $payload ): SendResult {
		$customer = DaftraOrderPayload::customer( $payload['customer'] ?? null );
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
		$existing = $this->clients->find( $api, $customer['email'] );
		if ( null !== $existing ) {
			return SendResult::existing( $this->clientRef( $existing ) );
		}
		$id = $this->clients->create( $api, $customer, DaftraPayloadReader::text( $payload['currency'] ?? '' ) );
		if ( $id instanceof ConnectorError ) {
			return SendResult::failure( $id );
		}
		return SendResult::created( new ExternalRef( $id, '' !== $customer['company'] ? $customer['company'] : $customer['name'] ) );
	}

	/**
	 * Find a client by email.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$found = $this->clients->find( $this->api( $connection ), $email );
		return null === $found ? null : $this->clientRef( $found );
	}

	/**
	 * Find a product by SKU (its product code).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$found = $this->catalog->productBySku( $this->api( $connection ), $sku );
		return null === $found ? null : $this->productRef( $found );
	}

	/**
	 * Search products by name or code.
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
	 * Stock of products (one figure for all warehouses).
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouse ids; Daftra can only answer for all.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return $this->stock->fetch( $this->api( $connection ), $skus, $warehouses );
	}

	/**
	 * Tax codes for the tax mapping screen: the active taxes of the account.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		$codes = array();
		foreach ( $this->catalog->taxes( $this->api( $connection ), $connection->id ) as $tax ) {
			if ( ! $tax['active'] ) {
				continue;
			}
			$codes[] = array(
				'code'         => $tax['id'],
				'name'         => $tax['name'] . ( $tax['included'] ? ' (' . __( 'included in prices', 'estasoft-enterprise-erpcrm-sync-engine' ) . ')' : '' ),
				'rate_percent' => null === $tax['rate_bp'] ? '0' : $this->amounts->decimalString( $tax['rate_bp'], 2 ),
			);
		}
		return $codes;
	}

	/**
	 * A reference to a client.
	 *
	 * @param array<string,mixed> $client Client as Daftra reports it.
	 */
	private function clientRef( array $client ): ExternalRef {
		return new ExternalRef(
			DaftraPayloadReader::text( $client['id'] ?? '' ),
			DaftraPayloadReader::text( $client['business_name'] ?? '' ),
			null,
			array( 'email' => strtolower( DaftraPayloadReader::text( $client['email'] ?? '' ) ) )
		);
	}

	/**
	 * A reference to a product.
	 *
	 * @param array<string,mixed> $product Product as Daftra reports it.
	 */
	private function productRef( array $product ): ExternalRef {
		return new ExternalRef(
			DaftraPayloadReader::text( $product['id'] ?? '' ),
			DaftraPayloadReader::text( $product['product_code'] ?? '' ),
			null,
			array( 'name' => DaftraPayloadReader::text( $product['name'] ?? '' ) )
		);
	}
}
