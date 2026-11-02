<?php
/**
 * Items (products) of Oracle Fusion.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Looks items up in `itemsV2` by item number (`q=ItemNumber = '...'`) and searches them by part of a number or description.
 */
final class OracleFusionItems {

	public const RESOURCE = 'itemsV2';

	/**
	 * Set up with what it depends on.
	 *
	 * @param OracleFusionGateway $gateway Calls.
	 */
	public function __construct( private readonly OracleFusionGateway $gateway ) {
	}

	/**
	 * An item by its number (the upper-case form is tried next, since item numbers are normally upper case).
	 *
	 * @param string $sku SKU.
	 * @return array{id:string,ref:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function product( string $sku ): ?array {
		foreach ( array_unique( array( trim( $sku ), strtoupper( trim( $sku ) ) ) ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			$rows = $this->gateway->list(
				self::RESOURCE,
				array(
					'q'      => 'ItemNumber = ' . $this->gateway->literal( $candidate ),
					'fields' => 'ItemNumber',
				),
				1
			);
			if ( array() !== $rows ) {
				$number = (string) ( $rows[0]['ItemNumber'] ?? $candidate );
				return array(
					'id'  => $number,
					'ref' => $number,
				);
			}
		}
		return null;
	}

	/**
	 * Items whose number or description contains a text.
	 *
	 * @param string $query Search text.
	 * @return array<int,array{id:string,ref:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function search( string $query ): array {
		$text = trim( str_replace( array( '%', '_' ), '', $query ) );
		if ( '' === $text ) {
			return array();
		}
		$like  = $this->gateway->literal( '%' . $text . '%' );
		$upper = $this->gateway->literal( '%' . strtoupper( $text ) . '%' );
		$found = array();
		foreach ( $this->gateway->list(
			self::RESOURCE,
			array(
				'q'       => 'ItemNumber like ' . $upper . ' or ItemDescription like ' . $like,
				'fields'  => 'ItemNumber',
				'orderBy' => 'ItemNumber:asc',
			),
			25
		) as $row ) {
			$number = (string) ( $row['ItemNumber'] ?? '' );
			if ( '' !== $number ) {
				$found[] = array(
					'id'  => $number,
					'ref' => $number,
				);
			}
		}
		return $found;
	}
}
