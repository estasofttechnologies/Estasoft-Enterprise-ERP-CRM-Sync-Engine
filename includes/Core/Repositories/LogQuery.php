<?php
/**
 * Filters for the log list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable set of filters for `GET /logs`.
 */
final class LogQuery {

	/**
	 * Levels in order of severity.
	 */
	public const LEVELS = array( 'info', 'warning', 'error' );

	/**
	 * @param string|null $level        info, warning, error, or null for all.
	 * @param string|null $search       Text matched against message, code and item reference.
	 * @param int|null    $connectionId Connection.
	 * @param int|null    $queueId      Queue item.
	 * @param string|null $code         A SYNC-... code.
	 * @param int         $page         Page from 1.
	 * @param int         $perPage      Rows per page, 10 to 200.
	 */
	public function __construct(
		public readonly ?string $level = null,
		public readonly ?string $search = null,
		public readonly ?int $connectionId = null,
		public readonly ?int $queueId = null,
		public readonly ?string $code = null,
		public readonly int $page = 1,
		public readonly int $perPage = 50
	) {
	}

	/**
	 * Build from request parameters, dropping anything not recognised.
	 *
	 * @param array<string,mixed> $args Parameters named as in `GET /logs`.
	 */
	public static function fromArray( array $args ): self {
		$level  = isset( $args['level'] ) ? (string) $args['level'] : null;
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		return new self(
			null !== $level && in_array( $level, self::LEVELS, true ) ? $level : null,
			'' === $search ? null : mb_substr( $search, 0, 100 ),
			isset( $args['connection_id'] ) && (int) $args['connection_id'] > 0 ? (int) $args['connection_id'] : null,
			isset( $args['queue_id'] ) && (int) $args['queue_id'] > 0 ? (int) $args['queue_id'] : null,
			isset( $args['code'] ) && '' !== (string) $args['code'] ? (string) $args['code'] : null,
			max( 1, (int) ( $args['page'] ?? 1 ) ),
			max( 10, min( 200, (int) ( $args['per_page'] ?? 50 ) ) )
		);
	}
}
