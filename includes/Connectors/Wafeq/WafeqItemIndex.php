<?php
/**
 * An index of Wafeq items by SKU, kept between requests.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Wafeq's item list has no SKU filter [documented], so a SKU is found by reading the list. Reading all of it for every order wastes the call
 * quota and, on a big catalogue, runs into the rate limit. The index keeps `UPPER-CASE SKU => item id` in a store (a transient in the engine) and is built
 * page by page: a read stops as soon as the SKU is found, remembers where it stopped, and the next read goes on from there. A rate limit in the
 * middle of a read therefore loses nothing.
 *
 * A SKU missing from a finished index counts as unknown for five minutes; after that the list is read again from the start, so a product added
 * in Wafeq is found without waiting for the hour the index lives. Anything that makes Wafeq refuse an item id drops the index (`forget()`).
 */
final class WafeqItemIndex {

	/**
	 * Seconds an index lives.
	 */
	public const TTL = 3600;

	/**
	 * Seconds a finished index is trusted for a SKU it does not hold.
	 */
	public const MISS_TTL = 300;

	/**
	 * Most SKUs kept.
	 */
	public const MAX_ENTRIES = 20000;

	/**
	 * Indexes read in this run, by connection.
	 *
	 * @var array<int,array{at:int,complete:bool,next:string,items:array<string,string>}>
	 */
	private array $memory = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param WafeqPager      $pager Reads one page of a list.
	 * @param Clock           $clock Time source.
	 * @param WafeqIndexStore $store Where the index is kept.
	 */
	public function __construct( private readonly WafeqPager $pager, private readonly Clock $clock, private readonly WafeqIndexStore $store ) {
	}

	/**
	 * The item with a SKU (exact, case-insensitive): its id and its SKU as Wafeq writes it, or null.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $sku          SKU.
	 * @return array{id:string,sku:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, int $connectionId, string $sku ): ?array {
		$key   = strtoupper( trim( $sku ) );
		$now   = $this->clock->now()->getTimestamp();
		$index = $this->load( $connectionId, $now );
		if ( null !== $index && isset( $index['items'][ $key ] ) ) {
			return $this->decode( $key, $index['items'][ $key ] );
		}
		if ( null !== $index && $index['complete'] && $now - $index['at'] < self::MISS_TTL ) {
			return null;
		}
		if ( null === $index || $index['complete'] ) {
			$index = array(
				'at'       => $now,
				'complete' => false,
				'next'     => '',
				'items'    => array(),
			);
		}
		$limit = WafeqPager::MAX_PAGES;
		while ( ! $index['complete'] && $limit-- > 0 ) {
			$page = $this->pager->page( $api, 'items/', '' === $index['next'] ? null : $index['next'] );
			foreach ( $page['rows'] as $row ) {
				$written = WafeqPayloadReader::text( $row['sku'] ?? '' );
				$rowSku  = strtoupper( $written );
				$id      = WafeqPayloadReader::text( $row['id'] ?? '' );
				if ( '' !== $rowSku && '' !== $id && ! isset( $index['items'][ $rowSku ] ) ) {
					// The SKU is written after the id only when it is not already upper case, to keep the index small.
					$index['items'][ $rowSku ] = $written === $rowSku ? $id : $id . '|' . $written;
				}
			}
			$index['next']     = $page['next'];
			$index['complete'] = '' === $page['next'];
			if ( count( $index['items'] ) > self::MAX_ENTRIES ) {
				$this->forget( $connectionId );
				throw new ApiException( $this->tooLong() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
			}
			$this->save( $connectionId, $index );
			if ( isset( $index['items'][ $key ] ) ) {
				return $this->decode( $key, $index['items'][ $key ] );
			}
		}
		if ( ! $index['complete'] ) {
			throw new ApiException( $this->tooLong() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return null;
	}

	/**
	 * An entry of the index as an item.
	 *
	 * @param string $key   Upper-case SKU.
	 * @param string $entry The id, or the id and the SKU as written, separated by a bar.
	 * @return array{id:string,sku:string}
	 */
	private function decode( string $key, string $entry ): array {
		$bar = strpos( $entry, '|' );
		return false === $bar
			? array(
				'id'  => $entry,
				'sku' => $key,
			)
			: array(
				'id'  => substr( $entry, 0, $bar ),
				'sku' => substr( $entry, $bar + 1 ),
			);
	}

	/**
	 * Drop the index of a connection.
	 *
	 * @param int $connectionId Connection id.
	 */
	public function forget( int $connectionId ): void {
		unset( $this->memory[ $connectionId ] );
		$this->store->forget( $connectionId );
	}

	/**
	 * The index of a connection, from memory or the store, when it has not expired.
	 *
	 * @param int $connectionId Connection id.
	 * @param int $now          Now (Unix time).
	 * @return array{at:int,complete:bool,next:string,items:array<string,string>}|null
	 */
	private function load( int $connectionId, int $now ): ?array {
		$index = $this->memory[ $connectionId ] ?? $this->fromStore( $connectionId );
		if ( null === $index || $now - $index['at'] >= self::TTL ) {
			return null;
		}
		$this->memory[ $connectionId ] = $index;
		return $index;
	}

	/**
	 * The index in the store, checked for its shape.
	 *
	 * @param int $connectionId Connection id.
	 * @return array{at:int,complete:bool,next:string,items:array<string,string>}|null
	 */
	private function fromStore( int $connectionId ): ?array {
		$value = $this->store->get( $connectionId );
		if ( null === $value || ! isset( $value['at'], $value['items'] ) || ! is_int( $value['at'] ) || ! is_array( $value['items'] ) ) {
			return null;
		}
		$items = array();
		foreach ( $value['items'] as $sku => $id ) {
			if ( is_string( $id ) ) {
				$items[ (string) $sku ] = $id;
			}
		}
		return array(
			'at'       => $value['at'],
			'complete' => true === ( $value['complete'] ?? false ),
			'next'     => is_string( $value['next'] ?? null ) ? $value['next'] : '',
			'items'    => $items,
		);
	}

	/**
	 * Keep an index in memory and in the store.
	 *
	 * @param int                                                                $connectionId Connection id.
	 * @param array{at:int,complete:bool,next:string,items:array<string,string>} $index        Index.
	 */
	private function save( int $connectionId, array $index ): void {
		$this->memory[ $connectionId ] = $index;
		$this->store->put( $connectionId, $index, self::TTL );
	}

	/**
	 * SYNC-VAL-002 for an item list too long to index.
	 */
	private function tooLong(): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => WafeqConnector::LABEL,
				'field'  => 'items/',
				'reason' => __( 'The item list is longer than the connector reads. Map the products to Wafeq items by hand.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			null,
			null,
			null,
			'items too many'
		);
	}
}
