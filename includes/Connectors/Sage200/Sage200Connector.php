<?php
/**
 * The Sage 200 connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\ErpCommon\FieldSets;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
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
 * Orders as Sales Order Processing orders, refunds as sales returns, customers with their contacts; the nightly list from the orders.
 * The documented parts of the Sage 200 API are the sign-in and the headers; resource and field names are third-party and probed when
 * connecting. See docs/connectors/sage200.md for every field and what is not verified.
 */
final class Sage200Connector extends AbstractApiConnector implements OAuthConnector {

	/**
	 * Name shown in messages.
	 */
	public const LABEL = 'Sage 200';

	/**
	 * Resources that Test connection reads one row of, to prove the names.
	 *
	 * @var array<int,string>
	 */
	private const PROBES = array( 'tax_codes', 'customers', 'customer_contacts', 'products', 'sop_orders', 'sop_returns' );

	/**
	 * Identity, flags, sign-in style and limits.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'sage200',
			label: self::LABEL,
			type: ConnectorType::Erp,
			capabilities: array(
				Capability::Orders,
				Capability::Customers,
				Capability::Refunds,
				Capability::Reconciliation,
				Capability::ShadowLookup,
			),
			entities: array( EntityType::Order, EntityType::Refund, EntityType::Customer ),
			auth: AuthStyle::OAuthAuthorizationCode,
			regions: array( 'GB' ),
			rateLimit: new RateLimitHints( 2.0, 1 ),
			hosts: array( 'id.sage.com', Sage200Site::HOST )
		);
	}

	/**
	 * The API address of the edition.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		return Sage200Site::base( $connection );
	}

	/**
	 * Bearer tokens of the merchant's own Sage ID client.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new OAuth2Auth( $this->oauthProvider() );
	}

	/**
	 * The subscription key and the site and company that pin every call.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		$headers = array( 'ocp-apim-subscription-key' => $connection->secret( 'subscription_key' ) );
		if ( '' !== Sage200Site::siteId( $connection ) ) {
			$headers['X-Site'] = Sage200Site::siteId( $connection );
		}
		if ( '' !== Sage200Site::companyId( $connection ) ) {
			$headers['X-Company'] = Sage200Site::companyId( $connection );
		}
		return array_filter( $headers, static fn( string $value ): bool => '' !== $value );
	}

	/**
	 * Map the error body of the Sage 200 API.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new Sage200ErrorMapper() )->map( $response, self::LABEL );
	}

	/**
	 * The site and company ids are removed from every message besides the secrets.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		return array_values( array_filter( array_merge( parent::redactionValues( $connection ), array( Sage200Site::siteId( $connection ), Sage200Site::companyId( $connection ) ) ) ) );
	}

	/**
	 * The sign-in settings (the provider is a small class of its own).
	 */
	public function oauthProvider(): OAuthProvider {
		return new Sage200OAuth();
	}

