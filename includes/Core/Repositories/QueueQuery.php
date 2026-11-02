<?php
/**
 * Filters for the queue list (`QueueQuery` in src/api/types.ts).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ItemMode;
use Estasoft\SyncEngine\Domain\ItemState;

/**
 * An immutable set of filters. Values that are not recognised are dropped, so request input can be passed in as it is.
 */
final class QueueQuery {

	/**
	 * Page sizes the screen offers.
	 */
	public const PER_PAGE = array( 25, 50, 100 );

	/**
	 * Day filters.
	 */
	public const DAYS = array( 'today', 'yesterday', '7d', '30d' );

	/**
	 * Describe a queue search.
	 *
	 * @param string|null $state        A state, `waiting` (queued, syncing, retrying), `all`, or null for all.
	 * @param string|null $entityType   An entity type value.
	 * @param string|null $day          today, yesterday, 7d or 30d (store time).
	 * @param string|null $search       Text matched against order number, SKU, email-in-ref, customer and external number.
	 * @param string|null $errorCode    A SYNC-... code.
	 * @param int|null    $connectionId Connection.
	 * @param string|null $mode         live or shadow, or null for both.
	 * @param int         $page         Page from 1.
	 * @param int         $perPage      25, 50 or 100.
	 */
	public function __construct(
		public readonly ?string $state = null,
		public readonly ?string $entityType = null,
		public readonly ?string $day = null,
		public readonly ?string $search = null,
		public readonly ?string $errorCode = null,
		public readonly ?int $connectionId = null,
		public readonly ?string $mode = null,
		public readonly int $page = 1,
		public readonly int $perPage = 25
	) {
	}

	/**
	 * Build from request parameters, dropping anything not recognised.
	 *
	 * @param array<string,mixed> $args Parameters named as in `QueueQuery` of types.ts.
	 */
	public static function fromArray( array $args ): self {
		$state = isset( $args['state'] ) ? (string) $args['state'] : null;
		if ( null !== $state && ! in_array( $state, array( 'waiting', 'all' ), true ) && null === ItemState::tryFrom( $state ) ) {
			$state = null;
		}
		$entity = isset( $args['entity_type'] ) ? (string) $args['entity_type'] : null;
		if ( null !== $entity && null === EntityType::tryFrom( $entity ) ) {
			$entity = null;
		}
		$day = isset( $args['day'] ) ? (string) $args['day'] : null;
		if ( null !== $day && ! in_array( $day, self::DAYS, true ) ) {
			$day = null;
		}
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : null;
		if ( null !== $mode && null === ItemMode::tryFrom( $mode ) ) {
			$mode = null;
		}
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 25;
		return new self(
			$state,
			$entity,
			$day,
			'' === $search ? null : mb_substr( $search, 0, 100 ),
			isset( $args['error_code'] ) && '' !== (string) $args['error_code'] ? (string) $args['error_code'] : null,
			isset( $args['connection_id'] ) && (int) $args['connection_id'] > 0 ? (int) $args['connection_id'] : null,
			$mode,
			max( 1, (int) ( $args['page'] ?? 1 ) ),
			in_array( $per_page, self::PER_PAGE, true ) ? $per_page : 25
		);
	}
}
