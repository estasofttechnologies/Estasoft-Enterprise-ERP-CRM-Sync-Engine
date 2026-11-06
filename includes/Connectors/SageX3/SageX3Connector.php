<?php
/**
 * The Sage X3 connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageX3;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\ErpCommon\FieldSets;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\SiteAddress;
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
use Estasoft\SyncEngine\Domain\Item;

/**
 * Orders as sales orders through a class and representation the merchant's partner exposes, on the REST web services (`/api1/`) with
 * Basic authentication. The connection method of Sage X3 is to be confirmed (docs/connectors/sagex3.md): Online, which accepts OAuth 2
 * only, and SOAP are not built.
 */
final class SageX3Connector extends AbstractApiConnector {

	/**
	 * Name shown in messages.
	 */
	public const LABEL = 'Sage X3';

	/**
	 * Identity, flags, sign-in style and limits.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'sagex3',
			label: self::LABEL,
			type: ConnectorType::Erp,
			capabilities: array(
				Capability::Orders,
			),
			entities: array( EntityType::Order ),
			auth: AuthStyle::Basic,
			regions: array(),
			rateLimit: new RateLimitHints( 1.0, 1 ),
			hosts: array(),
			merchantHost: true
		);
	}

	/**
	 * The address of the folder's REST services.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is not a web address.
	 */
	protected function baseUrl( Connection $connection ): string {
		return SageX3Settings::base( $connection, self::LABEL );
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
	 * JSON in and out.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,string>
	 */
	protected function defaultHeaders( Connection $connection ): array {
		unset( $connection );
		return array( 'Accept' => 'application/json' );
	}

	/**
	 * Map the error body of the web services.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	protected function vendorError( ApiResponse $response ): ?ConnectorError {
		return ( new SageX3ErrorMapper() )->map( $response, self::LABEL );
	}

	/**
	 * The folder and the user name are removed from every message besides the secrets.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,string>
	 */
	protected function redactionValues( Connection $connection ): array {
		return array_values( array_filter( array_merge( parent::redactionValues( $connection ), array( trim( (string) $connection->config( 'folder', '' ) ), trim( (string) $connection->config( 'username', '' ) ) ) ) ) );
	}

	/**
	 * Connect form: address, folder, user and password, then the names of the class and its properties.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function credentialDefinition(): array {
		$advanced = static fn( string $name, string $label, string $hint = '' ): CredentialField => new CredentialField( $name, $label, false, false, $hint, '', 'text', array(), 'advanced' );
		$fields   = array(
			CredentialFields::address( 'address', __( 'Web service address', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'The https address of the Sage X3 server, for example https://x3.example.com:8443', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			new CredentialField( 'folder', __( 'Folder (endpoint)', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, __( 'The name of the Sage X3 folder, for example SEED.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::username( 'username', __( 'Syracuse user', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'A user that logs in with Basic authentication and is mapped to a Sage X3 user who may create sales orders.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			CredentialFields::password( 'password', __( 'Password', 'estasoft-enterprise-erpcrm-sync-engine' ), __( 'Saved encrypted. It is sent with every request, so use an https address.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			new CredentialField( 'customer_code', __( 'Customer code for web orders', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, __( 'Every web order is put on this customer.', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
		);
		$labels   = array(
			'order_class'             => __( 'Sales order class', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'order_representation'    => __( 'Sales order representation', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_customer'           => __( 'Property: customer', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_date'               => __( 'Property: order date', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_reference'          => __( 'Property: customer order reference', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_currency'           => __( 'Property: currency', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_lines'              => __( 'Property: order lines', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_line_item'          => __( 'Property: line item', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_line_quantity'      => __( 'Property: line quantity', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_line_price'         => __( 'Property: line price', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_site'               => __( 'Property: sales site', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_number'             => __( 'Property: order number', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'item_class'              => __( 'Item class (checks that a SKU exists)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'item_representation'     => __( 'Item representation', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_item_code'          => __( 'Property: item code', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'customer_class'          => __( 'Customer class (checks the customer code)', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'customer_representation' => __( 'Customer representation', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'prop_customer_code'      => __( 'Property: customer code', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'shipping_item'           => __( 'Item code for shipping', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'fee_item'                => __( 'Item code for fees', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'discount_item'           => __( 'Item code for discounts', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'site'                    => __( 'Sales site', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'total_property'          => __( 'Property that holds the order total (required)', 'estasoft-enterprise-erpcrm-sync-engine' ),
		);
		foreach ( $labels as $name => $label ) {
			$default  = SageX3Settings::DEFAULTS[ $name ];
			$hint     = '' !== $default ? sprintf( /* translators: %s: default name. */ __( 'Empty: %s', 'estasoft-enterprise-erpcrm-sync-engine' ), $default ) : '';
			$fields[] = $advanced( $name, $label, $this->hintFor( $name, $hint ) );
		}
		$fields[] = new CredentialField(
			'total_mismatch',
			__( 'When Sage X3 totals differ from the shop', 'estasoft-enterprise-erpcrm-sync-engine' ),
			false,
			false,
			'',
			'fail',
			'select',
			array(
				'fail' => __( 'Stop and report', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'warn' => __( 'Continue with a warning', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			'advanced'
		);
		return $fields;
	}

	/**
	 * The hint of a setting field: the special ones, else the default.
	 *
	 * @param string $name Setting name.
	 * @param string $hint Hint made from the default.
	 */
	private function hintFor( string $name, string $hint ): string {
		return match ( $name ) {
			'item_class'     => __( 'Empty: ITMMASTER. Enter none to skip the check.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'total_property' => __( 'The property of the order that holds its total. Needed so the total can be compared with the shop, unless you choose to continue with a warning below.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			default          => $hint,
		};
	}

	/**
	 * Test connection: the class and representation, the property names, the item and customer codes.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException On failure.
	 */
	protected function identify( Connection $connection ): TestResult {
		$settings = new SageX3Settings( Options::of( $connection ) );
		$folder   = trim( (string) $connection->config( 'folder', '' ) );
		$label    = 'Sage X3 REST web services';
		$failed   = static fn( ConnectorError $error ): TestResult => new TestResult( false, $label, $folder, array(), array(), $error );
		if ( '' === $folder ) {
			return $failed( Problems::refused( self::LABEL, 'folder', __( 'Enter the folder (endpoint) name.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		if ( '' === $settings->get( 'customer_code' ) ) {
			return $failed( Problems::refused( self::LABEL, 'customer_code', __( 'Enter the customer code that should receive the web orders.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		$unchecked = ( new SageX3OrderSender( $this->client( $connection ), $settings, Options::of( $connection ), new Amounts(), self::LABEL ) )->totalUnchecked();
		if ( $unchecked instanceof ConnectorError ) {
			return $failed( $unchecked );
		}
		$client = $this->client( $connection );
		$client->template( $settings->get( 'order_class' ), $settings->get( 'order_representation' ) );
		$prototype = $client->prototype( $settings->get( 'order_representation' ) );
		if ( null !== $prototype ) {
			$missing = array();
			foreach ( array( 'prop_customer', 'prop_date', 'prop_reference', 'prop_currency', 'prop_lines' ) as $name ) {
				if ( ! in_array( $settings->get( $name ), $prototype['props'], true ) ) {
					$missing[] = $name . ' (' . $settings->get( $name ) . ')';
				}
			}
			$lineProps = $prototype['children'][ $settings->get( 'prop_lines' ) ] ?? array();
			foreach ( array( 'prop_line_item', 'prop_line_quantity', 'prop_line_price' ) as $name ) {
				if ( array() !== $lineProps && ! in_array( $settings->get( $name ), $lineProps, true ) ) {
					$missing[] = $name . ' (' . $settings->get( $name ) . ')';
				}
			}
			if ( array() !== $missing ) {
				return $failed(
					Problems::refused(
						self::LABEL,
						'order_representation',
						sprintf(
							/* translators: 1: representation code, 2: setting names. */
							__( 'The representation %1$s does not have these properties: %2$s. Change the settings to the names your folder uses.', 'estasoft-enterprise-erpcrm-sync-engine' ),
							$settings->get( 'order_representation' ),
							implode( ', ', $missing )
						)
					)
				);
			}
		}
		$customer = $this->customerExists( $client, $settings );
		if ( false === $customer ) {
			return $failed( Problems::refused( self::LABEL, 'customer_code', __( 'Sage X3 has no customer with this code.', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
		}
		return new TestResult(
			true,
			$label,
			$folder,
			array(),
			array(),
			null,
			array(
				'order_class'          => $settings->get( 'order_class' ),
				'order_representation' => $settings->get( 'order_representation' ),
				'prototype_read'       => null !== $prototype,
				'customer_checked'     => null !== $customer,
				'total_property'       => $settings->get( 'total_property' ),
				'write_not_probed'     => true,
			)
		);
	}

	/**
	 * Whether the customer code exists: true or false, or null when the customer class is switched off or the folder does not offer it.
	 *
	 * @param SageX3Client   $client   Client.
	 * @param SageX3Settings $settings Names of the folder.
	 *
	 * @throws ApiException On failure other than a missing class.
	 */
	private function customerExists( SageX3Client $client, SageX3Settings $settings ): ?bool {
		if ( 'none' === strtolower( $settings->get( 'customer_class' ) ) ) {
			return null;
		}
		try {
			$rows = $client->query( $settings->get( 'customer_class' ), $settings->get( 'customer_representation' ), $settings->get( 'prop_customer_code' ) . ' eq ' . SageX3Client::literal( $settings->get( 'customer_code' ) ), 1 );
		} catch ( ApiException $exception ) {
			if ( 'order_class' === ( $exception->error()->context['field'] ?? '' ) || 'request' === ( $exception->error()->context['field'] ?? '' ) ) {
				return null;
			}
			throw $exception;
		}
		return array() !== $rows;
	}

	/**
	 * Cheap health probe: does the order class still answer?
	 *
	 * @param Connection $connection Connection.
	 */
	public function ping( Connection $connection ): bool {
		try {
			$settings = new SageX3Settings( Options::of( $connection ) );
			$this->client( $connection )->template( $settings->get( 'order_class' ), $settings->get( 'order_representation' ) );
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
			'orders'   => FieldSets::orders( self::LABEL, false, false ),
			'products' => FieldSets::products( __( 'Item code', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
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
			'orders'   => DefaultRules::orders( false, false ),
			'products' => DefaultRules::products(),
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
		return EntityType::Order === $item->entityType ? $this->orders( $connection )->finished( $item ) : null;
	}

	/**
	 * Send an order.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item being sent.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		if ( EntityType::Order !== $item->entityType ) {
			return SendResult::failure( Problems::refused( self::LABEL, 'entity', __( 'This kind of item cannot be sent to Sage X3.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'unsupported entity' ) );
		}
		$parsed = OrderPayload::parse( $payload, $item, self::LABEL, new Amounts(), false );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		return $this->orders( $connection )->send( $item, $parsed );
	}

	/**
	 * Customers are not looked up: the connector puts every order on one customer.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $email      Email address.
	 */
	public function lookupCustomer( Connection $connection, string $email ): ExternalRef|ConnectorError|null {
		unset( $connection, $email );
		return null;
	}

	/**
	 * Find an item by its code (the SKU), when the item class is known.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $sku        SKU.
	 *
	 * @throws ApiException On failure.
	 */
	public function lookupProduct( Connection $connection, string $sku ): ExternalRef|ConnectorError|null {
		$sku      = trim( $sku );
		$settings = new SageX3Settings( Options::of( $connection ) );
		if ( '' === $sku || ! $settings->itemCheck() ) {
			return null;
		}
		$rows = $this->client( $connection )->query( $settings->get( 'item_class' ), $settings->get( 'item_representation' ), $settings->get( 'prop_item_code' ) . ' eq ' . SageX3Client::literal( $sku ), 1 );
		if ( array() === $rows ) {
			return null;
		}
		$code = trim( (string) ( $rows[0][ $settings->get( 'prop_item_code' ) ] ?? $sku ) );
		return new ExternalRef( $code, $code );
	}

	/**
	 * Search items: the key lookup, because the `where` grammar beyond `eq` is not documented.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $query      Search text.
	 * @return array<int,ExternalRef>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function searchProducts( Connection $connection, string $query ): array|ConnectorError {
		$one = $this->lookupProduct( $connection, $query );
		return $one instanceof ExternalRef ? array( $one ) : array();
	}

	/**
	 * The client of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function client( Connection $connection ): SageX3Client {
		return new SageX3Client( $this->api( $connection ), self::LABEL, SiteAddress::checked( (string) $connection->config( 'address', '' ), self::LABEL ) );
	}

	/**
	 * The order sender of a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException When the address is refused.
	 */
	private function orders( Connection $connection ): SageX3OrderSender {
		$options = Options::of( $connection );
		return new SageX3OrderSender( $this->client( $connection ), new SageX3Settings( $options ), $options, new Amounts(), self::LABEL );
	}
}
