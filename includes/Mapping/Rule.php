<?php
/**
 * One mapping rule, read from its stored array form.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Domain\ErrorCode;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of {id, target, source, transforms[], required, error_code, when}. Reading is forgiving (a malformed rule becomes an
 * empty one); RuleValidator is what reports problems.
 */
final class Rule {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                                                 $id         Rule id.
	 * @param string                                                 $target     Target path in the connector's fields.
	 * @param array<int,string>                                      $sources    Source paths (empty for a constant).
	 * @param bool                                                   $isConstant True when the source is a fixed value.
	 * @param mixed                                                  $constant   The fixed value.
	 * @param array<int,array{type:string,args:array<string,mixed>}> $transforms Steps in order.
	 * @param bool                                                   $required   Whether an empty result stops the order.
	 * @param string                                                 $errorCode  Code to use when the rule fails, or an empty string.
	 * @param array{source:string,equals:string}|null                $when       Condition, or null.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $target,
		public readonly array $sources,
		public readonly bool $isConstant,
		public readonly mixed $constant,
		public readonly array $transforms,
		public readonly bool $required,
		public readonly string $errorCode,
		public readonly ?array $when,
	) {
	}

	/**
	 * Read a rule from its array form.
	 *
	 * @param array<string,mixed> $raw Stored rule.
	 */
	public static function fromArray( array $raw ): self {
		$source   = $raw['source'] ?? null;
		$sources  = array();
		$constant = null;
		$isConst  = false;
		if ( is_string( $source ) ) {
			$sources = array( $source );
		} elseif ( is_array( $source ) && array_key_exists( 'const', $source ) ) {
			$isConst  = true;
			$constant = $source['const'];
		} elseif ( is_array( $source ) ) {
			foreach ( $source as $path ) {
				if ( is_string( $path ) ) {
					$sources[] = $path;
				}
			}
		}

		$steps = array();
		foreach ( is_array( $raw['transforms'] ?? null ) ? $raw['transforms'] : array() as $step ) {
			if ( is_array( $step ) && isset( $step['type'] ) && is_string( $step['type'] ) ) {
				$steps[] = array(
					'type' => $step['type'],
					'args' => is_array( $step['args'] ?? null ) ? $step['args'] : array(),
				);
			}
		}

		$when = null;
		if ( is_array( $raw['when'] ?? null ) && isset( $raw['when']['source'] ) && is_string( $raw['when']['source'] ) ) {
			$equals = $raw['when']['equals'] ?? '';
			$when   = array(
				'source' => $raw['when']['source'],
				'equals' => is_scalar( $equals ) ? ( is_bool( $equals ) ? ( $equals ? 'true' : 'false' ) : (string) $equals ) : '',
			);
		}

		return new self(
			is_scalar( $raw['id'] ?? null ) ? (string) $raw['id'] : '',
			is_string( $raw['target'] ?? null ) ? $raw['target'] : '',
			$sources,
			$isConst,
			$constant,
			$steps,
			! empty( $raw['required'] ),
			is_string( $raw['error_code'] ?? null ) ? $raw['error_code'] : '',
			$when
		);
	}

	/**
	 * The catalog code to raise when the rule fails, or null when none (or an unknown one) is configured.
	 */
	public function code(): ?ErrorCode {
		return '' === $this->errorCode ? null : ErrorCode::tryFrom( $this->errorCode );
	}

	/**
	 * Whether the target is an entry of a list ("order_line[].sku").
	 */
	public function targetIsList(): bool {
		return false !== strpos( $this->target, '[]' );
	}

	/**
	 * Whether any source reads one value per row ("lines[].sku").
	 */
	public function sourceFansOut(): bool {
		foreach ( $this->sources as $path ) {
			if ( CanonicalOrder::fansOut( $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * First source path, or an empty string for a constant.
	 */
	public function firstSource(): string {
		return $this->sources[0] ?? '';
	}
}
