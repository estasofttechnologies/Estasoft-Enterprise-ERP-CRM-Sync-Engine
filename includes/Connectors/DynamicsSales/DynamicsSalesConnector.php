<?php
/**
 * The Dynamics 365 Sales connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects, not output.

use Estasoft\SyncEngine\Connectors\Microsoft\EntraCredentialFields;
use Estasoft\SyncEngine\Connectors\Microsoft\EntraTokenProvider;
use Estasoft\SyncEngine\Connectors\Microsoft\EnvironmentUrl;
use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\MicrosoftErrors;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
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
 * Connects the engine to the Dataverse Web API of a Dynamics 365 Sales environment: an order becomes a deal (account, contact, opportunity
 * with its products), a refund becomes a note on the deal, a buyer becomes a contact. The Entra app signs in with client credentials as an
 * application user, so nobody signs in on a page. See docs/connectors/dynamics-sales.md.
 */
final class DynamicsSalesConnector extends AbstractApiConnector {

	/**
	 * Version of the Web API.
	 */
	private const API_PATH = '/api/data/v9.2';

	/**
	 * Identity, flags, sign-in style, limits and hosts.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'dynamics-sales',
			label: 'Dynamics 365 Sales',
			type: ConnectorType::Crm,
			capabilities: array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::Reconciliation, Capability::ShadowLookup ),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthClientCredentials,
			regions: array(),
			rateLimit: new RateLimitHints( 4.0, 1, 1200 ),
			hosts: array( '*.dynamics.com' )
		);
	}

	/**
	 * Address of the Web API of the environment.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the environment address is not one of Dynamics 365.
	 */
	protected function baseUrl( Connection $connection ): string {
		return $this->environment( $connection ) . self::API_PATH;
	}

