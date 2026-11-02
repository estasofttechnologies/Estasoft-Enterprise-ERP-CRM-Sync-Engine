<?php
/**
 * Sign-in, scopes, limits, error mapping and reads of the Fortnox connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Fortnox;

use Estasoft\SyncEngine\Connectors\Fortnox\FortnoxConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthRedirect;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeFortnoxServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

/**
 * Fortnox: a one hour access token, a refresh token that works once, 25 calls in 5 seconds, scopes tied to licences.
 */
final class FortnoxAuthAndReadsTest extends FortnoxTestCase {

	public function test_the_sign_in_settings_follow_the_options_because_each_scope_needs_a_licence(): void {
		$connector = new FortnoxConnector( $this->kit->deps );
		$basic     = $connector->config( $this->connection() );
		$this->assertSame( array( 'companyinformation', 'customer', 'article', 'invoice' ), $basic->scopes );
		$this->assertSame( 'https://apps.fortnox.se/oauth-v1/auth', $basic->authorizeUrl );
		$this->assertSame( 'https://apps.fortnox.se/oauth-v1/token', $basic->tokenUrl );
		$this->assertSame( 'basic', $basic->clientAuth );
		$this->assertTrue( $basic->usePkce );
		$this->assertSame( array( 'access_type' => 'offline' ), $basic->extraAuthorizeParams );
		$this->assertSame( 45, $basic->refreshTokenLifetimeDays );
		$this->assertSame( array( 'apps.fortnox.se' ), $basic->allowedHosts );
		$all = $connector->config( $this->connection( array( 'options' => array( 'document' => 'order', 'stock_source' => 'warehouse', 'record_payment' => 'yes' ) ) ) );
		$this->assertSame( array( 'companyinformation', 'customer', 'article', 'invoice', 'order', 'warehouse', 'payment' ), $all->scopes );
	}

	public function test_the_form_has_client_id_and_secret_and_shows_the_redirect_address(): void {
		$fields = $this->connector->credentialFields();
		$this->assertSame( array( 'client_id', 'client_secret', 'redirect_uri' ), array_map( static fn( $f ) => $f->name, $fields ) );
		$this->assertSame( OAuthRedirect::uri(), $fields[2]->default );
	}

	public function test_a_token_about_to_expire_is_refreshed_with_a_basic_login_and_the_new_refresh_token_is_kept(): void {
		$this->connection();
		$this->kit->clock->advance( 3400 );
		list( $item, $payload ) = $this->order( '4001' );
		$this->assertTrue( $this->connector->send( $this->kit->storage->load( 1 ), $item, $payload )->isSuccess() );
		$request = $this->kit->http->matching( '#oauth-v1/token#' )[0];
		$this->assertSame( 'Basic ' . base64_encode( FakeFortnoxServer::CLIENT_ID . ':' . FakeFortnoxServer::CLIENT_SECRET ), $request['headers']['Authorization'] );
		parse_str( (string) $request['body'], $form );
		$this->assertSame( 'refresh_token', $form['grant_type'] );
		$stored = $this->kit->storage->load( 1 );
		$this->assertNotSame( 'refresh-fx-access-0', $stored->secret( OAuthTokenManager::REFRESH_TOKEN ) );
		$this->assertEqualsWithDelta( $this->kit->clock->now()->getTimestamp() + 45 * 86400, $this->kit->storage->rows[1]['credential_expires_at'], 30 );
		$this->kit->clock->advance( 3400 );
		list( $second, $secondPayload ) = $this->order( '4002' );
		$this->assertTrue( $this->connector->send( $this->kit->storage->load( 1 ), $second, $secondPayload )->isSuccess() );
	}

