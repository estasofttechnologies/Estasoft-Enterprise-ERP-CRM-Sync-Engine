<?php
/**
 * The wire behaviour of "Acme CRM", an invented CRM with OAuth 2 sign-in, used to prove the shared base.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors;

use Estasoft\SyncEngine\Tests\Support\ApiFakeRequest;
use Estasoft\SyncEngine\Tests\Support\ApiFakeServer;
use Estasoft\SyncEngine\Tests\Support\ApiFakeTokens;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

/**
 * Sign-in host `login.acme-crm.test` (token endpoint with Basic client authentication, rotating refresh tokens) and API host `api.acme-crm.test`
 * (bearer token plus an `X-Tenant` header on every call, deals searched by an `external_id` field).
 */
final class ReferenceCrmVendor {

	/**
	 * Server.
	 */
	public ApiFakeServer $server;

	/**
	 * Token endpoint and bearer check.
	 */
	public ApiFakeTokens $tokens;

	/**
	 * Deals.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $deals = array();

	/**
	 * @param FakeHttpClient $http     Network.
	 * @param int            $lifetime Seconds an access token lives.
	 */
	public function __construct( FakeHttpClient $http, int $lifetime = 1800 ) {
		$this->server = ApiFakeServer::on( $http, array( 'api.acme-crm.test', 'login.acme-crm.test' ) );
		$this->tokens = new ApiFakeTokens( $this->server, '/oauth/token', $lifetime );
		$guard        = fn( ApiFakeRequest $request ) => $this->tokens->check( $request ) ?? ( 'tenant-7' === $request->header( 'X-Tenant' ) || '/v1/connections' === $request->path ? null : FakeHttpClient::status( 403, '{"message":"Tenant not allowed"}' ) );

		$this->server->route( 'GET', '/v1/connections', fn( $r ) => $this->tokens->check( $r ) ?? array( 'items' => array( array( 'id' => 'tenant-7', 'name' => 'Acme Trading LLC' ), array( 'id' => 'tenant-8', 'name' => 'Acme Retail' ) ) ) );
		$this->server->route( 'GET', '/v1/me', fn( $r ) => $guard( $r ) ?? array( 'org' => 'Acme Trading LLC', 'version' => '9.1', 'scopes' => array( 'deals.write', 'contacts.read' ) ) );
		$this->server->route(
			'GET',
			'/v1/deals',
			fn( $r ) => $guard( $r ) ?? array( 'items' => array_values( array_filter( $this->deals, static fn( $d ) => $d['external_id'] === ( $r->query['external_id'] ?? null ) ) ) )
		);
		$this->server->route(
			'POST',
			'/v1/deals',
			function ( $r ) use ( $guard ) {
				$bad = $guard( $r );
				if ( null !== $bad ) {
					return $bad;
				}
				$body            = $r->json();
				$deal            = array( 'id' => 'd-' . ( count( $this->deals ) + 1 ), 'external_id' => $body['external_id'], 'title' => $body['title'], 'amount' => $body['amount'] );
				$this->deals[]   = $deal;
				return FakeHttpClient::json( $deal, 201 );
			}
		);
	}
}
