<?php
/**
 * In-memory id map.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes;

use Estasoft\SyncEngine\Mapping\Ports\IdMapStore;

final class InMemoryIdMap implements IdMapStore {

	/**
	 * Links by "connection|type|wc id".
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $links = array();

	/**
	 * Number of writes.
	 */
	public int $writes = 0;

	public function get( int $connectionId, string $type, string $wcId ): ?array {
		return $this->links[ "$connectionId|$type|$wcId" ] ?? null;
	}

	public function link( int $connectionId, string $type, string $wcId, string $externalId, ?string $externalRef, ?string $externalUrl, ?string $sku, bool $isOverride ): bool {
		$existing = $this->get( $connectionId, $type, $wcId );
		if ( null !== $existing && $existing['is_override'] && ! $isOverride ) {
			return false;
		}
		++$this->writes;
		$this->links[ "$connectionId|$type|$wcId" ] = array(
			'external_id'  => $externalId,
			'external_ref' => $externalRef,
			'external_url' => $externalUrl,
			'sku'          => $sku,
			'is_override'  => $isOverride,
			'wc_id'        => $wcId,
		);
		return true;
	}

	public function forget( int $connectionId, string $type, string $wcId ): void {
		++$this->writes;
		unset( $this->links[ "$connectionId|$type|$wcId" ] );
	}

	public function findBySku( int $connectionId, string $sku ): ?array {
		$best = null;
		foreach ( $this->links as $key => $link ) {
			if ( 0 !== strpos( $key, "$connectionId|product|" ) || null === $link['sku'] || 0 !== strcasecmp( $link['sku'], $sku ) ) {
				continue;
			}
			if ( null === $best || ( $link['is_override'] && ! $best['is_override'] ) ) {
				$best = $link;
			}
		}
		return $best;
	}
}
