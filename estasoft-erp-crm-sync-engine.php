<?php
/**
 * Plugin Name:       Estasoft Enterprise ERP/CRM Sync Engine
 * Plugin URI:        https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine
 * Description:       Keeps WooCommerce in step with your ERP, accounting system or CRM through a durable queue, so checkout never waits. Orders, customers, refunds, stock and tracking.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Estasoft Technologies
 * Author URI:        https://estasoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       estasoft-erp-crm-sync-engine
 * Domain Path:       /languages
 * WC requires at least: 8.2
 * WC tested up to:   9.4
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'ESTASOFT_SYNC_VERSION', '0.1.0' );
define( 'ESTASOFT_SYNC_FILE', __FILE__ );
define( 'ESTASOFT_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESTASOFT_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'ESTASOFT_SYNC_MIN_PHP', '8.1' );
define( 'ESTASOFT_SYNC_MIN_WP', '6.4' );
define( 'ESTASOFT_SYNC_MIN_WC', '8.2' );
define( 'ESTASOFT_SYNC_TEXT_DOMAIN', 'estasoft-erp-crm-sync-engine' );

/*
 * The first guard runs before any class is loaded, so an old PHP version can never hit a parse
 * error from the rest of the plugin (ONB-1). WooCommerce is checked later, on plugins_loaded,
 * because plugins load alphabetically and WooCommerce comes after this one.
 */
require_once ESTASOFT_SYNC_DIR . 'includes/requirements.php';

if ( '' !== estasoft_sync_unmet_requirement( false ) ) {
	estasoft_sync_requirement_notice( false );
	return;
}

require_once ESTASOFT_SYNC_DIR . 'includes/Autoloader.php';
Estasoft\SyncEngine\Autoloader::register( 'Estasoft\\SyncEngine\\', ESTASOFT_SYNC_DIR . 'includes/' );

register_activation_hook( __FILE__, array( Estasoft\SyncEngine\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Estasoft\SyncEngine\Lifecycle::class, 'deactivate' ) );

add_action( 'plugins_loaded', 'estasoft_sync_boot', 20 );
