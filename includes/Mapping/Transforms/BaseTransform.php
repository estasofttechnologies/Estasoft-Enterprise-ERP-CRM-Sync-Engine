<?php
/**
 * Shared argument reading for transforms.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Most transforms handle one value at a time and take a few plain arguments. This base class supplies those defaults.
 */
abstract class BaseTransform implements Transform {

	/**
	 * By default a list is handled entry by entry.
	 */
	public function handlesLists(): bool {
		return false;
	}

	/**
	 * By default every argument is accepted.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		return array();
	}

	/**
	 * Read a text argument.
	 *
	 * @param array<string,mixed> $args     Arguments.
	 * @param string              $key      Name.
	 * @param string              $fallback Value when absent.
	 */
	protected function text( array $args, string $key, string $fallback = '' ): string {
		return isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ? Text::toString( $args[ $key ] ) : $fallback;
	}

	/**
	 * Read a yes/no argument ("true", "yes", "1" and true count as yes).
	 *
	 * @param array<string,mixed> $args     Arguments.
	 * @param string              $key      Name.
	 * @param bool                $fallback Value when absent.
	 */
	protected function flag( array $args, string $key, bool $fallback = false ): bool {
		if ( ! array_key_exists( $key, $args ) ) {
			return $fallback;
		}
		$value = $args[ $key ];
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( Text::toString( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * A scalar as text, or null for anything else (null, lists, objects).
	 *
	 * @param mixed $value Value.
	 */
	protected function scalarText( mixed $value ): ?string {
		return is_scalar( $value ) ? Text::toString( $value ) : null;
	}

	/**
	 * A problem message for a missing or wrong argument.
	 *
	 * @param string $key Name of the argument.
	 */
	protected function missing( string $key ): string {
		/* translators: %s: name of a rule argument, for example separator. */
		return sprintf( __( 'The argument ‘%s’ is missing or not valid.', 'estasoft-enterprise-erpcrm-sync-engine' ), $key );
	}
}
