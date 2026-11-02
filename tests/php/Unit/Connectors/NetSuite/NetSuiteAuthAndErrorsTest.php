<?php
/**
 * Sign-in, token reuse, account ids and NetSuite's error shapes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\NetSuite;

use Estasoft\SyncEngine\Connectors\NetSuite\NetSuiteErrors;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class NetSuiteAuthAndErrorsTest extends NetSuiteTestCase {

	public function test_the_token_is_requested_once_and_reused_for_every_call(): void {
		$this->connector->send( $this->connection(), $this->item( '3001' ), $this->payload() );
		$this->connector->send( $this->connection(), $this->item( '3002' ), $this->payload() );
		$this->assertCount( 1, $this->server->tokenCalls );
	}

	public function test_the_assertion_carries_the_documented_claims_and_no_secret_travels_in_the_url(): void {
		$this->connector->test( $this->connection() );
		$form = $this->server->tokenCalls[0];
		$this->assertSame( 'client_credentials', $form['grant_type'] );
		$parts = explode( '.', $form['client_assertion'] );
		$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads a test token.
		$this->assertSame( $this->server->clientId, $claims['iss'] );
		$this->assertSame( array( 'rest_webservices' ), $claims['scope'] );
		$this->assertLessThan( 3600, $claims['exp'] - $claims['iat'] );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( $this->server->clientId, $request['url'] );
			$this->assertStringNotContainsString( 'PRIVATE KEY', $request['url'] );
		}
	}

	public function test_a_refused_token_is_one_new_assertion_and_then_auth_001(): void {
		$this->kit->http->route( 'POST', '~/auth/oauth2/v1/token~', FakeHttpClient::json( array( 'error' => 'invalid_client' ), 401 ) );
		$result = $this->connector->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
	}

	public function test_an_expired_token_is_replaced_once_when_netsuite_answers_401(): void {
		$this->connector->test( $this->connection() );
		$this->assertCount( 1, $this->server->tokenCalls );
		$this->server->token = 'ns-access-token-0002';
		$sent                = $this->connector->send( $this->connection(), $this->item( '3003' ), $this->payload() );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertCount( 2, $this->server->tokenCalls );
	}

	/**
	 * @dataProvider provide_accounts
	 */
	public function test_the_account_id_decides_the_host( string $account, string $host ): void {
		$this->server->account = $account;
		$this->connector->test( $this->connection( array(), array( 'account_id' => $account ) ) );
		$this->assertStringStartsWith( 'https://' . $host . '/services/rest/auth/oauth2/v1/token', $this->kit->http->requests[0]['url'] );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provide_accounts(): array {
		return array(
			'plain'    => array( '1234567', '1234567.suitetalk.api.netsuite.com' ),
			'sandbox'  => array( '1234567_SB1', '1234567-sb1.suitetalk.api.netsuite.com' ),
			'testdrive' => array( 'TSTDRV123456', 'tstdrv123456.suitetalk.api.netsuite.com' ),
		);
	}

	/**
	 * @dataProvider provide_bad_accounts
	 */
	public function test_an_account_id_that_could_name_another_host_is_refused_before_any_request( string $account ): void {
		$result = $this->connector->test( $this->connection( array(), array( 'account_id' => $account ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_bad_accounts(): array {
		return array(
			'empty'     => array( '' ),
			'dots'      => array( 'evil.example.com' ),
			'slash'     => array( '123/evil' ),
			'at sign'   => array( 'user@host' ),
			'too short' => array( 'ab' ),
		);
	}

	public function test_a_missing_client_id_is_auth_001_and_an_algorithm_nobody_offered_falls_back_to_es256(): void {
		$this->assertSame( ErrorCode::Auth001, $this->connector->test( $this->connection( array(), array( 'client_id' => '' ) ) )->error->code );
		$this->assertTrue( $this->connector->test( $this->connection( array(), array( 'algorithm' => 'RS256' ) ) )->ok );
		$header = json_decode( (string) base64_decode( strtr( explode( '.', (string) end( $this->server->tokenCalls )['client_assertion'] )[0], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads a test token.
		$this->assertSame( 'ES256', $header['alg'] );
		$this->assertSame( $this->server->certificateId, $header['kid'] );
	}

	public function test_a_key_that_does_not_fit_the_algorithm_is_auth_001_and_the_message_holds_no_key(): void {
		$rsa = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
		openssl_pkey_export( $rsa, $pem );
		$result = $this->connector->test( $this->connection( array(), array(), array( 'private_key' => $pem ) ) );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'PRIVATE KEY', $result->error->message() . $result->error->detail . json_encode( $result->error->context ) );
		$this->assertTrue( $this->connector->test( $this->connection( array(), array( 'algorithm' => 'PS256' ), array( 'private_key' => $pem ) ) )->ok, 'The same RSA key works with PS256.' );
	}

	public function test_a_wrong_certificate_id_is_auth_001_and_does_not_name_the_certificate(): void {
		$result = $this->connector->test( $this->connection( array(), array( 'certificate_id' => 'ns-cert-WRONG' ) ) );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'ns-cert-WRONG', $result->error->message() . $result->error->detail . json_encode( $result->error->context ) );
	}

	/**
	 * @dataProvider provide_errors
	 */
	public function test_error_shapes_map_to_the_catalog( int $status, array $body, ?ErrorCode $expected ): void {
		$mapped = ( new NetSuiteErrors( 'NetSuite' ) )->map( new ApiResponse( $status, (string) json_encode( $body ) ) );
		if ( null === $expected ) {
			$this->assertNull( $mapped );
			return;
		}
		$this->assertNotNull( $mapped );
		$this->assertSame( $expected, $mapped->code );
	}

	/**
	 * @return array<string,array{0:int,1:array<string,mixed>,2:ErrorCode|null}>
	 */
	public static function provide_errors(): array {
		$detail = static fn( string $code, string $text, string $path = '' ): array => array(
			'status'         => 400,
			'o:errorDetails' => array( array( 'detail' => $text, 'o:errorCode' => $code ) + ( '' === $path ? array() : array( 'o:errorPath' => $path ) ) ),
		);
		return array(
			'invalid client'     => array( 401, array( 'error' => 'invalid_client' ), ErrorCode::Auth001 ),
			'invalid request'    => array( 400, array( 'error' => 'invalid_request' ), ErrorCode::Auth001 ),
			'concurrency'        => array( 429, $detail( 'CONCURRENCY_LIMIT_EXCEEDED', 'Request limit exceeded.' ), ErrorCode::Rate001 ),
			'concurrency as 400' => array( 400, $detail( 'CONCURRENCY_LIMIT_EXCEEDED', 'Request limit exceeded.' ), ErrorCode::Rate001 ),
			'permission'         => array( 403, $detail( 'INSUFFICIENT_PERMISSION', 'You do not have permission.' ), ErrorCode::Auth004 ),
			'invalid content'    => array( 400, $detail( 'INVALID_CONTENT', 'Invalid Field Value 9999 for item', 'item.items[0].item' ), ErrorCode::Val002 ),
			'invalid login'      => array( 401, $detail( 'INVALID_LOGIN', 'Invalid login attempt.' ), null ),
			'unexpected'         => array( 500, $detail( 'UNEXPECTED_ERROR', 'Error ID: abc' ), null ),
			'not found'          => array( 404, $detail( 'NONEXISTENT_ID', 'Record does not exist.' ), null ),
			'empty body'         => array( 400, array(), null ),
		);
	}

	public function test_a_validation_error_names_the_field_path_from_netsuite(): void {
		$response = new ApiResponse( 400, (string) json_encode( array( 'o:errorDetails' => array( array( 'detail' => 'Invalid Field Value 9999', 'o:errorCode' => 'INVALID_CONTENT', 'o:errorPath' => 'item.items[0].item' ) ) ) ) );
		$mapped   = ( new NetSuiteErrors( 'NetSuite' ) )->map( $response );
		$this->assertSame( 'item.items[0].item', $mapped->context['field'] );
		$this->assertSame( 'Invalid Field Value 9999', $mapped->context['reason'] );
	}
}
