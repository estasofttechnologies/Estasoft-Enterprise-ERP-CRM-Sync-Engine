<?php
/**
 * The Moneybird connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Moneybird;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadSchema;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\BearerAuth;
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
use Estasoft\SyncEngine\Domain\Item;

/**
 * Sends WooCommerce orders to Moneybird as sales invoices (draft by default), refunds of a whole order as credit invoices and customers as contacts, and
 * reads the invoice number and state back. See docs/connectors/moneybird.md for the facts, their sources and what could not be verified.
 */
final class MoneybirdConnector extends AbstractApiConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'moneybird';

	/**
	 * Name shown to the person.
	 */
	public const LABEL = 'Moneybird';

	/**
	 * Address of the account-level calls (outside any administration).
	 */
	private const ROOT = 'https://moneybird.com/api/v2';

	/**
	 * Advanced settings.
	 */
	private const OPTIONS = array(
		'invoice_state'           => array(
			'default' => 'draft',
			'allowed' => array( 'draft', 'sent' ),
		),
		'refund_state'            => array(
			'default' => 'follow',
			'allowed' => array( 'follow', 'draft', 'sent' ),
		),
		'customer_policy'         => array(
			'default' => 'match_or_create',
			'allowed' => array( 'match_or_create', 'match_only', 'always_create' ),
		),
		'duplicate_customer'      => array(
			'default' => 'oldest',
			'allowed' => array( 'oldest', 'fail' ),
		),
		'unknown_sku'             => array(
			'default' => 'refuse',
			'allowed' => array( 'refuse', 'custom' ),
		),
		'total_mismatch'          => array(
			'default' => 'fail',
			'allowed' => array( 'fail', 'warn' ),
		),
		'total_tolerance'         => array( 'default' => '' ),
		'sales_ledger_account'    => array( 'default' => '' ),
		'shipping_ledger_account' => array( 'default' => '' ),
		'workflow_id'             => array( 'default' => '' ),
		'document_style_id'       => array( 'default' => '' ),
		'payment_account'         => array( 'default' => '' ),
		'timezone'                => array( 'default' => 'Europe/Amsterdam' ),
	);

	/**
	 * Identity, flags, sign-in style, regions, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: self::ID,
			label: self::LABEL,
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::InvoiceReadback, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array( 'NL' ),
			rateLimit: new RateLimitHints( 0.4, 1, 24 ),
			hosts: array( 'moneybird.com' )
		);
	}

	/**
	 * The API address of the administration. The administration id is part of every path, so no call can reach another administration.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the id is not digits only.
	 */
	protected function baseUrl( Connection $connection ): string {
		$id = trim( (string) $connection->config( 'administration_id', '' ) );
		if ( 1 !== preg_match( '/^\d{1,30}$/', $id ) ) {
			$error = Refusal::value( self::LABEL, 'administration_id', __( 'The administration number must be digits only.', 'estasoft-erp-crm-sync-engine' ), 'invalid administration id' );
			throw new ApiException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return self::ROOT . '/' . $id;
	}

	/**
	 * Bearer token.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new BearerAuth( 'api_token' );
	}

	/**
	 * The connect form: the administration number and the token.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			new CredentialField(
				'administration_id',
				__( 'Administration number', 'estasoft-erp-crm-sync-engine' ),
				false,
				true,
				__( 'The digits in the address of your administration in Moneybird.', 'estasoft-erp-crm-sync-engine' )
			),
			new CredentialField(
				'api_token',
				__( 'API token', 'estasoft-erp-crm-sync-engine' ),
				true,
				true,
				__( 'A personal token with the scopes sales_invoices and settings.', 'estasoft-erp-crm-sync-engine' ),
				'',
				'password'
			),
		);
	}

	/**
	 * Values removed from every message besides the secrets: the administration number.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$id = trim( (string) $connection->config( 'administration_id', '' ) );
		return '' === $id ? array() : array( $id );
	}

	/**
	 * Moneybird's 402, 403, 400 and 422 answers.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new MoneybirdErrorMapper() )->map( $response );
	}

	/**
	 * Are the token and the administration accepted: one cheap read inside the administration (a token that cannot reach it gets 404).
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->api( $connection )->get( 'workflows.json' )->isSuccess();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Test connection.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$api   = $this->api( $connection );
		$id    = trim( (string) $connection->config( 'administration_id', '' ) );
		$found = null;
		foreach ( (array) $api->get( self::ROOT . '/administrations.json' )->json() as $administration ) {
			if ( is_array( $administration ) && PayloadReader::text( $administration['id'] ?? '' ) === $id ) {
				$found = $administration;
			}
		}
		if ( null === $found ) {
			return new TestResult(
				false,
				'',
				'',
				array(),
				array(),
				Refusal::value( self::LABEL, 'administration_id', __( 'The token cannot reach this administration. Check the number and the token.', 'estasoft-erp-crm-sync-engine' ), 'administration not reachable' )
			);
		}
		$rates = $this->lines()->taxRates( $api );
		$api->get( 'contacts.json', array( 'per_page' => 1 ) );
		$api->get( 'workflows.json' );
		return new TestResult(
			true,
			'API v2',
			'' !== PayloadReader::text( $found['name'] ?? '' ) ? PayloadReader::text( $found['name'] ) : self::LABEL,
			array(),
			array(
				'read_contacts'  => true,
				'read_tax_rates' => array() !== $rates,
				'read_workflows' => true,
			),
			null,
			array(
				'currency'  => PayloadReader::text( $found['currency'] ?? '' ),
				'country'   => PayloadReader::text( $found['country'] ?? '' ),
				'time_zone' => PayloadReader::text( $found['time_zone'] ?? '' ),
			)
		);
	}

	/**
	 * Target fields for a mapping type.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return PayloadSchema::fields( $mappingType );
	}

	/**
	 * Starting rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return PayloadSchema::rules( $mappingType );
	}

	/**
	 * Look up the invoice or credit invoice by its reference, and report it only when this connector's own send() has finished it.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$options = Options::fromConnection( $connection, self::OPTIONS );
		$refund  = EntityType::Refund === $item->entityType;
		$invoice = $this->documents()->findByReference( $this->api( $connection ), $item->clientReference(), $refund );
		if ( null === $invoice ) {
			return null;
		}
		$state    = (string) ( $invoice['state'] ?? '' );
		$wantSent = $refund ? ( 'sent' === $options->choice( 'refund_state' ) || ( 'follow' === $options->choice( 'refund_state' ) && $options->is( 'invoice_state', 'sent' ) ) ) : $options->is( 'invoice_state', 'sent' );
		if ( ( $wantSent && 'draft' === $state ) || ( ! $refund && '' !== $options->text( 'payment_account' ) && 'paid' !== $state ) ) {
			return null;
		}
		return $this->documents()->ref( $invoice, array() );
	}

	/**
	 * Send an item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$options = Options::fromConnection( $connection, self::OPTIONS );
		$api     = $this->api( $connection );
		switch ( $item->entityType ) {
			case EntityType::Order:
				$order = OrderPayload::parse( $payload, $item, self::LABEL );
				if ( $order instanceof ConnectorError ) {
					return SendResult::failure( $order );
				}
				return $this->documents()->sendOrder( $api, $item, $order, $options );
			case EntityType::Refund:
				$refund = RefundPayload::parse( $payload, self::LABEL );
				if ( $refund instanceof ConnectorError ) {
					return SendResult::failure( $refund );
				}
				return ( new MoneybirdRefunds( $this->documents(), new Amounts() ) )->sendRefund( $api, $item, $refund, $options );
			case EntityType::Customer:
				return $this->sendCustomer( $api, $payload, $options, $item );
			default:
				return SendResult::failure( Refusal::value( self::LABEL, 'entity', __( 'This connector does not send this kind of item.', 'estasoft-erp-crm-sync-engine' ), 'unsupported entity' ) );
		}
	}

	/**
	 * Find a contact by email.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$contacts = new MoneybirdContacts();
		$found    = $contacts->findByEmail( $this->api( $connection ), $email );
		if ( array() === $found ) {
			return null;
		}
		$name = $contacts->nameOf( $found[0] );
		return new ExternalRef( (string) $found[0]['id'], $name, null, array( 'name' => $name ) );
	}

	/**
	 * Find a product by identifier.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        Identifier.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$product = $this->lines()->product( $this->api( $connection ), trim( $sku ) );
		return null === $product ? null : $this->productRef( $product );
	}

	/**
	 * Search products by description, title or identifier (Moneybird's free-text search).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$rows  = $this->api( $connection )->get(
			'products.json',
			array(
				'query'    => $query,
				'per_page' => 20,
			)
		)->json();
		$found = array();
		foreach ( \Estasoft\SyncEngine\Core\Support\Lists::isList( $rows ) ? $rows : array() as $product ) {
			if ( is_array( $product ) ) {
				$found[] = $this->productRef( $product );
			}
		}
		return $found;
	}

	/**
	 * Number and state of an invoice.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The synced order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		$invoice = $this->documents()->read( $this->api( $connection ), $ref->id );
		if ( null === $invoice ) {
			return null;
		}
		return array(
			'number'           => PayloadReader::text( $invoice['invoice_id'] ?? '' ),
			'status'           => PayloadReader::text( $invoice['state'] ?? '' ),
			'authority_status' => '',
			'pdf_url'          => PayloadReader::text( $invoice['url'] ?? '' ),
			'qr'               => '',
			'issued_at'        => PayloadReader::text( $invoice['sent_at'] ?? '' ),
		);
	}

	/**
	 * The active sales tax rates; the code is the tax rate id.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		$api   = $this->api( $connection );
		$amts  = new Amounts();
		$codes = array();
		for ( $page = 1; $page <= 10; $page++ ) {
			$rows = $api->get(
				'tax_rates.json',
				array(
					'filter'   => 'tax_rate_type:sales_invoice,active:true',
					'page'     => $page,
					'per_page' => 100,
				)
			)->json();
			$rows = \Estasoft\SyncEngine\Core\Support\Lists::isList( $rows ) ? $rows : array();
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && '' !== PayloadReader::text( $row['id'] ?? '' ) ) {
					$codes[] = array(
						'code'         => PayloadReader::text( $row['id'] ),
						'name'         => PayloadReader::text( $row['name'] ?? $row['id'] ),
						'rate_percent' => $amts->percent( (int) $amts->basisPoints( $row['percentage'] ?? 0 ) ),
					);
				}
			}
			if ( count( $rows ) < 100 ) {
				break;
			}
		}
		return $codes;
	}

	/**
	 * Make a contact for a customer item (no document).
	 *
	 * @param ApiClient           $api     Client.
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Options             $options Settings.
	 * @param Item                $item    Item.
	 */
	private function sendCustomer( ApiClient $api, array $payload, Options $options, Item $item ): SendResult {
		$customer = OrderPayload::customer( $payload['customer'] ?? null );
		if ( '' === $customer['email'] ) {
			return SendResult::failure( Refusal::email( self::LABEL, $item->entityId ) );
		}
		$resolved = ( new MoneybirdContacts() )->resolve( $api, $customer, $options );
		if ( $resolved instanceof ConnectorError ) {
			return SendResult::failure( $resolved );
		}
		$ref = new ExternalRef( $resolved['id'], $customer['name'], null, array( 'name' => $customer['name'] ) );
		return $resolved['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The reference of a product.
	 *
	 * @param array<string,mixed> $product Product from the API.
	 */
	private function productRef( array $product ): ExternalRef {
		return new ExternalRef(
			PayloadReader::text( $product['id'] ?? '' ),
			PayloadReader::text( $product['identifier'] ?? '' ),
			null,
			array( 'name' => PayloadReader::text( $product['description'] ?? '' ) )
		);
	}

	/**
	 * Line helper.
	 */
	private function lines(): MoneybirdLines {
		return new MoneybirdLines( new Amounts() );
	}

	/**
	 * Order flow helper.
	 */
	private function documents(): MoneybirdDocuments {
		return new MoneybirdDocuments( new MoneybirdContacts(), $this->lines(), new Amounts() );
	}
}
