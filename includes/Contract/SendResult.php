<?php
/**
 * What a connector returns from send().
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Either a success (new or already existing record) or a ConnectorError.
 */
final class SendResult {

	/**
	 * @param ExternalRef|null    $ref            The record when the send succeeded.
	 * @param bool                $alreadyExisted True when the record was found instead of created (SYNC-DUP-001).
	 * @param ConnectorError|null $error          The failure, when there was one.
	 */
	private function __construct(
		public readonly ?ExternalRef $ref,
		public readonly bool $alreadyExisted,
		public readonly ?ConnectorError $error,
	) {
	}

	/**
	 * A record was created.
	 *
	 * @param ExternalRef $ref The new record.
	 */
	public static function created( ExternalRef $ref ): self {
		return new self( $ref, false, null );
	}

	/**
	 * The record already existed and was linked (idempotent retry).
	 *
	 * @param ExternalRef $ref The existing record.
	 */
	public static function existing( ExternalRef $ref ): self {
		return new self( $ref, true, null );
	}

	/**
	 * The send failed.
	 *
	 * @param ConnectorError $error Why.
	 */
	public static function failure( ConnectorError $error ): self {
		return new self( null, false, $error );
	}

	/**
	 * Whether the system now has the record.
	 */
	public function isSuccess(): bool {
		return null === $this->error && null !== $this->ref;
	}
}
