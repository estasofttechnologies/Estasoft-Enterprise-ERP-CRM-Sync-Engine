<?php
/**
 * Finds Xero items by code.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The WooCommerce SKU is the Xero item code (max 30 characters). Several codes are asked for with one call
 * (`where=Code=="A" OR Code=="B"`), twenty at a time; Xero answers an item list without paging.
 */
final class XeroItems {

	/**
	 * Codes asked for in one call.
	 */
	private const CHUNK = 20;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $api Client for the connection.
	 */
	public function __construct( private readonly ApiClient $api ) {
	}

	/**
	 * Items for a list of codes.
	 *
	 * @param array<int,string> $codes Item codes (SKUs).
	 * @return array<string,array<string,mixed>> Items keyed by the lower-case code. A code with a quote, a backslash or a control character cannot be searched and is not found.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function byCodes( array $codes ): array {
		$searchable = array();
		foreach ( array_unique( array_filter( $codes, static fn( string $code ): bool => '' !== $code ) ) as $code ) {
			if ( 1 === preg_match( '/["\\\\\x00-\x1F]/', $code ) ) {
				continue;
			}
			$searchable[] = $code;
		}
		$found = array();
		foreach ( array_chunk( $searchable, self::CHUNK ) as $chunk ) {
			$where = implode( ' OR ', array_map( static fn( string $code ): string => 'Code=="' . $code . '"', $chunk ) );
			$data  = $this->api->get(
				'Items',
				array(
					'where'  => $where,
					'unitdp' => 4,
				)
			)->json();
			foreach ( (array) ( $data['Items'] ?? array() ) as $item ) {
				if ( is_array( $item ) && '' !== XeroPayloadReader::text( $item['Code'] ?? '' ) ) {
					$found[ strtolower( XeroPayloadReader::text( $item['Code'] ) ) ] = $item;
				}
			}
		}
		return $found;
	}
}
