<?php
/**
 * The process would have stopped here.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Thrown by the fake CLI output where WP_CLI::error() exits; the code is the exit code.
 */
final class RestCliExit extends \RuntimeException {
}
