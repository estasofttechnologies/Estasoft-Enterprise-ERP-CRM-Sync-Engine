<?php
/**
 * Reads from NetSuite for the invoice status and the nightly check.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * SuiteQL reads only. Dates are sent as bound parameters (`TO_DATE(?, 'YYYY-MM-DD')`).
 */
final class NetSuiteReader {

	/**
	 * Set up with what it depends on.
	 *
	 * @param NetSuiteGateway $gateway Calls.
	 * @param NetSuiteLookups $lookups Reads.
	 * @param Amounts         $amounts Conversion helper.
	 * @param string          $host    Host of the account (for links).
	 */
	public function __construct(
		private readonly NetSuiteGateway $gateway,
		private readonly NetSuiteLookups $lookups,
		private readonly Amounts $amounts,
		private readonly string $host
	) {
	}

	/**
	 * Invoice facts for a sales order.
	 *
	 * @param string $orderId Internal id of the sales order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( string $orderId ): ?array {
		$rows = $this->gateway->rows( "SELECT id, tranid, status, trandate FROM transaction WHERE type = 'CustInvc' AND createdfrom = ? ORDER BY id DESC", array( $orderId ), 1 );
		if ( array() === $rows ) {
			return null;
		}
		$status = strtoupper( (string) ( $rows[0]['status'] ?? '' ) );
		$date   = (string) ( $rows[0]['trandate'] ?? '' );
		return array(
			'number'           => (string) ( $rows[0]['tranid'] ?? '' ),
			'status'           => 'B' === $status ? 'paid' : ( 'A' === $status ? 'open' : strtolower( $status ) ),
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => $this->isoDate( $date ),
		);
	}

	/**
	 * Sales orders of the shop since a date, with totals, refunds and the buyer's email.
	 *
	 * @param \DateTimeImmutable $from UTC window start.
	 * @return array<string,ExternalRef> Keyed by WC-{id}.
	 *
	 * @throws ApiException On failure.
	 */
	public function orders( \DateTimeImmutable $from ): array {
		$day     = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d' );
		$symbols = array_flip( $this->lookups->currencies() );
		$rows    = $this->gateway->rows(
			"SELECT t.id, t.tranid, t.externalid, t.foreigntotal, t.currency, c.email FROM transaction t LEFT JOIN customer c ON c.id = t.entity WHERE t.type = 'SalesOrd' AND t.externalid LIKE 'WC-%' AND t.trandate >= TO_DATE(?, 'YYYY-MM-DD') ORDER BY t.id",
			array( $day ),
			10000
		);
		$refunds = $this->refunded( $day, $symbols );
		$found   = array();
		foreach ( $rows as $row ) {
			$reference = (string) ( $row['externalid'] ?? '' );
			$currency  = (string) ( $symbols[ (string) ( $row['currency'] ?? '' ) ] ?? '' );
			if ( '' === $reference || '' === $currency ) {
				continue;
			}
			$found[ $reference ] = new ExternalRef(
				(string) $row['id'],
				(string) ( $row['tranid'] ?? '' ),
				'https://' . $this->host . '.app.netsuite.com/app/accounting/transactions/salesord.nl?id=' . rawurlencode( (string) $row['id'] ),
				array(
					'total_minor'    => abs( (int) $this->amounts->fromWire( $row['foreigntotal'] ?? null, $currency ) ),
					'refunded_minor' => $refunds[ $reference ] ?? 0,
					'email'          => (string) ( $row['email'] ?? '' ),
					'currency'       => $currency,
				)
			);
		}
		return $found;
	}

	/**
	 * Credit memo totals per sales order reference (credit memo to invoice to sales order).
	 *
	 * @param string               $day     Window start, Y-m-d.
	 * @param array<string,string> $symbols Currency id to ISO code.
	 * @return array<string,int> Minor units by order reference.
	 *
	 * @throws ApiException On failure.
	 */
	private function refunded( string $day, array $symbols ): array {
		$rows = $this->gateway->rows(
			"SELECT so.externalid AS orderref, cm.foreigntotal, cm.currency FROM transaction cm JOIN transaction inv ON inv.id = cm.createdfrom JOIN transaction so ON so.id = inv.createdfrom WHERE cm.type = 'CustCred' AND so.externalid LIKE 'WC-%' AND so.trandate >= TO_DATE(?, 'YYYY-MM-DD')",
			array( $day ),
			10000
		);
		$sums = array();
		foreach ( $rows as $row ) {
			$reference = (string) ( $row['orderref'] ?? '' );
			$currency  = (string) ( $symbols[ (string) ( $row['currency'] ?? '' ) ] ?? '' );
			if ( '' === $reference || '' === $currency ) {
				continue;
			}
			$sums[ $reference ] = ( $sums[ $reference ] ?? 0 ) + abs( (int) $this->amounts->fromWire( $row['foreigntotal'] ?? null, $currency ) );
		}
		return $sums;
	}

	/**
	 * A NetSuite date (m/d/Y or Y-m-d) as an ISO date, or an empty string.
	 *
	 * @param string $date Date text.
	 */
	private function isoDate( string $date ): string {
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $date, $match ) ) {
			return $match[1] . '-' . $match[2] . '-' . $match[3];
		}
		if ( 1 === preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $date, $match ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $match[3], (int) $match[1], (int) $match[2] );
		}
		return '';
	}
}
