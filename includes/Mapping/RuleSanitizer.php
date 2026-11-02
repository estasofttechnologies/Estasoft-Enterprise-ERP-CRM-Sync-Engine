<?php
/**
 * Cleans rules received from outside (REST) before they are stored.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps only the keys of the rule model, with the types the model allows: text, whole numbers, true or false, and one level of
 * text-to-text tables for value_map. Control characters are removed from all text. It does not judge whether a rule makes sense;
 * RuleValidator does that, so a mistake is reported instead of silently repaired.
 */
final class RuleSanitizer {

	/**
	 * Most rules in one mapping.
	 */
	public const MAX_RULES = 500;

	/**
	 * Most steps in one rule.
	 */
	public const MAX_TRANSFORMS = 20;

	/**
	 * Most entries in a lookup table.
	 */
	public const MAX_MAP_ENTRIES = 500;

	/**
	 * Clean a list of rules.
	 *
	 * @param array<int|string,mixed> $rules Rules as received.
	 * @return array<int,array<string,mixed>>
	 */
	public function clean( array $rules ): array {
		$clean = array();
		foreach ( array_slice( array_values( $rules ), 0, self::MAX_RULES ) as $rule ) {
			if ( is_array( $rule ) ) {
				$clean[] = $this->rule( $rule );
			}
		}
		return $clean;
	}

	/**
	 * Clean one rule.
	 *
	 * @param array<string,mixed> $rule Rule as received.
	 * @return array<string,mixed>
	 */
	private function rule( array $rule ): array {
		$clean = array(
			'id'         => $this->text( $rule['id'] ?? '', 64 ),
			'target'     => $this->text( $rule['target'] ?? '', 191 ),
			'source'     => $this->source( $rule['source'] ?? '' ),
			'transforms' => $this->transforms( $rule['transforms'] ?? array() ),
			'required'   => ! empty( $rule['required'] ),
		);
		$code  = $this->text( $rule['error_code'] ?? '', 32 );
		if ( '' !== $code ) {
			$clean['error_code'] = $code;
		}
		if ( is_array( $rule['when'] ?? null ) ) {
			$clean['when'] = array(
				'source' => $this->text( $rule['when']['source'] ?? '', 191 ),
				'equals' => $this->text( $rule['when']['equals'] ?? '', 191, false ),
			);
		}
		return $clean;
	}

	/**
	 * Clean a source: a path, a list of paths or a constant.
	 *
	 * @param mixed $source Source as received.
	 * @return string|array<int|string,mixed>
	 */
	private function source( mixed $source ): string|array {
		if ( is_array( $source ) && array_key_exists( 'const', $source ) ) {
			$const = $source['const'];
			return array( 'const' => is_bool( $const ) || is_int( $const ) || is_float( $const ) ? $const : $this->text( $const, 1000, false ) );
		}
		if ( is_array( $source ) ) {
			return array_values( array_map( fn( mixed $path ): string => $this->text( $path, 191 ), array_slice( $source, 0, 20 ) ) );
		}
		return $this->text( $source, 191 );
	}

	/**
	 * Clean the transform list.
	 *
	 * @param mixed $transforms Transforms as received.
	 * @return array<int,array{type:string,args:array<string,mixed>}>
	 */
	private function transforms( mixed $transforms ): array {
		$clean = array();
		foreach ( is_array( $transforms ) ? array_slice( array_values( $transforms ), 0, self::MAX_TRANSFORMS ) : array() as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$clean[] = array(
				'type' => $this->text( $step['type'] ?? '', 40 ),
				'args' => $this->args( $step['args'] ?? array() ),
			);
		}
		return $clean;
	}

	/**
	 * Clean transform arguments: scalars, or a table of text to text.
	 *
	 * @param mixed $args Arguments as received.
	 * @return array<string,mixed>
	 */
	private function args( mixed $args ): array {
		$clean = array();
		foreach ( is_array( $args ) ? $args : array() as $name => $value ) {
			$key = $this->text( $name, 40 );
			if ( '' === $key ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = $this->text( $value, 1000, false );
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = $this->table( $value );
			}
		}
		return $clean;
	}

	/**
	 * Clean a text-to-text table.
	 *
	 * @param array<int|string,mixed> $table Table as received.
	 * @return array<string,string>
	 */
	private function table( array $table ): array {
		$clean = array();
		foreach ( array_slice( $table, 0, self::MAX_MAP_ENTRIES, true ) as $from => $to ) {
			if ( is_scalar( $to ) ) {
				$clean[ $this->text( (string) $from, 191, false ) ] = $this->text( $to, 500, false );
			}
		}
		return $clean;
	}

	/**
	 * Text without control characters and cut to a length. Names and paths are trimmed; values that may carry meaningful spaces
	 * (a separator, a prefix, a constant) are not.
	 *
	 * @param mixed $value  Value.
	 * @param int   $length Most characters.
	 * @param bool  $trim   Whether to trim spaces around the text.
	 */
	private function text( mixed $value, int $length, bool $trim = true ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		// A yes/no value is written the way the canonical model writes it ("true"/"false"), never as "1" and "", so a condition that
		// compares with a flag such as order.payment.is_cod matches.
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
		$text = $trim ? trim( $text ) : $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
	}
}
