<?php
/**
 * Turns what a person typed into a safe Odoo base address.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Rules: http or https only; https is required unless the site owner allows private hosts (filter
 * estasoft_sync_allow_private_hosts) and the host is on a private network or is localhost; no credentials in the address;
 * the path, query and fragment are dropped; private-network hosts are refused unless the filter allows them.
 */
final class OdooAddressNormalizer {

	/**
	 * Host name endings that mean a private network.
	 *
	 * @var array<int,string>
	 */
	private const PRIVATE_SUFFIXES = array( '.localhost', '.local', '.internal', '.lan', '.localdomain', '.home.arpa', '.intranet', '.corp' );

	/**
	 * Decides whether private hosts are allowed. Receives the normalised base URL, returns a bool.
	 *
	 * @var callable
	 */
	private $allowPrivate;

	/**
	 * Set up with what it depends on.
	 *
	 * @param callable $allowPrivate Receives the base URL, returns true when the site owner allows private hosts.
	 */
	public function __construct( callable $allowPrivate ) {
		$this->allowPrivate = $allowPrivate;
	}

	/**
	 * Check and normalise an address.
	 *
	 * @param string $input What the person typed.
	 * @return OdooAddress|ConnectorError The address, or SYNC-VAL-002 with the reason.
	 */
	public function normalize( string $input ): OdooAddress|ConnectorError {
		$input = trim( $input );
		if ( '' === $input ) {
			return $this->reject( __( 'Enter the web address you use to sign in to Odoo.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( 1 === preg_match( '/[\x00-\x20\x7f]/', $input ) ) {
			return $this->reject( __( 'The address must not contain spaces or control characters.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}

		if ( 1 === preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $input ) ) {
			$withScheme = $input;
		} else {
			$withScheme = 'https://' . $input;
		}

		$parts = wp_parse_url( $withScheme );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return $this->reject( __( 'This is not a web address.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return $this->reject( __( 'Use an address that starts with https://.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $this->reject( __( 'Do not put a user name or password in the address. Use the fields below it.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}

		$host = $this->asciiHost( strtolower( rtrim( (string) $parts['host'], '.' ) ) );
		if ( '' === $host || ! $this->validHost( $host ) ) {
			return $this->reject( __( 'The host name in the address is not valid.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( null !== $port && ( $port < 1 || $port > 65535 ) ) {
			return $this->reject( __( 'The port in the address is not valid.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}

		$private = $this->isPrivateHost( $host );
		$base    = $scheme . '://' . $this->hostForUrl( $host ) . $this->portSuffix( $scheme, $port );

		if ( $private && ! (bool) call_user_func( $this->allowPrivate, $base ) ) {
			return $this->reject( __( 'This address points to a private network or to this computer, which is not allowed.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( 'http' === $scheme && ! $private ) {
			return $this->reject( __( 'Use an address that starts with https://. Plain http is only accepted for private networks you have allowed.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}

		return new OdooAddress( $base, $host, $private );
	}

	/**
	 * Whether a host name or IP address is on a private, loopback or reserved network.
	 *
	 * @param string $host Lower-case host without brackets.
	 */
	public function isPrivateHost( string $host ): bool {
		$host = trim( $host, '[]' );
		if ( 'localhost' === $host ) {
			return true;
		}
		foreach ( self::PRIVATE_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		if ( str_contains( $host, ':' ) ) {
			return $this->isPrivateIpv6( $host );
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return $this->isPrivateIpv4( $host );
		}
		// Numeric forms such as 2130706433, 0x7f.1 or 127.1 are read by network libraries as IP addresses.
		return 1 === preg_match( '/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+)){0,3}$/i', $host );
	}

	/**
	 * Private, loopback, link-local, carrier-grade NAT or reserved IPv4 address.
	 *
	 * @param string $ip Dotted IPv4 address.
	 */
	private function isPrivateIpv4( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		$long = ip2long( $ip );
		// 100.64.0.0/10 carrier-grade NAT is not covered by the PHP flags.
		return false !== $long && ( $long >= ip2long( '100.64.0.0' ) && $long <= ip2long( '100.127.255.255' ) );
	}

	/**
	 * Private, loopback, link-local, unspecified or IPv4-mapped private IPv6 address.
	 *
	 * @param string $ip IPv6 address without brackets.
	 */
	private function isPrivateIpv6( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return true;
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return true;
		}
		// ::ffff:a.b.c.d (IPv4-mapped) and ::a.b.c.d (IPv4-compatible) carry an IPv4 address in the last four bytes.
		$prefix = substr( $packed, 0, 12 );
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === $prefix || str_repeat( "\0", 12 ) === $prefix ) {
			$v4 = inet_ntop( substr( $packed, 12 ) );
			return false === $v4 || $this->isPrivateIpv4( $v4 );
		}
		return false;
	}

	/**
	 * Host in punycode when it has non-ASCII letters and the intl extension is present.
	 *
	 * @param string $host Lower-case host.
	 */
	private function asciiHost( string $host ): string {
		if ( 1 === preg_match( '/[^\x00-\x7f]/', $host ) ) {
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				return '';
			}
			$converted = idn_to_ascii( $host );
			return is_string( $converted ) ? strtolower( $converted ) : '';
		}
		return $host;
	}

	/**
	 * Whether the host has only letters, digits, dots and hyphens, or is an IP literal.
	 *
	 * @param string $host Host without brackets.
	 */
	private function validHost( string $host ): bool {
		$host = trim( $host, '[]' );
		if ( str_contains( $host, ':' ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		}
		return 1 === preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/', $host ) && strlen( $host ) <= 253;
	}

	/**
	 * Host as it appears in a URL (IPv6 gets brackets).
	 *
	 * @param string $host Host without brackets.
	 */
	private function hostForUrl( string $host ): string {
		$host = trim( $host, '[]' );
		return str_contains( $host, ':' ) ? '[' . $host . ']' : $host;
	}

	/**
	 * ":port" unless the port is the default of the scheme.
	 *
	 * @param string   $scheme http or https.
	 * @param int|null $port   Port or null.
	 */
	private function portSuffix( string $scheme, ?int $port ): string {
		if ( null === $port || ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			return '';
		}
		return ':' . $port;
	}

	/**
	 * A rejection as SYNC-VAL-002.
	 *
	 * @param string $reason Why, already translated.
	 */
	private function reject( string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => 'Odoo',
				'field'  => __( 'Odoo address', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'reason' => $reason,
			),
			null,
			null,
			null,
			'address rejected'
		);
	}
}
