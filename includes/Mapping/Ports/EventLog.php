<?php
/**
 * Where the mapping module writes events.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * The part of Core\Logging\Logger that the mapping and WooCommerce modules use. Implementations redact and never throw.
 */
interface EventLog {

	/**
	 * Record something that happened.
	 *
	 * @param string              $message Plain-language message.
	 * @param array<string,mixed> $context Facts such as connection_id or user_id. No secrets and no customer data.
	 */
	public function info( string $message, array $context = array() ): void;

	/**
	 * Record something that went wrong but did not stop the work.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Facts.
	 */
	public function warning( string $message, array $context = array() ): void;

	/**
	 * Record a failure.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Facts.
	 */
	public function error( string $message, array $context = array() ): void;
}
