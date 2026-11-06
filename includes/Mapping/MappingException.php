<?php
/**
 * A mapping failure found before anything is sent.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the catalog code the rule asked for (SYNC-MAP-003, SYNC-VAL-001 ...) and the values for its message.
 * The exception text names the code and the rule only; it never repeats customer data.
 */
final class MappingException extends \RuntimeException {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ErrorCode           $errorCode Catalog code.
	 * @param array<string,mixed> $context   Values for the {placeholders} of the code's message.
	 * @param string              $ruleId    Rule that failed, or an empty string.
	 * @param string              $target    Target path of that rule.
	 */
	public function __construct(
		public readonly ErrorCode $errorCode,
		public readonly array $context = array(),
		public readonly string $ruleId = '',
		public readonly string $target = '',
	) {
		parent::__construct(
			'' === $ruleId ? $errorCode->value : sprintf( '%1$s (rule %2$s)', $errorCode->value, (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $ruleId ) )
		);
	}

	/**
	 * The same failure as a connector error, which is what the queue stores and shows.
	 */
	public function toConnectorError(): ConnectorError {
		return new ConnectorError( $this->errorCode, $this->context );
	}

	/**
	 * A required value is empty.
	 *
	 * @param ErrorCode           $code    Code configured on the rule.
	 * @param string              $ruleId  Rule id.
	 * @param string              $target  Target path.
	 * @param array<string,mixed> $context Values for the message.
	 */
	public static function requiredMissing( ErrorCode $code, string $ruleId, string $target, array $context = array() ): self {
		$context += array(
			'field'  => $target,
			'reason' => __( 'A required value is missing.', 'estasoft-enterprise-erpcrm-sync-engine' ),
		);
		return new self( $code, $context, $ruleId, $target );
	}

	/**
	 * A transform or the rule itself is set up wrongly.
	 *
	 * @param string              $ruleId  Rule id.
	 * @param string              $target  Target path.
	 * @param string              $reason  What is wrong, in plain words.
	 * @param array<string,mixed> $context Values for the message.
	 */
	public static function badRule( string $ruleId, string $target, string $reason, array $context = array() ): self {
		return new self(
			ErrorCode::Val002,
			$context + array(
				'field'  => $target,
				'reason' => $reason,
			),
			$ruleId,
			$target
		);
	}
}
