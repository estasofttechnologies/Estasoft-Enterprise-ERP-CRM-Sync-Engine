<?php
/**
 * The Sage 300 connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\ErpCommon\FieldSets;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\BasicAuth;
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
 * Orders as Order Entry orders on one customer account, refunds as Order Entry credit notes, the nightly list from the orders. The Web API
 * is stateless (Basic authentication on every request): there is no session to open or close. See docs/connectors/sage300.md for every
 * property and what is not verified.
 */
final class Sage300Connector extends AbstractApiConnector {

	/**
	 * Name shown in messages.
	 */
	public const LABEL = 'Sage 300';

	/**
	 * Resources that Test connection reads one row of, to prove the names and the rights.
	 *
	 * @var array<int,string>
	 */
	private const PROBES = array( 'OE/OEOrders', 'OE/OECreditDebitNotes', 'AR/ARCustomers', 'IC/ICItems', 'TX/TXTaxGroups' );

	/**
	 * Identity, flags, sign-in style and limits.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'sage300',
			label: self::LABEL,
			type: ConnectorType::Erp,
			capabilities: array(
				Capability::Orders,
				Capability::Refunds,
				Capability::ShadowLookup,
			),
			entities: array( EntityType::Order, EntityType::Refund ),
			auth: AuthStyle::ServerLogin,
			regions: array(),
			rateLimit: new RateLimitHints( 2.0, 1 ),
			hosts: array(),
			merchantHost: true
		);
	}

	/**
	 * The Web API address with its version and company.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is not a web address.
	 */
	protected function baseUrl( Connection $connection ): string {
		return Sage300Site::base( $connection, self::LABEL );
	}

