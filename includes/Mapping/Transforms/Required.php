<?php
/**
 * Transform: stop the order when a value is empty.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\MappingException;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: error_code (optional SYNC-... code; the rule's own error_code is used when this is absent).
 * Use it in the middle of a pipeline, for example after a default; the rule flag "required" checks the final value.
 */
final class Required extends BaseTransform {

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'required';
	}

	/**
	 * Pass a non-empty value through, fail on an empty one.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 *
	 * @throws MappingException When the value is empty.
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		if ( ! Text::isEmpty( $value ) ) {
			return $value;
		}
		$code    = ErrorCode::tryFrom( $this->text( $args, 'error_code' ) ) ?? $context->errorCode() ?? ErrorCode::Val002;
		$failure = MappingException::requiredMissing( $code, $context->ruleId(), $context->target(), $context->messageContext() );
		throw $failure;
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$code = $this->text( $args, 'error_code' );
		return '' === $code || null !== ErrorCode::tryFrom( $code ) ? array() : array( $this->missing( 'error_code' ) );
	}
}
