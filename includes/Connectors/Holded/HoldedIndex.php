<?php
/**
 * Indexes of Holded contacts and products, kept between requests.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Holded v1 has no contact or product filter [third-party], and a plan has a monthly quota of 500 to 100,000 calls. Reading every contact for
 * every order would spend it. An index keeps `key => value` for a list (contacts by email, products by SKU) in a store (a transient in the
 * engine), and is built page by page: a read stops as soon as the key is found, remembers the page it reached, and the next read goes on from
 * there. A rate limit or the quota guard in the middle of a read therefore loses nothing.
 *
 * A key that is not in a finished index counts as unknown until `missTtl` has passed; then the list is read again from the start. An index
 * lives an hour. Whatever the connector itself creates is added at once, so its own contacts are never missed.
 */
final class HoldedIndex {

	/**
	 * Seconds an index lives.
	 */
	public const TTL = 3600;

	/**
	 * Most entries kept.
	 */
	public const MAX_ENTRIES = 20000;

	/**
	 * Indexes read in this run, by connection and name.
	 *
	 * @var array<string,array{at:int,complete:bool,page:int,first:string,items:array<string,string>}>
	 */
	private array $memory = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedPager      $pager Reads one page of a list.
	 * @param Clock            $clock Time source.
	 * @param HoldedIndexStore $store Where an index is kept.
	 */
	public function __construct( private readonly HoldedPager $pager, private readonly Clock $clock, private readonly HoldedIndexStore $store ) {
	}

	/**
	 * The value kept for a key, reading the list as far as needed.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $name         Index name (`contacts`, `products`).
	 * @param string    $path         List path.
	 * @param callable  $entry        Turns a record into [key, value], or null to skip it.
	 * @param string    $key          The key looked for (already normalised the way `entry` does).
	 * @param int       $missTtl      Seconds a finished index is trusted for a key it does not hold.
	 * @return string|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, int $connectionId, string $name, string $path, callable $entry, string $key, int $missTtl ): ?string {
		$now   = $this->clock->now()->getTimestamp();
		$index = $this->load( $connectionId, $name, $now );
		if ( null !== $index && isset( $index['items'][ $key ] ) ) {
			return $index['items'][ $key ];
		}
		if ( null !== $index && $index['complete'] && $now - $index['at'] < $missTtl ) {
			return null;
		}
		if ( null === $index || $index['complete'] ) {
			$index = $this->fresh( $now );
		}
		$index = $this->scan( $api, $connectionId, $name, $path, $entry, $index, $key );
		return $index['items'][ $key ] ?? null;
	}

	/**
	 * Every entry of a list, reading it to the end.
	 *
	 * @param ApiClient $api          Client.
	 * @param int       $connectionId Connection id.
	 * @param string    $name         Index name.
	 * @param string    $path         List path.
	 * @param callable  $entry        Turns a record into [key, value], or null to skip it.
	 * @return array<string,string>
	 *
	 * @throws ApiException On failure.
	 */
	public function all( ApiClient $api, int $connectionId, string $name, string $path, callable $entry ): array {
		$now   = $this->clock->now()->getTimestamp();
		$index = $this->load( $connectionId, $name, $now ) ?? $this->fresh( $now );
		if ( ! $index['complete'] ) {
			$index = $this->scan( $api, $connectionId, $name, $path, $entry, $index, null );
		}
		return $index['items'];
	}

	/**
	 * Add what the connector has just created, when an index exists.
	 *
	 * @param int    $connectionId Connection id.
	 * @param string $name         Index name.
	 * @param string $key          Key.
	 * @param string $value        Value.
	 */
	public function add( int $connectionId, string $name, string $key, string $value ): void {
		$now   = $this->clock->now()->getTimestamp();
		$index = $this->load( $connectionId, $name, $now );
		if ( null === $index ) {
			return;
		}
		$index['items'][ $key ] = $value;
		$this->save( $connectionId, $name, $index );
	}

	/**
	 * Drop an index.
	 *
	 * @param int    $connectionId Connection id.
	 * @param string $name         Index name.
	 */
	public function forget( int $connectionId, string $name ): void {
		unset( $this->memory[ $connectionId . '|' . $name ] );
		$this->store->forget( $connectionId, $name );
	}

