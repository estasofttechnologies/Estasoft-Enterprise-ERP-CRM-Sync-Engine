<?php
/**
 * Where WooCommerce ids and the connected system's ids are linked.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * The part of Core\Repositories\IdMapRepository that the mapping module uses (estasoft_sync_id_map). A row with is_override set was
 * chosen by a person (Map this SKU) and always wins over a lookup.
 */
interface IdMapStore {

	/**
	 * The link for one WooCommerce object, or null.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         product (also for variations), customer or order.
	 * @param string $wcId         WooCommerce id.
	 * @return array{external_id:string,external_ref:?string,external_url:?string,sku:?string,is_override:bool}|null
	 */
	public function get( int $connectionId, string $type, string $wcId ): ?array;

	/**
	 * Save or replace a link. A link a person chose is kept unless the new one is a person's choice too.
	 *
	 * @param int         $connectionId Connection.
	 * @param string      $type         product, customer or order.
	 * @param string      $wcId         WooCommerce id.
	 * @param string      $externalId   The system's id.
	 * @param string|null $externalRef  The system's reference (for a product, its internal reference).
	 * @param string|null $externalUrl  Link to the record.
	 * @param string|null $sku          SKU, for products.
	 * @param bool        $isOverride   True when a person chose it.
	 * @return bool Whether the link was written.
	 */
	public function link( int $connectionId, string $type, string $wcId, string $externalId, ?string $externalRef, ?string $externalUrl, ?string $sku, bool $isOverride ): bool;

	/**
	 * Remove a link.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         product, customer or order.
	 * @param string $wcId         WooCommerce id.
	 */
	public function forget( int $connectionId, string $type, string $wcId ): void;

	/**
	 * Find a product link by SKU, preferring one a person chose.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $sku          SKU.
	 * @return array{external_id:string,external_ref:?string,external_url:?string,sku:?string,is_override:bool,wc_id:string}|null
	 */
	public function findBySku( int $connectionId, string $sku ): ?array;
}
