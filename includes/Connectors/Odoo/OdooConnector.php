<?php
/**
 * The Odoo connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Connects the engine to Odoo 17 and later (Online, Odoo.sh and on-premise). Odoo 17 and 18 are reached through JSON-RPC,
 * Odoo 19 and later through the JSON-2 API; the version found at the address decides. Every request goes through the engine's
 * HTTP client, one at a time, about a second apart. Nothing here reads or writes WordPress data.
 *
 * Public methods return a ConnectorError instead of throwing; the internal OdooException never leaves this class.
 */
final class OdooConnector implements ConnectorInterface {

	/**
	 * Matches shown to the person when searching products for SKU mapping.
	 */
	private const SEARCH_LIMIT = 20;

	/**
	 * Builds and remembers signed-in clients.
	 *
	 * @var OdooClientFactory
	 */
	private OdooClientFactory $factory;

	/**
	 * Conversion helper.
	 *
	 * @var OdooAmounts
	 */
	private OdooAmounts $amounts;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface $http              The engine's HTTP client (the only way out to the network).
	 * @param callable|null       $allowPrivateHosts Receives the base URL, returns true when private hosts are allowed. Defaults to the filter estasoft_sync_allow_private_hosts.
	 * @param callable|null       $now               Returns the time in seconds; defaults to time(). Tests pass a fixed clock.
	 * @param callable|null       $sleep             Receives milliseconds; defaults to usleep. Tests pass a recorder.
	 * @param callable|null       $clock             Returns milliseconds as a float; defaults to microtime.
	 */
	public function __construct( HttpClientInterface $http, ?callable $allowPrivateHosts = null, ?callable $now = null, ?callable $sleep = null, ?callable $clock = null ) {
		$allow         = $allowPrivateHosts ?? static fn( string $url ): bool => (bool) apply_filters( 'estasoft_sync_allow_private_hosts', false, $url );
		$this->amounts = new OdooAmounts();
		$this->factory = new OdooClientFactory( $http, new OdooAddressNormalizer( $allow ), $now, $sleep, $clock );
	}

	/**
	 * Stable id.
	 */
	public function id(): string {
		return 'odoo';
	}

	/**
	 * Name shown to the person.
	 */
	public function label(): string {
		return 'Odoo';
	}

	/**
	 * Odoo is an ERP.
	 */
	public function type(): ConnectorType {
		return ConnectorType::Erp;
	}

	/**
	 * The contract version this connector was written against.
	 */
	public function contractVersion(): string {
		return ConnectorInterface::CONTRACT_VERSION;
	}

	/**
	 * Capability flags. Each true flag has an implementation behind it; prices from Odoo and webhooks are not built.
	 *
	 * @return array<string,bool>
	 */
	public function capabilities(): array {
		return array(
			Capability::Orders->value          => true,
			Capability::Customers->value       => true,
			Capability::Refunds->value         => true,
			Capability::StockIn->value         => true,
			Capability::TrackingIn->value      => true,
			Capability::PricesIn->value        => false,
			Capability::InvoiceReadback->value => true,
			Capability::Reconciliation->value  => true,
			Capability::Webhooks->value        => false,
			Capability::ShadowLookup->value    => true,
		);
	}

	/**
	 * Entity types the connector sends.
	 *
	 * @return array<int,EntityType>
	 */
	public function supportedEntities(): array {
		return array( EntityType::Order, EntityType::Refund, EntityType::Customer );
	}

	/**
	 * About one call a second, one at a time (Odoo's acceptable use policy).
	 *
	 * @return array{requests_per_second:float,concurrency:int}
	 */
	public function rateLimitHints(): array {
		return array(
			'requests_per_second' => 1.0,
			'concurrency'         => 1,
		);
	}

