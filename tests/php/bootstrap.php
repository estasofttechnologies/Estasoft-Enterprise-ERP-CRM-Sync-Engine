<?php
/**
 * Unit test bootstrap. No WordPress is loaded; the few WordPress functions the code under test
 * calls are provided by tests/php/Support/wp-stubs.php.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

define( 'ABSPATH', sys_get_temp_dir() . '/estasoft-sync-abspath/' );

require_once __DIR__ . '/../phpstan-bootstrap.php';
require_once __DIR__ . '/Support/wp-stubs.php';
require_once dirname( __DIR__, 2 ) . '/includes/Autoloader.php';

Estasoft\SyncEngine\Autoloader::register( 'Estasoft\\SyncEngine\\', dirname( __DIR__, 2 ) . '/includes/' );
Estasoft\SyncEngine\Autoloader::register( 'Estasoft\\SyncEngine\\Tests\\', __DIR__ . '/' );

// No test needs DNS: every host name resolves to a public address. A test of the host policy passes its own resolver.
Estasoft\SyncEngine\Connectors\Support\HostPolicy::resolveWith( static fn( string $host ): array => array( '93.184.216.34' ) );