	/**
	 * The sites and companies the signed-in person can reach.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function organisations( Connection $connection ): array {
		$list = array();
		foreach ( $this->client( $connection )->rows( 'sites', '', 200 ) as $row ) {
			if ( ! isset( $row['site_id'], $row['company_id'] ) || ! is_scalar( $row['site_id'] ) || ! is_scalar( $row['company_id'] ) ) {
				continue;
			}
			$company = isset( $row['company_name'] ) && is_scalar( $row['company_name'] ) ? (string) $row['company_name'] : (string) $row['company_id'];
			$site    = isset( $row['site_name'] ) && is_scalar( $row['site_name'] ) ? (string) $row['site_name'] : (string) $row['site_id'];
			$list[]  = array(
				'id'   => $row['site_id'] . '/' . $row['company_id'],
				'name' => $company . ' (' . $site . ')',
			);
		}
		return $list;
	}

	/**
	 * Remember the chosen site and company.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         The id from organisations().
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function selectOrganisation( Connection $connection, string $id ): array {
		$parts  = explode( '/', $id, 2 );
		$config = array(
			'site_id'    => $parts[0],
			'company_id' => $parts[1] ?? '',
		);
		foreach ( $this->organisations( $connection ) as $organisation ) {
			if ( $organisation['id'] === $id ) {
				$config['company_name'] = $organisation['name'];
			}
		}
		return $config;
	}

	/**
	 * Connect form: the app, the subscription key, the edition, then the settings.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$advanced = static fn( string $name, string $label, string $hint = '', string $type = 'text', array $options = array(), string $initial = '' ): CredentialField => new CredentialField( $name, $label, false, false, $hint, $initial, $type, $options, 'advanced' );
		return array(
			CredentialFields::clientId( 'client_id', __( 'The Client ID Sage sent for your application.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::clientSecret( 'client_secret', __( 'The Client Secret Sage sent. Saved encrypted.', 'estasoft-erp-crm-sync-engine' ) ),
			new CredentialField( 'subscription_key', __( 'Subscription key', 'estasoft-erp-crm-sync-engine' ), true, true, __( 'The subscription key Sage sent with the credentials. Saved encrypted.', 'estasoft-erp-crm-sync-engine' ), '', 'password', array(), 'app' ),
			CredentialFields::choice(
				'edition',
				__( 'Edition', 'estasoft-erp-crm-sync-engine' ),
				array(
					'standard'     => __( 'Sage 200 Standard', 'estasoft-erp-crm-sync-engine' ),
					'professional' => __( 'Sage 200 Professional', 'estasoft-erp-crm-sync-engine' ),
				),
				'standard'
			),
			$advanced( 'base_currency', __( 'Base currency of the company', 'estasoft-erp-crm-sync-engine' ), __( 'Orders are sent only in this currency. Empty: GBP.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'customer_policy',
				__( 'Customers', 'estasoft-erp-crm-sync-engine' ),
				'',
				'select',
				array(
					'match_or_create' => __( 'Match by email, create when missing', 'estasoft-erp-crm-sync-engine' ),
					'match_only'      => __( 'Match by email only', 'estasoft-erp-crm-sync-engine' ),
				),
				'match_or_create'
			),
			$advanced( 'customer_reference_prefix', __( 'Account reference prefix for new customers', 'estasoft-erp-crm-sync-engine' ), __( 'Up to three letters. Empty: WEB.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'default_tax_code', __( 'Tax code for new customers', 'estasoft-erp-crm-sync-engine' ), __( 'The tax code number.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'country_code_id', __( 'Country code id for new customers', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'shipping_product', __( 'Product code for shipping', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'fee_product', __( 'Product code for fees', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'discount_product', __( 'Product code for discounts', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'refund_product', __( 'Product code for refunds without lines', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'total_mismatch',
				__( 'When Sage 200 totals differ from the shop', 'estasoft-erp-crm-sync-engine' ),
				'',
				'select',
				array(
					'fail' => __( 'Stop and report', 'estasoft-erp-crm-sync-engine' ),
					'warn' => __( 'Continue with a warning', 'estasoft-erp-crm-sync-engine' ),
				),
				'fail'
			),
		);
	}

	/**
	 * Test connection: the site and company, every resource the connector uses, the tax codes.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On failure.
	 */
	protected function identify( Connection $connection ): TestResult {
		$edition = 'professional' === Sage200Site::edition( $connection ) ? 'Sage 200 Professional' : 'Sage 200 Standard';
		if ( '' === Sage200Site::siteId( $connection ) || '' === Sage200Site::companyId( $connection ) ) {
			return new TestResult( false, $edition, '', array(), array(), Problems::refused( self::LABEL, 'site_id', __( 'No site and company are chosen yet. Sign in with Sage and choose one.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$client      = $this->client( $connection );
		$permissions = array();
		$refused     = array();
		foreach ( self::PROBES as $endpoint ) {
			try {
				$client->first( $endpoint );
				$permissions[ 'read ' . $endpoint ] = true;
			} catch ( ApiException $exception ) {
				if ( 'site_id' === ( $exception->error()->context['field'] ?? '' ) ) {
					throw $exception;
				}
				if ( 403 === $exception->status() ) {
					$permissions[ 'read ' . $endpoint ] = false;
				} elseif ( in_array( (int) $exception->status(), array( 400, 404, 422 ), true ) || ErrorCode::Val002 === $exception->error()->code ) {
					$permissions[ 'read ' . $endpoint ] = false;
					$refused[]                          = $endpoint;
				} else {
					throw $exception;
				}
			}
		}
		if ( array() !== $refused ) {
			return new TestResult(
				false,
				$edition,
				'',
				array(),
				$permissions,
				Problems::refused(
					self::LABEL,
					'edition',
					sprintf(
						/* translators: %s: resource names. */
						__( 'Sage 200 did not answer for: %s. Check the edition, the site and company chosen, and that the user may open Sales Order Processing.', 'estasoft-erp-crm-sync-engine' ),
						implode( ', ', $refused )
					)
				)
			);
		}
		$company = $this->companyName( $connection, $client );
		$codes   = $permissions['read tax_codes'] ? array_column( ( new Sage200Lookups( $client ) )->taxCodes(), 'code' ) : array();
		return new TestResult(
			true,
			$edition,
			$company,
			array(),
			$permissions,
			null,
			array(
				'site_id'          => Sage200Site::siteId( $connection ),
				'company_id'       => Sage200Site::companyId( $connection ),
				'tax_codes'        => $codes,
				'base_currency'    => $this->orders( $connection )->baseCurrency(),
				'write_not_probed' => true,
			)
		);
	}

	/**
	 * The company name: the one saved at sign-in, else the name Sage lists for the chosen company.
	 *
	 * @param Connection    $connection Connection.
	 * @param Sage200Client $client     Client.
	 *
	 * @throws ApiException On failure.
	 */
	private function companyName( Connection $connection, Sage200Client $client ): string {
		$saved = trim( (string) $connection->config( 'company_name', '' ) );
		if ( '' !== $saved ) {
			return $saved;
		}
		foreach ( $client->rows( 'sites', '', 200 ) as $row ) {
			if ( (string) ( $row['company_id'] ?? '' ) === Sage200Site::companyId( $connection ) && isset( $row['company_name'] ) && is_scalar( $row['company_name'] ) ) {
				return (string) $row['company_name'];
			}
		}
		return Sage200Site::companyId( $connection );
	}

	/**
	 * Cheap health probe: can a tax code list still be read?
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->client( $connection )->first( 'tax_codes' );
			return true;
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
		return match ( $mappingType ) {
			'orders'    => FieldSets::orders( self::LABEL ),
			'customers' => FieldSets::customers(),
			'products'  => FieldSets::products( __( 'Product code', 'estasoft-erp-crm-sync-engine' ) ),
			'refunds'   => FieldSets::refunds( self::LABEL, __( 'Sales return', 'estasoft-erp-crm-sync-engine' ) ),
			default     => array(),
		};
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock, tracking.
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => DefaultRules::orders(),
			'customers' => DefaultRules::customers(),
			'products'  => DefaultRules::products(),
			'refunds'   => DefaultRules::refunds(),
			default     => array(),
		};
	}

	/**
	 * Look for the record of an item that its own send finished.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item being sent.
	 *
	 * @throws ApiException On failure.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		if ( EntityType::Order === $item->entityType ) {
			return $this->orders( $connection )->finished( $item );
		}
		if ( EntityType::Refund === $item->entityType ) {
			return $this->refunds( $connection )->finished( $item );
		}
		return null;
	}

	/**
	 * Send an order, a refund or a customer.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item being sent.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$amounts = new Amounts();
		if ( EntityType::Order === $item->entityType ) {
			$parsed = OrderPayload::parse( $payload, $item, self::LABEL, $amounts );
			if ( $parsed instanceof ConnectorError ) {
				return SendResult::failure( $parsed );
			}
			return $this->orders( $connection )->send( $item, $parsed );
		}
		if ( EntityType::Refund === $item->entityType ) {
			$parsed = RefundPayload::parse( $payload, $item, self::LABEL, $amounts );
			if ( $parsed instanceof ConnectorError ) {
				return SendResult::failure( $parsed );
			}
			return $this->refunds( $connection )->send( $item, $parsed );
		}
		if ( EntityType::Customer === $item->entityType ) {
			return $this->sendCustomer( $connection, $payload, $item );
		}
		return SendResult::failure( Problems::refused( self::LABEL, 'entity', __( 'This kind of item cannot be sent to Sage 200.', 'estasoft-erp-crm-sync-engine' ), 'unsupported entity' ) );
	}

	/**
	 * Find a customer by the email of a contact.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$client = $this->client( $connection );
		$found  = ( new Sage200Customers( $client, new Sage200Lookups( $client ), Options::of( $connection ), self::LABEL ) )->find( $email );
		return null === $found ? null : new ExternalRef( $found['id'], $found['name'], null, array( 'email' => $email ) );
	}

	/**
	 * Find a product by its code (the SKU).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		$found = ( new Sage200Lookups( $this->client( $connection ) ) )->products( array( $sku ) )[ strtolower( $sku ) ] ?? null;
		return null === $found ? null : new ExternalRef( $found['id'], $found['code'] );
	}

	/**
	 * Search products by code or name.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$client = $this->client( $connection );
		$found  = array();
		foreach ( array( 'code', 'name' ) as $field ) {
			foreach ( $client->rows( 'products', 'substringof(' . Sage200Client::literal( $query ) . ',' . $field . ')', 20 ) as $row ) {
				if ( isset( $row['id'], $row['code'] ) && is_scalar( $row['id'] ) && is_scalar( $row['code'] ) ) {
					$found[ (string) $row['id'] ] = new ExternalRef( (string) $row['id'], (string) $row['code'], null, array( 'name' => (string) ( $row['name'] ?? '' ) ) );
				}
			}
		}
		return array_slice( array_values( $found ), 0, 20 );
	}

	/**
	 * Orders for the nightly check.
	 *
	 * @param Connection         $connection Connection.
	 * @param \DateTimeImmutable $from       UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function listForReconcile( Connection $connection, \DateTimeImmutable $from ): array|ConnectorError {
		return ( new Sage200Reader( $this->client( $connection ), $this->orders( $connection ) ) )->reconcile( $from );
	}

	/**
	 * Tax codes for the tax mapping screen.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		$codes = array();
		foreach ( ( new Sage200Lookups( $this->client( $connection ) ) )->taxCodes() as $tax ) {
			$codes[] = array(
				'code'         => $tax['code'],
				'name'         => $tax['name'],
				'rate_percent' => $tax['percent'],
			);
		}
		return $codes;
	}

	/**
	 * The client of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function client( Connection $connection ): Sage200Client {
		return new Sage200Client( $this->api( $connection ), self::LABEL );
	}

	/**
	 * The order sender of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function orders( Connection $connection ): Sage200OrderSender {
		$client  = $this->client( $connection );
		$options = Options::of( $connection );
		$lookups = new Sage200Lookups( $client );
		return new Sage200OrderSender( $client, $options, new Amounts(), $lookups, new Sage200Customers( $client, $lookups, $options, self::LABEL ), self::LABEL );
	}

	/**
	 * The refund sender of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function refunds( Connection $connection ): Sage200RefundSender {
		$client = $this->client( $connection );
		return new Sage200RefundSender( $client, Options::of( $connection ), new Amounts(), new Sage200Lookups( $client ), $this->orders( $connection ), self::LABEL );
	}

	/**
	 * Create or match a customer from the customer sync.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $payload    Mapped payload.
	 * @param Item                $item       The queue item.
	 *
	 * @throws ApiException On failure.
	 */
	private function sendCustomer( Connection $connection, array $payload, Item $item ): SendResult {
		$partner = PayloadReader::address( $payload['partner'] ?? null );
		if ( '' === ( $partner['email'] ?? '' ) ) {
			return SendResult::failure( Problems::missingEmail( $item->entityId ) );
		}
		if ( '' === ( $partner['name'] ?? '' ) && '' === ( $partner['company_name'] ?? '' ) ) {
			return SendResult::failure( Problems::refused( self::LABEL, 'partner.name', __( 'The customer name is empty.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$client   = $this->client( $connection );
		$resolved = ( new Sage200Customers( $client, new Sage200Lookups( $client ), Options::of( $connection ), self::LABEL ) )->resolve( $partner, array(), $item->entityId );
		if ( $resolved instanceof ConnectorError ) {
			return SendResult::failure( $resolved );
		}
		$ref = new ExternalRef( $resolved['id'], $resolved['name'], null, array( 'email' => $partner['email'] ) );
		return $resolved['created'] ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}
}
