<?php
/**
 * Reads the order list for the nightly check from Sage 200.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * A plain GET with a date filter. Every page of the window is read; rows whose Customer Document No starts with `WC-` are kept.
 */
final class Sage200Reader {

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage200Client      $client Client.
	 * @param Sage200OrderSender $orders Order reads.
	 */
	public function __construct( private readonly Sage200Client $client, private readonly Sage200OrderSender $orders ) {
	}

	/**
	 * Orders since a date for the nightly check.
	 *
	 * @param \DateTimeImmutable $from UTC start of the window.
	 * @return array<string,ExternalRef> Keyed by WC-{id}. Sage 200 returns cannot be tied to an order, so `refunded_minor` is 0.
	 *
	 * @throws ApiException On failure.
	 */
	public function reconcile( \DateTimeImmutable $from ): array {
		$day  = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '-1 day' )->format( 'Y-m-d' ) . 'T00:00:00Z';
		$out  = array();
		$rows = $this->client->rows( 'sop_orders', 'document_date ge ' . $day );
		foreach ( $rows as $row ) {
			$key = trim( (string) ( $row['customer_document_no'] ?? '' ) );
			if ( 1 !== preg_match( '/^WC-\d+$/', $key ) ) {
				continue;
			}
			$ref         = $this->orders->reference( $row );
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
