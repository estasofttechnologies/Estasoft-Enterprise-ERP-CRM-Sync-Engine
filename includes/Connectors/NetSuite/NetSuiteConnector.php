<?php
/**
 * The NetSuite connector (SuiteTalk REST Web Services, OAuth 2.0 machine to machine).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

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
 * Orders become sales orders (optionally billed to an invoice), refunds credit memos made from the invoice, customers customer records.
 * The sign-in is the machine-to-machine client credentials flow with a signed assertion; the access token is kept per connection and
 * a new assertion is signed when it ends or NetSuite answers 401 (once). See docs/connectors/netsuite.md.
 */
final class NetSuiteConnector extends AbstractApiConnector {

	/**
	 * System name used in messages.
	 */
	public const SYSTEM = 'NetSuite';

	/**
	 * Identity, flags and limits.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'netsuite',
			label: self::SYSTEM,
			type: ConnectorType::Erp,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::InvoiceReadback, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthJwtBearer,
			regions: array(),
			rateLimit: new RateLimitHints( requestsPerSecond: 2.0, concurrency: 1 ),
			hosts: array( '*.suitetalk.api.netsuite.com' )
		);
	}

	/**
	 * The REST base address of the account.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the account id is not usable.
	 */
	protected function baseUrl( Connection $connection ): string {
		return 'https://' . $this->host( $connection ) . '/services/rest';
	}

