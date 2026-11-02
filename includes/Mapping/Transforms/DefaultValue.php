<?php
/**
 * Transform: use a fallback when a value is empty.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: value (fixed text to use) or from (another canonical path to read, for example customer.phone).
 * A value that is not empty is kept as it is.
 */
final class DefaultValue extends BaseTransform {

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'default';
	}

	/**
	 * Replace an empty value.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		if ( ! Text::isEmpty( $value ) ) {
			return $value;
		}
		$from = $this->text( $args, 'from' );
		if ( '' !== $from ) {
			return $context->read( $from );
		}
		return $this->text( $args, 'value' );
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$from = $this->text( $args, 'from' );
		if ( '' !== $from ) {
			return CanonicalOrder::isValidPath( $from ) ? array() : array( $this->missing( 'from' ) );
		}
		return array_key_exists( 'value', $args ) && is_scalar( $args['value'] ) ? array() : array( $this->missing( 'value' ) );
	}
}
