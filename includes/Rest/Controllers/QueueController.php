<?php
/**
 * Routes: the queue.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Admin\ScreenOptions;
use Estasoft\SyncEngine\Core\Queue\QueueService;
use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * List, detail, retry, skip, restore and the bulk actions. Every state change is written to the log by Core\Queue\QueueService with
 * the id of the person.
 */
final class QueueController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add(
			'/queue',
			'GET',
			self::MANAGE,
			$this->index( ... ),
			array(
				'state'         => self::enumArg( array( 'queued', 'syncing', 'synced', 'retrying', 'failed', 'skipped', 'waiting', 'all' ) ),
				'entity_type'   => self::enumArg( array( 'order', 'refund', 'customer', 'stock', 'tracking', 'invoice' ) ),
				'day'           => self::enumArg( QueueQuery::DAYS ),
				'mode'          => self::enumArg( array( 'live', 'shadow', 'all' ) ),
				'search'        => self::lineArg( 100 ),
				'error_code'    => array(
					'type'    => 'string',
					'pattern' => '^SYNC-[A-Z]+-[0-9]{3}$',
				),
				'connection_id' => self::connectionArg(),
				'page'          => self::pageArg(),
				'per_page'      => array(
					'type' => 'integer',
					'enum' => QueueQuery::PER_PAGE,
				),
			)
		);
		$this->add( '/queue/attention', 'GET', self::MANAGE, $this->attention( ... ) );
		$this->add( '/queue/(?P<id>\d+)', 'GET', self::MANAGE, $this->show( ... ), array( 'id' => self::idArg() ) );
		$this->add( '/queue/(?P<id>\d+)/retry', 'POST', self::MANAGE, $this->retry( ... ), array( 'id' => self::idArg() ) );
		$this->add(
			'/queue/(?P<id>\d+)/skip',
			'POST',
			self::MANAGE,
			$this->skip( ... ),
			array(
				'id'     => self::idArg(),
				'reason' => self::lineArg( 500 ),
			)
		);
		$this->add( '/queue/(?P<id>\d+)/restore', 'POST', self::MANAGE, $this->restore( ... ), array( 'id' => self::idArg() ) );
		$this->add(
			'/queue/retry',
			'POST',
			self::MANAGE,
			$this->retryMany( ... ),
			array(
				'ids'        => self::idListArg(),
				'error_code' => array(
					'type'    => 'string',
					'pattern' => '^SYNC-[A-Z]+-[0-9]{3}$',
				),
				'cause'      => self::lineArg( 200 ),
			)
		);
		$this->add(
			'/queue/bulk',
			'POST',
			self::MANAGE,
			$this->bulk( ... ),
			array(
				'ids'    => self::idListArg( true ),
				'action' => self::enumArg( array( 'skip', 'retry', 'restore' ), true ),
				'reason' => self::lineArg( 500 ),
			)
		);
	}

	/**
	 * `GET /queue`: one page of items.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var QueueRepository $queue */
		$queue = $this->service( Services::QUEUE_REPOSITORY );
		$args  = $this->params( $request );
		if ( ! isset( $args['mode'] ) ) {
			$args['mode'] = 'live';
		}
		if ( ! isset( $args['per_page'] ) && $this->container->has( ScreenOptions::SERVICE ) ) {
			/** @var ScreenOptions $options */
			$options          = $this->container->get( ScreenOptions::SERVICE );
			$args['per_page'] = $options->perPage( $this->actorId() );
		}
		return $this->paged( $queue->query( QueueQuery::fromArray( $args ) ) );
	}

	/**
	 * `GET /queue/attention`: failed items grouped by error code and cause.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function attention(): array {
		/** @var QueueRepository $queue */
		$queue = $this->service( Services::QUEUE_REPOSITORY );
		return $queue->attentionGroups();
	}

	/**
	 * `GET /queue/{id}`: the item with its timeline and excerpts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException 404 when there is no such item.
	 */
	public function show( \WP_REST_Request $request ): array {
		/** @var QueueService $queue */
		$queue  = $this->service( Services::QUEUE_SERVICE );
		$detail = $queue->detail( (int) $request['id'] );
		if ( null === $detail ) {
			throw ApiException::notFound( __( 'That queue item does not exist.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $detail;
	}

	/**
	 * `POST /queue/{id}/retry`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException 409 when the item is not failed or retrying.
	 */
	public function retry( \WP_REST_Request $request ): array {
		/** @var QueueService $queue */
		$queue = $this->service( Services::QUEUE_SERVICE );
		$item  = $queue->retry( (int) $request['id'] );
		if ( null === $item ) {
			throw ApiException::conflict( __( 'Only failed or retrying orders can be retried.', 'estasoft-erp-crm-sync-engine' ), 'estasoft_sync_cannot_retry' );
		}
		return $item;
	}

	/**
	 * `POST /queue/{id}/skip`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException 409 when the item cannot be skipped.
	 */
	public function skip( \WP_REST_Request $request ): array {
		/** @var QueueService $queue */
		$queue = $this->service( Services::QUEUE_SERVICE );
		$item  = $queue->skip( (int) $request['id'], Sanitize::line( $request->get_param( 'reason' ), 500 ) );
		if ( null === $item ) {
			throw ApiException::conflict( __( 'This item has already finished and cannot be skipped.', 'estasoft-erp-crm-sync-engine' ), 'estasoft_sync_cannot_skip' );
		}
		return $item;
	}

	/**
	 * `POST /queue/{id}/restore`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException 409 when the item is not skipped.
	 */
	public function restore( \WP_REST_Request $request ): array {
		/** @var QueueService $queue */
		$queue = $this->service( Services::QUEUE_SERVICE );
		$item  = $queue->restore( (int) $request['id'] );
		if ( null === $item ) {
			throw ApiException::conflict( __( 'Only skipped orders can be restored.', 'estasoft-erp-crm-sync-engine' ), 'estasoft_sync_cannot_restore' );
		}
		return $item;
	}

	/**
	 * `POST /queue/retry`: by ids, or a whole group by error code and cause.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{retried:int}
	 *
	 * @throws ApiException 422 when neither ids nor an error code are given.
	 */
	public function retryMany( \WP_REST_Request $request ): array {
		/** @var QueueService $queue */
		$queue = $this->service( Services::QUEUE_SERVICE );
		$code  = (string) $request->get_param( 'error_code' );
		if ( '' !== $code ) {
			$cause = Sanitize::line( $request->get_param( 'cause' ), 200 );
			return array( 'retried' => $queue->retryGroup( $code, '' === $cause ? null : $cause ) );
		}
		$ids = Sanitize::idList( $request->get_param( 'ids' ) );
		if ( array() === $ids ) {
			throw ApiException::invalid( __( 'Choose the orders to retry, or an error code.', 'estasoft-erp-crm-sync-engine' ), array( 'ids' => __( 'Choose at least one order.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		return array( 'retried' => $queue->retryMany( $ids ) );
	}

	/**
	 * `POST /queue/bulk`: skip, retry or restore the selected items.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{done:int}
	 *
	 * @throws ApiException 422 when the action is unknown.
	 */
	public function bulk( \WP_REST_Request $request ): array {
		/** @var QueueService $queue */
		$queue  = $this->service( Services::QUEUE_SERVICE );
		$reason = Sanitize::line( $request->get_param( 'reason' ), 500 );
		$done   = $queue->bulk( Sanitize::idList( $request->get_param( 'ids' ) ), (string) $request->get_param( 'action' ), '' === $reason ? null : $reason );
		if ( null === $done ) {
			throw ApiException::invalid( __( 'That action is not known.', 'estasoft-erp-crm-sync-engine' ), array( 'action' => __( 'Choose skip, retry or restore.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		return array( 'done' => $done );
	}
}
