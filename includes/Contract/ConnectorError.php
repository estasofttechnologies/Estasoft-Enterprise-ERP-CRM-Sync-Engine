<?php
/**
 * A failure reported by a connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Contract;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Carries the catalog code, optional context for the message template, and what the system said about retrying.
 */
final class ConnectorError {

	/**
	 * @param ErrorCode           $code        Catalog code.
	 * @param array<string,mixed> $context     Values for {placeholders}: system, sku, field, reason, status, n.
	 * @param int|null            $httpStatus  HTTP status when there was one.
	 * @param int|null            $retryAfter  Seconds the system asked us to wait.
	 * @param ErrorClass|null     $classOverride Connector's own classification (QUE-7); null uses the catalog.
	 * @param string              $detail      Short redacted detail for the log; never a raw payload.
	 */
	public function __construct(
		public readonly ErrorCode $code,
		public readonly array $context = array(),
		public readonly ?int $httpStatus = null,
		public readonly ?int $retryAfter = null,
		public readonly ?ErrorClass $classOverride = null,
		public readonly string $detail = '',
	) {
	}

	/**
	 * The class that decides retry or fail.
	 */
	public function errorClass(): ErrorClass {
		return $this->classOverride ?? $this->code->errorClass();
	}

	/**
	 * The message with placeholders filled in (untranslated template).
	 */
	public function message(): string {
		$text = $this->code->template();
		foreach ( $this->context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$text = str_replace( '{' . $key . '}', (string) $value, $text );
			}
		}
		return $text;
	}
}
