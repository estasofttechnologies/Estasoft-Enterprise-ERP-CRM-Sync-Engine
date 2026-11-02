<?php
/**
 * The lines an order or a refund becomes, in order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OrderData;
use Estasoft\SyncEngine\Connectors\Microsoft\OrderExtra;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Lines are created one after the other, so a document that was interrupted holds the first lines of the plan. The last line is a comment
 * `WC-123 synced, 5 lines`: when it is there, every line before it was written. The same text is what `findExisting()` looks for.
 */
final class BcPlan {

	/**
	 * Lines of an order, in the order they are written: products, shipping, fees, discount, then a note.
	 *
	 * @param OrderData                              $order   Order.
	 * @param array<string,array<string,mixed>|null> $items Items by SKU (null for an unknown SKU).
	 * @param BcOptions                              $options Options.
	 * @param string                                 $system  System name.
	 * @return array<int,BcLine>|ConnectorError
	 */
	public static function forOrder( OrderData $order, array $items, BcOptions $options, string $system ): array|ConnectorError {
		$lines = array();
		foreach ( $order->lines as $line ) {
			$row = $items[ $line->sku ] ?? null;
			if ( null !== $row ) {
				$lines[] = new BcLine( 'Item', (string) $row['number'], '' !== $line->name ? $line->name : (string) ( $row['displayName'] ?? '' ), $line->quantity, $line->priceMinor, $line->taxCode );
				continue;
			}
			if ( 'account' !== $options->unknownSku() ) {
				return Failures::unknownSku( $system, $line->sku );
			}
			$lines[] = new BcLine( 'Account', $options->unknownSkuAccount(), trim( $line->sku . ' ' . $line->name ), $line->quantity, $line->priceMinor, $line->taxCode );
		}
		$extras = array();
		if ( null !== $order->shipping ) {
			$extras[] = array( 'shipping', $order->shipping, __( 'Shipping', 'estasoft-erp-crm-sync-engine' ), 1 );
		}
		foreach ( $order->fees as $fee ) {
			$extras[] = array( 'fee', $fee, __( 'Fee', 'estasoft-erp-crm-sync-engine' ), 1 );
		}
		if ( null !== $order->discount ) {
			$extras[] = array( 'discount', $order->discount, __( 'Discount', 'estasoft-erp-crm-sync-engine' ), -1 );
		}
		foreach ( $extras as $extra ) {
			$line = self::extraLine( $extra[0], $extra[1], $extra[2], $extra[3], $options, $system );
			if ( $line instanceof ConnectorError ) {
				return $line;
			}
			$lines[] = $line;
		}
		$note = trim( $order->note . ( '' !== $order->paymentReference ? ( '' !== $order->note ? ' | ' : '' ) . sprintf( /* translators: %s: payment reference. */ __( 'Payment %s', 'estasoft-erp-crm-sync-engine' ), $order->paymentReference ) : '' ) );
		if ( '' !== $note ) {
			$lines[] = BcLine::comment( $note );
		}
		return $lines;
	}

	/**
	 * A priced line for shipping, a fee, a discount or a refund amount.
	 *
	 * @param string     $kind    shipping, fee, discount or refund.
	 * @param OrderExtra $extra  The amount.
	 * @param string     $fallback Text when the amount has none.
	 * @param int        $sign    1, or -1 for a discount.
	 * @param BcOptions  $options Options.
	 * @param string     $system  System name.
	 */
	public static function extraLine( string $kind, OrderExtra $extra, string $fallback, int $sign, BcOptions $options, string $system ): BcLine|ConnectorError {
		$target = $options->lineTarget( $kind );
		if ( null === $target ) {
			return Failures::invalid(
				$system,
				$options->lineOptionName( $kind ),
				sprintf(
					/* translators: %s: name of the connection option. */
					__( 'Business Central needs an item or a G/L account for this amount. Set the option %s.', 'estasoft-erp-crm-sync-engine' ),
					$options->lineOptionName( $kind )
				),
				$kind . ' target missing'
			);
		}
		return new BcLine( $target[0], $target[1], '' !== $extra->name ? $extra->name : $fallback, 1, $sign * $extra->totalMinor, $extra->taxCode );
	}

	/**
	 * The text of the last line of a finished document.
	 *
	 * @param string $reference WC-123.
	 * @param int    $count     Lines written before the marker.
	 */
	public static function marker( string $reference, int $count ): string {
		return sprintf( '%s synced, %d lines', $reference, $count );
	}

	/**
	 * The line count a marker carries, or null when the text is not the marker of that reference.
	 *
	 * @param string $description Line text.
	 * @param string $reference   WC-123.
	 */
	public static function markerCount( string $description, string $reference ): ?int {
		if ( 1 === preg_match( '/^' . preg_quote( $reference, '/' ) . ' synced, (\d+) lines$/', trim( $description ), $found ) ) {
			return (int) $found[1];
		}
		return null;
	}
}
