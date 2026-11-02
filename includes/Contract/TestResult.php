<?php
/**
 * What a connector returns from test().
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Result of Test connection (CON-4).
 */
final class TestResult {

	/**
	 * @param bool                                    $ok          True when the connection works and nothing blocks syncing.
	 * @param string                                  $version     System version, for example Odoo 18.0.
	 * @param string                                  $company     Company name.
	 * @param array<int,array{id:string,name:string}> $warehouses Warehouses found.
	 * @param array<string,bool>                      $permissions Permission name => allowed (for example create_sale_orders).
	 * @param ConnectorError|null                     $error       The failure when ok is false.
	 * @param array<string,mixed>                     $detected    Facts to store on the connection: transport, version number, product count.
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly string $version = '',
		public readonly string $company = '',
		public readonly array $warehouses = array(),
		public readonly array $permissions = array(),
		public readonly ?ConnectorError $error = null,
		public readonly array $detected = array(),
	) {
	}

	/**
	 * Names of the permissions that are missing.
	 *
	 * @return array<int,string>
	 */
	public function missingPermissions(): array {
		return array_keys( array_filter( $this->permissions, static fn( bool $allowed ): bool => ! $allowed ) );
	}
}
