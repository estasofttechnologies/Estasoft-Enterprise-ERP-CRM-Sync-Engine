<?php
/**
 * Calls the SuiteCRM v8 JSON:API of one server.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SuiteCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Module records are `{id, type, attributes}`. A list without `page[size]` returns every record of the module, so every search names a
 * page size and reads pages until one comes back short. A filtered field must exist in the module's definition, otherwise SuiteCRM answers
 * with an error that names the field.
 */
final class SuiteCrmApi {

	/**
	 * Records asked for per page.
	 */
	public const PAGE = 50;

	/**
	 * Most pages one search reads.
	 */
	public const MAX_PAGES = 400;

	/**
	 * Media type of the JSON:API.
	 */
	public const MEDIA = 'application/vnd.api+json';

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $client Client for the connection.
	 */
	public function __construct( private readonly ApiClient $client ) {
	}

	/**
	 * Records of a module that match conditions, all pages.
	 *
	 * @param string                                       $module   Module name such as Opportunities.
	 * @param array<int,array{0:string,1:string,2:string}> $filters  Conditions: field, operator (eq, neq, gt, gte, lt, lte, like), value.
	 * @param string                                       $operator and or or, how the conditions combine.
	 * @param string                                       $sort     Field to sort by, with a leading minus for descending; empty for none.
	 * @param int                                          $maxPages Pages to read at most; a search for suggestions reads one.
	 * @return array<int,array{id:string,attributes:array<string,mixed>}>
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException On failure.
	 * @throws SuiteCrmFailure When the answer is not JSON or the list is too long.
	 */
	public function find( string $module, array $filters = array(), string $operator = 'and', string $sort = '', int $maxPages = self::MAX_PAGES ): array {
		$rows = array();
		for ( $page = 1; $page <= $maxPages; ++$page ) {
			$query = array(
				'page' => array(
					'size'   => self::PAGE,
					'number' => $page,
				),
			);
			if ( array() !== $filters ) {
				$query['filter'] = array();
				foreach ( $filters as $filter ) {
					$query['filter'][ $filter[0] ][ $filter[1] ] = $filter[2];
				}
				if ( count( $filters ) > 1 ) {
					$query['filter']['operator'] = $operator;
				}
			}
			if ( '' !== $sort ) {
				$query['sort'] = $sort;
			}
			$data  = $this->data( $this->call( 'get', 'V8/module/' . rawurlencode( $module ), $query, null, array( 'accept' => self::MEDIA ) )->json() );
			$count = 0;
			foreach ( $data as $record ) {
				if ( is_array( $record ) && isset( $record['id'] ) ) {
					$rows[] = self::record( $record );
					++$count;
				}
			}
			if ( $count < self::PAGE || $maxPages < self::MAX_PAGES ) {
				return $rows;
			}
		}
		SuiteCrmFailure::refuse( 'window', __( 'The list in SuiteCRM is longer than the plugin reads in one go.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * One record by id, or null when SuiteCRM has none.
	 *
	 * @param string $module Module.
	 * @param string $id     Record id.
	 * @return array{id:string,attributes:array<string,mixed>}|null
	 */
	public function get( string $module, string $id ): ?array {
		$response = $this->call(
			'get',
			'V8/module/' . rawurlencode( $module ) . '/' . rawurlencode( $id ),
			array(),
			null,
			array(
				'accept' => self::MEDIA,
				'allow'  => array( 404 ),
			)
		);
		if ( 404 === $response->status() ) {
			return null;
		}
		$data = $response->json()['data'] ?? null;
		return is_array( $data ) && isset( $data['id'] ) ? self::record( $data ) : null;
	}

	/**
	 * Create a record.
	 *
	 * @param string              $module     Module.
	 * @param array<string,mixed> $attributes Fields.
	 * @return array{id:string,attributes:array<string,mixed>}
	 *
	 * @throws SuiteCrmFailure When SuiteCRM does not answer with the new record.
	 */
	public function create( string $module, array $attributes ): array {
		$response = $this->call(
			'post',
			'V8/module',
			array(),
			array(
				'data' => array(
					'type'       => $module,
					'attributes' => $attributes,
				),
			),
			array(
				'accept'       => self::MEDIA,
				'content_type' => self::MEDIA,
			)
		);
		$data     = $response->json()['data'] ?? null;
		if ( ! is_array( $data ) || ! isset( $data['id'] ) ) {
			SuiteCrmFailure::refuse( $module, __( 'SuiteCRM did not return the new record.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return self::record( $data );
	}

	/**
	 * The ids related to a record through a link field.
	 *
	 * @param string $module Module.
	 * @param string $id     Record id.
	 * @param string $link   Link field name such as contacts.
	 * @return array<int,string>
	 */
	public function related( string $module, string $id, string $link ): array {
		$data = $this->data( $this->call( 'get', 'V8/module/' . rawurlencode( $module ) . '/' . rawurlencode( $id ) . '/relationships/' . rawurlencode( $link ), array(), null, array( 'accept' => self::MEDIA ) )->json() );
		$ids  = array();
		foreach ( $data as $record ) {
			if ( is_array( $record ) && isset( $record['id'] ) ) {
				$ids[] = (string) $record['id'];
			}
		}
		return $ids;
	}

	/**
	 * Relate two records.
	 *
	 * @param string $module        Module of the record.
	 * @param string $id            Record id.
	 * @param string $link          Link field name such as contacts.
	 * @param string $relatedModule Module of the other record.
	 * @param string $relatedId     Id of the other record.
	 */
	public function link( string $module, string $id, string $link, string $relatedModule, string $relatedId ): void {
		$this->call(
			'post',
			'V8/module/' . rawurlencode( $module ) . '/' . rawurlencode( $id ) . '/relationships/' . rawurlencode( $link ),
			array(),
			array(
				'data' => array(
					'type' => $relatedModule,
					'id'   => $relatedId,
				),
			),
			array(
				'accept'       => self::MEDIA,
				'content_type' => self::MEDIA,
			)
		);
	}

	/**
	 * Read one page of a module to see whether the client may read it.
	 *
	 * @param string $module Module.
	 * @return bool False when SuiteCRM refuses; a lapsed sign-in is thrown.
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException For a lapsed sign-in and any failure but a refusal.
	 */
	public function canRead( string $module ): bool {
		try {
			$this->call(
				'get',
				'V8/module/' . rawurlencode( $module ),
				array(
					'page' => array(
						'size'   => 1,
						'number' => 1,
					),
				),
				null,
				array( 'accept' => self::MEDIA )
			);
			return true;
		} catch ( ApiException $exception ) {
			if ( ErrorCode::Auth004 === $exception->error()->code ) {
				return false;
			}
			throw $exception;
		}
	}

	/**
	 * The user the token belongs to.
	 *
	 * @return array<string,mixed> Attributes of the user (empty when SuiteCRM gives none).
	 */
	public function currentUser(): array {
		$data = $this->call( 'get', 'V8/current-user', array(), null, array( 'accept' => self::MEDIA ) )->json()['data'] ?? array();
		return is_array( $data ) && is_array( $data['attributes'] ?? null ) ? $data['attributes'] : array();
	}

	/**
	 * One call to the API. Where URL rewriting is off the token path answers with a 404 page, which the sign-in reports as a refused key; the
	 * person needs to hear about the address and the rewriting instead.
	 *
	 * @param string              $method  get or post.
	 * @param string              $path    Path under the API address.
	 * @param array<string,mixed> $query   Query parameters.
	 * @param mixed               $body    Body of a post.
	 * @param array<string,mixed> $options Options of the client.
	 *
	 * @throws ApiException On failure. A token path that is not the SuiteCRM API is reported as a SuiteCrmFailure.
	 */
	private function call( string $method, string $path, array $query = array(), mixed $body = null, array $options = array() ): ApiResponse {
		try {
			return 'post' === $method ? $this->client->post( $path, $query, $body, $options ) : $this->client->get( $path, $query, $body, $options );
		} catch ( ApiException $exception ) {
			$error = $exception->error();
			if ( ErrorCode::Auth001 === $error->code && 404 === $error->httpStatus ) {
				SuiteCrmFailure::refuseNotApi();
			}
			throw $exception;
		}
	}

	/**
	 * The `data` list of a JSON:API answer. An answer that is not JSON (the page of a server where URL rewriting is off) is an error.
	 *
	 * @param array<mixed> $json Decoded answer.
	 * @return array<int,mixed>
	 *
	 * @throws SuiteCrmFailure When the answer is not JSON:API.
	 */
	private function data( array $json ): array {
		if ( array() === $json ) {
			SuiteCrmFailure::refuseNotApi();
		}
		return is_array( $json['data'] ?? null ) ? $json['data'] : array();
	}

	/**
	 * A record in a plain shape.
	 *
	 * @param array<mixed> $record Record as SuiteCRM returns it.
	 * @return array{id:string,attributes:array<string,mixed>}
	 */
	private static function record( array $record ): array {
		return array(
			'id'         => (string) $record['id'],
			'attributes' => is_array( $record['attributes'] ?? null ) ? $record['attributes'] : array(),
		);
	}

	/**
	 * Whether an error is the "not a JSON:API answer" failure.
	 *
	 * @param ConnectorError $error Error.
	 */
	public static function isNotApi( ConnectorError $error ): bool {
		return ErrorCode::Val002 === $error->code && 'address' === ( $error->context['field'] ?? '' );
	}
}
