<?php
/**
 * Connection options of an ERP connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Reads the option block of a connection (`config.options`, or the same keys directly in the config) with defaults and limits, so a
 * wrong value never reaches a request.
 */
final class Options {

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<string,mixed> $values Option values.
	 */
	public function __construct( private readonly array $values = array() ) {
	}

	/**
	 * Options of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function from( Connection $connection ): self {
		$config  = $connection->config;
		$options = isset( $config['options'] ) && is_array( $config['options'] ) ? $config['options'] : array();
		return new self( $options + $config );
	}

	/**
	 * A text option, trimmed.
	 *
	 * @param string $key      Key.
	 * @param string $fallback Value when absent.
	 */
	public function text( string $key, string $fallback = '' ): string {
		$value = $this->values[ $key ] ?? null;
		return is_scalar( $value ) && ! is_bool( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : $fallback;
	}

	/**
	 * A choice from a fixed list.
	 *
	 * @param string            $key      Key.
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
	 * @param string $key      Key.
	 * @param bool   $fallback Value when absent.
	 */
	public function flag( string $key, bool $fallback ): bool {
		return PayloadReader::flag( $this->values[ $key ] ?? null, $fallback );
	}

	/**
	 * A whole number option inside limits.
	 *
	 * @param string $key      Key.
	 * @param int    $min      Smallest accepted.
	 * @param int    $max      Largest accepted.
	 * @param int    $fallback Value when absent or not a number.
	 */
	public function integer( string $key, int $min, int $max, int $fallback ): int {
		$value = $this->values[ $key ] ?? null;
		return is_numeric( $value ) ? max( $min, min( $max, (int) $value ) ) : $fallback;
	}
}
