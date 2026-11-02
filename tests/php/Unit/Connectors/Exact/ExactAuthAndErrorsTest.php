<?php
/**
 * Sign-in, token rotation, limits and error mapping of the Exact Online connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Exact;

use Estasoft\SyncEngine\Connectors\Exact\ExactConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthRedirect;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Exact is the vendor with a rotating refresh token that works once, a 600 second access token and a 60 calls a minute limit.
 */
final class ExactAuthAndErrorsTest extends ExactTestCase {

	public function test_an_expiring_token_is_refreshed_and_the_new_refresh_token_is_kept_for_the_next_time(): void {
		$connection = $this->connection();
		$this->kit->clock->advance( 400 );
		list( $item, $payload ) = $this->order( '4001' );
		$this->assertTrue( $this->connector->send( $this->kit->storage->load( 1 ), $item, $payload )->isSuccess() );
		$stored = $this->kit->storage->load( 1 );
		$this->assertNotSame( 'refresh-access-0', $stored->secret( OAuthTokenManager::REFRESH_TOKEN ), 'The new refresh token replaced the old one.' );
		$this->assertArrayNotHasKey( 'refresh-access-0', array_filter( $this->server->refreshTokens ), 'The old one works only once.' );
		$this->assertEqualsWithDelta( $this->kit->clock->now()->getTimestamp() + 30 * 86400, $this->kit->storage->rows[1]['credential_expires_at'], 30, 'The 30 day window moves on.' );
		$this->kit->clock->advance( 400 );
		list( $second, $secondPayload ) = $this->order( '4002' );
		$this->assertTrue( $this->connector->send( $this->kit->storage->load( 1 ), $second, $secondPayload )->isSuccess(), 'The second refresh uses the new refresh token.' );
		unset( $connection );
	}

