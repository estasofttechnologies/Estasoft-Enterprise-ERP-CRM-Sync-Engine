<?php
/**
 * Reads deals back from Dynamics 365 Sales for the nightly check.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * The deals of the window with their values, and what the refund notes say was refunded. Both lists are filtered by the server on the
 * `WC-` prefix and the date, and read page by page.
 */
final class DvReader {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param DvClient  $dv      Client.
	 * @param DvOptions $options Options.
	 * @param string    $system  System name.
	 */
	public function __construct( private readonly DvClient $dv, private readonly DvOptions $options, private readonly string $system ) {
	}

	/**
	 * Deals created, or dated, on or after the window start.
	 *
	 * @param \DateTimeImmutable $from Window start (UTC).
	 * @return array<string,ExternalRef> Keyed by WC-{order id}.
	 *
	 * @throws ApiException On failure.
	 */
	public function reconcile( \DateTimeImmutable $from ): array {
		$utc      = $from->setTimezone( new \DateTimeZone( 'UTC' ) );
		$date     = OData::date( $utc );
		$stamp    = $utc->format( 'Y-m-d\TH:i:s\Z' );
		$column   = $this->options->referenceColumn();
		$window   = '(estimatedclosedate ge ' . $date . ' or createdon ge ' . $stamp . ')';
		$prefix   = '' !== $column ? OData::startsWith( $column, 'WC-' ) : OData::startsWith( 'name', 'WC-' );
		$refunded = $this->refunds( $stamp );
		$codes    = array();
		$found    = array();
		$rows     = $this->dv->rows(
			'opportunities',
			array(
				'$filter' => OData::all( array( $prefix, $window ) ),
				'$select' => DvOpportunities::SELECT . ( '' !== $column ? ',' . $column : '' ),
			)
		);
		foreach ( $rows as $row ) {
			$reference = '' !== $column ? (string) ( $row[ $column ] ?? '' ) : ( 1 === preg_match( '/^(WC-\d+) - /', (string) ( $row['name'] ?? '' ), $name ) ? $name[1] : '' );
			if ( 1 !== preg_match( '/^WC-\d+$/', $reference ) ) {
				continue;
			}
			$currencyId = (string) ( $row['_transactioncurrencyid_value'] ?? '' );
			if ( ! isset( $codes[ $currencyId ] ) ) {
				$codes[ $currencyId ] = ( new DvCurrencies( $this->dv, $this->system ) )->codeOf( $currencyId );
			}
			$currency            = $codes[ $currencyId ];
			$info                = DvOpportunities::markerInfo( (string) ( $row['description'] ?? '' ), $reference );
			$base                = ( new DvOpportunities( $this->dv, $this->options, $this->system ) )->ref( $row, $currency, '' );
			$found[ $reference ] = new ExternalRef(
				$base->id,
				$base->ref,
				null,
				$base->extra + array(
					'refunded_minor' => $refunded[ $reference ] ?? 0,
					'email'          => $info['email'] ?? '',
				)
			);
		}
		return $found;
	}

	/**
	 * Refunded amounts by the order they refer to, from the refund notes.
	 *
	 * @param string $stamp Window start as an OData date-time.
	 * @return array<string,int> Minor units by WC-{order id}.
	 *
	 * @throws ApiException On failure.
	 */
	private function refunds( string $stamp ): array {
		$notes = $this->dv->rows(
			'annotations',
			array(
				'$filter' => OData::all( array( OData::startsWith( 'subject', 'Refund WC-' ), 'createdon ge ' . $stamp ) ),
				'$select' => 'annotationid,subject,notetext',
			)
		);
		$sums  = array();
		foreach ( $notes as $note ) {
			if ( 1 !== preg_match( '/^Refund WC-\d+ of (WC-\d+)$/', (string) ( $note['subject'] ?? '' ), $order ) || 1 !== preg_match( '/\[estasoft:refund WC-\d+ minor=(-?\d+) [A-Z]{3}\]/', (string) ( $note['notetext'] ?? '' ), $amount ) ) {
				continue;
			}
			$sums[ $order[1] ] = ( $sums[ $order[1] ] ?? 0 ) + (int) $amount[1];
		}
		return $sums;
	}
}
