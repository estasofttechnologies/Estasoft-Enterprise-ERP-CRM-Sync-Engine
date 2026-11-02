<?php
/**
 * The decision about one failed attempt.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorClass;

/**
 * Result of ErrorClassifier::classify().
 */
final class Classification {

	/**
	 * Final class after the connector override and the filter.
	 *
	 * @var ErrorClass
	 */
	public readonly ErrorClass $class;

	/**
	 * Describe how a connector error is handled.
	 *
	 * @param ConnectorError $error           The error, with the system name filled into its context.
	 * @param ErrorClass     $errorClass      Final class after the connector override and the filter.
	 * @param FailureAction  $action          What the queue does.
	 * @param bool           $countsForBreaker Whether it counts toward the circuit breaker (retryable, not rate limiting).
	 * @param int|null       $retryAfter      Seconds the system asked us to wait.
	 */
	public function __construct(
		public readonly ConnectorError $error,
		ErrorClass $errorClass,
		public readonly FailureAction $action,
		public readonly bool $countsForBreaker,
		public readonly ?int $retryAfter
	) {
		$this->class = $errorClass;
	}
}
