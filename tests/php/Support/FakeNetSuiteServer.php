<?php
/**
 * A small in-memory NetSuite for connector tests: the token endpoint, record calls, transforms and SuiteQL.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Behaviour is taken from the pages listed in docs/connectors/netsuite.md: the M2M token request with a signed assertion, `eid:` upsert (204 and
 * `Location`), `!transform`, error bodies with `o:errorDetails`, SuiteQL with `Prefer: transient`, `limit` and `offset`. SuiteQL is not an SQL
 * engine: the queries the connector sends are recognised by their text. It models on purpose what once hid bugs elsewhere: a tax rate that
 * makes NetSuite's total differ from the shop's, an order that comes back in another currency, a transform that needs approval first.
 */
final class FakeNetSuiteServer {

	/**
	 * The client the connector is built on.
	 */
	public FakeHttpClient $http;

	/**
	 * Account id as the merchant types it.
	 */
	public string $account = '1234567-SB1';

	/**
	 * Client id of the integration record.
	 */
	public string $clientId = 'ns-client-id-0001';

	/**
	 * Certificate id.
	 */
	public string $certificateId = 'ns-cert-01';

	/**
	 * Public key (PEM) an ES256 assertion must verify against; empty skips the signature check.
	 */
	public string $publicKey = '';

	/**
	 * How many of the next token requests are refused with invalid_client.
	 */
	public int $tokenFailures = 0;

	/**
	 * The access token it issues.
	 */
	public string $token = 'ns-access-token-0001';

	/**
	 * Tax rate in percent that NetSuite adds to goods and shipping (SuiteTax); 0 for none.
	 */
	public float $taxRate = 0.0;

	/**
	 * Whether SuiteTax is enabled (the tax item record exists).
	 */
	public bool $suiteTax = true;

	/**
	 * When true a transform to an invoice is refused until the order is approved.
	 */
	public bool $needsApproval = false;

	/**
	 * When set, orders are saved in this currency id whatever was asked.
	 */
	public ?string $forceCurrency = null;

	/**
	 * What the lines of a credit memo transform do with the lines copied from the invoice: `replace` (the body's lines are the memo's lines)
	 * or `add` (they come on top of the copied ones). The NetSuite page does not say which, so a test can run both.
	 */
	public string $creditMemoLines = 'replace';

	/**
	 * Whether DELETE of a credit memo is refused.
	 */
	public bool $refuseCreditMemoDelete = false;

	/**
	 * Items by id.
	 *
	 * @var array<string,array<string,string>>
	 */
	public array $items = array();

	/**
	 * Currencies: id to symbol.
	 *
	 * @var array<string,string>
	 */
	public array $currencies = array(
		'1' => 'USD',
		'2' => 'AED',
		'3' => 'KWD',
	);

	/**
	 * Customers by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $customers = array();

	/**
	 * Records by type then id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $records = array(
		'salesOrder' => array(),
		'invoice'    => array(),
		'creditMemo' => array(),
	);

	/**
	 * Calls of the token endpoint.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $tokenCalls = array();

	/**
	 * Answers scripted for the next write calls (not the token endpoint, not SuiteQL).
	 *
	 * @var array<int,HttpResponse>
	 */
	private array $failures = array();

	/**
	 * Next internal id.
	 *
	 * @var int
	 */
	private int $nextId = 100;

	/**
	 * Conversion helper.
	 *
	 * @var Amounts
	 */
	private Amounts $amounts;

	/**
	 * Build the server and wire it into a FakeHttpClient.
	 *
	 * @param FakeHttpClient|null $http Client to install into; a new one when null.
	 */
	public function __construct( ?FakeHttpClient $http = null ) {
		$this->http    = $http ?? new FakeHttpClient();
		$this->amounts = new Amounts();
		$this->http->fallback( fn( array $request ): HttpResponse => $this->handle( $request ) );
		$this->addItem( 'CS-BOWL-02', 'Stoneware bowl set' );
		$this->addItem( 'CS-MUG-01', 'Ceramic mug, sand' );
	}

