<?php
/**
 * The connector contract and the rules of the shared API base, checked against the Fortnox connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Fortnox;

use Estasoft\SyncEngine\Connectors\Fortnox\FortnoxConnector;
use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Contract\ApiConnectorContractTestCase;
use Estasoft\SyncEngine\Tests\Support\FakeFortnoxServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

/**
 * A sign-in connector: the refused key is a refused client secret at the token endpoint, and the expired token is what makes the connector go there.
 */
final class FortnoxContractTest extends ApiConnectorContractTestCase {

	private const SECRET = FakeFortnoxServer::CLIENT_SECRET;

	/**
	 * The fake Fortnox.
	 *
	 * @var FakeFortnoxServer
	 */
	private FakeFortnoxServer $server;

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
		$this->server = new FakeFortnoxServer();
		$this->kit->http->fallback( $this->server->handler() );
		$this->server->addArticle( 'CS-MUG-01', 10.0, 2.0 );
	}

	protected function apiConnector(): AbstractApiConnector {
		return new FortnoxConnector( $this->kit->deps );
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
		$expires = $this->kit->clock->now()->getTimestamp() + ( $expired ? -10 : 3600 );
		return $this->kit->connection(
			'fortnox',
			array( 'client_id' => FakeFortnoxServer::CLIENT_ID, 'tenant_id' => '1234567', 'company_name' => 'Test AB' ),
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
					'street'     => $options['street'] ?? 'Drottninggatan 1',
					'country_id' => 'SE',
				),
				'order_line' => array( array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => $quantity, 'price_unit' => $unit, 'tax' => '0' ) ),
			),
		);
	}

	protected function failNextWrite( string $kind, int $seconds = 0 ): void {
		switch ( $kind ) {
			case 'rate_limit':
				$this->server->fail( 'POST', '#/3/#', FakeHttpClient::tooManyRequests( $seconds ) );
				break;
			case 'timeout':
				$this->server->fail( 'POST', '#/3/#', FakeHttpClient::timeout() );
				break;
			case 'server_error':
				$this->server->fail( 'POST', '#/3/#', FakeHttpClient::status( 500, 'Internal error' ) );
				break;
			case 'unknown_fault':
				$this->server->fail( 'POST', '#/3/#', $this->server->error( 400, 2000588, 'Something odd happened' ) );
				break;
			case 'certificate':
				$this->server->fail( 'POST', '#/3/#', FakeHttpClient::certificateError() );
				break;
			case 'auth':
				$this->expireNext = true;
				$this->server->fail( '*', '#oauth-v1/token#', new HttpResponse( 401, '{"error":"invalid_client"}', array( 'content-type' => 'application/json' ) ) );
				break;
		}
	}

	protected function secretValues(): array {
		return array( self::SECRET, 'wrong-secret-0000' );
	}
}
