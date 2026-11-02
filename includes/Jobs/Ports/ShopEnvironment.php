<?php
/**
 * Facts about the shop and the server.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Versions, order storage, currency, time zone and addresses. Implemented over WordPress and WooCommerce so the readiness
 * checks, the support report and the schedules can be tested without either.
 */
interface ShopEnvironment {

	/**
	 * WooCommerce version, or an empty string when it is not loaded.
	 */
	public function wooVersion(): string;

	/**
	 * WordPress version.
	 */
	public function wpVersion(): string;

	/**
	 * PHP version.
	 */
	public function phpVersion(): string;

	/**
	 * Database server version text.
	 */
	public function databaseVersion(): string;

	/**
	 * PHP memory limit as WordPress reports it, for example 256M.
	 */
	public function memoryLimit(): string;

	/**
	 * Whether orders are stored in the custom order tables.
	 */
	public function hposEnabled(): bool;

	/**
	 * Store currency, ISO code.
	 */
	public function currency(): string;

	/**
	 * Decimal places WooCommerce shows for the currency.
	 */
	public function currencyDecimals(): int;

	/**
	 * Store time zone.
	 */
	public function timezone(): \DateTimeZone;

	/**
	 * Base country of the store, ISO 3166 alpha-2.
	 */
	public function baseCountry(): string;

	/**
	 * Character set of the plugin's tables (for example utf8mb4).
	 */
	public function databaseCharset(): string;

	/**
	 * Site name, for e-mail subjects.
	 */
	public function siteName(): string;

	/**
	 * The site administrator's e-mail address.
	 */
	public function adminEmail(): string;

	/**
	 * Whether WP-Cron runs on site visits (true) or is driven by a real cron job (false).
	 */
	public function cronRunsOnVisits(): bool;

	/**
	 * A URL in wp-admin.
	 *
	 * @param string $path Path and query below wp-admin, for example admin.php?page=estasoft-sync.
	 */
	public function adminUrl( string $path = '' ): string;

	/**
	 * Plugin version.
	 */
	public function pluginVersion(): string;
}