	/**
	 * The host the connector must call.
	 */
	public function host(): string {
		return strtolower( str_replace( '_', '-', $this->account ) ) . '.suitetalk.api.netsuite.com';
	}

	/**
	 * Issue a different access token from now on (the one a client holds stops working).
	 */
	public function rotateToken(): void {
		$this->token .= 'x';
	}

	/**
	 * Add an item.
	 *
	 * @param string $itemId Item name or number.
	 * @param string $name   Display name.
	 * @return string The internal id.
	 */
	public function addItem( string $itemId, string $name ): string {
		$id                 = (string) $this->nextId++;
		$this->items[ $id ] = array(
			'id'          => $id,
			'itemid'      => $itemId,
			'displayname' => $name,
		);
		return $id;
	}

	/**
	 * Add a customer.
	 *
	 * @param string $email Email.
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return string The internal id.
	 */
	public function addCustomer( string $email, string $first, string $last ): string {
		$id                     = (string) $this->nextId++;
		$this->customers[ $id ] = array(
			'id'          => $id,
			'email'       => $email,
			'firstName'   => $first,
			'lastName'    => $last,
			'companyName' => '',
			'externalId'  => '',
		);
		return $id;
	}

	/**
	 * Make the next write calls fail with a response (the sign-in and SuiteQL calls are not writes).
	 *
	 * @param HttpResponse $response Answer.
	 * @param int          $times    How many writes get it.
	 */
	public function failNext( HttpResponse $response, int $times = 1 ): void {
		for ( $i = 0; $i < $times; ++$i ) {
			$this->failures[] = $response;
		}
	}

	/**
	 * Records of a type.
	 *
	 * @param string $type salesOrder, invoice or creditMemo.
	 * @return array<string,array<string,mixed>>
	 */
	public function all( string $type ): array {
		return $this->records[ $type ] ?? array();
	}

	/**
	 * Answer a request.
	 *
	 * @param array<string,mixed> $request Request as the FakeHttpClient records it.
	 */
	private function handle( array $request ): HttpResponse {
		$parts = wp_parse_url( (string) $request['url'] );
		$path  = (string) ( $parts['path'] ?? '' );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( strtolower( (string) ( $parts['host'] ?? '' ) ) !== $this->host() ) {
			return FakeHttpClient::status( 404, 'Unknown host' );
		}
		$path = (string) preg_replace( '#^/services/rest#', '', $path );
		if ( '/auth/oauth2/v1/token' === $path ) {
			return $this->token( $request );
		}
		$header = $this->header( $request, 'authorization' );
		if ( 'Bearer ' . $this->token !== $header ) {
			return $this->error( 401, 'Unauthorized', 'INVALID_LOGIN', 'Invalid login attempt.' );
		}
		if ( '/query/v1/suiteql' === $path ) {
			return $this->suiteql( $request, $query );
		}
		if ( 'GET' !== $request['method'] && array() !== $this->failures ) {
			return array_shift( $this->failures );
		}
		return $this->record( $request, $path, $query );
	}

