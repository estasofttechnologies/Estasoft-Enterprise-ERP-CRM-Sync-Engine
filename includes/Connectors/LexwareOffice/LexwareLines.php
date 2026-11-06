<?php
/**
 * Invoice lines and the document's tax treatment for Lexware Office.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\LexwareOffice;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Options;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Refusal;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * Lexware takes one tax treatment per document (`taxConditions.taxType`: net, gross or a special type) and a tax rate per line [documented].
 * The tax mapping gives each line either a percentage ("19", "7", "0") or one of the special types. All lines must agree: all percentages, or
 * all the same special type. Prices are sent as net or gross unit prices with up to four decimals [documented]. A line whose total cannot
 * be divided exactly by its quantity becomes one line of quantity 1 so the invoice total equals the shop's.
 */
final class LexwareLines {

	/**
	 * Special tax types [documented enum of `taxConditions.taxType`].
	 */
	public const SPECIAL_TYPES = array(
		'vatfree',
		'intraCommunitySupply',
		'constructionService13b',
		'externalService13b',
		'thirdPartyCountryService',
		'thirdPartyCountryDelivery',
		'photovoltaicEquipment',
	);

	/**
	 * Most line items in one document [documented].
	 */
	public const MAX_LINES = 300;

	/**
	 * Set up the object.
	 *
	 * @param Amounts $amounts Conversion helper.
	 */
	public function __construct( private readonly Amounts $amounts ) {
	}