	/**
	 * Bearer token from the machine-to-machine sign-in (a signed assertion trades for an access token; the base keeps and renews it).
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( new NetSuiteOAuth() );
	}

	/**
	 * The connect form.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			new CredentialField( 'account_id', __( 'Account ID', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'Under Setup, Company, Company Information. A sandbox looks like 1234567-SB1.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::clientId( 'client_id', __( 'The Client ID (consumer key) of the integration record.', 'estasoft-erp-crm-sync-engine' ) ),
			new CredentialField( 'certificate_id', __( 'Certificate ID', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'Shown after you upload the public certificate under OAuth 2.0 Client Credentials (M2M) Setup.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'app' ),
			CredentialFields::privateKey( 'private_key', __( 'The private key that belongs to the uploaded certificate. Saved encrypted. It is never shown again.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::choice(
				'algorithm',
				__( 'Signing algorithm', 'estasoft-erp-crm-sync-engine' ),
				array(
					'ES256' => __( 'ES256 (EC key)', 'estasoft-erp-crm-sync-engine' ),
					'PS256' => __( 'PS256 (RSA key)', 'estasoft-erp-crm-sync-engine' ),
				),
				'ES256',
				__( 'Must match the kind of key you made. NetSuite does not accept RS256.', 'estasoft-erp-crm-sync-engine' )
			),
			new CredentialField( 'subsidiary', __( 'Subsidiary (internal id)', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Only for accounts with subsidiaries (OneWorld): the subsidiary new customers belong to.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
		);
	}

	/**
	 * Remove the account and certificate from messages.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		$values = parent::redactionValues( $connection );
		$id     = trim( (string) $connection->config( 'certificate_id', '' ) );
		if ( '' !== $id ) {
			$values[] = $id;
		}
		return $values;
	}

	/**
	 * NetSuite's error shapes.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new NetSuiteErrors( self::SYSTEM ) )->map( $response );
	}

	/**
	 * Test connection: sign in, then read what the connector needs.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the sign-in or a probe fails for a reason other than a missing permission.
	 */
	protected function identify( Connection $connection ): TestResult {
		$kit         = $this->kit( $connection );
		$permissions = array(
			'sales_orders' => $kit->gateway->readable( 'record/v1/salesOrder' ),
			'customers'    => $kit->gateway->readable( 'record/v1/customer' ),
			'invoices'     => $kit->gateway->readable( 'record/v1/invoice' ),
			'items'        => $this->itemsReadable( $kit ),
			'suitetax'     => $kit->gateway->readable( 'record/v1/salesTaxItem' ),
		);
		$needed      = array( 'sales_orders', 'customers', 'items' );
		if ( 'order_invoice' === $kit->options->documents() ) {
			$needed[] = 'invoices';
		}
		$missing = array_values( array_filter( $needed, static fn( string $name ): bool => ! $permissions[ $name ] ) );
		$error   = array() === $missing ? null : new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => self::SYSTEM,
				'action' => __( 'read sales orders, customers and items', 'estasoft-erp-crm-sync-engine' ),
			),
			403,
			null,
			null,
			'missing: ' . implode( ',', $missing )
		);
		return new TestResult(
			null === $error,
			__( 'REST record service v1', 'estasoft-erp-crm-sync-engine' ),
			strtoupper( trim( (string) $connection->config( 'account_id', '' ) ) ),
			array(),
			$permissions,
			$error,
			array( 'currencies' => array_keys( $kit->lookups->currencies() ) )
		);
	}

	/**
	 * Cheap health probe.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->kit( $connection )->gateway->readable( 'record/v1/salesOrder' );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Target fields for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ( new NetSuiteMappings() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new NetSuiteMappings() )->rules( $mappingType );
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
			$row = $kit->refunds->find( $item->clientReference() );
			if ( null === $row ) {
				return null;
			}
			$error = $kit->refunds->checkMarked( $row, $kit->total );
			return $error ?? $kit->refunds->reference( $row, $kit->lookups->symbolOf( (string) ( $row['currency'] ?? '' ) ) );
		}
		if ( EntityType::Order !== $item->entityType ) {
			return null;
		}
		$record = $kit->orders->find( $item->clientReference() );
		if ( null === $record ) {
			return null;
		}
		$error = $kit->orders->checkMarked( $record, $kit->total );
		if ( null !== $error ) {
			return $error;
		}
		$extra = array();
		if ( 'order_invoice' === $kit->options->documents() ) {
			$invoice = $kit->orders->invoiceOf( (string) $record['id'] );
			if ( null === $invoice ) {
				return null;
			}
			$extra = array( 'invoice_id' => (string) ( $invoice['id'] ?? '' ) );
		}
		return $kit->orders->reference( $record, $kit->orders->currencyCodeOf( $record ), $extra );
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
			default              => SendResult::failure( Problem::validation( self::SYSTEM, 'entity', __( 'This connector does not send this kind of record.', 'estasoft-erp-crm-sync-engine' ) ) ),
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
		$found = $this->kit( $connection )->lookups->customerByEmail( $email );
		return null === $found ? null : new ExternalRef( $found['id'], $found['name'] );
	}

	/**
	 * Find an item by SKU.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On a request failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$found = $this->kit( $connection )->lookups->itemBySku( $sku );
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
		foreach ( $this->kit( $connection )->lookups->searchItems( $query ) as $found ) {
			$list[] = new ExternalRef( $found['id'], $found['ref'], null, array( 'name' => $found['name'] ) );
		}
		return $list;
	}

	/**
	 * Invoice facts for a synced order.
	 *
	 * @param Connection  $connection Connection.
	 * @param ExternalRef $ref        The sales order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|ConnectorError|null
	 *
	 * @throws ApiException On a request failure.
	 */
	public function fetchInvoice( Connection $connection, ExternalRef $ref ): array|ConnectorError|null {
		return $this->kit( $connection )->reader->invoice( $ref->id );
	}

	/**
	 * The shop's sales orders since a date, for the nightly check.
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
	 * NetSuite works out tax itself (SuiteTax), so there are no tax codes to map.
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
		$parsed = ( new OrderParser( self::SYSTEM, $kit->amounts ) )->parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		if ( $parsed->pricesIncludeTax ) {
			return SendResult::failure( Problem::validation( self::SYSTEM, 'prices_include_tax', __( 'NetSuite takes prices before tax. Map the prices without tax.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$warnings = new Warnings();
		$record   = $kit->orders->find( $parsed->reference );
		$existed  = null !== $record;
		$record   = $record ?? $kit->orders->create( $parsed, $warnings );
		$error    = $kit->orders->check( $record, $parsed->currency, $parsed->totalMinor, $kit->total, $warnings );
		if ( null !== $error ) {
			return SendResult::failure( $error );
		}
		$extra = array();
		if ( 'order_invoice' === $kit->options->documents() ) {
			$invoice = $kit->orders->ensureInvoice( (string) $record['id'], $parsed->orderDate );
			$error   = $kit->orders->check( $invoice, $parsed->currency, $parsed->totalMinor, $kit->total, $warnings );
			if ( null !== $error ) {
				return SendResult::failure( $error );
			}
			$extra = array(
				'invoice_id'     => (string) ( $invoice['id'] ?? '' ),
				'invoice_number' => (string) ( $invoice['tranId'] ?? ( $invoice['tranid'] ?? '' ) ),
			);
		}
		if ( $warnings->any() ) {
			$extra['warnings'] = $warnings->all();
		}
		$ref = $kit->orders->reference( $record, $parsed->currency, $extra );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Send a refund as a credit memo.
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
		$row      = $kit->refunds->find( $refund->reference );
		$existed  = null !== $row;
		$warnings = new Warnings();
		$row      = $row ?? $kit->refunds->create( $refund, $warnings );
		$currency = $kit->lookups->symbolOf( (string) ( $row['currency'] ?? '' ) );
		$error    = $kit->refunds->check( $row, $expected, $currency, $kit->total, $warnings );
		if ( null !== $error ) {
			if ( ! $existed && 'fail' === $kit->options->totalMismatch() ) {
				$removed = $kit->refunds->remove( (string) ( $row['id'] ?? '' ) );
				$error   = Problem::validation(
					self::SYSTEM,
					'credit memo total',
					(string) $error->context['reason'] . ' ' . ( $removed ? __( 'The credit memo was removed. Credit the customer in NetSuite.', 'estasoft-erp-crm-sync-engine' ) : __( 'The credit memo could not be removed and stays in NetSuite; correct or delete it there.', 'estasoft-erp-crm-sync-engine' ) )
				);
			}
			return SendResult::failure( $error );
		}
		$ref = $kit->refunds->reference( $row, $currency );
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
		$customer = $kit->customers->ensure( $partner, '', new Warnings() );
		$ref      = new ExternalRef( $customer['id'], $customer['name'] );
		return $customer['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The helpers for a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the account id is refused.
	 */
	private function kit( Connection $connection ): NetSuiteKit {
		return new NetSuiteKit( $this->api( $connection ), NetSuiteOptions::from( $connection ), self::SYSTEM, $this->hostLabel( $connection ) );
	}

	/**
	 * Whether items can be read.
	 *
	 * @param NetSuiteKit $kit Helpers.
	 *
	 * @throws ApiException On a failure other than a missing permission.
	 */
	private function itemsReadable( NetSuiteKit $kit ): bool {
		try {
			$kit->gateway->rows( 'SELECT id FROM item', array(), 1 );
			return true;
		} catch ( ApiException $exception ) {
			if ( in_array( $exception->status(), array( 400, 403 ), true ) ) {
				return false;
			}
			throw $exception;
		}
	}

	/**
	 * The host name of the account: lower case, underscores as hyphens.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the account id is missing or has odd characters.
	 */
	private function host( Connection $connection ): string {
		return $this->hostLabel( $connection ) . '.suitetalk.api.netsuite.com';
	}

	/**
	 * The first label of the host (also used in links to the web application).
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the account id is missing or has odd characters.
	 */
	private function hostLabel( Connection $connection ): string {
		$label = NetSuiteAccount::label( $connection );
		if ( '' === $label ) {
			$problem = Problem::validation( self::SYSTEM, 'account_id', __( 'The account ID may hold letters, digits, hyphens and underscores only (3 to 40 characters).', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return $label;
	}
}
