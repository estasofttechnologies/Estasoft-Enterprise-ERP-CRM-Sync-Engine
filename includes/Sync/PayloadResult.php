<?php
/**
 * Result of building a payload.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Sync;

use Estasoft\SyncEngine\Contract\ConnectorError;

defined( 'ABSPATH' ) || exit;

/**
 * Either a payload ready for the connector, or an error found before sending (SYNC-MAP-002,
 * SYNC-MAP-003, SYNC-VAL-001, SYNC-CFG-001) which never reaches the system.
 */
final class PayloadResult {

	/**
	 * Describe a built payload.
	 *
	 * @param array<string,mixed> $payload        Mapped payload (empty when there is an error).
	 * @param ConnectorError|null $error          Pre-send failure.
	 * @param int|null            $mappingVersion Live mapping version used.
	 * @param array<int,string>   $notes          Rule notes for previews (for example "tax code mapped").
	 * @param array<int,string>   $sensitivePaths Field paths of the payload that hold personal data (for example
	 *                                            `partner.vat`), so the log can remove them from its excerpts.
	 */
	public function __construct(
		public readonly array $payload = array(),
		public readonly ?ConnectorError $error = null,
		public readonly ?int $mappingVersion = null,
		public readonly array $notes = array(),
		public readonly array $sensitivePaths = array(),
	) {
	}

	/**
	 * Whether the payload can be sent.
	 */
	public function isReady(): bool {
		return null === $this->error;
	}
}
