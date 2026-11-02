<?php
/**
 * The last tracking numbers saved on orders.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Tracking;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Jobs\Support\OptionList;

/**
 * The Stock screen shows "recent tracking". This keeps the last 50 in the option `estasoft_sync_tracking_recent`.
 */
final class TrackingLog {

	/**
	 * Option name.
	 */
	public const OPTION = 'estasoft_sync_tracking_recent';

	/**
	 * Most entries kept.
	 */
	public const CAP = 50;

	/**
	 * Entries.
	 *
	 * @var OptionList
	 */
	private OptionList $list;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OptionStore $options Options.
	 */
	public function __construct( OptionStore $options ) {
		$this->list = new OptionList( $options, self::OPTION, self::CAP );
	}

	/**
	 * Remember a tracking number that was saved.
	 *
	 * @param int    $connectionId Connection.
	 * @param int    $orderId      Order id.
	 * @param string $orderLabel   Order number.
	 * @param string $carrier      Carrier.
	 * @param string $number       Tracking number.
	 * @param string $at           ISO time.
	 */
	public function add( int $connectionId, int $orderId, string $orderLabel, string $carrier, string $number, string $at ): void {
		$this->list->prepend(
			array(
				'connection_id' => $connectionId,
				'order_id'      => $orderId,
				'order_label'   => $orderLabel,
				'carrier'       => $carrier,
				'number'        => $number,
				'at'            => $at,
			)
		);
	}

	/**
	 * The recent entries in the shape of `StockOverview.tracking_recent`.
	 *
	 * @param int|null $connectionId One connection, or null for all.
	 * @return array<int,array{order_id:int,order_label:string,carrier:string,number:string,at:string}>
	 */
	public function recent( ?int $connectionId = null ): array {
		$rows = array();
		foreach ( $this->list->all() as $entry ) {
			if ( null !== $connectionId && (int) ( $entry['connection_id'] ?? 0 ) !== $connectionId ) {
				continue;
			}
			$rows[] = array(
				'order_id'    => (int) $entry['order_id'],
				'order_label' => (string) $entry['order_label'],
				'carrier'     => (string) $entry['carrier'],
				'number'      => (string) $entry['number'],
				'at'          => (string) $entry['at'],
			);
		}
		return $rows;
	}
}
