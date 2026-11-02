<?php
/**
 * Transform: change letter case.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: mode (upper, lower or title). Scripts without case, Arabic included, come back unchanged.
 */
final class CaseConvert extends BaseTransform {

	/**
	 * Modes that can be chosen.
	 */
	private const MODES = array( 'upper', 'lower', 'title' );

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'case';
	}

	/**
	 * Change the case.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 *
	 * @throws TransformArgumentException When the mode is not one of upper, lower, title.
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$mode = $this->text( $args, 'mode' );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new TransformArgumentException( esc_html( $this->missing( 'mode' ) ) );
		}
		$text = $this->scalarText( $value );
		if ( null === $text || '' === $text ) {
			return $value;
		}
		return match ( $mode ) {
			'upper' => Text::upper( $text ),
			'lower' => Text::lower( $text ),
			default => Text::title( $text ),
		};
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		return in_array( $this->text( $args, 'mode' ), self::MODES, true ) ? array() : array( $this->missing( 'mode' ) );
	}
}
