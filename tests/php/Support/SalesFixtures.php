<?php
/**
 * Synthetic orders and refunds in the neutral payload shape of the CRM and accounting connectors. No real people, tokens or tax numbers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;

final class SalesFixtures {

	/**
	 * An order item and its mapped payload.
	 *
	 * @param string              $orderId WooCommerce order id.
	 * @param array<string,mixed> $o       name, email, street, currency, quantity, unit_minor, sku, paid, company, note, shipping, tax, lines.
	 * @return array{0:Item,1:array<string,mixed>}
	 */
	public static function order( string $orderId, array $o = array() ): array {
		$name     = (string) ( $o['name'] ?? 'Dana Example' );
		$parts    = explode( ' ', $name, 2 );
		$unit     = (int) ( $o['unit_minor'] ?? 2500 );
		$quantity = (int) ( $o['quantity'] ?? 2 );
		$shipping = (int) ( $o['shipping'] ?? 0 );
		$tax      = (int) ( $o['tax'] ?? 0 );
		$lines    = $o['lines'] ?? array(
			array(
				'sku'        => $o['sku'] ?? 'CS-MUG-01',
				'name'       => $o['product'] ?? 'Ceramic mug, sand',
				'quantity'   => (string) $quantity,
				'unit_price' => $unit,
				'total'      => $unit * $quantity,
				'tax'        => $tax,
			),
		);
		$goods    = 0;
		foreach ( $lines as $line ) {
			$goods += (int) $line['total'] + (int) $line['tax'];
		}
		$payload = array(
			'reference'          => 'WC-' . $orderId,
			'currency'           => $o['currency'] ?? 'EUR',
			'date'               => '2026-11-01 10:00:00',
			'paid_at'            => ! empty( $o['paid'] ) ? '2026-11-01 10:05:00' : '',
			'note'               => $o['note'] ?? '',
			'total'              => $goods + $shipping,
			'excluded_total'     => (int) ( $o['excluded'] ?? 0 ),
			'prices_include_tax' => false,
			'contact'            => array(
				'email'      => $o['email'] ?? 'dana.example@example.test',
				'first_name' => $parts[0],
				'last_name'  => $parts[1] ?? '',
				'company'    => $o['company'] ?? '',
				'phone'      => '+971500000000',
				'street'     => $o['street'] ?? '1 Example Street',
				'city'       => 'Dubai',
				'state'      => 'DU',
				'postcode'   => '00000',
				'country'    => 'AE',
			),
			'lines'              => $lines,
			'shipping'           => array( 'total' => $shipping ),
			'fees'               => $o['fees'] ?? array(),
			'discount'           => array( 'total' => 0, 'codes' => '' ),
			'payment'            => array( 'reference' => 'ch_synthetic_1' ),
		);
		return array( new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'key-' . $orderId, ItemState::Syncing ), $payload );
	}

	/**
	 * A refund item and payload for an order.
	 *
	 * @param string              $refundId Refund id.
	 * @param string              $orderId  Order id.
	 * @param array<string,mixed> $o        amount, currency, reason.
	 * @return array{0:Item,1:array<string,mixed>}
	 */
	public static function refund( string $refundId, string $orderId, array $o = array() ): array {
		$payload = array(
			'order_ref' => 'WC-' . $orderId,
			'amount'    => (int) ( $o['amount'] ?? 1200 ),
			'currency'  => $o['currency'] ?? 'EUR',
			'date'      => '2026-11-02 09:00:00',
			'reason'    => $o['reason'] ?? 'Damaged in transit',
		);
		return array( new Item( 900 + (int) $refundId, 1, EntityType::Refund, $refundId, 'created', 'order:' . $orderId, 'rkey-' . $refundId, ItemState::Syncing ), $payload );
	}
}
