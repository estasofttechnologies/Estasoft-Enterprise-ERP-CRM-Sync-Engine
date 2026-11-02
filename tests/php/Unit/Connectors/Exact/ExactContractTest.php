<?php
/**
 * The connector contract and the rules of the shared API base, checked against the Exact Online connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Exact;

use Estasoft\SyncEngine\Connectors\Exact\ExactConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeExactServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

/**
 * A sign-in connector: the refused key is a refused client secret at the token endpoint, and the expired token is what makes the connector go there.
 */
final class ExactContractTest extends ApiConnectorContractTestCase {

	private const SECRET = 'exact-client-secret-0123456789';

	/**
	 * The fake Exact Online.
	 *
	 * @var FakeExactServer
	 */
	private FakeExactServer $server;

	/**
	 * Whether the next working connection starts with an expired token.
	 *
	 * @var bool
	 */
	private bool $expireNext = false;

	/**
	 * Counter of tokens handed to connections.
	 *
	 * @var int
	 */
	private int $tokens = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new FakeExactServer();
		$this->kit->http->fallback( $this->server->handler() );
		$this->server->addItem( 'CS-MUG-01', '0' );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new ExactConnector( $this->kit->deps );
	}

	/**
	 * A connection with a token the server knows; expired when a failure of the sign-in was asked for.
	 *
	 * @param string $secret Client secret.
	 * @param bool   $expired Whether the token has run out.
	 */
	private function signedIn( string $secret, bool $expired ): Connection {
		++$this->tokens;
		$access  = 'access-c' . $this->tokens;
		$refresh = 'refresh-c' . $this->tokens;
		$this->server->allowTokens( $access, $refresh );
		$expires = $this->kit->clock->now()->getTimestamp() + ( $expired ? -10 : 600 );
		return $this->kit->connection(
			'exact-online',
			array( 'region' => 'nl', 'client_id' => 'exact-client-id', 'division' => FakeExactServer::DIVISION, 'division_name' => 'Test BV' ),
			array( 'client_secret' => $secret, 'oauth_access_token' => $access, 'oauth_refresh_token' => $refresh, 'oauth_expires_at' => (string) $expires )
		);
	}

	protected function workingConnection(): Connection {
		$expired          = $this->expireNext;
		$this->expireNext = false;
		return $this->signedIn( self::SECRET, $expired );
	}

	protected function brokenConnection(): Connection {
		return $this->signedIn( 'wrong-secret-0000', true );
	}

	protected function orderFixture( string $orderId, array $options = array() ): array {
		$item     = new Item( (int) $orderId, 1, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'k-' . $orderId, ItemState::Syncing );
		$quantity = (int) ( $options['quantity'] ?? 1 );
		$unit     = (int) ( $options['unit_minor'] ?? 4200 );
		return array(
			$item,
			array(
				'currency'   => $options['currency'] ?? 'AED',
				'date_order' => '2026-10-30 09:15:00',
				'total'      => $quantity * $unit,
				'partner'    => array(
					'name'       => $options['name'] ?? 'Layla Haddad',
					'email'      => $options['email'] ?? 'layla@example.test',
					'street'     => $options['street'] ?? 'Keizersgracht 1',
					'country_id' => 'NL',
				),
				'order_line' => array( array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => $quantity, 'price_unit' => $unit, 'tax' => '0' ) ),
			),
		);
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		switch ( $kind ) {
			case 'rate_limit':
				$this->kit->http->route(
					'POST',
					'#api/v1/#',
					function () use ( $seconds ): HttpResponse {
						$now = $this->kit->clock->now()->getTimestamp();
						return new HttpResponse( 429, '', array( 'x-ratelimit-minutely-remaining' => '0', 'x-ratelimit-minutely-reset' => (string) ( ( $now + $seconds - 1 ) * 1000 ) ) );
					}
				);
				break;
			case 'timeout':
				$this->server->fail( 'POST', '#api/v1/#', FakeHttpClient::timeout() );
				break;
			case 'server_error':
				$this->server->fail( 'POST', '#api/v1/#', $this->server->error( 500, 'Internal error' ) );
				break;
			case 'unknown_fault':
				$this->server->fail( 'POST', '#api/v1/#', $this->server->error( 400, 'Something odd happened' ) );
				break;
			case 'certificate':
				$this->server->fail( 'POST', '#api/v1/#', FakeHttpClient::certificateError() );
				break;
			case 'auth':
				$this->expireNext = true;
				$this->server->fail( '*', '#oauth2/token#', new HttpResponse( 401, '{"error":"invalid_client"}', array( 'content-type' => 'application/json' ) ) );
				break;
		}
	}

	protected function secretValues(): array {
		return array( self::SECRET, 'wrong-secret-0000' );
	}
}
