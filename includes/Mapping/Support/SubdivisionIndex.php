<?php
/**
 * Finds an ISO 3166-2 subdivision from whatever people typed.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Support;

use Estasoft\SyncEngine\Mapping\Transforms\Data\SubdivisionData;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts the subdivision code ("DU", "AE-DU", "AEDU" as WooCommerce writes some of them) and English or Arabic names.
 */
final class SubdivisionIndex {

	/**
	 * Comparison keys built so far, by country.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $cache = array();

	/**
	 * The ISO 3166-2 code of a subdivision, such as AE-DU.
	 *
	 * @param string $country Alpha-2 country code.
	 * @param string $text    What was typed or stored.
	 * @return string|null Code, or null when the country is listed and the text is not recognised.
	 */
	public function code( string $country, string $text ): ?string {
		$country = strtoupper( $country );
		$key     = Text::fold( $text );
		if ( '' === $key ) {
			return null;
		}
		if ( ! isset( SubdivisionData::SUBDIVISIONS[ $country ] ) ) {
			return $this->unlisted( $country, $text );
		}
		$index = $this->indexFor( $country );
		if ( isset( $index[ $key ] ) ) {
			return $country . '-' . $index[ $key ];
		}
		$prefix = strtolower( $country );
		if ( 0 === strpos( $key, $prefix ) ) {
			$rest = trim( substr( $key, strlen( $prefix ) ) );
			if ( isset( $index[ $rest ] ) ) {
				return $country . '-' . $index[ $rest ];
			}
		}
		return null;
	}

	/**
	 * A country without a table: a code is passed through, a name cannot be resolved.
	 *
	 * @param string $country Alpha-2 country code.
	 * @param string $text    What was typed or stored.
	 */
	private function unlisted( string $country, string $text ): ?string {
		$text = strtoupper( Text::trim( $text ) );
		if ( 1 === preg_match( '/^' . preg_quote( $country, '/' ) . '[- ]([A-Z0-9]{1,3})$/', $text, $found ) ) {
			return $country . '-' . $found[1];
		}
		if ( 1 === preg_match( '/^[A-Z0-9]{1,3}$/', $text ) ) {
			return $country . '-' . $text;
		}
		return null;
	}

	/**
	 * Comparison key => subdivision code for one listed country.
	 *
	 * @param string $country Alpha-2 country code.
	 * @return array<string,string>
	 */
	private function indexFor( string $country ): array {
		if ( isset( $this->cache[ $country ] ) ) {
			return $this->cache[ $country ];
		}
		$index = array();
		foreach ( SubdivisionData::SUBDIVISIONS[ $country ] as $code => $names ) {
			foreach ( $names as $name ) {
				$key = Text::fold( $name );
				if ( '' !== $key && ! isset( $index[ $key ] ) ) {
					$index[ $key ] = (string) $code;
				}
			}
			foreach ( array( (string) $code, $country . '-' . $code, $country . $code ) as $alias ) {
				$index[ Text::fold( $alias ) ] = (string) $code;
			}
		}
		$this->cache[ $country ] = $index;
		return $index;
	}
}
