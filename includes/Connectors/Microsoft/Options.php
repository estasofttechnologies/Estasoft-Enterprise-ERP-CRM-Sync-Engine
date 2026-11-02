<?php
/**
 * Connection options, read once.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Options come from the connection's `config.options`, or from the same keys directly in the config. A value that is not on the list of
 * allowed values falls back to the default, so a typo can never switch on something dangerous.
 */
final class Options {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param array<string,mixed> $values Raw values.
	 */
	public function __construct( private readonly array $values = array() ) {
	}

	/**
	 * Read the options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function fromConnection( Connection $connection ): self {
		$nested = $connection->config( 'options', array() );
		$values = is_array( $nested ) ? $nested : array();
		return new self( $values + $connection->config );
	}

	/**
	 * A value from a list.
	 *
	 * @param string            $key     Option key.
	 * @param array<int,string> $allowed Allowed values.
	 * @param string            $fallback Value when absent or not allowed.
	 */
	public function choice( string $key, array $allowed, string $fallback ): string {
		$value = $this->values[ $key ] ?? null;
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * A free text, trimmed.
	 *
	 * @param string $key     Option key.
	 * @param string $fallback Value when absent.
	 */
	public function text( string $key, string $fallback = '' ): string {
		$value = $this->values[ $key ] ?? null;
		return is_string( $value ) || is_int( $value ) ? trim( (string) $value ) : $fallback;
	}

	/**
	 * A yes/no value.
	 *
	 * @param string $key     Option key.
	 * @param bool   $fallback Value when absent.
	 */
	public function flag( string $key, bool $fallback ): bool {
		$value = $this->values[ $key ] ?? null;
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true ) ? true : ( in_array( strtolower( trim( $value ) ), array( '0', 'false', 'no', 'off' ), true ) ? false : $fallback );
		}
		return $fallback;
	}
}