	/**
	 * The fields of the connect screen. The user is only needed with the key on Odoo 17 and 18.
	 *
	 * @return array<int,CredentialField>
	 */
	public function credentialFields(): array {
		return array(
			new CredentialField(
				'address',
				__( 'Odoo address', 'estasoft-enterprise-erpcrm-sync-engine' ),
				false,
				true,
				__( 'The web address you use to sign in to Odoo, for example https://mycompany.odoo.com.', 'estasoft-enterprise-erpcrm-sync-engine' )
			),
			new CredentialField(
				'database',
				__( 'Database', 'estasoft-enterprise-erpcrm-sync-engine' ),
				false,
				true,
				__( 'Shown on the Odoo sign-in page. On Odoo Online it is the first part of the address.', 'estasoft-enterprise-erpcrm-sync-engine' )
			),
			new CredentialField(
				'user',
				__( 'Sync user (email)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				false,
				false,
				__( 'A user made just for syncing. Odoo 17 and 18 need it together with the key. Odoo 19 and later use the key alone, and this field can stay empty.', 'estasoft-enterprise-erpcrm-sync-engine' )
			),
			new CredentialField(
				'api_key',
				__( 'API key', 'estasoft-enterprise-erpcrm-sync-engine' ),
				true,
				true,
				__( 'Saved encrypted. It is never shown again.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'',
				'password'
			),
			new CredentialField(
				'company',
				__( 'Company', 'estasoft-enterprise-erpcrm-sync-engine' ),
				false,
				false,
				__( 'Only for an Odoo with several companies: the name of the company that gets the orders. Leave empty to use the sync user\'s own company.', 'estasoft-enterprise-erpcrm-sync-engine' )
			),
		);
	}

	/**
	 * Test connection.
	 *
	 * @param Connection $connection Connection with its decrypted key.
	 */
	public function test( Connection $connection ): TestResult {
		try {
			return ( new OdooConnectionTester( $this->factory, $this->amounts ) )->test( $connection );
		} catch ( \Throwable $throwable ) {
			return new TestResult( false, '', '', array(), array(), $this->unexpected( $throwable ) );
		}
	}

	/**
	 * Cheap health probe: does the address answer with a version? No sign-in, no database work.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->factory->responds( $connection );
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Target fields for the mapping screens.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array{path:string,label:string,type:string,required:bool,group:string}>
	 */
	public function describeFields( string $mappingType ): array {
		return ( new OdooFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public function defaultMapping( string $mappingType ): array {
		return ( new OdooDefaultMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the record a retry would create again: the sale order whose customer reference is WC-{id}, or the credit note
	 * whose reference starts with it. The engine links a record found here and does not call send() for it, so a record whose
	 * steps are not all done (a quotation not confirmed, an invoice not posted or not yet sent to the tax authority service, an
	 * unpaid invoice, a credit note still a draft) is reported as not found: send() then finds it and finishes the steps. The
	 * steps wanted are the ones the connection's options ask for.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item being sent.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		return $this->guard(
			$connection,
			function ( OdooClient $client ) use ( $connection, $item ): ?ExternalRef {
				$finder  = new OdooOrderFinder( $client, $this->amounts );
				$flow    = new OdooDocumentFlow( $client );
				$options = OdooOptions::fromConnection( $connection );
				if ( EntityType::Order === $item->entityType ) {
					$record = $finder->order( $item->clientReference() );
					if ( null === $record || ! $flow->isComplete( OdooRecord::id( $record['id'] ?? 0 ), OdooOrderPayload::plan( $options, array() ) ) ) {
						return null;
					}
					return $finder->orderRef( $record );
				}
				if ( EntityType::Refund === $item->entityType ) {
					$record = $finder->creditNote( $item->clientReference() );
					if ( null === $record || ! $flow->isMoveComplete( OdooRecord::id( $record['id'] ?? 0 ), 'post' === $options->choice( 'refund_state', array( 'draft', 'post' ), 'draft' ) ) ) {
						return null;
					}
					return $finder->creditNoteRef( $record );
				}
				return null;
			}
		);
	}

	/**
	 * Send an order, a refund or a customer. A payload that is wrong on its face is refused before any request goes out.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item being sent.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		try {
			return match ( $item->entityType ) {
				EntityType::Order    => $this->sendOrder( $connection, $item, $payload ),
				EntityType::Refund   => $this->sendRefund( $connection, $item, $payload ),
				EntityType::Customer => $this->sendCustomer( $connection, $payload ),
				default              => SendResult::failure( $this->notSupported( $item->entityType ) ),
			};
		} catch ( OdooException $exception ) {
			return SendResult::failure( $exception->error );
		} catch ( \Throwable $throwable ) {
			return SendResult::failure( $this->unexpected( $throwable ) );
		}
	}

	/**
	 * Find a customer by email, ignoring case.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		return $this->guard(
			$connection,
			function ( OdooClient $client ) use ( $connection, $email ): ?ExternalRef {
				$partners = new OdooPartners( $client, new OdooCatalog( $client, OdooOptions::fromConnection( $connection ) ), OdooOptions::fromConnection( $connection ) );
				$matches  = $partners->findByEmail( $email );
				if ( array() === $matches ) {
					return null;
				}
				$id = OdooRecord::id( $matches[0]['id'] ?? 0 );
				return new ExternalRef(
					(string) $id,
					OdooRecord::text( $matches[0]['name'] ?? '' ),
					$client->recordUrl( 'res.partner', $id ),
					array(
						'email'   => OdooRecord::text( $matches[0]['email'] ?? '' ),
						'phone'   => OdooRecord::text( $matches[0]['phone'] ?? '' ),
						'vat'     => OdooRecord::text( $matches[0]['vat'] ?? '' ),
						'matches' => count( $matches ),
					)
				);
			}
		);
	}

	/**
	 * Find a product by its internal reference (the SKU).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 * @return ExternalRef|ConnectorError|null
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		return $this->guard(
			$connection,
			function ( OdooClient $client ) use ( $sku ): ?ExternalRef {
				$sku = trim( $sku );
				if ( '' === $sku ) {
					return null;
				}
				$found = $client->searchRead(
					'product.product',
					array_merge( array( array( 'default_code', '=', $sku ) ), $client->companyDomain() ),
					array( 'default_code', 'name', 'barcode' ),
					array(
						'order' => 'id asc',
						'limit' => 1,
					)
				);
				return isset( $found[0] ) ? $this->productRef( $client, $found[0] ) : null;
			}
		);
	}

	/**
	 * Search products by reference, name or barcode, for SKU mapping.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$result = $this->guard(
			$connection,
			function ( OdooClient $client ) use ( $query ): array {
				$query = trim( $query );
				if ( '' === $query ) {
					return array();
				}
				$domain  = array_merge( array( '|', '|', array( 'default_code', 'ilike', $query ), array( 'name', 'ilike', $query ), array( 'barcode', 'ilike', $query ) ), $client->companyDomain() );
				$records = $client->searchRead(
					'product.product',
					$domain,
					array( 'default_code', 'name', 'barcode' ),
					array(
						'order' => 'default_code asc, id asc',
						'limit' => self::SEARCH_LIMIT,
					)
				);
				return array_map( fn( array $record ): ExternalRef => $this->productRef( $client, $record ), $records );
			}
		);
		return $result;
	}

	/**
	 * Stock quantities.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       Internal references.
	 * @param array<int,string> $warehouses Warehouse ids that count; empty means all.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return $this->guard(
			$connection,
			fn( OdooClient $client ): array => ( new OdooStockReader( $client, new OdooCatalog( $client, OdooOptions::fromConnection( $connection ) ) ) )->fetch( $skus, $warehouses )
		);
	}

	/**
	 * Deliveries finished since a time.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $since      UTC.
	 * @return array<int,array{order_ref:string,status:string,carrier:string,tracking:string,done_at:string}>|ConnectorError
	 */
	public function fetchTracking( Connection $connection, \DateTimeImmutable $since ): array|ConnectorError {
		return $this->guard(
			$connection,
			fn( OdooClient $client ): array => ( new OdooTrackingReader( $client, $this->amounts ) )->fetch( $since )
		);
	}

	/**
	 * Invoice facts of a synced order.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The sale order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return $this->guard(
			$connection,
			fn( OdooClient $client ): ?array => ( new OdooInvoiceReader( $client, $this->amounts ) )->fetch( $ref )
		);
	}

	/**
	 * Orders for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return $this->guard(
			$connection,
			fn( OdooClient $client ): array => ( new OdooReconciler( $client, new OdooOrderFinder( $client, $this->amounts ), $this->amounts ) )->fetch( $from )
		);
	}

	/**
	 * Sale taxes for the tax mapping screen. The code is the tax id, written as text.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,label:string,rate_percent:float}>|ConnectorError
	 */
	public function listTaxes( Connection $connection ): array|ConnectorError {
		return $this->guard(
			$connection,
			function ( OdooClient $client ): array {
				$domain = array_merge( array( array( 'type_tax_use', 'in', array( 'sale', 'none' ) ) ), $client->companyDomain() );
				$taxes  = array();
				foreach ( $client->searchRead( 'account.tax', $domain, array( 'name', 'amount', 'amount_type' ), array( 'order' => 'sequence asc, id asc' ) ) as $record ) {
					$taxes[] = array(
						'code'         => (string) OdooRecord::id( $record['id'] ?? 0 ),
						'label'        => OdooRecord::text( $record['name'] ?? '' ),
						'rate_percent' => 'percent' === ( $record['amount_type'] ?? 'percent' ) ? OdooRecord::number( $record['amount'] ?? 0 ) : 0.0,
					);
				}
				return $taxes;
			}
		);
	}

	/**
	 * Override the catalog class for failures Odoo reports in its own way: conflicts and lock errors may pass by themselves.
	 *
	 * @param ConnectorError $error Failure.
	 */
	public function classifyError( ConnectorError $error ): ?ErrorClass {
		if ( null !== $error->classOverride ) {
			return $error->classOverride;
		}
		foreach ( OdooErrorMapper::RETRYABLE_FAULTS as $fault ) {
			if ( str_contains( $error->detail, $fault ) ) {
				return ErrorClass::Retry;
			}
		}
		if ( in_array( $error->httpStatus, array( 409, 423, 425 ), true ) ) {
			return ErrorClass::Retry;
		}
		return null;
	}

	/**
	 * Run a read-only action with a client; turn an Odoo failure into a ConnectorError.
	 *
	 * @param Connection $connection Connection.
	 * @param callable   $action     Receives the OdooClient.
	 * @return mixed What the action returns, or the ConnectorError.
	 */
	private function guard( Connection $connection, callable $action ): mixed {
		try {
			return $action( $this->factory->forConnection( $connection ) );
		} catch ( OdooException $exception ) {
			return $exception->error;
		} catch ( \Throwable $throwable ) {
			return $this->unexpected( $throwable );
		}
	}

	/**
	 * The error for something this connector did not expect. Only the kind of failure is kept: its message could repeat a
	 * request, and a request holds the key. The item is tried again a few times and then shown to a person.
	 *
	 * @param \Throwable $throwable What was thrown.
	 */
	private function unexpected( \Throwable $throwable ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Net002,
			array(
				'system' => OdooErrorMapper::SYSTEM,
				'status' => 500,
			),
			500,
			null,
			null,
			'unexpected ' . get_class( $throwable )
		);
	}

	/**
	 * A product record as a reference.
	 *
	 * @param OdooClient          $client Client.
	 * @param array<string,mixed> $record Product record.
	 */
	private function productRef( OdooClient $client, array $record ): ExternalRef {
		$id = OdooRecord::id( $record['id'] ?? 0 );
		return new ExternalRef(
			(string) $id,
			OdooRecord::text( $record['default_code'] ?? '' ),
			$client->recordUrl( 'product.product', $id ),
			array(
				'name'    => OdooRecord::text( $record['name'] ?? '' ),
				'barcode' => OdooRecord::text( $record['barcode'] ?? '' ),
			)
		);
	}

	/**
	 * Create the sale order for an order event.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws OdooException When the server cannot be used.
	 */
	private function sendOrder( Connection $connection, Item $item, array $payload ): SendResult {
		$options = OdooOptions::fromConnection( $connection );
		$parsed  = OdooOrderPayload::parse( $payload, $item, $options, $this->amounts );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$client  = $this->factory->forConnection( $connection );
		$catalog = new OdooCatalog( $client, $options );
		$sender  = new OdooOrderSender( $client, $catalog, new OdooPartners( $client, $catalog, $options ), new OdooOrderFinder( $client, $this->amounts ), new OdooDocumentFlow( $client ), $options, $this->amounts, new OdooTaxPricing( $catalog ), new OdooTotalCheck( $options, $this->amounts ) );
		return $sender->send( $item, $parsed );
	}

	/**
	 * Create the credit note for a refund.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws OdooException When the server cannot be used.
	 */
	private function sendRefund( Connection $connection, Item $item, array $payload ): SendResult {
		$problem = OdooRefundSender::precheck( $payload );
		if ( null !== $problem ) {
			return SendResult::failure( $problem );
		}
		$options = OdooOptions::fromConnection( $connection );
		$client  = $this->factory->forConnection( $connection );
		$catalog = new OdooCatalog( $client, $options );
		$sender  = new OdooRefundSender( $client, $catalog, new OdooOrderFinder( $client, $this->amounts ), new OdooDocumentFlow( $client ), $options, $this->amounts, new OdooTaxPricing( $catalog ) );
		return $sender->send( $item, $payload );
	}

	/**
	 * Create or update a customer from the customer sync.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws OdooException On failure.
	 */
	private function sendCustomer( Connection $connection, array $payload ): SendResult {
		$partner = OdooPayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) || '' === ( $partner['name'] ?? '' ) ) {
			return SendResult::failure(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => OdooErrorMapper::SYSTEM,
						'field'  => 'partner.email',
						'reason' => __( 'A customer needs a name and an email address.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					),
					null,
					null,
					null,
					'customer incomplete'
				)
			);
		}
		$options = OdooOptions::fromConnection( $connection );
		$client  = $this->factory->forConnection( $connection );
		$saved   = ( new OdooPartners( $client, new OdooCatalog( $client, $options ), $options ) )->upsert( $partner );
		$ref     = new ExternalRef( (string) $saved['id'], $partner['name'], $client->recordUrl( 'res.partner', $saved['id'] ), array( 'email' => $partner['email'] ) );
		return $saved['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The error for an entity type this connector does not send.
	 *
	 * @param EntityType $type Entity type.
	 */
	private function notSupported( EntityType $type ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => OdooErrorMapper::SYSTEM,
				'field'  => $type->value,
				'reason' => __( 'This connector does not send this kind of item.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			null,
			null,
			null,
			'unsupported entity'
		);
	}
}
