<?php
/**
 * Id map store backed by the core id map repository.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Adapters;

use Estasoft\SyncEngine\Core\Repositories\IdMapRepository;
use Estasoft\SyncEngine\Mapping\Ports\IdMapStore;

defined( 'ABSPATH' ) || exit;

/**
 * Passes every call to Core\Repositories\IdMapRepository.
 */
final class CoreIdMapStore implements IdMapStore {

	/**
	 * Set up with what it depends on.
	 *
	 * @param IdMapRepository $repository Core repository.
	 */
	public function __construct( private readonly IdMapRepository $repository ) {
	}

	/**
	 * The link for one object.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         product, customer or order.
	 * @param string $wcId         WooCommerce id.
	 * @return array{external_id:string,external_ref:?string,external_url:?string,sku:?string,is_override:bool}|null
	 */
	public function get( int $connectionId, string $type, string $wcId ): ?array {
		return $this->repository->get( $connectionId, $type, $wcId );
	}

	/**
	 * Save a link.
	 *
	 * @param int         $connectionId Connection.
	 * @param string      $type         product, customer or order.
	 * @param string      $wcId         WooCommerce id.
	 * @param string      $externalId   The system's id.
	 * @param string|null $externalRef  The system's reference.
	 * @param string|null $externalUrl  Link.
	 * @param string|null $sku          SKU.
	 * @param bool        $isOverride   Chosen by a person.
	 */
	public function link( int $connectionId, string $type, string $wcId, string $externalId, ?string $externalRef, ?string $externalUrl, ?string $sku, bool $isOverride ): bool {
		return $this->repository->link( $connectionId, $type, $wcId, $externalId, $externalRef, $externalUrl, $sku, $isOverride );
	}

	/**
	 * Remove a link.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         product, customer or order.
	 * @param string $wcId         WooCommerce id.
	 */
	public function forget( int $connectionId, string $type, string $wcId ): void {
		$this->repository->forget( $connectionId, $type, $wcId );
	}

	/**
	 * Find a product link by SKU.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $sku          SKU.
	 * @return array{external_id:string,external_ref:?string,external_url:?string,sku:?string,is_override:bool,wc_id:string}|null
	 */
	public function findBySku( int $connectionId, string $sku ): ?array {
		return $this->repository->findBySku( $connectionId, $sku );
	}
}
