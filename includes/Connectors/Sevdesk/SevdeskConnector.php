<?php
/**
 * The sevDesk connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

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
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\HeaderKeyAuth;
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
 * Sends WooCommerce orders to sevDesk as invoices (draft by default), refunds as credit notes (sevDesk-Update 2.0) and customers as contacts, reads
 * the stock of parts, the invoice number and status and a night's list of invoices. See docs/connectors/sevdesk.md for the facts, their sources and
 * what could not be verified: the official reference could not be fetched, so request shapes come from third-party mirrors of it.
 */
final class SevdeskConnector extends AbstractApiConnector {

	/**
	 * Connector id.
	 */
	public const ID = 'sevdesk';

	/**
	 * Name shown to the person.
	 */
	public const LABEL = 'sevDesk';

	/**
	 * Advanced settings.
	 */
	private const OPTIONS = array(
		'invoice_state'          => array(
			'default' => 'draft',
			'allowed' => array( 'draft', 'open' ),
		),
		'refund_state'           => array(
			'default' => 'follow',
			'allowed' => array( 'follow', 'draft', 'open' ),
		),
		'customer_policy'        => array(
			'default' => 'match_or_create',
			'allowed' => array( 'match_or_create', 'match_only', 'always_create', 'collective' ),
		),
		'collective_contact_id'  => array( 'default' => '' ),
		'customer_number_prefix' => array( 'default' => 'WC-' ),
		'contact_category_id'    => array(
			'default' => 3,
			'min'     => 1,
			'max'     => 99999,
		),
		'communication_key_id'   => array(
			'default' => 2,
			'min'     => 1,
			'max'     => 99999,
		),
		'unknown_sku'            => array(
			'default' => 'refuse',
			'allowed' => array( 'refuse', 'custom' ),
		),
		'total_mismatch'         => array(
			'default' => 'fail',
			'allowed' => array( 'fail', 'warn' ),
		),
		'total_tolerance'        => array( 'default' => '' ),
		'payment_date_format'    => array(
			'default' => 'ymd',
			'allowed' => array( 'ymd', 'unix' ),
		),
		'unity_id'               => array(
			'default' => 1,
			'min'     => 1,
			'max'     => 99999,
		),
		'time_to_pay'            => array(
			'default' => 14,
			'min'     => 0,
			'max'     => 365,
		),
		'payment_account'        => array( 'default' => '' ),
		'small_settlement'       => array(
			'default' => 'no',
			'allowed' => array( 'no', 'yes' ),
		),
		'timezone'               => array( 'default' => 'Europe/Berlin' ),
	);

