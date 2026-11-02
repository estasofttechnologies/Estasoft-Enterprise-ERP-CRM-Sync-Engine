<?php
/**
 * The orders waiting for the next export file of one connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * A connector's appendOrder() adds one row per order (its own array shape: lines, totals, dates); finish() turns the rows into file contents.
 * An order the connector cannot export (a ledger code is missing) is passed to leaveOut() with the reason: the file is made without it
 * and the Exports screen lists it (SYNC-EXP-002).
 *
 * A batch opened by the store reads its rows from disk only when asked. `iterate()` streams the rows one at a time, so a batch of ten thousand orders
 * never sits in memory; `rows()` and `included()` load them all (fine for small batches). The batch passed to appendOrder() is empty: it holds only
 * the order being added. `connection()` is the connection the file is made for (its config and settings), set before finish() runs.
 */
final class ExportBatch {

	/**
	 * Rows by queue item id (the rows added or loaded so far).
	 *
	 * @var array<int,array{item_id:int,order:string,row:array<string,mixed>}>
	 */
	private array $rows = array();

	/**
	 * Orders left out of the file.
	 *
	 * @var array<int,array{item_id:int,order:string,reason:string}>
	 */
	private array $leftOut = array();

	/**
	 * Item ids on disk, as a set, when the batch was opened by the store; null for a batch built in memory.
	 *
	 * @var array<int,bool>|null
	 */
	private ?array $idSet = null;

	/**
	 * Reads every row from disk, one at a time.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $source = null;

	/**
	 * Whether every row of the source is in $rows.
	 *
	 * @var bool
	 */
	private bool $loaded = true;

	/**
	 * The connection the file is made for.
	 *
	 * @var Connection|null
	 */
	private ?Connection $connection = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param int                 $connectionId Connection the batch belongs to.
	 * @param string              $openedAt     When the first order was added (MySQL datetime, UTC); empty for a new batch.
	 * @param array<string,mixed> $meta         Facts the connector keeps for the file (company, currency, header values).
	 */
	public function __construct( public readonly int $connectionId, public readonly string $openedAt = '', public array $meta = array() ) {
	}

	/**
	 * A batch backed by files: the ids are known, the rows are read when asked.
	 *
	 * @param int                                                      $connectionId Connection.
	 * @param string                                                   $openedAt     When the first order was added.
	 * @param array<int,int>                                           $itemIds      Queue item ids on disk.
	 * @param \Closure                                                 $source       Returns a Generator of `array{item_id,order,row,left_out}` for every item on disk, in item id order.
	 * @param array<int,array{item_id:int,order:string,reason:string}> $leftOut Orders already known to be left out.
	 */
	public static function backed( int $connectionId, string $openedAt, array $itemIds, \Closure $source, array $leftOut = array() ): self {
		$batch         = new self( $connectionId, $openedAt );
		$batch->idSet  = array_fill_keys( $itemIds, true );
		$batch->source = $source;
		$batch->loaded = false;
		foreach ( $leftOut as $entry ) {
			$batch->leftOut[ $entry['item_id'] ] = $entry;
		}
		return $batch;
	}

	/**
	 * Add the row of an order. The same queue item added twice keeps its latest row.
	 *
	 * @param int                 $itemId  Queue item id.
	 * @param string              $orderId WooCommerce order id.
	 * @param array<string,mixed> $row     The connector's own row.
	 */
	public function add( int $itemId, string $orderId, array $row ): void {
		$this->rows[ $itemId ] = array(
			'item_id' => $itemId,
			'order'   => $orderId,
			'row'     => $row,
		);
		if ( null !== $this->idSet ) {
			$this->idSet[ $itemId ] = true;
		}
	}

	/**
	 * The connection the file is made for, or null when it is not known (a batch built in a test).
	 */
	public function connection(): ?Connection {
		return $this->connection;
	}

	/**
	 * Tell the batch which connection it is made for.
	 *
	 * @param Connection|null $connection Connection.
	 */
	public function forConnection( ?Connection $connection ): void {
		$this->connection = $connection;
	}

	/**
	 * Every row with its bookkeeping, oldest first. Loads all rows into memory.
	 *
	 * @return array<int,array{item_id:int,order:string,row:array<string,mixed>}>
	 */
	public function rows(): array {
		$this->load();
		ksort( $this->rows );
		return array_values( $this->rows );
	}