	/**
	 * HTTP Basic on every request.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new BasicAuth( 'username', 'password' );
	}

	/**
	 * The Web API answers HTTP 500 to a request without a JSON content type.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		unset( $connection );
		return array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
	}

	/**
	 * Map the error body of the Web API.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new Sage300ErrorMapper() )->map( $response, self::LABEL );
	}

	/**
	 * The company ID and the user name are removed from every message besides the secrets.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		return array_values( array_filter( array_merge( parent::redactionValues( $connection ), array( Sage300Site::company( $connection ), trim( (string) $connection->config( 'username', '' ) ) ) ) ) );
	}

	/**
	 * Connect form: address, company, user and password, then the settings.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$advanced = static fn( string $name, string $label, string $hint = '', string $type = 'text', array $options = array(), string $initial = '' ): CredentialField => new CredentialField( $name, $label, false, false, $hint, $initial, $type, $options, 'advanced' );
		return array(
			CredentialFields::address( 'address', __( 'Web API address', 'estasoft-erp-crm-sync-engine' ), __( 'The https address of the Sage 300 Web API, for example https://sage.example.com/Sage300WebApi', 'estasoft-erp-crm-sync-engine' ) ),
			new CredentialField( 'company', __( 'Company ID', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'The company (database) ID, for example SAMLTD.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::username( 'username', __( 'Sage 300 user', 'estasoft-erp-crm-sync-engine' ), __( 'A user whose security group has the Web API, Order Entry and Accounts Receivable rights.', 'estasoft-erp-crm-sync-engine' ) ),
			CredentialFields::password( 'password', __( 'Password', 'estasoft-erp-crm-sync-engine' ), __( 'Saved encrypted. It is sent with every request, so use an https address.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'customer_policy',
				__( 'Customers', 'estasoft-erp-crm-sync-engine' ),
				'',
				'select',
				array(
					'fixed'      => __( 'Put every order on one customer account', 'estasoft-erp-crm-sync-engine' ),
					'match_only' => __( 'Match the buyer by email', 'estasoft-erp-crm-sync-engine' ),
				),
				'fixed'
			),
			$advanced( 'customer_number', __( 'Customer number for web orders', 'estasoft-erp-crm-sync-engine' ), __( 'Needed for the one-account setting.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'location', __( 'Inventory location', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'shipping_charge_code', __( 'Miscellaneous charge code for shipping', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'fee_charge_code', __( 'Miscellaneous charge code for fees', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'discount_charge_code', __( 'Miscellaneous charge code for discounts', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'refund_charge_code', __( 'Miscellaneous charge code for refunds without lines', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'reference_property', __( 'Order property for the shop order number', 'estasoft-erp-crm-sync-engine' ), __( 'Empty: PONumber, else Reference.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'total_property', __( 'Order property that holds the total', 'estasoft-erp-crm-sync-engine' ), __( 'Empty: the first of OrderTotal, TotalOrderAmount, OrderTotalAmount that the answer has.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced( 'refund_total_property', __( 'Credit note property that holds the total', 'estasoft-erp-crm-sync-engine' ), __( 'Needed to send refunds, so a credit note can be compared with the refund; or choose to continue with a warning below.', 'estasoft-erp-crm-sync-engine' ) ),
			$advanced(
				'total_mismatch',
				__( 'When Sage 300 totals differ from the shop', 'estasoft-erp-crm-sync-engine' ),
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
	 * Test connection: the rights on every resource, the property names, the customer, the locations and the tax groups.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On failure.
	 */
	protected function identify( Connection $connection ): TestResult {
		$client      = $this->client( $connection );
		$permissions = array();
		$refused     = array();
		foreach ( self::PROBES as $path ) {
			try {
				$client->first( $path );
				$permissions[ 'read ' . $path ] = true;
			} catch ( ApiException $exception ) {
				if ( 403 === $exception->status() || ErrorCode::Auth004 === $exception->error()->code ) {
					$permissions[ 'read ' . $path ] = false;
				} elseif ( ErrorCode::Val002 === $exception->error()->code && 'address' !== ( $exception->error()->context['field'] ?? '' ) ) {
					$permissions[ 'read ' . $path ] = false;
					$refused[]                      = $path;
				} else {
					throw $exception;
				}
			}
		}
		$label = 'Sage 300 Web API v1.0';
		if ( array() !== $refused ) {
			return new TestResult(
				false,
				$label,
				Sage300Site::company( $connection ),
				array(),
				$permissions,
				Problems::refused(
					self::LABEL,
					'company',
					sprintf(
						/* translators: %s: resource names. */
						__( 'The Web API did not answer for: %s. Check the company ID and that this Sage 300 offers these resources.', 'estasoft-erp-crm-sync-engine' ),
						implode( ', ', $refused )
					)
				)
			);
		}
		$orders   = $this->orders( $connection );
		$property = $orders->referenceProperty();
		if ( $property instanceof ConnectorError ) {
			return new TestResult( false, $label, Sage300Site::company( $connection ), array(), $permissions, $property );
		}
		$metadata = new Sage300Metadata( $client );
		if ( null !== $metadata->entity( 'OE', 'OEOrders' ) && ! $metadata->has( 'OE', 'OEOrders', 'UnitPrice', 'OrderDetails' ) ) {
			return new TestResult( false, $label, Sage300Site::company( $connection ), array(), $permissions, Problems::refused( self::LABEL, 'order_line[].price_unit', __( 'The Order Entry order lines of this Sage 300 have no UnitPrice property, so the shop price cannot be sent.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$options  = Options::of( $connection );
		$lookups  = new Sage300Lookups( $client );
		$customer = null;
		if ( 'match_only' !== $options->choice( 'customer_policy', array( 'fixed', 'match_only' ), 'fixed' ) ) {
			$number = $options->text( 'customer_number' );
			if ( '' === $number ) {
				return new TestResult( false, $label, Sage300Site::company( $connection ), array(), $permissions, Problems::refused( self::LABEL, 'customer_number', __( 'Enter the customer number that should receive the web orders.', 'estasoft-erp-crm-sync-engine' ) ) );
			}
			$customer = $lookups->customer( $number );
			if ( null === $customer ) {
				return new TestResult( false, $label, Sage300Site::company( $connection ), array(), $permissions, Problems::refused( self::LABEL, 'customer_number', __( 'Sage 300 has no customer with this number.', 'estasoft-erp-crm-sync-engine' ) ) );
			}
		}
		$locations = $permissions['read IC/ICItems'] ? $this->locationsOrNone( $lookups ) : array();
		$groups    = $permissions['read TX/TXTaxGroups'] ? array_column( $lookups->taxGroups(), 'id' ) : array();
		return new TestResult(
			true,
			$label,
			Sage300Site::company( $connection ),
			$locations,
			$permissions,
			null,
			array(
				'reference_property' => $property,
				'customer'           => null === $customer ? '' : $customer['id'],
				'customer_currency'  => null === $customer ? '' : $customer['currency'],
				'tax_groups'         => $groups,
				'write_not_probed'   => true,
			)
		);
	}

	/**
	 * The locations, or an empty list when the user may not read them.
	 *
	 * @param Sage300Lookups $lookups Lookups.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On failure other than a refused right.
	 */
	private function locationsOrNone( Sage300Lookups $lookups ): array {
		try {
			return $lookups->locations();
		} catch ( ApiException $exception ) {
			if ( 403 === $exception->status() || ErrorCode::Auth004 === $exception->error()->code || ErrorCode::Val002 === $exception->error()->code ) {
				return array();
			}
			throw $exception;
		}
	}

	/**
	 * Cheap health probe: can an order list still be read?
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$this->client( $connection )->first( 'AR/ARCustomers' );
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
			'orders'   => FieldSets::orders( self::LABEL ),
			'products' => FieldSets::products( __( 'Item number', 'estasoft-erp-crm-sync-engine' ) ),
			'refunds'  => FieldSets::refunds( self::LABEL, __( 'Credit note', 'estasoft-erp-crm-sync-engine' ) ),
			default    => array(),
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
			'orders'   => DefaultRules::orders(),
			'products' => DefaultRules::products(),
			'refunds'  => DefaultRules::refunds(),
			default    => array(),
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
	 * Send an order or a refund.
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
			$buyer  = 'match_only' === Options::of( $connection )->choice( 'customer_policy', array( 'fixed', 'match_only' ), 'fixed' );
			$parsed = OrderPayload::parse( $payload, $item, self::LABEL, $amounts, $buyer );
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
		return SendResult::failure( Problems::refused( self::LABEL, 'entity', __( 'This kind of item cannot be sent to Sage 300.', 'estasoft-erp-crm-sync-engine' ), 'unsupported entity' ) );
	}

	/**
	 * Find a customer by email, when the customers have an email property.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		$email    = trim( $email );
		$client   = $this->client( $connection );
		$property = '' === $email ? '' : ( new Sage300Metadata( $client ) )->containing( 'AR', 'ARCustomers', 'Email' );
		if ( '' === $property ) {
			return null;
		}
		$row = $client->first( 'AR/ARCustomers', $property . ' eq ' . Sage300Client::literal( $email ) );
		if ( null === $row || ! isset( $row['CustomerNumber'] ) || ! is_scalar( $row['CustomerNumber'] ) ) {
			return null;
		}
		return new ExternalRef( trim( (string) $row['CustomerNumber'] ), trim( (string) ( $row['CustomerName'] ?? $row['CustomerNumber'] ) ), null, array( 'email' => $email ) );
	}

	/**
	 * Find an item by its item number (the SKU).
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
		$found = ( new Sage300Lookups( $this->client( $connection ) ) )->items( array( $sku ) )[ strtolower( $sku ) ] ?? null;
		return null === $found ? null : new ExternalRef( $found['id'], $found['id'], null, array( 'unit' => $found['unit'] ) );
	}

	/**
	 * Search items by item number; the key lookup when the server refuses the filter.
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
		try {
			$rows = $client->rows( 'IC/ICItems', 'substringof(' . Sage300Client::literal( $query ) . ',ItemNumber)', 20 );
		} catch ( ApiException $exception ) {
			if ( ErrorCode::Val002 !== $exception->error()->code ) {
				throw $exception;
			}
			$one = $this->lookupProduct( $connection, $query );
			return $one instanceof ExternalRef ? array( $one ) : array();
		}
		$found = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['ItemNumber'] ) && is_scalar( $row['ItemNumber'] ) ) {
				$id      = trim( (string) $row['ItemNumber'] );
				$found[] = new ExternalRef( $id, $id, null, array( 'name' => trim( (string) ( $row['Description'] ?? '' ) ) ) );
			}
		}
		return $found;
	}

	/**
	 * Tax groups for the tax mapping screen. The rate is not in the group record, so it is not known here.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		$codes = array();
		foreach ( ( new Sage300Lookups( $this->client( $connection ) ) )->taxGroups() as $group ) {
			$codes[] = array(
				'code'         => $group['id'],
				'name'         => $group['name'] . ' (' . __( 'rate set by the tax group', 'estasoft-erp-crm-sync-engine' ) . ')',
				'rate_percent' => '0',
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
	private function client( Connection $connection ): Sage300Client {
		return new Sage300Client( $this->api( $connection ), self::LABEL );
	}

	/**
	 * The order sender of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function orders( Connection $connection ): Sage300OrderSender {
		$client = $this->client( $connection );
		return new Sage300OrderSender( $client, new Sage300Metadata( $client ), new Sage300Lookups( $client ), Options::of( $connection ), new Amounts(), self::LABEL );
	}

	/**
	 * The refund sender of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function refunds( Connection $connection ): Sage300RefundSender {
		$client = $this->client( $connection );
		return new Sage300RefundSender( $client, Options::of( $connection ), new Amounts(), new Sage300Lookups( $client ), $this->orders( $connection ), self::LABEL );
	}
}
