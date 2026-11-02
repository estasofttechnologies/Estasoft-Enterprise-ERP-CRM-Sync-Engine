<?php
/**
 * Invoice positions and the document's tax rule for sevDesk.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

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
 * The sevDesk API takes one tax rule per document (sevDesk-Update 2.0: an id) or one tax type (1.0: a word), and the tax rate on each position
 * [documented in the API news]. The tax mapping gives each row such a code; all rows must agree. Prices are net unit prices with `showNet` true.
 * Prices that include tax are converted to net with whole numbers. A line whose total cannot be divided exactly into a two-decimal unit price
 * becomes one position of quantity 1, because the number of decimals sevDesk keeps is not documented.
 */
final class SevdeskLines {

	/**
	 * Tax rule ids of sevDesk-Update 2.0 [third-party mirror of the API reference and the API news].
	 */
	public const RULES = array( '1', '2', '3', '4', '5', '11', '17', '18', '19', '20', '21' );

	/**
	 * Tax types of the older system version 1.0 [documented in the model description].
	 */
	public const TYPES = array( 'default', 'eu', 'noteu', 'custom', 'ss' );

	/**
	 * Set up the builder.
	 *
	 * @param Amounts $amounts Conversion helper.
	 */
	public function __construct( private readonly Amounts $amounts ) {
	}

	/**
	 * Positions, tax rule (or type) and tax text for an order.
	 *
	 * @param ApiClient    $api     Client (part lookups).
	 * @param OrderPayload $order   Order.
	 * @param Options      $options Settings.
	 * @param string       $version Bookkeeping system version, 2.0 or 1.0.
	 * @return array{code:string,tax_text:string,positions:array<int,array<string,mixed>>,warnings:array<int,string>}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function build( ApiClient $api, OrderPayload $order, Options $options, string $version ): array|ConnectorError {
		$rows = $this->rows( $order );
		$code = $this->code( $rows, $version );
		if ( $code instanceof ConnectorError ) {
			return $code;
		}
		$positions = array();
		$warnings  = array();
		$parts     = array();
		foreach ( $rows as $index => $row ) {
			if ( null === $row['rate_bp'] && in_array( $code, array( '1', 'default' ), true ) ) {
				return Refusal::value( SevdeskConnector::LABEL, 'lines[].tax_rate', __( 'The tax rate of a line is missing. Map the tax rate of the order lines.', 'estasoft-erp-crm-sync-engine' ), 'tax rate missing' );
			}
			$bp   = (int) ( $row['rate_bp'] ?? 0 );
			$part = null;
			if ( 'line' === $row['kind'] && '' !== $row['sku'] ) {
				if ( ! array_key_exists( $row['sku'], $parts ) ) {
					$parts[ $row['sku'] ] = $this->part( $api, $row['sku'] );
				}
				$part = $parts[ $row['sku'] ];
				if ( null === $part && ! $options->is( 'unknown_sku', 'custom' ) ) {
					return Refusal::product( SevdeskConnector::LABEL, $row['sku'] );
				}
			}
			$net = $order->pricesIncludeTax ? $this->amounts->netFromGross( (int) $row['total'], $bp ) : (int) $row['total'];
			$one = $this->position( $row, $net, $bp, $part, $options, $index + 1 );
			if ( $one['split'] ) {
				$warnings[] = 'line_split:' . ( $index + 1 );
			}
			$positions[] = $one['body'];
		}
		$first = $rows[0]['rate_bp'] ?? 0;
		return array(
			'code'      => $code,
			'tax_text'  => $this->taxText( $code, (int) $first ),
			'positions' => $positions,
			'warnings'  => $warnings,
		);
	}

	/**
	 * Text printed for a tax rule. Suggestions only: the merchant's accountant confirms them [inferred].
	 *
	 * @param string $code Tax rule id or type.
	 * @param int    $bp   Rate of the first row in basis points.
	 */
	public function taxText( string $code, int $bp ): string {
		return match ( $code ) {
			'1', 'default'   => 'Umsatzsteuer ' . $this->amounts->percent( $bp ) . '%',
			'2'              => 'Steuerfreie Ausfuhrlieferung',
			'3', 'eu'        => 'Steuerfreie innergemeinschaftliche Lieferung',
			'4'              => 'Steuerfrei nach § 4 UStG',
			'5'              => 'Steuerschuldnerschaft des Leistungsempfängers (§ 13b UStG)',
			'11', 'ss'       => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet',
			'17', 'noteu'    => 'Nicht im Inland steuerbare Leistung',
			'18', '19', '20' => 'Besteuerung nach dem One-Stop-Shop-Verfahren',
			'21'             => 'Steuerschuldnerschaft des Leistungsempfängers (§ 18b UStG)',
			default          => '',
		};
	}

