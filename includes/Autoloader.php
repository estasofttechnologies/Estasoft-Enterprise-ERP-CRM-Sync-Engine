<?php
/**
 * PSR-4 autoloader (the plugin ships no vendor directory).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the namespace prefix to the includes directory.
 */
final class Autoloader {

	/**
	 * Register one namespace prefix.
	 *
	 * @param string $prefix  Namespace prefix with trailing backslash.
	 * @param string $baseDir Directory with trailing slash.
	 */
	public static function register( string $prefix, string $baseDir ): void {
		spl_autoload_register(
			static function ( string $class ) use ( $prefix, $baseDir ): void {
				if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
					return;
				}
				$relative = substr( $class, strlen( $prefix ) );
				$file     = $baseDir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
