<?php
/**
 * Orders in the shop.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes WooCommerce orders through the WooCommerce data layer only (`wc_get_orders`, order objects, order notes,
 * `update_status`). Money is in integer minor units of the order currency. Times are UTC "Y-m-d H:i:s".
 *
 * An order summary is `array{id:int,label:string,status:string,currency:string,total_minor:int,refunded_minor:int,email:string,customer:string,payment_method:string,created_at:string,sync_state:string}`.
 */
interface ShopOrders {

	/**
	 * Summary of one order.
	 *
	 * @param int $orderId Order id.
	 * @return array<string,mixed>|null Null when the order does not exist.
	 */
	public function find( int $orderId ): ?array;

	/**
	 * Summaries of several orders.
	 *
	 * @param array<int,int> $orderIds Order ids.
	 * @return array<int,array<string,mixed>> Keyed by order id; orders that do not exist are left out.
	 */
	public function summaries( array $orderIds ): array;

	/**
	 * The order object, for hooks that pass it on to other plugins.
	 *
	 * @param int $orderId Order id.
	 */
	public function orderObject( int $orderId ): ?object;

	/**
	 * Ids of paid orders created since a moment, oldest first.
	 *
	 * @param \DateTimeImmutable $from    Window start.
	 * @param int                $page    Page from 1.
	 * @param int                $perPage Rows per page.
	 * @return array{ids:array<int,int>,has_more:bool}
	 */
	public function idsSince( \DateTimeImmutable $from, int $page, int $perPage ): array;

	/**
	 * Move an order to a status and add a note.
	 *
	 * @param int    $orderId Order id.
	 * @param string $status  Status slug without the wc- prefix.
	 * @param string $note    Order note.
	 * @return bool Whether the status changed.
	 */
	public function updateStatus( int $orderId, string $status, string $note ): bool;

	/**
	 * Add a private order note.
	 *
	 * @param int    $orderId Order id.
	 * @param string $note    Note.
	 */
	public function addNote( int $orderId, string $note ): void;

	/**
	 * Order statuses WooCommerce knows.
	 *
	 * @return array<string,string> Slug (no prefix) to label.
	 */
	public function statuses(): array;

	/**
	 * Tracking saved on an order.
	 *
	 * @param int $orderId Order id.
	 * @return array<int,array{carrier:string,number:string,at:string}>
	 */
	public function tracking( int $orderId ): array;

	/**
	 * Replace the tracking saved on an order.
	 *
	 * @param int                                                  $orderId Order id.
	 * @param array<int,array{carrier:string,number:string,at:string}> $entries Complete list.
	 */
	public function saveTracking( int $orderId, array $entries ): void;

	/**
	 * Invoice facts saved on an order.
	 *
	 * @param int $orderId Order id.
	 * @return array<string,mixed>|null
	 */
	public function invoice( int $orderId ): ?array;

	/**
	 * Save invoice facts on an order.
	 *
	 * @param int                 $orderId Order id.
	 * @param array<string,mixed> $facts   number, status, authority_status, issued_at, due_at, pdf_url, qr, reject_reason.
	 */
	public function saveInvoice( int $orderId, array $facts ): void;

	/**
	 * Orders that carry invoice facts, newest first.
	 *
	 * @param string|null $status  waiting, rejected, reported, not_required, or null for all.
	 * @param string      $search  Order number or invoice number text.
	 * @param int         $page    Page from 1.
	 * @param int         $perPage Rows per page.
	 * @return array{items:array<int,array<string,mixed>>,total:int} Each item is an order summary plus `invoice`.
	 */
	public function invoiceRows( ?string $status, string $search, int $page, int $perPage ): array;

	/**
	 * Ids of the refunds of an order.
	 *
	 * @param int $orderId Order id.
	 * @return array<int,int>
	 */
	public function refundIds( int $orderId ): array;

	/**
	 * Recent orders for the setup preview.
	 *
	 * @param int $limit How many.
	 * @return array<int,array<string,mixed>> Order summaries, newest first.
	 */
	public function recent( int $limit ): array;
}
