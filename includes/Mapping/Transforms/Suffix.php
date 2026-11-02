<?php
/**
 * Transform: add text after a value.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

defined( 'ABSPATH' ) || exit;

/**
 * Args: value (text to add). Empty values stay empty.
 */
final class Suffix extends BaseTransform {

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'suffix';
	}

	/**
	 * Add the suffix.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$text = $this->scalarText( $value );
		if ( null === $text || '' === $text ) {
			return $value;
		}
		return $text . $this->text( $args, 'value' );
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		return isset( $args['value'] ) && is_scalar( $args['value'] ) ? array() : array( $this->missing( 'value' ) );
	}
}
