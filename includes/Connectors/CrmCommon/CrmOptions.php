<?php
/**
 * Connector options read from a connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Typed access to the options of a connection: the keys under `config.options`, or the same keys directly in `config`.
 */
final class CrmOptions {

	/**
	 * Create the object.
	 *
	 * @param array<string,mixed> $values Raw option values.
	 */
	public function __construct( private readonly array $values = array() ) {
	}

	/**
	 * Read the named options from a connection.
	 *
	 * @param Connection        $connection Connection.
	 * @param array<int,string> $keys       Option keys the connector knows.
	 */
	public static function fromConnection( Connection $connection, array $keys ): self {
		$values = array();
		foreach ( $keys as $key ) {
			$value = $connection->config( $key );
			if ( is_scalar( $value ) ) {
				$values[ $key ] = $value;
			}
		}
		$options = $connection->config( 'options' );
		if ( is_array( $options ) ) {
			$values = $options + $values;
		}
		return new self( $values );
	}

	/**
	 * A trimmed text option.
	 *
	 * @param string $key      Option key.
	 * @param string $fallback Value when missing or empty.
	 */
	public function text( string $key, string $fallback = '' ): string {
		$value = $this->values[ $key ] ?? null;
		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : $fallback;
	}

	/**
	 * One of a list of allowed words.
	 *
	 * @param string            $key      Option key.
	 * @param array<int,string> $allowed  Allowed lower-case values.
	 * @param string            $fallback Value when missing or not allowed.
	 */
	public function choice( string $key, array $allowed, string $fallback ): string {
		$value = strtolower( $this->text( $key, $fallback ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * A yes or no option.
	 *
	 * @param string $key      Option key.
	 * @param bool   $fallback Value when missing.
	 */
	public function flag( string $key, bool $fallback ): bool {
		if ( ! array_key_exists( $key, $this->values ) ) {
			return $fallback;
		}
		$value = $this->values[ $key ];
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) ( is_scalar( $value ) ? $value : '' ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
