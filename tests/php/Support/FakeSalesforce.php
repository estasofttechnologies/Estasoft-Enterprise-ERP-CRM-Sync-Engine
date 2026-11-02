<?php
/**
 * A small in-memory Salesforce (with its login host) for the connector tests.
 *
 * Shapes follow the Salesforce REST API guide: /services/data/{version}/query with {totalSize, done, nextRecordsUrl, records[{attributes, ...}]},
 * sObject create answering {id, success, errors}, error bodies as an array of {message, errorCode, fields}, describe with fields[], the
 * Sforce-Limit-Info header, token endpoint. SOQL is parsed only as far as the connector writes it: equality, LIKE, >=, IN, OR groups,
 * ORDER BY CreatedDate and LIMIT.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

final class FakeSalesforce extends FakeSalesServer {

	public const ACCESS  = 'synthetic-access-token-0001';
	public const REFRESH = 'synthetic-refresh-token-0001';
	public const FRESH   = 'synthetic-access-token-0002';
	public const SECRET  = 'consumer-secret-synthetic-0001';
	public const VERSION = 'v66.0';

	/**
	 * Rows by object: id => row.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $rows = array();

	/**
	 * Whether the org uses several currencies (CurrencyIsoCode exists).
	 */
	public bool $multiCurrency = false;

	/**
	 * Records per query page.
	 */
	public int $pageSize = 2000;

	/**
	 * Calls left in the allocation, sent as Sforce-Limit-Info when set.
	 */
	public ?int $remaining = null;

	/**
	 * Objects the user may not create.
	 *
	 * @var array<int,string>
	 */
	public array $notCreateable = array();

	/**
	 * Whether duplicate rules refuse a contact unless Sforce-Duplicate-Rule-Header allows saving.
	 */
	public bool $duplicateRules = false;

	/**
	 * Minor units added to the Amount of every Opportunity, to force a mismatch.
	 */
	public float $amountDrift = 0.0;

	/**
	 * Access tokens the API accepts.
	 *
	 * @var array<int,string>
	 */
	public array $validTokens = array( self::ACCESS, self::FRESH );

	/**
	 * Token requests seen and their bodies.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $tokenRequests = array();

	/**
	 * When true the token endpoint refuses every grant.
	 */
	public bool $refreshRevoked = false;

	private int $counter = 0;

	public function __construct() {
		foreach ( array( 'Organization', 'Account', 'Contact', 'Opportunity', 'OpportunityLineItem', 'OpportunityContactRole', 'Task', 'Product2', 'Pricebook2', 'PricebookEntry' ) as $object ) {
			$this->rows[ $object ] = array();
		}
		$this->put( 'Organization', array( 'Name' => 'Example Trading LLC' ), '00D000000000001' );
		$this->put( 'Pricebook2', array( 'Name' => 'Standard Price Book', 'IsStandard' => true ), '01s000000000001' );
	}

	/**
	 * Add a product with a standard price book entry.
	 *
	 * @param string $code Product code (the SKU).
	 * @param string $name Name.
	 * @return string The price book entry id.
	 */
	public function addProduct( string $code, string $name ): string {
		$product = $this->put( 'Product2', array( 'Name' => $name, 'ProductCode' => $code, 'IsActive' => true ) );
		return $this->put( 'PricebookEntry', array( 'Pricebook2Id' => '01s000000000001', 'Product2Id' => $product, 'Product2' => array( 'ProductCode' => $code, 'Name' => $name ), 'IsActive' => true, 'UnitPrice' => 10.0 ) );
	}

	/**
	 * Add an Opportunity as an earlier pass or the merchant would.
	 *
	 * @param string $name   Name.
	 * @param float  $amount Amount.
	 */
	public function addOpportunity( string $name, float $amount ): string {
		return $this->put( 'Opportunity', array( 'Name' => $name, 'Amount' => $amount, 'StageName' => 'Prospecting', 'CloseDate' => '2026-11-01' ) );
	}

	/**
	 * Rows of an object.
	 *
	 * @param string $object Object.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( string $object ): array {
		return array_values( array_filter( $this->rows[ $object ], static fn( array $row ): bool => empty( $row['_deleted'] ) ) );
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
		if ( '/services/oauth2/token' === $path ) {
			$this->tokenRequests[] = $body;
			if ( $this->refreshRevoked || ( 'refresh_token' === ( $body['grant_type'] ?? '' ) && self::SECRET !== ( $body['client_secret'] ?? '' ) ) ) {
				return $this->json( array( 'error' => 'invalid_client', 'error_description' => 'invalid client credentials' ), 400 );
			}
			$ok = ( 'refresh_token' === ( $body['grant_type'] ?? '' ) && self::REFRESH === ( $body['refresh_token'] ?? '' ) ) || str_contains( (string) ( $body['grant_type'] ?? '' ), 'jwt-bearer' );
			return $ok
				? $this->json( array( 'access_token' => self::FRESH, 'instance_url' => 'https://example.my.salesforce.com', 'token_type' => 'Bearer', 'issued_at' => '1800000000000' ) )
				: $this->json( array( 'error' => 'invalid_grant', 'error_description' => 'expired access/refresh token' ), 400 );
		}
		$extra = null === $this->remaining ? array() : array( 'Sforce-Limit-Info' => 'api-usage=' . ( 15000 - $this->remaining ) . '/15000' );
		$token = str_starts_with( $headers['authorization'] ?? '', 'Bearer ' ) ? substr( $headers['authorization'], 7 ) : '';
		if ( ! in_array( $token, $this->validTokens, true ) ) {
			return $this->json( array( array( 'message' => 'Session expired or invalid', 'errorCode' => 'INVALID_SESSION_ID' ) ), 401 );
		}
		if ( '/services/data/' === $path ) {
			return $this->json( array( array( 'label' => 'Spring \'26', 'url' => '/services/data/v66.0', 'version' => '66.0' ), array( 'label' => 'Summer \'26', 'url' => '/services/data/v67.0', 'version' => '67.0' ) ), 200, $extra );
		}
		$base = '/services/data/' . self::VERSION;
		if ( $path === $base . '/query' ) {
			return $this->query( (string) ( $query['q'] ?? '' ), 0, $extra );
		}
		if ( 1 === preg_match( '~^' . preg_quote( $base, '~' ) . '/query/more-(\d+)$~', $path, $m ) ) {
			return $this->query( (string) ( $this->lastQuery ?? '' ), (int) $m[1], $extra );
		}
		if ( 1 === preg_match( '~^' . preg_quote( $base, '~' ) . '/sobjects/(\w+)/describe$~', $path, $m ) ) {
			return $this->describe( $m[1], $extra );
		}
		if ( 1 === preg_match( '~^' . preg_quote( $base, '~' ) . '/sobjects/(\w+)$~', $path, $m ) && 'POST' === $method ) {
			return $this->create( $m[1], $body, $headers, $extra );
		}
		if ( 1 === preg_match( '~^' . preg_quote( $base, '~' ) . '/sobjects/(\w+)/(\w+)$~', $path, $m ) ) {
			return $this->one( $method, $m[1], $m[2], $body, $extra );
		}
		return $this->json( array( array( 'message' => 'The requested resource does not exist', 'errorCode' => 'NOT_FOUND' ) ), 404, $extra );
	}

	/**
	 * @param string $kind    Kind.
	 * @param int    $seconds Seconds.
	 */
	protected function failureResponse( string $kind, int $seconds ): HttpResponse {
		return match ( $kind ) {
			'rate_limit'   => $this->json( array( array( 'message' => 'TotalRequests Limit exceeded.', 'errorCode' => 'REQUEST_LIMIT_EXCEEDED' ) ), 403, array( 'retry-after' => (string) $seconds ) ),
			'server_error' => $this->json( array( array( 'message' => 'An unexpected error occurred', 'errorCode' => 'UNKNOWN_EXCEPTION' ) ), 500 ),
			default        => $this->json( array( 'weird' => true ), 418 ),
		};
	}

	private ?string $lastQuery = null;

	/**
	 * @param string              $object Object.
	 * @param array<string,mixed> $fields Fields.
	 * @param string|null         $id     Fixed id.
	 */
	private function put( string $object, array $fields, ?string $id = null ): string {
		$id ??= sprintf( '%s%012d', substr( md5( $object ), 0, 3 ), ++$this->counter );
		$this->rows[ $object ][ $id ] = $fields + array( 'Id' => $id, 'CreatedDate' => gmdate( 'Y-m-d\TH:i:s.000\Z', 1_800_000_000 + $this->counter ), '_deleted' => false );
		return $id;
	}

	/**
	 * @param string               $object Object.
	 * @param array<string,string> $extra  Headers.
	 */
	private function describe( string $object, array $extra ): HttpResponse {
		if ( ! isset( $this->rows[ $object ] ) ) {
			return $this->json( array( array( 'message' => 'The requested resource does not exist', 'errorCode' => 'NOT_FOUND' ) ), 404, $extra );
		}
		$names = array( 'Id', 'Name', 'Amount', 'StageName', 'CloseDate' );
		if ( $this->multiCurrency ) {
			$names[] = 'CurrencyIsoCode';
		}
		return $this->json( array( 'name' => $object, 'createable' => ! in_array( $object, $this->notCreateable, true ), 'fields' => array_map( static fn( string $name ): array => array( 'name' => $name ), $names ) ), 200, $extra );
	}

	/**
	 * @param string               $soql   SOQL.
	 * @param int                  $offset Offset of the page.
	 * @param array<string,string> $extra  Headers.
	 */
	private function query( string $soql, int $offset, array $extra ): HttpResponse {
		$this->lastQuery = $soql;
		if ( 1 !== preg_match( '/^SELECT (.+?) FROM (\w+)(?: WHERE (.+?))?(?: ORDER BY (\w+) (ASC|DESC))?(?: LIMIT (\d+))?$/s', trim( $soql ), $m ) ) {
			return $this->json( array( array( 'message' => 'unexpected token in query', 'errorCode' => 'MALFORMED_QUERY' ) ), 400, $extra );
		}
		$object = $m[2];
		if ( ! isset( $this->rows[ $object ] ) ) {
			return $this->json( array( array( 'message' => "sObject type '{$object}' is not supported.", 'errorCode' => 'INVALID_TYPE' ) ), 400, $extra );
		}
		$rows = $this->all( $object );
		if ( ! empty( $m[3] ) ) {
			foreach ( $this->conditions( $m[3] ) as $condition ) {
				$rows = array_values( array_filter( $rows, fn( array $row ): bool => $this->matches( $row, $condition ) ) );
			}
		}
		if ( ! empty( $m[4] ) && 'DESC' === ( $m[5] ?? 'ASC' ) ) {
			$rows = array_reverse( $rows );
		}
		if ( ! empty( $m[6] ) ) {
			$rows = array_slice( $rows, 0, (int) $m[6] );
		}
		$page    = array_slice( $rows, $offset, $this->pageSize );
		$fields  = array_map( 'trim', explode( ',', $m[1] ) );
		$records = array();
		foreach ( $page as $row ) {
			$record = array( 'attributes' => array( 'type' => $object, 'url' => "/services/data/v66.0/sobjects/{$object}/{$row['Id']}" ) );
			foreach ( $fields as $field ) {
				$this->assign( $record, $field, $row );
			}
			$records[] = $record;
		}
		$more = $offset + $this->pageSize < count( $rows );
		return $this->json( array( 'totalSize' => count( $rows ), 'done' => ! $more ) + ( $more ? array( 'nextRecordsUrl' => '/services/data/v66.0/query/more-' . ( $offset + $this->pageSize ) ) : array() ) + array( 'records' => $records ), 200, $extra );
	}

	/**
	 * @param array<string,mixed> $record Record being built.
	 * @param string              $field  Field path such as Product2.ProductCode.
	 * @param array<string,mixed> $row    Source row.
	 */
	private function assign( array &$record, string $field, array $row ): void {
		if ( str_contains( $field, '.' ) ) {
			list( $parent, $child ) = explode( '.', $field, 2 );
			$record[ $parent ][ $child ] = $row[ $parent ][ $child ] ?? null;
			return;
		}
		$record[ $field ] = $row[ $field ] ?? null;
	}

	/**
	 * @param string $where WHERE text.
	 * @return array<int,string>
	 */
	private function conditions( string $where ): array {
		return array_map( 'trim', preg_split( '/ AND (?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $where ) ?: array() );
	}

	/**
	 * @param array<string,mixed> $row       Row.
	 * @param string              $condition Condition.
	 */
	private function matches( array $row, string $condition ): bool {
		if ( str_starts_with( $condition, '(' ) && str_ends_with( $condition, ')' ) ) {
			foreach ( preg_split( '/ OR /', substr( $condition, 1, -1 ) ) ?: array() as $part ) {
				if ( $this->matches( $row, trim( $part ) ) ) {
					return true;
				}
			}
			return false;
		}
		if ( 1 === preg_match( '/^([\w.]+) (=|LIKE|>=) (.+)$/s', $condition, $m ) ) {
			$have = $this->value( $row, $m[1] );
			$want = $this->literal( $m[3] );
			return match ( $m[2] ) {
				'='    => is_bool( $have ) ? ( $have ? 'true' : 'false' ) === $want : 0 === strcasecmp( (string) $have, $want ),
				'LIKE' => $this->like( (string) $have, $want ),
				default => strtotime( (string) $have ) >= strtotime( $want ),
			};
		}
		if ( 1 === preg_match( '/^([\w.]+) IN \((.+)\)$/s', $condition, $m ) ) {
			$have = strtolower( (string) $this->value( $row, $m[1] ) );
			foreach ( preg_split( "/',\s*'/", trim( $m[2], "'" ) ) ?: array() as $candidate ) {
				if ( strtolower( stripslashes( $candidate ) ) === $have ) {
					return true;
				}
			}
			return false;
		}
		return false;
	}

	/**
	 * @param string $have Value.
	 * @param string $want Pattern with % wildcards.
	 */
	private function like( string $have, string $want ): bool {
		$pattern = '/^' . str_replace( '%', '.*', preg_quote( str_replace( array( '\\%', '\\_' ), array( "\x01", "\x02" ), $want ), '/' ) ) . '$/is';
		return 1 === preg_match( str_replace( array( "\x01", "\x02" ), array( '%', '_' ), $pattern ), $have );
	}

	/**
	 * @param array<string,mixed> $row  Row.
	 * @param string              $path Field path.
	 */
	private function value( array $row, string $path ): mixed {
		if ( str_contains( $path, '.' ) ) {
			list( $parent, $child ) = explode( '.', $path, 2 );
			return $row[ $parent ][ $child ] ?? null;
		}
		return $row[ $path ] ?? null;
	}

	/**
	 * @param string $text SOQL literal.
	 */
	private function literal( string $text ): string {
		$text = trim( $text );
		if ( str_starts_with( $text, "'" ) && str_ends_with( $text, "'" ) ) {
			return (string) preg_replace( '/\\\\(.)/', '$1', substr( $text, 1, -1 ) );
		}
		return $text;
	}

	/**
	 * @param string               $object  Object.
	 * @param array<string,mixed>  $fields  Fields.
	 * @param array<string,string> $headers Headers.
	 * @param array<string,string> $extra   Headers.
	 */
	private function create( string $object, array $fields, array $headers, array $extra ): HttpResponse {
		if ( ! isset( $this->rows[ $object ] ) ) {
			return $this->json( array( array( 'message' => 'The requested resource does not exist', 'errorCode' => 'NOT_FOUND' ) ), 404, $extra );
		}
		if ( in_array( $object, $this->notCreateable, true ) ) {
			return $this->json( array( array( 'message' => 'insufficient access rights on object id', 'errorCode' => 'INSUFFICIENT_ACCESS_OR_READONLY' ) ), 403, $extra );
		}
		$error = $this->validate( $object, $fields, $headers );
		if ( null !== $error ) {
			return $this->json( array( $error ), 400, $extra );
		}
		if ( 'Opportunity' === $object && 0.0 !== $this->amountDrift && isset( $fields['Amount'] ) ) {
			$fields['Amount'] = (float) $fields['Amount'] + $this->amountDrift;
		}
		if ( 'Contact' === $object ) {
			$fields['Name'] = trim( (string) ( $fields['FirstName'] ?? '' ) . ' ' . (string) $fields['LastName'] );
		}
		$id = $this->put( $object, $fields );
		if ( 'OpportunityLineItem' === $object ) {
			$sum = 0.0;
			foreach ( $this->all( 'OpportunityLineItem' ) as $line ) {
				if ( $line['OpportunityId'] === $fields['OpportunityId'] ) {
					$sum += (float) $line['TotalPrice'];
				}
			}
			$this->rows['Opportunity'][ $fields['OpportunityId'] ]['Amount'] = round( $sum, 3 );
		}
		return $this->json( array( 'id' => $id, 'success' => true, 'errors' => array() ), 201, $extra );
	}

	/**
	 * @param string               $object  Object.
	 * @param array<string,mixed>  $fields  Fields.
	 * @param array<string,string> $headers Headers.
	 * @return array<string,mixed>|null
	 */
	private function validate( string $object, array $fields, array $headers ): ?array {
		$missing = static fn( string $field ): array => array( 'message' => 'Required fields are missing: [' . $field . ']', 'errorCode' => 'REQUIRED_FIELD_MISSING', 'fields' => array( $field ) );
		$required = array(
			'Opportunity'            => array( 'Name', 'StageName', 'CloseDate' ),
			'Contact'                => array( 'LastName' ),
			'Account'                => array( 'Name' ),
			'Task'                   => array( 'Subject' ),
			'OpportunityContactRole' => array( 'OpportunityId', 'ContactId' ),
			'OpportunityLineItem'    => array( 'OpportunityId', 'PricebookEntryId', 'Quantity', 'TotalPrice' ),
		);
		foreach ( $required[ $object ] ?? array() as $field ) {
			if ( ! isset( $fields[ $field ] ) || '' === $fields[ $field ] ) {
				return $missing( $field );
			}
		}
		if ( isset( $fields['CurrencyIsoCode'] ) && ! $this->multiCurrency ) {
			return array( 'message' => "No such column 'CurrencyIsoCode' on sobject of type {$object}", 'errorCode' => 'INVALID_FIELD', 'fields' => array( 'CurrencyIsoCode' ) );
		}
		if ( 'Opportunity' === $object && ! in_array( $fields['StageName'], array( 'Prospecting', 'Closed Won', 'Qualification' ), true ) ) {
			return array( 'message' => 'Stage: bad value for restricted picklist field: ' . $fields['StageName'], 'errorCode' => 'INVALID_OR_NULL_FOR_RESTRICTED_PICKLIST', 'fields' => array( 'StageName' ) );
		}
		if ( 'Contact' === $object && $this->duplicateRules && 'allowSave=true' !== ( $headers['sforce-duplicate-rule-header'] ?? '' ) ) {
			return array( 'message' => 'Use one of these records?', 'errorCode' => 'DUPLICATES_DETECTED', 'fields' => array() );
		}
		if ( 'Task' === $object && ( empty( $fields['Status'] ) || empty( $fields['Priority'] ) ) ) {
			return $missing( 'Status' );
		}
		return null;
	}

	/**
	 * @param string               $method Method.
	 * @param string               $object Object.
	 * @param string               $id     Id.
	 * @param array<string,mixed>  $body   Body.
	 * @param array<string,string> $extra  Headers.
	 */
	private function one( string $method, string $object, string $id, array $body, array $extra ): HttpResponse {
		if ( ! isset( $this->rows[ $object ][ $id ] ) || ! empty( $this->rows[ $object ][ $id ]['_deleted'] ) ) {
			return $this->json( array( array( 'message' => 'Provided external ID field does not exist or is not accessible: ' . $id, 'errorCode' => 'NOT_FOUND' ) ), 404, $extra );
		}
		if ( 'DELETE' === $method ) {
			$this->rows[ $object ][ $id ]['_deleted'] = true;
			if ( 'Opportunity' === $object ) {
				foreach ( array( 'OpportunityLineItem', 'OpportunityContactRole' ) as $child ) {
					foreach ( $this->rows[ $child ] as $childId => $row ) {
						if ( ( $row['OpportunityId'] ?? '' ) === $id ) {
							$this->rows[ $child ][ $childId ]['_deleted'] = true;
						}
					}
				}
			}
			return new HttpResponse( 204, '', $extra );
		}
		if ( 'PATCH' === $method ) {
			$this->rows[ $object ][ $id ] = $body + $this->rows[ $object ][ $id ];
			return new HttpResponse( 204, '', $extra );
		}
		return $this->json( array_diff_key( $this->rows[ $object ][ $id ], array( '_deleted' => 1 ) ) + array( 'attributes' => array( 'type' => $object ) ), 200, $extra );
	}
}
