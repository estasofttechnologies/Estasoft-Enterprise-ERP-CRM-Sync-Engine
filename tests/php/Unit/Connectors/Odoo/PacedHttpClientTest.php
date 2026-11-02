<?php
/**
 * Pacing of requests to one server.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Connectors\Odoo\PacedHttpClient;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class PacedHttpClientTest extends TestCase {

	/**
	 * Clock in milliseconds and the sleeps asked for.
	 *
	 * @var array{now:float,slept:array<int,int>}
	 */
	private array $time = array( 'now' => 1000.0, 'slept' => array() );

	private function paced( FakeHttpClient $inner, int $interval ): PacedHttpClient {
		$this->time = array( 'now' => 1000.0, 'slept' => array() );
		return new PacedHttpClient(
			$inner,
			$interval,
			function ( int $milliseconds ): void {
				$this->time['slept'][] = $milliseconds;
				$this->time['now']    += $milliseconds;
			},
			fn(): float => $this->time['now']
		);
	}

	public function test_the_first_request_does_not_wait(): void {
		$inner = ( new FakeHttpClient() )->queue( FakeHttpClient::json( 1 ) );
		$this->paced( $inner, 1000 )->request( 'GET', 'https://erp.example.com/a' );
		$this->assertSame( array(), $this->time['slept'] );
	}

	public function test_the_second_request_waits_out_the_rest_of_the_interval(): void {
		$inner  = ( new FakeHttpClient() )->queue( FakeHttpClient::json( 1 ) )->queue( FakeHttpClient::json( 2 ) );
		$client = $this->paced( $inner, 1000 );
		$client->request( 'GET', 'https://erp.example.com/a' );
		$this->time['now'] += 300.0;
		$client->request( 'GET', 'https://erp.example.com/b' );
		$this->assertSame( array( 700 ), $this->time['slept'] );
	}

	public function test_no_wait_when_the_interval_has_already_passed(): void {
		$inner  = ( new FakeHttpClient() )->queue( FakeHttpClient::json( 1 ) )->queue( FakeHttpClient::json( 2 ) );
		$client = $this->paced( $inner, 1000 );
		$client->request( 'GET', 'https://erp.example.com/a' );
		$this->time['now'] += 2500.0;
		$client->request( 'GET', 'https://erp.example.com/b' );
		$this->assertSame( array(), $this->time['slept'] );
	}

	public function test_a_zero_interval_turns_pacing_off(): void {
		$inner  = ( new FakeHttpClient() )->queue( FakeHttpClient::json( 1 ) )->queue( FakeHttpClient::json( 2 ) );
		$client = $this->paced( $inner, 0 );
		$client->request( 'GET', 'https://erp.example.com/a' );
		$client->request( 'GET', 'https://erp.example.com/b' );
		$this->assertSame( array(), $this->time['slept'] );
	}

	public function test_a_wait_is_never_longer_than_five_seconds(): void {
		$inner  = ( new FakeHttpClient() )->queue( FakeHttpClient::json( 1 ) )->queue( FakeHttpClient::json( 2 ) );
		$client = $this->paced( $inner, 60000 );
		$client->request( 'GET', 'https://erp.example.com/a' );
		$client->request( 'GET', 'https://erp.example.com/b' );
		$this->assertSame( array( 5000 ), $this->time['slept'] );
	}

	public function test_the_answer_and_the_request_pass_through_unchanged(): void {
		$inner    = ( new FakeHttpClient() )->queue( FakeHttpClient::tooManyRequests( 9 ) );
		$response = $this->paced( $inner, 1000 )->request( 'POST', 'https://erp.example.com/x', array( 'A' => 'b' ), '{"k":1}', 12 );
		$this->assertInstanceOf( HttpResponse::class, $response );
		$this->assertSame( 429, $response->status );
		$this->assertSame( 9, $response->retryAfter() );
		$this->assertSame( 'POST', $inner->last()['method'] );
		$this->assertSame( '{"k":1}', $inner->last()['body'] );
		$this->assertSame( 12, $inner->last()['timeout'] );
	}
}
