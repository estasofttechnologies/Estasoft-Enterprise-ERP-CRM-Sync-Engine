<?php
/**
 * Reads the order list for the nightly check from Sage 300.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * A plain GET with a date filter. If the server refuses `startswith`, the date filter alone is used and every page of the window is read.
 */
final class Sage300Reader {

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage300Client      $client  Client.
	 * @param Sage300Lookups     $lookups Setup lookups.
	 * @param Sage300OrderSender $orders  Order reads.
	 */
	public function __construct( private readonly Sage300Client $client, private readonly Sage300Lookups $lookups, private readonly Sage300OrderSender $orders ) {
	}

	/**
	 * Orders since a date for the nightly check.
	 *
	 * @param \DateTimeImmutable $from UTC start of the window.
	 * @return array<string,ExternalRef>|ConnectorError Keyed by WC-{id}. `refunded_minor` is 0: credit notes are not tied to an order here.
	 *
	 * @throws ApiException On failure.
	 */
	public function reconcile( \DateTimeImmutable $from ): array|ConnectorError {
		$property = $this->orders->referenceProperty();
		if ( $property instanceof ConnectorError ) {
			return $property;
		}
		$day    = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$filter = "OrderDate ge datetime'" . $day . "T00:00'";
		try {
			$rows = $this->client->rows( 'OE/OEOrders', $filter . ' and startswith(' . $property . ",'WC-')" );
		} catch ( ApiException $exception ) {
			if ( 400 !== $exception->status() && 'request' !== ( $exception->error()->context['field'] ?? '' ) ) {
				throw $exception;
			}
			$rows = $this->client->rows( 'OE/OEOrders', $filter );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$key = trim( (string) ( $row[ $property ] ?? '' ) );
			if ( 1 !== preg_match( '/^WC-\d+$/', $key ) ) {
				continue;
			}
			$customer    = trim( (string) ( $row['CustomerNumber'] ?? '' ) );
			$ref         = $this->orders->reference( $row, '' === $customer ? '' : ( $this->lookups->customer( $customer )['currency'] ?? '' ) );
			$out[ $key ] = new ExternalRef(
				$ref->id,
				$ref->ref,
				null,
				$ref->extra + array(
					'refunded_minor' => 0,
					'email'          => '',
				)
			);
		}
		return $out;
	}
}
