<?php
/**
 * What the fake WooCommerce and WordPress functions know.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Test-only state behind wc_get_order() and friends. Call reset() in setUp().
 */
final class WooRegistry {

	/**
	 * Orders, refunds and other order objects by id.
	 *
	 * @var array<int,object>
	 */
	public static array $orders = array();

	/**
	 * Products by id.
	 *
	 * @var array<int,object>
	 */
	public static array $products = array();

	/**
	 * Result of the next wc_get_orders() call.
	 *
	 * @var object|array<int,mixed>
	 */
	public static $ordersResult = array();

	/**
	 * Arguments of every wc_get_orders() call.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $orderQueries = array();

	/**
	 * Whether the signed-in person has the capability asked for.
	 */
	public static bool $can = true;

	/**
	 * Capabilities asked for.
	 *
	 * @var array<int,string>
	 */
	public static array $capsAsked = array();

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	public static array $options = array();

	/**
	 * Transients.
	 *
	 * @var array<string,mixed>
	 */
	public static array $transients = array();

	/**
	 * Forget everything.
	 */
	public static function reset(): void {
		self::$orders       = array();
		self::$products     = array();
		self::$ordersResult = array();
		self::$orderQueries = array();
		self::$can          = true;
		self::$capsAsked    = array();
		self::$options      = array();
		self::$transients   = array();
		$_GET               = array();
		$_POST              = array();
	}
}
