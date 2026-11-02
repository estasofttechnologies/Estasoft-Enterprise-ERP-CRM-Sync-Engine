<?php
/**
 * Transform: remove spaces around a value.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: collapse (true to also squeeze runs of spaces inside the text into one). Arabic letters and digits are never touched.
 */
final class Trim extends BaseTransform {

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'trim';
	}

	/**
	 * Trim the value.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$text = $this->scalarText( $value );
		if ( null === $text ) {
			return $value;
		}
		$text = Text::trim( $text );
		return $this->flag( $args, 'collapse' ) ? Text::collapseSpaces( $text ) : $text;
	}
}
