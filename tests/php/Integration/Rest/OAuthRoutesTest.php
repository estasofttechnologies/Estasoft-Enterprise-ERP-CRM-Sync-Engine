<?php
/**
 * Sign-in routes: refused for key-style connectors, complete plumbing for sign-in connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Rest\RestModule;
use Estasoft\SyncEngine\Tests\Support\RestFakeSignInConnector;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class OAuthRoutesTest extends RestIntegrationCase {

	private function connection( string $connector ): int {
		return $this->container->get( Services::CONNECTION_REPOSITORY )->create(
			array(
				'connector' => $connector,
				'type'      => 'erp',
				'label'     => 'Sign-in system',
			),
			array(),
			array()
		);
	}

	/**
	 * Install a sign-in connector and a connection to it.
	 *
	 * @return array{0:int,1:RestFakeSignInConnector}
	 */
	private function signInConnection(): array {
		$connector = new RestFakeSignInConnector( 'signin' );
		$this->replace( Services::CONNECTOR_REGISTRY, new ConnectorRegistry( array( $connector ) ) );
		return array( $this->connection( 'signin' ), $connector );
	}

	public function test_a_key_style_connector_answers_sync_cfg_004_on_every_sign_in_route(): void {
		$id = $this->connection( 'odoo' );
		$this->actAs( 'administrator' );
		foreach ( array( array( 'POST', "/connections/$id/oauth/start" ), array( 'GET', "/connections/$id/oauth/organisations" ) ) as $request ) {
			$response = $this->call( $request[0], $request[1] );
			$this->assertSame( 400, $response->get_status(), $request[1] );
			$this->assertSame( 'SYNC-CFG-004', $response->get_data()['code'] );
			$this->assertStringContainsString( 'does not use sign-in', $response->get_data()['message'] );
		}
	}

	public function test_start_returns_the_provider_address_with_a_one_time_state_and_pkce(): void {
		list( $id, $connector ) = $this->signInConnection();
		$this->actAs( 'administrator' );
		$response = $this->call( 'POST', "/connections/$id/oauth/start" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( 'https://login.example.test/authorize?state=', $response->get_data()['url'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_\-]{40,}$/', $connector->authorized['state'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_\-]{43}$/', $connector->authorized['codeChallenge'], 'SHA-256 challenge' );
		$this->assertStringEndsWith( "/estasoft-sync/v1/connections/$id/oauth/callback", $connector->authorized['redirectUri'] );
	}

	public function test_the_callback_works_without_a_login_cookie_and_the_state_is_used_once(): void {
		list( $id, $connector ) = $this->signInConnection();
		$this->actAs( 'administrator' );
		$this->call( 'POST', "/connections/$id/oauth/start" );
		$state = $connector->authorized['state'];

		$this->actAs( 'anonymous' ); // The provider redirect carries no REST nonce, so the user is unknown to WordPress.
		$done = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'the-code' ) );
		$this->assertSame( 302, $done->get_status() );
		$this->assertStringEndsWith( "#/connections/$id?signin=ok", $done->get_headers()['Location'] );
		$this->assertSame( 'the-code', $connector->completed['code'] );
		$this->assertNotSame( '', $connector->completed['codeVerifier'] );
		$this->assertSame( $connector->authorized['codeChallenge'], $this->container->get( RestModule::OAUTH_STATE )->challenge( $connector->completed['codeVerifier'] ), 'the verifier belongs to the challenge' );

		$again = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'the-code' ) );
		$this->assertSame( 403, $again->get_status(), 'the state was used up' );
	}

	public function test_a_state_from_another_connection_a_made_up_state_and_a_person_who_lost_the_capability_are_refused(): void {
		list( $id, $connector ) = $this->signInConnection();
		$other = $this->connection( 'signin' );
		$this->actAs( 'administrator' );
		$this->call( 'POST', "/connections/$id/oauth/start" );
		$state = $connector->authorized['state'];
		$this->actAs( 'anonymous' );

		$this->assertSame( 403, $this->call( 'GET', "/connections/$other/oauth/callback", array( 'state' => $state, 'code' => 'c' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => str_repeat( 'a', 43 ), 'code' => 'c' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', "/connections/$id/oauth/callback", array( 'code' => 'c' ) )->get_status(), 'no state, no entry' );

		$user = new \WP_User( $this->userId( 'administrator' ) );
		$user->remove_cap( 'estasoft_sync_configure' );
		$user->remove_role( 'administrator' );
		$user->add_role( 'subscriber' );
		$refused = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'c' ) );
		$user->remove_role( 'subscriber' );
		$user->add_role( 'administrator' );
		$this->assertSame( 403, $refused->get_status(), 'the person no longer holds estasoft_sync_configure' );
	}

	public function test_a_cancelled_sign_in_and_a_failed_exchange_send_the_browser_back_with_a_flag(): void {
		list( $id, $connector ) = $this->signInConnection();
		$this->actAs( 'administrator' );
		$this->call( 'POST', "/connections/$id/oauth/start" );
		$this->actAs( 'anonymous' );
		$cancelled = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $connector->authorized['state'], 'error' => 'access_denied' ) );
		$this->assertStringEndsWith( "?signin=cancelled", $cancelled->get_headers()['Location'] );
		$this->assertSame( '', $connector->completed['code'] ?? '', 'the code was never exchanged' );

		$this->actAs( 'administrator' );
		$this->call( 'POST', "/connections/$id/oauth/start" );
		$connector->completes = new ConnectorError( ErrorCode::Auth003 );
		$this->actAs( 'anonymous' );
		$failed = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $connector->authorized['state'], 'code' => 'x' ) );
		$this->assertStringEndsWith( '?signin=failed', $failed->get_headers()['Location'] );
	}

	public function test_organisations_are_listed_for_a_signed_in_connection(): void {
		list( $id ) = $this->signInConnection();
		$this->actAs( 'administrator' );
		$response = $this->call( 'GET', "/connections/$id/oauth/organisations" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'org-1', $response->get_data()[0]['id'] );
	}
}
