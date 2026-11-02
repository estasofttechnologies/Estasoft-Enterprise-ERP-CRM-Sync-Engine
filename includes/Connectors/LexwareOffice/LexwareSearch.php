<?php
/**
 * Finds an invoice or credit note Lexware Office already has for a client reference.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\LexwareOffice;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\LocalDate;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Lexware has no reference field and no free-text search on documents [documented absence]. The reference sits in the first line of the
 * introduction. The search lists the customer's vouchers of the document's type on the document's day (`GET /v1/voucherlist`, every status),
 * pages through all of them, reads each document and looks for the reference as a whole word. It is scoped to one contact and one day on
 * the server, so it never takes "the first N rows". The `voucherlist` filter names are [third-party].
 */
final class LexwareSearch {

	/**
	 * Most pages read.
	 */
	private const MAX_PAGES = 20;

	/**
	 * Find a document.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $kind      `invoice` or `creditnote`.
	 * @param string    $contactId Lexware contact id.
	 * @param string    $marker    Client reference such as WC-1234.
	 * @param string    $dateUtc   The document's date (UTC text); empty searches every day.
	 * @param string    $timezone  Time zone of the Lexware calendar.
	 * @return array<string,mixed>|null The document as the API returns it, or null.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, string $kind, string $contactId, string $marker, string $dateUtc, string $timezone ): ?array {
		$endpoint = 'creditnote' === $kind ? 'credit-notes' : 'invoices';
		$day      = '' === $dateUtc ? '' : LocalDate::ymd( $dateUtc, $timezone );
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = array(
				'voucherType'   => $kind,
				'voucherStatus' => 'draft,open,paid,paidoff,voided',
				'contactId'     => $contactId,
				'size'          => 250,
				'page'          => $page,
			);
			if ( '' !== $day ) {
				$query['voucherDateFrom'] = $day;
				$query['voucherDateTo']   = $day;
			}
			$json = $api->get( 'voucherlist', $query )->json();
			foreach ( (array) ( $json['content'] ?? array() ) as $voucher ) {
				if ( ! is_array( $voucher ) || '' === PayloadReader::text( $voucher['id'] ?? '' ) ) {
					continue;
				}
				$document = $api->get( $endpoint . '/' . rawurlencode( (string) $voucher['id'] ) )->json();
				$text     = PayloadReader::text( $document['introduction'] ?? '' ) . "\n" . PayloadReader::text( $document['title'] ?? '' );
				if ( PayloadReader::hasMarker( $text, $marker ) ) {
					return $document;
				}
			}
			if ( ! empty( $json['last'] ) || array() === (array) ( $json['content'] ?? array() ) ) {
				break;
			}
		}
		return null;
	}
}
