<?php
/**
 * Items (products) of the company.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\Failures;
use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Finds items by the field the connection matches SKUs with (the item number, or the GTIN). The search is made by the server in the pinned
 * company; the rows it returns are compared again here so a loose server match never links the wrong item.
 */
final class BcItems {

	/**
	 * Matches returned by a product search.
	 */
	private const SEARCH_LIMIT = 20;

	/**
	 * Fields read of an item.
	 */
	private const SELECT = 'id,number,displayName,type,blocked,gtin,unitPrice,taxGroupCode';

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param BcClient  $bc      Client pinned to the company.
	 * @param BcOptions $options Options.
	 * @param string    $system  System name for messages.
	 */
	public function __construct( private readonly BcClient $bc, private readonly BcOptions $options, private readonly string $system ) {
	}

	/**
	 * The item for a SKU, or null.
	 *
	 * @param string $sku SKU.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $sku ): ?array {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		$field = $this->options->skuField();
		$path  = $this->bc->path( 'items' );
		$row   = $this->match(
			$this->bc->rows(
				$path,
				array(
					'$filter' => OData::eq( $field, $sku ),
					'$select' => self::SELECT,
				)
			),
			$field,
			$sku
		);
		if ( null !== $row || ( mb_strtolower( $sku ) === $sku && mb_strtoupper( $sku ) === $sku ) ) {
			return $row;
		}
		// A number is a Code in Business Central and is found whatever the case; a plain text filter may not be. tolower() turns case off.
		try {
			$rows = $this->bc->rows(
				$path,
				array(
					'$filter' => 'tolower(' . $field . ') eq ' . OData::string( mb_strtolower( $sku ) ),
					'$select' => self::SELECT,
				)
			);
		} catch ( ApiException $exception ) {
			if ( 400 !== $exception->status() ) {
				throw $exception;
			}
			return null;
		}
		return $this->match( $rows, $field, $sku );
	}

	/**
	 * The row whose field equals the SKU, ignoring case.
	 *
	 * @param array<int,array<string,mixed>> $rows  Rows the server returned.
	 * @param string                         $field Field compared.
	 * @param string                         $sku   SKU.
	 * @return array<string,mixed>|null
	 */
	private function match( array $rows, string $field, string $sku ): ?array {
		foreach ( $rows as $row ) {
			if ( 0 === strcasecmp( (string) ( $row[ $field ] ?? '' ), $sku ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The items of a list of SKUs, keyed by SKU. A SKU that is not an item, or is blocked, is a refusal.
	 *
	 * @param array<int,string> $skus Distinct SKUs.
	 * @return array<string,array<string,mixed>|null>|ConnectorError A row, or null for an unknown SKU (the caller decides what that means).
	 *
	 * @throws ApiException On failure.
	 */
	public function resolve( array $skus ): array|ConnectorError {
		$items = array();
		foreach ( array_unique( $skus ) as $sku ) {
			$row = $this->find( $sku );
			if ( null !== $row && true === ( $row['blocked'] ?? false ) ) {
				return Failures::invalid( $this->system, 'order_line[].product', sprintf( /* translators: %s: SKU. */ __( 'Item %s is blocked in Business Central.', 'estasoft-erp-crm-sync-engine' ), $sku ), 'item blocked' );
			}
			$items[ $sku ] = $row;
		}
		return $items;
	}

	/**
	 * Items whose number starts with, or whose name contains, a text. Two searches, because BC does not allow OR across two fields.
	 *
	 * @param string $query Search text.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function search( string $query ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$found = array();
		foreach ( array( OData::startsWith( $this->options->skuField(), $query ), OData::contains( 'displayName', $query ) ) as $filter ) {
			$rows = $this->bc->rows(
				$this->bc->path( 'items' ),
				array(
					'$filter' => $filter,
					'$select' => self::SELECT,
					'$top'    => self::SEARCH_LIMIT,
				)
			);
			foreach ( $rows as $row ) {
				$found[ (string) ( $row['id'] ?? '' ) ] = $row;
			}
		}
		return array_slice( array_values( $found ), 0, self::SEARCH_LIMIT );
	}

	/**
	 * An item row as a reference.
	 *
	 * @param array<string,mixed> $row Item row.
	 */
	public function ref( array $row ): ExternalRef {
		return new ExternalRef(
			(string) ( $row['id'] ?? '' ),
			(string) ( $row['number'] ?? '' ),
			null,
			array(
				'name'    => (string) ( $row['displayName'] ?? '' ),
				'gtin'    => (string) ( $row['gtin'] ?? '' ),
				'type'    => (string) ( $row['type'] ?? '' ),
				'blocked' => true === ( $row['blocked'] ?? false ),
			)
		);
	}
}
