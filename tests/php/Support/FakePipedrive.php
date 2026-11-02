<?php
/**
 * A small in-memory Pipedrive for the connector tests.
 *
 * Shapes follow pipedrive.readme.io and developers.pipedrive.com: API v2 persons/organizations/deals/products with cursor paging
 * (additional_data.next_cursor), GET /api/v2/itemSearch, v1 notes with start and more_items_in_collection, error bodies
 * {success:false, error, error_info, errorCode}.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

final class FakePipedrive extends FakeSalesServer {

	public const KEY = '0123456789abcdef0123456789abcdef01234567';

	/**
	 * Rows by collection: id => row.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $rows = array(
		'persons'       => array(),
		'organizations' => array(),
		'deals'         => array(),
		'products'      => array(),
		'notes'         => array(),
	);

	/**
	 * Products attached to deals: deal id => list.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $dealProducts = array();

	/**
	 * Currencies the company accepts.
	 *
	 * @var array<int,string>
	 */
	public array $currencies = array( 'USD', 'EUR', 'AED', 'SAR' );

	/**
	 * When true a deal's value follows the sum of its products.
	 */
	public bool $valueFromProducts = false;

	/**
	 * When true a PATCH cannot change a deal's value.
	 */
	public bool $lockValue = false;

	/**
	 * Collections the token may not read.
	 *
	 * @var array<int,string>
	 */
	public array $denied = array();

	private int $counter = 100;

	/**
	 * Add a product the way a merchant would.
	 *
	 * @param string $code SKU.
	 * @param string $name Name.
	 */
	public function addProduct( string $code, string $name ): string {
		return $this->insert( 'products', array( 'code' => $code, 'name' => $name ) );
	}

	/**
	 * Add a deal the way an earlier pass or the merchant would.
	 *
	 * @param string $title    Title.
	 * @param float  $value    Value.
	 * @param string $currency Currency.
	 */
	public function addDeal( string $title, float $value, string $currency = 'EUR' ): string {
		return $this->insert( 'deals', array( 'title' => $title, 'value' => $value, 'currency' => $currency, 'status' => 'open', 'person_id' => 1 ) );
	}

	/**
	 * Rows of a collection that are not deleted.
	 *
	 * @param string $collection Collection.
	 * @return array<int,array<string,mixed>>
	 */
	public function live( string $collection ): array {
		return array_values( array_filter( $this->rows[ $collection ], static fn( array $row ): bool => empty( $row['deleted'] ) ) );
	}

	/**
	 * @param string               $method  Method.
	 * @param string               $path    Path.
	 * @param array<string,mixed>  $query   Query.
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Headers.
	 */
	protected function route( string $method, string $path, array $query, array $body, array $headers ): HttpResponse {
		if ( self::KEY !== ( $headers['x-api-token'] ?? '' ) ) {
			return $this->json( array( 'success' => false, 'error' => 'unauthorized access', 'errorCode' => 401 ), 401 );
		}
		if ( '/api/v1/users/me' === $path ) {
			return $this->json( array( 'success' => true, 'data' => array( 'id' => 1, 'name' => 'Sync User', 'company_name' => 'Example Trading LLC', 'company_domain' => 'example', 'default_currency' => 'AED' ) ) );
		}
		if ( '/api/v2/itemSearch' === $path ) {
			return $this->itemSearch( $query );
		}
		if ( '/api/v1/notes' === $path ) {
			return 'GET' === $method ? $this->listNotes( $query ) : $this->createNote( $body );
		}
		if ( 1 === preg_match( '~^/api/v2/deals/(\d+)/products$~', $path, $m ) ) {
			return 'GET' === $method ? $this->json( array( 'success' => true, 'data' => $this->dealProducts[ $m[1] ] ?? array(), 'additional_data' => array( 'next_cursor' => null ) ) ) : $this->addDealProduct( $m[1], $body );
		}
		if ( 1 === preg_match( '~^/api/v2/(deals|persons|organizations|products|pipelines)/(\d+)$~', $path, $m ) ) {
			return $this->one( $method, $m[1], $m[2], $body );
		}
		if ( 1 === preg_match( '~^/api/v2/(deals|persons|organizations|products|pipelines)$~', $path, $m ) ) {
			if ( in_array( $m[1], $this->denied, true ) ) {
				return $this->json( array( 'success' => false, 'error' => 'You do not have permission to access this resource', 'errorCode' => 403 ), 403 );
			}
			return 'GET' === $method ? $this->listing( $m[1], $query ) : $this->create( $m[1], $body );
		}
		return $this->json( array( 'success' => false, 'error' => 'Not found', 'errorCode' => 404 ), 404 );
	}

	/**
	 * @param string $kind    Kind.
	 * @param int    $seconds Seconds.
	 */
	protected function failureResponse( string $kind, int $seconds ): HttpResponse {
		return match ( $kind ) {
			'rate_limit'   => $this->json( array( 'success' => false, 'error' => 'Too many requests' ), 429, array( 'retry-after' => (string) $seconds ) ),
			'server_error' => $this->json( array( 'success' => false, 'error' => 'internal' ), 500 ),
			'auth'         => $this->json( array( 'success' => false, 'error' => 'unauthorized access', 'errorCode' => 401 ), 401 ),
			default        => $this->json( array( 'weird' => true ), 418 ),
		};
	}

	/**
	 * @param string              $collection Collection.
	 * @param array<string,mixed> $data       Fields.
	 */
	private function insert( string $collection, array $data ): string {
		$id = (string) ++$this->counter;
		$this->rows[ $collection ][ $id ] = $data + array( 'id' => (int) $id, 'add_time' => gmdate( 'Y-m-d\TH:i:s\Z', 1_800_000_000 + $this->counter ), 'update_time' => gmdate( 'Y-m-d\TH:i:s\Z', 1_800_000_000 + $this->counter ), 'deleted' => false );
		return $id;
	}

	/**
	 * @param string              $collection Collection.
	 * @param array<string,mixed> $body       Body.
	 */
	private function create( string $collection, array $body ): HttpResponse {
		if ( 'deals' === $collection ) {
			if ( empty( $body['title'] ) ) {
				return $this->invalid( 'title is required' );
			}
			if ( isset( $body['currency'] ) && ! in_array( $body['currency'], $this->currencies, true ) ) {
				return $this->invalid( 'Currency ' . $body['currency'] . ' is not supported' );
			}
			if ( empty( $body['person_id'] ) || ! isset( $this->rows['persons'][ (string) $body['person_id'] ] ) ) {
				return $this->invalid( 'person_id does not exist' );
			}
		}
		if ( 'persons' === $collection && empty( $body['name'] ) ) {
			return $this->invalid( 'name is required' );
		}
		$id = $this->insert( $collection, $body );
		return $this->json( array( 'success' => true, 'data' => $this->rows[ $collection ][ $id ] ), 201 );
	}

	/**
	 * @param string              $method     Method.
	 * @param string              $collection Collection.
	 * @param string              $id         Id.
	 * @param array<string,mixed> $body       Body.
	 */
	private function one( string $method, string $collection, string $id, array $body ): HttpResponse {
		if ( ! isset( $this->rows[ $collection ][ $id ] ) || ! empty( $this->rows[ $collection ][ $id ]['deleted'] ) ) {
			return $this->json( array( 'success' => false, 'error' => 'Not found', 'errorCode' => 404 ), 404 );
		}
		if ( 'DELETE' === $method ) {
			$this->rows[ $collection ][ $id ]['deleted'] = true;
			return $this->json( array( 'success' => true, 'data' => array( 'id' => (int) $id ) ) );
		}
		if ( 'PATCH' === $method ) {
			if ( $this->lockValue ) {
				unset( $body['value'] );
			}
			$this->rows[ $collection ][ $id ] = $body + $this->rows[ $collection ][ $id ];
		}
		return $this->json( array( 'success' => true, 'data' => $this->rows[ $collection ][ $id ] ) );
	}

	/**
	 * @param string              $collection Collection.
	 * @param array<string,mixed> $query      Query.
	 */
	private function listing( string $collection, array $query ): HttpResponse {
		$rows = $this->live( $collection );
		if ( isset( $query['updated_since'] ) ) {
			$since = strtotime( (string) $query['updated_since'] );
			$rows  = array_values( array_filter( $rows, static fn( array $row ): bool => strtotime( (string) $row['update_time'] ) >= $since ) );
		}
		$limit  = min( 500, (int) ( $query['limit'] ?? 100 ) );
		$offset = (int) ( $query['cursor'] ?? 0 );
		$slice  = array_slice( $rows, $offset, $limit );
		return $this->json( array( 'success' => true, 'data' => $slice, 'additional_data' => array( 'next_cursor' => $offset + $limit < count( $rows ) ? (string) ( $offset + $limit ) : null ) ) );
	}

	/**
	 * @param array<string,mixed> $query Query.
	 */
	private function itemSearch( array $query ): HttpResponse {
		$term   = strtolower( (string) ( $query['term'] ?? '' ) );
		$type   = (string) ( $query['item_types'] ?? '' );
		$fields = explode( ',', (string) ( $query['fields'] ?? '' ) );
		$exact  = 'true' === ( $query['exact_match'] ?? 'false' );
		$map    = array( 'deal' => 'deals', 'person' => 'persons', 'organization' => 'organizations', 'product' => 'products' );
		$hits   = array();
		foreach ( $this->live( $map[ $type ] ?? 'deals' ) as $row ) {
			foreach ( $fields as $field ) {
				$values = 'email' === $field ? array_map( static fn( array $e ): string => (string) $e['value'], (array) ( $row['emails'] ?? array() ) ) : array( (string) ( $row[ $field ] ?? '' ) );
				foreach ( $values as $value ) {
					$value = strtolower( $value );
					if ( '' !== $value && ( $exact ? $value === $term : str_contains( $value, $term ) ) ) {
						$hits[ $row['id'] ] = $row;
					}
				}
			}
		}
		$offset = (int) ( $query['cursor'] ?? 0 );
		$limit  = (int) ( $query['limit'] ?? 100 );
		$page   = array_slice( array_values( $hits ), $offset, $limit );
		return $this->json(
			array(
				'success'         => true,
				'data'            => array( 'items' => array_map( static fn( array $row ): array => array( 'result_score' => 1.0, 'item' => $row ), $page ) ),
				'additional_data' => array( 'next_cursor' => $offset + $limit < count( $hits ) ? (string) ( $offset + $limit ) : null ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $body Body.
	 */
	private function createNote( array $body ): HttpResponse {
		if ( empty( $body['content'] ) ) {
			return $this->invalid( 'content is required' );
		}
		$id = $this->insert( 'notes', $body );
		return $this->json( array( 'success' => true, 'data' => $this->rows['notes'][ $id ] ), 201 );
	}

	/**
	 * @param array<string,mixed> $query Query.
	 */
	private function listNotes( array $query ): HttpResponse {
		$rows = array_values( array_filter( $this->live( 'notes' ), static fn( array $row ): bool => ! isset( $query['deal_id'] ) || (string) $row['deal_id'] === (string) $query['deal_id'] ) );
		$start = (int) ( $query['start'] ?? 0 );
		$limit = (int) ( $query['limit'] ?? 100 );
		$more  = $start + $limit < count( $rows );
		return $this->json( array( 'success' => true, 'data' => array_slice( $rows, $start, $limit ), 'additional_data' => array( 'pagination' => array( 'start' => $start, 'limit' => $limit, 'more_items_in_collection' => $more, 'next_start' => $start + $limit ) ) ) );
	}

	/**
	 * @param string              $dealId Deal id.
	 * @param array<string,mixed> $body   Body.
	 */
	private function addDealProduct( string $dealId, array $body ): HttpResponse {
		if ( empty( $body['product_id'] ) || ! isset( $this->rows['products'][ (string) $body['product_id'] ] ) ) {
			return $this->invalid( 'product_id does not exist' );
		}
		$this->dealProducts[ $dealId ][] = $body;
		if ( $this->valueFromProducts ) {
			$sum = 0.0;
			foreach ( $this->dealProducts[ $dealId ] as $row ) {
				$sum += (float) $row['item_price'] * (int) $row['quantity'];
			}
			$this->rows['deals'][ $dealId ]['value'] = round( $sum, 2 );
		}
		return $this->json( array( 'success' => true, 'data' => $body ), 201 );
	}

	/**
	 * @param string $reason Reason.
	 */
	private function invalid( string $reason ): HttpResponse {
		return $this->json( array( 'success' => false, 'error' => 'Validation failed', 'error_info' => $reason, 'errorCode' => 400 ), 400 );
	}
}
