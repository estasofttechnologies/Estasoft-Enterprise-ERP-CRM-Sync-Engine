<?php
/**
 * Version 1 mapping rules for Finance and Supply Chain.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\MappingRules;

/**
 * The starting rules a merchant sees before editing anything. Countries are written as three letters, which is how Finance and Operations
 * names them (USA, SAU, ARE). No tax code is mapped to start with: a line without one gets the item's own sales tax group.
 */
final class FoDefaultMappings {

	/**
	 * Rules for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => array_merge( MappingRules::orderHeader(), MappingRules::customer( 'partner.', true, 'alpha3' ), MappingRules::delivery( 'alpha3' ), MappingRules::lines( true, false ) ),
			'customers' => MappingRules::customer( 'partner.', true, 'alpha3' ),
			'products'  => MappingRules::products(),
			default     => array(),
		};
	}
}
