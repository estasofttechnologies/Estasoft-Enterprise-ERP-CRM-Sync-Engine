<?php
/**
 * Removes secrets from text that may reach a message or a log.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Every error message built from a vendor answer goes through here. Known secret values (and their URL-encoded form) are replaced, and
 * so are the shapes tokens and keys travel in: Authorization schemes, `access_token=...`, `"client_secret": "..."`, and long hex or
 * token-like strings that follow a secret-looking name. Hostnames and tenant identifiers can be added as values too.
 */
final class Redaction {

	/**
	 * Replacement for a removed value.
	 */
	public const MASK = '[secret]';

	/**
	 * Secret names the base always treats as secret, next to the fields a connector marks as secret.
	 */
	public const RESERVED_SECRETS = array( 'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at', 'oauth_refresh_expires_at', 'client_secret', 'private_key', 'api_key', 'api_secret', 'password', 'token', 'access_token', 'refresh_token', 'session_id' );

	/**
	 * Values to remove.
	 *
	 * @var array<int,string>
	 */
	private array $values = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<int,string> $values Exact values to remove.
	 */
	public function __construct( array $values = array() ) {
		foreach ( $values as $value ) {
			$this->add( $value );
		}
	}

	/**
	 * Redaction for a connection: the secrets named by the connector and the reserved ones, plus extra values (hosts, tenant ids).
	 *
	 * @param Connection        $connection  Connection with its secrets.
	 * @param array<int,string> $secretNames Names of the secret fields.
	 * @param array<int,string> $extra       Other values to remove (hostnames, tenant ids).
	 */
	public static function forConnection( Connection $connection, array $secretNames, array $extra = array() ): self {
		$redaction = new self( $extra );
		foreach ( array_unique( array_merge( $secretNames, self::RESERVED_SECRETS ) ) as $name ) {
			$redaction->add( $connection->secret( (string) $name ) );
		}
		return $redaction;
	}

	/**
	 * Add one value (shorter than four characters is ignored: it would damage ordinary text).
	 *
	 * @param string $value Value.
	 */
	public function add( string $value ): self {
		$value = trim( $value );
		if ( strlen( $value ) < 4 || in_array( $value, $this->values, true ) ) {
			return $this;
		}
		$this->values[] = $value;
		// Long multi-line values (private keys) are removed line by line as well.
		if ( false !== strpos( $value, "\n" ) ) {
			foreach ( (array) preg_split( '/\R/', $value ) as $line ) {
				$line = trim( $line );
				if ( strlen( $line ) >= 12 && ! in_array( $line, $this->values, true ) ) {
					$this->values[] = $line;
				}
			}
		}
		return $this;
	}

	/**
	 * Add the host of a URL.
	 *
	 * @param string $url URL or host.
	 */
	public function addHost( string $url ): self {
		$host = false === strpos( $url, '://' ) ? $url : (string) wp_parse_url( $url, PHP_URL_HOST );
		return $this->add( $host );
	}

	/**
	 * Remove secrets from a text.
	 *
	 * @param string $text Text.
	 */
	public function scrub( string $text ): string {
		foreach ( $this->values as $value ) {
			$text = str_replace( array( $value, rawurlencode( $value ), urlencode( $value ) ), self::MASK, $text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- plain URL encoding of a value to find in text.
		}
		$text = (string) preg_replace( '/\b(Bearer|Basic|Token|Digest)\s+[A-Za-z0-9\-._~+\/]{6,}=*/i', '$1 ' . self::MASK, $text );
		$text = (string) preg_replace( '/((?:access_token|refresh_token|id_token|client_secret|api[_-]?key|apikey|password|passwd|secret|assertion|private_key|session[_-]?id|token|signature)["\']?\s*[:=]\s*["\']?)[^"\'&\s,}\]]+/i', '$1' . self::MASK, $text );
		return $text;
	}

	/**
	 * A short, single-line reason taken from a vendor message: tags and control characters removed, secrets removed, cut with an ellipsis.
	 *
	 * @param string $text Vendor text.
	 * @param int    $max  Longest result in characters.
	 */
	public function reason( string $text, int $max = 200 ): string {
		$text = (string) preg_replace( '/<[^>]*>/', ' ', $text );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $this->scrub( $text ) ) );
		if ( mb_strlen( $text ) > $max ) {
			$text = rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}
		return $text;
	}
}