	/**
	 * Documents this instance made or found (see findExisting()).
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
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::StockIn, Capability::InvoiceReadback, Capability::Reconciliation ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array( 'DE' ),
			rateLimit: new RateLimitHints( 2.0, 1 ),
			hosts: array( 'my.sevdesk.de' )
		);
	}

	/**
	 * The API address.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return 'https://my.sevdesk.de/api/v1';
	}

	/**
	 * The token in the Authorization header, without a prefix [third-party]. It is never put in the address (sevDesk removed that on 29 April 2025).
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new HeaderKeyAuth( 'Authorization', 'api_token' );
	}

	/**
	 * The connect form: the token.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			new CredentialField(
				'api_token',
				__( 'API token', 'estasoft-enterprise-erpcrm-sync-engine' ),
				true,
				true,
				__( 'In sevDesk: Settings, Users, your user, API token.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'',
				'password'
			),
		);
	}

	/**
	 * A 400-class problem that arrives as HTTP 500.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new SevdeskErrorMapper() )->map( $response );
	}

	/**
	 * Is the token accepted: one cheap read of the bookkeeping version, nothing else.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->api( $connection )->get( 'Tools/bookkeepingSystemVersion' )->isSuccess();
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
		$api      = $this->api( $connection );
		$options  = Options::fromConnection( $connection, self::OPTIONS );
		$version  = $this->reference()->askVersion( $api );
		$client   = SevdeskJson::first( $api->get( 'SevClient' )->json() );
		$userId   = $this->reference()->userId( $api );
		$units    = SevdeskJson::rows( $api->get( 'Unity' )->json() );
		$accounts = SevdeskJson::rows( $api->get( 'CheckAccount' )->json() );
		$api->get( 'Contact', array( 'limit' => 1 ) );
		$unitFound = array() === $units;
		foreach ( $units as $unit ) {
			if ( (string) ( $unit['id'] ?? '' ) === (string) $options->int( 'unity_id' ) ) {
				$unitFound = true;
			}
		}
		$banks = array();
		foreach ( array_slice( $accounts, 0, 20 ) as $account ) {
			$banks[] = array(
				'id'   => (string) ( $account['id'] ?? '' ),
				'name' => PayloadReader::text( $account['name'] ?? '' ),
			);
		}
		$name = null === $client ? '' : PayloadReader::text( $client['name'] ?? '' );
		return new TestResult(
			true,
			'sevDesk-Update ' . $version,
			'' !== $name ? $name : self::LABEL,
			array(),
			array(
				'read_contacts' => true,
				'read_invoices' => true,
				'has_user'      => '' !== $userId,
			),
			null,
			array(
				'bookkeeping_version' => $version,
				'unity_found'         => $unitFound,
				'bank_accounts'       => $banks,
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
		return PayloadSchema::fields( $mappingType, array( 'header', 'introduction', 'remark' ), true );
	}

	/**
	 * Starting rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return PayloadSchema::rules( $mappingType, array(), true );
	}

	/**
	 * Answer for an item the engine asks about before sending. sevDesk cannot search by reference without the customer and the date, which only the
	 * payload has; that search runs inside send(). Here: the document the engine already linked is checked to still exist, a document this
	 * instance made or found is returned, and otherwise the token is verified and nothing is reported.
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
		if ( null !== $item->externalId && '' !== $item->externalId ) {
			$refund   = EntityType::Refund === $item->entityType;
			$document = $refund ? $this->refunds()->readCredit( $api, $item->externalId ) : $this->documents()->read( $api, $item->externalId );
			return null === $document ? null : $this->documents()->ref( $document + array( 'id' => $item->externalId ), array() );
		}
		$api->get( 'Tools/bookkeepingSystemVersion' );
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
		$options = Options::fromConnection( $connection, self::OPTIONS );
		$api     = $this->api( $connection );
		switch ( $item->entityType ) {
			case EntityType::Order:
				$order = OrderPayload::parse( $payload, $item, self::LABEL );
				if ( $order instanceof ConnectorError ) {
					return SendResult::failure( $order );
				}
				$result = $this->documents()->sendOrder( $api, $connection, $item, $order, $options );
				break;
			case EntityType::Refund:
				$refund = RefundPayload::parse( $payload, self::LABEL );
				if ( $refund instanceof ConnectorError ) {
					return SendResult::failure( $refund );
				}
				$result = $this->refunds()->sendRefund( $api, $connection, $item, $refund, $options );
				break;
			case EntityType::Customer:
				$result = $this->sendCustomer( $api, $payload, $options, $item );
				break;
			default:
				return SendResult::failure( Refusal::value( self::LABEL, 'entity', __( 'This connector does not send this kind of item.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'unsupported entity' ) );
		}
		if ( null !== $result->ref ) {
			$this->known[ $this->key( $connection, $item ) ] = $result->ref;
		}
		return $result;
	}

	/**
	 * Find the contact this connector made for an email.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$options  = Options::fromConnection( $connection, self::OPTIONS );
		$contacts = $this->contacts();
		$contact  = $contacts->find( $this->api( $connection ), $contacts->numberFor( $email, $options ) );
		if ( null === $contact ) {
			return null;
		}
		$name = '' !== PayloadReader::text( $contact['name'] ?? '' ) ? PayloadReader::text( $contact['name'] ) : trim( PayloadReader::text( $contact['surename'] ?? '' ) . ' ' . PayloadReader::text( $contact['familyname'] ?? '' ) );
		return new ExternalRef(
			(string) $contact['id'],
			$name,
			null,
			array(
				'name'            => $name,
				'customer_number' => (string) ( $contact['customerNumber'] ?? '' ),
			)
		);
	}

	/**
	 * Find a part by part number.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        Part number.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$part = $this->lines()->part( $this->api( $connection ), trim( $sku ) );
		return null === $part ? null : $this->partRef( $part );
	}

	/**
	 * Search parts by name (sevDesk matches the name filter itself).
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
		$found = array();
		foreach ( SevdeskJson::rows(
			$this->api( $connection )->get(
				'Part',
				array(
					'name'  => $query,
					'limit' => 20,
				)
			)->json()
		) as $part ) {
			$found[] = $this->partRef( $part );
		}
		return $found;
	}

	/**
	 * Total stock by SKU.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Ignored: sevDesk has one stock figure per part.
	 * @return array<string,array{quantity:int,held:int}>
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		unset( $warehouses );
		return ( new SevdeskReader() )->stock( $this->api( $connection ), $this->lines(), $skus );
	}

	/**
	 * Number and status of an invoice.
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
		$status = match ( (int) ( $invoice['status'] ?? 0 ) ) {
			SevdeskDocuments::DRAFT => 'draft',
			SevdeskDocuments::OPEN  => 'open',
			750                     => 'partially_paid',
			SevdeskDocuments::PAID  => 'paid',
			default                 => 'other',
		};
		return array(
			'number'           => PayloadReader::text( $invoice['invoiceNumber'] ?? '' ),
			'status'           => $status,
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => PayloadReader::text( $invoice['sendDate'] ?? '' ),
		);
	}

	/**
	 * Invoices from a date on, keyed by client reference.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       Window start (UTC).
	 * @return array<string,ExternalRef>
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return ( new SevdeskReader() )->reconcile( $this->api( $connection ), $from );
	}

	/**
	 * Tax codes the tax mapping can use: rule ids on sevDesk-Update 2.0, tax types on 1.0.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 */
	public function listTaxCodes( Connection $connection ): array {
		$detected = $connection->config( 'detected' );
		$version  = is_array( $detected ) ? (string) ( $detected['bookkeeping_version'] ?? '2.0' ) : '2.0';
		if ( '1.0' === $version ) {
			return array(
				array(
					'code'         => 'default',
					'name'         => __( 'Taxable (default)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'rate_percent' => '19',
				),
				array(
					'code'         => 'eu',
					'name'         => __( 'Intra-community supply', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'rate_percent' => '0',
				),
				array(
					'code'         => 'noteu',
					'name'         => __( 'Not taxable in Germany (outside the EU)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'rate_percent' => '0',
				),
				array(
					'code'         => 'custom',
					'name'         => __( 'Custom tax set', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'rate_percent' => '0',
				),
				array(
					'code'         => 'ss',
					'name'         => __( 'Small business (§ 19 UStG)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'rate_percent' => '0',
				),
			);
		}
		return array(
			array(
				'code'         => '1',
				'name'         => __( 'Taxable sales in Germany', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '19',
			),
			array(
				'code'         => '2',
				'name'         => __( 'Exports', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => '3',
				'name'         => __( 'Intra-community supply', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => '4',
				'name'         => __( 'Tax-free (§ 4 UStG)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => '5',
				'name'         => __( 'Reverse charge (§ 13b UStG)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => '11',
				'name'         => __( 'Small business (§ 19 UStG)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => '17',
				'name'         => __( 'Not taxable in Germany', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => '18',
				'name'         => __( 'One-Stop-Shop, goods', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => '19',
				'name'         => __( 'One-Stop-Shop, electronic services', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => '20',
				'name'         => __( 'One-Stop-Shop, other services', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
			array(
				'code'         => '21',
				'name'         => __( 'Reverse charge (§ 18b UStG)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'rate_percent' => '0',
			),
		);
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
		$resolved = $this->contacts()->resolve( $api, $customer, $options, $item->clientReference() );
		if ( $resolved instanceof ConnectorError ) {
			return SendResult::failure( $resolved );
		}
		$ref = new ExternalRef( $resolved['id'], $customer['name'], null, array( 'name' => $customer['name'] ) );
		return $resolved['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The reference of a part.
	 *
	 * @param array<string,mixed> $part Part from the API.
	 */
	private function partRef( array $part ): ExternalRef {
		return new ExternalRef(
			PayloadReader::text( $part['id'] ?? '' ),
			PayloadReader::text( $part['partNumber'] ?? '' ),
			null,
			array( 'name' => PayloadReader::text( $part['name'] ?? '' ) )
		);
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
	 * Reference data helper.
	 */
	private function reference(): SevdeskReference {
		return new SevdeskReference();
	}

	/**
	 * Contact helper.
	 */
	private function contacts(): SevdeskContacts {
		return new SevdeskContacts( $this->reference() );
	}

	/**
	 * Position helper.
	 */
	private function lines(): SevdeskLines {
		return new SevdeskLines( new Amounts() );
	}

	/**
	 * Order flow helper.
	 */
	private function documents(): SevdeskDocuments {
		return new SevdeskDocuments( $this->contacts(), $this->lines(), new SevdeskSearch(), $this->reference(), new Amounts() );
	}

	/**
	 * Refund flow helper.
	 */
	private function refunds(): SevdeskRefunds {
		return new SevdeskRefunds( $this->contacts(), new SevdeskSearch(), $this->documents(), $this->reference(), new Amounts() );
	}
}
