<?php
/**
 * An Odoo server version.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

/**
 * Parsed from GET /web/version (Odoo 19 and later) or from the common service's version call (17 and 18).
 * Chooses the transport: JSON-2 from 19, JSON-RPC for 17 and 18 (including Odoo Online "saas~18.x" releases).
 */
final class OdooVersion {

	/**
	 * Oldest major version the connector supports.
	 */
	public const MIN_MAJOR = 17;

	/**
	 * First major version with the JSON-2 API.
	 */
	public const JSON2_MAJOR = 19;

	/**
	 * Set up with what it depends on.
	 *
	 * @param int    $major      Major number, for example 18.
	 * @param int    $minor      Minor number, for example 0 or 4 (saas releases).
	 * @param bool   $saas       True for an Odoo Online release such as saas~18.4.
	 * @param bool   $enterprise True when the server reports the Enterprise edition.
	 * @param string $raw        The version text as the server sent it.
	 */
	public function __construct(
		public readonly int $major,
		public readonly int $minor,
		public readonly bool $saas = false,
		public readonly bool $enterprise = false,
		public readonly string $raw = '',
	) {
	}

	/**
	 * Build from the answer of /web/version or of the common service's version call.
	 *
	 * @param array<string,mixed> $answer Decoded JSON. Reads "version" and "version_info" (or "server_version" and "server_version_info").
	 * @return self|null Null when no version can be read.
	 */
	public static function fromAnswer( array $answer ): ?self {
		$text = $answer['version'] ?? $answer['server_version'] ?? '';
		$info = $answer['version_info'] ?? $answer['server_version_info'] ?? array();
		$text = is_scalar( $text ) ? trim( (string) $text ) : '';
		$info = is_array( $info ) ? $info : array();

		if ( 1 === preg_match( '/^(saas~)?(\d{1,3})\.(\d{1,3})/', $text, $parts ) ) {
			$major = (int) $parts[2];
			$minor = (int) $parts[3];
			$saas  = '' !== $parts[1];
		} elseif ( isset( $info[0], $info[1] ) && is_numeric( $info[0] ) && is_numeric( $info[1] ) ) {
			$major = (int) $info[0];
			$minor = (int) $info[1];
			$saas  = false;
			$text  = $major . '.' . $minor;
		} elseif ( isset( $info[0], $info[1] ) && is_string( $info[0] ) && 1 === preg_match( '/^saas~(\d{1,3})$/', $info[0], $saasParts ) && is_numeric( $info[1] ) ) {
			$major = (int) $saasParts[1];
			$minor = (int) $info[1];
			$saas  = true;
			$text  = $info[0] . '.' . $minor;
		} else {
			return null;
		}

		$flag       = isset( $info[5] ) && is_string( $info[5] ) ? $info[5] : '';
		$enterprise = str_contains( $flag, 'e' ) || 1 === preg_match( '/\+e\b/', $text );

		return new self( $major, $minor, $saas, $enterprise, $text );
	}

	/**
	 * Whether the connector supports this version (17 and later).
	 */
	public function isSupported(): bool {
		return $this->major >= self::MIN_MAJOR;
	}

	/**
	 * Whether this version has the JSON-2 API. Odoo Online releases before 19 use JSON-RPC.
	 */
	public function usesJson2(): bool {
		return $this->major >= self::JSON2_MAJOR;
	}

	/**
	 * Whether this version is at least the given major number.
	 *
	 * @param int $major Major number.
	 */
	public function atLeast( int $major ): bool {
		return $this->major >= $major;
	}

	/**
	 * Id of the transport this version uses: json2 or jsonrpc.
	 */
	public function transportId(): string {
		return $this->usesJson2() ? 'json2' : 'jsonrpc';
	}

	/**
	 * Text for screens, for example "Odoo 18 Enterprise" or "Odoo saas~18.4 Community".
	 */
	public function label(): string {
		if ( $this->saas ) {
			$number = 'saas~' . $this->major . '.' . $this->minor;
		} else {
			$number = 0 === $this->minor ? (string) $this->major : $this->major . '.' . $this->minor;
		}
		return 'Odoo ' . $number . ( $this->enterprise ? ' Enterprise' : ' Community' );
	}

	/**
	 * Facts stored on the connection.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'version'    => $this->raw,
			'major'      => $this->major,
			'minor'      => $this->minor,
			'saas'       => $this->saas,
			'enterprise' => $this->enterprise,
			'transport'  => $this->transportId(),
		);
	}
}
