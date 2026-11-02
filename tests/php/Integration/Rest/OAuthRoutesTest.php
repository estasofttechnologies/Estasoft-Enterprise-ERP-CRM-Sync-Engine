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
use Estasoft\SyncEngine\Core\Repositories\LogQuery;
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
		$this->assertSame( \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthRedirect::uri(), $connector->authorized['redirectUri'], 'one stable address for the whole site' );
		$this->assertStringEndsWith( '/estasoft-sync/v1/oauth/callback', $connector->authorized['redirectUri'] );
	}

	/**
	 * Start a sign-in as an administrator and return the state, then become a visitor the way the provider's redirect does.
	 *
	 * @param int                      $id        Connection.
	 * @param RestFakeSignInConnector  $connector Connector.
	 */
	private function startAndLeave( int $id, RestFakeSignInConnector $connector ): string {
		$this->actAs( 'administrator' );
		$this->call( 'POST', "/connections/$id/oauth/start" );
		$this->actAs( 'anonymous' ); // The provider redirect carries no REST nonce, so the user is unknown to WordPress.
		return $connector->authorized['state'];
	}

	/**
	 * Assert that an answer is the redirect that tells the browser the sign-in failed, with no JSON body.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param string            $message  Message for a failure.
	 */
	private function assertFailedRedirect( \WP_REST_Response $response, string $message ): void {
		$this->assertSame( 302, $response->get_status(), $message . ' ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( admin_url( 'admin.php?page=estasoft-sync' ) . '#/connections?signin=failed', $response->get_headers()['Location'], $message );
		$this->assertNull( $response->get_data(), $message . ': nothing is written to the browser' );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	public function test_the_callback_works_without_a_login_cookie_and_the_state_is_used_once(): void {
		list( $id, $connector ) = $this->signInConnection();
		$state                  = $this->startAndLeave( $id, $connector );

		$done = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'the-code' ) );
		$this->assertSame( 302, $done->get_status() );
		$this->assertStringEndsWith( "#/connections/$id?signin=ok&connection=$id", $done->get_headers()['Location'] );
		$this->assertNull( $done->get_data() );
		$this->assertSame( 'the-code', $connector->completed['code'] );
		$this->assertNotSame( '', $connector->completed['codeVerifier'] );
		$this->assertSame( $connector->authorized['codeChallenge'], $this->container->get( RestModule::OAUTH_STATE )->challenge( $connector->completed['codeVerifier'] ), 'the verifier belongs to the challenge' );

		$connector->completed = array();
		$this->assertFailedRedirect( $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'the-code' ) ), 'the state was used up' );
		$this->assertSame( array(), $connector->completed, 'a used state exchanges nothing' );
	}

	public function test_a_state_from_another_connection_a_made_up_state_and_an_expired_one_end_in_the_failed_redirect_not_in_json(): void {
		list( $id, $connector ) = $this->signInConnection();
		$other                  = $this->connection( 'signin' );
		$state                  = $this->startAndLeave( $id, $connector );

		$this->assertFailedRedirect( $this->call( 'GET', "/connections/$other/oauth/callback", array( 'state' => $state, 'code' => 'c' ) ), 'another connection' );
		$this->assertFailedRedirect( $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => str_repeat( 'a', 43 ), 'code' => 'c' ) ), 'made up' );
		$this->assertSame( array(), $connector->completed );

		$this->container->get( RestModule::STORE )->delete( 'oauth_' . hash( 'sha256', $state ) ); // What the end of the ten minutes does.
		$this->assertFailedRedirect( $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'c' ) ), 'expired' );
	}

	public function test_a_visitor_learns_nothing_the_same_redirect_for_a_connection_that_exists_and_one_that_does_not(): void {
		list( $id )  = $this->signInConnection();
		$this->actAs( 'anonymous' );
		$state = str_repeat( 'b', 43 );
		$known = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'c' ) );
		$none  = $this->call( 'GET', "/connections/987654/oauth/callback", array( 'state' => $state, 'code' => 'c' ) );
		$zero  = $this->call( 'GET', '/connections/0/oauth/callback', array( 'state' => $state, 'code' => 'c' ) );
		$this->assertFailedRedirect( $known, 'known connection' );
		$this->assertFailedRedirect( $none, 'unknown connection' );
		$this->assertFailedRedirect( $zero, 'id zero' );
		$this->assertSame( $known->get_headers(), $none->get_headers() );
	}

	public function test_a_request_without_the_shape_of_a_state_is_refused_as_any_unauthorised_rest_call_and_says_nothing_more(): void {
		list( $id ) = $this->signInConnection();
		$this->actAs( 'anonymous' );
		foreach ( array( array(), array( 'code' => 'c' ), array( 'state' => '' ), array( 'state' => 'short' ), array( 'state' => str_repeat( 'a', 43 ) . '!' ), array( 'state' => array( 'a' ) ) ) as $params ) {
			$response = $this->call( 'GET', "/connections/$id/oauth/callback", $params );
			$this->assertSame( 401, $response->get_status(), wp_json_encode( $params ) );
			$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
			$this->assertSame( 'You do not have permission to do this.', $response->get_data()['message'] );
		}
		$this->actAs( 'administrator' );
		$this->assertSame( 403, $this->call( 'GET', "/connections/$id/oauth/callback", array( 'code' => 'c' ) )->get_status(), 'a signed-in person is told 403, as everywhere' );
	}

	public function test_a_person_who_lost_the_capability_gets_the_failed_redirect_and_the_state_is_used_up_anyway(): void {
		list( $id, $connector ) = $this->signInConnection();
		$state                  = $this->startAndLeave( $id, $connector );

		$user = new \WP_User( $this->userId( 'administrator' ) );
		$user->remove_role( 'administrator' );
		$user->add_role( 'subscriber' );
		try {
			$this->assertFailedRedirect( $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'c' ) ), 'the person no longer holds estasoft_sync_configure' );
		} finally {
			$user->remove_role( 'subscriber' );
			$user->add_role( 'administrator' );
		}
		$this->assertSame( array(), $connector->completed, 'nothing was exchanged' );
		$this->assertFailedRedirect( $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'c' ) ), 'and giving the capability back does not revive the link' );
	}

	public function test_a_browser_signed_in_as_somebody_else_cannot_finish_a_sign_in_that_another_person_started(): void {
		list( $id, $connector ) = $this->signInConnection();
		$state                  = $this->startAndLeave( $id, $connector ); // Started by the administrator.

		// The link reaches a browser where another person is signed in (a prepared link; the attacker started it with their own vendor account).
		$stranger = $this->userId( 'subscriber' );
		wp_set_current_user( $stranger );
		try {
			$this->assertFailedRedirect( $this->call( 'GET', '/oauth/callback', array( 'state' => $state, 'code' => 'attacker-code' ) ), 'the signed-in person is not the one who started it' );
		} finally {
			wp_set_current_user( 0 );
		}
		$this->assertSame( array(), $connector->completed, 'The code was not exchanged.' );
		$this->assertFailedRedirect( $this->call( 'GET', '/oauth/callback', array( 'state' => $state, 'code' => 'attacker-code' ) ), 'and the state is used up' );
	}

	public function test_the_person_who_started_it_can_finish_it_while_signed_in(): void {
		list( $id, $connector ) = $this->signInConnection();
		$state                  = $this->startAndLeave( $id, $connector );

		wp_set_current_user( $this->userId( 'administrator' ) );
		try {
			$done = $this->call( 'GET', '/oauth/callback', array( 'state' => $state, 'code' => 'the-code' ) );
		} finally {
			wp_set_current_user( 0 );
		}
		$this->assertSame( 302, $done->get_status() );
		$this->assertStringEndsWith( "signin=ok&connection=$id", $done->get_headers()['Location'] );
		$this->assertSame( 'the-code', $connector->completed['code'] );
	}

	public function test_odd_values_after_a_good_state_still_end_in_a_redirect(): void {
		list( $id, $connector ) = $this->signInConnection();
		foreach (
			array(
				'a code that is too long' => array( 'code' => str_repeat( 'c', 2049 ) ),
				'a code that is a list'   => array( 'code' => array( 'x' ) ),
				'an error that is a list' => array( 'error' => array( 'x' ), 'code' => 'c' ),
			) as $label => $params
		) {
			$state    = $this->startAndLeave( $id, $connector );
			$response = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state ) + $params );
			$this->assertSame( 302, $response->get_status(), $label . ' ' . wp_json_encode( $response->get_data() ) );
			$this->assertNull( $response->get_data(), $label );
			$this->assertMatchesRegularExpression( '/\?signin=(failed|cancelled)&connection=\d+$/', $response->get_headers()['Location'], $label );
		}
		$this->assertSame( array(), $connector->completed ?? array(), 'the oversized or malformed code was never sent to the provider' );
	}

	public function test_a_connector_that_breaks_ends_in_the_failed_redirect_and_the_log_gets_the_class_only(): void {
		list( $id, $connector ) = $this->signInConnection();
		$connector->throws      = new \RuntimeException( 'client_secret=hunter2 refused' );
		$state                  = $this->startAndLeave( $id, $connector );
		$this->assertFailedRedirect( $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $state, 'code' => 'c' ) ), 'broken connector' );

		$this->actAs( 'administrator' );
		$logged = wp_json_encode( $this->container->get( Services::LOG_REPOSITORY )->query( new LogQuery( 'error' ) )->toArray() );
		$this->assertStringContainsString( 'Unexpected failure in the REST API: RuntimeException', $logged );
		$this->assertStringNotContainsString( 'hunter2', $logged );
	}

	public function test_a_cancelled_sign_in_and_a_failed_exchange_send_the_browser_back_with_a_flag(): void {
		list( $id, $connector ) = $this->signInConnection();
		$this->actAs( 'administrator' );
		$this->call( 'POST', "/connections/$id/oauth/start" );
		$this->actAs( 'anonymous' );
		$cancelled = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $connector->authorized['state'], 'error' => 'access_denied' ) );
		$this->assertStringEndsWith( "?signin=cancelled&connection=$id", $cancelled->get_headers()['Location'] );
		$this->assertSame( '', $connector->completed['code'] ?? '', 'the code was never exchanged' );

		$this->actAs( 'administrator' );
		$this->call( 'POST', "/connections/$id/oauth/start" );
		$connector->completes = new ConnectorError( ErrorCode::Auth003 );
		$this->actAs( 'anonymous' );
		$failed = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $connector->authorized['state'], 'code' => 'x' ) );
		$this->assertStringEndsWith( "?signin=failed&connection=$id", $failed->get_headers()['Location'] );
	}

	public function test_organisations_are_listed_for_a_signed_in_connection(): void {
		list( $id ) = $this->signInConnection();
		$this->actAs( 'administrator' );
		$response = $this->call( 'GET', "/connections/$id/oauth/organisations" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'org-1', $response->get_data()[0]['id'] );
	}
}
