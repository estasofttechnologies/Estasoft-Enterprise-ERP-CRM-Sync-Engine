<?php
/**
 * Reads a list from an OData service page by page.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects, not output.

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Follows `@odata.nextLink` until there is none. The link is used exactly as the server gave it (Dataverse forbids changing it).
 * A list is never cut off silently: when the page limit is reached the read stops with an error instead of returning a partial result.
 */
final class ODataPages {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param ApiClient $api      Client of the connection.
	 * @param string    $system   System name for messages.
	 * @param int       $maxPages Most pages one read may take.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $system = '', private readonly int $maxPages = 100 ) {
	}

	/**
	 * Every row of a list.
	 *
	 * @param string              $path    Path with its query options (see OData::path).
	 * @param array<string,mixed> $options ApiClient options (headers such as Prefer).
	 * @return \Generator<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call or when the page limit is reached.
	 */
	public function rows( string $path, array $options = array() ): \Generator {
		$next  = $path;
		$pages = 0;
		while ( '' !== $next ) {
			if ( ++$pages > $this->maxPages ) {
				throw new ApiException(
					new ConnectorError(
						ErrorCode::Val002,
						array(
							'system' => $this->system,
							'field'  => 'list',
							'reason' => __( 'The list was longer than the connector reads in one pass. Narrow the date window.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						),
						null,
						null,
						null,
						'page limit'
					)
				);
			}
			$response = $this->api->get( $next, array(), null, $options );
			$data     = $response->json();
			foreach ( (array) ( $data['value'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					yield $row;
				}
			}
			$link = $data['@odata.nextLink'] ?? '';
			$next = is_string( $link ) && str_starts_with( $link, 'https://' ) ? $link : '';
		}
	}

	/**
	 * The rows of a list as an array.
	 *
	 * @param string              $path    Path with its query options.
	 * @param array<string,mixed> $options ApiClient options.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function all( string $path, array $options = array() ): array {
		$rows = array();
		foreach ( $this->rows( $path, $options ) as $row ) {
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * The first row of a list, or null.
	 *
	 * @param string              $path    Path with its query options.
	 * @param array<string,mixed> $options ApiClient options.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function first( string $path, array $options = array() ): ?array {
		foreach ( $this->rows( $path, $options ) as $row ) {
			return $row;
		}
		return null;
	}
}
