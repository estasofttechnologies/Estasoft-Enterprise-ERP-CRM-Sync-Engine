<?php
/**
 * A problem found in a rule set before it is saved or published.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * One finding of RuleEngine::validate(). Shaped for the mapping screen: which rule, which part of it, what is wrong.
 */
final class RuleError {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $ruleId  Rule id, or an empty string when the problem is not tied to one rule.
	 * @param string $field   Part of the rule: id, target, source, transforms[2], when, error_code, rules.
	 * @param string $code    Stable machine code such as target_unknown or transform_unknown.
	 * @param string $message Plain-language message (translated).
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly string $field,
		public readonly string $code,
		public readonly string $message,
	) {
	}

	/**
	 * Array form for REST responses.
	 *
	 * @return array{rule_id:string,field:string,code:string,message:string}
	 */
	public function toArray(): array {
		return array(
			'rule_id' => $this->ruleId,
			'field'   => $this->field,
			'code'    => $this->code,
			'message' => $this->message,
		);
	}
}
