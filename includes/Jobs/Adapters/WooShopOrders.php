<?php
/**
 * Orders through the WooCommerce data layer.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Adapters;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Jobs\Ports\ShopOrders;
use Estasoft\SyncEngine\Mapping\Support\MinorUnits;

/**
 * Reads with `wc_get_orders` and `wc_get_order`, writes order meta with `update_meta_data()` and `save_meta_data()` (never
 * `save()`, which would look like a change to sync), adds notes with `add_order_note()` and moves a status with `update_status()`.
 * No SQL on posts, post meta or the order tables.
 */
final class WooShopOrders implements ShopOrders {

	/**
	 * Order meta: JSON list of tracking entries.
	 */
	public const TRACKING = '_estasoft_sync_tracking';

	/**
	 * Order meta: JSON object with the invoice facts.
	 */
	public const INVOICE = '_estasoft_sync_invoice';

	/**
	 * Order meta the order box reads (latest tracking number and carrier, invoice number, status and PDF).
	 */
	public const TRACKING_NUMBER  = '_estasoft_sync_tracking_number';
	public const TRACKING_CARRIER = '_estasoft_sync_tracking_carrier';
	public const INVOICE_NUMBER   = '_estasoft_sync_invoice_number';
	public const INVOICE_STATUS   = '_estasoft_sync_invoice_status';
	public const INVOICE_PDF      = '_estasoft_sync_invoice_pdf';

	/**
	 * Order meta: sync state kept by the Woo module.
	 */
	public const STATE = '_estasoft_sync_state';

	/**
	 * Statuses of orders that count as sold.
	 */
	private const PAID = array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' );

	/**
	 * Summary of one order.
	 *
	 * @param int $orderId Order id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $orderId ): ?array {
		$order = $this->load( $orderId );
		return null === $order ? null : $this->summarize( $order );
	}

	/**
	 * Summaries of several orders.
	 *
	 * @param array<int,int> $orderIds Order ids.
	 * @return array<int,array<string,mixed>>
	 */
	public function summaries( array $orderIds ): array {
		$rows = array();
		foreach ( $orderIds as $id ) {
			$order = $this->load( (int) $id );
			if ( null !== $order ) {
				$rows[ (int) $id ] = $this->summarize( $order );
			}
		}
		return $rows;
	}

	/**
	 * The order object.
	 *
	 * @param int $orderId Order id.
	 */
	public function orderObject( int $orderId ): ?object {
		return $this->load( $orderId );
	}