	/**
	 * Read pages from where the index stopped until the key is found, the list ends, or the cap is hit.
	 *
	 * @param ApiClient                                                                    $api          Client.
	 * @param int                                                                          $connectionId Connection id.
	 * @param string                                                                       $name         Index name.
	 * @param string                                                                       $path         List path.
	 * @param callable                                                                     $entry        Record to [key, value].
	 * @param array{at:int,complete:bool,page:int,first:string,items:array<string,string>} $index Index so far.
	 * @param string|null                                                                  $key          Key looked for, or null to read to the end.
	 * @return array{at:int,complete:bool,page:int,first:string,items:array<string,string>}
	 *
	 * @throws ApiException On failure.
	 */
	private function scan( ApiClient $api, int $connectionId, string $name, string $path, callable $entry, array $index, ?string $key ): array {
		for ( $reads = 0; $reads < HoldedPager::MAX_PAGES && ! $index['complete']; ++$reads ) {
			$rows  = $this->pager->page( $api, $path, $index['page'] );
			$first = array() === $rows ? '' : HoldedPayloadReader::text( $rows[0]['id'] ?? ( $rows[0]['_id'] ?? '' ) );
			// An empty page, or the same first record as the page before, is the end of the list (a server that ignores `page` repeats itself).
			if ( array() === $rows || ( '' !== $first && $first === $index['first'] ) ) {
				$index['complete'] = true;
				$this->save( $connectionId, $name, $index );
				return $index;
			}
			foreach ( $rows as $row ) {
				$pair = $entry( $row );
				if ( null !== $pair && '' !== $pair[0] && ! isset( $index['items'][ $pair[0] ] ) ) {
					$index['items'][ $pair[0] ] = $pair[1];
				}
			}
			$index['first'] = $first;
			++$index['page'];
			$this->save( $connectionId, $name, $index );
			if ( count( $index['items'] ) > self::MAX_ENTRIES ) {
				$this->forget( $connectionId, $name );
				throw new ApiException( $this->tooLong( $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
			}
			if ( null !== $key && isset( $index['items'][ $key ] ) ) {
				return $index;
			}
		}
		if ( ! $index['complete'] ) {
			throw new ApiException( $this->tooLong( $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return $index;
	}

	/**
	 * An empty, unfinished index.
	 *
	 * @param int $now Now (Unix time).
	 * @return array{at:int,complete:bool,page:int,first:string,items:array<string,string>}
	 */
	private function fresh( int $now ): array {
		return array(
			'at'       => $now,
			'complete' => false,
			'page'     => 1,
			'first'    => '',
			'items'    => array(),
		);
	}

	/**
	 * The index of a connection and name, from memory or the store, when it has not expired.
	 *
	 * @param int    $connectionId Connection id.
	 * @param string $name         Index name.
	 * @param int    $now          Now (Unix time).
	 * @return array{at:int,complete:bool,page:int,first:string,items:array<string,string>}|null
	 */
	private function load( int $connectionId, string $name, int $now ): ?array {
		$slot  = $connectionId . '|' . $name;
		$index = $this->memory[ $slot ] ?? $this->fromStore( $connectionId, $name );
		if ( null === $index || $now - $index['at'] >= self::TTL ) {
			return null;
		}
		$this->memory[ $slot ] = $index;
		return $index;
	}

	/**
	 * The index in the store, checked for its shape.
	 *
	 * @param int    $connectionId Connection id.
	 * @param string $name         Index name.
	 * @return array{at:int,complete:bool,page:int,first:string,items:array<string,string>}|null
	 */
	private function fromStore( int $connectionId, string $name ): ?array {
		$value = $this->store->get( $connectionId, $name );
		if ( null === $value || ! isset( $value['at'], $value['items'] ) || ! is_int( $value['at'] ) || ! is_array( $value['items'] ) ) {
			return null;
		}
		$items = array();
		foreach ( $value['items'] as $key => $entry ) {
			if ( is_string( $entry ) ) {
				$items[ (string) $key ] = $entry;
			}
		}
		return array(
			'at'       => $value['at'],
			'complete' => true === ( $value['complete'] ?? false ),
			'page'     => is_int( $value['page'] ?? null ) && $value['page'] > 0 ? $value['page'] : 1,
			'first'    => is_string( $value['first'] ?? null ) ? $value['first'] : '',
			'items'    => $items,
		);
	}

	/**
	 * Keep an index in memory and in the store.
	 *
	 * @param int                                                                          $connectionId Connection id.
	 * @param string                                                                       $name         Index name.
	 * @param array{at:int,complete:bool,page:int,first:string,items:array<string,string>} $index        Index.
	 */
	private function save( int $connectionId, string $name, array $index ): void {
		$this->memory[ $connectionId . '|' . $name ] = $index;
		$this->store->put( $connectionId, $name, $index, self::TTL );
	}

	/**
	 * SYNC-VAL-002 for a list too long to index.
	 *
	 * @param string $path List path.
	 */
	private function tooLong( string $path ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => HoldedConnector::LABEL,
				'field'  => $path,
				'reason' => __( 'The list is longer than the connector reads. Narrow it in Holded or use a bigger plan.', 'estasoft-erp-crm-sync-engine' ),
			),
			null,
			null,
			null,
			'index too long'
		);
	}
}
