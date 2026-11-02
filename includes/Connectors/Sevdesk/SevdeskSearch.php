<?php
/**
 * Finds an invoice or credit note sevDesk already has for a client reference.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\LocalDate;
use Estasoft\SyncEngine\Connectors\EuAccountingCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The sevDesk API documents no filter on the reference field, so the search is scoped on the server by the customer's contact and the document's day
 * (`contact[id]`, `startDate`, `endDate`; filter names [third-party]) and the reference is compared on every page that comes back
 * (`limit` 100, `offset` until a short page), never on "the first N rows". The reference is written to `customerInternalNote` and into the
 * heading; either carrying it as a whole word counts.
 */
final class SevdeskSearch {

	/**
	 * Rows per page.
	 */
	private const LIMIT = 100;

	/**
	 * Most pages read.
	 */
	private const MAX_PAGES = 30;

	/**
	 * Find a document.
	 *
	 * @param ApiClient $api       Client.
	 * @param string    $endpoint  `Invoice` or `CreditNote`.
	 * @param string    $contactId Contact id.
	 * @param string    $marker    Client reference such as WC-1234.
	 * @param string    $dateUtc   The document's date (UTC text); empty searches every day.
	 * @param string    $timezone  Time zone of the sevDesk calendar.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, string $endpoint, string $contactId, string $marker, string $dateUtc, string $timezone ): ?array {
		$bounds = '' === $dateUtc ? null : LocalDate::dayBounds( $dateUtc, $timezone );
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = array(
				'contact' => array(
					'id'         => $contactId,
					'objectName' => 'Contact',
				),
				'limit'   => self::LIMIT,
				'offset'  => $page * self::LIMIT,
			);
			if ( null !== $bounds ) {
				$query['startDate'] = $bounds[0];
				$query['endDate']   = $bounds[1];
			}
			$rows = SevdeskJson::rows( $api->get( $endpoint, $query )->json() );
			foreach ( $rows as $row ) {
				if ( 'Invoice' === $endpoint && 'RE' !== (string) ( $row['invoiceType'] ?? 'RE' ) ) {
					continue;
				}
				$text = PayloadReader::text( $row['customerInternalNote'] ?? '' ) . "\n" . PayloadReader::text( $row['header'] ?? '' );
				if ( PayloadReader::hasMarker( $text, $marker ) ) {
					return $row;
				}
			}
			if ( count( $rows ) < self::LIMIT ) {
				break;
			}
		}
		return null;
	}
}