	/**
	 * Only the connector rows, without the bookkeeping. Loads all rows into memory.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function orderRows(): array {
		return array_values( array_map( static fn( array $entry ): array => $entry['row'], $this->included() ) );
	}

	/**
	 * Stream the rows that go into the file, one at a time, oldest first, without loading the batch.
	 *
	 * @return \Generator<int,array{item_id:int,order:string,row:array<string,mixed>}>
	 */
	public function iterate(): \Generator {
		if ( $this->loaded || null === $this->source ) {
			foreach ( $this->included() as $entry ) {
				yield $entry;
			}
			return;
		}
		foreach ( ( $this->source )() as $entry ) {
			$itemId = (int) $entry['item_id'];
			if ( isset( $this->leftOut[ $itemId ] ) ) {
				continue;
			}
			yield array(
				'item_id' => $itemId,
				'order'   => (string) $entry['order'],
				'row'     => (array) $entry['row'],
			);
		}
	}

	/**
	 * Number of orders that will be in the file.
	 */
	public function count(): int {
		return count( $this->includedIds() );
	}

	/**
	 * Queue item ids of the orders that will be in the file, without reading the rows.
	 *
	 * @return array<int,int>
	 */
	public function includedIds(): array {
		$ids = array_keys( null === $this->idSet ? $this->rows : $this->idSet + array_fill_keys( array_keys( $this->rows ), true ) );
		$ids = array_values( array_diff( $ids, array_keys( $this->leftOut ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Whether a queue item is already in the batch.
	 *
	 * @param int $itemId Queue item id.
	 */
	public function has( int $itemId ): bool {
		return isset( $this->rows[ $itemId ] ) || isset( $this->idSet[ $itemId ] );
	}

	/**
	 * One row with its bookkeeping, or null (only rows added or loaded so far).
	 *
	 * @param int $itemId Queue item id.
	 * @return array{item_id:int,order:string,row:array<string,mixed>}|null
	 */
	public function entry( int $itemId ): ?array {
		return $this->rows[ $itemId ] ?? null;
	}

	/**
	 * Why an item was left out, or null.
	 *
	 * @param int $itemId Queue item id.
	 */
	public function reasonFor( int $itemId ): ?string {
		return $this->leftOut[ $itemId ]['reason'] ?? null;
	}

	/**
	 * Take an order out of the file and say why.
	 *
	 * @param int    $itemId  Queue item id.
	 * @param string $orderId WooCommerce order id.
	 * @param string $reason  Short reason shown to the person ("no ledger code for tax 5%").
	 */
	public function leaveOut( int $itemId, string $orderId, string $reason ): void {
		$this->leftOut[ $itemId ] = array(
			'item_id' => $itemId,
			'order'   => $orderId,
			'reason'  => $reason,
		);
	}

	/**
	 * Orders left out, with reasons.
	 *
	 * @return array<int,array{item_id:int,order:string,reason:string}>
	 */
	public function leftOut(): array {
		return array_values( $this->leftOut );
	}

	/**
	 * Queue item ids left out.
	 *
	 * @return array<int,int>
	 */
	public function leftOutItemIds(): array {
		return array_keys( $this->leftOut );
	}

	/**
	 * The rows that go into the file (the rows minus the ones left out). Loads all rows into memory.
	 *
	 * @return array<int,array{item_id:int,order:string,row:array<string,mixed>}>
	 */
	public function included(): array {
		$this->load();
		$rows = array_diff_key( $this->rows, $this->leftOut );
		ksort( $rows );
		return array_values( $rows );
	}

	/**
	 * Read every row of the source into memory, once.
	 */
	private function load(): void {
		if ( $this->loaded || null === $this->source ) {
			return;
		}
		foreach ( ( $this->source )() as $entry ) {
			$itemId = (int) $entry['item_id'];
			if ( ! isset( $this->rows[ $itemId ] ) ) {
				$this->rows[ $itemId ] = array(
					'item_id' => $itemId,
					'order'   => (string) $entry['order'],
					'row'     => (array) $entry['row'],
				);
			}
		}
		$this->loaded = true;
	}
}
