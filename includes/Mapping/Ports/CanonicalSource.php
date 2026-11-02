<?php
/**
 * Where canonical data comes from.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Ports;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by Woo\WooOrderReader. Reads through the WooCommerce data layer only; never writes.
 */
interface CanonicalSource {

	/**
	 * Read an order, refund or customer as canonical data.
	 *
	 * @param EntityType        $type      Order, Refund (the canonical order of the parent plus the refund) or Customer.
	 * @param string            $entityId  WooCommerce id.
	 * @param array<int,string> $metaKeys  Order or customer meta keys to expose as meta.<key>.
	 * @return CanonicalOrder
	 *
	 * @throws SourceNotFoundException When the WooCommerce object no longer exists.
	 */
	public function read( EntityType $type, string $entityId, array $metaKeys = array() ): CanonicalOrder;
}
