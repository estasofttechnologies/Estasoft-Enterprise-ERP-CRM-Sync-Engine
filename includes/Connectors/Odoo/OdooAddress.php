<?php
/**
 * A checked Odoo server address.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

/**
 * The result of OdooAddressNormalizer: scheme, host and optional port, with no path, query, user or password.
 */
final class OdooAddress {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $baseUrl Scheme, host and optional port, for example https://erp.example.com.
	 * @param string $host    Lower-case host name or IP address.
	 * @param bool   $isPrivate True when the host is on a private, loopback or reserved network.
	 */
	public function __construct(
		public readonly string $baseUrl,
		public readonly string $host,
		public readonly bool $isPrivate = false,
	) {
	}

	/**
	 * A full URL below the server root.
	 *
	 * @param string $path Path that starts with a slash.
	 */
	public function url( string $path ): string {
		return $this->baseUrl . $path;
	}

	/**
	 * Whether the host is an Odoo Online address (a subdomain of odoo.com).
	 */
	public function isOdooOnline(): bool {
		return 'odoo.com' === $this->host || str_ends_with( $this->host, '.odoo.com' );
	}
}
