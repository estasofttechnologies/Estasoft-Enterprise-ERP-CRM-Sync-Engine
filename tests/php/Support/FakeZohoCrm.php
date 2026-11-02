<?php
/**
 * A small in-memory Zoho CRM (with its accounts server) for the connector tests.
 *
 * Shapes follow the Zoho CRM v8 developer pages: POST /crm/v8/{module} with data[], answers {data:[{code, details, message, status}]},
 * search with criteria "(Field:equals:value)" or email, 204 for no match, related lists, COQL, credits header, token endpoint.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

final class FakeZohoCrm extends FakeSalesServer {

	public const ACCESS  = 'access-token-synthetic-0001';
	public const REFRESH = 'refresh-token-synthetic-0001';
	public const FRESH   = 'access-token-synthetic-0002';
	public const SECRET  = 'client-secret-synthetic-0001';

	/**
	 * Rows by module: id => row.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $rows = array(
		'Deals'    => array(),
		'Contacts' => array(),
		'Accounts' => array(),
		'Notes'    => array(),
		'Products' => array(),
	);

	/**
	 * Whether the organisation uses several currencies (the Currency field exists).
	 */
	public bool $multiCurrency = false;

	/**
	 * Whether Deals need a Pipeline.
	 */
	public bool $requirePipeline = false;

	/**
	 * Whether a second contact with the same email is refused as DUPLICATE_DATA.
	 */
	public bool $uniqueEmail = false;

	/**
	 * When true the contact search finds nothing (the index lags), so an insert can meet a duplicate.
	 */
	public bool $searchBlind = false;

	/**
	 * Credits left, sent as a header when set.
	 */
	public ?int $credits = null;

	/**
	 * Modules the token has no scope for.
	 *
	 * @var array<int,string>
	 */
	public array $denied = array();

	/**
	 * Access tokens the API accepts.
	 *
	 * @var array<int,string>
	 */
	public array $validTokens = array( self::ACCESS, self::FRESH );

	/**
	 * Token requests seen.
	 */
	public int $tokenCalls = 0;

	/**
	 * When true the token endpoint refuses every refresh (the sign-in was revoked).
	 */
	public bool $refreshRevoked = false;

	private int $counter = 4000;

	/**
	 * Add a deal as an earlier pass or the merchant would.
	 *
	 * @param string $name   Deal name.
	 * @param float  $amount Amount.
	 */
	public function addDeal( string $name, float $amount ): string {
		return $this->insertRow( 'Deals', array( 'Deal_Name' => $name, 'Amount' => $amount, 'Stage' => 'Qualification' ) );
	}

	/**
	 * Add a product the way a merchant would.
	 *
	 * @param string $code Product code (the SKU).
	 * @param string $name Name.
	 */
	public function addProduct( string $code, string $name ): string {
		return $this->insertRow( 'Products', array( 'Product_Code' => $code, 'Product_Name' => $name ) );
	}

	/**
	 * Rows of a module.
	 *
	 * @param string $module Module.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $module ): array {
		return array_values( array_filter( $this->rows[ $module ], static fn( array $row ): bool => empty( $row['_deleted'] ) ) );
	}

	/**
	 * A refused sign-in is not a one-off: the tokens stop working and the refresh is refused too.
	 *
	 * @param string $kind        Kind.
	 * @param int    $seconds     Seconds.
	 * @param int    $afterWrites Writes that succeed first.
	 */
	public function failNextWrite( string $kind, int $seconds = 0, int $afterWrites = 0 ): void {
		if ( 'auth' === $kind ) {
			$this->validTokens    = array();
			$this->refreshRevoked = true;
			return;
		}
		parent::failNextWrite( $kind, $seconds, $afterWrites );
	}

	/**
	 * @param string               $method  Method.
	 * @param string               $path    Path.
	 * @param array<string,mixed>  $query   Query.
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Headers.
	 */
	protected function route( string $method, string $path, array $query, array $body, array $headers ): HttpResponse {
		if ( '/oauth/v2/token' === $path ) {
			++$this->tokenCalls;
			return ! $this->refreshRevoked && self::REFRESH === ( $body['refresh_token'] ?? '' ) && self::SECRET === ( $body['client_secret'] ?? '' )
				? $this->json( array( 'access_token' => self::FRESH, 'api_domain' => 'https://www.zohoapis.com', 'token_type' => 'Bearer', 'expires_in' => 3600 ) )
				: $this->json( array( 'error' => 'invalid_code' ), 400 );
		}
		$extra = null === $this->credits ? array() : array( 'X-API-CREDITS-REMAINING' => (string) $this->credits );
		$token = str_starts_with( $headers['authorization'] ?? '', 'Zoho-oauthtoken ' ) ? substr( $headers['authorization'], 16 ) : '';
		if ( ! in_array( $token, $this->validTokens, true ) ) {
			return $this->json( array( 'code' => 'INVALID_TOKEN', 'details' => new \stdClass(), 'message' => 'invalid oauth token', 'status' => 'error' ), 401 );
		}
		if ( '/crm/v8/org' === $path ) {
			return $this->json( array( 'org' => array( array( 'id' => '9000001', 'company_name' => 'Example Trading LLC', 'iso_code' => 'AED', 'currency' => 'AED' ) ) ), 200, $extra );
		}
		if ( '/crm/v8/coql' === $path ) {
			return $this->coql( (string) ( $body['select_query'] ?? '' ), $extra );
		}
		if ( 1 === preg_match( '~^/crm/v8/(\w+)/search$~', $path, $m ) ) {
			return $this->denial( $m[1] ) ?? $this->search( $m[1], $query, $extra );
		}
		if ( 1 === preg_match( '~^/crm/v8/Deals/(\d+)/Notes$~', $path, $m ) ) {
			return $this->relatedNotes( $m[1], $query, $extra );
		}
		if ( 1 === preg_match( '~^/crm/v8/(\w+)/(\d+)$~', $path, $m ) ) {
			return $this->record( $method, $m[1], $m[2], $body, $extra );
		}
		if ( 1 === preg_match( '~^/crm/v8/(\w+)$~', $path, $m ) ) {
			return $this->denial( $m[1] ) ?? $this->collection( $method, $m[1], $query, $body, $extra );
		}
		return $this->json( array( 'code' => 'INVALID_URL_PATTERN', 'message' => 'Please check if the URL trying to access is a correct one', 'status' => 'error' ), 404 );
	}

	/**
	 * @param string $kind    Kind.
	 * @param int    $seconds Seconds.
	 */
	protected function failureResponse( string $kind, int $seconds ): HttpResponse {
		return match ( $kind ) {
			'rate_limit'   => $this->json( array( 'code' => 'TOO_MANY_REQUESTS', 'message' => 'too many requests', 'status' => 'error' ), 429, array( 'retry-after' => (string) $seconds ) ),
			'server_error' => $this->json( array( 'code' => 'INTERNAL_ERROR', 'message' => 'internal error', 'status' => 'error' ), 500 ),
			'auth'         => $this->json( array( 'code' => 'INVALID_TOKEN', 'message' => 'invalid oauth token', 'status' => 'error' ), 401 ),
			default        => $this->json( array( 'weird' => true ), 418 ),
		};
	}

	/**
	 * @param string $module Module.
	 */
	private function denial( string $module ): ?HttpResponse {
		return in_array( $module, $this->denied, true )
			? $this->json( array( 'code' => 'OAUTH_SCOPE_MISMATCH', 'message' => 'invalid oauth scope to access this API', 'status' => 'error' ), 401 )
			: null;
	}

	/**
	 * @param string              $module Module.
	 * @param array<string,mixed> $data   Fields.
	 */
	private function insertRow( string $module, array $data ): string {
		$id = (string) ++$this->counter;
		$this->rows[ $module ][ $id ] = $data + array( 'id' => $id, 'Created_Time' => gmdate( 'Y-m-d\TH:i:sP', 1_800_000_000 + $this->counter ), '_deleted' => false );
		return $id;
	}

	/**
	 * @param string               $module Module.
	 * @param string               $method Method.
	 * @param array<string,mixed>  $query  Query.
	 * @param array<string,mixed>  $body   Body.
	 * @param array<string,string> $extra  Headers.
	 */
	private function collection( string $method, string $module, array $query, array $body, array $extra ): HttpResponse {
		if ( 'GET' === $method ) {
			$rows = array_slice( $this->all( $module ), 0, (int) ( $query['per_page'] ?? 200 ) );
			return array() === $rows ? new HttpResponse( 204, '', $extra ) : $this->json( array( 'data' => $this->visible( $rows ) ), 200, $extra );
		}
		if ( 'DELETE' === $method ) {
			foreach ( explode( ',', (string) ( $query['ids'] ?? '' ) ) as $id ) {
				if ( isset( $this->rows[ $module ][ $id ] ) ) {
					$this->rows[ $module ][ $id ]['_deleted'] = true;
				}
			}
			return $this->json( array( 'data' => array( array( 'code' => 'SUCCESS', 'details' => array( 'id' => $query['ids'] ?? '' ), 'message' => 'record deleted', 'status' => 'success' ) ) ), 200, $extra );
		}
		$record = (array) ( $body['data'][0] ?? array() );
		$error  = $this->validate( $module, $record );
		if ( null !== $error ) {
			return $this->json( array( 'data' => array( $error ) ), 400, $extra );
		}
		$id = $this->insertRow( $module, $record + ( 'Notes' === $module ? array( 'Parent' => (string) ( $record['Parent_Id']['id'] ?? '' ) ) : array() ) );
		return $this->json( array( 'data' => array( array( 'code' => 'SUCCESS', 'details' => array( 'id' => $id, 'Created_Time' => $this->rows[ $module ][ $id ]['Created_Time'] ), 'message' => 'record added', 'status' => 'success' ) ) ), 201, $extra );
	}

	/**
	 * @param string              $module Module.
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>|null
	 */
	private function validate( string $module, array $record ): ?array {
		$bad = static fn( string $code, string $field, string $message ): array => array( 'code' => $code, 'details' => array( 'api_name' => $field ), 'message' => $message, 'status' => 'error' );
		if ( 'Deals' === $module ) {
			foreach ( array( 'Deal_Name', 'Stage' ) as $required ) {
				if ( empty( $record[ $required ] ) ) {
					return $bad( 'MANDATORY_NOT_FOUND', $required, 'required field not found' );
				}
			}
			if ( $this->requirePipeline && empty( $record['Pipeline'] ) ) {
				return $bad( 'MANDATORY_NOT_FOUND', 'Pipeline', 'required field not found' );
			}
			if ( isset( $record['Currency'] ) && ! $this->multiCurrency ) {
				return $bad( 'INVALID_DATA', 'Currency', 'invalid data' );
			}
			if ( isset( $record['Stage'] ) && ! in_array( $record['Stage'], array( 'Qualification', 'Closed Won', 'Negotiation', 'Won Stage' ), true ) ) {
				return $bad( 'INVALID_DATA', 'Stage', 'invalid data' );
			}
		}
		if ( 'Contacts' === $module ) {
			if ( empty( $record['Last_Name'] ) ) {
				return $bad( 'MANDATORY_NOT_FOUND', 'Last_Name', 'required field not found' );
			}
			if ( $this->uniqueEmail ) {
				foreach ( $this->all( 'Contacts' ) as $row ) {
					if ( 0 === strcasecmp( (string) ( $row['Email'] ?? '-' ), (string) ( $record['Email'] ?? '' ) ) ) {
						return array( 'code' => 'DUPLICATE_DATA', 'details' => array( 'api_name' => 'Email', 'id' => $row['id'] ), 'message' => 'duplicate data', 'status' => 'error' );
					}
				}
			}
		}
		if ( 'Notes' === $module && empty( $record['Parent_Id']['id'] ) ) {
			return $bad( 'MANDATORY_NOT_FOUND', 'Parent_Id', 'required field not found' );
		}
		return null;
	}

	/**
	 * @param string               $module Module.
	 * @param string               $id     Id.
	 * @param array<string,mixed>  $body   Body.
	 * @param array<string,string> $extra  Headers.
	 */
	private function record( string $method, string $module, string $id, array $body, array $extra ): HttpResponse {
		if ( ! isset( $this->rows[ $module ][ $id ] ) || ! empty( $this->rows[ $module ][ $id ]['_deleted'] ) ) {
			return new HttpResponse( 204, '', $extra );
		}
		if ( 'PUT' === $method ) {
			$this->rows[ $module ][ $id ] = (array) ( $body['data'][0] ?? array() ) + $this->rows[ $module ][ $id ];
		}
		return $this->json( array( 'data' => $this->visible( array( $this->rows[ $module ][ $id ] ) ) ), 200, $extra );
	}

	/**
	 * @param string               $dealId Deal id.
	 * @param array<string,mixed>  $query  Query.
	 * @param array<string,string> $extra  Headers.
	 */
	private function relatedNotes( string $dealId, array $query, array $extra ): HttpResponse {
		$rows = array_values( array_filter( $this->all( 'Notes' ), static fn( array $row ): bool => (string) ( $row['Parent'] ?? '' ) === $dealId ) );
		return array() === $rows ? new HttpResponse( 204, '', $extra ) : $this->json( array( 'data' => $this->visible( $rows ) ), 200, $extra );
	}

	/**
	 * @param string               $module Module.
	 * @param array<string,mixed>  $query  Query.
	 * @param array<string,string> $extra  Headers.
	 */
	private function search( string $module, array $query, array $extra ): HttpResponse {
		$rows = $this->searchBlind && 'Contacts' === $module ? array() : $this->all( $module );
		if ( isset( $query['email'] ) ) {
			$wanted = strtolower( (string) $query['email'] );
			$rows   = array_values( array_filter( $rows, static fn( array $row ): bool => strtolower( (string) ( $row['Email'] ?? '' ) ) === $wanted ) );
		} elseif ( isset( $query['word'] ) ) {
			$needle = strtolower( (string) $query['word'] );
			$rows   = array_values( array_filter( $rows, static fn( array $row ): bool => str_contains( strtolower( (string) wp_json_encode( array_values( $row ) ) ), $needle ) ) );
		} elseif ( isset( $query['criteria'] ) ) {
			preg_match( '/^\((\w+):equals:(.*)\)$/s', (string) $query['criteria'], $m );
			$field = $m[1] ?? '';
			$value = strtolower( (string) preg_replace( '/\\\\(.)/', '$1', $m[2] ?? '' ) );
			$rows  = array_values( array_filter( $rows, static fn( array $row ): bool => strtolower( (string) ( $row[ $field ] ?? '' ) ) === $value ) );
		}
		if ( array() === $rows ) {
			return new HttpResponse( 204, '', $extra );
		}
		$per    = (int) ( $query['per_page'] ?? 200 );
		$page   = (int) ( $query['page'] ?? 1 );
		$slice  = array_slice( $rows, ( $page - 1 ) * $per, $per );
		return $this->json( array( 'data' => $this->visible( $slice ), 'info' => array( 'more_records' => $page * $per < count( $rows ), 'page' => $page, 'per_page' => $per, 'count' => count( $slice ) ) ), 200, $extra );
	}

	/**
	 * @param string               $query COQL text.
	 * @param array<string,string> $extra Headers.
	 */
	private function coql( string $query, array $extra ): HttpResponse {
		preg_match( "/where \((\w+) like '(\w+-)%' and Created_Time >= '([^']+)'\)/i", $query, $m );
		preg_match( '/limit (\d+) offset (\d+)/i', $query, $l );
		$field  = $m[1] ?? 'Deal_Name';
		$prefix = $m[2] ?? '';
		$since  = strtotime( $m[3] ?? '1970-01-01' );
		$rows   = array_values( array_filter( $this->all( 'Deals' ), static fn( array $row ): bool => str_starts_with( (string) ( $row[ $field ] ?? '' ), $prefix ) && strtotime( (string) $row['Created_Time'] ) >= $since ) );
		if ( array() === $rows ) {
			return new HttpResponse( 204, '', $extra );
		}
		$limit  = (int) ( $l[1] ?? 200 );
		$offset = (int) ( $l[2] ?? 0 );
		$slice  = array_slice( $rows, $offset, $limit );
		return $this->json( array( 'data' => array_map( fn( array $row ): array => array( 'id' => $row['id'], $field => $row[ $field ], 'Amount' => $row['Amount'] ?? null ), $slice ), 'info' => array( 'more_records' => $offset + $limit < count( $rows ), 'count' => count( $slice ) ) ), 200, $extra );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function visible( array $rows ): array {
		return array_map( static fn( array $row ): array => array_diff_key( $row, array( '_deleted' => 1, 'Parent' => 1 ) ), $rows );
	}
}