	public function test_a_refresh_token_that_no_longer_works_holds_the_connection_for_a_new_sign_in(): void {
		$this->connection();
		$this->server->refreshTokens = array();
		$this->kit->clock->advance( 3400 );
		list( $item, $payload ) = $this->order( '4003' );
		$result = $this->connector->send( $this->kit->storage->load( 1 ), $item, $payload );
		$this->assertSame( ErrorCode::Auth002, $result->error->code );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->status( 1 ) );
	}

	public function test_an_invalid_token_answered_with_403_and_code_2000310_is_a_lapsed_sign_in(): void {
		$this->server->fail( 'POST', '#invoices#', $this->server->error( 403, 2000310, 'Ogiltig inloggning' ) );
		list( $item, $payload ) = $this->order( '4004' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Auth002, $result->error->code );
		$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
	}

	public function test_the_errors_of_fortnox_map_to_the_catalog(): void {
		$cases = array(
			'no scope'             => array( $this->server->error( 403, 2000663, 'Har inte behörighet för scope' ), ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'no licence for scope' => array( $this->server->error( 403, 2001101, 'Det finns ingen aktiv licens för önskat scope' ), ErrorCode::Auth006, ErrorClass::NeedsPerson ),
			'no api licence'       => array( $this->server->error( 403, 2001103, 'Api-licens saknas.' ), ErrorCode::Auth006, ErrorClass::NeedsPerson ),
			'oauth licence'        => array( new HttpResponse( 403, '{"error":"error_missing_license"}' ), ErrorCode::Auth006, ErrorClass::NeedsPerson ),
			'a validation message' => array( $this->server->error( 400, 2000588, 'Ogiltig parameter i anropet.' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'wrong body'           => array( $this->server->error( 400, 2001392, 'Ingen eller felaktig typ av data.' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'system exception'     => array( $this->server->error( 500, 1000003, 'System exception' ), ErrorCode::Net002, ErrorClass::Retry ),
			'a plain 500'          => array( FakeHttpClient::status( 500, 'oops' ), ErrorCode::Net002, ErrorClass::Retry ),
			'a gateway time out'   => array( FakeHttpClient::status( 504, '' ), ErrorCode::Net002, ErrorClass::Retry ),
			'a time out'           => array( FakeHttpClient::timeout(), ErrorCode::Net001, ErrorClass::Retry ),
			'a certificate'        => array( FakeHttpClient::certificateError(), ErrorCode::Net003, ErrorClass::NeedsPerson ),
		);
		$index = 0;
		foreach ( $cases as $name => $case ) {
			$this->server->failures = array();
			$this->server->fail( 'POST', '#/3/invoices#', $case[0] );
			list( $item, $payload ) = $this->order( (string) ( 4100 + $index++ ) );
			$result = $this->connector->send( $this->connection(), $item, $payload );
			$this->assertSame( $case[1], $result->error->code, $name );
			$this->assertSame( $case[2], $result->error->errorClass(), $name );
		}
	}

	public function test_a_429_waits_five_seconds_the_length_of_the_sliding_window_or_what_the_server_says(): void {
		$this->server->fail( 'POST', '#invoices#', new HttpResponse( 429, '' ) );
		list( $item, $payload ) = $this->order( '4010' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 5, $result->error->retryAfter );
		$this->assertSame( ErrorClass::Retry, $result->error->errorClass() );
		$this->server->fail( 'POST', '#invoices#', FakeHttpClient::tooManyRequests( 12 ) );
		list( $item2, $payload2 ) = $this->order( '4011' );
		$this->assertSame( 12, $this->connector->send( $this->connection(), $item2, $payload2 )->error->retryAfter );
	}

	public function test_calls_are_paced_below_25_in_5_seconds(): void {
		list( $item, $payload ) = $this->order( '4012' );
		$this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( 4.0, $this->connector->rateLimitHints()['requests_per_second'] );
		foreach ( $this->kit->slept as $microseconds ) {
			$this->assertLessThanOrEqual( 250000, $microseconds );
		}
	}

	public function test_the_text_of_an_error_is_cut_and_has_no_secret_token_or_host(): void {
		$this->server->fail( 'POST', '#invoices#', $this->server->error( 400, 2000588, 'Fel hos api.fortnox.se med ' . FakeFortnoxServer::CLIENT_SECRET . ' och fx-access-0 ' . str_repeat( 'x', 400 ) ) );
		list( $item, $payload ) = $this->order( '4013' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$text   = $result->error->message() . '|' . $result->error->detail . '|' . wp_json_encode( $result->error->context );
		$this->assertStringNotContainsString( FakeFortnoxServer::CLIENT_SECRET, $text );
		$this->assertStringNotContainsString( 'fx-access-0', $text );
		$this->assertStringNotContainsString( 'api.fortnox.se', $text );
		$this->assertLessThanOrEqual( 200, mb_strlen( (string) $result->error->context['reason'] ) );
	}

	public function test_test_connection_reports_the_company_and_the_reads_it_may_do(): void {
		$result = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Test AB', $result->company );
		$this->assertSame( 'Fortnox API v3', $result->version );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertSame( '1234567', $result->detected['tenant_id'] );
		$this->assertSame( array( 'companyinformation', 'customer', 'article', 'invoice' ), $result->detected['scopes'] );
	}

	public function test_test_connection_names_a_scope_the_integration_lacks_and_a_licence_that_is_missing(): void {
		$this->server->fail( 'GET', '#/3/articles#', $this->server->error( 403, 2000663, 'Har inte behörighet för scope' ) );
		$missing = $this->connector->test( $this->connection() );
		$this->assertFalse( $missing->ok );
		$this->assertSame( array( 'read_articles' ), $missing->missingPermissions() );
		$this->server->fail( 'GET', '#/3/customers#', $this->server->error( 403, 2001101, 'Det finns ingen aktiv licens för önskat scope' ) );
		$licence = $this->connector->test( $this->connection( array(), array(), 'fx-access-3' ) );
		$this->assertFalse( $licence->ok );
		$this->assertSame( ErrorCode::Auth006, $licence->error->code );
	}

	public function test_ping_is_one_read_of_the_company_information(): void {
		$connection = $this->connection();
		$before     = count( $this->server->log );
		$this->assertTrue( $this->connector->ping( $connection ) );
		$calls = array_slice( $this->server->log, $before );
		$this->assertCount( 1, $calls, 'One call, not the whole test connection.' );
		$this->assertStringContainsString( 'companyinformation', $calls[0] );
		$this->server->fail( 'GET', '#companyinformation#', FakeHttpClient::status( 500, 'down' ) );
		$this->assertFalse( $this->connector->ping( $this->connection( array(), array(), 'fx-access-7' ) ) );
	}

	public function test_the_company_is_the_only_organisation(): void {
		$connector = new FortnoxConnector( $this->kit->deps );
		$connection = $this->connection();
		$this->assertSame( array( array( 'id' => '1234567', 'name' => 'Test AB' ) ), $connector->organisations( $connection ) );
		$this->assertSame( array( 'tenant_id' => '1234567', 'company_name' => 'Test AB' ), $connector->selectOrganisation( $connection, '1234567' ) );
	}

	public function test_the_tax_codes_are_the_swedish_rates(): void {
		$codes = $this->connector->listTaxes( $this->connection() );
		$this->assertSame( array( '25', '12', '6', '0' ), array_column( $codes, 'code' ) );
		$this->assertSame( 25.0, $codes[0]['rate_percent'] );
	}

	public function test_stock_comes_from_the_article_register_and_unknown_or_service_articles_are_left_out(): void {
		$this->server->addArticle( 'SERVICE-1', null );
		$stock = $this->connector->fetchStock( $this->connection(), array( 'CS-MUG-01', 'SERVICE-1', 'NO-SUCH' ), array() );
		$this->assertSame( array( 'CS-MUG-01' => array( 'quantity' => 10, 'held' => 2 ) ), $stock );
	}

	public function test_stock_from_lager_is_summed_over_the_chosen_stock_points(): void {
		$this->server->stockBalances = array(
			array( 'itemId' => 'CS-MUG-01', 'stockPointCode' => 'MAIN', 'inStock' => 8.0, 'availableStock' => 6.0 ),
			array( 'itemId' => 'CS-MUG-01', 'stockPointCode' => 'WEB', 'inStock' => 3.0, 'availableStock' => 3.0 ),
			array( 'itemId' => 'CS-MUG-01', 'stockPointCode' => 'OLD', 'inStock' => 50.0, 'availableStock' => 50.0 ),
		);
		$connection = $this->connection( array( 'options' => array( 'stock_source' => 'warehouse' ) ) );
		$this->assertSame( array( 'CS-MUG-01' => array( 'quantity' => 61, 'held' => 2 ) ), $this->connector->fetchStock( $connection, array( 'CS-MUG-01' ), array() ) );
		$this->assertSame( array( 'CS-MUG-01' => array( 'quantity' => 11, 'held' => 2 ) ), $this->connector->fetchStock( $connection, array( 'CS-MUG-01' ), array( 'MAIN', 'WEB' ) ) );
	}

	public function test_invoice_facts_are_read_back_by_document_number(): void {
		$connection = $this->connection( array( 'options' => array( 'bookkeep' => 'yes', 'record_payment' => 'yes', 'payment_mode' => 'KORT' ) ) );
		list( $item, $payload ) = $this->order( '5001' );
		$sent  = $this->connector->send( $connection, $item, $payload );
		$facts = $this->connector->fetchInvoice( $connection, $sent->ref );
		$this->assertSame( 'paid', $facts['status'] );
		$this->assertSame( '2026-10-30', $facts['issued_at'] );
		$this->assertSame( (string) $sent->ref->id, $facts['number'] );
		list( $open, $openPayload ) = $this->order( '5002' );
		$plain = $this->connector->send( $this->connection( array(), array(), 'fx-access-5' ), $open, $openPayload );
		$this->assertSame( 'open', $this->connector->fetchInvoice( $connection, $plain->ref )['status'] );
	}

	public function test_the_nightly_list_has_totals_and_refunded_amounts(): void {
		$this->server->pageSize = 2;
		$connection = $this->connection();
		foreach ( array( '5003', '5004', '5005' ) as $id ) {
			list( $item, $payload ) = $this->order( $id );
			$this->connector->send( $connection, $item, $payload );
		}
		list( $refund, $refundPayload ) = $this->refund( '5006', '5004' );
		$this->connector->send( $connection, $refund, $refundPayload );
		$list = $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );
		$this->assertSame( array( 'WC-5003', 'WC-5004', 'WC-5005' ), array_keys( $list ) );
		$this->assertSame( 14500, $list['WC-5004']->extra['total_minor'] );
		$this->assertSame( 2000, $list['WC-5004']->extra['refunded_minor'] );
		$this->assertSame( 0, $list['WC-5003']->extra['refunded_minor'] );
		$this->assertSame( array(), $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2026-11-15T00:00:00Z' ) ) );
	}

	public function test_lookups_and_search(): void {
		$connection = $this->connection();
		$product    = $this->connector->lookupProduct( $connection, 'cs-mug-01' );
		$this->assertInstanceOf( ExternalRef::class, $product );
		$this->assertSame( 'CS-MUG-01', $product->ref );
		$this->assertNull( $this->connector->lookupProduct( $connection, 'MUG' ), 'A part of a number is not the number.' );
		$this->assertSame( array( 'CS-MUG-01' ), array_map( static fn( ExternalRef $r ) => $r->ref, $this->connector->searchProducts( $connection, 'Article CS-MUG' ) ) );
		$this->assertNull( $this->connector->lookupCustomer( $connection, 'nobody@example.test' ) );
	}

	public function test_the_default_mapping_targets_fields_the_catalog_knows(): void {
		foreach ( array( 'orders', 'customers', 'products', 'refunds' ) as $type ) {
			$paths = array_map( static fn( array $f ): string => (string) preg_replace( '/\[\d*\]/', '', $f['path'] ), $this->connector->describeFields( $type ) );
			foreach ( $this->connector->defaultMapping( $type ) as $rule ) {
				$this->assertContains( (string) preg_replace( '/\[\d*\]/', '', (string) $rule['target'] ), $paths, $type . ': ' . $rule['id'] );
			}
		}
	}
}
