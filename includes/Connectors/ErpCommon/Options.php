<?php
/**
 * Connection options of an ERP connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Reads settings from the connection's config. A setting is looked up in `config.options` first and then directly in the config,
 * the same two places the Odoo connector reads. Every reader checks the type, so a hand-edited value cannot break a send.
 */
final class Options {

	/**
	 * Set up with the services it uses.
	 *
	 * @param array<string,mixed> $values Config values.
	 */
	public function __construct( private readonly array $values ) {
	}

	/**
	 * The options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function of( Connection $connection ): self {
		$values  = array();
		$options = $connection->config( 'options' );
		if ( is_array( $options ) ) {
			$values = $options;
		}
		foreach ( $connection->config as $key => $value ) {
			if ( ! array_key_exists( $key, $values ) && 'options' !== $key ) {
				$values[ $key ] = $value;
			}
		}
		return new self( $values );
	}

	/**
	 * Text, trimmed; empty when absent or not text.
	 *
	 * @param string $key Option name.
	 */
	public function text( string $key ): string {
		$value = $this->values[ $key ] ?? '';
		return is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * One of a fixed list.
	 *
	 * @param string            $key      Option name.
	 * @param array<int,string> $allowed  Allowed values.
	 * @param string            $fallback Value when absent or not allowed.
	 */
	public function choice( string $key, array $allowed, string $fallback ): string {
		$value = strtolower( $this->text( $key ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * A yes or no option.
	 *
	 * @param string $key      Option name.
	 * @param bool   $fallback Value when absent or unclear.
	 */
	public function flag( string $key, bool $fallback ): bool {
		return PayloadReader::flag( $this->values[ $key ] ?? null, $fallback );
	}

	/**
	 * A whole number inside a range.
	 *
	 * @param string $key      Option name.
	 * @param int    $min      Smallest value.
	 * @param int    $max      Largest value.
	 * @param int    $fallback Value when absent or not a number.
	 */
	public function integer( string $key, int $min, int $max, int $fallback ): int {
		$value = $this->values[ $key ] ?? null;
		if ( is_numeric( $value ) ) {
			return max( $min, min( $max, (int) $value ) );
		}
		return $fallback;
	}
}