	/**
	 * The tax type and line items of an order.
	 *
	 * @param ApiClient    $api     Client (article lookups).
	 * @param OrderPayload $order   Order.
	 * @param Options      $options Settings.
	 * @return array{tax_type:string,items:array<int,array<string,mixed>>,warnings:array<int,string>}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	public function build( ApiClient $api, OrderPayload $order, Options $options ): array|ConnectorError {
		$rows = $this->rows( $order );
		if ( count( $rows ) > self::MAX_LINES ) {
			return Refusal::value( LexwareOfficeConnector::LABEL, 'lines', __( 'Lexware Office takes at most 300 lines in one invoice.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'too many lines' );
		}
		$tax = $this->treatment( $rows, $order->pricesIncludeTax );
		if ( $tax instanceof ConnectorError ) {
			return $tax;
		}
		$items    = array();
		$warnings = array();
		$cache    = array();
		foreach ( $rows as $index => $row ) {
			$article = null;
			if ( 'line' === $row['kind'] && '' !== $row['sku'] ) {
				if ( ! array_key_exists( $row['sku'], $cache ) ) {
					$cache[ $row['sku'] ] = $this->article( $api, $row['sku'] );
				}
				$article = $cache[ $row['sku'] ];
				if ( null === $article && ! $options->is( 'unknown_sku', 'custom' ) ) {
					return Refusal::product( LexwareOfficeConnector::LABEL, $row['sku'] );
				}
			}
			$item = $this->item( $row, $tax['type'], $tax['special'], $tax['rates'][ $index ], $article, $options );
			if ( $item['split'] ) {
				$warnings[] = 'line_split:' . ( $index + 1 );
			}
			$items[] = $item['body'];
		}
		return array(
			'tax_type' => $tax['type'],
			'items'    => $items,
			'warnings' => $warnings,
		);
	}

	/**
	 * Product, shipping and fee rows with the tax code each one carries. Shipping without a code takes the first product's, a fee without a
	 * code takes the shipping's, else the first product's.
	 *
	 * @param OrderPayload $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( OrderPayload $order ): array {
		$rows  = array();
		$first = '';
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
		$shipping = '';
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

	/**
	 * The document's tax type and each row's rate in basis points.
	 *
	 * @param array<int,array<string,mixed>> $rows          Rows from rows().
	 * @param bool                           $pricesInclude True when the amounts include tax.
	 * @return array{type:string,special:bool,rates:array<int,int>}|ConnectorError
	 */
	private function treatment( array $rows, bool $pricesInclude ): array|ConnectorError {
		$special = array();
		$rates   = array();
		foreach ( $rows as $index => $row ) {
			$code = trim( (string) $row['code'] );
			if ( '' === $code ) {
				return Refusal::tax( LexwareOfficeConnector::LABEL, 'tax', '#' . ( $index + 1 ) );
			}
			$token = $this->specialType( $code );
			if ( null !== $token ) {
				$special[ $token ] = true;
				$rates[ $index ]   = 0;
				continue;
			}
			$bp = $this->amounts->basisPoints( $code );
			if ( null === $bp || $bp < 0 || $bp > 10000 ) {
				return Refusal::tax( LexwareOfficeConnector::LABEL, 'tax', $code );
			}
			$rates[ $index ] = $bp;
		}
		if ( array() !== $special ) {
			$types = array_keys( $special );
			if ( count( $types ) > 1 || count( $rates ) !== $this->countSpecial( $rows ) ) {
				return Refusal::value(
					LexwareOfficeConnector::LABEL,
					'taxConditions.taxType',
					__( 'Lexware Office takes one tax treatment per invoice, but the tax mapping gives the lines different ones. Use the same tax code for every line.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'mixed tax treatment'
				);
			}
			return array(
				'type'    => $types[0],
				'special' => true,
				'rates'   => $rates,
			);
		}
		return array(
			'type'    => $pricesInclude ? 'gross' : 'net',
			'special' => false,
			'rates'   => $rates,
		);
	}

	/**
	 * How many rows carry a special type.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 */
	private function countSpecial( array $rows ): int {
		$count = 0;
		foreach ( $rows as $row ) {
			if ( null !== $this->specialType( trim( (string) $row['code'] ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The special type a code names, in the spelling Lexware uses, or null.
	 *
	 * @param string $code Tax code.
	 */
	public function specialType( string $code ): ?string {
		foreach ( self::SPECIAL_TYPES as $type ) {
			if ( 0 === strcasecmp( $type, $code ) ) {
				return $type;
			}
		}
		return null;
	}

	/**
	 * One line item.
	 *
	 * @param array<string,mixed>      $row     Row.
	 * @param string                   $type    Document tax type (net, gross or a special type).
	 * @param bool                     $special True when the document uses a special type (net amounts, rate 0).
	 * @param int                      $bp      Rate of this row in basis points.
	 * @param array<string,mixed>|null $article Article, when the SKU matched.
	 * @param Options                  $options Settings.
	 * @return array{body:array<string,mixed>,split:bool}
	 */
	private function item( array $row, string $type, bool $special, int $bp, ?array $article, Options $options ): array {
		$quantity = (float) $row['quantity'];
		$name     = '' !== $row['name'] ? (string) $row['name'] : (string) ( $article['title'] ?? $row['sku'] );
		$unit     = $this->amounts->unitPrice( (int) $row['total'], 2, $quantity, 2 );
		$split    = false;
		if ( null === $unit ) {
			$split    = true;
			$name     = $this->amounts->quantityText( $quantity ) . ' × ' . $name;
			$quantity = 1.0;
			$unit     = $this->amounts->decimal( (int) $row['total'], 2 );
		}
		$unitName  = (string) ( $article['unitName'] ?? '' );
		$amountKey = ( 'gross' === $type && ! $special ) ? 'grossAmount' : 'netAmount';
		$price     = array(
			'currency'          => 'EUR',
			$amountKey          => $this->amounts->number( $unit ),
			'taxRatePercentage' => $this->amounts->number( $this->amounts->percent( $bp ) ),
		);
		$body      = array(
			'type'      => 'custom',
			'name'      => $name,
			'quantity'  => $this->amounts->number( $this->amounts->quantityText( $quantity ) ),
			'unitName'  => '' !== $unitName ? $unitName : ( '' !== $options->text( 'unit_name' ) ? $options->text( 'unit_name' ) : 'Stück' ),
			'unitPrice' => $price,
		);
		if ( null !== $article && '' !== (string) ( $article['id'] ?? '' ) ) {
			$body['type'] = 'SERVICE' === strtoupper( (string) ( $article['type'] ?? '' ) ) ? 'service' : 'material';
			$body['id']   = (string) $article['id'];
		}
		return array(
			'body'  => $body,
			'split' => $split,
		);
	}

	/**
	 * The article with an article number (exact, case-insensitive), or null.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $sku Article number.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function article( ApiClient $api, string $sku ): ?array {
		$json = $api->get(
			'articles',
			array(
				'articleNumber' => $sku,
				'size'          => 250,
			)
		)->json();
		foreach ( (array) ( $json['content'] ?? array() ) as $article ) {
			if ( is_array( $article ) && 0 === strcasecmp( PayloadReader::text( $article['articleNumber'] ?? '' ), $sku ) ) {
				return $article;
			}
		}
		return null;
	}
}
