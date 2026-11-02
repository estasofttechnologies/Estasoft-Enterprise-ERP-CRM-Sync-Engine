<?php
/**
 * Reference data sevDesk needs on every document: system version, contact person, country ids.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Small reads that every invoice needs. The bookkeeping system version is stored by Test connection (`detected.bookkeeping_version`) and read from
 * the connection; it is asked for again only when it is missing. A sevDesk account is on "sevDesk-Update 2.0" (tax rules) or the older 1.0 (tax types)
 * [documented in the API news].
 */
final class SevdeskReference {

	/**
	 * The bookkeeping system version, "2.0" or "1.0".
	 *
	 * @param ApiClient  $api        Client.
	 * @param Connection $connection Connection (the stored value is used first).
	 *
	 * @throws ApiException On failure.
	 */
	public function version( ApiClient $api, Connection $connection ): string {
		$detected = $connection->config( 'detected' );
		$stored   = is_array( $detected ) ? (string) ( $detected['bookkeeping_version'] ?? '' ) : '';
		if ( '' !== $stored ) {
			return $stored;
		}
		return $this->askVersion( $api );
	}

	/**
	 * Ask sevDesk for the version.
	 *
	 * @param ApiClient $api Client.
	 *
	 * @throws ApiException On failure.
	 */
	public function askVersion( ApiClient $api ): string {
		$json    = $api->get( 'Tools/bookkeepingSystemVersion' )->json();
		$objects = $json['objects'] ?? array();
		$version = is_array( $objects ) ? (string) ( $objects['version'] ?? '' ) : '';
		return '1.0' === $version ? '1.0' : '2.0';
	}

	/**
	 * The id of the sevDesk user that is named as contact person on documents: the first user.
	 *
	 * @param ApiClient $api Client.
	 * @return string Empty when the account has no user.
	 *
	 * @throws ApiException On failure.
	 */
	public function userId( ApiClient $api ): string {
		$user = SevdeskJson::first( $api->get( 'SevUser', array( 'limit' => 1 ) )->json() );
		return null === $user ? '' : (string) ( $user['id'] ?? '' );
	}

	/**
	 * The id of a country by its ISO alpha-2 code.
	 *
	 * @param ApiClient $api Client.
	 * @param string    $iso Two-letter code.
	 * @return string Empty when sevDesk does not know the country.
	 *
	 * @throws ApiException On failure.
	 */
	public function countryId( ApiClient $api, string $iso ): string {
		$iso = strtolower( trim( $iso ) );
		if ( '' === $iso ) {
			return '';
		}
		foreach ( SevdeskJson::rows( $api->get( 'StaticCountry', array( 'limit' => 1000 ) )->json() ) as $country ) {
			if ( strtolower( trim( (string) ( $country['code'] ?? '' ) ) ) === $iso ) {
				return (string) ( $country['id'] ?? '' );
			}
		}
		return '';
	}
}
