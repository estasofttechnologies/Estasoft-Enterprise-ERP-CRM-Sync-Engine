<?php
/**
 * What the person chose on the "Choose what to sync" step of setup.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented over Core\OnboardingState (answers.what, written by the setup wizard). Only the choices that decide what WooCommerce
 * hands to the queue are here; the other answers belong to the system the shop is connected to.
 */
interface SendChoices {

	/**
	 * Whether orders are sent at all. When this is off no order, refund or new customer is queued by the hooks (a person can still
	 * send one by hand from the order screen).
	 */
	public function sendOrders(): bool;

	/**
	 * When an order is first sent: `placed` (as soon as it is placed), `paid_or_cod` (when it is paid, or placed with cash on
	 * delivery) or `completed` (when it is completed).
	 */
	public function sendWhen(): string;

	/**
	 * Whether a refund is sent after its order.
	 */
	public function sendRefunds(): bool;
}
