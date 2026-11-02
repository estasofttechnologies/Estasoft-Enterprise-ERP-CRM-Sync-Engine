<?php
/**
 * References to Business Central documents.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Amounts;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * The reference the engine stores for a sales order, an invoice or a credit memo: the document's id, its number, and facts the checks use.
 */
final class BcRefs {

	/**
	 * A document row as a reference.
	 *
	 * @param BcDocType           $type     Kind of document.
	 * @param array<string,mixed> $row      Row from the API.
	 * @param string              $currency Currency to read the total with when the row has none.
	 * @param string              $warning  Accepted difference, for the result.
	 */
	public static function document( BcDocType $type, array $row, string $currency = '', string $warning = '' ): ExternalRef {
		$code  = '' !== (string) ( $row['currencyCode'] ?? '' ) ? (string) $row['currencyCode'] : $currency;
		$extra = array(
			'total_minor' => Amounts::minor( $row['totalAmountIncludingTax'] ?? 0, $code ),
			'currency'    => $code,
			'status'      => (string) ( $row['status'] ?? '' ),
			'kind'        => $type->value,
			'reference'   => (string) ( $row['externalDocumentNumber'] ?? '' ),
		);
		if ( '' !== $warning ) {
			$extra['warnings'] = array( $warning );
		}
		return new ExternalRef( (string) ( $row['id'] ?? '' ), (string) ( $row['number'] ?? '' ), null, $extra );
	}
}
