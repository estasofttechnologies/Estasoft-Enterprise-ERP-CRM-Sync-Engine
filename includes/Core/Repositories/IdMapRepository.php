<?php
/**
 * WooCommerce id to external system id.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\EntityType;

/**
 * Reads and writes `estasoft_sync_id_map`. The type is an EntityType value, or `product` for products and variations.
 * A product row with `is_override` set was chosen by a person on the SKU matching screen and is never replaced by
 * automatic matching.
 */
final class IdMapRepository extends Repository {

	/**
	 * Type value used for products and variations.
	 */
	public const PRODUCT = 'product';

	/**
	 * Find the link for a WooCommerce object.
	 *
	 * @param int                $connectionId Connection.
	 * @param EntityType|string  $type         Entity type, or `product`.
	 * @param string             $wcId         WooCommerce id.
	 * @return array{external_id:string,external_ref:?string,external_url:?string,sku:?string,is_override:bool}|null
	 */
	public function get( int $connectionId, EntityType|string $type, string $wcId ): ?array {
		$row = $this->row(
			"SELECT * FROM {$this->tables->idMap()} WHERE connection_id = %d AND entity_type = %s AND wc_id = %s",
			array( $connectionId, self::typeValue( $type ), $wcId )
		);
		return null === $row ? null : $this->shape( $row );
	}

	/**
	 * Create or replace a link. A link marked as an override is kept unless the new one is an override too.
	 *
	 * @param int                $connectionId Connection.
	 * @param EntityType|string  $type         Entity type, or `product`.
	 * @param string             $wcId         WooCommerce id.
	 * @param string             $externalId   The system's id.
	 * @param string|null        $externalRef  The system's human number.
	 * @param string|null        $externalUrl  Link to the record.
	 * @param string|null        $sku          SKU, for products.
	 * @param bool               $isOverride   True when a person chose this link.
	 * @return bool Whether the link was written (false when an override protected the existing one).
	 */
	public function link( int $connectionId, EntityType|string $type, string $wcId, string $externalId, ?string $externalRef = null, ?string $externalUrl = null, ?string $sku = null, bool $isOverride = false ): bool {
		$existing = $this->get( $connectionId, $type, $wcId );
		if ( null !== $existing && $existing['is_override'] && ! $isOverride ) {
			return false;
		}
		$now  = $this->now();
		$args = array( $connectionId, self::typeValue( $type ), $wcId, mb_substr( $externalId, 0, 191 ) );
		$ref  = $this->bind( $externalRef, '%s', $args );
		$url  = $this->bind( $externalUrl, '%s', $args );
		$code = $this->bind( $sku, '%s', $args );
		array_push( $args, $isOverride ? 1 : 0, $now, $now );
		$this->run(
			"INSERT INTO {$this->tables->idMap()} (connection_id, entity_type, wc_id, external_id, external_ref, external_url, sku, is_override, created_at, updated_at)
			VALUES (%d, %s, %s, %s, $ref, $url, $code, %d, %s, %s)
			ON DUPLICATE KEY UPDATE external_id = VALUES(external_id), external_ref = VALUES(external_ref), external_url = VALUES(external_url), sku = VALUES(sku), is_override = VALUES(is_override), updated_at = VALUES(updated_at)",
			$args
		);
		return true;
	}

	/**
	 * Remove a link (for example when a person unmaps a product).
	 *
	 * @param int                $connectionId Connection.
	 * @param EntityType|string  $type         Entity type, or `product`.
	 * @param string             $wcId         WooCommerce id.
	 */
	public function forget( int $connectionId, EntityType|string $type, string $wcId ): void {
		$this->run(
			"DELETE FROM {$this->tables->idMap()} WHERE connection_id = %d AND entity_type = %s AND wc_id = %s",
			array( $connectionId, self::typeValue( $type ), $wcId )
		);
	}

	/**
	 * Find a product link by SKU, preferring one a person chose.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $sku          SKU.
	 * @return array{external_id:string,external_ref:?string,external_url:?string,sku:?string,is_override:bool,wc_id:string}|null
	 */
	public function findBySku( int $connectionId, string $sku ): ?array {
		$row = $this->row(
			"SELECT * FROM {$this->tables->idMap()} WHERE connection_id = %d AND entity_type = %s AND sku = %s ORDER BY is_override DESC, id ASC LIMIT 1",
			array( $connectionId, self::PRODUCT, $sku )
		);
		return null === $row ? null : array_merge( $this->shape( $row ), array( 'wc_id' => (string) $row['wc_id'] ) );
	}

	/**
	 * Find the WooCommerce object linked to a system id.
	 *
	 * @param int                $connectionId Connection.
	 * @param EntityType|string  $type         Entity type, or `product`.
	 * @param string             $externalId   The system's id.
	 * @return string|null The WooCommerce id.
	 */
	public function findWcId( int $connectionId, EntityType|string $type, string $externalId ): ?string {
		return $this->value(
			"SELECT wc_id FROM {$this->tables->idMap()} WHERE connection_id = %d AND entity_type = %s AND external_id = %s ORDER BY id ASC LIMIT 1",
			array( $connectionId, self::typeValue( $type ), $externalId )
		);
	}

	/**
	 * The string stored for a type.
	 *
	 * @param EntityType|string $type Entity type or string.
	 */
	private static function typeValue( EntityType|string $type ): string {
		return $type instanceof EntityType ? $type->value : $type;
	}

	/**
	 * The shape returned to callers.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array{external_id:string,external_ref:?string,external_url:?string,sku:?string,is_override:bool}
	 */
	private function shape( array $row ): array {
		return array(
			'external_id'  => (string) $row['external_id'],
			'external_ref' => self::stringOrNull( $row['external_ref'] ),
			'external_url' => self::stringOrNull( $row['external_url'] ),
			'sku'          => self::stringOrNull( $row['sku'] ),
			'is_override'  => (bool) $row['is_override'],
		);
	}
}
