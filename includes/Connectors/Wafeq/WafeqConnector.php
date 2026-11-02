<?php
/**
 * The Wafeq connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

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
 * Sends orders as invoices, refunds as credit notes and customers as contacts to Wafeq (UAE and Saudi Arabia). Connects with an API key
 * (header `Authorization: Api-Key <key>`). See docs/connectors/wafeq.md for the objects, the flows and what could not be verified.
 */
final class WafeqConnector extends AbstractApiConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'wafeq';

	/**
	 * Name used in messages.
	 */
	public const LABEL = 'Wafeq';

	/**
	 * Base address of the API.
	 */
	public const BASE_URL = 'https://api.wafeq.com/v1/';

	/**
	 * Shared amount helper.
	 *
	 * @var WafeqAmounts
	 */
	private WafeqAmounts $amounts;

	/**
	 * Shared reference helper.
	 *
	 * @var WafeqIds
	 */
	private WafeqIds $ids;

	/**
	 * Contacts.
	 *
	 * @var WafeqContacts
	 */
	private WafeqContacts $contacts;

	/**
	 * Tax rates, accounts, items.
	 *
	 * @var WafeqCatalog
	 */
	private WafeqCatalog $catalog;

	/**
	 * Order flow.
	 *
	 * @var WafeqOrderSender
	 */
	private WafeqOrderSender $orders;

	/**
	 * Refund flow.
	 *
	 * @var WafeqRefundSender
	 */
	private WafeqRefundSender $refunds;

	/**
	 * Error mapping.
	 *
	 * @var WafeqErrorMapper
	 */
	private WafeqErrorMapper $errors;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function __construct( ConnectorDependencies $deps ) {
		parent::__construct( $deps );
		$pager          = new WafeqPager();
		$this->amounts  = new WafeqAmounts();
		$this->ids      = new WafeqIds();
		$this->catalog  = new WafeqCatalog( $pager, $deps->clock, $this->amounts );
		$this->contacts = new WafeqContacts( $pager, $this->ids );
		$docs           = new WafeqDocuments( $pager );
		$lines          = new WafeqLines( $this->amounts );
		$totals         = new WafeqTotals( $this->amounts );
		$this->orders   = new WafeqOrderSender( $docs, $this->contacts, $this->catalog, $lines, $totals, $this->amounts, $this->ids, $deps->clock );
		$this->refunds  = new WafeqRefundSender( $docs, $this->catalog, $lines, $totals, $this->amounts, $this->ids, $deps->clock );
		$this->errors   = new WafeqErrorMapper();
	}

	/**
	 * Identity, flags, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: self::ID,
			label: self::LABEL,
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array( 'AE', 'SA' ),
			rateLimit: new RateLimitHints( 1.0, 1, 60 ),
			hosts: array( 'api.wafeq.com' )
		);
	}

	/**
	 * The base address.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return self::BASE_URL;
	}

	/**
	 * `Authorization: Api-Key <key>`.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new HeaderKeyAuth( 'Authorization', 'api_key', 'Api-Key ' );
	}

	/**
	 * The header that carries the idempotency key.
	 */
	protected function idempotencyHeader(): string {
		return 'X-Wafeq-Idempotency-Key';
	}

	/**
	 * Wafeq's own error answers.
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
			CredentialFields::apiKey( 'api_key', __( 'API key', 'estasoft-erp-crm-sync-engine' ), __( 'Make it under Developer, API Keys in Wafeq, then press Enable API. Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'sales_account', __( 'Sales account', 'estasoft-erp-crm-sync-engine' ), __( 'The revenue account (its ID, code or exact name) that invoice lines are booked to. Empty: the only revenue account.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'invoice_state',
				__( 'Invoices', 'estasoft-erp-crm-sync-engine' ),
				__( 'Finalized invoices are posted. Drafts wait for a person to finalize them in Wafeq.', 'estasoft-erp-crm-sync-engine' ),
				'finalized',
				'select',
				array(
					'finalized' => __( 'Finalize after the total is checked', 'estasoft-erp-crm-sync-engine' ),
					'draft'     => __( 'Leave as draft', 'estasoft-erp-crm-sync-engine' ),
				)
			),
			$advanced(
				'record_payment',
				__( 'Record payments', 'estasoft-erp-crm-sync-engine' ),
				__( 'Paid orders also create a payment in Wafeq. Needs the payment account below.', 'estasoft-erp-crm-sync-engine' ),
				'no',
				'select',
				array(
					'no'  => __( 'No', 'estasoft-erp-crm-sync-engine' ),
					'yes' => __( 'Yes', 'estasoft-erp-crm-sync-engine' ),
				)
			),
			$advanced( 'payment_account', __( 'Payment account', 'estasoft-erp-crm-sync-engine' ), __( 'The bank or cash account (ID, code or exact name) that receives the money.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'unknown_sku',
				__( 'A product Wafeq does not know', 'estasoft-erp-crm-sync-engine' ),
				__( 'Stop the order, or send the line as text without an item.', 'estasoft-erp-crm-sync-engine' ),
				'refuse',
				'select',
				array(
					'refuse'    => __( 'Stop the order', 'estasoft-erp-crm-sync-engine' ),
					'free_text' => __( 'Send a text line', 'estasoft-erp-crm-sync-engine' ),
				)
			),
			$advanced( 'invoice_number_prefix', __( 'Invoice number prefix', 'estasoft-erp-crm-sync-engine' ), __( 'Put before the order number to make the invoice number.', 'estasoft-erp-crm-sync-engine' ), 'WC-' ),
			$advanced(
				'place_of_supply',
				__( 'Place of supply (UAE only)', 'estasoft-erp-crm-sync-engine' ),
				__( 'UAE organisations must name the emirate of supply.', 'estasoft-erp-crm-sync-engine' ),
				'',
				'select',
				array(
					''               => __( 'Not needed', 'estasoft-erp-crm-sync-engine' ),
					'ABU_DHABI'      => 'Abu Dhabi',
					'AJMAN'          => 'Ajman',
					'DUBAI'          => 'Dubai',
					'FUJAIRAH'       => 'Fujairah',
					'RAS_AL_KHAIMAH' => 'Ras Al Khaimah',
					'SHARJAH'        => 'Sharjah',
					'UMM_AL_QUWAIN'  => 'Umm Al Quwain',
					'OUTSIDE_UAE'    => __( 'Outside the UAE', 'estasoft-erp-crm-sync-engine' ),
				)
			),
			$advanced(
				'language',
				__( 'Document language', 'estasoft-erp-crm-sync-engine' ),
				'',
				'en',
				'select',
				array(
					'en' => 'English',
					'ar' => 'العربية',
				)
			),
			$advanced(
				'total_mismatch',
				__( 'A total that differs from the shop', 'estasoft-erp-crm-sync-engine' ),
				__( 'Stop before finalizing, or finalize and warn.', 'estasoft-erp-crm-sync-engine' ),
				'fail',
				'select',
				array(
					'fail' => __( 'Stop the invoice', 'estasoft-erp-crm-sync-engine' ),
					'warn' => __( 'Warn and go on', 'estasoft-erp-crm-sync-engine' ),
				)
			),
		);
	}

	/**
	 * Test connection: the organisation, the tax rates, and whether the accounts named in the settings exist.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api          = $this->api( $connection );
		$organisation = $api->get( 'organization/' )->json();
		$name         = WafeqPayloadReader::text( $organisation['name'] ?? ( $organisation['legal_name'] ?? '' ) );
		$base         = strtoupper( WafeqPayloadReader::text( $organisation['base_currency'] ?? ( $organisation['currency'] ?? '' ) ) );
		$options      = WafeqOptions::fromConnection( $connection );
		$rates        = $this->catalog->taxRates( $api, $connection->id );
		$sales        = $this->catalog->salesAccountId( $api, $connection->id, $options->salesAccount() );
		$permissions  = array(
			'read_organisation' => true,
			'tax_rates_found'   => array() !== $rates,
			'sales_account'     => ! $sales instanceof ConnectorError,
		);
		if ( $options->recordPayment() ) {
			$permissions['payment_account'] = ! $this->catalog->paymentAccountId( $api, $connection->id, $options->paymentAccount() ) instanceof ConnectorError;
		}
		$detected = array(
			'tax_rates' => count( $rates ),
		);
		if ( '' !== $base ) {
			$detected['base_currency'] = $base;
		}
		$error = $sales instanceof ConnectorError ? $sales : null;
		return new TestResult( null === $error, 'API v1', $name, array(), $permissions, $error, $detected );
	}

	/**
	 * Fields for the mapping screens.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ( new WafeqFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new WafeqDefaultMappings() )->rules( $mappingType );
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
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	private function sendCustomer( Connection $connection, Item $item, array $payload ): SendResult {
		$customer = WafeqOrderPayload::customer( $payload['customer'] ?? null );
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
		$id = $this->contacts->create( $api, $item, $customer );
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
	 * Find an item by SKU.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$found = $this->catalog->itemBySku( $this->api( $connection ), $connection->id, $sku );
		return null === $found ? null : $this->itemRef( $found );
	}

	/**
	 * Search items by name or SKU.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$refs = array();
		foreach ( $this->catalog->searchItems( $this->api( $connection ), $connection->id, $query ) as $item ) {
			$refs[] = $this->itemRef( $item );
		}
		return $refs;
	}

	/**
	 * Tax codes for the tax mapping screen: the sales tax rates of the organisation.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		$codes = array();
		foreach ( $this->catalog->taxRates( $this->api( $connection ), $connection->id ) as $rate ) {
			if ( '' !== $rate['type'] && 'SALES' !== $rate['type'] ) {
				continue;
			}
			$codes[] = array(
				'code'         => $rate['id'],
				'name'         => null === $rate['rate_bp'] ? $rate['name'] . ' (' . __( 'rate unknown', 'estasoft-erp-crm-sync-engine' ) . ')' : $rate['name'],
				'rate_percent' => null === $rate['rate_bp'] ? '0' : $this->amounts->decimalString( $rate['rate_bp'], 2 ),
			);
		}
		return $codes;
	}

	/**
	 * A reference to a contact.
	 *
	 * @param array<string,mixed> $contact Contact as Wafeq reports it.
	 */
	private function contactRef( array $contact ): ExternalRef {
		return new ExternalRef(
			WafeqPayloadReader::text( $contact['id'] ?? '' ),
			WafeqPayloadReader::text( $contact['name'] ?? '' ),
			null,
			array( 'email' => strtolower( WafeqPayloadReader::text( $contact['email'] ?? '' ) ) )
		);
	}

	/**
	 * A reference to an item.
	 *
	 * @param array<string,mixed> $item Item as Wafeq reports it.
	 */
	private function itemRef( array $item ): ExternalRef {
		return new ExternalRef(
			WafeqPayloadReader::text( $item['id'] ?? '' ),
			WafeqPayloadReader::text( $item['sku'] ?? '' ),
			null,
			array( 'name' => WafeqPayloadReader::text( $item['name'] ?? '' ) )
		);
	}
}
