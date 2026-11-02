<?php
/**
 * The class, representation and property names of a Sage X3 folder, with the defaults of the standard sales order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageX3;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\SiteAddress;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * These names differ between folders and partners, so each is an option. The defaults are inferred from the standard product
 * (docs/connectors/sagex3.md section 5) and Test connection checks them against what the folder describes.
 */
final class SageX3Settings {

	/**
	 * Options and their defaults. An empty default means "required" or "optional, off".
	 *
	 * @var array<string,string>
	 */
	public const DEFAULTS = array(
		'order_class'             => 'SORDER',
		'order_representation'    => 'SOH',
		'prop_customer'           => 'BPCORD',
		'prop_date'               => 'ORDDAT',
		'prop_reference'          => 'CUSORDREF',
		'prop_currency'           => 'CUR',
		'prop_lines'              => 'SORDERQ',
		'prop_line_item'          => 'ITMREF',
		'prop_line_quantity'      => 'QTY',
		'prop_line_price'         => 'GROPRI',
		'prop_site'               => 'SALFCY',
		'prop_number'             => 'SOHNUM',
		'item_class'              => 'ITMMASTER',
		'item_representation'     => 'ITMMASTER',
		'prop_item_code'          => 'ITMREF',
		'customer_class'          => 'BPCUSTOMER',
		'customer_representation' => 'BPCUSTOMER',
		'prop_customer_code'      => 'BPCNUM',
		'shipping_item'           => '',
		'fee_item'                => '',
		'discount_item'           => '',
		'customer_code'           => '',
		'site'                    => '',
		'total_property'          => '',
	);

	/**
	 * Set up with the options of a connection.
	 *
	 * @param Options $options Connection options.
	 */
	public function __construct( private readonly Options $options ) {
	}

	/**
	 * A setting: the option when set, else the default.
	 *
	 * @param string $name Option name.
	 */
	public function get( string $name ): string {
		$value = $this->options->text( $name );
		return '' !== $value ? $value : ( self::DEFAULTS[ $name ] ?? '' );
	}

	/**
	 * Whether a setting was left empty on purpose: only the item check can be switched off, by saving `item_class` as the word `none`.
	 */
	public function itemCheck(): bool {
		return 'none' !== strtolower( $this->options->text( 'item_class' ) );
	}

	/**
	 * The base address of the folder's REST services.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $system     System name for messages.
	 *
	 * @throws ApiException When the address is not a web address.
	 */
	public static function base( Connection $connection, string $system ): string {
		$folder = trim( (string) $connection->config( 'folder', '' ) );
		return SiteAddress::checked( (string) $connection->config( 'address', '' ), $system, true, array( 'api1' ) ) . '/api1/x3/erp/' . rawurlencode( $folder );
	}
}
