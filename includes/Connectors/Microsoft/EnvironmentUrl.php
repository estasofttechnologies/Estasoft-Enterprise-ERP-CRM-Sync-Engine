<?php
/**
 * The address of a Dynamics 365 environment, checked before it becomes part of a request.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

/**
 * Dataverse and Finance and Operations environments live at a host of the merchant's own under `dynamics.com`
 * (`contoso.crm4.dynamics.com`, `contoso.operations.dynamics.com`, `contoso-uat.sandbox.operations.dynamics.com`). The merchant pastes the
 * address; only an https address of such a host is accepted, and everything after the host (a path, a query, a trailing slash) is dropped,
 * because the address is also the resource of the token (`{address}/.default`) and Microsoft says it must have no trailing slash.
 */
final class EnvironmentUrl {

	/**
	 * The clean address `https://host`, or null when the text is not an address of a Dynamics 365 environment.
	 *
	 * @param string $text What the merchant typed.
	 */
	public static function normalise( string $text ): ?string {
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}
		if ( ! str_contains( $text, '://' ) ) {
			$text = 'https://' . $text;
		}
		$parts = wp_parse_url( $text );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return null;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.dynamics\.com$/', $host ) ) {
			return null;
		}
		return 'https://' . $host;
	}
}
