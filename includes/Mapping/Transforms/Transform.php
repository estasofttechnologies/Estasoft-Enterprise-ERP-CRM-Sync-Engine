<?php
/**
 * One step of a mapping rule.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

defined( 'ABSPATH' ) || exit;

/**
 * A transform takes the value a rule has so far and returns the next value. It is deterministic: the same value, arguments and
 * order always give the same result. It never sends anything and never changes the order.
 */
interface Transform {

	/**
	 * The name rules use in "type", for example phone_e164.
	 */
	public function name(): string;

	/**
	 * Whether apply() wants a whole list (join) instead of being called once for each entry of a list.
	 */
	public function handlesLists(): bool;

	/**
	 * Transform a value.
	 *
	 * @param mixed               $value   Scalar, null, or a list when handlesLists() is true.
	 * @param array<string,mixed> $args    Arguments from the rule.
	 * @param TransformContext    $context The order, the rule being run and the lookups.
	 * @return mixed New value; null or an empty string means "nothing to send".
	 *
	 * @throws TransformArgumentException When the arguments are unusable.
	 * @throws \Estasoft\SyncEngine\Mapping\MappingException When the transform's own rule fails (required, tax_lookup, sku_match).
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed;

	/**
	 * Check arguments without running anything.
	 *
	 * @param array<string,mixed> $args Arguments from the rule.
	 * @return array<int,string> Plain-language problems; empty when the arguments are fine.
	 */
	public function check( array $args ): array;
}
