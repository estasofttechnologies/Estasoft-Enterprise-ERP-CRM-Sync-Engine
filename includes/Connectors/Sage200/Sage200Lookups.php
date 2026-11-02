<?php
/**
 * Lookups of Sage 200 setup data: products by code, tax codes, the base currency.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\TaxPricing;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The `In` filter is not supported by the API, so a product is looked up with one request per code. Codes are matched without regard to case.
 */
final class Sage200Lookups {

	/**
	 * Products found so far by lower-case code.
	 *
	 * @var array<string,array{id:string,code:string}|null>
	 */
	private array $products = array();

	/**
	 * Tax codes by lower-case code, loaded once.
	 *
	 * @var array<string,array{id:string,code:string,name:string,percent:string,rate:int|null}>|null
	 */
	private ?array $taxes = null;

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage200Client $client Client.
	 */
	public function __construct( private readonly Sage200Client $client ) {
	}

	/**
	 * Products by code.
	 *
	 * @param array<int,string> $codes Product codes (SKUs).
	 * @return array<string,array{id:string,code:string}> Keyed by lower-case code; unknown codes are left out.
	 *
	 * @throws ApiException On failure.
	 */
	public function products( array $codes ): array {
		$found = array();
		foreach ( $codes as $code ) {
			$low = strtolower( trim( $code ) );
			if ( '' === $low ) {
				continue;
			}
			if ( ! array_key_exists( $low, $this->products ) ) {
				$row                    = $this->client->first( 'products', 'code eq ' . Sage200Client::literal( trim( $code ) ) );
				$this->products[ $low ] = null !== $row && isset( $row['id'] ) && is_scalar( $row['id'] )
					? array(
						'id'   => (string) $row['id'],
						'code' => isset( $row['code'] ) && is_scalar( $row['code'] ) ? (string) $row['code'] : trim( $code ),
					)
					: null;
			}
			if ( null !== $this->products[ $low ] ) {
				$found[ $low ] = $this->products[ $low ];
			}
		}
		return $found;
	}

	/**
	 * The tax codes of the company.
	 *
	 * @return array<string,array{id:string,code:string,name:string,percent:string,rate:int|null}> Keyed by lower-case code.
	 *
	 * @throws ApiException On failure.
	 */
	public function taxCodes(): array {
		if ( null === $this->taxes ) {
			$this->taxes = array();
			foreach ( $this->client->rows( 'tax_codes', '', 1000 ) as $row ) {
				if ( ! isset( $row['id'], $row['code'] ) || ! is_scalar( $row['id'] ) || ! is_scalar( $row['code'] ) ) {
					continue;
				}
				$code                               = trim( (string) $row['code'] );
				$percent                            = isset( $row['tax_rate'] ) && is_numeric( $row['tax_rate'] ) ? rtrim( rtrim( number_format( (float) $row['tax_rate'], 4, '.', '' ), '0' ), '.' ) : '0';
				$this->taxes[ strtolower( $code ) ] = array(
					'id'      => (string) $row['id'],
					'code'    => $code,
					'name'    => isset( $row['name'] ) && is_scalar( $row['name'] ) ? trim( (string) $row['name'] ) : $code,
					'percent' => '' === $percent ? '0' : $percent,
					'rate'    => TaxPricing::rate( '' === $percent ? '0' : $percent ),
				);
			}
		}
		return $this->taxes;
	}

	/**
	 * The tax code that has an id.
	 *
	 * @param string $id Tax code id.
	 * @return array{id:string,code:string,name:string,percent:string,rate:int|null}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function taxById( string $id ): ?array {
		foreach ( $this->taxCodes() as $tax ) {
			if ( $tax['id'] === $id ) {
				return $tax;
			}
		}
		return null;
	}

	/**
	 * The id of the base currency of the company, or an empty string when the list does not say.
	 *
	 * @throws ApiException On failure.
	 */
	public function baseCurrencyId(): string {
		foreach ( $this->client->rows( 'currencies', '', 500 ) as $row ) {
			if ( ! empty( $row['is_base_currency'] ) && isset( $row['id'] ) && is_scalar( $row['id'] ) ) {
				return (string) $row['id'];
			}
		}
		return '';
	}
}
