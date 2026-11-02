<?php
/**
 * Reads finished deliveries with their carrier and tracking number.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery orders (stock.picking) that are done, go out to customers, and belong to a sale order made by this plugin
 * (client reference WC-...). The carrier and tracking reference exist only when Odoo's Delivery module is installed
 * (the fields come from stock_delivery); without it the rows still say the delivery is done, with empty carrier and number.
 */
final class OdooTrackingReader {

	/**
	 * Most deliveries returned in one call; the caller continues from the last done_at.
	 */
	private const LIMIT = 500;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient  $client  Client.
	 * @param OdooAmounts $amounts Date helper.
	 */
	public function __construct( private readonly OdooClient $client, private readonly OdooAmounts $amounts ) {
	}

	/**
	 * Deliveries finished since a time.
	 *
	 * @param \DateTimeImmutable $since UTC.
	 * @return array<int,array{order_ref:string,status:string,carrier:string,tracking:string,done_at:string,delivery:string}>
	 *
	 * @throws OdooException On failure.
	 */
	public function fetch( \DateTimeImmutable $since ): array {
		$have   = $this->client->fields( 'stock.picking', array( 'carrier_id', 'carrier_tracking_ref', 'sale_id' ) );
		$fields = array( 'name', 'state', 'date_done', 'origin' );
		foreach ( array_keys( array_filter( $have ) ) as $field ) {
			$fields[] = $field;
		}
		$domain   = array(
			array( 'state', '=', 'done' ),
			array( 'picking_type_code', '=', 'outgoing' ),
			array( 'date_done', '>=', $since->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) ),
		);
		$pickings = $this->client->searchRead(
			'stock.picking',
			$domain,
			$fields,
			array(
				'order' => 'date_done asc, id asc',
				'limit' => self::LIMIT,
			)
		);
		if ( array() === $pickings ) {
			return array();
		}

		$references = $this->orderReferences( $pickings, $have['sale_id'] );
		$rows       = array();
		foreach ( $pickings as $picking ) {
			$order = $references['by_id'][ OdooRecord::id( $picking['sale_id'] ?? 0 ) ] ?? ( $references['by_name'][ OdooRecord::text( $picking['origin'] ?? '' ) ] ?? '' );
			if ( ! str_starts_with( $order, 'WC-' ) ) {
				continue;
			}
			$rows[] = array(
				'order_ref' => $order,
				'status'    => 'done',
				'carrier'   => OdooRecord::name( $picking['carrier_id'] ?? false ),
				'tracking'  => OdooRecord::text( $picking['carrier_tracking_ref'] ?? '' ),
				'done_at'   => $this->amounts->fromOdooDatetime( $picking['date_done'] ?? '' ),
				'delivery'  => OdooRecord::text( $picking['name'] ?? '' ),
			);
		}
		return $rows;
	}

	/**
	 * Client references of the sale orders the deliveries belong to.
	 *
	 * @param array<int,array<string,mixed>> $pickings Delivery records.
	 * @param bool                           $bySale   True when the pickings carry sale_id.
	 * @return array{by_id:array<int,string>,by_name:array<string,string>}
	 *
	 * @throws OdooException On failure.
	 */
	private function orderReferences( array $pickings, bool $bySale ): array {
		$ids   = array();
		$names = array();
		foreach ( $pickings as $picking ) {
			$id = OdooRecord::id( $picking['sale_id'] ?? 0 );
			if ( $bySale && $id > 0 ) {
				$ids[] = $id;
			} elseif ( '' !== OdooRecord::text( $picking['origin'] ?? '' ) ) {
				$names[] = OdooRecord::text( $picking['origin'] );
			}
		}
		$byId   = array();
		$byName = array();
		if ( array() !== $ids ) {
			foreach ( $this->client->read( 'sale.order', array_values( array_unique( $ids ) ), array( 'client_order_ref', 'name' ) ) as $order ) {
				$byId[ OdooRecord::id( $order['id'] ?? 0 ) ] = OdooRecord::text( $order['client_order_ref'] ?? '' );
			}
		}
		if ( array() !== $names ) {
			foreach ( $this->client->searchRead( 'sale.order', array( array( 'name', 'in', array_values( array_unique( $names ) ) ) ), array( 'client_order_ref', 'name' ) ) as $order ) {
				$byName[ OdooRecord::text( $order['name'] ?? '' ) ] = OdooRecord::text( $order['client_order_ref'] ?? '' );
			}
		}
		return array(
			'by_id'   => $byId,
			'by_name' => $byName,
		);
	}
}
