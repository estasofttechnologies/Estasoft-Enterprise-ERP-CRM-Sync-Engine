<?php
/**
 * Checks a rule set before it is saved or published.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\Support\MetaKeyPolicy;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Finds every problem it can: malformed rules, unknown transforms, bad arguments, sources the canonical model does not have, targets
 * the connector does not offer, and required connector fields no rule fills.
 */
final class RuleValidator {

	/**
	 * Set up with what it depends on.
	 *
	 * @param TransformRegistry $transforms Transform library.
	 * @param CanonicalFields   $sources    Canonical source fields.
	 */
	public function __construct(
		private readonly TransformRegistry $transforms,
		private readonly CanonicalFields $sources = new CanonicalFields(),
	) {
	}

	/**
	 * Validate rules against the fields a connector offers.
	 *
	 * @param array<int,mixed>                                                                      $rules  Rules as stored.
	 * @param array<int,array{path:string,label?:string,type?:string,required?:bool,group?:string}> $fields Target fields from the connector (describeFields). An empty list skips the target checks.
	 * @param bool                                                                                  $checkSources False for rules that read from the connected system (stock, tracking), whose sources are not canonical fields.
	 * @return array<int,RuleError>
	 */
	public function validate( array $rules, array $fields = array(), bool $checkSources = true ): array {
		if ( array() !== $rules && ! \Estasoft\SyncEngine\Core\Support\Lists::isList( $rules ) ) {
			return array( new RuleError( '', 'rules', 'rules_not_a_list', __( 'The rules must be a list.', 'estasoft-erp-crm-sync-engine' ) ) );
		}

		$errors  = array();
		$ids     = array();
		$targets = array();
		$known   = $this->targetSet( $fields );

		foreach ( $rules as $position => $raw ) {
			if ( ! is_array( $raw ) ) {
				/* translators: %d: position of the rule in the list, starting at 1. */
				$errors[] = new RuleError( '', 'rules', 'rule_not_an_object', sprintf( __( 'Rule %d is not valid.', 'estasoft-erp-crm-sync-engine' ), $position + 1 ) );
				continue;
			}
			$rule      = Rule::fromArray( $raw );
			$errors    = array_merge( $errors, $this->checkId( $rule, $ids ) );
			$errors    = array_merge( $errors, $this->checkTarget( $rule, $known ) );
			$errors    = array_merge( $errors, $this->checkSource( $rule, $raw, $checkSources ) );
			$errors    = array_merge( $errors, $this->checkTransforms( $rule, $raw ) );
			$errors    = array_merge( $errors, $this->checkOptions( $rule, $checkSources ) );
			$targets[] = self::normalise( $rule->target );
		}

		return array_merge( $errors, $this->uncoveredRequired( $fields, $targets ) );
	}

	/**
	 * Path without list markers, for comparing "order_line[].sku" with "order_line.sku".
	 *
	 * @param string $path Path.
	 */
	public static function normalise( string $path ): string {
		return (string) preg_replace( '/\[\d*\]/', '', $path );
	}

	/**
	 * Every connector field, plus the parents of nested ones, as a lookup set.
	 *
	 * @param array<int,array<string,mixed>> $fields Fields.
	 * @return array<string,true>
	 */
	private function targetSet( array $fields ): array {
		$set = array();
		foreach ( $fields as $field ) {
			$path = self::normalise( (string) ( $field['path'] ?? '' ) );
			while ( '' !== $path ) {
				$set[ $path ] = true;
				$cut          = strrpos( $path, '.' );
				$path         = false === $cut ? '' : substr( $path, 0, $cut );
			}
		}
		return $set;
	}

