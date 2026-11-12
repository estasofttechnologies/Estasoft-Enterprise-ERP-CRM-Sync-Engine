<?php
/**
 * Which addresses a connector may contact.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Only https. A connector with fixed hosts may contact those hosts (a leading `*.` matches subdomains) and nothing else. A connector whose
 * host is typed by the merchant may contact public addresses only: the name is resolved (A and AAAA) and every address it resolves to must
 * be public, so a name that points into the merchant's network is refused. Private, loopback, link-local, carrier-grade NAT and
 * IPv4-mapped IPv6 addresses count as private; they are refused unless the filter `estasoft_sync_allow_private_hosts` allows them (the
 * rule the Odoo connector follows). A policy with no fixed host and no merchant host allows nothing (it fails closed). Numeric host forms
 * (decimal, hexadecimal or octal IP addresses, `127.1`), a trailing dot and a user name or password in the address are always refused.
 *
 * A name can be pointed somewhere else between this check and the connection (DNS rebinding). The addresses that passed are therefore
 * remembered for thirty seconds (`HostPins`) and handed to cURL with CURLOPT_RESOLVE, so the connection goes to an address that was
 * checked. That works when WordPress uses its cURL transport; with another transport the gap remains.
 */
final class HostPolicy {

	/**
	 * Resolver used when none is given: receives a host name, returns IP address texts. Tests set it so that nothing touches the network.
	 *
	 * @var \Closure|null
	 */
	private static ?\Closure $defaultResolver = null;

	/**
	 * Answers of the real resolver for a minute: host to the time they expire and the addresses.
	 *
	 * @var array<string,array{until:int,ips:array<int,string>}>
	 */
	private static array $answers = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<int,string> $hosts        Fixed hosts; empty means the merchant supplies the host.
	 * @param bool              $merchantHost True when the merchant supplies the host. With no fixed host and false nothing is allowed.
	 * @param \Closure|null     $allowPrivate Receives the URL, returns true when private hosts are allowed. Defaults to the filter.
	 * @param \Closure|null     $resolver     Receives a host name, returns its IP addresses (A and AAAA). Defaults to the system resolver.
	 */
	public function __construct( private readonly array $hosts = array(), private readonly bool $merchantHost = false, private readonly ?\Closure $allowPrivate = null, private readonly ?\Closure $resolver = null ) {
	}

	/**
	 * The policy a connector declares in its meta.
	 *
	 * @param ConnectorMeta $meta         Meta.
	 * @param \Closure|null $allowPrivate Override for the private-host decision (tests).
	 * @param \Closure|null $resolver     Override for the name lookup (tests).
	 */
	public static function fromMeta( ConnectorMeta $meta, ?\Closure $allowPrivate = null, ?\Closure $resolver = null ): self {
		return new self( $meta->hosts, $meta->merchantHost, $allowPrivate, $resolver );
	}

	/**
	 * Replace the system resolver for every policy that is not given one (tests). Null restores it.
	 *
	 * @param \Closure|null $resolver Receives a host name, returns IP address texts.
	 */
	public static function resolveWith( ?\Closure $resolver ): void {
		self::$defaultResolver = $resolver;
		self::$answers         = array();
		HostPins::reset();
	}

