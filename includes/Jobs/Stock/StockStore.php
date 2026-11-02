<?php
/**
 * What the stock job remembers between runs.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Stock;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Jobs\Support\OptionList;

/**
 * Three small records, all in options that are not autoloaded and are capped:
 *
 * - `estasoft_sync_stock_last`: per connection, when the last full pass finished, the counts, and where a pass that ran out of
 *   time will continue (`next_page` with the counts so far).
 * - `estasoft_sync_stock_changes`: the last 100 changes of shop quantity.
 * - `estasoft_sync_stock_conflicts`: open conflicts (SYNC-STK-001, at most 200) and the person's decisions about them (at most
 *   500), so a product the person accepted or held is not raised again while the system quantity is the same.
 */
final class StockStore {

	/**
	 * Option: last pass per connection.
	 */
	public const LAST = 'estasoft_sync_stock_last';

	/**
	 * Option: recent changes.
	 */
	public const CHANGES = 'estasoft_sync_stock_changes';

	/**
	 * Option: conflicts and decisions.
	 */
	public const CONFLICTS = 'estasoft_sync_stock_conflicts';

	/**
	 * Most changes kept.
	 */
	public const CHANGE_CAP = 100;

	/**
	 * Most open conflicts kept.
	 */
	public const CONFLICT_CAP = 200;

	/**
	 * Most decisions kept.
	 */
	public const DECISION_CAP = 500;

	/**
	 * Recent changes list.
	 *
	 * @var OptionList
	 */
	private OptionList $changes;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OptionStore $options Options.
	 */
	public function __construct( private readonly OptionStore $options ) {
		$this->changes = new OptionList( $options, self::CHANGES, self::CHANGE_CAP );
	}

	/**
	 * The last pass of one connection, or of all.
	 *
	 * @param int|null $connectionId Connection, or null for the map of all.
	 * @return array<string,mixed>
	 */
	public function last( ?int $connectionId = null ): array {
		$all = $this->options->get( self::LAST, array() );
		$all = is_array( $all ) ? $all : array();
		if ( null === $connectionId ) {
			return $all;
		}
		$one = $all[ (string) $connectionId ] ?? array();
		return is_array( $one ) ? $one : array();
	}

	/**
	 * Save the state of one connection's pass.
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<string,mixed> $state        State.
	 */
	public function saveLast( int $connectionId, array $state ): void {
		$all                          = $this->last();
		$all[ (string) $connectionId ] = $state;
		$this->options->set( self::LAST, $all );
	}

	/**
	 * Recent changes, newest first.
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 * @return array<int,array<string,mixed>>
	 */
	public function changes( ?int $connectionId = null ): array {
		$items = $this->changes->all();
		if ( null === $connectionId ) {
			return $items;
		}
		return array_values( array_filter( $items, static fn( array $change ): bool => (int) ( $change['connection_id'] ?? 0 ) === $connectionId ) );
	}

	/**
	 * Remember a change.
	 *
	 * @param array<string,mixed> $change sku, product_name, system_quantity, shop_before, shop_after, at, connection_id.
	 */
	public function addChange( array $change ): void {
		$this->changes->prepend( $change );
	}

	/**
	 * Open conflicts, oldest first.
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 * @return array<int,array<string,mixed>>
	 */
	public function conflicts( ?int $connectionId = null ): array {
		$list = $this->conflictData()['open'];
		if ( null !== $connectionId ) {
			$list = array_filter( $list, static fn( array $conflict ): bool => (int) $conflict['connection_id'] === $connectionId );
		}
		return array_values( $list );
	}

	/**
	 * One open conflict.
	 *
	 * @param int $id Conflict id.
	 * @return array<string,mixed>|null
	 */
	public function conflict( int $id ): ?array {
		foreach ( $this->conflictData()['open'] as $conflict ) {
			if ( (int) $conflict['id'] === $id ) {
				return $conflict;
			}
		}
		return null;
	}