	/**
	 * Token from the Entra v2.0 endpoint of the merchant's tenant, for the environment.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( new EntraTokenProvider( fn( Connection $c ): string => $this->environment( $c ), $this->label() ) );
	}

	/**
	 * Headers every Web API call carries (documented).
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		unset( $connection );
		return array(
			'Accept'           => 'application/json',
			'OData-MaxVersion' => '4.0',
			'OData-Version'    => '4.0',
			'If-None-Match'    => 'null',
		);
	}

	/**
	 * The connect form: the environment, the Entra app and the options.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$choice = static fn( string $name, string $label, array $options ): CredentialField => EntraCredentialFields::option( $name, $label, $options, (string) array_key_first( $options ) );
		return array_merge(
			array(
				new CredentialField( 'environment_url', __( 'Environment URL', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'The address of your Dynamics 365 environment, for example https://contoso.crm4.dynamics.com.', 'estasoft-erp-crm-sync-engine' ), '', 'url' ),
			),
			EntraCredentialFields::app(),
			array(
				$choice(
					'customer_policy',
					__( 'Contacts', 'estasoft-erp-crm-sync-engine' ),
					array(
						'match_or_create' => __( 'Match by email, create when missing', 'estasoft-erp-crm-sync-engine' ),
						'match_only'      => __( 'Match by email only', 'estasoft-erp-crm-sync-engine' ),
					)
				),
				$choice(
					'create_accounts',
					__( 'Accounts for company buyers', 'estasoft-erp-crm-sync-engine' ),
					array(
						'yes' => __( 'Create an account', 'estasoft-erp-crm-sync-engine' ),
						'no'  => __( 'Contact only', 'estasoft-erp-crm-sync-engine' ),
					)
				),
				$choice(
					'unknown_sku',
					__( 'SKU not found as a product', 'estasoft-erp-crm-sync-engine' ),
					array(
						'refuse'   => __( 'Refuse the order', 'estasoft-erp-crm-sync-engine' ),
						'write_in' => __( 'Add a write-in line', 'estasoft-erp-crm-sync-engine' ),
					)
				),
				$choice(
					'total_mismatch',
					__( 'Total differs from the shop', 'estasoft-erp-crm-sync-engine' ),
					array(
						'fail' => __( 'Stop and show an error', 'estasoft-erp-crm-sync-engine' ),
						'warn' => __( 'Go on with a warning', 'estasoft-erp-crm-sync-engine' ),
					)
				),
				EntraCredentialFields::text( 'reference_column', __( 'Order reference column', 'estasoft-erp-crm-sync-engine' ), __( 'Optional: the logical name of a text column on Opportunity that holds the order number, so a renamed topic is still found.', 'estasoft-erp-crm-sync-engine' ) ),
				EntraCredentialFields::text( 'price_list', __( 'Price list', 'estasoft-erp-crm-sync-engine' ), __( 'Optional: the name of a price list to put on every deal.', 'estasoft-erp-crm-sync-engine' ) ),
			)
		);
	}

	/**
	 * Map the Dataverse error shapes the engine's default does not know.
	 *
	 * @param ApiResponse $response A response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		$common = MicrosoftErrors::common( $response, $this->label() );
		if ( null !== $common ) {
			return $common;
		}
		$message = MicrosoftErrors::message( $response );
		if ( 403 === $response->status() ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => $this->label(),
					'action' => $this->actionOf( $message ),
				),
				403,
				null,
				null,
				'privilege denied'
			);
		}
		if ( 404 === $response->status() && 1 === preg_match( '/Resource not found for the segment/i', $message ) ) {
			return Failures::invalid( $this->label(), 'table', __( 'Dynamics 365 Sales does not seem to be installed in this environment (a table was not found).', 'estasoft-erp-crm-sync-engine' ), 'table not found' );
		}
		if ( 412 === $response->status() ) {
			return Failures::invalid( $this->label(), 'record', __( 'Dynamics 365 refused the record (a duplicate or a stale version).', 'estasoft-erp-crm-sync-engine' ), 'precondition failed' );
		}
		return null;
	}

	/**
	 * Test connection: who the app is, the organisation, the version, and what it may read.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On failure.
	 */
	protected function identify( Connection $connection ): TestResult {
		$dv  = new DvClient( $this->api( $connection ), $this->label() );
		$who = $dv->one( 'WhoAmI' );
		if ( null === $who ) {
			throw new ApiException( Failures::invalid( $this->label(), 'environment_url', __( 'The address did not answer like a Dynamics 365 environment.', 'estasoft-erp-crm-sync-engine' ), 'whoami missing' ) );
		}
		$org     = $dv->first( 'organizations', array( '$select' => 'name' ) );
		$version = $dv->one( 'RetrieveVersion' );
		$allowed = array();
		$probes  = array(
			'read_opportunities' => array( 'opportunities', 'opportunityid' ),
			'read_contacts'      => array( 'contacts', 'contactid' ),
			'read_accounts'      => array( 'accounts', 'accountid' ),
			'read_products'      => array( 'products', 'productid' ),
		);
		foreach ( $probes as $name => $probe ) {
			try {
				$dv->first(
					$probe[0],
					array(
						'$top'    => 1,
						'$select' => $probe[1],
					)
				);
				$allowed[ $name ] = true;
			} catch ( ApiException $exception ) {
				if ( 403 !== $exception->status() ) {
					throw $exception;
				}
				$allowed[ $name ] = false;
			}
		}
		$missing = array_keys( array_filter( $allowed, static fn( bool $ok ): bool => ! $ok ) );
		$error   = array() === $missing ? null : new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => $this->label(),
				'action' => __( 'read deals, contacts, accounts and products', 'estasoft-erp-crm-sync-engine' ),
			),
			403,
			null,
			null,
			'missing: ' . implode( ',', $missing )
		);
		$host    = (string) wp_parse_url( $this->environment( $connection ), PHP_URL_HOST );
		return new TestResult(
			null === $error,
			is_string( $version['Version'] ?? null ) && '' !== $version['Version'] ? 'Dataverse ' . $version['Version'] : 'Dataverse',
			is_string( $org['name'] ?? null ) && '' !== $org['name'] ? $org['name'] : $host,
			array(),
			$allowed,
			$error,
			array(
				'api'             => 'v9.2',
				'organization_id' => (string) ( $who['OrganizationId'] ?? '' ),
				'user_id'         => (string) ( $who['UserId'] ?? '' ),
			)
		);
	}

	/**
	 * Cheap health probe: does WhoAmI answer?
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			return null !== ( new DvClient( $this->api( $connection ), $this->label() ) )->one( 'WhoAmI' );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Target fields for the mapping screens.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,\Estasoft\SyncEngine\Connectors\Support\FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ( new DvFieldCatalog() )->fields( $mappingType );
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new DvDefaultMappings() )->rules( $mappingType );
	}

	/**
	 * Look for the finished deal or refund note of an item.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 *
	 * @throws ApiException On failure.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		if ( EntityType::Order === $item->entityType ) {
			return ( new DvOrderSender( $this->client( $connection ), DvOptions::fromConnection( $connection ), $this->label(), $this->environment( $connection ), $this->deps->logger ) )->find( $item );
		}
		if ( EntityType::Refund === $item->entityType ) {
			return ( new DvRefundSender( $this->client( $connection ), DvOptions::fromConnection( $connection ), $this->label() ) )->find( $item );
		}
		return null;
	}

	/**
	 * Send an order, a refund or a customer.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		if ( ! in_array( $item->entityType, array( EntityType::Order, EntityType::Refund, EntityType::Customer ), true ) ) {
			return SendResult::failure( Failures::unsupported( $this->label(), $item->entityType->value ) );
		}
		$problem = match ( $item->entityType ) {
			EntityType::Order  => DvOrderSender::precheck( $payload, $this->label() ),
			EntityType::Refund => DvRefundSender::precheck( $payload, $this->label() ),
			default            => null,
		};
		if ( null !== $problem ) {
			return SendResult::failure( $problem );
		}
		$dv      = $this->client( $connection );
		$options = DvOptions::fromConnection( $connection );
		return match ( $item->entityType ) {
			EntityType::Refund   => ( new DvRefundSender( $dv, $options, $this->label() ) )->send( $item, $payload ),
			EntityType::Customer => $this->sendCustomer( $dv, $payload ),
			default              => ( new DvOrderSender( $dv, $options, $this->label(), $this->environment( $connection ), $this->deps->logger ) )->send( $item, $payload ),
		};
	}

	/**
	 * Find a contact by email, ignoring case.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$parties = new DvParties( $this->client( $connection ), $this->label(), $this->deps->logger );
		$row     = $parties->findContact( $email );
		return null === $row ? null : $parties->contactRef( $row );
	}

	/**
	 * Find a product by its Product ID.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$products = new DvProducts( $this->client( $connection ) );
		$row      = $products->find( $sku );
		return null === $row ? null : $products->ref( $row );
	}

	/**
	 * Search products by Product ID or name.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>
	 *
	 * @throws ApiException On failure.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$products = new DvProducts( $this->client( $connection ) );
		return array_map( fn( array $row ): ExternalRef => $products->ref( $row ), $products->search( $query ) );
	}

	/**
	 * Deals and refunds of a window for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>
	 *
	 * @throws ApiException On failure.
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return ( new DvReader( $this->client( $connection ), DvOptions::fromConnection( $connection ), $this->label() ) )->reconcile( $from );
	}

	/**
	 * Create or find a contact from the customer sync.
	 *
	 * @param DvClient            $dv      Client.
	 * @param array<string,mixed> $payload Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	private function sendCustomer( DvClient $dv, array $payload ): SendResult {
		$partner = array();
		foreach ( is_array( $payload['partner'] ?? null ) ? $payload['partner'] : array() as $key => $value ) {
			if ( is_string( $key ) && ( is_string( $value ) || is_int( $value ) ) ) {
				$partner[ $key ] = trim( (string) $value );
			}
		}
		$name = trim( ( $partner['first_name'] ?? '' ) . ' ' . ( $partner['last_name'] ?? ( $partner['name'] ?? '' ) ) );
		if ( '' === ( $partner['email'] ?? '' ) || '' === $name ) {
			return SendResult::failure( Failures::invalid( $this->label(), 'partner.email', __( 'A contact needs a name and an email address.', 'estasoft-erp-crm-sync-engine' ), 'customer incomplete' ) );
		}
		$parties = new DvParties( $dv, $this->label(), $this->deps->logger );
		$found   = $parties->findContact( $partner['email'] );
		if ( null !== $found ) {
			return SendResult::existing( $parties->contactRef( $found ) );
		}
		return SendResult::created( $parties->contactRef( $parties->createContact( $partner, $name, null ) ) );
	}

	/**
	 * A client for a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the environment address is refused.
	 */
	private function client( Connection $connection ): DvClient {
		return new DvClient( $this->api( $connection ), $this->label() );
	}

	/**
	 * The clean environment address of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when it is not an address of a Dynamics 365 environment.
	 */
	private function environment( Connection $connection ): string {
		$url = EnvironmentUrl::normalise( (string) $connection->config( 'environment_url', '' ) );
		if ( null === $url ) {
			throw new ApiException( Failures::invalid( $this->label(), 'environment_url', __( 'The environment URL must be an https address ending in dynamics.com, for example https://contoso.crm4.dynamics.com.', 'estasoft-erp-crm-sync-engine' ), 'environment refused' ) );
		}
		return $url;
	}

	/**
	 * What a refused privilege was for, from the message ("is missing prvCreateOpportunity privilege").
	 *
	 * @param string $message Message of the error.
	 */
	private function actionOf( string $message ): string {
		if ( 1 === preg_match( '/prv(AppendTo|Append|Create|Read|Write|Delete)([A-Za-z0-9_]+)/', $message, $found ) ) {
			$verb = array(
				'AppendTo' => 'attach records to',
				'Append'   => 'attach records to',
				'Create'   => 'create',
				'Read'     => 'read',
				'Write'    => 'change',
				'Delete'   => 'delete',
			)[ $found[1] ];
			return $verb . ' ' . strtolower( $found[2] ) . ' records';
		}
		return __( 'do this', 'estasoft-erp-crm-sync-engine' );
	}
}
