<?php
/**
 * Reads from Fortnox: stock, invoice facts and the nightly check.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\Amounts;
use Estasoft\SyncEngine\Connectors\CrmCommon\CrmOptions;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Read-only. Every list is paged to the end.
 */
final class FortnoxReads {

	/**
	 * Address of the warehouse API (not under /3).
	 */
	public const STOCK_URL = 'https://api.fortnox.se/api/warehouse/status-v1/stockbalance';

	/**
	 * SKUs asked for in one warehouse call.
	 */
	private const CHUNK = 50;

	/**
	 * Set up with what it depends on.
	 *
	 * @param FortnoxApi       $api       API.
	 * @param FortnoxLookups   $lookups   Lookups.
	 * @param FortnoxDocuments $documents Document helper.
	 * @param CrmOptions       $options   Options.
	 */
	public function __construct( private readonly FortnoxApi $api, private readonly FortnoxLookups $lookups, private readonly FortnoxDocuments $documents, private readonly CrmOptions $options ) {
	}

	/**
	 * On-hand and held quantities.
	 *
	 * @param array<int,string> $skus       Article numbers.
	 * @param array<int,string> $warehouses Stock point codes that count (warehouse source only); empty means all.
	 * @return array<string,array{quantity:int,held:int}>
	 */
	public function stock( array $skus, array $warehouses ): array {
		$skus = array_values( array_unique( array_map( 'strval', $skus ) ) );
		return 'warehouse' === $this->options->choice( 'stock_source', array( 'article', 'warehouse' ), 'article' )
			? $this->warehouseStock( $skus, $warehouses )
			: $this->articleStock( $skus );
	}

	/**
	 * Facts of an invoice.
	 *
	 * @param ExternalRef $ref The synced order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 */
	public function invoice( ExternalRef $ref ): ?array {
		$orders = 'order' === ( $ref->extra['document'] ?? '' );
		$row    = $this->api->one( $orders ? 'orders' : 'invoices', $ref->id, $orders ? 'Order' : 'Invoice' );
		if ( null === $row ) {
			return null;
		}
		$currency = '' !== (string) ( $row['Currency'] ?? '' ) ? (string) $row['Currency'] : 'SEK';
		if ( true === ( $row['Cancelled'] ?? false ) ) {
			$status = 'cancelled';
		} elseif ( $orders ) {
			$status = 'open';
		} elseif ( true === ( $row['Booked'] ?? false ) ) {
			$status = 0 === Amounts::minor( $row['Balance'] ?? null, $currency ) ? 'paid' : 'booked';
		} else {
			$status = 'open';
		}
		return array(
			'number'           => (string) ( $row['DocumentNumber'] ?? $ref->id ),
			'status'           => $status,
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => FortnoxApi::date( $row['InvoiceDate'] ?? $row['OrderDate'] ?? '' ),
		);
	}

	/**
	 * Documents of the shop from a day on, with totals and refunded amounts.
	 *
	 * @param \DateTimeImmutable $from UTC window start.
	 * @return array<string,ExternalRef> Keyed by WC-{id}.
	 */
	public function reconcile( \DateTimeImmutable $from ): array {
		$date     = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d' );
		$orders   = $this->documents->usesOrders();
		$invoices = $this->api->rows( 'invoices', 'Invoices', array( 'fromdate' => $date ), 500 );
		$main     = $orders ? $this->api->rows( 'orders', 'Orders', array( 'fromdate' => $date ), 500 ) : $invoices;
		$refunded = array();
		foreach ( $invoices as $row ) {
			$e1 = trim( (string) ( $row['ExternalInvoiceReference1'] ?? '' ) );
			$e2 = trim( (string) ( $row['ExternalInvoiceReference2'] ?? '' ) );
			if ( 1 === preg_match( '/^WC-\d+$/', $e1 ) && 1 === preg_match( '/^WC-\d+$/', $e2 ) && true !== ( $row['Cancelled'] ?? false ) ) {
				$currency        = '' !== (string) ( $row['Currency'] ?? '' ) ? (string) $row['Currency'] : 'SEK';
				$refunded[ $e2 ] = ( $refunded[ $e2 ] ?? 0 ) + abs( Amounts::minor( $row['Total'] ?? null, $currency ) ?? 0 );
			}
		}
		$out = array();
		foreach ( $main as $row ) {
			$e1 = trim( (string) ( $row['ExternalInvoiceReference1'] ?? '' ) );
			$e2 = trim( (string) ( $row['ExternalInvoiceReference2'] ?? '' ) );
			if ( 1 !== preg_match( '/^WC-\d+$/', $e1 ) || 1 === preg_match( '/^WC-\d+$/', $e2 ) || true === ( $row['Cancelled'] ?? false ) ) {
				continue;
			}
			$currency   = '' !== (string) ( $row['Currency'] ?? '' ) ? (string) $row['Currency'] : 'SEK';
			$out[ $e1 ] = new ExternalRef(
				(string) ( $row['DocumentNumber'] ?? '' ),
				(string) ( $row['DocumentNumber'] ?? '' ),
				null,
				array(
					'total_minor'    => abs( Amounts::minor( $row['Total'] ?? null, $currency ) ?? 0 ),
					'refunded_minor' => $refunded[ $e1 ] ?? 0,
					'currency'       => $currency,
					'email'          => '',
				)
			);
		}
		return $out;
	}

