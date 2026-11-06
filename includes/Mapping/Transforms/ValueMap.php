<?php
/**
 * Transform: look a value up in a table.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: map (table of "from" to "to", for example cod to Pay on delivery), else (value for anything not in the table;
 * without it the original value is kept), ignore_case (compare ignoring case and accents), strict (true to leave an unmapped value empty with a warning).
 */
final class ValueMap extends BaseTransform {

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'value_map';
	}

	/**
	 * Look the value up.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 *
	 * @throws TransformArgumentException When the table is missing.
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$map = $args['map'] ?? null;
		if ( ! is_array( $map ) || array() === $map ) {
			throw new TransformArgumentException( esc_html( $this->missing( 'map' ) ) );
		}
		$text = $this->scalarText( $value );
		if ( null === $text || '' === $text ) {
			return $value;
		}
		$ignore = $this->flag( $args, 'ignore_case' );
		$needle = $ignore ? Text::fold( $text ) : $text;
		foreach ( $map as $from => $to ) {
			$key = $ignore ? Text::fold( (string) $from ) : (string) $from;
			if ( $key === $needle ) {
				return is_scalar( $to ) ? $to : '';
			}
		}
		if ( array_key_exists( 'else', $args ) ) {
			return $args['else'];
		}
		if ( $this->flag( $args, 'strict' ) ) {
			$context->warn( __( 'A value had no entry in the lookup table and was left empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			return '';
		}
		return $value;
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$map = $args['map'] ?? null;
		return is_array( $map ) && array() !== $map ? array() : array( $this->missing( 'map' ) );
	}
}