	public function test_a_refresh_token_the_server_no_longer_knows_holds_the_connection_for_a_new_sign_in(): void {
		$this->connection();
		$this->server->refreshTokens = array();
		$this->kit->clock->advance( 400 );
		list( $item, $payload ) = $this->order( '4003' );
		$result = $this->connector->send( $this->kit->storage->load( 1 ), $item, $payload );
		$this->assertSame( ErrorCode::Auth002, $result->error->code );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->status( 1 ) );
		$this->assertSame( array(), $this->server->invoices );
	}

	public function test_a_401_in_the_middle_of_a_token_s_life_refreshes_once_and_repeats_the_call(): void {
		$this->connection();
		$this->server->accessTokens = array();
		$this->server->refreshTokens['refresh-access-0'] = true;
		list( $item, $payload ) = $this->order( '4004' );
		$result = $this->connector->send( $this->kit->storage->load( 1 ), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->server->invoices );
	}

	public function test_the_sign_in_settings_follow_the_region_and_ask_for_no_scope(): void {
		$connector = new ExactConnector( $this->kit->deps );
		$config    = $connector->config( $this->connection( array( 'region' => 'co.uk' ) ) );
		$this->assertSame( 'https://start.exactonline.co.uk/api/oauth2/auth', $config->authorizeUrl );
		$this->assertSame( 'https://start.exactonline.co.uk/api/oauth2/token', $config->tokenUrl );
		$this->assertSame( array(), $config->scopes );
		$this->assertSame( 'body', $config->clientAuth );
		$this->assertFalse( $config->usePkce );
		$this->assertSame( array( 'force_login' => '0' ), $config->extraAuthorizeParams );
		$this->assertSame( 30, $config->refreshTokenLifetimeDays );
		$this->assertSame( 600, $config->accessTokenFallbackSeconds );
		$this->assertContains( 'start.exactonline.de', $config->allowedHosts );
	}

	public function test_the_redirect_address_is_shown_and_the_form_has_region_id_and_secret(): void {
		$fields = $this->connector->credentialFields();
		$this->assertSame( array( 'region', 'client_id', 'client_secret', 'redirect_uri' ), array_map( static fn( $f ) => $f->name, $fields ) );
		$this->assertSame( OAuthRedirect::uri(), $fields[3]->default );
		$this->assertArrayHasKey( 'co.uk', $fields[0]->options );
	}

	public function test_an_unknown_region_is_refused_before_any_request(): void {
		$connection = $this->connection( array( 'region' => 'xx' ) );
		$test       = $this->connector->test( $connection );
		$this->assertFalse( $test->ok );
		$this->assertSame( ErrorCode::Val002, $test->error->code );
		$this->assertSame( 'address', $test->error->context['field'] );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_the_administrations_of_the_signed_in_person_are_listed_and_one_is_chosen(): void {
		$this->server->divisions[] = array( 'Code' => '777', 'Description' => 'Second BV' );
		$connection = $this->connection();
		$connector  = new ExactConnector( $this->kit->deps );
		$this->assertSame( array( array( 'id' => '4242', 'name' => 'Test BV' ), array( 'id' => '777', 'name' => 'Second BV' ) ), $connector->organisations( $connection ) );
		$this->assertSame( array( 'division' => '777', 'division_name' => 'Second BV' ), $connector->selectOrganisation( $connection, '777' ) );
	}

	public function test_test_connection_reports_user_company_permissions_vat_codes_and_the_journal(): void {
		$result = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Test BV', $result->company );
		$this->assertSame( 'Exact Online REST API v1', $result->version );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertSame( 3, $result->detected['vat_codes'], 'Sales and both-way VAT codes, not the purchase-only one.' );
		$this->assertSame( '70', $result->detected['journal'] );
		$this->assertSame( 'Test User', $result->detected['user'] );
	}

	public function test_test_connection_without_a_division_asks_for_one(): void {
		$result = $this->connector->test( $this->connection( array( 'division' => '' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( 'division', $result->error->context['field'] );
	}

	public function test_test_connection_names_a_read_the_app_may_not_do(): void {
		$this->server->fail( 'GET', '#logistics/Items#', $this->server->error( 403, 'Forbidden' ) );
		$result = $this->connector->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( array( 'read_items' ), $result->missingPermissions() );
	}

	public function test_the_tax_codes_come_from_the_vat_codes_with_percent_from_the_fraction(): void {
		$codes = $this->connector->listTaxes( $this->connection() );
		$this->assertContains( array( 'code' => '2', 'label' => 'VAT high 21%', 'rate_percent' => 21.0 ), $codes );
		$this->assertContains( array( 'code' => '4', 'label' => 'VAT low 9%', 'rate_percent' => 9.0 ), $codes );
		$this->assertNotContains( '9', array_column( $codes, 'code' ), 'A purchase-only code is not offered.' );
	}

	public function test_a_429_waits_until_the_minute_counter_resets(): void {
		$this->kit->http->route(
			'POST',
			'#SalesInvoices#',
			function (): HttpResponse {
				$now = $this->kit->clock->now()->getTimestamp();
				return new HttpResponse( 429, '', array( 'x-ratelimit-minutely-remaining' => '0', 'x-ratelimit-minutely-reset' => (string) ( ( $now + 36 ) * 1000 ), 'x-ratelimit-remaining' => '4000' ) );
			}
		);
		list( $item, $payload ) = $this->order( '4010' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 37, $result->error->retryAfter );
		$this->assertSame( ErrorClass::Retry, $result->error->errorClass() );
	}

	public function test_a_429_with_the_daily_counter_used_up_waits_until_the_day_resets(): void {
		$this->kit->http->route(
			'POST',
			'#SalesInvoices#',
			function (): HttpResponse {
				$now = $this->kit->clock->now()->getTimestamp();
				return new HttpResponse( 429, '', array( 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) ( ( $now + 7200 ) * 1000 ) ) );
			}
		);
		list( $item, $payload ) = $this->order( '4011' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 7201, $result->error->retryAfter );
	}

	public function test_a_429_without_headers_waits_a_minute_and_a_huge_wait_is_capped_at_a_day(): void {
		$this->server->fail( 'POST', '#SalesInvoices#', new HttpResponse( 429, '' ) );
		list( $item, $payload ) = $this->order( '4012' );
		$this->assertSame( 60, $this->connector->send( $this->connection(), $item, $payload )->error->retryAfter );
		$this->server->fail( 'POST', '#SalesInvoices#', new HttpResponse( 429, '', array( 'retry-after' => '999999' ) ) );
		list( $item2, $payload2 ) = $this->order( '4013' );
		$this->assertSame( 86400, $this->connector->send( $this->connection(), $item2, $payload2 )->error->retryAfter );
	}

	public function test_the_daily_cap_of_5000_calls_stops_the_connector_with_rate_001_and_the_wait_until_midnight(): void {
		$connection = $this->connection();
		$key        = 'apiday:1:' . $this->kit->clock->now()->format( 'Ymd' );
		$this->kit->rates->compareAndSet( $key, null, 5000.0 );
		list( $item, $payload ) = $this->order( '4014' );
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertGreaterThan( 3600, $result->error->retryAfter );
		$this->assertSame( 0, count( $this->server->log ), 'Not a single call went out.' );
	}

	public function test_calls_are_paced_at_one_a_second(): void {
		list( $item, $payload ) = $this->order( '4015' );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertGreaterThan( 5, $this->kit->http->count() );
		$this->assertNotEmpty( $this->kit->slept );
		foreach ( $this->kit->slept as $microseconds ) {
			$this->assertLessThanOrEqual( 1000000, $microseconds );
		}
	}

	public function test_the_errors_of_exact_map_to_the_catalog(): void {
		$cases = array(
			'403 without a message'  => array( $this->server->error( 403, 'Forbidden' ), ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'division refused'       => array( $this->server->error( 400, 'Wrong division' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'division out of scope'  => array( $this->server->error( 403, 'User division is not within division scope' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'a validation message'   => array( $this->server->error( 400, 'Journal is mandatory' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'a server error'         => array( $this->server->error( 500, 'Boom' ), ErrorCode::Net002, ErrorClass::Retry ),
			'maintenance (503)'      => array( FakeHttpClient::status( 503, 'Down for maintenance' ), ErrorCode::Net002, ErrorClass::Retry ),
			'a time out'             => array( FakeHttpClient::timeout(), ErrorCode::Net001, ErrorClass::Retry ),
			'a certificate problem'  => array( FakeHttpClient::certificateError(), ErrorCode::Net003, ErrorClass::NeedsPerson ),
		);
		foreach ( $cases as $name => $case ) {
			$server = clone $this->server;
			$this->server->failures = array();
			$this->server->fail( 'POST', '#SalesInvoices#', $case[0] );
			list( $item, $payload ) = $this->order( (string) ( 4100 + array_search( $name, array_keys( $cases ), true ) ) );
			$result = $this->connector->send( $this->connection(), $item, $payload );
			$this->assertInstanceOf( ConnectorError::class, $result->error, $name );
			$this->assertSame( $case[1], $result->error->code, $name );
			$this->assertSame( $case[2], $result->error->errorClass(), $name );
			unset( $server );
		}
	}

	public function test_the_text_of_a_400_is_kept_short_and_without_secrets_or_the_host(): void {
		$this->server->fail( 'POST', '#SalesInvoices#', $this->server->error( 400, 'Bad request for start.exactonline.nl with ' . self::SECRET . ' and access-0 ' . str_repeat( 'x', 400 ) ) );
		list( $item, $payload ) = $this->order( '4020' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$text   = $result->error->message() . '|' . $result->error->detail . '|' . wp_json_encode( $result->error->context );
		$this->assertStringNotContainsString( self::SECRET, $text );
		$this->assertStringNotContainsString( 'access-0', $text );
		$this->assertStringNotContainsString( 'start.exactonline.nl', $text );
		$this->assertLessThanOrEqual( 200, mb_strlen( (string) $result->error->context['reason'] ) );
	}

	public function test_the_division_number_is_scrubbed_from_messages(): void {
		$this->server->fail( 'POST', '#SalesInvoices#', $this->server->error( 400, 'Entry in division 4242 is locked' ) );
		list( $item, $payload ) = $this->order( '4021' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertStringNotContainsString( '4242', (string) wp_json_encode( $result->error->context ) );
	}

	public function test_the_client_never_asks_for_a_record_that_may_not_exist(): void {
		list( $item, $payload ) = $this->order( '4030' );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->connector->send( $this->connection(), $item, $payload );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( 'invalid', $request['url'] );
		}
		$errors = array_filter( $this->server->log, static fn( string $line ): bool => str_contains( $line, '404' ) );
		$this->assertSame( array(), $errors, 'Exact blocks a key after ten errors an hour, so no probing.' );
	}
}