	/**
	 * Open a conflict, or refresh the one already open for the product.
	 *
	 * @param int    $connectionId   Connection.
	 * @param int    $productId      Product or variation id.
	 * @param string $sku            SKU.
	 * @param string $productName    Product name.
	 * @param int    $systemQuantity Quantity in the system.
	 * @param int    $soldUnsynced   Units sold and not yet known to the system.
	 * @param string $detectedAt     ISO time.
	 * @param int    $systemHeld     Units the system holds back (reserved), kept so accepting the quantity later subtracts the same.
	 * @return int Conflict id.
	 */
	public function openConflict( int $connectionId, int $productId, string $sku, string $productName, int $systemQuantity, int $soldUnsynced, string $detectedAt, int $systemHeld = 0 ): int {
		$data = $this->conflictData();
		foreach ( $data['open'] as $index => $conflict ) {
			if ( (int) $conflict['connection_id'] === $connectionId && (int) $conflict['product_id'] === $productId ) {
				$data['open'][ $index ]['system_quantity'] = $systemQuantity;
				$data['open'][ $index ]['sold_unsynced']   = $soldUnsynced;
				$data['open'][ $index ]['system_held']     = $systemHeld;
				$this->options->set( self::CONFLICTS, $data );
				return (int) $conflict['id'];
			}
		}
		$id             = $data['next_id'];
		$data['next_id'] = $id + 1;
		$data['open'][] = array(
			'id'              => $id,
			'connection_id'   => $connectionId,
			'product_id'      => $productId,
			'sku'             => $sku,
			'product_name'    => $productName,
			'system_quantity' => $systemQuantity,
			'sold_unsynced'   => $soldUnsynced,
			'system_held'     => $systemHeld,
			'detected_at'     => $detectedAt,
		);
		$data['open'] = array_slice( $data['open'], -self::CONFLICT_CAP );
		$this->options->set( self::CONFLICTS, $data );
		return $id;
	}

	/**
	 * Close the open conflict of a product, if any.
	 *
	 * @param int $connectionId Connection.
	 * @param int $productId    Product or variation id.
	 */
	public function closeConflictFor( int $connectionId, int $productId ): void {
		$data         = $this->conflictData();
		$data['open'] = array_values(
			array_filter(
				$data['open'],
				static fn( array $conflict ): bool => ! ( (int) $conflict['connection_id'] === $connectionId && (int) $conflict['product_id'] === $productId )
			)
		);
		unset( $data['decisions'][ $connectionId . ':' . $productId ] );
		$this->options->set( self::CONFLICTS, $data );
	}

	/**
	 * Close a conflict because the person decided, and remember the decision for the quantity the system has now.
	 *
	 * @param int    $id       Conflict id.
	 * @param string $decision accept or hold.
	 * @param string $at       ISO time.
	 * @return array<string,mixed>|null The conflict that was closed.
	 */
	public function decide( int $id, string $decision, string $at ): ?array {
		$data  = $this->conflictData();
		$found = null;
		foreach ( $data['open'] as $index => $conflict ) {
			if ( (int) $conflict['id'] === $id ) {
				$found = $conflict;
				unset( $data['open'][ $index ] );
				break;
			}
		}
		if ( null === $found ) {
			return null;
		}
		$data['open']                                                  = array_values( $data['open'] );
		$data['decisions'][ $found['connection_id'] . ':' . $found['product_id'] ] = array(
			'decision' => $decision,
			'quantity' => (int) $found['system_quantity'],
			'at'       => $at,
		);
		$data['decisions'] = array_slice( $data['decisions'], -self::DECISION_CAP, null, true );
		$this->options->set( self::CONFLICTS, $data );
		return $found;
	}

	/**
	 * The decision about a product.
	 *
	 * @param int $connectionId Connection.
	 * @param int $productId    Product or variation id.
	 * @return array{decision:string,quantity:int,at:string}|null
	 */
	public function decision( int $connectionId, int $productId ): ?array {
		$data = $this->conflictData();
		$one  = $data['decisions'][ $connectionId . ':' . $productId ] ?? null;
		return is_array( $one ) ? $one : null;
	}

	/**
	 * The stored conflict record with defaults.
	 *
	 * @return array{next_id:int,open:array<int,array<string,mixed>>,decisions:array<string,array<string,mixed>>}
	 */
	private function conflictData(): array {
		$stored = $this->options->get( self::CONFLICTS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'next_id'   => max( 1, (int) ( $stored['next_id'] ?? 1 ) ),
			'open'      => isset( $stored['open'] ) && is_array( $stored['open'] ) ? array_values( array_filter( $stored['open'], 'is_array' ) ) : array(),
			'decisions' => isset( $stored['decisions'] ) && is_array( $stored['decisions'] ) ? $stored['decisions'] : array(),
		);
	}
}
