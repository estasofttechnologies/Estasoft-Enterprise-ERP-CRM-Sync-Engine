<?php
/**
 * Transform: choose between two values by a condition on the current value.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: operator (equals, not_equals, contains, empty, not_empty, gt or lt), value (what to compare with; not needed for empty and
 * not_empty), then (result when the condition holds), else (result when it does not; without it the value is kept).
 * Numbers compare as numbers, everything else as text, ignoring case.
 */
final class IfThen extends BaseTransform {

	/**
	 * Operators that can be chosen.
	 */
	private const OPERATORS = array( 'equals', 'not_equals', 'contains', 'empty', 'not_empty', 'gt', 'lt' );

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'if_then';
	}

	/**
	 * Apply the condition.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 *
	 * @throws TransformArgumentException When the operator or "then" is missing.
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$problems = $this->check( $args );
		if ( array() !== $problems ) {
			throw new TransformArgumentException( esc_html( $problems[0] ) );
		}
		if ( $this->holds( $value, $this->text( $args, 'operator' ), $this->text( $args, 'value' ) ) ) {
			return $args['then'];
		}
		return array_key_exists( 'else', $args ) ? $args['else'] : $value;
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$problems = array();
		$operator = $this->text( $args, 'operator' );
		if ( ! in_array( $operator, self::OPERATORS, true ) ) {
			$problems[] = $this->missing( 'operator' );
		}
		if ( ! in_array( $operator, array( 'empty', 'not_empty' ), true ) && ( ! array_key_exists( 'value', $args ) || ! is_scalar( $args['value'] ) ) ) {
			$problems[] = $this->missing( 'value' );
		}
		if ( ! array_key_exists( 'then', $args ) || ! is_scalar( $args['then'] ) ) {
			$problems[] = $this->missing( 'then' );
		}
		return $problems;
	}

	/**
	 * Evaluate the condition.
	 *
	 * @param mixed  $value    Current value.
	 * @param string $operator Operator.
	 * @param string $expected Value to compare with.
	 */
	private function holds( mixed $value, string $operator, string $expected ): bool {
		if ( 'empty' === $operator ) {
			return Text::isEmpty( $value );
		}
		if ( 'not_empty' === $operator ) {
			return ! Text::isEmpty( $value );
		}
		$actual = $this->scalarText( $value ) ?? '';
		if ( in_array( $operator, array( 'gt', 'lt' ), true ) ) {
			if ( ! is_numeric( $actual ) || ! is_numeric( $expected ) ) {
				return false;
			}
			return 'gt' === $operator ? (float) $actual > (float) $expected : (float) $actual < (float) $expected;
		}
		$left  = Text::fold( $actual );
		$right = Text::fold( $expected );
		return match ( $operator ) {
			'equals'     => $left === $right,
			'not_equals' => $left !== $right,
			default      => '' !== $right && false !== strpos( $left, $right ),
		};
	}
}