	/**
	 * Ids of paid orders created since a moment.
	 *
	 * @param \DateTimeImmutable $from    Window start.
	 * @param int                $page    Page from 1.
	 * @param int                $perPage Rows per page.
	 * @return array{ids:array<int,int>,has_more:bool}
	 */
	public function idsSince( \DateTimeImmutable $from, int $page, int $perPage ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'ids'      => array(),
				'has_more' => false,
			);
		}
		$result = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => self::PAID,
				'date_created' => '>=' . $from->getTimestamp(),
				'limit'        => max( 1, $perPage ),
				'page'         => max( 1, $page ),
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'paginate'     => true,
				'return'       => 'ids',
			)
		);
		$ids   = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : ( is_array( $result ) ? $result : array() );
		$pages = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
		return array(
			'ids'      => array_values( array_map( 'intval', $ids ) ),
			'has_more' => $page < $pages,
		);
	}

	/**
	 * Move an order to a status.
	 *
	 * @param int    $orderId Order id.
	 * @param string $status  Status slug.
	 * @param string $note    Order note.
	 * @return bool Whether the status changed.
	 */
	public function updateStatus( int $orderId, string $status, string $note ): bool {
		$order = $this->load( $orderId );
		if ( null === $order ) {
			return false;
		}
		$status = preg_replace( '/^wc-/', '', $status );
		if ( (string) $order->get_status() === $status ) {
			return false;
		}
		$order->update_status( (string) $status, $note );
		return (string) $order->get_status() === $status;
	}

	/**
	 * Add a private note.
	 *
	 * @param int    $orderId Order id.
	 * @param string $note    Note.
	 */
	public function addNote( int $orderId, string $note ): void {
		$order = $this->load( $orderId );
		if ( null !== $order ) {
			$order->add_order_note( $note, 0, false );
		}
	}

	/**
	 * Order statuses WooCommerce knows.
	 *
	 * @return array<string,string>
	 */
	public function statuses(): array {
		$list = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$out  = array();
		foreach ( (array) $list as $slug => $label ) {
			$out[ (string) preg_replace( '/^wc-/', '', (string) $slug ) ] = (string) $label;
		}
		return $out;
	}

	/**
	 * Tracking saved on an order.
	 *
	 * @param int $orderId Order id.
	 * @return array<int,array{carrier:string,number:string,at:string}>
	 */
	public function tracking( int $orderId ): array {
		$order = $this->load( $orderId );
		if ( null === $order ) {
			return array();
		}
		$list = Json::decode( (string) $order->get_meta( self::TRACKING, true ) );
		$out  = array();
		foreach ( $list as $entry ) {
			if ( is_array( $entry ) && isset( $entry['number'] ) ) {
				$out[] = array(
					'carrier' => (string) ( $entry['carrier'] ?? '' ),
					'number'  => (string) $entry['number'],
					'at'      => (string) ( $entry['at'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Replace the tracking on an order.
	 *
	 * @param int                                                      $orderId Order id.
	 * @param array<int,array{carrier:string,number:string,at:string}> $entries Complete list.
	 */
	public function saveTracking( int $orderId, array $entries ): void {
		$order = $this->load( $orderId );
		if ( null === $order ) {
			return;
		}
		$order->update_meta_data( self::TRACKING, Json::encode( array_values( $entries ) ) );
		$last = array() === $entries ? array() : end( $entries );
		if ( is_array( $last ) ) {
			$order->update_meta_data( self::TRACKING_NUMBER, (string) ( $last['number'] ?? '' ) );
			$order->update_meta_data( self::TRACKING_CARRIER, (string) ( $last['carrier'] ?? '' ) );
		}
		$order->save_meta_data();
	}

	/**
	 * Invoice facts saved on an order.
	 *
	 * @param int $orderId Order id.
	 * @return array<string,mixed>|null
	 */
	public function invoice( int $orderId ): ?array {
		$order = $this->load( $orderId );
		if ( null === $order ) {
			return null;
		}
		$facts = Json::decode( (string) $order->get_meta( self::INVOICE, true ) );
		return array() === $facts ? null : $facts;
	}

	/**
	 * Save invoice facts.
	 *
	 * @param int                 $orderId Order id.
	 * @param array<string,mixed> $facts   Facts.
	 */
	public function saveInvoice( int $orderId, array $facts ): void {
		$order = $this->load( $orderId );
		if ( null === $order ) {
			return;
		}
		$order->update_meta_data( self::INVOICE, Json::encode( $facts ) );
		$order->update_meta_data( self::INVOICE_NUMBER, (string) ( $facts['number'] ?? '' ) );
		$order->update_meta_data( self::INVOICE_STATUS, (string) ( $facts['status'] ?? '' ) );
		$order->update_meta_data( self::INVOICE_PDF, (string) ( $facts['pdf_url'] ?? '' ) );
		$order->save_meta_data();
	}

	/**
	 * Orders with invoice facts, newest first.
	 *
	 * @param string|null $status  waiting, rejected, reported, not_required, or null.
	 * @param string      $search  Order number or invoice number text.
	 * @param int         $page    Page from 1.
	 * @param int         $perPage Rows per page.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function invoiceRows( ?string $status, string $search, int $page, int $perPage ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}
		$meta = array(
			array(
				'key'     => self::INVOICE,
				'compare' => 'EXISTS',
			),
		);
		if ( null !== $status && '' !== $status ) {
			$meta[] = array(
				'key'     => self::INVOICE_STATUS,
				'value'   => $status,
				'compare' => '=',
			);
		}
		$args = array(
			'type'       => 'shop_order',
			'status'     => 'any',
			'limit'      => max( 1, $perPage ),
			'page'       => max( 1, $page ),
			'orderby'    => 'date',
			'order'      => 'DESC',
			'paginate'   => true,
			'return'     => 'objects',
			'meta_query' => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		$search = trim( $search );
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$args['s'] = $search;
			} else {
				$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'key'     => self::INVOICE_NUMBER,
					'value'   => $search,
					'compare' => 'LIKE',
				);
			}
		}
		$result = wc_get_orders( $args );
		$orders = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : ( is_array( $result ) ? $result : array() );
		$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );
		$items  = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$row            = $this->summarize( $order );
			$row['invoice'] = Json::decode( (string) $order->get_meta( self::INVOICE, true ) );
			$items[]        = $row;
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Ids of the refunds of an order.
	 *
	 * @param int $orderId Order id.
	 * @return array<int,int>
	 */
	public function refundIds( int $orderId ): array {
		$order = $this->load( $orderId );
		if ( null === $order ) {
			return array();
		}
		$ids = array();
		foreach ( (array) $order->get_refunds() as $refund ) {
			$ids[] = (int) $refund->get_id();
		}
		return $ids;
	}

	/**
	 * Recent orders.
	 *
	 * @param int $limit How many.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'status'  => 'any',
				'limit'   => max( 1, $limit ),
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
			)
		);
		$rows   = array();
		foreach ( (array) $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$rows[] = $this->summarize( $order );
			}
		}
		return $rows;
	}

	/**
	 * Load an order that is a real order (not a refund).
	 *
	 * @param int $orderId Order id.
	 */
	private function load( int $orderId ): ?\WC_Order {
		if ( $orderId <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $orderId );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * The summary shape.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function summarize( \WC_Order $order ): array {
		$currency = strtoupper( (string) $order->get_currency() );
		$exponent = MinorUnits::exponent( $currency );
		$created  = $order->get_date_created();
		$name     = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
		return array(
			'id'             => (int) $order->get_id(),
			'label'          => (string) $order->get_order_number(),
			'status'         => (string) $order->get_status(),
			'currency'       => $currency,
			'total_minor'    => (int) ( MinorUnits::parse( (string) $order->get_total(), $exponent ) ?? 0 ),
			'refunded_minor' => (int) ( MinorUnits::parse( (string) $order->get_total_refunded(), $exponent ) ?? 0 ),
			'email'          => (string) $order->get_billing_email(),
			'customer'       => '' !== $name ? $name : (string) $order->get_billing_company(),
			'payment_method' => (string) $order->get_payment_method(),
			'created_at'     => is_object( $created ) && method_exists( $created, 'getTimestamp' ) ? gmdate( 'Y-m-d H:i:s', (int) $created->getTimestamp() ) : '',
			'sync_state'     => (string) $order->get_meta( self::STATE, true ),
		);
	}
}
