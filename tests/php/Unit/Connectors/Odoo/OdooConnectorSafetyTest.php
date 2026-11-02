<?php
/**
 * The connector reports surprises as errors; it does not let them escape.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class OdooConnectorSafetyTest extends OdooTestCase {

	/**
	 * A client that breaks in a way nobody planned for, with a message that holds the key.
	 */
	private function explodingClient(): HttpClientInterface {
		return new class() implements HttpClientInterface {
			public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 30 ): HttpResponse {
				throw new \RuntimeException( 'Unexpected failure with Authorization: bearer sk-test-0123456789abcdef' );
			}
		};
	}

	public function test_an_unexpected_exception_becomes_a_retryable_error_without_its_message(): void {
		$server     = $this->server( 19 );
		$connector  = $this->connector( $this->explodingClient() );
		$connection = $this->connection( $server );
		$since      = new \DateTimeImmutable( '2026-11-01T00:00:00Z' );

		$errors = array(
			$connector->send( $connection, $this->item( EntityType::Order, '1' ), $this->orderPayload() )->error,
			$connector->send( $connection, $this->item( EntityType::Refund, '2', 'refunded' ), array( 'order_ref' => 'WC-1', 'amount' => 5 ) )->error,
			$connector->send( $connection, $this->item( EntityType::Customer, '3' ), array( 'partner' => array( 'name' => 'A', 'email' => 'a@b.c' ) ) )->error,
			$connector->test( $connection )->error,
			$connector->findExisting( $connection, $this->item( EntityType::Order, '1' ) ),
			$connector->lookupCustomer( $connection, 'a@b.c' ),
			$connector->lookupProduct( $connection, 'X' ),
			$connector->searchProducts( $connection, 'x' ),
			$connector->fetchStock( $connection, array( 'X' ), array() ),
			$connector->fetchTracking( $connection, $since ),
			$connector->fetchInvoice( $connection, new ExternalRef( '1' ) ),
			$connector->listForReconcile( $connection, $since ),
			$connector->listTaxes( $connection ),
		);
		foreach ( $errors as $index => $error ) {
			$this->assertInstanceOf( ConnectorError::class, $error, "call {$index}" );
			$this->assertSame( ErrorCode::Net002, $error->code, "call {$index}" );
			$this->assertSame( ErrorClass::Retry, $error->errorClass() );
			$this->assertSame( 'unexpected RuntimeException', $error->detail );
			$this->assertStringNotContainsString( 'sk-test', $error->message() . $error->detail . json_encode( $error->context ) );
		}
	}

	public function test_ping_is_false_instead_of_throwing(): void {
		$server = $this->server( 19 );
		$this->assertFalse( $this->connector( $this->explodingClient() )->ping( $this->connection( $server ) ) );
	}

	public function test_a_server_that_answers_with_the_wrong_shapes_is_an_error_not_a_php_error(): void {
		$http = new class() implements HttpClientInterface {
			public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 30 ): HttpResponse {
				if ( 'GET' === $method ) {
					return new HttpResponse( 200, '{"version":"19.0","version_info":[19,0,0,"final",0,""]}' );
				}
				return new HttpResponse( 200, '"a string where records should be"' );
			}
		};
		$server     = $this->server( 19 );
		$connector  = $this->connector( $http );
		$connection = $this->connection( $server );
		$this->assertInstanceOf( ConnectorError::class, $connector->searchProducts( $connection, 'x' ) );
		$this->assertFalse( $connector->send( $connection, $this->item( EntityType::Order, '1' ), $this->orderPayload() )->isSuccess() );
		$this->assertFalse( $connector->test( $connection )->ok );
	}
}