	/**
	 * Stock from the article register.
	 *
	 * @param array<int,string> $skus Article numbers.
	 * @return array<string,array{quantity:int,held:int}>
	 */
	private function articleStock( array $skus ): array {
		$out = array();
		foreach ( $skus as $sku ) {
			$row = $this->lookups->article( $sku );
			if ( null === $row || ! isset( $row['QuantityInStock'] ) ) {
				continue;
			}
			$out[ $sku ] = array(
				'quantity' => (int) floor( (float) $row['QuantityInStock'] ),
				'held'     => max( 0, (int) ceil( (float) ( $row['ReservedQuantity'] ?? 0 ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Stock from the Lager (warehouse) API.
	 *
	 * @param array<int,string> $skus       Article numbers.
	 * @param array<int,string> $warehouses Stock point codes; empty means all.
	 * @return array<string,array{quantity:int,held:int}>
	 */
	private function warehouseStock( array $skus, array $warehouses ): array {
		$wanted = array();
		foreach ( $warehouses as $code ) {
			$wanted[ Text::lower( trim( (string) $code ) ) ] = true;
		}
		$byKey = array();
		foreach ( $skus as $sku ) {
			$byKey[ Text::lower( trim( $sku ) ) ] = $sku;
		}
		$out = array();
		foreach ( array_chunk( $skus, self::CHUNK ) as $chunk ) {
			$query = array( 'itemIds' => implode( ',', $chunk ) );
			if ( array() !== $warehouses ) {
				$query['stockPointCodes'] = implode( ',', $warehouses );
			}
			foreach ( self::balances( $this->api->raw( self::STOCK_URL, $query ) ) as $balance ) {
				$key   = Text::lower( trim( (string) self::pick( $balance, 'itemId' ) ) );
				$sku   = $byKey[ $key ] ?? null;
				$point = Text::lower( trim( (string) self::pick( $balance, 'stockPointCode' ) ) );
				if ( null === $sku || ( array() !== $wanted && ! isset( $wanted[ $point ] ) ) ) {
					continue;
				}
				$in          = (float) self::pick( $balance, 'inStock' );
				$avail       = (float) self::pick( $balance, 'availableStock' );
				$out[ $sku ] = array(
					'quantity' => ( $out[ $sku ]['quantity'] ?? 0 ) + (int) floor( $in ),
					'held'     => ( $out[ $sku ]['held'] ?? 0 ) + max( 0, (int) ceil( $in - $avail ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * The rows of a stock balance answer: a list, or an object holding a list.
	 *
	 * @param array<mixed> $data Decoded answer.
	 * @return array<int,array<string,mixed>>
	 */
	private static function balances( array $data ): array {
		if ( ! \Estasoft\SyncEngine\Core\Support\Lists::isList( $data ) ) {
			foreach ( $data as $value ) {
				if ( is_array( $value ) && \Estasoft\SyncEngine\Core\Support\Lists::isList( $value ) ) {
					$data = $value;
					break;
				}
			}
		}
		return array_values( array_filter( $data, 'is_array' ) );
	}

	/**
	 * A value by key, in either letter case of the first letter.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $key Key in camel case.
	 */
	private static function pick( array $row, string $key ): mixed {
		return $row[ $key ] ?? $row[ ucfirst( $key ) ] ?? '';
	}
}
