<?php
/**
 * Meta keys the plugin writes on WooCommerce objects and users.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * One list so uninstall can remove exactly what the plugin wrote and nothing else. Other modules add
 * their own keys through the filter `estasoft_sync_meta_keys`.
 */
final class MetaKeys {

	/**
	 * Order meta (written through the WooCommerce CRUD layer).
	 *
	 * @return array<int,string>
	 */
	public static function order(): array {
		return self::filtered(
			'order',
			array(
				'_estasoft_sync_state',
				'_estasoft_sync_external_id',
				'_estasoft_sync_external_ref',
				'_estasoft_sync_external_url',
				'_estasoft_sync_last_sync',
				'_estasoft_sync_mapping_version',
				'_estasoft_sync_invoice_number',
				'_estasoft_sync_invoice_status',
				'_estasoft_sync_invoice_pdf',
				'_estasoft_sync_tracking_number',
				'_estasoft_sync_tracking_carrier',
				'_estasoft_sync_skip',
			)
		);
	}

	/**
	 * Product meta.
	 *
	 * @return array<int,string>
	 */
	public static function product(): array {
		return self::filtered(
			'product',
			array(
				'_estasoft_sync_exclude',
				'_estasoft_sync_external_id',
				'_estasoft_sync_external_ref',
				'_estasoft_sync_last_stock',
				'_estasoft_sync_last_stock_at',
			)
		);
	}

	/**
	 * User meta (dismissed notices, screen options).
	 *
	 * @return array<int,string>
	 */
	public static function user(): array {
		return self::filtered(
			'user',
			array(
				'estasoft_sync_dismissed_notices',
				'estasoft_sync_screen_options',
				'estasoft_sync_per_page',
			)
		);
	}

	/**
	 * Apply the filter.
	 *
	 * @param string            $kind Which list: order, product or user.
	 * @param array<int,string> $keys Default keys.
	 * @return array<int,string>
	 */
	private static function filtered( string $kind, array $keys ): array {
		/**
		 * Filter the meta keys removed on uninstall.
		 *
		 * @param array<int,string> $keys Keys.
		 * @param string            $kind order, product or user.
		 */
		$filtered = apply_filters( 'estasoft_sync_meta_keys', $keys, $kind );
		return array_values( array_unique( array_filter( array_map( 'strval', (array) $filtered ) ) ) );
	}
}
