<?php
/**
 * Typed settings of an export connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * An export connection keeps no secret. Its settings (company name, ledger names, account numbers) sit in the connection's config,
 * either as keys of `options` or directly as keys of the config. Every getter has a fallback, so a half-filled connection still
 * reads; the vendor settings classes decide what must be filled before a file is made.
 */
final class ExportOptions {

	/**
	 * Set up with the values.
	 *
	 * @param array<string,mixed> $values Setting values by key.
	 */
	public function __construct( private readonly array $values = array() ) {
	}

	/**
	 * Read the settings of a connection.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $keys       Keys the connector knows.
	 */
	public static function fromConnection( Connection $connection, array $keys ): self {
		$values = array();
		foreach ( $keys as $key ) {
			$value = $connection->config( $key );
			if ( is_scalar( $value ) || is_array( $value ) ) {
				$values[ $key ] = $value;
			}
		}
		$options = $connection->config( 'options' );
		if ( is_array( $options ) ) {
			$values = array_intersect_key( $options, array_flip( $keys ) ) + $values;
		}
		return new self( $values );
	}

	/**
	 * A text setting, trimmed.
	 *
	 * @param string $key      Key.
	 * @param string $fallback Value when absent or empty.
	 */
	public function text( string $key, string $fallback = '' ): string {
		$value = $this->values[ $key ] ?? null;
		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}
		$text = Utf8::line( (string) $value );
		return '' === $text ? $fallback : $text;
	}

	/**
	 * One of a fixed list of words.
	 *
	 * @param string            $key      Key.
	 * @param array<int,string> $allowed  Allowed values.
	 * @param string            $fallback Value when absent or not allowed.
	 */
	public function choice( string $key, array $allowed, string $fallback ): string {
		$value = strtolower( $this->text( $key, $fallback ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * A yes or no setting ("1", "yes", "true" and "on" mean yes).
	 *
	 * @param string $key      Key.
	 * @param bool   $fallback Value when absent.
	 */
	public function flag( string $key, bool $fallback ): bool {
		if ( ! array_key_exists( $key, $this->values ) ) {
			return $fallback;
		}
		$value = $this->values[ $key ];
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( is_scalar( $value ) ? (string) $value : '' ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * A whole number within limits.
	 *
	 * @param string $key      Key.
	 * @param int    $fallback Value when absent or not a number.
	 * @param int    $min      Smallest allowed.
	 * @param int    $max      Largest allowed.
	 */
	public function integer( string $key, int $fallback, int $min, int $max ): int {
		$value = $this->values[ $key ] ?? null;
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * A table setting (payment method id to ledger, account or nominal).
	 *
	 * @param string $key Key.
	 * @return array<string,string> Empty values are dropped.
	 */
	public function table( string $key ): array {
		$value = $this->values[ $key ] ?? null;
		$table = array();
		if ( is_string( $value ) ) {
			$value = $this->lines( $value );
		}
		if ( ! is_array( $value ) ) {
			return $table;
		}
		foreach ( $value as $name => $entry ) {
			$name  = Utf8::line( (string) $name );
			$entry = is_scalar( $entry ) ? Utf8::line( (string) $entry ) : '';
			if ( '' !== $name && '' !== $entry ) {
				$table[ $name ] = $entry;
			}
		}
		return $table;
	}

	/**
	 * A table typed as lines ("stripe = Bank A/c", one per line) read into pairs.
	 *
	 * @param string $text Lines.
	 * @return array<string,string>
	 */
	private function lines( string $text ): array {
		$pairs = array();
		foreach ( (array) preg_split( '/\R/u', $text ) as $line ) {
			$parts = explode( '=', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$pairs[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}
		return $pairs;
	}
}
