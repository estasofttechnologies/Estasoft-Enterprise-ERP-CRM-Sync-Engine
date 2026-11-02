<?php
/**
 * What a rule run may consult besides the order itself.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * The connection being mapped for, the system's display name (used in messages), and the two lookups the
 * tax_lookup and sku_match transforms need. Both lookups are optional so the engine runs in tests and previews without them.
 */
final class MappingEnvironment {

	/**
	 * Set up with what it depends on.
	 *
	 * @param int                  $connectionId Connection id, 0 when none.
	 * @param string               $system       System name for messages, for example Odoo.
	 * @param TaxResolver|null     $taxes        Tax code lookup.
	 * @param ProductResolver|null $products     Product lookup.
	 */
	public function __construct(
		public readonly int $connectionId = 0,
		public readonly string $system = '',
		public readonly ?TaxResolver $taxes = null,
		public readonly ?ProductResolver $products = null,
	) {
	}
}
