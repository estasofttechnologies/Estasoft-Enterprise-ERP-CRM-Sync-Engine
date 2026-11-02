<?php
/**
 * Shop and server facts from WordPress and WooCommerce.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Adapters;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Estasoft\SyncEngine\Core\Database\Tables;
use Estasoft\SyncEngine\Core\Queue\Scheduler;
use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;

/**
 * Thin: every method is one WordPress or WooCommerce call with a safe fallback when WooCommerce is not loaded.
 */
final class WooShopEnvironment implements ShopEnvironment {

	/**
	 * Set up with what it depends on.
	 *
	 * @param \wpdb  $wpdb   Database.
	 * @param Tables $tables Plugin table names.
	 */
	public function __construct( private readonly \wpdb $wpdb, private readonly Tables $tables ) {
	}

	/**
	 * WooCommerce version.
	 */
	public function wooVersion(): string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
	}

	/**
	 * WordPress version.
	 */
	public function wpVersion(): string {
		return (string) get_bloginfo( 'version' );
	}

	/**
	 * PHP version.
	 */
	public function phpVersion(): string {
		return PHP_VERSION;
	}

	/**
	 * Database server version text.
	 */
	public function databaseVersion(): string {
		return method_exists( $this->wpdb, 'db_server_info' ) ? (string) $this->wpdb->db_server_info() : (string) $this->wpdb->db_version();
	}

	/**
	 * PHP memory limit.
	 */
	public function memoryLimit(): string {
		return defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : (string) ini_get( 'memory_limit' );
	}

	/**
	 * Whether orders use the custom order tables.
	 */
	public function hposEnabled(): bool {
		return class_exists( OrderUtil::class ) && true === OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Store currency.
	 */
	public function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : (string) get_option( 'woocommerce_currency', '' );
	}

	/**
	 * Decimal places WooCommerce shows.
	 */
	public function currencyDecimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
	}

	/**
	 * Store time zone.
	 */
	public function timezone(): \DateTimeZone {
		return wp_timezone();
	}

	/**
	 * Base country.
	 */
	public function baseCountry(): string {
		$location = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
		if ( is_array( $location ) && ! empty( $location['country'] ) ) {
			return strtoupper( (string) $location['country'] );
		}
		$default = (string) get_option( 'woocommerce_default_country', '' );
		return strtoupper( (string) strtok( $default, ':' ) );
	}

	/**
	 * Character set of the plugin's tables, read from the collation of the queue table.
	 */
	public function databaseCharset(): string {
		$wpdb = $this->wpdb;
		$like = $wpdb->esc_like( $this->tables->queue() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the table's collation is not available through any WordPress function, and it is read once for the Status screen.
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );
		if ( is_array( $status ) && ! empty( $status['Collation'] ) ) {
			return (string) strtok( (string) $status['Collation'], '_' );
		}
		return (string) $this->wpdb->charset;
	}

	/**
	 * Site name.
	 */
	public function siteName(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Administrator e-mail address.
	 */
	public function adminEmail(): string {
		return (string) get_option( 'admin_email', '' );
	}

	/**
	 * Whether background tasks wait for site visits.
	 */
	public function cronRunsOnVisits(): bool {
		return Scheduler::cronRunsOnVisits();
	}

	/**
	 * A URL in wp-admin.
	 *
	 * @param string $path Path below wp-admin.
	 */
	public function adminUrl( string $path = '' ): string {
		return admin_url( $path );
	}

	/**
	 * Plugin version.
	 */
	public function pluginVersion(): string {
		return defined( 'ESTASOFT_SYNC_VERSION' ) ? (string) ESTASOFT_SYNC_VERSION : '';
	}
}