	/**
	 * The token endpoint.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private function token( array $request ): HttpResponse {
		parse_str( (string) $request['body'], $form );
		$this->tokenCalls[] = $form;
		if ( 'client_credentials' !== ( $form['grant_type'] ?? '' ) || 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' !== ( $form['client_assertion_type'] ?? '' ) ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_request' ), 400 );
		}
		$parts = explode( '.', (string) ( $form['client_assertion'] ?? '' ) );
		if ( 3 !== count( $parts ) ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_request' ), 400 );
		}
		$header = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads a test token.
		$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads a test token.
		if ( ! is_array( $header ) || ! is_array( $claims ) ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_request' ), 400 );
		}
		$audience = 'https://' . $this->host() . '/services/rest/auth/oauth2/v1/token';
		$scopes   = (array) ( $claims['scope'] ?? array() );
		$valid    = in_array( $header['alg'] ?? '', array( 'ES256', 'PS256' ), true )
			&& 'JWT' === ( $header['typ'] ?? '' )
			&& $this->certificateId === ( $header['kid'] ?? '' )
			&& $this->clientId === ( $claims['iss'] ?? '' )
			&& in_array( 'rest_webservices', $scopes, true )
			&& $audience === ( $claims['aud'] ?? '' )
			&& (int) ( $claims['exp'] ?? 0 ) - (int) ( $claims['iat'] ?? 0 ) < 3600
			&& $this->signatureHolds( $parts, (string) ( $header['alg'] ?? '' ) );
		if ( $this->tokenFailures > 0 ) {
			--$this->tokenFailures;
			$valid = false;
		}
		if ( ! $valid ) {
			return FakeHttpClient::json( array( 'error' => 'invalid_client' ), 401 );
		}
		return FakeHttpClient::json(
			array(
				'access_token' => $this->token,
				'token_type'   => 'Bearer',
				'expires_in'   => 3600,
			)
		);
	}

	/**
	 * Whether an ES256 signature verifies against the public key (other algorithms and an empty key are not checked here).
	 *
	 * @param array<int,string> $parts Header, payload and signature of the token.
	 * @param string            $alg   Algorithm.
	 */
	private function signatureHolds( array $parts, string $alg ): bool {
		if ( 'ES256' !== $alg || '' === $this->publicKey ) {
			return true;
		}
		$raw = (string) base64_decode( strtr( $parts[2], '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads a test token.
		if ( 64 !== strlen( $raw ) ) {
			return false;
		}
		$int = static function ( string $value ): string {
			$value = ltrim( $value, "\x00" );
			$value = '' === $value ? "\x00" : $value;
			return "\x02" . chr( strlen( $value ) + ( ord( $value[0] ) & 0x80 ? 1 : 0 ) ) . ( ord( $value[0] ) & 0x80 ? "\x00" : '' ) . $value;
		};
		$body = $int( substr( $raw, 0, 32 ) ) . $int( substr( $raw, 32 ) );
		return 1 === openssl_verify( $parts[0] . '.' . $parts[1], "\x30" . chr( strlen( $body ) ) . $body, $this->publicKey, OPENSSL_ALGO_SHA256 );
	}

	/**
	 * Record calls.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param string              $path    Path under /services/rest.
	 * @param array<string,mixed> $query   Query parameters.
	 */
	private function record( array $request, string $path, array $query ): HttpResponse {
		$method = (string) $request['method'];
		$body   = json_decode( (string) $request['body'], true );
		$body   = is_array( $body ) ? $body : array();
		if ( 1 === preg_match( '#^/record/v1/(\w+)/(\d+)/!transform/(\w+)$#', $path, $m ) ) {
			return $this->transform( $m[1], $m[2], $m[3], $body );
		}
		if ( 1 !== preg_match( '#^/record/v1/(\w+)(?:/(eid:)?([^/]+))?$#', $path, $m ) ) {
			return $this->error( 400, 'Bad Request', 'INVALID_REQUEST', 'The request could not be understood by the server due to malformed syntax.' );
		}
		$type = $m[1];
		if ( 'salesTaxItem' === $type && ! $this->suiteTax ) {
			return $this->error( 404, 'Not Found', 'NONEXISTENT_ID', 'Record type does not exist.' );
		}
		$key = isset( $m[3] ) ? rawurldecode( $m[3] ) : '';
		if ( '' === $key ) {
			return FakeHttpClient::json(
				array(
					'links'        => array(),
					'count'        => count( $this->records[ $type ] ?? array() ),
					'hasMore'      => false,
					'items'        => array(),
					'offset'       => 0,
					'totalResults' => count( $this->records[ $type ] ?? array() ),
				)
			);
		}
		$byEid = '' !== ( $m[2] ?? '' );
		$found = $this->find( $type, $key, $byEid );
		if ( 'GET' === $method ) {
			return null === $found ? $this->error( 404, 'Not Found', 'NONEXISTENT_ID', 'Record does not exist.' ) : FakeHttpClient::json( $this->view( $found, (string) ( $query['fields'] ?? '' ) ) );
		}
		if ( 'PUT' === $method && $byEid ) {
			return $this->upsert( $type, $key, $body, $found );
		}
		if ( 'DELETE' === $method && null !== $found && 'creditMemo' === $type ) {
			if ( $this->refuseCreditMemoDelete ) {
				return $this->error( 400, 'Bad Request', 'USER_ERROR', 'This transaction cannot be deleted.' );
			}
			unset( $this->records[ $type ][ (string) $found['id'] ] );
			return new HttpResponse( 204 );
		}
		if ( 'PATCH' === $method && null !== $found ) {
			$this->records[ $type ][ (string) $found['id'] ] = $body + $found;
			return $this->created( $type, (string) $found['id'] );
		}
		return $this->error( 404, 'Not Found', 'NONEXISTENT_ID', 'Record does not exist.' );
	}

	/**
	 * Create or update a record by external id.
	 *
	 * @param string                   $type  Record type.
	 * @param string                   $eid   External id.
	 * @param array<string,mixed>      $body  Body.
	 * @param array<string,mixed>|null $found Existing record.
	 */
	private function upsert( string $type, string $eid, array $body, ?array $found ): HttpResponse {
		if ( 'customer' === $type ) {
			$id                     = null === $found ? (string) $this->nextId++ : (string) $found['id'];
			$this->customers[ $id ] = $body + array( 'id' => $id ) + ( $found ?? array() );
			return $this->created( $type, $id );
		}
		if ( 'salesOrder' !== $type ) {
			return $this->error( 400, 'Bad Request', 'INVALID_REQUEST', 'Unsupported record type.' );
		}
		$entity = (string) ( $body['entity']['id'] ?? '' );
		if ( '' === $entity || ! isset( $this->customers[ $entity ] ) ) {
			return $this->error( 400, 'Bad Request', 'INVALID_CONTENT', 'You have entered an Invalid Field Value ' . $entity . ' for the following field: entity', 'entity' );
		}
		$currency = $this->forceCurrency ?? (string) ( $body['currency']['id'] ?? '1' );
		$goods    = 0;
		$symbol   = $this->currencies[ $currency ] ?? 'USD';
		foreach ( (array) ( $body['item']['items'] ?? array() ) as $index => $line ) {
			$itemId = (string) ( $line['item']['id'] ?? '' );
			if ( ! isset( $this->items[ $itemId ] ) ) {
				return $this->error( 400, 'Bad Request', 'INVALID_CONTENT', 'You have entered an Invalid Field Value ' . $itemId . ' for the following field: item', 'item.items[' . $index . '].item' );
			}
			$goods += $this->amounts->lineTotal( (int) $this->amounts->fromWire( $line['rate'] ?? 0, $symbol ), (string) ( $line['quantity'] ?? 1 ) );
		}
		$goods += (int) $this->amounts->fromWire( $body['shippingCost'] ?? 0, $symbol );
		$tax    = $this->suiteTax ? (int) round( $goods * $this->taxRate / 100 ) : 0;
		$id     = null === $found ? (string) $this->nextId++ : (string) $found['id'];
		$this->records['salesOrder'][ $id ] = $body + array(
			'id'       => $id,
			'tranId'   => 'SO' . $id,
			'entity'   => array( 'id' => $entity ),
			'total'    => $this->amounts->toNumber( $goods + $tax, $symbol ),
			'status'   => array( 'refName' => $this->needsApproval ? 'Pending Approval' : 'Pending Fulfillment' ),
			'currency' => array(
				'id'      => $currency,
				'refName' => $symbol,
			),
		);
		$this->records['salesOrder'][ $id ]['currency'] = array(
			'id'      => $currency,
			'refName' => $symbol,
		);
		return $this->created( $type, $id );
	}

	/**
	 * A transform call.
	 *
	 * @param string              $source Source type.
	 * @param string              $id     Source id.
	 * @param string              $target Target type.
	 * @param array<string,mixed> $body   Body.
	 */
	private function transform( string $source, string $id, string $target, array $body ): HttpResponse {
		if ( array() !== $this->failures ) {
			return array_shift( $this->failures );
		}
		$from = $this->records[ $source ][ $id ] ?? null;
		if ( null === $from ) {
			return $this->error( 404, 'Not Found', 'NONEXISTENT_ID', 'Record does not exist.' );
		}
		if ( 'salesOrder' === $source && 'invoice' === $target ) {
			if ( $this->needsApproval ) {
				return $this->error( 400, 'Bad Request', 'INVALID_CONTENT', 'The sales order must be approved before it can be billed.' );
			}
			$new = (string) $this->nextId++;
			$this->records['invoice'][ $new ] = array(
				'id'          => $new,
				'tranId'      => 'INV' . $new,
				'createdfrom' => $id,
				'total'       => $from['total'],
				'currency'    => $from['currency'],
				'tranDate'    => $body['tranDate'] ?? ( $from['tranDate'] ?? '' ),
				'status'      => 'A',
			);
			return $this->created( 'invoice', $new );
		}
		if ( 'invoice' === $source && 'creditMemo' === $target ) {
			$new    = (string) $this->nextId++;
			$symbol = (string) $from['currency']['refName'];
			$total  = 0;
			foreach ( (array) ( $body['item']['items'] ?? array() ) as $line ) {
				$total += (int) $this->amounts->fromWire( $line['amount'] ?? 0, $symbol );
			}
			if ( 'add' === $this->creditMemoLines ) {
				$total += (int) $this->amounts->fromWire( $from['total'] ?? 0, $symbol );
			}
			$this->records['creditMemo'][ $new ] = array(
				'id'          => $new,
				'tranId'      => 'CM' . $new,
				'createdfrom' => $id,
				'memo'        => $body['memo'] ?? '',
				'total'       => $this->amounts->toNumber( $total, $symbol ),
				'currency'    => $from['currency'],
				'externalId'  => '',
			);
			return $this->created( 'creditMemo', $new );
		}
		return $this->error( 400, 'Bad Request', 'INVALID_REQUEST', 'Unsupported transform.' );
	}

	/**
	 * SuiteQL: the queries the connector sends are recognised by their text.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param array<string,mixed> $query   Query parameters.
	 */
	private function suiteql( array $request, array $query ): HttpResponse {
		if ( 'transient' !== $this->header( $request, 'prefer' ) ) {
			return $this->error( 400, 'Bad Request', 'INVALID_REQUEST', 'The Prefer: transient header is required.' );
		}
		$body   = json_decode( (string) $request['body'], true );
		$sql    = is_array( $body ) ? (string) ( $body['q'] ?? '' ) : '';
		$params = is_array( $body ) && isset( $body['params'] ) && is_array( $body['params'] ) ? array_values( $body['params'] ) : array();
		$rows   = $this->rows( $sql, $params );
		$limit  = max( 1, (int) ( $query['limit'] ?? 1000 ) );
		$offset = max( 0, (int) ( $query['offset'] ?? 0 ) );
		$page   = array_slice( $rows, $offset, $limit );
		foreach ( $page as $index => $row ) {
			$page[ $index ] = array( 'links' => array() ) + $row;
		}
		return FakeHttpClient::json(
			array(
				'links'        => array(),
				'count'        => count( $page ),
				'hasMore'      => $offset + $limit < count( $rows ),
				'items'        => $page,
				'offset'       => $offset,
				'totalResults' => count( $rows ),
			)
		);
	}

	/**
	 * Rows for a recognised query.
	 *
	 * @param string            $sql    Query text.
	 * @param array<int,mixed>  $params Bound values.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( string $sql, array $params ): array {
		$flat = (string) preg_replace( '/\s+/', ' ', $sql );
		if ( 1 === preg_match( '/FROM currency WHERE UPPER\(symbol\) = \?/', $flat ) ) {
			$rows = array();
			foreach ( $this->currencies as $id => $symbol ) {
				if ( strtoupper( (string) $params[0] ) === $symbol ) {
					$rows[] = array( 'id' => (string) $id );
				}
			}
			return $rows;
		}
		if ( str_contains( $flat, 'FROM currency WHERE isinactive' ) ) {
			$rows = array();
			foreach ( $this->currencies as $id => $symbol ) {
				$rows[] = array(
					'id'     => (string) $id,
					'symbol' => $symbol,
				);
			}
			return $rows;
		}
		if ( str_contains( $flat, 'FROM customer WHERE LOWER(email) = ?' ) ) {
			$rows = array();
			foreach ( $this->customers as $customer ) {
				if ( strtolower( (string) ( $customer['email'] ?? '' ) ) === strtolower( (string) $params[0] ) ) {
					$rows[] = array(
						'id'          => (string) $customer['id'],
						'entityid'    => 'C' . $customer['id'],
						'companyname' => (string) ( $customer['companyName'] ?? '' ),
						'firstname'   => (string) ( $customer['firstName'] ?? '' ),
						'lastname'    => (string) ( $customer['lastName'] ?? '' ),
					);
				}
			}
			return $rows;
		}
		if ( 1 === preg_match( '/FROM item WHERE UPPER\((\w+)\) = UPPER\(\?\)/', $flat ) ) {
			return array_values( array_map( static fn( array $item ): array => $item, array_filter( $this->items, static fn( array $item ): bool => strtoupper( $item['itemid'] ) === strtoupper( (string) $params[0] ) ) ) );
		}
		if ( str_contains( $flat, 'FROM item WHERE (LOWER(' ) ) {
			$needle = trim( strtolower( (string) $params[0] ), '%' );
			return array_values( array_filter( $this->items, static fn( array $item ): bool => str_contains( strtolower( $item['itemid'] ), $needle ) || str_contains( strtolower( $item['displayname'] ), $needle ) ) );
		}
		if ( 'SELECT id FROM item' === trim( $flat ) ) {
			return array_values( array_map( static fn( array $item ): array => array( 'id' => $item['id'] ), $this->items ) );
		}
		if ( str_contains( $flat, "type = 'CustInvc' AND createdfrom = ?" ) ) {
			$rows = array();
			foreach ( array_reverse( $this->records['invoice'], true ) as $invoice ) {
				if ( (string) $invoice['createdfrom'] === (string) $params[0] ) {
					$rows[] = array(
						'id'       => (string) $invoice['id'],
						'tranid'   => (string) $invoice['tranId'],
						'status'   => (string) $invoice['status'],
						'trandate' => (string) ( $invoice['tranDate'] ?? '' ),
					);
				}
			}
			return $rows;
		}
		if ( str_contains( $flat, "type = 'CustCred' AND (externalid = ?" ) ) {
			$rows = array();
			foreach ( $this->records['creditMemo'] as $memo ) {
				$marked = str_contains( (string) ( $memo['memo'] ?? '' ), trim( (string) $params[1], '%' ) );
				if ( (string) ( $memo['externalId'] ?? '' ) === (string) $params[0] || $marked ) {
					$rows[] = $this->memoRow( $memo );
				}
			}
			return $rows;
		}
		if ( str_contains( $flat, "t.type = 'SalesOrd'" ) ) {
			$rows = array();
			foreach ( $this->records['salesOrder'] as $order ) {
				if ( str_starts_with( (string) ( $order['externalId'] ?? '' ), 'WC-' ) ) {
					$rows[] = array(
						'id'           => (string) $order['id'],
						'tranid'       => (string) $order['tranId'],
						'externalid'   => (string) $order['externalId'],
						'foreigntotal' => $order['total'],
						'currency'     => (string) $order['currency']['id'],
						'email'        => (string) ( $this->customers[ (string) $order['entity']['id'] ]['email'] ?? '' ),
					);
				}
			}
			return $rows;
		}
		if ( str_contains( $flat, "cm.type = 'CustCred'" ) ) {
			$rows = array();
			foreach ( $this->records['creditMemo'] as $memo ) {
				$invoice = $this->records['invoice'][ (string) $memo['createdfrom'] ] ?? null;
				$order   = null === $invoice ? null : ( $this->records['salesOrder'][ (string) $invoice['createdfrom'] ] ?? null );
				if ( null !== $order ) {
					$rows[] = array(
						'orderref'     => (string) $order['externalId'],
						'foreigntotal' => $memo['total'],
						'currency'     => (string) $memo['currency']['id'],
					);
				}
			}
			return $rows;
		}
		return array();
	}

	/**
	 * A credit memo as a SuiteQL row.
	 *
	 * @param array<string,mixed> $memo Credit memo.
	 * @return array<string,mixed>
	 */
	private function memoRow( array $memo ): array {
		return array(
			'id'           => (string) $memo['id'],
			'tranid'       => (string) $memo['tranId'],
			'foreigntotal' => $memo['total'],
			'currency'     => (string) $memo['currency']['id'],
			'memo'         => (string) ( $memo['memo'] ?? '' ),
		);
	}

	/**
	 * A record by internal or external id.
	 *
	 * @param string $type  Type.
	 * @param string $key   Id or external id.
	 * @param bool   $byEid Whether the key is an external id.
	 * @return array<string,mixed>|null
	 */
	private function find( string $type, string $key, bool $byEid ): ?array {
		$store = 'customer' === $type ? $this->customers : ( $this->records[ $type ] ?? array() );
		if ( ! $byEid ) {
			return $store[ $key ] ?? null;
		}
		foreach ( $store as $record ) {
			if ( (string) ( $record['externalId'] ?? '' ) === $key ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Only the fields asked for.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $fields Comma separated field names.
	 * @return array<string,mixed>
	 */
	private function view( array $record, string $fields ): array {
		if ( '' === $fields ) {
			return $record;
		}
		$view = array( 'links' => array() );
		foreach ( explode( ',', $fields ) as $name ) {
			if ( array_key_exists( $name, $record ) ) {
				$view[ $name ] = $record[ $name ];
			}
		}
		return $view;
	}

	/**
	 * A 204 with the Location header of the record.
	 *
	 * @param string $type Type.
	 * @param string $id   Id.
	 */
	private function created( string $type, string $id ): HttpResponse {
		return new HttpResponse( 204, '', array( 'location' => 'https://' . $this->host() . '/services/rest/record/v1/' . $type . '/' . $id ) );
	}

	/**
	 * An error body in the shape of the error handling page.
	 *
	 * @param int    $status HTTP status.
	 * @param string $title  Title.
	 * @param string $code   o:errorCode.
	 * @param string $detail Detail.
	 * @param string $path   o:errorPath.
	 */
	private function error( int $status, string $title, string $code, string $detail, string $path = '' ): HttpResponse {
		$entry = array(
			'detail'      => $detail,
			'o:errorCode' => $code,
		);
		if ( '' !== $path ) {
			$entry['o:errorPath'] = $path;
		}
		return FakeHttpClient::json(
			array(
				'type'           => 'https://www.rfc-editor.org/rfc/rfc9110.html',
				'title'          => $title,
				'status'         => $status,
				'o:errorDetails' => array( $entry ),
			),
			$status
		);
	}

	/**
	 * A request header, any case.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param string              $name    Header name.
	 */
	private function header( array $request, string $name ): string {
		foreach ( (array) $request['headers'] as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, $name ) ) {
				return (string) $value;
			}
		}
		return '';
	}
}
