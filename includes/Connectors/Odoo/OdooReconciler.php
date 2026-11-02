<?php
/**
 * Lists the orders Odoo holds for the nightly comparison.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Read-only. For every sale order whose client reference starts with WC- since a time, the list gives the total, the
 * state, the customer's email and the sum of credit notes (draft and posted, not cancelled), all in minor units, so the
 * engine can compare them with WooCommerce without any float comparison.
 */
final class OdooReconciler {

	/**
	 * Orders read in one call.
	 */
	private const PAGE = 500;

	/**
	 * Most pages read in one run (10,000 orders).
	 */
	private const MAX_PAGES = 20;

	/**
	 * Ids read in one call when looking up related records.
	 */
	private const CHUNK = 200;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OdooClient      $client  Client.
	 * @param OdooOrderFinder $finder  Reference builder.
	 * @param OdooAmounts     $amounts Conversion helper.
	 */
	public function __construct( private readonly OdooClient $client, private readonly OdooOrderFinder $finder, private readonly OdooAmounts $amounts ) {
	}

	/**
	 * Orders since a time.
	 *
	 * @param \DateTimeImmutable $from UTC window start.
	 * @return array<string,ExternalRef> Keyed by client reference; extra holds total_minor, refunded_minor, email, state, currency, duplicates.
	 *
	 * @throws OdooException On failure.
	 */
	public function fetch( \DateTimeImmutable $from ): array {
		$orders = $this->orders( $from );
		if ( array() === $orders ) {
			return array();
		}
		$emails   = $this->emails( $orders );
		$refunded = $this->refundedByOrder( $orders );

		$result = array();
		foreach ( $orders as $order ) {
			$reference = OdooRecord::text( $order['client_order_ref'] ?? '' );
			if ( isset( $result[ $reference ] ) ) {
				$extra                = $result[ $reference ]->extra;
				$extra['duplicates']  = (int) ( $extra['duplicates'] ?? 0 ) + 1;
				$result[ $reference ] = new ExternalRef( $result[ $reference ]->id, $result[ $reference ]->ref, $result[ $reference ]->url, $extra );
				continue;
			}
			$base                    = $this->finder->orderRef( $order );
			$extra                   = $base->extra;
			$extra['refunded_minor'] = $refunded[ OdooRecord::id( $order['id'] ?? 0 ) ] ?? 0;
			$extra['email']          = $emails[ OdooRecord::id( $order['partner_id'] ?? 0 ) ] ?? '';
			$extra['duplicates']     = 0;
			unset( $extra['invoice_ids'] );
			$result[ $reference ] = new ExternalRef( $base->id, $base->ref, $base->url, $extra );
		}
		return $result;
	}

	/**
	 * Sale orders made by this plugin since a time, oldest first.
	 *
	 * @param \DateTimeImmutable $from UTC window start.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws OdooException On failure.
	 */
	private function orders( \DateTimeImmutable $from ): array {
		$domain = array_merge(
			array(
				array( 'client_order_ref', '=like', 'WC-%' ),
				array( 'date_order', '>=', $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) ),
			),
			$this->client->companyDomain()
		);
		$orders = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$records = $this->client->searchRead(
				'sale.order',
				$domain,
				OdooOrderFinder::ORDER_FIELDS,
				array(
					'order'  => 'id asc',
					'limit'  => self::PAGE,
					'offset' => $page * self::PAGE,
				)
			);
			$orders  = array_merge( $orders, $records );
			if ( count( $records ) < self::PAGE ) {
				break;
			}
		}
		return $orders;
	}

	/**
	 * Customer emails by partner id.
	 *
	 * @param array<int,array<string,mixed>> $orders Sale orders.
	 * @return array<int,string>
	 *
	 * @throws OdooException On failure.
	 */
	private function emails( array $orders ): array {
		$ids = array();
		foreach ( $orders as $order ) {
			$id = OdooRecord::id( $order['partner_id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		$emails = array();
		foreach ( array_chunk( array_values( $ids ), self::CHUNK ) as $chunk ) {
			foreach ( $this->client->read( 'res.partner', $chunk, array( 'email' ) ) as $partner ) {
				$emails[ OdooRecord::id( $partner['id'] ?? 0 ) ] = OdooRecord::text( $partner['email'] ?? '' );
			}
		}
		return $emails;
	}

	/**
	 * Credit note totals in minor units for each order. A credit note belongs to an order when it reverses one of the
	 * order's invoices, or when its source document is the order's number (how this plugin writes credit notes). Draft and
	 * posted credit notes count; cancelled ones do not.
	 *
	 * @param array<int,array<string,mixed>> $orders Sale orders.
	 * @return array<int,int> Sale order id => credit note total.
	 *
	 * @throws OdooException On failure.
	 */
	private function refundedByOrder( array $orders ): array {
		$orderByInvoice = array();
		$orderByName    = array();
		foreach ( $orders as $order ) {
			$orderId = OdooRecord::id( $order['id'] ?? 0 );
			$orderByName[ OdooRecord::text( $order['name'] ?? '' ) ] = $orderId;
			foreach ( OdooRecord::ids( $order['invoice_ids'] ?? array() ) as $invoiceId ) {
				$orderByInvoice[ $invoiceId ] = $orderId;
			}
		}
		$names    = array_keys( $orderByName );
		$invoices = array_keys( $orderByInvoice );
		$refunded = array();
		$seen     = array();

		$queries = array();
		foreach ( array_chunk( $invoices, self::CHUNK ) as $chunk ) {
			$queries[] = array( 'reversed_entry_id', 'in', $chunk );
		}
		foreach ( array_chunk( $names, self::CHUNK ) as $chunk ) {
			$queries[] = array( 'invoice_origin', 'in', $chunk );
		}
		foreach ( $queries as $query ) {
			$moves = $this->client->searchRead(
				'account.move',
				array( array( 'move_type', '=', 'out_refund' ), array( 'state', 'in', array( 'draft', 'posted' ) ), $query ),
				array( 'amount_total', 'currency_id', 'reversed_entry_id', 'invoice_origin' )
			);
			foreach ( $moves as $move ) {
				$id = OdooRecord::id( $move['id'] ?? 0 );
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$orderId = $orderByInvoice[ OdooRecord::id( $move['reversed_entry_id'] ?? 0 ) ] ?? ( $orderByName[ OdooRecord::text( $move['invoice_origin'] ?? '' ) ] ?? 0 );
				if ( $orderId < 1 ) {
					continue;
				}
				$seen[ $id ]          = true;
				$currency             = strtoupper( OdooRecord::name( $move['currency_id'] ?? false ) );
				$refunded[ $orderId ] = ( $refunded[ $orderId ] ?? 0 ) + $this->amounts->toMinor( $move['amount_total'] ?? 0, $this->amounts->exponent( $currency ) );
			}
		}
		return $refunded;
	}
}
