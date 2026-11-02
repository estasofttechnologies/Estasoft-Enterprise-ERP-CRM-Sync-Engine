<?php
/**
 * WooCommerce orders in memory.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Jobs\Ports\ShopOrders;

final class JobsFakeOrders implements ShopOrders {

	/**
	 * Order summaries by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $orders = array();

	/**
	 * Tracking lists by order id.
	 *
	 * @var array<int,array<int,array{carrier:string,number:string,at:string}>>
	 */
	public array $tracking = array();

	/**
	 * Invoice facts by order id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $invoices = array();

	/**
	 * Refund ids by order id.
	 *
	 * @var array<int,array<int,int>>
	 */
	public array $refunds = array();

	/**
	 * Notes added: array( order id, text ).
	 *
	 * @var array<int,array{0:int,1:string}>
	 */
	public array $notes = array();

	/**
	 * Status changes: array( order id, status, note ).
	 *
	 * @var array<int,array{0:int,1:string,2:string}>
	 */
	public array $statusChanges = array();

	/**
	 * Called each time summaries are read (a test moves the clock here).
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $onRead = null;

	/**
	 * Add an order.
	 *
	 * @param int                 $id    Order id.
	 * @param array<string,mixed> $props Values over the defaults.
	 */
	public function add( int $id, array $props = array() ): void {
		$this->orders[ $id ] = array_merge(
			array(
				'id'             => $id,
				'label'          => (string) $id,
				'status'         => 'processing',
				'currency'       => 'AED',
				'total_minor'    => 48615,
				'refunded_minor' => 0,
				'email'          => 'noor@example.test',
				'customer'       => 'Noor',
				'payment_method' => 'cod',
				'created_at'     => '2026-11-02 08:00:00',
				'sync_state'     => 'synced',
			),
			$props
		);
	}

	public function find( int $orderId ): ?array {
		return $this->orders[ $orderId ] ?? null;
	}

	public function summaries( array $orderIds ): array {
		if ( null !== $this->onRead ) {
			( $this->onRead )( $orderIds );
		}
		$out = array();
		foreach ( $orderIds as $id ) {
			if ( isset( $this->orders[ (int) $id ] ) ) {
				$out[ (int) $id ] = $this->orders[ (int) $id ];
			}
		}
		return $out;
	}

	public function orderObject( int $orderId ): ?object {
		return isset( $this->orders[ $orderId ] ) ? (object) $this->orders[ $orderId ] : null;
	}

	public function idsSince( \DateTimeImmutable $from, int $page, int $perPage ): array {
		$ids = array();
		foreach ( $this->orders as $id => $order ) {
			if ( in_array( $order['status'], array( 'processing', 'completed', 'on-hold', 'refunded' ), true ) && (string) $order['created_at'] >= $from->format( 'Y-m-d H:i:s' ) ) {
				$ids[] = $id;
			}
		}
		sort( $ids );
		$slice = array_slice( $ids, ( $page - 1 ) * $perPage, $perPage );
		return array(
			'ids'      => $slice,
			'has_more' => count( $ids ) > $page * $perPage,
		);
	}

	public function updateStatus( int $orderId, string $status, string $note ): bool {
		if ( ! isset( $this->orders[ $orderId ] ) || $this->orders[ $orderId ]['status'] === $status ) {
			return false;
		}
		$this->orders[ $orderId ]['status'] = $status;
		$this->statusChanges[]              = array( $orderId, $status, $note );
		return true;
	}

	public function addNote( int $orderId, string $note ): void {
		$this->notes[] = array( $orderId, $note );
	}

	public function statuses(): array {
		return array(
			'pending'    => 'Pending payment',
			'processing' => 'Processing',
			'on-hold'    => 'On hold',
			'completed'  => 'Completed',
			'cancelled'  => 'Cancelled',
		);
	}

	public function tracking( int $orderId ): array {
		return $this->tracking[ $orderId ] ?? array();
	}

	public function saveTracking( int $orderId, array $entries ): void {
		$this->tracking[ $orderId ] = array_values( $entries );
	}

	public function invoice( int $orderId ): ?array {
		return $this->invoices[ $orderId ] ?? null;
	}

	public function saveInvoice( int $orderId, array $facts ): void {
		$this->invoices[ $orderId ] = $facts;
	}

	public function invoiceRows( ?string $status, string $search, int $page, int $perPage ): array {
		$rows = array();
		foreach ( $this->invoices as $id => $invoice ) {
			if ( null !== $status && ( $invoice['status'] ?? '' ) !== $status ) {
				continue;
			}
			if ( '' !== $search && false === stripos( (string) ( $invoice['number'] ?? '' ) . ' ' . $id, $search ) ) {
				continue;
			}
			$rows[] = $this->orders[ $id ] + array( 'invoice' => $invoice );
		}
		return array(
			'items' => array_slice( $rows, ( $page - 1 ) * $perPage, $perPage ),
			'total' => count( $rows ),
		);
	}

	public function refundIds( int $orderId ): array {
		return $this->refunds[ $orderId ] ?? array();
	}

	public function recent( int $limit ): array {
		return array_slice( array_reverse( array_values( $this->orders ) ), 0, $limit );
	}
}
