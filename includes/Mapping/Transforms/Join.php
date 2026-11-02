<?php
/**
 * Transform: join several values into one text.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: separator (default one space), skip_empty (default true). Receives the whole list, for example the first and last name.
 */
final class Join extends BaseTransform {

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'join';
	}

	/**
	 * This transform works on the whole list.
	 */
	public function handlesLists(): bool {
		return true;
	}

	/**
	 * Join the parts.
	 *
	 * @param mixed               $value   List of values, or one value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$separator = $this->text( $args, 'separator', ' ' );
		$skip      = $this->flag( $args, 'skip_empty', true );
		$parts     = array();
		foreach ( $value as $part ) {
			$text = $this->scalarText( $part );
			if ( null === $text ) {
				continue;
			}
			$text = Text::trim( $text );
			if ( $skip && '' === $text ) {
				continue;
			}
			$parts[] = $text;
		}
		return implode( $separator, $parts );
	}
}
