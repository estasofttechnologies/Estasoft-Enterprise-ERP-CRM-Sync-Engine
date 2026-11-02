<?php
/**
 * The Lexware Office connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\LexwareOffice;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadSchema;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
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
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Sends WooCommerce orders to Lexware Office as invoices (draft by default), refunds as credit notes and customers as contacts, and reads the
 * invoice number and status back. See docs/connectors/lexware-office.md for the facts, their sources and what could not be verified. Errors
 * come out of the HTTP client as ApiException and are turned into ConnectorError values by GuardedConnector.
 */
final class LexwareOfficeConnector extends AbstractApiConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'lexware-office';

	/**
	 * Name shown to the person.
	 */
	public const LABEL = 'Lexware Office';

	/**
	 * Advanced settings.
	 */
	private const OPTIONS = array(
		'invoice_state'      => array(
			'default' => 'draft',
			'allowed' => array( 'draft', 'open' ),
		),
		'refund_state'       => array(
			'default' => 'follow',
			'allowed' => array( 'follow', 'draft', 'open' ),
		),
		'customer_policy'    => array(
			'default' => 'match_or_create',
			'allowed' => array( 'match_or_create', 'match_only' ),
		),
		'duplicate_customer' => array(
			'default' => 'oldest',
			'allowed' => array( 'oldest', 'fail' ),
		),
		'unknown_sku'        => array(
			'default' => 'refuse',
			'allowed' => array( 'refuse', 'custom' ),
		),
		'total_mismatch'     => array(
			'default' => 'fail',
			'allowed' => array( 'fail', 'warn' ),
		),
		'total_tolerance'    => array( 'default' => '' ),
		'shipping_type'      => array(
			'default' => 'delivery',
			'allowed' => array( 'delivery', 'service', 'none' ),
		),
		'unit_name'          => array( 'default' => 'Stück' ),
		'timezone'           => array( 'default' => 'Europe/Berlin' ),
	);

	/**
	 * Documents this instance made or found, so findExisting can answer without a search it cannot scope (see findExisting()).
	 *
	 * @var array<string,ExternalRef>
	 */
	private array $known = array();

	/**
	 * Identity, flags, sign-in style, regions, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: self::ID,
			label: self::LABEL,
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::InvoiceReadback ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array( 'DE' ),
			rateLimit: new RateLimitHints( 1.7, 1 ),
			hosts: array( 'api.lexware.io' )
		);
	}

	/**
	 * The API address.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return 'https://api.lexware.io/v1';
	}

	/**
	 * Bearer key.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new BearerAuth( 'api_key' );
	}

	/**
	 * The connect form: the key and the day it runs out.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			new CredentialField(
				'api_key',
				__( 'API key', 'estasoft-erp-crm-sync-engine' ),
				true,
				true,
				__( 'Create it at app.lexware.de/addons/public-api. Lexware shows it once.', 'estasoft-erp-crm-sync-engine' ),
				'',
				'password'
			),
			new CredentialField(
				'key_expires_on',
				__( 'Key valid until', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				__( 'YYYY-MM-DD. Lexware keys last at most 24 months. You get a warning before it ends.', 'estasoft-erp-crm-sync-engine' )
			),
		);
	}

	/**
	 * Lexware's 402 and 406 answers.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new LexwareErrorMapper() )->map( $response );
	}

	/**
	 * Is the key accepted: one cheap read, no search. A key past its end date answers no without a request.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		if ( $this->expiry( $connection ) instanceof ConnectorError ) {
			return false;
		}
		try {
			return $this->api( $connection )->get( 'profile' )->isSuccess();
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
		$expired = $this->expiry( $connection );
		if ( $expired instanceof ConnectorError ) {
			return new TestResult( false, '', '', array(), array(), $expired );
		}
		$api     = $this->api( $connection );
		$profile = $api->get( 'profile' )->json();
		$api->get( 'contacts', array( 'size' => 1 ) );
		$api->get( 'articles', array( 'size' => 1 ) );
		$api->get(
			'voucherlist',
			array(
				'voucherType'   => 'invoice',
				'voucherStatus' => 'draft,open,paid,paidoff,voided',
				'size'          => 1,
			)
		);
		$company  = PayloadReader::text( $profile['companyName'] ?? '' );
		$plan     = PayloadReader::text( $profile['subscriptionStatus'] ?? '' );
		$detected = array(
			'tax_type'       => PayloadReader::text( $profile['taxType'] ?? '' ),
			'small_business' => PayloadReader::flag( $profile['smallBusiness'] ?? null, false ),
		);
		$until    = $this->expiryDate( $connection );
		if ( null !== $until ) {
			$detected['credential_expires_at'] = $until->format( 'Y-m-d' ) . ' 23:59:59';
		}
		return new TestResult(
			true,
			'' !== $plan ? 'API v1 (' . $plan . ')' : 'API v1',
			'' !== $company ? $company : self::LABEL,
			array(),
			array(
				'read_contacts' => true,
				'read_articles' => true,
				'read_invoices' => true,
			),
			null,
			$detected
		);
	}

	/**
	 * Target fields for a mapping type.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return PayloadSchema::fields( $mappingType, array( 'title', 'introduction', 'remark' ) );
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
	 * Answer for an item the engine asks about before sending. Lexware has no reference field, so the duplicate search needs the customer and
	 * the date, which only the payload has; it runs inside send(). Here: the document the engine already linked is checked to still exist,
	 * a document this instance made or found is returned, and otherwise the key is verified and nothing is reported.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$api   = $this->api( $connection );
		$known = $this->known[ $this->key( $connection, $item ) ] ?? null;
		if ( null !== $known ) {
			return $known;
		}
		$path = EntityType::Refund === $item->entityType ? 'credit-notes' : 'invoices';
		if ( null !== $item->externalId && '' !== $item->externalId ) {
			$response = $api->get( $path . '/' . rawurlencode( $item->externalId ), array(), null, array( 'allow' => array( 404 ) ) );
			if ( $response->isSuccess() ) {
				return $this->documents()->ref( $response->json() + array( 'id' => $item->externalId ), $path, array() );
			}
			return null;
		}
		$api->get( 'profile' );
		return null;
	}

	/**
	 * Send an item.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$expired = $this->expiry( $connection );
		if ( $expired instanceof ConnectorError ) {
			return SendResult::failure( $expired );
		}
		$options = Options::fromConnection( $connection, self::OPTIONS );
		$api     = $this->api( $connection );
		switch ( $item->entityType ) {
			case EntityType::Order:
				$order = OrderPayload::parse( $payload, $item, self::LABEL );
				if ( $order instanceof ConnectorError ) {
					return SendResult::failure( $order );
				}
				$result = $this->documents()->sendOrder( $api, $item, $order, $options );
				break;
			case EntityType::Refund:
				$refund = RefundPayload::parse( $payload, self::LABEL );
				if ( $refund instanceof ConnectorError ) {
					return SendResult::failure( $refund );
				}
				$result = $this->refunds()->sendRefund( $api, $item, $refund, $options );
				break;
			case EntityType::Customer:
				$result = $this->sendCustomer( $api, $payload, $options );
				break;
			default:
				return SendResult::failure( Refusal::value( self::LABEL, 'entity', __( 'This connector does not send this kind of item.', 'estasoft-erp-crm-sync-engine' ), 'unsupported entity' ) );
		}
		if ( null !== $result->ref ) {
			$this->known[ $this->key( $connection, $item ) ] = $result->ref;
		}
		return $result;
	}

	/**
	 * Find a contact by email, as the mapping screens do.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$found = ( new LexwareContacts() )->findByEmail( $this->api( $connection ), $email );
		if ( array() === $found ) {
			return null;
		}
		$name = ( new LexwareContacts() )->nameOf( $found[0] );
		return new ExternalRef( (string) $found[0]['id'], $name, null, array( 'name' => $name ) );
	}

	/**
	 * Find an article by article number.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        Article number.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$article = ( new LexwareLines( new Amounts() ) )->article( $this->api( $connection ), trim( $sku ) );
		return null === $article ? null : $this->articleRef( $article );
	}

	/**
	 * Search articles by title or article number. The API filters by exact article number, GTIN and type only, so the list is read page by page
	 * (at most 20 pages of 250) and searched here.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$query = mb_strtolower( trim( $query ) );
		if ( '' === $query ) {
			return array();
		}
		$api   = $this->api( $connection );
		$found = array();
		for ( $page = 0; $page < 20; $page++ ) {
			$json = $api->get(
				'articles',
				array(
					'page' => $page,
					'size' => 250,
				)
			)->json();
			foreach ( (array) ( $json['content'] ?? array() ) as $article ) {
				if ( is_array( $article ) && str_contains( mb_strtolower( PayloadReader::text( $article['title'] ?? '' ) . ' ' . PayloadReader::text( $article['articleNumber'] ?? '' ) ), $query ) ) {
					$found[] = $this->articleRef( $article );
				}
			}
			if ( ! empty( $json['last'] ) || array() === (array) ( $json['content'] ?? array() ) || count( $found ) >= 20 ) {
				break;
			}
		}
		return $found;
	}

	/**
	 * Number and status of an invoice.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The synced order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		$response = $this->api( $connection )->get( 'invoices/' . rawurlencode( $ref->id ), array(), null, array( 'allow' => array( 404 ) ) );
		if ( ! $response->isSuccess() ) {
			return null;
		}
		$invoice = $response->json();
		$status  = PayloadReader::text( $invoice['voucherStatus'] ?? '' );
		return array(
			'number'           => PayloadReader::text( $invoice['voucherNumber'] ?? '' ),
			'status'           => $status,
			'authority_status' => '',
			'pdf_url'          => LexwareDocuments::APP . '/permalink/invoices/view/' . rawurlencode( $ref->id ),
			'qr'               => '',
			'issued_at'        => 'draft' === $status ? '' : PayloadReader::text( $invoice['voucherDate'] ?? '' ),
		);
	}

	/**
	 * Tax codes the tax mapping can use: the common German rates and the special types.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 */
	public function listTaxCodes( Connection $connection ): array {
		unset( $connection );
		$codes = array(
			array(
				'code'         => '19',
				'name'         => __( '19 % VAT', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '19',
			),
			array(
				'code'         => '7',
				'name'         => __( '7 % VAT', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '7',
			),
			array(
				'code'         => '0',
				'name'         => __( '0 % VAT (taxable at 0 %)', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => 'vatfree',
				'name'         => __( 'Tax-free', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => 'intraCommunitySupply',
				'name'         => __( 'Intra-community supply', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => 'constructionService13b',
				'name'         => __( 'Construction service, reverse charge (§ 13b)', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => 'externalService13b',
				'name'         => __( 'Service from abroad, reverse charge (§ 13b)', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => 'thirdPartyCountryService',
				'name'         => __( 'Service to a non-EU country', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => 'thirdPartyCountryDelivery',
				'name'         => __( 'Delivery to a non-EU country (export)', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => 'photovoltaicEquipment',
				'name'         => __( 'Photovoltaic equipment', 'estasoft-erp-crm-sync-engine' ),
				'rate_percent' => '0',
			),
		);
		return $codes;
	}

	/**
	 * Make a contact for a customer item (no document).
	 *
	 * @param ApiClient           $api     Client.
	 * @param array<string,mixed> $payload Mapped payload.
	 * @param Options             $options Settings.
	 */
	private function sendCustomer( ApiClient $api, array $payload, Options $options ): SendResult {
		$customer = OrderPayload::customer( $payload['customer'] ?? null );
		if ( '' === $customer['email'] ) {
			return SendResult::failure( Refusal::email( self::LABEL, '' ) );
		}
		$contacts = new LexwareContacts();
		$resolved = $contacts->resolve( $api, $customer, $options );
		if ( $resolved instanceof ConnectorError ) {
			return SendResult::failure( $resolved );
		}
		$ref = new ExternalRef( $resolved['id'], $customer['name'], null, array( 'name' => $customer['name'] ) );
		return $resolved['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The reference of an article.
	 *
	 * @param array<string,mixed> $article Article from the API.
	 */
	private function articleRef( array $article ): ExternalRef {
		return new ExternalRef(
			PayloadReader::text( $article['id'] ?? '' ),
			PayloadReader::text( $article['articleNumber'] ?? '' ),
			null,
			array(
				'name' => PayloadReader::text( $article['title'] ?? '' ),
				'type' => PayloadReader::text( $article['type'] ?? '' ),
			)
		);
	}

	/**
	 * The error for a key that is past its end date, or null.
	 *
	 * @param Connection $connection Connection.
	 */
	private function expiry( Connection $connection ): ?ConnectorError {
		$until = $this->expiryDate( $connection );
		if ( null === $until || $until->setTime( 23, 59, 59 ) >= $this->deps->clock->now() ) {
			return null;
		}
		return new ConnectorError(
			ErrorCode::Auth005,
			array(
				'system' => self::LABEL,
				'date'   => $until->format( 'Y-m-d' ),
			),
			null,
			null,
			null,
			'key expired'
		);
	}

	/**
	 * The day the key runs out, from the connection's config.
	 *
	 * @param Connection $connection Connection.
	 */
	private function expiryDate( Connection $connection ): ?\DateTimeImmutable {
		$text = PayloadReader::text( $connection->config( 'key_expires_on' ) );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $text, new \DateTimeZone( 'UTC' ) );
		return false === $date ? null : $date;
	}

	/**
	 * Key of the in-memory shortcut.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	private function key( Connection $connection, Item $item ): string {
		return $connection->id . '|' . $item->entityType->value . '|' . $item->clientReference();
	}

	/**
	 * The document helpers.
	 */
	private function documents(): LexwareDocuments {
		$amounts = new Amounts();
		return new LexwareDocuments( new LexwareContacts(), new LexwareLines( $amounts ), new LexwareSearch(), $amounts );
	}

	/**
	 * The refund helpers.
	 */
	private function refunds(): LexwareRefunds {
		$amounts = new Amounts();
		return new LexwareRefunds( new LexwareContacts(), new LexwareSearch(), $this->documents(), new LexwareLines( $amounts ), $amounts );
	}
}
