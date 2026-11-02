<?php
/**
 * Reads documents back from Business Central.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * The invoice of a synced order, and the document lists of the nightly check. Lists are asked from the server with the date and the
 * `WC-` prefix as filters and read page by page; nothing is cut off.
 */
final class BcReader {

	/**
	 * Fields read for the nightly check.
	 */
	private const SELECT = 'id,number,externalDocumentNumber,totalAmountIncludingTax,currencyCode,status';

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param BcClient $bc Client pinned to the company.
	 */
	public function __construct( private readonly BcClient $bc ) {
	}

	/**
	 * The latest posted invoice of an order or an invoice reference.
	 *
	 * @param ExternalRef $ref The synced document: an order number or an invoice.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( ExternalRef $ref ): ?array {
		$rows = array();
		if ( '' !== $ref->ref ) {
			$rows = $this->bc->rows( $this->bc->path( 'salesInvoices' ), array( '$filter' => OData::eq( 'orderNumber', $ref->ref ) ) );
			if ( array() === $rows ) {
				$rows = $this->bc->rows( $this->bc->path( 'salesInvoices' ), array( '$filter' => OData::eq( 'number', $ref->ref ) ) );
			}
		}
		$guid = OData::guid( $ref->id );
		if ( array() === $rows && null !== $guid ) {
			$one  = $this->bc->one( $this->bc->path( 'salesInvoices', $guid ) );
			$rows = null === $one ? array() : array( $one );
		}
		$rows = array_values( array_filter( $rows, static fn( array $row ): bool => BcDocType::Invoice->isPosted( (string) ( $row['status'] ?? '' ) ) ) );
		if ( array() === $rows ) {
			return null;
		}
		usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $b['invoiceDate'] ?? '' ), (string) ( $a['invoiceDate'] ?? '' ) ) );
		$row  = $rows[0];
		$date = (string) ( $row['invoiceDate'] ?? $row['postingDate'] ?? '' );
		return array(
			'number'           => (string) ( $row['number'] ?? '' ),
			'status'           => strtolower( (string) ( $row['status'] ?? '' ) ),
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date . 'T00:00:00Z' : '',
		);
	}

	/**
	 * The orders and invoices of the window with their totals and what was refunded.
	 *
	 * @param \DateTimeImmutable $from Window start.
	 * @return array<string,ExternalRef> Keyed by WC-{order id}.
	 *
	 * @throws ApiException On failure.
	 */
	public function reconcile( \DateTimeImmutable $from ): array {
		$date     = OData::date( $from );
		$refunded = $this->refunds( $date );
		$found    = array();
		$kinds    = array(
			array( BcDocType::Order, self::SELECT . ',email' ),
			array( BcDocType::Invoice, self::SELECT ),
		);
		foreach ( $kinds as $kind ) {
			$type = $kind[0];
			$rows = $this->bc->rows(
				$this->bc->path( $type->collection() ),
				array(
					'$filter' => OData::all( array( $type->dateField() . ' ge ' . $date, OData::startsWith( 'externalDocumentNumber', 'WC-' ) ) ),
					'$select' => $kind[1],
				)
			);
			foreach ( $rows as $row ) {
				$reference = (string) ( $row['externalDocumentNumber'] ?? '' );
				if ( 1 !== preg_match( '/^WC-\d+$/', $reference ) ) {
					continue;
				}
				if ( isset( $found[ $reference ] ) && BcDocType::Invoice !== $type ) {
					continue;
				}
				$base                = BcRefs::document( $type, $row );
				$found[ $reference ] = new ExternalRef(
					$base->id,
					$base->ref,
					null,
					$base->extra + array(
						'refunded_minor' => $refunded[ $reference ] ?? 0,
						'email'          => (string) ( $row['email'] ?? '' ),
					)
				);
			}
		}
		return $found;
	}

	/**
	 * Credit memo totals of the window, by the order they refer to.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return array<string,int> Minor units by WC-{order id}.
	 *
	 * @throws ApiException On failure.
	 */
	private function refunds( string $date ): array {
		$rows = $this->bc->rows(
			$this->bc->path( 'salesCreditMemos' ),
			array(
				'$filter' => OData::all( array( 'creditMemoDate ge ' . $date, OData::startsWith( 'externalDocumentNumber', 'WC-' ) ) ),
				'$select' => self::SELECT,
				'$expand' => 'salesCreditMemoLines($select=lineType,description)',
			)
		);
		$sums = array();
		foreach ( $rows as $row ) {
			$order = null;
			foreach ( (array) ( $row['salesCreditMemoLines'] ?? array() ) as $line ) {
				if ( is_array( $line ) && 'Comment' === (string) ( $line['lineType'] ?? '' ) && 1 === preg_match( '/^Refund of (WC-\d+)$/', trim( (string) ( $line['description'] ?? '' ) ), $found ) ) {
					$order = $found[1];
					break;
				}
			}
			if ( null === $order || 'Canceled' === (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			$sums[ $order ] = ( $sums[ $order ] ?? 0 ) + Amounts::minor( $row['totalAmountIncludingTax'] ?? 0, (string) ( $row['currencyCode'] ?? '' ) );
		}
		return $sums;
	}
}
