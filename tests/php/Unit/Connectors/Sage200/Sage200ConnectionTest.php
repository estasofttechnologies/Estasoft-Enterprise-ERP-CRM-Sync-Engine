<?php
/**
 * Test connection, sign-in, the connect form and the error mapping of the Sage 200 connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage200;

use Estasoft\SyncEngine\Connectors\Sage200\Sage200Connector;
use Estasoft\SyncEngine\Connectors\Sage200\Sage200OAuth;
use Estasoft\SyncEngine\Connectors\Sage200\Sage200Site;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthRedirect;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSage200Server;

final class Sage200ConnectionTest extends Sage200TestCase {

	public function test_the_test_shows_the_edition_the_company_the_permissions_and_the_tax_codes(): void {
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok, (string) $result->error?->message() . ' ' . (string) ( $result->error->context['reason'] ?? '' ) );
		$this->assertSame( 'Sage 200 Standard', $result->version );
		$this->assertSame( 'Dunes Trading Ltd (Head office)', $result->company );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertCount( 6, $result->permissions );
		$this->assertSame( array( '1', '0', '5' ), $result->detected['tax_codes'] );
		$this->assertSame( 'GBP', $result->detected['base_currency'] );
		foreach ( $this->server->calls as $call ) {
			$this->assertSame( 'GET', $call['method'], 'Test connection writes nothing.' );
		}
	}

	public function test_the_company_name_is_read_from_the_sites_when_sign_in_saved_none(): void {
		$connection = $this->kit->connection(
			'sage200',
			array( 'client_id' => FakeSage200Server::CLIENT_ID, 'site_id' => FakeSage200Server::SITE, 'company_id' => FakeSage200Server::COMPANY ),
			$this->server->signedIn( $this->kit->clock->now()->getTimestamp() ),
			5
		);
		$result = $this->connector()->test( $connection );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Dunes Trading Ltd', $result->company );
	}

	public function test_a_connection_without_a_chosen_site_and_company_says_so_before_any_request(): void {
		$connection = $this->kit->connection( 'sage200', array( 'client_id' => FakeSage200Server::CLIENT_ID ), $this->server->signedIn( $this->kit->clock->now()->getTimestamp() ), 6 );
		$result     = $this->connector()->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'site_id', $result->error->context['field'] );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_resource_the_installed_version_lacks_is_named_when_connecting(): void {
		$this->server->missingResources = array( 'sop_returns' );
		$result                         = $this->connector()->test( $this->connection() );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'sop_returns', (string) $result->error->context['reason'] );
		$this->assertFalse( $result->permissions['read sop_returns'] );
	}

	public function test_a_module_the_user_may_not_open_is_a_missing_permission_not_a_failure(): void {
		$this->server->failNext( 'GET', '#/sop_returns$#', FakeHttpClient::status( 403, '' ) );
		$result = $this->connector()->test( $this->connection() );

		$this->assertTrue( $result->ok );
		$this->assertSame( array( 'read sop_returns' ), $result->missingPermissions() );
	}

	public function test_the_professional_edition_uses_its_own_base_address(): void {
		$connection = $this->connection( array( 'edition' => 'professional' ) );

		$this->assertSame( 'https://api.columbus.sage.com/uk/sage200extra/accounts/v1', Sage200Site::base( $connection ) );
		$this->assertSame( 'https://api.columbus.sage.com/uk/sage200/accounts/v1', Sage200Site::base( $this->connection() ) );
		$this->assertSame( 'professional', Sage200Site::edition( $connection ) );
		$this->assertSame( 'standard', Sage200Site::edition( $this->connection( array( 'edition' => 'anything' ) ) ) );
	}

	public function test_a_missing_or_wrong_subscription_key_is_auth_007(): void {
		$connection = $this->kit->connection(
			'sage200',
			array( 'client_id' => FakeSage200Server::CLIENT_ID, 'site_id' => FakeSage200Server::SITE, 'company_id' => FakeSage200Server::COMPANY ),
			array( 'subscription_key' => 'not-the-key' ) + $this->server->signedIn( $this->kit->clock->now()->getTimestamp() ),
			7
		);
		$this->assertSame( ErrorCode::Auth007, $this->connector()->test( $connection )->error->code );

		$secrets = $this->server->signedIn( $this->kit->clock->now()->getTimestamp() );
		unset( $secrets['subscription_key'] );
		$none = $this->kit->connection( 'sage200', array( 'client_id' => FakeSage200Server::CLIENT_ID, 'site_id' => FakeSage200Server::SITE, 'company_id' => FakeSage200Server::COMPANY ), $secrets, 8 );
		$this->assertSame( ErrorCode::Auth007, $this->connector()->test( $none )->error->code );
	}

	public function test_the_subscription_key_is_optional_and_the_header_is_sent_only_when_it_is_filled(): void {
		$this->server->requireKey = false;
		$secrets                  = $this->server->signedIn( $this->kit->clock->now()->getTimestamp() );
		unset( $secrets['subscription_key'] );
		$none   = $this->kit->connection( 'sage200', array( 'client_id' => FakeSage200Server::CLIENT_ID, 'site_id' => FakeSage200Server::SITE, 'company_id' => FakeSage200Server::COMPANY ), $secrets, 13 );
		$result = $this->connector()->test( $none );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		foreach ( $this->server->calls as $call ) {
			$this->assertArrayNotHasKey( 'ocp-apim-subscription-key', array_change_key_case( $call['headers'], CASE_LOWER ) );
		}
		$fields = array_column( $this->connector()->credentialFields(), null, 'name' );
		$this->assertFalse( $fields['subscription_key']->required );
		$this->assertTrue( $fields['subscription_key']->secret );

		$this->server->calls = array();
		$this->assertTrue( $this->connector()->test( $this->connection() )->ok );
		$this->assertSame( FakeSage200Server::SUBSCRIPTION_KEY, array_change_key_case( $this->server->calls[0]['headers'], CASE_LOWER )['ocp-apim-subscription-key'] );
	}

	public function test_a_wrong_site_or_company_says_to_sign_in_again_and_choose_them(): void {
		$connection = $this->kit->connection(
			'sage200',
			array( 'client_id' => FakeSage200Server::CLIENT_ID, 'site_id' => 'other-site', 'company_id' => FakeSage200Server::COMPANY ),
			$this->server->signedIn( $this->kit->clock->now()->getTimestamp() ),
			9
		);

		$error = $this->connector()->test( $connection )->error;
		$this->assertSame( ErrorCode::Val002, $error->code );
		$this->assertSame( 'site_id', $error->context['field'] );
	}

	public function test_an_expired_access_token_is_refreshed_with_the_secret_in_the_body_and_no_pkce(): void {
		$connection = $this->connection();
		$this->kit->storage->save(
			$connection->id,
			array(
				'client_secret'       => $connection->secret( 'client_secret' ),
				'subscription_key'    => $connection->secret( 'subscription_key' ),
				'oauth_access_token'  => $connection->secret( 'oauth_access_token' ),
				'oauth_refresh_token' => $connection->secret( 'oauth_refresh_token' ),
				'oauth_expires_at'    => (string) ( $this->kit->clock->now()->getTimestamp() - 10 ),
			)
		);
		$result = $this->connector()->test( $this->kit->storage->load( $connection->id ) );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertCount( 1, $this->server->tokenRequests );
		$form = $this->server->tokenRequests[0];
		$this->assertSame( 'refresh_token', $form['grant_type'] );
		$this->assertSame( FakeSage200Server::CLIENT_ID, $form['client_id'] );
		$this->assertSame( FakeSage200Server::CLIENT_SECRET, $form['client_secret'] );
		$this->assertArrayNotHasKey( 'code_verifier', $form );
		$this->assertSame( 'id.sage.com', $this->server->calls[0]['host'] );
	}

	public function test_a_refused_refresh_token_needs_a_new_sign_in(): void {
		$connection = $this->kit->connection(
			'sage200',
			array( 'client_id' => FakeSage200Server::CLIENT_ID, 'site_id' => FakeSage200Server::SITE, 'company_id' => FakeSage200Server::COMPANY ),
			array(
				'client_secret'       => FakeSage200Server::CLIENT_SECRET,
				'subscription_key'    => FakeSage200Server::SUBSCRIPTION_KEY,
				'oauth_access_token'  => 'old-access',
				'oauth_refresh_token' => 'ninety-days-ago',
				'oauth_expires_at'    => (string) ( $this->kit->clock->now()->getTimestamp() - 10 ),
			),
			11
		);

		$this->assertSame( ErrorCode::Auth002, $this->connector()->test( $connection )->error->code );
	}

	public function test_the_sign_in_settings_use_sage_id_with_the_audience_and_no_pkce(): void {
		$config = ( new Sage200OAuth() )->config( $this->connection() );

		$this->assertSame( 'https://id.sage.com/authorize', $config->authorizeUrl );
		$this->assertSame( 'https://id.sage.com/oauth/token', $config->tokenUrl );
		$this->assertSame( array( 'openid', 'profile', 'email', 'offline_access' ), $config->scopes );
		$this->assertSame( array( 'audience' => 's200ukipd/sage200' ), $config->extraAuthorizeParams );
		$this->assertFalse( $config->usePkce );
		$this->assertSame( 'body', $config->clientAuth );
		$this->assertSame( 90, $config->refreshTokenLifetimeDays );
		$this->assertSame( array( 'id.sage.com' ), $config->allowedHosts );
	}

	public function test_sites_and_companies_are_offered_after_sign_in_and_the_choice_is_stored(): void {
		$connector  = new Sage200Connector( $this->kit->deps );
		$connection = $this->kit->connection( 'sage200', array( 'client_id' => FakeSage200Server::CLIENT_ID ), $this->server->signedIn( $this->kit->clock->now()->getTimestamp() ), 12 );
		$list       = $connector->organisations( $connection );

		$this->assertSame( array( 'site-1111/comp-2222', 'site-1111/comp-3333' ), array_column( $list, 'id' ) );
		$this->assertSame( 'Dunes Trading Ltd (Head office)', $list[0]['name'] );
		$this->assertSame(
			array( 'site_id' => 'site-1111', 'company_id' => 'comp-3333', 'company_name' => 'Dunes Export Ltd (Head office)' ),
			$connector->selectOrganisation( $connection, 'site-1111/comp-3333' )
		);
		$headers = array_change_key_case( $this->server->calls[0]['headers'], CASE_LOWER );
		$this->assertArrayNotHasKey( 'x-site', $headers, 'The list of sites is read before a site is chosen.' );
	}

	public function test_ping_is_cheap_and_never_throws(): void {
		$connection = $this->connection();
		$this->assertTrue( $this->connector()->ping( $connection ) );
		$this->assertSame( 1, $this->kit->http->count(), 'One cheap call, not the whole Test connection.' );
		$this->server->failNext( 'GET', '#/tax_codes$#', FakeHttpClient::status( 500, 'boom' ) );
		$this->assertFalse( $this->connector()->ping( $this->connection() ) );
	}

	public function test_the_connect_form_names_the_app_the_key_the_edition_and_the_settings_and_shows_the_redirect_address(): void {
		$fields = array_column( $this->connector()->credentialFields(), null, 'name' );

		$this->assertSame( 'app', $fields['client_id']->group );
		$this->assertTrue( $fields['client_secret']->secret );
		$this->assertTrue( $fields['subscription_key']->secret );
		$this->assertSame( array( 'standard', 'professional' ), array_keys( $fields['edition']->options ) );
		$this->assertSame( 'advanced', $fields['shipping_product']->group );
		$this->assertSame( 'display', $fields['redirect_uri']->type );
		$this->assertSame( OAuthRedirect::uri(), $fields['redirect_uri']->default );
		$this->assertArrayNotHasKey( 'address', $fields, 'The address is fixed; there is nothing to type.' );
	}

	public function test_the_flags_say_what_the_connector_really_does(): void {
		$flags = $this->connector()->capabilities();
		foreach ( array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::ShadowLookup ) as $on ) {
			$this->assertTrue( $flags[ $on->value ], $on->value );
		}
		foreach ( array( Capability::Reconciliation, Capability::StockIn, Capability::TrackingIn, Capability::InvoiceReadback, Capability::PricesIn, Capability::Webhooks ) as $off ) {
			$this->assertFalse( $flags[ $off->value ], $off->value );
		}
		$this->assertSame( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->connector()->supportedEntities() );
	}

	/**
	 * @return array<string,array{0:int,1:array<mixed>|string,2:ErrorCode,3:ErrorClass}>
	 */
	public static function answers(): array {
		$message = static fn( string $text ): array => array( array( '$severity' => 'error', '$message' => $text ) );
		return array(
			'no right'       => array( 403, array( 'message' => 'Forbidden' ), ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'busy'           => array( 503, '<html>Service Unavailable</html>', ErrorCode::Net002, ErrorClass::Retry ),
			'not configured' => array( 524, 'A timeout occurred', ErrorCode::Net002, ErrorClass::Retry ),
			'validation'     => array( 400, $message( 'Customer is on hold <b>today</b>' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'conflict'       => array( 409, $message( 'The record is being edited' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'unprocessable'  => array( 422, array( 'message' => 'Bad value' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'key rejected'   => array( 403, array( 'message' => 'Access denied due to invalid subscription key.' ), ErrorCode::Auth007, ErrorClass::NeedsPerson ),
		);
	}

	/**
	 * @dataProvider answers
	 * @param array<mixed>|string $body Body.
	 */
	public function test_every_answer_of_the_api_has_a_code_and_a_class( int $status, array|string $body, ErrorCode $code, ErrorClass $class ): void {
		$this->server->failNext( 'POST', '#/sop_orders$#', is_array( $body ) ? FakeHttpClient::json( $body, $status ) : FakeHttpClient::status( $status, $body ) );
		$result = $this->sendOrder();

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( $code, $result->error->code );
		$this->assertSame( $class, $this->connector()->classifyError( $result->error ) ?? $result->error->errorClass() );
	}

	public function test_the_first_message_is_the_reason_without_tags(): void {
		$this->server->failNext( 'POST', '#/sop_orders$#', FakeHttpClient::json( array( array( '$severity' => 'error', '$message' => 'Customer <b>Amelia</b> is on hold' ) ), 400 ) );
		$result = $this->sendOrder();

		$this->assertSame( 'Customer Amelia is on hold', $result->error->context['reason'] );
	}

	public function test_a_rate_limit_waits_as_told(): void {
		$this->server->failNext( 'POST', '#/sop_orders$#', FakeHttpClient::tooManyRequests( 41 ) );
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 41, $result->error->retryAfter );
	}

	public function test_a_message_never_carries_the_secret_the_key_or_the_company(): void {
		$text = 'Failed for company ' . FakeSage200Server::COMPANY . ' key ' . FakeSage200Server::SUBSCRIPTION_KEY . ' secret ' . FakeSage200Server::CLIENT_SECRET;
		$this->server->failNext( 'POST', '#/sop_orders$#', FakeHttpClient::json( array( array( '$severity' => 'error', '$message' => $text ) ), 400 ) );
		$result = $this->sendOrder();
		$shown  = $result->error->message() . ' ' . $result->error->detail . ' ' . (string) json_encode( $result->error->context );

		$this->assertFalse( $result->isSuccess() );
		foreach ( array( FakeSage200Server::COMPANY, FakeSage200Server::SUBSCRIPTION_KEY, FakeSage200Server::CLIENT_SECRET ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $shown );
		}
	}
}
