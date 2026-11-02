<?php
/**
 * The settings of a connection that are not secrets.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EuAccountingCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Reads the advanced fields of the connect screen from the connection's config (directly, or under `options`) and gives each one a checked
 * value: a value outside its list falls back to the default, so a typo can never switch a feature on. The schema maps a key to
 * `array( 'type' => 'choice'|'text'|'int', 'default' => ..., 'allowed' => array(...), 'min' => n, 'max' => n )`.
 */
final class Options {

	/**
	 * Set up the object.
	 *
	 * @param array<string,mixed>               $values Raw option values by key.
	 * @param array<string,array<string,mixed>> $schema Option definitions.
	 */
	public function __construct( private readonly array $values, private readonly array $schema ) {
	}

	/**
	 * The options of a connection.
	 *
	 * @param Connection                        $connection Connection.
	 * @param array<string,array<string,mixed>> $schema     Option definitions.
	 */
	public static function fromConnection( Connection $connection, array $schema ): self {
		$nested = $connection->config( 'options' );
		$nested = is_array( $nested ) ? $nested : array();
		$values = array();
		foreach ( array_keys( $schema ) as $key ) {
			$value = $connection->config( $key );
			if ( null === $value && array_key_exists( $key, $nested ) ) {
				$value = $nested[ $key ];
			}
			if ( is_scalar( $value ) ) {
				$values[ $key ] = trim( (string) $value );
			}
		}
		return new self( $values, $schema );
	}

	/**
	 * A choice: the value when it is in the list, else the default.
	 *
	 * @param string $key Option key.
	 */
	public function choice( string $key ): string {
		$def     = $this->schema[ $key ] ?? array();
		$default = (string) ( $def['default'] ?? '' );
		$value   = strtolower( (string) ( $this->values[ $key ] ?? '' ) );
		return in_array( $value, (array) ( $def['allowed'] ?? array() ), true ) ? $value : $default;
	}

	/**
	 * A text value, trimmed; the default when the option was never set.
	 *
	 * @param string $key Option key.
	 */
	public function text( string $key ): string {
		$def = $this->schema[ $key ] ?? array();
		if ( ! array_key_exists( $key, $this->values ) ) {
			return (string) ( $def['default'] ?? '' );
		}
		return (string) $this->values[ $key ];
	}

	/**
	 * A whole number within the schema's bounds; the default when the value is missing or out of range.
	 *
	 * @param string $key Option key.
	 */
	public function int( string $key ): int {
		$def     = $this->schema[ $key ] ?? array();
		$default = (int) ( $def['default'] ?? 0 );
		$raw     = $this->values[ $key ] ?? '';
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^\d+$/', $raw ) ) {
			return $default;
		}
		$value = (int) $raw;
		if ( $value < (int) ( $def['min'] ?? 0 ) || $value > (int) ( $def['max'] ?? PHP_INT_MAX ) ) {
			return $default;
		}
		return $value;
	}

	/**
	 * Whether a choice option equals a value.
	 *
	 * @param string $key   Option key.
	 * @param string $value Wanted value.
	 */
	public function is( string $key, string $value ): bool {
		return $this->choice( $key ) === $value;
	}
}
