<?php
/**
 * Replays the first answer to a repeated Idempotency-Key.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Rest\Support\KeyValueStore;

/**
 * A mutating request may carry an `Idempotency-Key` header. The first successful answer is kept for ten minutes, per person,
 * method and route. The same key again with the same request (the body, the query string and the route parameters) gets the stored
 * answer back with the header `Idempotent-Replayed: true`; the same key with anything different is refused with 409, because it is a
 * mistake in the client and replaying would answer a question that was not asked. Failed answers are not kept, so a retry after an
 * error runs again. The stored value holds only the answer, a status and a keyed hash of the request, never the request itself,
 * because a body can carry a credential.
 */
final class IdempotencyCache {

	/**
	 * Seconds an answer is kept.
	 */
	public const TTL = 600;

	/**
	 * Longest key accepted.
	 */
	public const MAX_KEY_LENGTH = 128;

	/**
	 * @param KeyValueStore $store  Storage.
	 * @param string        $secret Secret for hashing the request body (a WordPress salt).
	 */
	public function __construct(
		private readonly KeyValueStore $store,
		private readonly string $secret
	) {
	}

	/**
	 * Check a header value.
	 *
	 * @param string $header Value of the Idempotency-Key header; empty when absent.
	 * @return string|null The key, or null when the request has none.
	 *
	 * @throws ApiException With status 400 when the key is longer than 128 characters or has characters other than letters, digits and - _ . :
	 */
	public function validKey( string $header ): ?string {
		$header = trim( $header );
		if ( '' === $header ) {
			return null;
		}
		if ( strlen( $header ) > self::MAX_KEY_LENGTH || 1 !== preg_match( '/^[A-Za-z0-9_.:\-]+$/', $header ) ) {
			throw new ApiException(
				'estasoft_sync_bad_idempotency_key',
				__( 'The Idempotency-Key header must be up to 128 letters, digits, dots, colons, dashes or underscores.', 'estasoft-erp-crm-sync-engine' ),
				400
			);
		}
		return $header;
	}

	/**
	 * The stored answer for a key, if there is one for this request.
	 *
	 * @param int    $userId Person making the request.
	 * @param string $key    Validated key.
	 * @param string $method HTTP method.
	 * @param string $route  Route such as /queue/12/retry.
	 * @param string $body   Raw request body.
	 * @param array<string,mixed> $query       Query-string parameters.
	 * @param array<string,mixed> $routeParams Parameters taken from the route, for example the id.
	 * @return array{status:int,data:mixed}|null Null when the key is new.
	 *
	 * @throws ApiException With status 409 when the key was used for a different request.
	 */
	public function replay( int $userId, string $key, string $method, string $route, string $body, array $query = array(), array $routeParams = array() ): ?array {
		$stored = $this->store->get( $this->storageKey( $userId, $key, $method, $route ) );
		if ( ! is_array( $stored ) || ! isset( $stored['fingerprint'], $stored['status'] ) ) {
			return null;
		}
		if ( ! hash_equals( (string) $stored['fingerprint'], $this->fingerprint( $body, $query, $routeParams ) ) ) {
			throw new ApiException(
				'estasoft_sync_idempotency_conflict',
				__( 'This Idempotency-Key was already used for a different request. Use a new key.', 'estasoft-erp-crm-sync-engine' ),
				409
			);
		}
		return array(
			'status' => (int) $stored['status'],
			'data'   => $stored['data'] ?? null,
		);
	}

	/**
	 * Keep an answer. Only answers below 400 are kept.
	 *
	 * @param int    $userId Person making the request.
	 * @param string $key    Validated key.
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param string $body   Raw request body.
	 * @param int    $status HTTP status of the answer.
	 * @param mixed  $data   Body of the answer.
	 * @param array<string,mixed> $query       Query-string parameters.
	 * @param array<string,mixed> $routeParams Parameters taken from the route.
	 */
	public function remember( int $userId, string $key, string $method, string $route, string $body, int $status, mixed $data, array $query = array(), array $routeParams = array() ): void {
		if ( $status >= 400 ) {
			return;
		}
		$this->store->set(
			$this->storageKey( $userId, $key, $method, $route ),
			array(
				'fingerprint' => $this->fingerprint( $body, $query, $routeParams ),
				'status'      => $status,
				'data'        => $data,
			),
			self::TTL
		);
	}

	/**
	 * Where an answer is kept.
	 *
	 * @param int    $userId Person.
	 * @param string $key    Key.
	 * @param string $method Method.
	 * @param string $route  Route.
	 */
	private function storageKey( int $userId, string $key, string $method, string $route ): string {
		return 'idem_' . substr( hash_hmac( 'sha256', $userId . '|' . strtoupper( $method ) . '|' . $route . '|' . $key, $this->secret ), 0, 40 );
	}

	/**
	 * A keyed hash of the request: the raw body, the query-string parameters and the route parameters. The parameters are sorted,
	 * so the order they were written in does not matter, and kept apart as JSON so that `a=1&b=2` and `a=12` cannot be confused.
	 *
	 * @param string              $body        Raw body.
	 * @param array<string,mixed> $query       Query-string parameters.
	 * @param array<string,mixed> $routeParams Route parameters.
	 */
	private function fingerprint( string $body, array $query, array $routeParams ): string {
		return hash_hmac( 'sha256', Json::canonical( array( 'body' => $body, 'query' => $query, 'route' => $routeParams ) ), $this->secret );
	}
}
