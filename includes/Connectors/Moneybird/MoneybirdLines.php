<?php
/**
 * Invoice lines for Moneybird.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Moneybird;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Each line carries its own tax rate id [documented fields of the sales invoice: `details[].tax_rate_id`, `ledger_account_id`, `product_id`]. The
 * tax mapping code is a Moneybird sales tax rate id; it is checked against the active sales tax rates of the administration before anything is
 * sent. Prices are unit prices as decimal text, tax-exclusive or tax-inclusive as the document flag `prices_are_incl_tax` says. A line whose total
 * cannot be divided exactly into a unit price of the currency's decimals becomes one line of quantity 1, because Moneybird's own rounding of a
 * longer price is not documented.
 */
final class MoneybirdLines {

	/**
	 * Set up the builder.
	 *
	 * @param Amounts $amounts Conversion helper.
	 */
	public function __construct( private readonly Amounts $amounts ) {
	}

	/**
	 * Detail lines for an order.
	 *
	 * @param ApiClient    $api     Client.
	 * @param OrderPayload $order   Order.
	 * @param Options      $options Settings.
	 * @return array{details:array<int,array<string,mixed>>,warnings:array<int,string>}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function build( ApiClient $api, OrderPayload $order, Options $options ): array|ConnectorError {
		$rows  = $this->rows( $order );
		$rates = $this->taxRates( $api );
		foreach ( $rows as $index => $row ) {
			$code = trim( (string) $row['code'] );
			if ( '' === $code || ! isset( $rates[ $code ] ) ) {
				return Refusal::tax( MoneybirdConnector::LABEL, 'tax', '' === $code ? '#' . ( $index + 1 ) : $code );
			}
		}
		$exponent = $this->amounts->exponent( $order->currency );
		$details  = array();
		$warnings = array();
		$products = array();
		foreach ( $rows as $index => $row ) {
			$productId = '';
			if ( 'line' === $row['kind'] && '' !== $row['sku'] ) {
				if ( ! array_key_exists( $row['sku'], $products ) ) {
					$products[ $row['sku'] ] = $this->product( $api, $row['sku'] );
				}
				if ( null === $products[ $row['sku'] ] && ! $options->is( 'unknown_sku', 'custom' ) ) {
					return Refusal::product( MoneybirdConnector::LABEL, $row['sku'] );
				}
				$productId = null === $products[ $row['sku'] ] ? '' : PayloadReader::text( $products[ $row['sku'] ]['id'] ?? '' );
			}
			$quantity = (float) $row['quantity'];
			$name     = (string) $row['name'];
			$unit     = $this->amounts->unitPrice( (int) $row['total'], $exponent, $quantity, 0 );
			if ( null === $unit ) {
				$warnings[] = 'line_split:' . ( $index + 1 );
				$name       = $this->amounts->quantityText( $quantity ) . ' × ' . $name;
				$quantity   = 1.0;
				$unit       = $this->amounts->decimal( (int) $row['total'], $exponent );
			}
			$detail = array(
				'description' => $name,
				'price'       => $unit,
				'amount'      => $this->amounts->quantityText( $quantity ),
				'tax_rate_id' => (int) $row['code'],
			);
			$ledger = $options->text( 'shipping' === $row['kind'] ? 'shipping_ledger_account' : 'sales_ledger_account' );
			if ( '' !== $ledger ) {
				$detail['ledger_account_id'] = (int) $ledger;
			}
			if ( '' !== $productId ) {
				$detail['product_id'] = (int) $productId;
			}
			$details[] = $detail;
		}
		return array(
			'details'  => $details,
			'warnings' => $warnings,
		);
	}

	/**
	 * The active sales tax rates of the administration: id => percentage in basis points.
	 *
	 * @param ApiClient $api Client.
	 * @return array<string,int>
	 *
	 * @throws ApiException On failure.
	 */
	public function taxRates( ApiClient $api ): array {
		$rates = array();
		for ( $page = 1; $page <= 10; $page++ ) {
			$rows = $api->get(
				'tax_rates.json',
				array(
					'filter'   => 'tax_rate_type:sales_invoice,active:true',
					'page'     => $page,
					'per_page' => 100,
				)
			)->json();
			$rows = array_is_list( $rows ) ? $rows : array();
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && '' !== PayloadReader::text( $row['id'] ?? '' ) ) {
					$rates[ PayloadReader::text( $row['id'] ) ] = (int) $this->amounts->basisPoints( $row['percentage'] ?? 0 );
				}
			}
			if ( count( $rows ) < 100 ) {
				break;
			}
		}
		return $rates;
	}

	/**
	 * The product with an identifier, or null.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $sku Identifier (the SKU).
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function product( ApiClient $api, string $sku ): ?array {
		$response = $api->get( 'products/identifier/' . rawurlencode( $sku ) . '.json', array(), null, array( 'allow' => array( 404 ) ) );
		return $response->isSuccess() && '' !== PayloadReader::text( $response->json()['id'] ?? '' ) ? $response->json() : null;
	}

	/**
	 * Rows with their tax codes. Shipping without a code takes the first product's; a fee takes the shipping's, else the first product's.
	 *
	 * @param OrderPayload $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( OrderPayload $order ): array {
		$rows     = array();
		$first    = '';
		$shipping = '';
		foreach ( $order->lines as $line ) {
			$rows[] = array(
				'kind'     => 'line',
				'sku'      => (string) $line['sku'],
				'name'     => (string) $line['name'],
				'quantity' => (float) $line['quantity'],
				'total'    => (int) $line['total_minor'],
				'code'     => (string) $line['tax_code'],
			);
			if ( '' === $first ) {
				$first = (string) $line['tax_code'];
			}
		}
		foreach ( $order->extras as $extra ) {
			$code = (string) $extra['tax_code'];
			if ( '' === $code ) {
				$code = 'shipping' === $extra['kind'] ? $first : ( '' !== $shipping ? $shipping : $first );
			}
			if ( 'shipping' === $extra['kind'] ) {
				$shipping = $code;
			}
			$rows[] = array(
				'kind'     => (string) $extra['kind'],
				'sku'      => '',
				'name'     => (string) $extra['name'],
				'quantity' => 1.0,
				'total'    => (int) $extra['total_minor'],
				'code'     => $code,
			);
		}
		return $rows;
	}
}
