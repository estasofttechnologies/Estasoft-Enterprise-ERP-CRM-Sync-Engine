<?php
/**
 * A request to stop the worker cleanly.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * A supervisor stops a worker with SIGTERM (and a person with Ctrl-C, SIGINT). The worker must not be cut off in the middle of
 * sending an order, so the signal only sets a flag; the worker looks at it between runs and exits with code 0. Where the PHP
 * pcntl extension is missing the flag is never set by a signal, and the worker still stops on its item and memory limits.
 */
final class StopSignal {

	/**
	 * Whether a stop was requested.
	 *
	 * @var bool
	 */
	private bool $requested = false;

	/**
	 * Start listening for SIGTERM and SIGINT.
	 *
	 * @return bool Whether signals are available.
	 */
	public function listen(): bool {
		if ( ! function_exists( 'pcntl_signal' ) || ! function_exists( 'pcntl_async_signals' ) || ! defined( 'SIGTERM' ) ) {
			return false;
		}
		pcntl_async_signals( true );
		$handler = function (): void {
			$this->request();
		};
		pcntl_signal( SIGTERM, $handler );
		pcntl_signal( SIGINT, $handler );
		return true;
	}

	/**
	 * Ask the worker to stop after the run it is in.
	 */
	public function request(): void {
		$this->requested = true;
	}

	/**
	 * Whether a stop was requested.
	 */
	public function requested(): bool {
		return $this->requested;
	}
}