	/**
	 * The part with a part number (exact), or null.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $sku Part number.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function part( ApiClient $api, string $sku ): ?array {
		foreach ( SevdeskJson::rows( $api->get( 'Part', array( 'partNumber' => $sku ) )->json() ) as $part ) {
			if ( 0 === strcasecmp( PayloadReader::text( $part['partNumber'] ?? '' ), $sku ) ) {
				return $part;
			}
		}
		return null;
	}

	/**
	 * Rows with their codes and rates. Shipping without a code or rate takes the first product's; a fee takes the shipping's, else the first product's.
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

	/**
	 * The one tax rule or type of the document.
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param string                         $version Bookkeeping system version.
	 * @return string|ConnectorError
	 */
	private function code( array $rows, string $version ): string|ConnectorError {
		$allowed = '1.0' === $version ? self::TYPES : self::RULES;
		$codes   = array();
		foreach ( $rows as $index => $row ) {
			$code = trim( (string) $row['code'] );
			if ( '' === $code || ! in_array( $code, $allowed, true ) ) {
				return Refusal::tax( SevdeskConnector::LABEL, 'tax', '' === $code ? '#' . ( $index + 1 ) : $code );
			}
			$codes[ $code ] = true;
		}
		if ( count( $codes ) > 1 ) {
			return Refusal::value(
				SevdeskConnector::LABEL,
				'taxRule',
				sprintf(
					/* translators: %s: tax codes, separated by commas. */
					__( 'sevDesk takes one tax rule per invoice, but the tax mapping gives the lines different ones (%s). Use the same tax code for every line.', 'estasoft-erp-crm-sync-engine' ),
					implode( ', ', array_keys( $codes ) )
				),
				'mixed tax rules'
			);
		}
		return (string) array_key_first( $codes );
	}

	/**
	 * One position.
	 *
	 * @param array<string,mixed>      $row     Row.
	 * @param int                      $net     Net line total in minor units.
	 * @param int                      $bp      Tax rate in basis points.
	 * @param array<string,mixed>|null $part    Part, when the SKU matched.
	 * @param Options                  $options Settings.
	 * @param int                      $number  Position number.
	 * @return array{body:array<string,mixed>,split:bool}
	 */
	private function position( array $row, int $net, int $bp, ?array $part, Options $options, int $number ): array {
		$quantity = (float) $row['quantity'];
		$name     = '' !== $row['name'] ? (string) $row['name'] : (string) ( $part['name'] ?? $row['sku'] );
		$unit     = $this->amounts->unitPrice( $net, 2, $quantity, 0 );
		$split    = false;
		if ( null === $unit ) {
			$split    = true;
			$name     = $this->amounts->quantityText( $quantity ) . ' × ' . $name;
			$quantity = 1.0;
			$unit     = $this->amounts->decimal( $net, 2 );
		}
		$body = array(
			'objectName'     => 'InvoicePos',
			'mapAll'         => true,
			'quantity'       => $this->amounts->number( $this->amounts->quantityText( $quantity ) ),
			'unity'          => SevdeskJson::ref( $options->int( 'unity_id' ), 'Unity' ),
			'taxRate'        => $this->amounts->number( $this->amounts->percent( $bp ) ),
			'price'          => $this->amounts->number( $unit ),
			'name'           => $name,
			'text'           => '',
			'positionNumber' => $number - 1,
		);
		if ( null !== $part && '' !== (string) ( $part['id'] ?? '' ) ) {
			$body['part'] = SevdeskJson::ref( (string) $part['id'], 'Part' );
		}
		return array(
			'body'  => $body,
			'split' => $split,
		);
	}
}
