<?php
/**
 * The Oracle Fusion Cloud ERP connector (Receivables REST APIs).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\OrderParser;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\RefundParser;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\BasicAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
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
 * Orders become Receivables invoices billed to one customer account, refunds on-account credit memos; invoices are read back and listed for
 * the nightly check. See docs/connectors/oracle-fusion.md for what the connector does not do and why.
 */
final class OracleFusionConnector extends AbstractApiConnector {

	/**
	 * System name used in messages.
	 */
	public const SYSTEM = 'Oracle Fusion Cloud ERP';

	/**
	 * Path of the REST API under the pod host.
	 */
	public const API_PATH = '/fscmRestApi/resources/11.13.18.05';

	/**
	 * Identity, flags and limits.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'oracle-fusion',
			label: self::SYSTEM,
			type: ConnectorType::Erp,
			capabilities: array( Capability::Orders, Capability::Refunds, Capability::InvoiceReadback, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund ),
			auth: AuthStyle::OAuthClientCredentials,
			regions: array(),
			rateLimit: new RateLimitHints( requestsPerSecond: 2.0, concurrency: 1 ),
			hosts: array(),
			merchantHost: true
		);
	}

	/**
	 * The base address of the REST API.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the address is not usable.
	 */
	protected function baseUrl( Connection $connection ): string {
		$parts = wp_parse_url( trim( (string) $connection->config( 'address', '' ) ) );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( ! is_array( $parts ) || '' === $host || 1 !== preg_match( '/^[a-z0-9.\-\[\]:]+$/', $host ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			$problem = Problem::validation( self::SYSTEM, 'address', __( 'Enter the address of the Oracle Fusion pod, for example https://example.fa.us2.oraclecloud.com.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$port = isset( $parts['port'] ) && (int) $parts['port'] > 0 && (int) $parts['port'] < 65536 ? ':' . (int) $parts['port'] : '';
		return 'https://' . $host . $port . self::API_PATH;
	}

	/**
	 * Bearer token from the identity domain's client credentials grant, or Basic sign-in of a Fusion user.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		return 'basic' === OracleFusionOptions::from( $connection )->authMethod() ? new BasicAuth( 'user', 'password' ) : new OAuth2Auth( new OracleFusionOAuth() );
	}

	/**
	 * Headers of every call: the REST framework version (the `q` syntax depends on it) and JSON answers.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		return array(
			'REST-Framework-Version' => (string) OracleFusionOptions::from( $connection )->frameworkVersion(),
			'Accept'                 => 'application/json',
		);
	}

	/**
	 * The identity domain host is removed from messages as well.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values = parent::redactionValues( $connection );
		$host   = (string) wp_parse_url( OracleFusionOptions::identityBase( $connection ), PHP_URL_HOST );
		if ( '' !== $host ) {
			$values[] = $host;
		}
		return $values;
	}

	/**
	 * The connect form.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::address( 'address', __( 'Address of the Fusion pod', 'estasoft-erp-crm-sync-engine' ), __( 'For example https://example.fa.us2.oraclecloud.com. Only the host is used.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::choice(
				'auth_method',
				__( 'Sign-in', 'estasoft-erp-crm-sync-engine' ),
				array(
					'oauth' => __( 'OAuth 2.0 (client ID and secret)', 'estasoft-erp-crm-sync-engine' ),
					'basic' => __( 'Fusion user and password', 'estasoft-erp-crm-sync-engine' ),
				),
				'oauth'
			),
			new CredentialField( 'identity_url', __( 'Identity domain address', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'OAuth: the address of the identity domain, for example https://idcs-1234.identity.oraclecloud.com.', 'estasoft-erp-crm-sync-engine' ), '', 'url', array(), 'app' ),
			new CredentialField( 'scope', __( 'Scope', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'OAuth: exactly as the application shows it, urn:opc:resource:fusion:<POD>:erp/.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'app' ),
			CredentialFields::clientId( 'client_id', __( 'OAuth: the Client ID of the confidential application.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::clientSecret( 'client_secret' ),
			CredentialFields::username( 'user', __( 'Fusion user', 'estasoft-erp-crm-sync-engine' ), __( 'Basic sign-in only.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::password( 'password', __( 'Password', 'estasoft-erp-crm-sync-engine' ), __( 'Basic sign-in only. Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ) ),
			new CredentialField( 'business_unit', __( 'Business unit', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'The name of the business unit that owns the invoices.', 'estasoft-erp-crm-sync-engine' ) ),
			new CredentialField( 'transaction_source', __( 'Transaction source', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'The name of the Receivables transaction source for web orders.', 'estasoft-erp-crm-sync-engine' ) ),
			new CredentialField( 'transaction_type', __( 'Transaction type', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'The name of the invoice transaction type.', 'estasoft-erp-crm-sync-engine' ) ),
			new CredentialField( 'payment_terms', __( 'Payment terms', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Optional. Empty leaves them to the customer account.', 'estasoft-erp-crm-sync-engine' ) ),
			new CredentialField( 'bill_to_account', __( 'Customer account number', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'The customer account all web orders are billed to.', 'estasoft-erp-crm-sync-engine' ) ),
		);
	}

	/**
	 * The Fusion error shapes.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new OracleFusionErrors( self::SYSTEM ) )->map( $response );
	}

	/**
	 * Test connection: read what the connector needs.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the sign-in or a probe fails for a reason other than a missing role.
	 */
	protected function identify( Connection $connection ): TestResult {
		$kit         = $this->kit( $connection );
		$permissions = array(
			'invoices'     => $kit->gateway->readable( OracleFusionOrders::RESOURCE ),
			'items'        => $kit->gateway->readable( OracleFusionItems::RESOURCE ),
			'credit_memos' => $kit->gateway->readable( OracleFusionRefunds::RESOURCE ),
		);
		$needed      = 'fail' === $kit->options->unknownSku() ? array( 'invoices', 'items' ) : array( 'invoices' );
		$missing     = array_values( array_filter( $needed, static fn( string $name ): bool => ! $permissions[ $name ] ) );
		$error       = array() === $missing ? null : new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => self::SYSTEM,
				'action' => __( 'read invoices and items', 'estasoft-erp-crm-sync-engine' ),
			),
			403,
			null,
			null,
			'missing: ' . implode( ',', $missing )
		);
		return new TestResult(
			null === $error,
			__( 'REST 11.13.18.05', 'estasoft-erp-crm-sync-engine' ),
			$kit->options->businessUnit(),
			array(),
			$permissions,
			$error,
			array( 'bill_to_account_set' => '' !== $kit->options->billToAccount() )
		);
	}

	/**
	 * Cheap health probe.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->kit( $connection )->gateway->readable( OracleFusionOrders::RESOURCE );
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
		return ( new OracleFusionMappings() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new OracleFusionMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the record an item stands for. An invoice made with a wrong total, or left incomplete, is returned as an error.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item being sent.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a request failure.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$kit = $this->kit( $connection );
		if ( EntityType::Refund !== $item->entityType && EntityType::Order !== $item->entityType ) {
			return null;
		}
		$kit->orders->requireUnit();
		if ( EntityType::Refund === $item->entityType ) {
			$memo = $kit->refunds->find( $item->clientReference() );
			if ( null === $memo ) {
				return null;
			}
			return $kit->refunds->checkMarked( $memo, $kit->total ) ?? $kit->refunds->reference( $memo );
		}
		$invoice = $kit->orders->find( $item->clientReference() );
		if ( null === $invoice ) {
			return null;
		}
		$error = $kit->orders->checkMarked( $invoice, $kit->total );
		return $error ?? $kit->orders->reference( $invoice, false );
	}

	/**
	 * Send an order or a refund.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On a request failure.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		return match ( $item->entityType ) {
			EntityType::Order  => $this->sendOrder( $connection, $item, $payload ),
			EntityType::Refund => $this->sendRefund( $connection, $item, $payload ),
			default            => SendResult::failure( Problem::validation( self::SYSTEM, 'entity', __( 'This connector does not send this kind of record.', 'estasoft-erp-crm-sync-engine' ) ) ),
		};
	}

	/**
	 * Customers are not synced, so there is never one to find. The call still checks the sign-in, so a wrong secret is reported here as well.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email.
	 *
	 * @throws ApiException When the sign-in or the request fails.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		unset( $email );
		$this->kit( $connection )->gateway->readable( OracleFusionOrders::RESOURCE );
		return null;
	}

	/**
	 * Find an item by number. With `unknown_sku = free_text` every SKU is accepted as it is, so a mapping works for a merchant who cannot read items.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On a request failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$kit = $this->kit( $connection );
		if ( 'free_text' === $kit->options->unknownSku() ) {
			return '' === trim( $sku ) ? null : new ExternalRef( trim( $sku ), trim( $sku ) );
		}
		$found = $kit->items->product( $sku );
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
		return array_map( static fn( array $found ): ExternalRef => new ExternalRef( $found['id'], $found['ref'] ), $this->kit( $connection )->items->search( $query ) );
	}

	/**
	 * Invoice facts for a synced order.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The invoice.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 *
	 * @throws ApiException On a request failure.
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return $this->kit( $connection )->reader->invoice( $ref->id );
	}

	/**
	 * The shop's invoices since a date for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On a request failure.
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		$kit = $this->kit( $connection );
		$kit->orders->requireUnit();
		return $kit->reader->orders( $from );
	}

	/**
	 * Oracle Fusion works tax out itself, so there are no tax codes to map.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 */
	public function listTaxCodes( Connection $connection ): array {
		unset( $connection );
		return array();
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
		$parsed = ( new OrderParser( self::SYSTEM, $kit->amounts, false ) )->parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		if ( $parsed->pricesIncludeTax ) {
			return SendResult::failure( Problem::validation( self::SYSTEM, 'prices_include_tax', __( 'Oracle Fusion takes unit prices before tax. Map the prices without tax.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$kit->orders->requireSettings( $kit->orders->billTo( $parsed ) );
		$warnings = new Warnings();
		$invoice  = $kit->orders->find( $parsed->reference );
		$existed  = null !== $invoice;
		$invoice  = $invoice ?? $kit->orders->settled( $kit->orders->create( $parsed, $warnings ) );
		$error    = $kit->orders->unfinished( $invoice ) ?? $kit->orders->check( $invoice, $parsed, $kit->total, $warnings, ! $existed );
		if ( null !== $error ) {
			return SendResult::failure( $error );
		}
		$ref = $kit->orders->reference( $invoice, ! $existed, $warnings->any() ? array( 'warnings' => $warnings->all() ) : array() );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Send a refund as an on-account credit memo.
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
		$kit->orders->requireUnit();
		$warnings = new Warnings();
		$expected = $kit->refunds->expected( $refund );
		$memo     = $kit->refunds->find( $refund->reference );
		$existed  = null !== $memo;
		$memo     = $memo ?? $kit->refunds->create( $refund, $warnings );
		$currency = strtoupper( (string) ( $memo['OrderCurrency'] ?? ( $memo['InvoiceCurrencyCode'] ?? '' ) ) );
		$error    = $kit->refunds->check( $memo, $expected, $currency, $kit->total, $warnings );
		if ( null !== $error ) {
			return SendResult::failure( $error );
		}
		$ref = $kit->refunds->reference( $memo );
		if ( $warnings->any() ) {
			$ref = new ExternalRef( $ref->id, $ref->ref, $ref->url, array( 'warnings' => $warnings->all() ) );
		}
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * The helpers for a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function kit( Connection $connection ): OracleFusionKit {
		return new OracleFusionKit( $this->api( $connection ), OracleFusionOptions::from( $connection ), self::SYSTEM );
	}
}
