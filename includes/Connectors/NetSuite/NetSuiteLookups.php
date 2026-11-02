<?php
/**
 * Reads of NetSuite master data through SuiteQL: currencies, customers and items.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Every value goes in as a bound parameter; the only name put into a query text is the SKU field, which the options class has checked
 * against a fixed pattern. Lookups never create anything.
 */
final class NetSuiteLookups {

	/**
	 * Currency ids by ISO code, for the length of the request.
	 *
	 * @var array<string,string|null>
	 */
	private array $currencies = array();

	/**
	 * Every active currency, read once.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $all = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param NetSuiteGateway $gateway Calls.
	 * @param NetSuiteOptions $options Options.
	 */
	public function __construct( private readonly NetSuiteGateway $gateway, private readonly NetSuiteOptions $options ) {
	}

	/**
	 * The internal id of a currency.
	 *
	 * @param string $code ISO 4217 code.
	 * @return string|null Null when NetSuite has no active currency with that symbol.
	 *
	 * @throws ApiException On failure.
	 */
	public function currencyId( string $code ): ?string {
		$code = strtoupper( trim( $code ) );
		if ( ! array_key_exists( $code, $this->currencies ) ) {
			$rows                      = $this->gateway->rows( "SELECT id FROM currency WHERE UPPER(symbol) = ? AND isinactive = 'F'", array( $code ), 1 );
			$this->currencies[ $code ] = isset( $rows[0]['id'] ) ? (string) $rows[0]['id'] : null;
		}
		return $this->currencies[ $code ];
	}

	/**
	 * Every active currency (internal id and symbol).
	 *
	 * @return array<string,string> Symbol to internal id.
	 *
	 * @throws ApiException On failure.
	 */
	public function currencies(): array {
		if ( null === $this->all ) {
			$this->all = array();
			foreach ( $this->gateway->rows( "SELECT id, symbol FROM currency WHERE isinactive = 'F'" ) as $row ) {
				if ( isset( $row['id'], $row['symbol'] ) ) {
					$this->all[ strtoupper( (string) $row['symbol'] ) ] = (string) $row['id'];
				}
			}
		}
		return $this->all;
	}

	/**
	 * The ISO code of a currency from its internal id.
	 *
	 * @param string $id Internal id.
	 * @return string Empty when unknown.
	 *
	 * @throws ApiException On failure.
	 */
	public function symbolOf( string $id ): string {
		$symbol = array_search( $id, $this->currencies(), true );
		return false === $symbol ? '' : (string) $symbol;
	}

	/**
	 * The oldest active customer with an email, ignoring case.
	 *
	 * @param string $email Email.
	 * @return array{id:string,name:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function customerByEmail( string $email ): ?array {
		$rows = $this->gateway->rows(
			"SELECT id, entityid, companyname, firstname, lastname FROM customer WHERE LOWER(email) = ? AND isinactive = 'F' ORDER BY id",
			array( strtolower( trim( $email ) ) ),
			1
		);
		if ( array() === $rows || ! isset( $rows[0]['id'] ) ) {
			return null;
		}
		$row    = $rows[0];
		$person = trim( trim( (string) ( $row['firstname'] ?? '' ) ) . ' ' . trim( (string) ( $row['lastname'] ?? '' ) ) );
		$name   = '' !== trim( (string) ( $row['companyname'] ?? '' ) ) ? trim( (string) $row['companyname'] ) : ( '' !== $person ? $person : trim( (string) ( $row['entityid'] ?? '' ) ) );
		return array(
			'id'   => (string) $row['id'],
			'name' => $name,
		);
	}

	/**
	 * An item by the value in the SKU field, ignoring case.
	 *
	 * @param string $sku SKU as sold in the shop.
	 * @return array{id:string,ref:string,name:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function itemBySku( string $sku ): ?array {
		$field = $this->options->skuField();
		$rows  = $this->gateway->rows(
			"SELECT id, itemid, displayname FROM item WHERE UPPER({$field}) = UPPER(?) AND isinactive = 'F' ORDER BY id",
			array( trim( $sku ) ),
			1
		);
		return array() === $rows || ! isset( $rows[0]['id'] ) ? null : $this->item( $rows[0] );
	}

	/**
	 * Items whose SKU field or display name contains a text.
	 *
	 * @param string $query Search text.
	 * @return array<int,array{id:string,ref:string,name:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function searchItems( string $query ): array {
		$field = $this->options->skuField();
		$like  = '%' . strtolower( addcslashes( trim( $query ), '%_\\' ) ) . '%';
		$found = array();
		foreach ( $this->gateway->rows( "SELECT id, itemid, displayname FROM item WHERE (LOWER({$field}) LIKE ? OR LOWER(displayname) LIKE ?) AND isinactive = 'F' ORDER BY id", array( $like, $like ), 25 ) as $row ) {
			if ( isset( $row['id'] ) ) {
				$found[] = $this->item( $row );
			}
		}
		return $found;
	}

	/**
	 * One item row as a small array.
	 *
	 * @param array<string,mixed> $row SuiteQL row.
	 * @return array{id:string,ref:string,name:string}
	 */
	private function item( array $row ): array {
		return array(
			'id'   => (string) $row['id'],
			'ref'  => (string) ( $row['itemid'] ?? '' ),
			'name' => (string) ( $row['displayname'] ?? '' ),
		);
	}
}
