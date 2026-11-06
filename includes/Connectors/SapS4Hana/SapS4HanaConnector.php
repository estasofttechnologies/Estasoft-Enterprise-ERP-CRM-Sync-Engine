<?php
/**
 * The SAP S/4HANA connector (OData V2 APIs of the cloud editions, communication arrangement).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

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
 * Orders become sales orders sold to one configured customer, refunds credit memo requests; billing documents are read back. See
 * docs/connectors/sap-s4hana.md for what the connector does not do and why.
 */
final class SapS4HanaConnector extends AbstractApiConnector {

	/**
	 * System name used in messages.
	 */
	public const SYSTEM = 'SAP S/4HANA';

	/**
	 * Identity, flags and limits.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'sap-s4hana',
			label: self::SYSTEM,
			type: ConnectorType::Erp,
			capabilities: array( Capability::Orders, Capability::Refunds, Capability::InvoiceReadback, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund ),
			auth: AuthStyle::OAuthClientCredentials,
			regions: array(),
			rateLimit: new RateLimitHints( requestsPerSecond: 2.0, concurrency: 1 ),
			hosts: array(),
			merchantHost: true
		);
	}

	/**
	 * The base address of the OData services.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the address is not usable.
	 */
	protected function baseUrl( Connection $connection ): string {
		$parts = wp_parse_url( trim( (string) $connection->config( 'address', '' ) ) );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( ! is_array( $parts ) || '' === $host || 1 !== preg_match( '/^[a-z0-9.\-\[\]:]+$/', $host ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'https', 'http' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			$problem = Problem::validation( self::SYSTEM, 'address', __( 'Enter the API address of the tenant, for example https://my300000-api.s4hana.cloud.sap.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$port = isset( $parts['port'] ) && (int) $parts['port'] > 0 && (int) $parts['port'] < 65536 ? ':' . (int) $parts['port'] : '';
		return strtolower( (string) $parts['scheme'] ) . '://' . $host . $port . '/sap/opu/odata/sap';
	}

	/**
	 * Bearer token from the communication arrangement's OAuth client, or the communication user's Basic sign-in.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		return 'basic' === SapS4HanaOptions::from( $connection )->authMethod() ? new BasicAuth( 'user', 'password' ) : new OAuth2Auth( new SapS4HanaOAuth() );
	}

	/**
	 * The connect form.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		return array(
			CredentialFields::address( 'address', __( 'API address of the tenant', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'The host with -api, for example https://my300000-api.s4hana.cloud.sap. Copy it from the communication arrangement.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::choice(
				'auth_method',
				__( 'Sign-in', 'estasoft-enterprise-erpcrm-sync-engine' ),
				array(
					'oauth' => __( 'OAuth 2.0 (client ID and secret)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'basic' => __( 'Communication user and password', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'oauth'
			),
			CredentialFields::clientId( 'client_id', __( 'OAuth: the client ID on the communication arrangement.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::clientSecret( 'client_secret' ),
			new CredentialField( 'token_url', __( 'Token service URL', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'OAuth: shown on the communication arrangement.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'url', array(), 'app' ),
			new CredentialField( 'scope', __( 'Scope', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'OAuth: the scope of the communication arrangement, for example API_SALES_ORDER_SRV_0001.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'app' ),
			CredentialFields::username( 'user', __( 'Communication user', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Basic sign-in only.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::password( 'password', __( 'Password', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Basic sign-in only. Saved encrypted. It is never shown again.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			new CredentialField( 'sales_organization', __( 'Sales organization', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, '' ),
			new CredentialField( 'distribution_channel', __( 'Distribution channel', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, '' ),
			new CredentialField( 'division', __( 'Division', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, '' ),
			new CredentialField( 'sold_to_party', __( 'Sold-to customer number', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, __( 'The customer all web orders are sold to.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
		);
	}

	/**
	 * The S/4HANA error shapes.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new SapS4HanaErrors( self::SYSTEM ) )->map( $response );
	}

	/**
	 * Test connection: read what the connector needs.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the sign-in or a probe fails for a reason other than a missing arrangement.
	 */
	protected function identify( Connection $connection ): TestResult {
		$kit         = $this->kit( $connection );
		$permissions = array(
			'sales_orders'         => $kit->gateway->readable( SapS4HanaOrders::SERVICE, SapS4HanaOrders::ENTITY, 'SalesOrder' ),
			'products'             => $kit->gateway->readable( SapS4HanaReader::PRODUCTS, 'A_Product', 'Product' ),
			'billing_documents'    => $kit->gateway->readable( SapS4HanaReader::BILLING, 'A_BillingDocument', 'BillingDocument' ),
			'credit_memo_requests' => $kit->gateway->readable( SapS4HanaRefunds::SERVICE, SapS4HanaRefunds::ENTITY, 'CreditMemoRequest' ),
		);
		$missing     = array_values( array_filter( array( 'sales_orders', 'products' ), static fn( string $name ): bool => ! $permissions[ $name ] ) );
		$error       = array() === $missing ? null : new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => self::SYSTEM,
				'action' => __( 'read sales orders and products', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			403,
			null,
			null,
			'missing: ' . implode( ',', $missing )
		);
		return new TestResult(
			null === $error,
			__( 'OData V2 APIs', 'estasoft-enterprise-erpcrm-sync-engine' ),
			sprintf(
				/* translators: %s: sales organization. */
				__( 'Sales organization %s', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$kit->options->salesOrganization()
			),
			array(),
			$permissions,
			$error,
			array( 'sold_to_party_set' => '' !== $kit->options->soldToParty() )
		);
	}

	/**
	 * Cheap health probe.
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return $this->kit( $connection )->gateway->readable( SapS4HanaOrders::SERVICE, SapS4HanaOrders::ENTITY, 'SalesOrder' );
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
		return ( new SapS4HanaMappings() )->fields( $mappingType );
	}

	/**
	 * Version 1 rules.
	 *
	 * @param string $mappingType Mapping type.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new SapS4HanaMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the record an item stands for.
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
			$request = $kit->refunds->find( $item->clientReference() );
			return null === $request ? null : $kit->refunds->reference( $request );
		}
		if ( EntityType::Order !== $item->entityType ) {
			return null;
		}
		$order = $kit->orders->find( $item->clientReference() );
		if ( null === $order ) {
			return null;
		}
		$error = $kit->orders->checkMarked( $order, $kit->total );
		return $error ?? $kit->orders->reference( $order, strtoupper( (string) ( $order['TransactionCurrency'] ?? '' ) ) );
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
			default            => SendResult::failure( Problem::validation( self::SYSTEM, 'entity', __( 'This connector does not send this kind of record.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) ),
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
		$this->kit( $connection )->gateway->readable( SapS4HanaReader::PRODUCTS, 'A_Product', 'Product' );
		return null;
	}

	/**
	 * Find a product by number.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On a request failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$found = $this->kit( $connection )->reader->product( $sku );
		return null === $found ? null : new ExternalRef( $found['id'], $found['ref'] );
	}

	/**
	 * Search products.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On a request failure.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		return array_map( static fn( array $found ): ExternalRef => new ExternalRef( $found['id'], $found['ref'] ), $this->kit( $connection )->reader->search( $query ) );
	}

	/**
	 * Billing document facts for a synced order.
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
	 * S/4HANA determines tax itself, so there are no tax codes to map.
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
		$warnings = new Warnings();
		$order    = $kit->orders->find( $parsed->reference );
		$existed  = null !== $order;
		$order    = $order ?? $kit->orders->create( $parsed, $warnings );
		$error    = $kit->orders->check( $order, $parsed, $kit->total, $warnings );
		if ( null !== $error ) {
			if ( $existed ) {
				$error = Problem::validation( self::SYSTEM, 'net amount', $error->context['reason'] . ' ' . __( 'The sales order was found, not made by this pass, so it is not deleted. Correct or delete it in S/4HANA.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			} elseif ( 'fail' === $kit->options->totalMismatch() ) {
				$error = Problem::validation(
					self::SYSTEM,
					'net amount',
					$error->context['reason'] . ' ' . ( $kit->orders->remove( $order ) ? __( 'The sales order was deleted.', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'The sales order could not be deleted and stays in S/4HANA.', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				);
			}
			return SendResult::failure( $error );
		}
		$extra = $warnings->any() ? array( 'warnings' => $warnings->all() ) : array();
		$ref   = $kit->orders->reference( $order, $parsed->currency, $extra );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Send a refund as a credit memo request.
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
		$warnings = new Warnings();
		$request  = $kit->refunds->find( $refund->reference );
		$existed  = null !== $request;
		$request  = $request ?? $kit->refunds->create( $refund );
		$currency = strtoupper( (string) ( $request['OrderCurrency'] ?? ( $request['TransactionCurrency'] ?? '' ) ) );
		$error    = $kit->refunds->check( $request, $refund, $currency, $kit->total, $warnings );
		if ( null !== $error ) {
			if ( $existed ) {
				$error = Problem::validation( self::SYSTEM, 'net amount', $error->context['reason'] . ' ' . __( 'The credit memo request was found, not made by this pass, so it is not deleted. Correct or delete it in S/4HANA.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			} elseif ( 'fail' === $kit->options->totalMismatch() ) {
				$error = Problem::validation(
					self::SYSTEM,
					'net amount',
					$error->context['reason'] . ' ' . ( $kit->refunds->remove( $request ) ? __( 'The credit memo request was deleted.', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'The credit memo request could not be deleted and stays in S/4HANA.', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				);
			}
			return SendResult::failure( $error );
		}
		$ref = $kit->refunds->reference( $request );
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
	private function kit( Connection $connection ): SapS4HanaKit {
		return new SapS4HanaKit( $this->api( $connection ), SapS4HanaOptions::from( $connection ), self::SYSTEM );
	}
}
