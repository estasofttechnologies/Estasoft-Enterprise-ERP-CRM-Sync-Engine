<?php
/**
 * The lines an order becomes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Microsoft\OrderData;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Product lines first, then shipping and the fees as write-in lines. The order discount is not a line: it goes to the opportunity's
 * `discountamount`. Lines are written in this order, numbered from 1 in `lineitemnumber`, so an interrupted opportunity holds the first lines.
 */
final class DvPlan {

	/**
	 * Lines of an order.
	 *
	 * @param OrderData                              $order    Order.
	 * @param array<string,array<string,mixed>|null> $products Products by SKU (null for an unknown SKU).
	 * @param DvOptions                              $options  Options.
	 * @param string                                 $system   System name.
	 * @return array<int,DvLine>|ConnectorError
	 */
	public static function forOrder( OrderData $order, array $products, DvOptions $options, string $system ): array|ConnectorError {
		$lines = array();
		foreach ( $order->lines as $line ) {
			$row = $products[ $line->sku ] ?? null;
			if ( null === $row ) {
				if ( 'write_in' !== $options->unknownSku() ) {
					return Failures::unknownSku( $system, $line->sku );
				}
				$lines[] = new DvLine( trim( $line->sku . ' ' . $line->name ), $line->quantity, $line->priceMinor, $line->taxMinor );
				continue;
			}
			$lines[] = new DvLine(
				'' !== $line->name ? $line->name : (string) ( $row['name'] ?? $line->sku ),
				$line->quantity,
				$line->priceMinor,
				$line->taxMinor,
				OData::guid( $row['productid'] ?? null ),
				OData::guid( $row['_defaultuomid_value'] ?? null )
			);
		}
		if ( null !== $order->shipping ) {
			$lines[] = new DvLine( '' !== $order->shipping->name ? $order->shipping->name : __( 'Shipping', 'estasoft-erp-crm-sync-engine' ), 1, $order->shipping->totalMinor, $order->shipping->taxMinor );
		}
		foreach ( $order->fees as $fee ) {
			$lines[] = new DvLine( '' !== $fee->name ? $fee->name : __( 'Fee', 'estasoft-erp-crm-sync-engine' ), 1, $fee->totalMinor, $fee->taxMinor );
		}
		return $lines;
	}
}
