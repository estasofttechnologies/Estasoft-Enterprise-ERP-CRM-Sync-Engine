<?php
/**
 * What the buttons on the order screens do.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Woo\Ports\QueueGateway;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the five actions, so the order box (one order) and the orders list (many) behave the same. Callers check the nonce and
 * the capability first.
 */
final class OrderActions {

	/**
	 * The actions, in the words used in links.
	 */
	public const ACTIONS = array( 'sync_again', 'send', 'retry', 'skip', 'restore' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param QueueGateway $queue  Queue.
	 * @param StateMirror  $mirror Copies queue state to the order.
	 */
	public function __construct(
		private readonly QueueGateway $queue,
		private readonly StateMirror $mirror
	) {
	}

	/**
	 * Do what the person asked for one order.
	 *
	 * @param string    $todo  sync_again, send, retry, skip or restore.
	 * @param \WC_Order $order Order.
	 * @param string    $by    Display name of the person, for "Skipped by".
	 * @return string Result key for ActionNotices: queued, retried, skipped, restored, nothing or failed.
	 */
	public function perform( string $todo, \WC_Order $order, string $by = '' ): string {
		$orderId = (int) $order->get_id();
		if ( ! in_array( $todo, self::ACTIONS, true ) ) {
			return 'failed';
		}
		if ( 'sync_again' === $todo ) {
			return null === $this->queue->syncAgain( $orderId ) ? 'nothing' : 'queued';
		}
		if ( 'send' === $todo ) {
			$queueId = $this->queue->enqueue( EntityType::Order, (string) $orderId, OrderHooks::CREATED );
			if ( null !== $queueId ) {
				$this->mirror->mirrorById( $queueId );
			}
			return null === $queueId ? 'nothing' : 'queued';
		}

		$item   = $this->queue->latestForOrder( $orderId );
		$itemId = null === $item ? 0 : (int) ( $item['id'] ?? 0 );
		if ( $itemId <= 0 ) {
			return 'nothing';
		}
		switch ( $todo ) {
			case 'retry':
				$done = $this->queue->retry( $itemId );
				$this->mirror->mirrorById( $itemId );
				return $done ? 'retried' : 'nothing';
			case 'restore':
				$done = $this->queue->restore( $itemId );
				$this->mirror->mirrorById( $itemId );
				return $done ? 'restored' : 'nothing';
			default:
				$reason = __( 'Skipped from the order screen.', 'estasoft-erp-crm-sync-engine' );
				$done   = $this->queue->skip( $itemId, $reason );
				if ( $done ) {
					$this->mirror->noteSkip( $order, $reason, $by, gmdate( 'Y-m-d H:i:s' ) );
				}
				return $done ? 'skipped' : 'nothing';
		}
	}
}
