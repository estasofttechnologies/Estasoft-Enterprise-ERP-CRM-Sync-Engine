<?php
/**
 * Transform: split a text into parts.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: separator (required), limit (at most this many parts; the last part keeps the rest), index (return only this part;
 * 0 is the first, -1 the last). Without index the result is a list.
 */
final class Split extends BaseTransform {

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'split';
	}

	/**
	 * Split the text.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 *
	 * @throws TransformArgumentException When the separator is missing.
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$separator = $this->text( $args, 'separator' );
		if ( '' === $separator ) {
			throw new TransformArgumentException( esc_html( $this->missing( 'separator' ) ) );
		}
		$text = $this->scalarText( $value );
		if ( null === $text || '' === Text::trim( $text ) ) {
			return $value;
		}
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		$raw   = $limit >= 2 ? explode( $separator, $text, $limit ) : explode( $separator, $text );
		$parts = array();
		foreach ( $raw as $part ) {
			$part = Text::trim( $part );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		if ( ! isset( $args['index'] ) || '' === $args['index'] ) {
			return $parts;
		}
		$index = (int) $args['index'];
		if ( $index < 0 ) {
			$index += count( $parts );
		}
		return $parts[ $index ] ?? '';
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$problems = array();
		if ( '' === $this->text( $args, 'separator' ) ) {
			$problems[] = $this->missing( 'separator' );
		}
		foreach ( array( 'limit', 'index' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== $args[ $key ] && ! is_numeric( $args[ $key ] ) ) {
				$problems[] = $this->missing( $key );
			}
		}
		return $problems;
	}
}
