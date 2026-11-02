<?php
/**
 * Reads from Oracle Fusion: the invoice facts and the nightly check list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Invoices are read with `onlyData=true` and no `fields` list, because the attribute that holds the total is not known from an Oracle page;
 * what comes back is looked at as it is. A pod that shows no total makes the nightly check say so instead of reporting every order missing.
 */
final class OracleFusionReader {

	/**
	 * Most invoices read for the nightly check.
	 */
	public const MAX_ROWS = 10000;

	/**
	 * Set up with what it depends on.
	 *
	 * @param OracleFusionGateway $gateway Calls.
	 * @param OracleFusionOrders  $orders  Invoices.
	 * @param OracleFusionOptions $options Options.
	 * @param string              $system  System name for messages.
	 */
	public function __construct(
		private readonly OracleFusionGateway $gateway,
		private readonly OracleFusionOrders $orders,
		private readonly OracleFusionOptions $options,
		private readonly string $system
	) {
	}

	/**
	 * Invoice facts for a synced order.
	 *
	 * @param string $id CustomerTransactionId.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( string $id ): ?array {
		$invoice = $this->gateway->one( OracleFusionOrders::RESOURCE, $id );
		if ( null === $invoice ) {
			return null;
		}
		$status = strtolower( (string) ( $invoice['InvoiceStatus'] ?? '' ) );
		return array(
			'number'           => (string) ( $invoice['TransactionNumber'] ?? '' ),
			'status'           => 'incomplete' === $status ? 'draft' : 'issued',
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => substr( (string) ( $invoice['TransactionDate'] ?? '' ), 0, 10 ),
		);
	}

	/**
	 * The shop's invoices since a date with their totals and refunded amounts.
	 *
	 * @param \DateTimeImmutable $from UTC window start.
	 * @return array<string,ExternalRef>|ConnectorError Keyed by WC-{id}.
	 *
	 * @throws ApiException On failure.
	 */
	public function orders( \DateTimeImmutable $from ): array|ConnectorError {
		$attribute = $this->options->referenceAttribute();
		$window    = $this->window( $attribute, $from );
		$rows      = $this->gateway->list(
			OracleFusionOrders::RESOURCE,
			array(
				'q'       => $window,
				'orderBy' => 'CustomerTransactionId:asc',
			),
			self::MAX_ROWS
		);
		$refunded  = $this->refunded( $window );
		if ( $refunded instanceof ConnectorError ) {
			return $refunded;
		}
		$found = array();
		foreach ( $rows as $row ) {
			$reference = (string) ( $row[ $attribute ] ?? '' );
			if ( '' === $reference ) {
				continue;
			}
			$figure = $this->orders->totalOf( $row, false );
			if ( null === $figure ) {
				return $this->noTotal();
			}
			$found[ $reference ] = $this->orders->reference(
				$row,
				false,
				array(
					'refunded_minor' => $refunded[ $reference ] ?? 0,
					'email'          => '',
				)
			);
		}
		return $found;
	}

	/**
	 * Credit memo amounts by the order they name in their comments.
	 *
	 * @param string $window The `q` of the invoice list.
	 * @return array<string,int>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function refunded( string $window ): array|ConnectorError {
		$sums = array();
		foreach ( $this->gateway->list(
			OracleFusionRefunds::RESOURCE,
			array(
				'q'       => $window,
				'orderBy' => 'CustomerTransactionId:asc',
			),
			self::MAX_ROWS
		) as $memo ) {
			if ( 1 !== preg_match( '/ of order (WC-[A-Za-z0-9_-]+)/', (string) ( $memo['Comments'] ?? '' ), $match ) ) {
				continue;
			}
			$figure = $this->orders->totalOf( $memo, false );
			if ( null === $figure ) {
				return $this->noTotal();
			}
			$sums[ $match[1] ] = ( $sums[ $match[1] ] ?? 0 ) + abs( $figure['minor'] );
		}
		return $sums;
	}

	/**
	 * The `q` of the shop's documents since a date.
	 *
	 * @param string             $attribute Reference attribute.
	 * @param \DateTimeImmutable $from      UTC window start.
	 */
	private function window( string $attribute, \DateTimeImmutable $from ): string {
		return $attribute . ' like ' . $this->gateway->literal( 'WC-%' )
			. ' and BusinessUnit = ' . $this->gateway->literal( $this->options->businessUnit() )
			. ' and TransactionDate >= ' . $this->gateway->literal( $from->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '-1 day' )->format( 'Y-m-d' ) );
	}

	/**
	 * The failure for a pod that does not show a total.
	 */
	private function noTotal(): ConnectorError {
		return Problem::validation( $this->system, 'TotalAmount', __( 'Oracle Fusion does not return a total for its invoices, so the nightly check cannot compare amounts.', 'estasoft-erp-crm-sync-engine' ) );
	}
}
