<?php
/**
 * Time source.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that needs the current time asks a Clock, so tests can control it.
 */
interface Clock {

	/**
	 * The current moment in UTC, with microseconds.
	 */
	public function now(): \DateTimeImmutable;
}
