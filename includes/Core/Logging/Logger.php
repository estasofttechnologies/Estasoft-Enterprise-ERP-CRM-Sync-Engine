<?php
/**
 * Writes log entries.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Logging;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Repositories\LogRepository;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The one way the plugin records an event. Every message and excerpt is redacted first, excerpts are cut to the size
 * in Settings, and nothing here ever throws: a failed log write must never break a sync or a checkout.
 */
final class Logger {

	/**
	 * Set up the logger.
	 *
	 * @param LogRepository $logs     Storage.
	 * @param Settings      $settings Settings (redaction on or off, excerpt size).
	 * @param Redactor      $redactor Redaction rules.
	 */
	public function __construct(
		private readonly LogRepository $logs,
		private readonly Settings $settings,
		private readonly Redactor $redactor
	) {
	}

	/**
	 * Record an information entry.
	 *
	 * @param string              $message Plain sentence.
	 * @param array<string,mixed> $context See log().
	 * @return int|null Entry id, or null when nothing was written.
	 */
	public function info( string $message, array $context = array() ): ?int {
		return $this->log( 'info', $message, $context );
	}

	/**
	 * Record a warning.
	 *
	 * @param string              $message Plain sentence.
	 * @param array<string,mixed> $context See log().
	 * @return int|null Entry id, or null when nothing was written.
	 */
	public function warning( string $message, array $context = array() ): ?int {
		return $this->log( 'warning', $message, $context );
	}

	/**
	 * Record an error.
	 *
	 * @param string              $message Plain sentence.
	 * @param array<string,mixed> $context See log().
	 * @return int|null Entry id, or null when nothing was written.
	 */
	public function error( string $message, array $context = array() ): ?int {
		return $this->log( 'error', $message, $context );
	}

	/**
	 * Record an entry. Never throws.
	 *
	 * Context keys, all optional: `connection_id`, `queue_id`, `entity_ref` (for example "Order #10490"),
	 * `code` (ErrorCode or string), `http_status`, `duration_ms`, `attempt`, `mapping_version`, `request` and `response`
	 * (arrays or strings, kept as redacted excerpts), `sensitive_paths` (extra field paths to treat as personal).
	 *
	 * @param string              $level   info, warning or error.
	 * @param string              $message Plain sentence.
	 * @param array<string,mixed> $context Context.
	 * @return int|null Entry id, or null when nothing was written.
	 */
	public function log( string $level, string $message, array $context = array() ): ?int {
		try {
			$personal = $this->settings->redactionEnabled();
			$bytes    = $this->settings->logExcerptBytes();
			$cap      = $this->settings->payloadCapBytes();
			$paths    = isset( $context['sensitive_paths'] ) && is_array( $context['sensitive_paths'] ) ? array_map( 'strval', $context['sensitive_paths'] ) : array();
			$code     = $context['code'] ?? null;

			$entry = array(
				'level'            => $level,
				'message'          => $this->redactor->message( $message, $personal ),
				'connection_id'    => $context['connection_id'] ?? null,
				'queue_id'         => $context['queue_id'] ?? null,
				'entity_ref'       => isset( $context['entity_ref'] ) ? $this->redactor->message( (string) $context['entity_ref'], $personal ) : null,
				'code'             => $code instanceof ErrorCode ? $code->value : ( null === $code ? null : (string) $code ),
				'http_status'      => $context['http_status'] ?? null,
				'duration_ms'      => $context['duration_ms'] ?? null,
				'attempt'          => $context['attempt'] ?? null,
				'mapping_version'  => $context['mapping_version'] ?? null,
				'request_excerpt'  => $this->redactor->excerpt( $context['request'] ?? null, $bytes, $personal, $paths, $cap ),
				'response_excerpt' => $this->redactor->excerpt( $context['response'] ?? null, $bytes, $personal, $paths, $cap ),
			);
			$id    = $this->logs->write( $entry );
			return $id > 0 ? $id : null;
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
	}
}
