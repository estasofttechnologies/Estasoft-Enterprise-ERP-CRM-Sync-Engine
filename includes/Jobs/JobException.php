<?php
/**
 * An error a Jobs service reports to its caller.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Thrown for refusals that are not field errors: something does not exist, the request is not allowed in the current
 * state, or the connected system said no. Field errors use `Core\Support\ValidationException`. The REST layer turns this into
 * `{ code, message, data: { status, class } }`: `httpStatus()` is the status, `errorCode()` the catalog code when there is one
 * (otherwise use a generic code of the caller's choice), and `getMessage()` is already safe to show (no secret, no raw payload).
 */
class JobException extends \RuntimeException {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string              $message    Plain sentence for the person (translated by the thrower).
	 * @param int                 $httpStatus HTTP status the REST layer should answer with.
	 * @param ErrorCode|null      $errorCode  Catalog code, when there is one.
	 * @param array<string,mixed> $context    Extra facts for the response (never a secret). The key `test` holds a ConnectionTestResult
	 *                                         array when a connection test failed; `key_line` holds the line to add to wp-config.php.
	 */
	public function __construct(
		string $message,
		private readonly int $httpStatus = 400,
		private readonly ?ErrorCode $errorCode = null,
		private readonly array $context = array()
	) {
		parent::__construct( $message );
	}

	/**
	 * The HTTP status.
	 */
	public function httpStatus(): int {
		return $this->httpStatus;
	}

	/**
	 * The catalog code, or null.
	 */
	public function errorCode(): ?ErrorCode {
		return $this->errorCode;
	}

	/**
	 * Extra facts for the response.
	 *
	 * @return array<string,mixed>
	 */
	public function context(): array {
		return $this->context;
	}
}
