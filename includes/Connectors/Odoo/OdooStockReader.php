<?php
/**
 * Reads stock quantities from Odoo.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

/**
 * Quantities come from product.product with the warehouse in the context, which is how Odoo itself computes the on-hand
 * figure for chosen warehouses (the context key is "warehouse" in Odoo 17 and "warehouse_id" from 18). On hand is
 * qty_available; held is what other documents have reserved (qty_available minus free_qty). Products that are not stocked
 * (services, consumables without tracking) have no quantity and are left out.
 */
final class OdooStockReader {

	/**
	 * References asked for in one call.
	 */
	private const CHUNK = 100;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient  $client  Client.
	 * @param OdooCatalog $catalog Lookups.
	 */
	public function __construct( private readonly OdooClient $client, private readonly OdooCatalog $catalog ) {
	}

	/**
	 * Quantities for some references.
	 *
	 * @param array<int,string> $skus       Internal references.
	 * @param array<int,string> $warehouses Warehouse ids (or codes) that count; empty means all warehouses of the company.
	 * @return array<string,array{quantity:int,held:int}> Keyed by reference.
	 *
	 * @throws OdooException On failure.
	 */
	public function fetch( array $skus, array $warehouses ): array {
		$skus = array_values( array_unique( array_filter( array_map( 'trim', $skus ), static fn( string $sku ): bool => '' !== $sku ) ) );
		if ( array() === $skus ) {
			return array();
		}
		$context = $this->warehouseContext( $warehouses );
		$fields  = array( 'default_code', 'qty_available', 'free_qty', 'type' );
		$storage = $this->client->version()->atLeast( 18 ) && $this->client->hasField( 'product.product', 'is_storable' );
		if ( $storage ) {
			$fields[] = 'is_storable';
		}

		$result = array();
		foreach ( array_chunk( $skus, self::CHUNK ) as $chunk ) {
			$records = $this->client->searchRead( 'product.product', array( array( 'default_code', 'in', $chunk ) ), $fields, array( 'order' => 'id asc' ), $context );
			foreach ( $records as $record ) {
				$sku = OdooRecord::text( $record['default_code'] ?? '' );
				if ( '' === $sku || isset( $result[ $sku ] ) || ! $this->isStocked( $record, $storage ) ) {
					continue;
				}
				$onHand         = (int) floor( OdooRecord::number( $record['qty_available'] ?? 0 ) );
				$free           = (int) floor( OdooRecord::number( $record['free_qty'] ?? 0 ) );
				$result[ $sku ] = array(
					'quantity' => $onHand,
					'held'     => max( 0, min( $onHand, $onHand - $free ) ),
				);
			}
		}
		return $result;
	}

	/**
	 * Whether a product keeps a stock quantity.
	 *
	 * @param array<string,mixed> $record  Product record.
	 * @param bool                $storage True when the version has the is_storable field.
	 */
	private function isStocked( array $record, bool $storage ): bool {
		if ( $storage ) {
			return true === ( $record['is_storable'] ?? false );
		}
		return 'product' === OdooRecord::text( $record['type'] ?? '' );
	}

	/**
	 * The context that limits quantities to some warehouses.
	 *
	 * @param array<int,string> $warehouses Warehouse ids or codes.
	 * @return array<string,mixed>
	 *
	 * @throws OdooException On failure.
	 */
	private function warehouseContext( array $warehouses ): array {
		$ids = array();
		foreach ( $warehouses as $warehouse ) {
			$reference = trim( (string) $warehouse );
			$id        = ctype_digit( $reference ) ? (int) $reference : $this->catalog->warehouseId( $reference );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( array() === $ids ) {
			return array();
		}
		return array( $this->client->version()->atLeast( 18 ) ? 'warehouse_id' : 'warehouse' => $ids );
	}
}
