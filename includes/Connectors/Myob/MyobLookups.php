<?php
/**
 * Tax codes, accounts and items of the company file.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * MYOB refers to tax codes, accounts, items and customers by a UID, but the shop knows them by their short code, display id or number. These
 * reads turn the one into the other. The tax code list is read once per object; items are asked for twenty numbers at a time.
 */
final class MyobLookups {

	/**
	 * Item numbers asked for in one query.
	 */
	private const CHUNK = 20;

	/**
	 * Tax codes of the file.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $taxCodes = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobApi $api Client.
	 */
	public function __construct( private readonly MyobApi $api ) {
	}

	/**
	 * The tax codes for the tax mapping screen.
	 *
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function taxList(): array {
		$list = array();
		foreach ( $this->taxRows() as $row ) {
			$list[] = array(
				'code'         => MyobPayloadReader::text( $row['Code'] ?? '' ),
				'name'         => MyobPayloadReader::text( $row['Description'] ?? ( $row['Code'] ?? '' ) ),
				'rate_percent' => number_format( (float) ( $row['Rate'] ?? 0 ), 4, '.', '' ),
			);
		}
		return $list;
	}

	/**
	 * The UID of a tax code.
	 *
	 * @param string $code Three-character code such as GST.
	 * @return string The UID, or empty when the file has no such code.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function taxUid( string $code ): string {
		foreach ( $this->taxRows() as $row ) {
			if ( 0 === strcasecmp( MyobPayloadReader::text( $row['Code'] ?? '' ), $code ) ) {
				return MyobPayloadReader::text( $row['UID'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * The UID of an account by its display id.
	 *
	 * @param string $displayId Display id such as 1-1110.
	 * @return string The UID, or empty when the file has no such account.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function accountUid( string $displayId ): string {
		foreach ( $this->api->select( 'GeneralLedger/Account', 'DisplayID eq ' . MyobApi::quote( $displayId ), 1 ) as $account ) {
			if ( 0 === strcasecmp( MyobPayloadReader::text( $account['DisplayID'] ?? '' ), $displayId ) ) {
				return MyobPayloadReader::text( $account['UID'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Items for a list of item numbers.
	 *
	 * @param array<int,string> $numbers Item numbers.
	 * @return array<string,array<string,mixed>> Active items keyed by the lower-case number.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function items( array $numbers ): array {
		$found = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( $numbers, static fn( string $number ): bool => '' !== $number ) ) ), self::CHUNK ) as $chunk ) {
			$filter = implode( ' or ', array_map( static fn( string $number ): string => 'Number eq ' . MyobApi::quote( $number ), $chunk ) );
			foreach ( $this->api->select( 'Inventory/Item', $filter, 1 ) as $item ) {
				if ( '' !== MyobPayloadReader::text( $item['Number'] ?? '' ) && false !== ( $item['IsActive'] ?? true ) ) {
					$found[ strtolower( MyobPayloadReader::text( $item['Number'] ) ) ] = $item;
				}
			}
		}
		return $found;
	}

	/**
	 * One item by number.
	 *
	 * @param string $number Item number.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function item( string $number ): ?array {
		return $this->items( array( $number ) )[ strtolower( $number ) ] ?? null;
	}

	/**
	 * The tax code rows, read once.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call.
	 */
	private function taxRows(): array {
		if ( null === $this->taxCodes ) {
			$this->taxCodes = $this->api->select( 'GeneralLedger/TaxCode' );
		}
		return $this->taxCodes;
	}
}
