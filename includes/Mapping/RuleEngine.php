<?php
/**
 * Applies an ordered list of mapping rules to a canonical order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\Support\Text;
use Estasoft\SyncEngine\Mapping\Transforms\Transform;
use Estasoft\SyncEngine\Mapping\Transforms\TransformArgumentException;
use Estasoft\SyncEngine\Mapping\Transforms\TransformContext;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP: no WordPress data access, no network. For each rule in order it checks the "when" condition, reads the source,
 * runs the transforms, enforces "required" and writes the result at the target path. Rules whose target ends in a list
 * ("order_line[].sku") run once for every row of their source list and line up by row number with other rules writing the same list.
 * A later rule overwrites an earlier rule that wrote the same target.
 */
final class RuleEngine {

	/**
	 * Checks rule sets before they are saved.
	 *
	 * @var RuleValidator
	 */
	private RuleValidator $validator;

	/**
	 * Set up with what it depends on.
	 *
	 * @param TransformRegistry  $transforms Transform library.
	 * @param RuleValidator|null $validator  Validator; one is built from the library when omitted.
	 */
	public function __construct( private readonly TransformRegistry $transforms, ?RuleValidator $validator = null ) {
		$this->validator = $validator ?? new RuleValidator( $transforms );
	}

	/**
	 * Apply the rules and stop at the first failure.
	 *
	 * @param array<int,array<string,mixed>> $rules       Ordered rules as stored.
	 * @param CanonicalOrder                 $order       The order, refund or customer.
	 * @param MappingEnvironment|null        $environment Connection and lookups.
	 * @return MappingOutput Output of a run that completed without failures.
	 *
	 * @throws MappingException When a required value is missing or a lookup (tax code, product) finds nothing. Nothing has been sent at that point.
	 * @throws LookupFailedException When the connected system could not be asked.
	 */
	public function apply( array $rules, CanonicalOrder $order, ?MappingEnvironment $environment = null ): MappingOutput {
		$output = $this->run( $rules, $order, $environment ?? new MappingEnvironment(), true );
		$output->throwIfFailed();
		return $output;
	}

	/**
	 * Apply every rule and collect all failures instead of stopping (used by previews, which show them together).
	 *
	 * @param array<int,array<string,mixed>> $rules       Ordered rules as stored.
	 * @param CanonicalOrder                 $order       The order, refund or customer.
	 * @param MappingEnvironment|null        $environment Connection and lookups.
	 *
	 * @throws LookupFailedException When the connected system could not be asked.
	 */
	public function applyAll( array $rules, CanonicalOrder $order, ?MappingEnvironment $environment = null ): MappingOutput {
		return $this->run( $rules, $order, $environment ?? new MappingEnvironment(), false );
	}

	/**
	 * Check a rule set against the fields a connector offers (MAP-3).
	 *
	 * @param array<int,mixed>               $rules  Rules as stored.
	 * @param array<int,array<string,mixed>> $fields Target fields from ConnectorInterface::describeFields(); empty skips the target checks.
	 * @param bool                           $checkSources False for rules that read from the connected system (stock, tracking).
	 * @return array<int,RuleError>
	 */
	public function validate( array $rules, array $fields = array(), bool $checkSources = true ): array {
		return $this->validator->validate( $rules, $fields, $checkSources );
	}

	/**
	 * Run the rules.
	 *
	 * @param array<int,array<string,mixed>> $rules       Rules.
	 * @param CanonicalOrder                 $order       Order.
	 * @param MappingEnvironment             $environment Environment.
	 * @param bool                           $stopAtFirst Stop at the first failure.
	 */
	private function run( array $rules, CanonicalOrder $order, MappingEnvironment $environment, bool $stopAtFirst ): MappingOutput {
		$diagnostics = new Diagnostics();
		$target      = new TargetBuilder();
		$context     = new TransformContext( $order, $diagnostics, $environment );
		$changed     = array();
		$errors      = array();

		foreach ( $rules as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$rule = Rule::fromArray( $raw );
			try {
				if ( $this->evaluate( $rule, $order, $context, $target, $diagnostics ) ) {
					$changed[] = $rule->target;
				}
			} catch ( MappingException $e ) {
				$errors[] = $e;
			} catch ( TransformArgumentException $e ) {
				$errors[] = MappingException::badRule( $rule->id, $rule->target, $e->getMessage(), $context->messageContext() );
			}
			if ( $stopAtFirst && array() !== $errors ) {
				break;
			}
		}

		return new MappingOutput( $target->build(), $diagnostics->notes(), $diagnostics->warnings(), $changed, $errors );
	}

