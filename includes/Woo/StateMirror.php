<?php
/**
 * Copies a queue item's state onto the WooCommerce order, so the order screens can show and filter it.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\Ports\EventLog;
use Estasoft\SyncEngine\Woo\Ports\QueueGateway;

defined( 'ABSPATH' ) || exit;

/**
 * The queue is the source of truth; the order meta is a mirror. Mirroring reads the item again by id, so it never depends on what a
 * hook happened to pass along, and doing it twice does no harm. Only items about the order itself are mirrored: refunds and customers
 * have their own lines in the queue.
 */
final class StateMirror {

	/**
	 * Hooks that mean "an item changed". The queue fires the last three when a send finishes (the row is already written). The first
	 * is fired by the queue when a person retries, skips or restores an item. The "syncing" state is deliberately not mirrored: an item
	 * that is put back in the queue after being claimed fires no hook, so a mirrored "syncing" could be left behind.
	 */
	public const HOOKS = array(
		'estasoft_sync_item_state_changed',
		'estasoft_sync_item_synced',
		'estasoft_sync_item_failed',
		'estasoft_sync_shadow_processed',
	);

	/**
	 * Set up with what it depends on.
	 *
	 * @param OrderMeta    $orders Order meta.
	 * @param QueueGateway $queue  Queue.
	 * @param EventLog     $log    Event log.
	 */
	public function __construct(
		private readonly OrderMeta $orders,
		private readonly QueueGateway $queue,
		private readonly EventLog $log
	) {
	}

	/**
	 * Listen to the queue's hooks.
	 */
	public function register(): void {
		foreach ( self::HOOKS as $hook ) {
			add_action( $hook, array( $this, 'onItemChanged' ), 20, 1 );
		}
	}

	/**
	 * Hook callback: the first argument is the item (an object with an id property or an id).
	 *
	 * @param mixed $item Item, or its id.
	 */
	public function onItemChanged( mixed $item = null ): void {
		$id = is_object( $item ) && isset( $item->id ) ? (int) $item->id : ( is_numeric( $item ) ? (int) $item : 0 );
		if ( $id > 0 ) {
			$this->mirrorById( $id );
		}
	}

	/**
	 * Mirror one queue item. Never throws.
	 *
	 * @param int $itemId Queue id.
	 */
	public function mirrorById( int $itemId ): void {
		try {
			$row = $this->queue->item( $itemId );
			if ( null !== $row ) {
				$this->mirror( $row );
			}
		} catch ( \Throwable $e ) {
			$this->log->warning( __( 'The order screen could not be updated with the sync state.', 'estasoft-enterprise-erpcrm-sync-engine' ), array( 'queue_id' => $itemId ) );
		}
	}

	/**
	 * Write the state of an order item onto its order.
	 *
	 * @param array<string,mixed> $row Queue item (QueueItem fields plus mapping_version).
	 */
	public function mirror( array $row ): void {
		if ( EntityType::Order->value !== (string) ( $row['entity_type'] ?? '' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) ( $row['entity_id'] ?? 0 ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$state  = (string) ( $row['state'] ?? '' );
		$shadow = 'shadow' === (string) ( $row['mode'] ?? 'live' );
		$values = array( OrderMeta::STATE => $shadow && 'synced' === $state ? 'shadow' : $state );

		if ( 'synced' === $state && ! $shadow ) {
			$values[ OrderMeta::EXTERNAL_ID ]     = (string) ( $row['external_id'] ?? '' );
			$values[ OrderMeta::EXTERNAL_REF ]    = (string) ( $row['external_ref'] ?? '' );
			$values[ OrderMeta::EXTERNAL_URL ]    = (string) ( $row['external_url'] ?? '' );
			$values[ OrderMeta::LAST_SYNC ]       = $this->sql( (string) ( $row['finished_at'] ?? $row['updated_at'] ?? '' ) );
			$values[ OrderMeta::MAPPING_VERSION ] = isset( $row['mapping_version'] ) ? (string) $row['mapping_version'] : '';
		}
		if ( 'skipped' === $state ) {
			$previous                  = $this->orders->read( $order )->skip;
			$values[ OrderMeta::SKIP ] = array(
				'reason' => (string) ( $row['skip_reason'] ?? '' ),
				'by'     => $previous['by'] ?? '',
				'at'     => $previous['at'] ?? $this->sql( (string) ( $row['updated_at'] ?? '' ) ),
			);
		} elseif ( 'queued' === $state || 'synced' === $state ) {
			$values[ OrderMeta::SKIP ] = '';
		}

		$this->orders->write( $order, $values );
	}

	/**
	 * Remember who skipped an order, for the order box ("Skipped by Layla Haddad").
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Reason.
	 * @param string    $by     Display name of the person.
	 * @param string    $at     UTC "Y-m-d H:i:s".
	 */
	public function noteSkip( \WC_Order $order, string $reason, string $by, string $at ): void {
		$this->orders->write(
			$order,
			array(
				OrderMeta::SKIP  => array(
					'reason' => $reason,
					'by'     => $by,
					'at'     => $at,
				),
				OrderMeta::STATE => 'skipped',
			)
		);
	}

	/**
	 * An ISO 8601 UTC time as a MySQL UTC time, or an empty string.
	 *
	 * @param string $iso ISO time.
	 */
	private function sql( string $iso ): string {
		$time = '' === $iso ? false : strtotime( $iso );
		return false === $time ? '' : gmdate( 'Y-m-d H:i:s', $time );
	}
}
