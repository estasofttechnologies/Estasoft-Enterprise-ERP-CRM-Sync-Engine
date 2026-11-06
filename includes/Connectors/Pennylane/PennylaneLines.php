<?php
/**
 * Invoice lines for Pennylane.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pennylane;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Every line has a label, a quantity, a unit, a net unit price as decimal TEXT (a JSON number is a 400) and a VAT code such as `FR_200` [documented
 * example of the create-invoice guide]. The tax mapping gives the VAT code of each row. The SKU is checked against the product list (`reference` filter,
 * [documented]); the product's id is not sent on the line because the guide says it replaces the manual line details and the shop's price must win.
 * A line whose total cannot be divided exactly into a two-decimal unit price becomes one line of quantity 1.
 */
final class PennylaneLines {

	/**
	 * Set up the builder.
	 *
	 * @param Amounts      $amounts    Conversion helper.
	 * @param PennylaneApi $pennylane  Request helpers.
	 */
	public function __construct( private readonly Amounts $amounts, private readonly PennylaneApi $pennylane ) {
	}

	/**
	 * Invoice lines for an order.
	 *
	 * @param ApiClient    $api     Client (product lookups).
	 * @param OrderPayload $order   Order.
	 * @param Options      $options Settings.
	 * @return array{lines:array<int,array<string,mixed>>,warnings:array<int,string>}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function build( ApiClient $api, OrderPayload $order, Options $options ): array|ConnectorError {
		$rows     = $this->rows( $order );
		$lines    = array();
		$warnings = array();
		$known    = array();
		foreach ( $rows as $index => $row ) {
			$code = trim( (string) $row['code'] );
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]{1,40}$/', $code ) ) {
				return Refusal::tax( PennylaneConnector::LABEL, 'VAT', '' === $code ? '#' . ( $index + 1 ) : $code );
			}
			if ( 'line' === $row['kind'] && '' !== $row['sku'] ) {
				if ( ! array_key_exists( $row['sku'], $known ) ) {
					$known[ $row['sku'] ] = $this->productExists( $api, $row['sku'] );
				}
				if ( ! $known[ $row['sku'] ] && ! $options->is( 'unknown_sku', 'custom' ) ) {
					return Refusal::product( PennylaneConnector::LABEL, $row['sku'] );
				}
			}
			$net = (int) $row['total'];
			if ( $order->pricesIncludeTax ) {
				$bp = $row['rate_bp'] ?? $this->rateOfCode( $code );
				if ( null === $bp ) {
					return Refusal::value( PennylaneConnector::LABEL, 'lines[].tax_rate', __( 'The tax rate of a line is needed to take the tax out of a price that includes it. Map the tax rate of the order lines.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'tax rate missing' );
				}
				$net = $this->amounts->netFromGross( $net, (int) $bp );
			}
			$quantity = (float) $row['quantity'];
			$label    = (string) $row['name'];
			$unit     = $this->amounts->unitPrice( $net, 2, $quantity, 0 );
			if ( null === $unit ) {
				$warnings[] = 'line_split:' . ( $index + 1 );
				$label      = $this->amounts->quantityText( $quantity ) . ' × ' . $label;
				$quantity   = 1.0;
				$unit       = $this->amounts->decimal( $net, 2 );
			}
			$line   = array(
				'label'                   => $label,
				'quantity'                => $this->amounts->number( $this->amounts->quantityText( $quantity ) ),
				'unit'                    => 'line' === $row['kind'] ? 'piece' : 'service',
				'raw_currency_unit_price' => $unit,
				'vat_rate'                => $code,
			);
			$ledger = $options->text( 'shipping' === $row['kind'] ? 'shipping_ledger_account' : 'sales_ledger_account' );
			if ( '' !== $ledger ) {
				$line['ledger_account_id'] = (int) $ledger;
			}
			$lines[] = $line;
		}
		return array(
			'lines'    => $lines,
			'warnings' => $warnings,
		);
	}

	/**
	 * Whether the product list has a product with this reference.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $sku Reference (the SKU).
	 *
	 * @throws ApiException On failure.
	 */
	public function productExists( ApiClient $api, string $sku ): bool {
		return null !== $this->product( $api, $sku );
	}

	/**
	 * The product with a reference, or null.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $sku Reference (the SKU).
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function product( ApiClient $api, string $sku ): ?array {
		$page = $this->pennylane->page( $api, 'products', array( array( 'reference', 'eq', $sku ) ), null );
		return $page['items'][0] ?? null;
	}

	/**
	 * The rate a VAT code names, in basis points, when it can be read from the code (`FR_200` is 20 percent, `exempt` is 0), else null.
	 *
	 * @param string $code VAT code.
	 */
	public function rateOfCode( string $code ): ?int {
		if ( 'exempt' === strtolower( $code ) ) {
			return 0;
		}
		if ( 1 === preg_match( '/^[A-Z]{2}_(\d{1,3})$/', $code, $parts ) ) {
			return (int) $parts[1] * 10;
		}
		return null;
	}

	/**
	 * Rows with their codes and rates. Shipping without a code takes the first product's; a fee takes the shipping's, else the first product's.
	 *
	 * @param OrderPayload $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( OrderPayload $order ): array {
		$rows      = array();
		$firstCode = '';
		$firstRate = null;
		foreach ( $order->lines as $line ) {
			$rows[] = array(
				'kind'     => 'line',
				'sku'      => (string) $line['sku'],
				'name'     => (string) $line['name'],
				'quantity' => (float) $line['quantity'],
				'total'    => (int) $line['total_minor'],
				'code'     => (string) $line['tax_code'],
				'rate_bp'  => $line['rate_bp'],
			);
			if ( '' === $firstCode ) {
				$firstCode = (string) $line['tax_code'];
				$firstRate = $line['rate_bp'];
			}
		}
		$shippingCode = '';
		$shippingRate = null;
		foreach ( $order->extras as $extra ) {
			$code = (string) $extra['tax_code'];
			$rate = $extra['rate_bp'];
			if ( 'shipping' === $extra['kind'] ) {
				$code         = '' === $code ? $firstCode : $code;
				$rate         = $rate ?? $firstRate;
				$shippingCode = $code;
				$shippingRate = $rate;
			} else {
				$code = '' === $code ? ( '' !== $shippingCode ? $shippingCode : $firstCode ) : $code;
				$rate = $rate ?? $shippingRate ?? $firstRate;
			}
			$rows[] = array(
				'kind'     => (string) $extra['kind'],
				'sku'      => '',
				'name'     => (string) $extra['name'],
				'quantity' => 1.0,
				'total'    => (int) $extra['total_minor'],
				'code'     => $code,
				'rate_bp'  => $rate,
			);
		}
		return $rows;
	}
}
