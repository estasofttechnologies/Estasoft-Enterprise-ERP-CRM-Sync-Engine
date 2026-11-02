<?php
/**
 * Version 1 mapping rules for Business Central.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\MappingRules;

/**
 * The starting rules a merchant sees before editing anything.
 */
final class BcDefaultMappings {

	/**
	 * Rules for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => array_merge( MappingRules::orderHeader(), MappingRules::customer(), MappingRules::delivery(), MappingRules::lines( true ) ),
			'customers' => MappingRules::customer(),
			'products'  => MappingRules::products(),
			'refunds'   => $this->refunds(),
			default     => array(),
		};
	}

	/**
	 * Refunds to credit memos.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function refunds(): array {
		return array(
			MappingRules::rule( 'refund-order-ref', 'order_ref', 'refund.order_id', array( MappingRules::transform( 'prefix', array( 'value' => 'WC-' ) ) ), true, 'SYNC-VAL-002' ),
			MappingRules::rule( 'refund-date', 'date', 'refund.created_at', array( MappingRules::transform( 'date_utc', array( 'format' => 'date' ) ) ) ),
			MappingRules::rule( 'refund-reason', 'reason', 'refund.reason', array( MappingRules::transform( 'trim' ) ) ),
			MappingRules::rule( 'refund-amount', 'amount', 'refund.amount' ),
			MappingRules::rule( 'refund-line-product', 'refund_line[].product', 'refund.lines[].sku', array( MappingRules::transform( 'trim' ) ) ),
			MappingRules::rule( 'refund-line-quantity', 'refund_line[].quantity', 'refund.lines[].quantity' ),
			MappingRules::rule( 'refund-line-amount', 'refund_line[].amount', 'refund.lines[].amount' ),
			MappingRules::rule( 'refund-shipping', 'shipping.total', 'refund.shipping_amount' ),
			MappingRules::rule( 'refund-other', 'other.total', 'refund.other_amount' ),
		);
	}
}
