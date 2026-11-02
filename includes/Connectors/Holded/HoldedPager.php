<?php
/**
 * Reads every page of a Holded list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Holded lists answer a plain JSON array of records and take `?page=2` [documented]. The pager asks for page 1, 2, 3 ... and stops at an empty
 * page or at a page that adds no record it has not seen (a server that ignores the parameter). A `status: 0` body is an error even in a 200.
 */
final class HoldedPager {

	/**
	 * Most pages one read follows.
	 */
	public const MAX_PAGES = 100;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedErrorMapper $errors Error mapping.
	 */
	public function __construct( private readonly HoldedErrorMapper $errors ) {
	}

	/**
	 * All rows of a list.
	 *
	 * @param ApiClient           $api   Client.
	 * @param string              $path  List path such as `invoicing/v1/contacts`.
	 * @param array<string,mixed> $query Filters.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException When a request fails or the list is longer than MAX_PAGES pages.
	 */
	public function all( ApiClient $api, string $path, array $query = array() ): array {
		$rows = array();
		$seen = array();
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$json = $api->get( $path, $query + array( 'page' => $page ) )->json();
			$this->guard( $json );
			$data  = array_is_list( $json ) ? $json : ( is_array( $json['data'] ?? null ) ? $json['data'] : array() );
			$fresh = 0;
			foreach ( $data as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id = HoldedPayloadReader::text( $row['id'] ?? ( $row['_id'] ?? '' ) );
				if ( '' !== $id && isset( $seen[ $id ] ) ) {
					continue;
				}
				if ( '' !== $id ) {
					$seen[ $id ] = true;
				}
				$rows[] = $row;
				++$fresh;
			}
			if ( 0 === $fresh ) {
				return $rows;
			}
		}
		$error = new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => HoldedConnector::LABEL,
				'field'  => $path,
				'reason' => __( 'The list is longer than the connector reads in one pass. Narrow the search.', 'estasoft-erp-crm-sync-engine' ),
			),
			null,
			null,
			null,
			'list too long'
		);
		throw new ApiException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
	}

	/**
	 * Throw the error of a `status: 0` answer.
	 *
	 * @param array<mixed> $json Decoded answer.
	 *
	 * @throws ApiException When the answer is a refusal.
	 */
	public function guard( array $json ): void {
		$error = $this->errors->answer( $json );
		if ( null !== $error ) {
			throw new ApiException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
	}
}
