<?php
/**
 * Whether an order was placed after syncing went live.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Woo\Ports\GoLiveInfo;

defined( 'ABSPATH' ) || exit;

/**
 * One rule for the hooks and the order box: an order created at or after the go-live time is a real order that must reach the other
 * system, whichever event is the first to be seen for it. An order created before it stays "Placed before go-live" until a person
 * sends it. While setup is unfinished (no go-live time) no order counts as placed after it.
 */
final class GoLive {

	/**
	 * Set up with what it depends on.
	 *
	 * @param GoLiveInfo $info The go-live time.
	 */
	public function __construct( private readonly GoLiveInfo $info ) {
	}

	/**
	 * Whether the order was created at or after the go-live time.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function placedAfter( \WC_Order $order ): bool {
		$live    = $this->info->wentLiveAt();
		$created = $order->get_date_created();
		return null !== $live && null !== $created && $created->getTimestamp() >= $live->getTimestamp();
	}
}
