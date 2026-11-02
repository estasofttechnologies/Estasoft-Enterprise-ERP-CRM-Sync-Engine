<?php
/**
 * Finds an ISO 3166-1 country from whatever people typed.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Support;

use Estasoft\SyncEngine\Mapping\Transforms\Data\CountryData;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts alpha-2 codes, alpha-3 codes, English names, common abbreviations and Arabic names; ignores case, accents and punctuation.
 */
final class CountryIndex {

	/**
	 * Comparison key => alpha-2 code.
	 *
	 * @var array<string,string>
	 */
	private array $index = array();

	/**
	 * Build the index.
	 */
	public function __construct() {
		foreach ( CountryData::COUNTRIES as $alpha2 => $row ) {
			list( $alpha3, $names ) = $row;
			foreach ( $names as $name ) {
				$key = Text::fold( $name );
				if ( '' !== $key && ! isset( $this->index[ $key ] ) ) {
					$this->index[ $key ] = $alpha2;
				}
			}
			$this->index[ strtolower( $alpha3 ) ] = $alpha2;
		}
		foreach ( array_keys( CountryData::COUNTRIES ) as $alpha2 ) {
			$this->index[ strtolower( $alpha2 ) ] = $alpha2;
		}
	}

	/**
	 * The alpha-2 code for a country as typed, or null when it is not recognised.
	 *
	 * @param string $text Code or name.
	 */
	public function alpha2( string $text ): ?string {
		$key = Text::fold( $text );
		return '' === $key ? null : ( $this->index[ $key ] ?? null );
	}

	/**
	 * The alpha-3 code for an alpha-2 code.
	 *
	 * @param string $alpha2 Alpha-2 code.
	 */
	public function alpha3( string $alpha2 ): ?string {
		return CountryData::COUNTRIES[ strtoupper( $alpha2 ) ][0] ?? null;
	}

	/**
	 * The English short name of an alpha-2 code, for messages.
	 *
	 * @param string $alpha2 Alpha-2 code.
	 */
	public function name( string $alpha2 ): ?string {
		return CountryData::COUNTRIES[ strtoupper( $alpha2 ) ][1][0] ?? null;
	}
}