	/**
	 * Why a URL is refused, or null when it is allowed.
	 *
	 * @param string $url Absolute URL.
	 */
	public function violation( string $url ): ?string {
		$fixed = array() !== $this->hosts && ! $this->merchantHost;
		if ( array() === $this->hosts && ! $this->merchantHost ) {
			return 'No address is allowed for this connection.';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return 'The address is not a valid web address.';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return 'The address must not contain a user name or password.';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( trim( (string) $parts['host'], '[]' ) );
		if ( str_ends_with( $host, '.' ) || str_contains( $host, '%' ) || 1 === preg_match( '/[\s\x00-\x1f]/', $host ) ) {
			return 'The address is not a valid web address.';
		}
		if ( self::isNumericForm( $host ) ) {
			return 'The address must use a host name or an IP address written the usual way.';
		}
		$private = $this->isPrivateHost( $host );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $private && $this->privateAllowed( $url ) ) ) {
			return 'The address must start with https://.';
		}
		if ( $fixed ) {
			return $this->matchesFixed( $host ) ? null : 'The address is not one of the hosts this connector contacts.';
		}
		if ( $private ) {
			return $this->privateAllowed( $url ) ? null : 'The address points to a private network.';
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) || $this->privateAllowed( $url ) ) {
			return null; // A public IP address needs no lookup; a site that allows private hosts has decided already.
		}
		// A name the merchant typed: every address it resolves to has to be public.
		$addresses = $this->addressesOf( $host );
		if ( array() === $addresses ) {
			return 'The address could not be found. Check the spelling of the host name.';
		}
		foreach ( $addresses as $address ) {
			if ( self::isPrivateAddress( $address ) ) {
				return 'The address points to a private network.';
			}
		}
		HostPins::remember( $host, isset( $parts['port'] ) ? (int) $parts['port'] : 443, $addresses );
		return null;
	}

	/**
	 * The reason text in the language of the site. Every message is written out as a plain string so that
	 * translators can see it; an unknown text is returned as it is.
	 *
	 * @param string $reason A text returned by violation().
	 */
	private static function reasonText( string $reason ): string {
		switch ( $reason ) {
			case 'No address is allowed for this connection.':
				return __( 'No address is allowed for this connection.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'The address is not a valid web address.':
				return __( 'The address is not a valid web address.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'The address must not contain a user name or password.':
				return __( 'The address must not contain a user name or password.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'The address must use a host name or an IP address written the usual way.':
				return __( 'The address must use a host name or an IP address written the usual way.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'The address must start with https://.':
				return __( 'The address must start with https://.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'The address is not one of the hosts this connector contacts.':
				return __( 'The address is not one of the hosts this connector contacts.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'The address points to a private network.':
				return __( 'The address points to a private network.', 'estasoft-enterprise-erpcrm-sync-engine' );
			case 'The address could not be found. Check the spelling of the host name.':
				return __( 'The address could not be found. Check the spelling of the host name.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		return $reason;
	}

	/**
	 * Throw when a URL is not allowed.
	 *
	 * @param string $url    Absolute URL.
	 * @param string $system System name for the message.
	 *
	 * @throws ApiException With SYNC-VAL-002 (field address) when the URL is refused.
	 */
	public function assert( string $url, string $system = '' ): void {
		$reason = $this->violation( $url );
		if ( null !== $reason ) {
			throw new ApiException(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => $system,
						'field'  => 'address',
						'reason' => self::reasonText( $reason ),
					),
					null,
					null,
					null,
					'host refused'
				)
			);
		}
	}

	/**
	 * Refuse an absolute link that points somewhere other than the connection's own server: a next-page link, a `Location` header or any
	 * address the other side sent. It has to have the scheme, host and port of the base address, or be on one of the connector's declared fixed
	 * hosts (a leading `*.` matches subdomains; the port is then the default one or the base address's). This is checked before the request is
	 * built, so no credential is ever applied to a request for a stranger's address.
	 *
	 * @param string $url     Absolute URL taken from a path argument.
	 * @param string $baseUrl Base address of the connection.
	 * @param string $system  System name for the message.
	 *
	 * @throws ApiException With SYNC-VAL-002 (field address) when the link is on another origin.
	 */
	public function assertFollowable( string $url, string $baseUrl, string $system = '' ): void {
		if ( $this->followable( $url, $baseUrl ) ) {
			return;
		}
		throw new ApiException(
			new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => $system,
					'field'  => 'address',
					'reason' => __( 'The system sent a link to a different server than the one this connection uses.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				null,
				null,
				null,
				'link to another origin refused'
			)
		);
	}

	/**
	 * Whether an absolute link is on the same origin as the base address, or on a declared fixed host.
	 *
	 * @param string $url     Absolute URL.
	 * @param string $baseUrl Base address.
	 */
	private function followable( string $url, string $baseUrl ): bool {
		$link = wp_parse_url( $url );
		$base = wp_parse_url( $baseUrl );
		if ( ! is_array( $link ) || ! is_array( $base ) || empty( $link['scheme'] ) || empty( $link['host'] ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return false;
		}
		$scheme     = strtolower( (string) $link['scheme'] );
		$host       = strtolower( trim( (string) $link['host'], '[]' ) );
		$baseHost   = strtolower( trim( (string) $base['host'], '[]' ) );
		$baseScheme = strtolower( (string) $base['scheme'] );
		$port       = isset( $link['port'] ) ? (int) $link['port'] : ( 'http' === $scheme ? 80 : 443 );
		$basePort   = isset( $base['port'] ) ? (int) $base['port'] : ( 'http' === $baseScheme ? 80 : 443 );
		if ( $scheme === $baseScheme && $host === $baseHost && $port === $basePort ) {
			return true;
		}
		// Another host the connector declares (a vendor with an API host and a download host), on https and the usual port or the base port.
		return array() !== $this->hosts && ! $this->merchantHost && 'https' === $scheme && $this->matchesFixed( $host ) && ( 443 === $port || $port === $basePort );
	}

	/**
	 * Whether a host is a private or reserved IP address, or a local name (localhost, a name without a dot, `.local`, `.internal`, `.lan`).
	 * A host name that is not local is not looked up here; violation() resolves it.
	 *
	 * @param string $host Host name or IP literal.
	 */
	public function isPrivateHost( string $host ): bool {
		$host      = strtolower( trim( $host, '[]' ) );
		$localName = '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) || str_ends_with( $host, '.lan' );
		if ( $localName || ( ! str_contains( $host, '.' ) && ! str_contains( $host, ':' ) ) ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::isPrivateAddress( $host );
		}
		return false;
	}

	/**
	 * Whether an IP address is private, loopback, link-local, carrier-grade NAT, multicast or otherwise reserved. An IPv6 address that carries an
	 * IPv4 address (mapped, NAT64, 6to4) is judged by the IPv4 address inside it. A text that is not an IP address counts as private.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 */
	public static function isPrivateAddress( string $ip ): bool {
		$bin = inet_pton( trim( $ip, '[]' ) );
		if ( false === $bin ) {
			return true;
		}
		if ( 4 === strlen( $bin ) ) {
			foreach ( array( '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4' ) as $net ) {
				if ( self::inNet( $bin, $net ) ) {
					return true;
				}
			}
			return false;
		}
		if ( self::inNet( $bin, '::/96' ) ) {
			return true; // Unspecified, loopback and the old IPv4-compatible form.
		}
		if ( self::inNet( $bin, '::ffff:0:0/96' ) || self::inNet( $bin, '64:ff9b::/96' ) ) {
			return self::isPrivateAddress( (string) inet_ntop( substr( $bin, 12, 4 ) ) );
		}
		if ( self::inNet( $bin, '2002::/16' ) ) {
			return self::isPrivateAddress( (string) inet_ntop( substr( $bin, 2, 4 ) ) );
		}
		foreach ( array( 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001::/32', '2001:db8::/32' ) as $net ) {
			if ( self::inNet( $bin, $net ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a host is written as a number in a form other than the plain dotted IPv4 (decimal `2130706433`, hexadecimal `0x7f.1`, octal
	 * `0177.0.0.1`, short `127.1`). Some systems read those as an IP address while a text check does not.
	 *
	 * @param string $host Lower-case host without brackets.
	 */
	private static function isNumericForm( string $host ): bool {
		if ( str_contains( $host, ':' ) ) {
			return false;
		}
		$labels = explode( '.', $host );
		if ( 1 !== preg_match( '/^(0x[0-9a-f]*|[0-9]+)$/', (string) end( $labels ) ) ) {
			return false; // The last label is a word: a host name.
		}
		return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	}

	/**
	 * Whether a packed address is inside a network.
	 *
	 * @param string $bin Packed address (inet_pton).
	 * @param string $net Network as `address/bits`.
	 */
	private static function inNet( string $bin, string $net ): bool {
		list( $address, $bits ) = explode( '/', $net );
		$base                   = (string) inet_pton( $address );
		if ( strlen( $base ) !== strlen( $bin ) ) {
			return false;
		}
		$bytes = intdiv( (int) $bits, 8 );
		$rest  = (int) $bits % 8;
		if ( substr( $bin, 0, $bytes ) !== substr( $base, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $base[ $bytes ] ) & $mask );
	}

	/**
	 * The addresses a host name resolves to (A and AAAA).
	 *
	 * @param string $host Host name.
	 * @return array<int,string>
	 */
	private function addressesOf( string $host ): array {
		$resolver = $this->resolver ?? self::$defaultResolver;
		if ( null !== $resolver ) {
			return array_values( array_filter( array_map( 'strval', (array) $resolver( $host ) ), static fn( string $ip ): bool => '' !== $ip ) );
		}
		$now = time();
		if ( isset( self::$answers[ $host ] ) && self::$answers[ $host ]['until'] > $now ) {
			return self::$answers[ $host ]['ips'];
		}
		$ips = self::lookUp( $host );
		if ( count( self::$answers ) > 64 ) {
			self::$answers = array();
		}
		self::$answers[ $host ] = array(
			'until' => $now + 60,
			'ips'   => $ips,
		);
		return $ips;
	}

	/**
	 * Ask the system resolver for A and AAAA records. A failure is an empty list.
	 *
	 * @param string $host Host name.
	 * @return array<int,string>
	 */
	private static function lookUp( string $host ): array {
		$ips = array();
		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- a failed lookup warns; the answer is the empty list.
		try {
			$records = function_exists( 'dns_get_record' ) ? dns_get_record( $host, DNS_A | DNS_AAAA ) : false;
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				} elseif ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
			if ( array() === $ips && function_exists( 'gethostbynamel' ) ) {
				$list = gethostbynamel( $host );
				$ips  = is_array( $list ) ? array_map( 'strval', $list ) : array();
			}
		} finally {
			restore_error_handler();
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * Whether a host matches one of the fixed hosts.
	 *
	 * @param string $host Lower-case host.
	 */
	private function matchesFixed( string $host ): bool {
		foreach ( $this->hosts as $allowed ) {
			$allowed = strtolower( trim( $allowed ) );
			if ( str_starts_with( $allowed, '*.' ) ) {
				$suffix = substr( $allowed, 1 );
				if ( str_ends_with( $host, $suffix ) && strlen( $host ) > strlen( $suffix ) ) {
					return true;
				}
				continue;
			}
			if ( $host === $allowed ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the site owner allows private hosts.
	 *
	 * @param string $url URL.
	 */
	private function privateAllowed( string $url ): bool {
		if ( null !== $this->allowPrivate ) {
			return (bool) ( $this->allowPrivate )( $url );
		}
		return (bool) apply_filters( 'estasoft_sync_allow_private_hosts', false, $url );
	}
}
