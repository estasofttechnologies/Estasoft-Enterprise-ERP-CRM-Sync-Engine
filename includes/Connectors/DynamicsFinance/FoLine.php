<?php
/**
 * One line of a sales order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\Text;

/**
 * An item, a quantity and a unit price. The price is in minor units and becomes a decimal only in body().
 */
final class FoLine {

	/**
	 * Longest line text (usual F&O length; the line page states none).
	 */
	public const DESCRIPTION_MAX = 1000;

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param string    $itemNumber  Item number.
	 * @param string    $description Line text.
	 * @param int|float $quantity    Quantity.
	 * @param int       $priceMinor  Unit price in minor units.
	 * @param string    $taxGroup    Item sales tax group, or empty.
	 */
	public function __construct(
		public readonly string $itemNumber,
		public readonly string $description,
		public readonly int|float $quantity,
		public readonly int $priceMinor,
		public readonly string $taxGroup = '',
	) {
	}

	/**
	 * The body of the POST that creates the line.
	 *
	 * @param string $orderNumber   Sales order number.
	 * @param string $currency      Currency of the order.
	 * @param string $shippingDate  Requested shipping date YYYY-MM-DD.
	 * @param string $warehouse     Shipping warehouse, or empty.
	 * @param string $site          Shipping site, or empty.
	 * @return array<string,mixed>
	 */
	public function body( string $orderNumber, string $currency, string $shippingDate, string $warehouse, string $site ): array {
		$body = array(
			'SalesOrderNumber'      => $orderNumber,
			'ItemNumber'            => $this->itemNumber,
			'OrderedSalesQuantity'  => $this->quantity,
			'SalesPrice'            => Amounts::decimal( $this->priceMinor, $currency ),
			'LineDescription'       => Text::cut( $this->description, self::DESCRIPTION_MAX ),
			'RequestedShippingDate' => $shippingDate,
		);
		foreach ( array(
			'SalesTaxItemGroupCode' => $this->taxGroup,
			'ShippingWarehouseId'   => $warehouse,
			'ShippingSiteId'        => $site,
		) as $field => $value ) {
			if ( '' !== $value ) {
				$body[ $field ] = $value;
			}
		}
		return $body;
	}

	/**
	 * Whether a line already on the order is this line (item, quantity and price).
	 *
	 * @param array<string,mixed> $row      Row of SalesOrderLines.
	 * @param string              $currency Currency of the order.
	 */
	public function matches( array $row, string $currency ): bool {
		return 0 === strcasecmp( (string) ( $row['ItemNumber'] ?? '' ), $this->itemNumber )
			&& abs( (float) ( $row['OrderedSalesQuantity'] ?? 0 ) - (float) $this->quantity ) < 0.000001
			&& Amounts::minor( $row['SalesPrice'] ?? 0, $currency ) === $this->priceMinor;
	}
}
