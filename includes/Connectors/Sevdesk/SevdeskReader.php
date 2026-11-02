<?php
/**
 * What is read back from sevDesk: stock, invoice facts, the nightly list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Stock comes from `GET /Part?partNumber=` (id, `stockEnabled`) and `GET /Part/{id}/getStock`, which answers `{"objects": <number>}` [third-party
 * mirror of the reference]. It is a single total: sevDesk has no warehouses. The invoice list carries the reference in `customerInternalNote` and in
 * the heading, so a night's reconciliation can key the invoices by `WC-{id}` without reading each one.
 */
final class SevdeskReader {

	/**
	 * Rows per page.
	 */
	private const LIMIT = 100;

	/**
	 * Most pages read.
	 */
	private const MAX_PAGES = 50;

	/**
	 * Stock by SKU. A part without stock tracking is left out.
	 *
	 * @param ApiClient         $api  Client.
	 * @param SevdeskLines      $lines Part lookups.
	 * @param array<int,string> $skus SKUs.
	 * @return array<string,array{quantity:int,held:int}>
	 *
	 * @throws ApiException On failure.
	 */
	public function stock( ApiClient $api, SevdeskLines $lines, array $skus ): array {
		$out = array();
		foreach ( $skus as $sku ) {
			$part = $lines->part( $api, (string) $sku );
			if ( null === $part || ! SevdeskJson::flag( $part['stockEnabled'] ?? false ) ) {
				continue;
			}
			$answer = $api->get( 'Part/' . rawurlencode( (string) $part['id'] ) . '/getStock' )->json();
			$value  = $answer['objects'] ?? null;
			if ( is_numeric( $value ) ) {
				$out[ (string) $sku ] = array(
					'quantity' => (int) round( (float) $value ),
					'held'     => 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Invoices of the shop from a date on, keyed by client reference, with the refunded amount of each.
	 *
	 * @param ApiClient          $api  Client.
	 * @param \DateTimeImmutable $from Window start (UTC).
	 * @return array<string,ExternalRef>
	 *
	 * @throws ApiException On failure.
	 */
	public function reconcile( ApiClient $api, \DateTimeImmutable $from ): array {
		$refunded = array();
		foreach ( $this->listAll( $api, 'CreditNote', $from ) as $credit ) {
			$text = PayloadReader::text( $credit['header'] ?? '' );
			if ( 1 === preg_match( '/(WC-\d+)(?![A-Za-z0-9]).*?(WC-\d+)(?![A-Za-z0-9])/u', $text, $parts ) ) {
				$refunded[ $parts[2] ] = ( $refunded[ $parts[2] ] ?? 0 ) + (int) SevdeskJson::minor( $credit['sumGross'] ?? 0 );
			}
		}
		$out = array();
		foreach ( $this->listAll( $api, 'Invoice', $from ) as $invoice ) {
			if ( 'RE' !== (string) ( $invoice['invoiceType'] ?? 'RE' ) ) {
				continue;
			}
			$text = PayloadReader::text( $invoice['customerInternalNote'] ?? '' ) . ' ' . PayloadReader::text( $invoice['header'] ?? '' );
			if ( 1 !== preg_match( '/(?<![A-Za-z0-9])(WC-\d+)(?![A-Za-z0-9])/u', $text, $parts ) ) {
				continue;
			}
			$out[ $parts[1] ] = new ExternalRef(
				PayloadReader::text( $invoice['id'] ?? '' ),
				PayloadReader::text( $invoice['invoiceNumber'] ?? '' ),
				null,
				array(
					'total_minor'    => (int) SevdeskJson::minor( $invoice['sumGross'] ?? 0 ),
					'refunded_minor' => $refunded[ $parts[1] ] ?? 0,
					'email'          => '',
				)
			);
		}
		return $out;
	}

	/**
	 * Every document of a kind dated from a day on.
	 *
	 * @param ApiClient          $api      Client.
	 * @param string             $endpoint `Invoice` or `CreditNote`.
	 * @param \DateTimeImmutable $from     Window start (UTC).
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	private function listAll( ApiClient $api, string $endpoint, \DateTimeImmutable $from ): array {
		$all = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$rows = SevdeskJson::rows(
				$api->get(
					$endpoint,
					array(
						'startDate' => $from->getTimestamp(),
						'limit'     => self::LIMIT,
						'offset'    => $page * self::LIMIT,
					)
				)->json()
			);
			$all  = array_merge( $all, $rows );
			if ( count( $rows ) < self::LIMIT ) {
				break;
			}
		}
		return $all;
	}
}
