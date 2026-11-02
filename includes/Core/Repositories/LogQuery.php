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
	 * Time ranges and their length in seconds; `all` has no limit.
	 *
	 * @var array<string,int|null>
	 */
	public const RANGES = array(
		'1h'  => 3600,
		'24h' => 86400,
		'7d'  => 604800,
		'30d' => 2592000,
		'all' => null,
	);

	/**
	 * @param string|null $level        info, warning, error, or null for all.
	 * @param string|null $search       Text matched against message, code and item reference.
	 * @param int|null    $connectionId Connection.
	 * @param int|null    $queueId      Queue item.
	 * @param string|null $code         A SYNC-... code.
	 * @param int         $page         Page from 1.
	 * @param int         $perPage      Rows per page, 10 to 200.
	 * @param string      $range        1h, 24h, 7d, 30d or all: only entries from the last hour, day, 7 days or 30 days.
	 */
	public function __construct(
		public readonly ?string $level = null,
		public readonly ?string $search = null,
		public readonly ?int $connectionId = null,
		public readonly ?int $queueId = null,
		public readonly ?string $code = null,
		public readonly int $page = 1,
		public readonly int $perPage = 50,
		public readonly string $range = 'all'
	) {
	}

	/**
	 * Length of the time range in seconds, or null for all time.
	 */
	public function rangeSeconds(): ?int {
		return self::RANGES[ $this->range ] ?? null;
	}

	/**
	 * Build from request parameters, dropping anything not recognised.
	 *
	 * @param array<string,mixed> $args Parameters named as in `GET /logs`.
	 */
	public static function fromArray( array $args ): self {
		$level  = isset( $args['level'] ) ? (string) $args['level'] : null;
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$range  = isset( $args['range'] ) ? strtolower( trim( (string) $args['range'] ) ) : 'all';
		$code   = isset( $args['code'] ) ? trim( (string) $args['code'] ) : '';
		return new self(
			null !== $level && in_array( $level, self::LEVELS, true ) ? $level : null,
			'' === $search ? null : mb_substr( $search, 0, 100 ),
			isset( $args['connection_id'] ) && (int) $args['connection_id'] > 0 ? (int) $args['connection_id'] : null,
			isset( $args['queue_id'] ) && (int) $args['queue_id'] > 0 ? (int) $args['queue_id'] : null,
			'' === $code ? null : mb_substr( $code, 0, 20 ),
			max( 1, (int) ( $args['page'] ?? 1 ) ),
			max( 10, min( 200, (int) ( $args['per_page'] ?? 50 ) ) ),
			array_key_exists( $range, self::RANGES ) ? $range : 'all'
		);
	}
}
