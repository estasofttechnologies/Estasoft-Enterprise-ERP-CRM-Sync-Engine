<?php
/**
 * Lookup over the country calling code table.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Support;

use Estasoft\SyncEngine\Mapping\Transforms\Data\DialCodeData;

defined( 'ABSPATH' ) || exit;

/**
 * Answers: what is the calling code and number length of a region, and which calling code does a digit string start with.
 */
final class DialCodes {

	/**
	 * Shortest and longest national number for each calling code, across the regions that share it.
	 *
	 * @var array<string,array{0:int,1:int}>
	 */
	private array $ranges = array();

	/**
	 * First region listed for each calling code (used for its trunk prefix).
	 *
	 * @var array<string,string>
	 */
	private array $primary = array();

	/**
	 * Build the reverse index.
	 */
	public function __construct() {
		foreach ( DialCodeData::REGIONS as $region => $row ) {
			list( $dial, $min, $max ) = $row;
			if ( ! isset( $this->ranges[ $dial ] ) ) {
				$this->ranges[ $dial ]  = array( $min, $max );
				$this->primary[ $dial ] = $region;
				continue;
			}
			$this->ranges[ $dial ] = array( min( $this->ranges[ $dial ][0], $min ), max( $this->ranges[ $dial ][1], $max ) );
		}
	}

	/**
	 * Calling code, minimum length, maximum length and trunk prefix of a region.
	 *
	 * @param string $region ISO 3166-1 alpha-2 code, any case.
	 * @return array{0:string,1:int,2:int,3:string}|null Null for an unknown region.
	 */
	public function region( string $region ): ?array {
		return DialCodeData::REGIONS[ strtoupper( $region ) ] ?? null;
	}

	/**
	 * The calling code a digit string starts with. Calling codes never overlap, so at most one length matches.
	 *
	 * @param string $digits Digits without a plus sign.
	 * @return string|null Calling code, or null when the digits start with no known code.
	 */
	public function dialCodeAt( string $digits ): ?string {
		for ( $length = 1; $length <= 3; $length++ ) {
			$candidate = substr( $digits, 0, $length );
			if ( isset( $this->ranges[ $candidate ] ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Shortest and longest national number accepted for a calling code.
	 *
	 * @param string $dial Calling code.
	 * @return array{0:int,1:int}
	 */
	public function range( string $dial ): array {
		return $this->ranges[ $dial ] ?? array( 4, 14 );
	}

	/**
	 * Trunk prefix people dial before a national number under a calling code ("0" for most countries).
	 *
	 * @param string $dial Calling code.
	 */
	public function trunkFor( string $dial ): string {
		$region = $this->primary[ $dial ] ?? '';
		return '' === $region ? '' : DialCodeData::REGIONS[ $region ][3];
	}
}
