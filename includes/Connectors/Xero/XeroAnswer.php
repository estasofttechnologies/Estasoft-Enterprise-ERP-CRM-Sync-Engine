<?php
/**
 * Reads the record out of a Xero create or update answer.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Xero answers a create with a list that holds the new record, and a record with validation errors can come back inside a 200 answer.
 * This turns both of those into an exception that carries SYNC-VAL-002.
 */
final class XeroAnswer {

	/**
	 * The first record of a list in an answer.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $key      Key of the list, such as Invoices.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException When the answer has no record or the record holds validation errors.
	 */
	public static function element( ApiResponse $response, string $key ): array {
		$data    = $response->json();
		$element = isset( $data[ $key ][0] ) && is_array( $data[ $key ][0] ) ? $data[ $key ][0] : null;
		if ( null === $element ) {
			$failure = XeroErrors::exception( XeroErrors::refused( $key, __( 'Xero answered without the record.', 'estasoft-enterprise-erpcrm-sync-engine' ), $response->status() ), $response );
			throw $failure;
		}
		if ( ! empty( $element['HasErrors'] ) || ! empty( $element['ValidationErrors'] ) ) {
			$messages = XeroErrors::validationMessages( array( $key => array( $element ) ) );
			$failure  = XeroErrors::exception( XeroErrors::refused( $key, array() === $messages ? __( 'Xero refused the data.', 'estasoft-enterprise-erpcrm-sync-engine' ) : $messages[0], $response->status() ), $response );
			throw $failure;
		}
		return $element;
	}
}
