<?php
/**
 * Uninstall: remove data only when the person chose "Delete all data when the plugin is deleted".
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Autoloader.php';
Estasoft\SyncEngine\Autoloader::register( 'Estasoft\\SyncEngine\\', __DIR__ . '/includes/' );

if ( class_exists( Estasoft\SyncEngine\Core\Installer::class ) ) {
	Estasoft\SyncEngine\Core\Installer::uninstall();
}
