<?php
/**
 * The SAP Business One connector (Service Layer with a server login).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\OrderParser;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\RefundParser;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\NoAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\SessionAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\SessionStore;
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
 * Orders become sales orders (optionally copied to an A/R invoice), refunds credit notes, customers business partners. Every call carries
 * the session cookie; a session that SAP ends (HTTP 401) is replaced once. See docs/connectors/sap-b1.md.
 */
final class SapBusinessOneConnector extends AbstractApiConnector {

	/**
	 * System name used in messages.
	 */
	public const SYSTEM = 'SAP Business One';

	/**
	 * True while the login call itself is made, so that it carries no session.
	 *
	 * @var bool
	 */
	private bool $anonymous = false;

	/**
	 * The session authentication, built once.
	 *
	 * @var SessionAuth|null
	 */
	private ?SessionAuth $session = null;

	/**
	 * The Service Layer version the last login reported.
	 *
	 * @var string
	 */
	private string $serviceVersion = '';

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorDependencies $deps     Shared services.
	 * @param SessionStore|null     $sessions Where session ids are kept between requests (transients by default).
	 */
	public function __construct( ConnectorDependencies $deps, private readonly ?SessionStore $sessions = null ) {
		parent::__construct( $deps );
	}

	/**
	 * Identity, flags and limits.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'sap-b1',
			label: self::SYSTEM,
			type: ConnectorType::Erp,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::StockIn, Capability::InvoiceReadback, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::ServerLogin,
			regions: array(),
			rateLimit: new RateLimitHints( requestsPerSecond: 2.0, concurrency: 1 ),
			hosts: array(),
			merchantHost: true
		);
	}

	/**
	 * The Service Layer base address.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the address is not usable.
	 */
	protected function baseUrl( Connection $connection ): string {
		$address = $this->address( (string) $connection->config( 'address', '' ) );
		if ( '' === $address ) {
			$problem = Problem::validation( self::SYSTEM, 'address', __( 'Enter the Service Layer address as https://host:port.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return $address . '/b1s/' . SapBusinessOneOptions::from( $connection )->apiVersion();
	}

	/**
	 * Session cookie from the cached login.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		if ( $this->anonymous ) {
			return new NoAuth();
		}
		if ( null === $this->session ) {
			$this->session = new SessionAuth( fn( Connection $signIn ): array => $this->signIn( $signIn ), 'B1SESSION', '', '', $this->sessions );
		}
		return $this->session;
	}

	/**
	 * Case-insensitive text comparisons in filters.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		unset( $connection );
		return array( 'B1S-CaseInsensitive' => 'true' );
	}

	/**
	 * The connect form.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::address( 'address', __( 'Service Layer address', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'For example https://sap.example.com:50000. The address of the Service Layer your website can reach.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			new CredentialField( 'database', __( 'Company database', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, __( 'The CompanyDB name from the SLD. Capitals count.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::username( 'user', __( 'User', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'A licensed SAP user made for the shop.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::password( 'password', __( 'Password', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Saved encrypted. It is never shown again.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::choice(
				'api_version',
				__( 'Service Layer version', 'estasoft-enterprise-erpcrm-sync-engine' ),
				array(
					'v2' => __( 'v2 (OData v4)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'v1' => __( 'v1 (OData v3, older installations)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'v2'
			),
		);
	}

	/**
	 * Remove the user name from messages too.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values = parent::redactionValues( $connection );
		$user   = trim( (string) $connection->config( 'user', '' ) );
		if ( strlen( $user ) > 3 ) {
			$values[] = $user;
		}
		return $values;
	}

	/**
	 * The Service Layer's error shapes.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new SapBusinessOneErrors( self::SYSTEM ) )->map( $response );
	}

	/**
	 * Test connection: sign in, then read what the connector needs.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the login or a probe fails for a reason other than a missing right.
	 */
	protected function identify( Connection $connection ): TestResult {
		$kit         = $this->kit( $connection );
		$permissions = array(
			'orders'            => $kit->gateway->readable( 'Orders', 'DocEntry' ),
			'invoices'          => $kit->gateway->readable( 'Invoices', 'DocEntry' ),
			'credit_notes'      => $kit->gateway->readable( 'CreditNotes', 'DocEntry' ),
			'business_partners' => $kit->gateway->readable( 'BusinessPartners', 'CardCode' ),
			'items'             => $kit->gateway->readable( 'Items', 'ItemCode' ),
		);
		$missing     = array_keys( array_filter( $permissions, static fn( bool $allowed ): bool => ! $allowed ) );
		$error       = array() === $missing ? null : new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => self::SYSTEM,
				'action' => __( 'read orders, customers and items', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			403,
			null,
			null,
			'missing: ' . implode( ',', $missing )
		);
		$warehouses  = $kit->reader->warehouses();
		return new TestResult(
			null === $error,
			'' !== $this->serviceVersion ? $this->serviceVersion : sprintf( 'Service Layer %s', $kit->options->apiVersion() ),
			(string) $connection->config( 'database', '' ),
			$warehouses,
			$permissions,
			$error,
			array( 'api_version' => $kit->options->apiVersion() )
		);
	}

	/**
	 * Cheap health probe.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->kit( $connection )->gateway->readable( 'Orders', 'DocEntry' );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Target fields for a mapping type.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ( new SapBusinessOneMappings() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new SapBusinessOneMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the record an item stands for, finished according to the connection's options.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item being sent.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a request failure.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$kit = $this->kit( $connection );
		if ( EntityType::Refund === $item->entityType ) {
			$note = $kit->refunds->find( $item->clientReference() );
			if ( null === $note ) {
				return null;
			}
			$error = $kit->refunds->checkMarked( $note, $kit->total );
			return $error ?? $kit->refunds->reference( $note, $kit->orders->currencyOf( $note ) );
		}
		if ( EntityType::Order !== $item->entityType ) {
			return null;
		}
		$order = $kit->orders->find( $item->clientReference() );
		if ( null === $order ) {
			return null;
		}
		$error = $kit->orders->checkMarked( $order, $kit->total );
		if ( null !== $error ) {
			return $error;
		}
		$extra = array();
		if ( 'order_invoice' === $kit->options->documents() ) {
			$invoice = $kit->orders->invoiceOf( $item->clientReference() );
			if ( null === $invoice ) {
				return null;
			}
			$extra = array( 'invoice_id' => (string) ( $invoice['DocEntry'] ?? '' ) );
		}
		return $kit->orders->reference( $order, $kit->orders->currencyOf( $order ), $extra );
	}

	/**
	 * Send an order, a refund or a customer.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On a request failure.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		return match ( $item->entityType ) {
			EntityType::Order    => $this->sendOrder( $connection, $item, $payload ),
			EntityType::Refund   => $this->sendRefund( $connection, $item, $payload ),
			EntityType::Customer => $this->sendCustomer( $connection, $payload ),
			default              => SendResult::failure( Problem::validation( self::SYSTEM, 'entity', __( 'This connector does not send this kind of record.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) ),
		};
	}

	/**
	 * Find a customer by email.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 *
	 * @throws ApiException On a request failure.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$found = $this->kit( $connection )->partners->find( $email );
		return null === $found ? null : new ExternalRef( $found['id'], $found['name'] );
	}

	/**
	 * Find an item by code.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On a request failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$found = $this->kit( $connection )->reader->item( $sku );
		return null === $found ? null : new ExternalRef( $found['id'], $found['ref'] );
	}

	/**
	 * Search items.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On a request failure.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$list = array();
		foreach ( $this->kit( $connection )->reader->searchItems( $query ) as $found ) {
			$list[] = new ExternalRef( $found['id'], $found['ref'], null, array( 'name' => $found['name'] ) );
		}
		return $list;
	}

	/**
	 * Quantities on hand and committed.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $skus       Item codes.
	 * @param array<int,string> $warehouses Warehouse codes that count; empty means all.
	 * @return array<string,array{quantity:int,held:int}>|ConnectorError
	 *
	 * @throws ApiException On a request failure.
	 */
	public function fetchStock( Connection $connection, array $skus, array $warehouses ): array|ConnectorError {
		return $this->kit( $connection )->reader->stock( $skus, $warehouses );
	}

	/**
	 * Invoice facts for a synced order.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 *
	 * @throws ApiException On a request failure.
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return $this->kit( $connection )->reader->invoice( $ref->id );
	}

	/**
	 * The shop's orders since a date, for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On a request failure.
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return $this->kit( $connection )->reader->orders( $from );
	}

	/**
	 * Output tax codes (VAT groups) for the tax mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 *
	 * @throws ApiException On a request failure.
	 */
	public function listTaxCodes( Connection $connection ): array {
		return $this->kit( $connection )->reader->taxCodes();
	}

	/**
	 * Send an order.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On a request failure.
	 */
	private function sendOrder( Connection $connection, Item $item, array $payload ): SendResult {
		$kit    = $this->kit( $connection );
		$parsed = ( new OrderParser( self::SYSTEM, $kit->amounts ) )->parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$warnings = new Warnings();
		$order    = $kit->orders->find( $parsed->reference );
		$existed  = null !== $order;
		$order    = $order ?? $kit->orders->create( $parsed, $warnings );
		$error    = $kit->orders->check( $order, $parsed->currency, $parsed->totalMinor, $kit->total, $warnings );
		if ( null !== $error ) {
			return SendResult::failure( $error );
		}
		$extra = array();
		if ( 'order_invoice' === $kit->options->documents() ) {
			$invoice = $kit->orders->ensureInvoice( $order, $parsed->orderDate );
			$error   = $kit->orders->check( $invoice, $parsed->currency, $parsed->totalMinor, $kit->total, $warnings );
			if ( null !== $error ) {
				return SendResult::failure( $error );
			}
			$extra = array(
				'invoice_id'     => (string) ( $invoice['DocEntry'] ?? '' ),
				'invoice_number' => (string) ( $invoice['DocNum'] ?? '' ),
			);
		}
		if ( $warnings->any() ) {
			$extra['warnings'] = $warnings->all();
		}
		$ref = $kit->orders->reference( $order, $parsed->currency, $extra );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Send a refund as a credit note.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On a request failure.
	 */
	private function sendRefund( Connection $connection, Item $item, array $payload ): SendResult {
		$kit    = $this->kit( $connection );
		$refund = ( new RefundParser( self::SYSTEM, $kit->amounts ) )->parse( $payload, $item );
		if ( $refund instanceof ConnectorError ) {
			return SendResult::failure( $refund );
		}
		$expected = $kit->refunds->expected( $refund );
		$warnings = new Warnings();
		$note     = $kit->refunds->find( $refund->reference );
		$existed  = null !== $note;
		$note     = $note ?? $kit->refunds->create( $refund, $warnings );
		$currency = $kit->orders->currencyOf( $note );
		$error    = $kit->refunds->check( $note, $expected, $currency, $kit->total, $warnings );
		if ( null !== $error ) {
			if ( ! $existed && 'fail' === $kit->options->totalMismatch() ) {
				$cancelled = $kit->refunds->cancel( $note );
				$error     = Problem::validation(
					self::SYSTEM,
					'credit note total',
					(string) $error->context['reason'] . ' ' . ( $cancelled ? __( 'The credit note was cancelled. Credit the customer in SAP.', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'The credit note could not be cancelled and stays in SAP; cancel or correct it there.', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				);
			}
			return SendResult::failure( $error );
		}
		$ref = $kit->refunds->reference( $note, $currency );
		if ( $warnings->any() ) {
			$ref = new ExternalRef( $ref->id, $ref->ref, $ref->url, $ref->extra + array( 'warnings' => $warnings->all() ) );
		}
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Send a customer.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On a request failure.
	 */
	private function sendCustomer( Connection $connection, array $payload ): SendResult {
		$kit     = $this->kit( $connection );
		$partner = PayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return SendResult::failure( Problem::missingEmail( self::SYSTEM, '' ) );
		}
		$customer = $kit->partners->ensure( $partner, array(), new Warnings() );
		$ref      = new ExternalRef( $customer['id'], $customer['name'] );
		return $customer['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The helpers for a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function kit( Connection $connection ): SapBusinessOneKit {
		return new SapBusinessOneKit( $this->api( $connection ), SapBusinessOneOptions::from( $connection ), self::SYSTEM );
	}

	/**
	 * Log in: company, user and password trade for a session.
	 *
	 * @param Connection $connection Connection.
	 * @return array{id:string,ttl:int}
	 *
	 * @throws ApiException With SYNC-AUTH-001 (or SYNC-CFG-003 for an unknown company) when SAP refuses the login.
	 */
	private function signIn( Connection $connection ): array {
		$this->anonymous = true;
		$database        = trim( (string) $connection->config( 'database', '' ) );
		try {
			$api = $this->api( $connection );
			try {
				$response = $api->post(
					'Login',
					array(),
					array(
						'CompanyDB' => $database,
						'UserName'  => trim( (string) $connection->config( 'user', '' ) ),
						'Password'  => $connection->secret( 'password' ),
					)
				);
			} catch ( ApiException $exception ) {
				throw $this->loginFailure( $exception, $database );
			}
			$json    = $response->json();
			$session = (string) ( $json['SessionId'] ?? '' );
			if ( '' === $session ) {
				$problem = new ConnectorError( ErrorCode::Auth001, array( 'system' => self::SYSTEM ), null, null, null, 'no session id in the answer' );
				throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
			}
			$route                = 1 === preg_match( '/ROUTEID=([^;,\s]+)/', (string) $response->header( 'set-cookie' ), $match ) ? '; ROUTEID=' . $match[1] : '';
			$this->serviceVersion = isset( $json['Version'] ) ? sprintf( 'Service Layer %s', (string) $json['Version'] ) : '';
			$api->redaction()->add( $session );
			return array(
				'id'  => $session . $route,
				'ttl' => max( 120, (int) ( $json['SessionTimeout'] ?? 30 ) * 60 ),
			);
		} finally {
			$this->anonymous = false;
		}
	}

	/**
	 * A failed login as the right error: an unknown company is SYNC-CFG-003, anything else about the sign-in SYNC-AUTH-001.
	 *
	 * @param ApiException $exception The failure of the login call.
	 * @param string       $database  The company database the person typed.
	 */
	private function loginFailure( ApiException $exception, string $database ): ApiException {
		$status = $exception->status();
		if ( null === $status || $status >= 500 || 429 === $status ) {
			return $exception;
		}
		$text = null === $exception->response() ? '' : strtolower( ( new SapBusinessOneErrors( self::SYSTEM ) )->message( $exception->response() ) );
		if ( 1 === preg_match( '/(company|database)[^.]{0,40}(not found|unknown|does not exist|invalid|no such)|(not found|unknown|invalid|no such)[^.]{0,40}(company|database)/', $text ) ) {
			$error = new ConnectorError(
				ErrorCode::Cfg003,
				array(
					'system' => self::SYSTEM,
					'name'   => $database,
				),
				$status,
				null,
				null,
				'company database unknown'
			);
		} else {
			$error = new ConnectorError( ErrorCode::Auth001, array( 'system' => self::SYSTEM ), $status, null, null, 'login refused' );
		}
		return new ApiException( $error, $exception->response() );
	}

	/**
	 * The address reduced to scheme, host and port, or an empty string when it is not usable.
	 *
	 * @param string $address What the person typed.
	 */
	private function address( string $address ): string {
		$parts = wp_parse_url( trim( $address ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'https', 'http' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 1 !== preg_match( '/^[a-z0-9.\-\[\]:]+$/', $host ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) && (int) $parts['port'] > 0 && (int) $parts['port'] < 65536 ? ':' . (int) $parts['port'] : '';
		return strtolower( (string) $parts['scheme'] ) . '://' . $host . $port;
	}
}
