<?php
/**
 * Removes everything the plugin stored on one site.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Database\Tables;
use Estasoft\SyncEngine\Core\Queue\Scheduler;

/**
 * Used by uninstall (when "Delete all plugin data when the plugin is deleted" is on) and by the Delete data tool. It drops
 * the nine tables, deletes the options listed in OptionNames (and the filter `estasoft_sync_option_names`) and every
 * transient with the plugin prefix, removes the plugin's own meta keys from orders, products and users, deletes the export
 * folder in uploads, and unschedules the background tasks. It does not delete an option only because its name starts with
 * the plugin prefix.
 *
 * User meta is shared by every site of a network, so on multisite it is removed only when the whole network is erased
 * (`eraseUserMeta()`, called once by the uninstaller when no site keeps its data), never by the erase of one site.
 *
 * It never deletes, changes or reads a WooCommerce order other than to remove the plugin's own meta keys from it, and it does
 * that through the WooCommerce CRUD layer when WooCommerce is loaded.
 */
final class DataEraser {

	/**
	 * Name of the export folder inside the uploads folder.
	 */
	public const EXPORT_DIR = 'estasoft-sync';

	/**
	 * Orders handled per batch when removing order meta.
	 */
	private const ORDER_BATCH = 100;

	/**
	 * Most batches of orders per meta key, a safety stop against a loop that never ends.
	 */
	private const MAX_BATCHES = 2000;

	/**
	 * Finds order ids that have a meta key: function( string $key, int $limit ): array of ids.
	 *
	 * @var \Closure
	 */
	private \Closure $orderIds;

	/**
	 * Loads an order object that has delete_meta_data() and save_meta_data(): function( int $id ): ?object.
	 *
	 * @var \Closure
	 */
	private \Closure $loadOrder;

	/**
	 * Set up the data eraser.
	 *
	 * @param \wpdb         $wpdb      Database of the site.
	 * @param Tables        $tables    Table names of the site.
	 * @param \Closure|null $orderIds  Finds order ids that carry a meta key; uses wc_get_orders() by default.
	 * @param \Closure|null $loadOrder Loads an order; uses wc_get_order() by default.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Tables $tables,
		?\Closure $orderIds = null,
		?\Closure $loadOrder = null
	) {
		$this->orderIds  = $orderIds ?? static function ( string $key, int $limit ): array {
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return array();
			}
			/**
			 * Order ids, because 'return' is 'ids'.
			 *
			 * @var mixed $ids
			 */
			$ids = wc_get_orders(
				array(
					'limit'      => $limit,
					'return'     => 'ids',
					'status'     => 'any',
					'type'       => array( 'shop_order', 'shop_order_refund' ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- finds the orders that carry the plugin's meta key; this runs once, when the data is erased.
					'meta_query' => array(
						array(
							'key'     => $key,
							'compare' => 'EXISTS',
						),
					),
				)
			);
			return is_array( $ids ) ? array_map( static fn( $id ): int => (int) $id, $ids ) : array();
		};
		$this->loadOrder = $loadOrder ?? static function ( int $id ): ?object {
			if ( ! function_exists( 'wc_get_order' ) ) {
				return null;
			}
			$order = wc_get_order( $id );
			return $order instanceof \WC_Abstract_Order ? $order : null;
		};
	}

	/**
	 * Erase the plugin's data on this site.
	 *
	 * @param bool      $revokeCapabilities Also remove the two capabilities from every role (uninstall does; the Delete data tool does not).
	 * @param bool|null $deleteUserMeta     Whether to remove the plugin's user meta. Null decides by itself: yes on a single site, no on
	 *                                      multisite (the users belong to the whole network; see eraseUserMeta()).
	 */
	public function erase( bool $revokeCapabilities = true, ?bool $deleteUserMeta = null ): void {
		Scheduler::unschedule();
		$this->dropTables();
		$this->deleteOptions();
		$this->deleteOrderMeta();
		$this->deleteMeta( 'post', MetaKeys::product() );
		$this->deleteMeta( 'post', MetaKeys::order() );
		if ( $deleteUserMeta ?? ! is_multisite() ) {
			$this->eraseUserMeta();
		}
		$this->deleteExportFolder();
		if ( $revokeCapabilities ) {
			Capabilities::revoke();
		}
	}

	/**
	 * Remove the plugin's user meta keys for every user. On multisite call this only when the whole network is erased.
	 */
	public function eraseUserMeta(): void {
		$this->deleteMeta( 'user', MetaKeys::user() );
	}

	/**
	 * Drop the nine tables.
	 */
	private function dropTables(): void {
		$wpdb = $this->wpdb;
		foreach ( $this->tables->all() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- removing the plugin's own tables is the point of erasing its data.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * Delete the options named in OptionNames, and every transient with the plugin prefix. Each one goes through
	 * delete_option(), so a persistent object cache forgets it too.
	 */
	private function deleteOptions(): void {
		$names = OptionNames::exact();
		foreach ( OptionNames::prefixes() as $prefix ) {
			$names = array_merge( $names, $this->optionsStartingWith( $prefix ) );
		}
		// Transients are disposable and the prefix is ours, so all of them go (also the ones with an id in their name).
		foreach ( array( '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ) as $kind ) {
			$names = array_merge( $names, $this->optionsStartingWith( $kind . 'estasoft_sync_' ) );
		}
		foreach ( array_unique( $names ) as $name ) {
			delete_option( (string) $name );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Names of the stored options that start with a text.
	 *
	 * @param string $prefix Start of the name.
	 * @return array<int,string>
	 */
	private function optionsStartingWith( string $prefix ): array {
		$wpdb    = $this->wpdb;
		$pattern = $wpdb->esc_like( $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- finds option names by prefix, which get_option() cannot do; each one is then removed with delete_option().
		$found = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $pattern ) );
		return array_map( 'strval', (array) $found );
	}

	/**
	 * Remove the plugin's meta keys from orders through the WooCommerce CRUD layer, in batches.
	 */
	private function deleteOrderMeta(): void {
		foreach ( MetaKeys::order() as $key ) {
			for ( $batch = 0; $batch < self::MAX_BATCHES; ++$batch ) {
				$ids = ( $this->orderIds )( $key, self::ORDER_BATCH );
				if ( array() === $ids ) {
					break;
				}
				$removed = 0;
				foreach ( $ids as $id ) {
					$order = ( $this->loadOrder )( (int) $id );
					if ( null !== $order ) {
						$order->delete_meta_data( $key );
						$order->save_meta_data();
						++$removed;
					}
				}
				if ( 0 === $removed ) {
					break;
				}
			}
		}
	}

	/**
	 * Remove meta keys of one WordPress meta type with the WordPress meta API.
	 *
	 * @param string            $type post or user.
	 * @param array<int,string> $keys Meta keys.
	 */
	private function deleteMeta( string $type, array $keys ): void {
		foreach ( $keys as $key ) {
			delete_metadata( $type, 0, $key, '', true );
		}
	}

	/**
	 * Delete the export folder in uploads, with its files.
	 */
	private function deleteExportFolder(): void {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return;
		}
		$base = wp_normalize_path( (string) $uploads['basedir'] );
		$dir  = trailingslashit( $base ) . self::EXPORT_DIR;
		if ( ! is_dir( $dir ) || wp_normalize_path( dirname( $dir ) ) !== $base ) {
			return;
		}
		// Direct access, like WordPress core uses for its own cache folders: no credentials are involved, and no global.
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		( new \WP_Filesystem_Direct( null ) )->delete( $dir, true );
	}
}
