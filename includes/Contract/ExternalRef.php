<?php
/**
 * A record in the external system.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies a record in the connected system.
 */
final class ExternalRef {

	/**
	 * Describe a record in the other system.
	 *
	 * @param string              $id  The system's internal id.
	 * @param string              $ref Human number such as S00490.
	 * @param string|null         $url Link to the record, when the system has one.
	 * @param array<string,mixed> $extra Optional facts, for example total_minor for reconciliation.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $ref = '',
		public readonly ?string $url = null,
		public readonly array $extra = array(),
	) {
	}
}
