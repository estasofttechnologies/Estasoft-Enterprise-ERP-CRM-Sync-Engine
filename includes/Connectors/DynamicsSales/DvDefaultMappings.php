<?php
/**
 * Version 1 mapping rules for Dynamics 365 Sales.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\MappingRules;

/**
 * The starting rules a merchant sees before editing anything.
 */
final class DvDefaultMappings {

	/**
	 * Rules for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => array_merge( MappingRules::orderHeader(), MappingRules::customer( 'partner.', true ), MappingRules::lines( false ) ),
			'customers' => MappingRules::customer( 'partner.', true ),
			'products'  => MappingRules::products(),
			'refunds'   => array(
				MappingRules::rule( 'refund-order-ref', 'order_ref', 'refund.order_id', array( MappingRules::transform( 'prefix', array( 'value' => 'WC-' ) ) ), true, 'SYNC-VAL-002' ),
				MappingRules::rule( 'refund-amount', 'amount', 'refund.amount', array(), true, 'SYNC-VAL-002' ),
				MappingRules::rule( 'refund-reason', 'reason', 'refund.reason', array( MappingRules::transform( 'trim' ) ) ),
			),
			default     => array(),
		};
	}
}