	/**
	 * Id present, well formed, unique.
	 *
	 * @param Rule               $rule Rule.
	 * @param array<string,true> $ids  Ids seen so far (updated).
	 * @return array<int,RuleError>
	 */
	private function checkId( Rule $rule, array &$ids ): array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $rule->id ) ) {
			return array( new RuleError( $rule->id, 'id', 'id_invalid', __( 'Every rule needs an id of letters, numbers, dashes or underscores.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		if ( isset( $ids[ $rule->id ] ) ) {
			return array( new RuleError( $rule->id, 'id', 'id_duplicate', __( 'Two rules have the same id.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$ids[ $rule->id ] = true;
		return array();
	}

	/**
	 * Target well formed and offered by the connector.
	 *
	 * @param Rule               $rule  Rule.
	 * @param array<string,true> $known Connector paths (empty to skip the existence check).
	 * @return array<int,RuleError>
	 */
	private function checkTarget( Rule $rule, array $known ): array {
		$ok = 1 === preg_match( '/^[A-Za-z0-9_\-]+(\[\])?(\.[A-Za-z0-9_\-]+(\[\])?)*$/', $rule->target ) && substr_count( $rule->target, '[]' ) <= 1;
		if ( ! $ok ) {
			return array( new RuleError( $rule->id, 'target', 'target_invalid', __( 'The target is not a valid field path.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		if ( array() !== $known && ! isset( $known[ self::normalise( $rule->target ) ] ) ) {
			return array( new RuleError( $rule->id, 'target', 'target_unknown', __( 'The connected system has no such field.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		return array();
	}

	/**
	 * Source is a known path, a list of known paths, or a constant.
	 *
	 * @param Rule                $rule         Rule.
	 * @param array<string,mixed> $raw          Rule as stored.
	 * @param bool                $checkSources Whether source paths must be canonical fields.
	 * @return array<int,RuleError>
	 */
	private function checkSource( Rule $rule, array $raw, bool $checkSources ): array {
		$source = $raw['source'] ?? null;
		if ( $rule->isConstant ) {
			return is_scalar( $rule->constant ) || null === $rule->constant
				? array()
				: array( new RuleError( $rule->id, 'source', 'source_invalid', __( 'A constant must be a single value.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		if ( array() === $rule->sources || ( is_array( $source ) && count( $source ) !== count( $rule->sources ) ) ) {
			return array( new RuleError( $rule->id, 'source', 'source_invalid', __( 'A rule needs a source field, a list of source fields or a constant.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$errors = array();
		foreach ( $rule->sources as $path ) {
			if ( $checkSources && self::isBlockedMeta( $path ) ) {
				$errors[] = new RuleError( $rule->id, 'source', 'source_blocked', __( 'This field holds payment, card, password or visitor data and cannot be sent to another system.', 'estasoft-erp-crm-sync-engine' ) );
			} elseif ( ! CanonicalOrder::isValidPath( $path ) || ( $checkSources && ! $this->sources->has( $path ) ) ) {
				$errors[] = new RuleError( $rule->id, 'source', 'source_unknown', __( 'This WooCommerce field does not exist.', 'estasoft-erp-crm-sync-engine' ) );
			}
		}
		return $errors;
	}

	/**
	 * Transforms exist and their arguments are usable.
	 *
	 * @param Rule                $rule Rule.
	 * @param array<string,mixed> $raw  Rule as stored.
	 * @return array<int,RuleError>
	 */
	private function checkTransforms( Rule $rule, array $raw ): array {
		$stored = $raw['transforms'] ?? array();
		if ( ! is_array( $stored ) || count( $stored ) !== count( $rule->transforms ) ) {
			return array( new RuleError( $rule->id, 'transforms', 'transforms_invalid', __( 'Transforms must be a list of steps, each with a type.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$errors = array();
		foreach ( $rule->transforms as $position => $step ) {
			$field = sprintf( 'transforms[%d]', $position );
			if ( ! $this->transforms->has( $step['type'] ) ) {
				$errors[] = new RuleError( $rule->id, $field, 'transform_unknown', __( 'This transform does not exist.', 'estasoft-erp-crm-sync-engine' ) );
				continue;
			}
			foreach ( $this->transforms->get( $step['type'] )->check( $step['args'] ) as $problem ) {
				$errors[] = new RuleError( $rule->id, $field, 'transform_args', $problem );
			}
		}
		return $errors;
	}

	/**
	 * The error code and the condition.
	 *
	 * @param Rule $rule         Rule.
	 * @param bool $checkSources Whether the condition's path must be a canonical field.
	 * @return array<int,RuleError>
	 */
	private function checkOptions( Rule $rule, bool $checkSources ): array {
		$errors = array();
		if ( '' !== $rule->errorCode && null === ErrorCode::tryFrom( $rule->errorCode ) ) {
			$errors[] = new RuleError( $rule->id, 'error_code', 'error_code_unknown', __( 'This error code is not in the catalog.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( null !== $rule->when && $checkSources && self::isBlockedMeta( $rule->when['source'] ) ) {
			$errors[] = new RuleError( $rule->id, 'when', 'when_blocked', __( 'The condition reads a field that holds payment, card, password or visitor data.', 'estasoft-erp-crm-sync-engine' ) );
		} elseif ( null !== $rule->when && ( ! CanonicalOrder::isValidPath( $rule->when['source'] ) || ( $checkSources && ! $this->sources->has( $rule->when['source'] ) ) ) ) {
			$errors[] = new RuleError( $rule->id, 'when', 'when_unknown', __( 'The condition reads a WooCommerce field that does not exist.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $errors;
	}

	/**
	 * Whether a path reads a store field that looks like payment, card, password or visitor data.
	 *
	 * @param string $path Source path.
	 */
	private static function isBlockedMeta( string $path ): bool {
		return 0 === strpos( $path, 'meta.' ) && MetaKeyPolicy::isBlocked( substr( $path, 5 ) );
	}

	/**
	 * Required connector fields that no rule fills.
	 *
	 * @param array<int,array<string,mixed>> $fields  Connector fields.
	 * @param array<int,string>              $targets Normalised rule targets.
	 * @return array<int,RuleError>
	 */
	private function uncoveredRequired( array $fields, array $targets ): array {
		$errors = array();
		foreach ( $fields as $field ) {
			$path = self::normalise( (string) ( $field['path'] ?? '' ) );
			if ( empty( $field['required'] ) || '' === $path || in_array( $path, $targets, true ) ) {
				continue;
			}
			$errors[] = new RuleError(
				'',
				'target',
				'required_target_missing',
				/* translators: %s: field path in the connected system. */
				sprintf( __( 'The field "%s" is required by the connected system but no rule fills it.', 'estasoft-erp-crm-sync-engine' ), $path )
			);
		}
		return $errors;
	}
}