	/**
	 * Run one rule.
	 *
	 * @param Rule             $rule        Rule.
	 * @param CanonicalOrder   $order       Order.
	 * @param TransformContext $context     Context.
	 * @param TargetBuilder    $target      Where results go.
	 * @param Diagnostics      $diagnostics Notes.
	 * @return bool Whether a value was written that differs from the plain source.
	 *
	 * @throws MappingException A required value is missing or a lookup failed.
	 * @throws TransformArgumentException A transform cannot use its arguments.
	 */
	private function evaluate( Rule $rule, CanonicalOrder $order, TransformContext $context, TargetBuilder $target, Diagnostics $diagnostics ): bool {
		$context->enter( $rule->id, $rule->target, $rule->firstSource(), $rule->code(), null );

		if ( null !== $rule->when && Text::toString( $this->scalarOf( $order->get( $rule->when['source'] ) ) ) !== $rule->when['equals'] ) {
			$diagnostics->note( $rule->id, $rule->target, __( 'Skipped: the condition on this rule is not met.', 'estasoft-erp-crm-sync-engine' ) );
			return false;
		}

		$sourceValues = $this->sourceValues( $rule, $order );
		$rows         = $this->rowCount( $rule, $sourceValues, $target );

		if ( 0 === $rows ) {
			// A list rule with nothing to run over: its source list is empty (a required rule fails), or it only holds constants and no earlier rule made rows for it.
			if ( $rule->required && $rule->sourceFansOut() ) {
				$failure = MappingException::requiredMissing( $rule->code() ?? ErrorCode::Val002, $rule->id, $rule->target, $context->messageContext() );
				throw $failure;
			}
			$diagnostics->note( $rule->id, $rule->target, __( 'No rows, so the field is left out.', 'estasoft-erp-crm-sync-engine' ) );
			return false;
		}

		if ( null === $rows ) {
			$plain = $this->plain( $rule, $sourceValues );
			$value = $this->pipeline( $rule, $plain, $context );
			$this->enforce( $rule, $value, $context );
			if ( Text::isEmpty( $value ) ) {
				$diagnostics->note( $rule->id, $rule->target, __( 'No value, so the field is left out.', 'estasoft-erp-crm-sync-engine' ) );
				return false;
			}
			$target->set( $rule->target, $value );
			return ! self::same( $plain, $value );
		}

		$plainRows = array();
		$valueRows = array();
		$wrote     = false;
		for ( $row = 0; $row < $rows; $row++ ) {
			$context->enter( $rule->id, $rule->target, $rule->firstSource(), $rule->code(), $row );
			$plain = $this->plainForRow( $rule, $sourceValues, $row );
			$value = $this->pipeline( $rule, $plain, $context );
			$this->enforce( $rule, $value, $context );
			$plainRows[] = $plain;
			$valueRows[] = $value;
			if ( ! Text::isEmpty( $value ) ) {
				$target->set( $rule->target, $value, $row );
				$wrote = true;
			}
		}
		$context->enter( $rule->id, $rule->target, $rule->firstSource(), $rule->code(), null );
		if ( ! $wrote ) {
			$diagnostics->note( $rule->id, $rule->target, __( 'No value, so the field is left out.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $wrote && ! self::same( $plainRows, $valueRows );
	}

	/**
	 * Read every source of the rule.
	 *
	 * @param Rule           $rule  Rule.
	 * @param CanonicalOrder $order Order.
	 * @return array<int,mixed> One entry per source path; a single entry holding the constant for constant rules.
	 */
	private function sourceValues( Rule $rule, CanonicalOrder $order ): array {
		if ( $rule->isConstant ) {
			return array( $rule->constant );
		}
		return array_map( static fn( string $path ): mixed => $order->get( $path ), $rule->sources );
	}

	/**
	 * Number of rows when the rule is a list rule (list target), or null for a single-value rule. A list rule whose sources are all single
	 * values is applied to every row an earlier rule already created in that list, or to one new row when there is none. A rule that only
	 * holds a constant never makes a row of its own: with no rows made by earlier rules it writes nothing, so a list never starts with a
	 * row that has nothing but a fixed value in it.
	 *
	 * @param Rule             $rule         Rule.
	 * @param array<int,mixed> $sourceValues Values read.
	 * @param TargetBuilder    $target       The payload so far.
	 */
	private function rowCount( Rule $rule, array $sourceValues, TargetBuilder $target ): ?int {
		if ( ! $rule->targetIsList() ) {
			return null;
		}
		$existing = $target->rows( $rule->target );
		$rows     = $rule->sourceFansOut() ? 0 : ( $rule->isConstant ? $existing : max( 1, $existing ) );
		foreach ( $rule->sources as $position => $path ) {
			if ( CanonicalOrder::fansOut( $path ) && is_array( $sourceValues[ $position ] ) ) {
				$rows = max( $rows, count( $sourceValues[ $position ] ) );
			}
		}
		return $rows;
	}

	/**
	 * The value a rule starts from when it is not a list rule: the single source, or the list of sources.
	 *
	 * @param Rule             $rule         Rule.
	 * @param array<int,mixed> $sourceValues Values read.
	 * @return mixed
	 */
	private function plain( Rule $rule, array $sourceValues ): mixed {
		return $rule->isConstant || 1 === count( $sourceValues ) ? $sourceValues[0] : $sourceValues;
	}

	/**
	 * The starting value of one row of a list rule.
	 *
	 * @param Rule             $rule         Rule.
	 * @param array<int,mixed> $sourceValues Values read.
	 * @param int              $row          Row.
	 * @return mixed
	 */
	private function plainForRow( Rule $rule, array $sourceValues, int $row ): mixed {
		if ( $rule->isConstant ) {
			return $sourceValues[0];
		}
		$cells = array();
		foreach ( $rule->sources as $position => $path ) {
			$value   = $sourceValues[ $position ];
			$cells[] = CanonicalOrder::fansOut( $path ) ? ( is_array( $value ) ? ( $value[ $row ] ?? null ) : null ) : $value;
		}
		return 1 === count( $cells ) ? $cells[0] : $cells;
	}

	/**
	 * Run the transforms in order. A transform that handles one value at a time is applied to every entry of a list.
	 *
	 * @param Rule             $rule    Rule.
	 * @param mixed            $value   Starting value.
	 * @param TransformContext $context Context.
	 * @return mixed
	 */
	private function pipeline( Rule $rule, mixed $value, TransformContext $context ): mixed {
		foreach ( $rule->transforms as $step ) {
			$transform = $this->transforms->get( $step['type'] );
			$value     = $this->step( $transform, $value, $step['args'], $context );
		}
		return $value;
	}

	/**
	 * One transform.
	 *
	 * @param Transform           $transform Transform.
	 * @param mixed               $value     Value.
	 * @param array<string,mixed> $args      Arguments.
	 * @param TransformContext    $context   Context.
	 * @return mixed
	 */
	private function step( Transform $transform, mixed $value, array $args, TransformContext $context ): mixed {
		if ( ! $transform->handlesLists() && is_array( $value ) && \Estasoft\SyncEngine\Core\Support\Lists::isList( $value ) ) {
			return array_map( fn( mixed $entry ): mixed => $this->step( $transform, $entry, $args, $context ), $value );
		}
		return $transform->apply( $value, $args, $context );
	}

	/**
	 * Fail when the rule is required and its value is empty.
	 *
	 * @param Rule             $rule    Rule.
	 * @param mixed            $value   Final value.
	 * @param TransformContext $context Context.
	 *
	 * @throws MappingException When required and empty.
	 */
	private function enforce( Rule $rule, mixed $value, TransformContext $context ): void {
		if ( $rule->required && Text::isEmpty( $value ) ) {
			$failure = MappingException::requiredMissing( $rule->code() ?? ErrorCode::Val002, $rule->id, $rule->target, $context->messageContext() );
			throw $failure;
		}
	}

	/**
	 * A condition value as a scalar: lists and objects read as empty.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function scalarOf( mixed $value ): mixed {
		return is_array( $value ) ? '' : $value;
	}

	/**
	 * Whether two values are the same when written as text, entry by entry.
	 *
	 * @param mixed $left  Value.
	 * @param mixed $right Value.
	 */
	private static function same( mixed $left, mixed $right ): bool {
		if ( is_array( $left ) || is_array( $right ) ) {
			if ( ! is_array( $left ) || ! is_array( $right ) || count( $left ) !== count( $right ) ) {
				return false;
			}
			foreach ( array_values( $left ) as $position => $entry ) {
				if ( ! self::same( $entry, array_values( $right )[ $position ] ) ) {
					return false;
				}
			}
			return true;
		}
		return Text::toString( $left ) === Text::toString( $right );
	}
}
