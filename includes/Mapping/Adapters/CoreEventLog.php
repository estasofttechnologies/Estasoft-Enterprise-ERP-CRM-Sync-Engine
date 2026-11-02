<?php
/**
 * Event log backed by the core logger.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Adapters;

use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Mapping\Ports\EventLog;

defined( 'ABSPATH' ) || exit;

/**
 * Passes messages to Core\Logging\Logger, which redacts them and never throws.
 */
final class CoreEventLog implements EventLog {

	/**
	 * Set up with what it depends on.
	 *
	 * @param Logger $logger Core logger.
	 */
	public function __construct( private readonly Logger $logger ) {
	}

	/**
	 * Record an information entry.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Facts.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->logger->info( $message, $context );
	}

	/**
	 * Record a warning.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Facts.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->logger->warning( $message, $context );
	}

	/**
	 * Record an error.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Facts.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->logger->error( $message, $context );
	}
}
