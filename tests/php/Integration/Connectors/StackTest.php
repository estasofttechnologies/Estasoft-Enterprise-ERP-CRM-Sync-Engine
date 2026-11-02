<?php
/**
 * The shared base on the real plugin: sign-in and token storage on the real connection store, the export routes on the real queue.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Connectors;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Connectors\ConnectorsModule;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthResolver;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Connectors\Support\OAuth\RefreshTask;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\OptionNames;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceCrmConnector;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceCrmVendor;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceExportConnector;

/**
 * The reference CRM with a machine-to-machine sign-in: no sign-in page, a token from the client credentials.
 */
final class CloudCrmConnector extends ReferenceCrmConnector {

	protected function meta(): \Estasoft\SyncEngine\Connectors\Support\ConnectorMeta {
		return new \Estasoft\SyncEngine\Connectors\Support\ConnectorMeta(
			id: 'reference-cloud',
			label: 'Acme CRM Cloud',
			type: \Estasoft\SyncEngine\Domain\ConnectorType::Crm,
			capabilities: array( \Estasoft\SyncEngine\Domain\Capability::Orders ),
			entities: array( EntityType::Order ),
			auth: \Estasoft\SyncEngine\Connectors\Support\AuthStyle::OAuthClientCredentials,
			hosts: array( 'api.acme-crm.test', 'login.acme-crm.test' ),
		);
	}

	protected function credentialDefinition(): array {
		return array(
			\Estasoft\SyncEngine\Connectors\Support\CredentialFields::clientId(),
			\Estasoft\SyncEngine\Connectors\Support\CredentialFields::clientSecret(),
			new \Estasoft\SyncEngine\Contract\CredentialField( 'tenant_id', 'Tenant', false, true ),
		);
	}

	public function config( Connection $connection ): \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig {
		return new \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig( authorizeUrl: '', tokenUrl: 'https://login.acme-crm.test/oauth/token', scopes: array( 'deals.write' ), grant: 'client_credentials', usePkce: false, clientAuth: 'body', allowedHosts: array( 'login.acme-crm.test' ) );
	}
}

/**
 * A connector that signs in with a page by default and connects with the client credentials when the merchant chose `auth_method = machine`
 * (the way Salesforce offers a signed-token option).
 */
final class EitherWayCrmConnector extends ReferenceCrmConnector {

	protected function meta(): \Estasoft\SyncEngine\Connectors\Support\ConnectorMeta {
		return new \Estasoft\SyncEngine\Connectors\Support\ConnectorMeta(
			id: 'reference-either',
			label: 'Acme CRM Either',
			type: \Estasoft\SyncEngine\Domain\ConnectorType::Crm,
			capabilities: array( \Estasoft\SyncEngine\Domain\Capability::Orders ),
			entities: array( EntityType::Order ),
			auth: \Estasoft\SyncEngine\Connectors\Support\AuthStyle::OAuthAuthorizationCode,
			hosts: array( 'api.acme-crm.test', 'login.acme-crm.test' ),
		);
	}

	protected function credentialDefinition(): array {
		return array(
			\Estasoft\SyncEngine\Connectors\Support\CredentialFields::clientId(),
			\Estasoft\SyncEngine\Connectors\Support\CredentialFields::clientSecret(),
			new \Estasoft\SyncEngine\Contract\CredentialField( 'auth_method', 'How to connect', false, false ),
			new \Estasoft\SyncEngine\Contract\CredentialField( 'tenant_id', 'Tenant', false, false ),
		);
	}

	public function requiresSignIn( Connection $connection ): bool {
		return 'machine' !== $connection->config( 'auth_method' );
	}

	public function config( Connection $connection ): \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig {
		return 'machine' === $connection->config( 'auth_method' )
			? new \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig( authorizeUrl: '', tokenUrl: 'https://login.acme-crm.test/oauth/token', grant: 'client_credentials', usePkce: false, clientAuth: 'body', allowedHosts: array( 'login.acme-crm.test' ) )
			: parent::config( $connection );
	}
}

final class StackTest extends RestIntegrationCase {

	private FakeHttpClient $http;

	private ReferenceCrmVendor $crm;

	private OAuthTokenManager $tokens;

	protected function setUp(): void {
		parent::setUp();
		$this->http = new FakeHttpClient();
		$this->crm  = new ReferenceCrmVendor( $this->http );
		$this->replace( Services::HTTP_CLIENT, $this->http );
		$this->replace(
			ConnectorsModule::TOKENS,
			$this->tokens = new OAuthTokenManager(
				$this->http,
				$this->container->get( Services::CLOCK ),
				$this->container->get( ConnectorsModule::TOKEN_STORAGE ),
				$this->container->get( ConnectorsModule::LOCK ),
				fn( Connection $connection ) => $this->container->get( ConnectorsModule::OAUTH_RESOLVER )->providerFor( $connection ),
				static function (): void {},
				$this->container->get( Services::LOGGER )
			)
		);
		$deps = new ConnectorDependencies(
			$this->http,
			$this->container->get( Services::CLOCK ),
			$this->tokens,
			$this->container->get( ConnectorsModule::EXPORT_STORE ),
			$this->container->get( Services::LOGGER ),
			$this->container->get( ConnectorsModule::PACER )
		);
		$this->replace( ConnectorsModule::DEPENDENCIES, $deps );
		$this->replace(
			Services::CONNECTOR_REGISTRY,
			new ConnectorRegistry(
				array(
					'reference-crm'    => fn() => GuardedConnector::wrap( new ReferenceCrmConnector( $deps ), $this->tokens ),
					'reference-export' => fn() => GuardedConnector::wrap( new ReferenceExportConnector( $deps ) ),
					'reference-cloud'  => fn() => GuardedConnector::wrap( new CloudCrmConnector( $deps ), $this->tokens ),
					'reference-either' => fn() => GuardedConnector::wrap( new EitherWayCrmConnector( $deps ), $this->tokens ),
				)
			)
		);
		$this->container->get( ConnectorsModule::EXPORT_STORE )->eraseAll();
	}

	protected function tearDown(): void {
		$this->container->get( ConnectorsModule::EXPORT_STORE )->eraseAll();
		parent::tearDown();
	}

	/**
	 * A token manager with an empty memory, as a new request has.
	 */
	private function freshTokens(): OAuthTokenManager {
		return new OAuthTokenManager(
			$this->http,
			$this->container->get( Services::CLOCK ),
			$this->container->get( ConnectorsModule::TOKEN_STORAGE ),
			$this->container->get( ConnectorsModule::LOCK ),
			fn( Connection $connection ) => $this->container->get( ConnectorsModule::OAUTH_RESOLVER )->providerFor( $connection ),
			static function (): void {},
			$this->container->get( Services::LOGGER )
		);
	}

	/* ------------------------------------------------------------ sign-in on the real stack */

	/**
	 * Run the whole first sign-in. Returns the connection id.
	 */
	private function connectCrm(): int {
		$this->actAs( 'administrator' );
		$created = $this->call(
			'POST',
			'/connections',
			array(
				'connector' => 'reference-crm',
				'config'    => array( 'client_id' => 'crm-client-1' ),
				'secrets'   => array( 'client_secret' => 'crm-client-secret-0123456789' ),
			)
		);
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$id = (int) $created->get_data()['id'];

		$start = $this->call( 'POST', "/connections/$id/oauth/start" );
		$this->assertSame( 200, $start->get_status(), (string) wp_json_encode( $start->get_data() ) );
		parse_str( (string) wp_parse_url( $start->get_data()['url'], PHP_URL_QUERY ), $query );

		$this->actAs( 'anonymous' ); // The vendor's redirect carries no REST nonce.
		$back = $this->call( 'GET', '/oauth/callback', array( 'state' => $query['state'], 'code' => 'good-code' ) );
		$this->assertSame( 302, $back->get_status() );
		$this->assertStringEndsWith( "?signin=ok&connection=$id", $back->get_headers()['Location'] );
		$this->actAs( 'administrator' );
		return $id;
	}

	public function test_a_sign_in_connection_is_saved_without_calling_the_vendor_and_waits_for_the_sign_in(): void {
		$this->actAs( 'administrator' );
		$created = $this->call( 'POST', '/connections', array( 'connector' => 'reference-crm', 'config' => array( 'client_id' => 'crm-client-1' ), 'secrets' => array( 'client_secret' => 'crm-client-secret-0123456789' ) ) );

		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$data = $created->get_data();
		$this->assertSame( 'needs_reconnect', $data['status'] );
		$this->assertSame( 0, $this->http->count(), 'Nothing is sent before the person signs in.' );
		$this->assertStringNotContainsString( 'crm-client-secret', (string) wp_json_encode( $data ) );
		$names = array_column( $data['credentials'], 'name' );
		$this->assertSame( array( 'client_id', 'client_secret', 'redirect_uri' ), $names );
		$redirect = array_values( array_filter( $data['credentials'], static fn( array $f ): bool => 'redirect_uri' === $f['name'] ) )[0];
		$this->assertSame( 'display', $redirect['type'] );
		$this->assertSame( rest_url( 'estasoft-sync/v1/oauth/callback' ), $redirect['value'] );
		$secretField = array_values( array_filter( $data['credentials'], static fn( array $f ): bool => 'client_secret' === $f['name'] ) )[0];
		$this->assertSame( 'app', $secretField['group'] );
		$this->assertTrue( $secretField['has_value'] );
	}

	public function test_the_whole_first_sign_in_ends_with_an_active_connection_and_encrypted_tokens(): void {
		$id = $this->connectCrm();

		$connection = $this->container->get( Services::CONNECTION_REPOSITORY )->find( $id );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $connection->status, 'Still waiting for the organisation.' );
		$this->assertNotSame( '', $connection->secret( OAuthTokenManager::ACCESS_TOKEN ) );
		$row = $this->h->connectionRow( $id );
		$this->assertStringNotContainsString( $connection->secret( OAuthTokenManager::ACCESS_TOKEN ), (string) $row['secrets_enc'], 'Tokens are encrypted at rest.' );
		$this->assertNotNull( $row['credential_expires_at'], 'The refresh token lifetime feeds the key-expiry warning.' );

		$organisations = $this->call( 'GET', "/connections/$id/oauth/organisations" );
		$this->assertSame( array( array( 'id' => 'tenant-7', 'name' => 'Acme Trading LLC' ), array( 'id' => 'tenant-8', 'name' => 'Acme Retail' ) ), $organisations->get_data() );

		$wrong = $this->call( 'POST', "/connections/$id/oauth/organisation", array( 'id' => 'tenant-99' ) );
		$this->assertSame( 422, $wrong->get_status() );
		$this->assertSame( ConnectionStatus::NeedsReconnect->value, $this->h->connectionRow( $id )['status'] );

		$chosen = $this->call( 'POST', "/connections/$id/oauth/organisation", array( 'id' => 'tenant-7' ) );
		$this->assertSame( 200, $chosen->get_status(), (string) wp_json_encode( $chosen->get_data() ) );
		$this->assertSame( 'active', $chosen->get_data()['status'] );
		$this->assertSame( 'Acme CRM 9.1', $chosen->get_data()['version'] );
		$this->assertSame( 'tenant-7', $this->container->get( Services::CONNECTION_REPOSITORY )->find( $id )->config( 'tenant_id' ) );
		$this->assertSame( 'tenant-7', $this->container->get( Services::CONNECTION_REPOSITORY )->find( $id )->config( 'oauth_organisation' ) );
		$this->assertSame( 'tenant-7', $this->http->requests[ count( $this->http->requests ) - 1 ]['headers']['X-Tenant'] );
		$this->assertStringNotContainsString( 'access-', (string) wp_json_encode( $chosen->get_data() ), 'No token reaches an answer.' );
	}

	public function test_a_revoked_refresh_token_holds_the_connection_and_the_next_sign_in_reactivates_it_without_asking_again(): void {
		$id = $this->connectCrm();
		$this->call( 'POST', "/connections/$id/oauth/organisation", array( 'id' => 'tenant-7' ) );
		$repo = $this->container->get( Services::CONNECTION_REPOSITORY );

		// The access token runs out and the vendor revoked the refresh token.
		$repo->update( $id, array( 'secrets' => array( OAuthTokenManager::EXPIRES_AT => (string) ( time() + 60 ) ) ) );
		$this->crm->tokens->refreshTokens = array();
		// The scheduled task runs in a request of its own: a token manager that remembers nothing.
		$task   = new RefreshTask( $this->container->get( ConnectorsModule::TOKEN_STORAGE ), $this->freshTokens(), $this->container->get( ConnectorsModule::OAUTH_RESOLVER ), $this->container->get( Services::LOGGER ) );
		$report = $task->run();
		$this->assertSame( array( 'checked' => 1, 'refreshed' => 0, 'failed' => 1 ), $report );
		$this->assertSame( 'needs_reconnect', $this->h->connectionRow( $id )['status'] );
		$this->assertSame( 'sign_in', $this->h->connectionRow( $id )['paused_reason'], 'The existing notification fires for a sign-in that ran out.' );

		// Sign in again.
		$start = $this->call( 'POST', "/connections/$id/oauth/start" );
		parse_str( (string) wp_parse_url( $start->get_data()['url'], PHP_URL_QUERY ), $query );
		$this->crm->tokens->codes['good-code'] = true;
		$this->actAs( 'anonymous' );
		$back = $this->call( 'GET', '/oauth/callback', array( 'state' => $query['state'], 'code' => 'good-code' ) );
		$this->assertStringEndsWith( "?signin=ok&connection=$id", $back->get_headers()['Location'] );
		$this->assertSame( 'active', $this->h->connectionRow( $id )['status'], 'The organisation was chosen before, so the connection is tested and resumed at once.' );
	}

	public function test_the_older_callback_address_with_the_connection_id_still_works(): void {
		$this->actAs( 'administrator' );
		$id    = (int) $this->call( 'POST', '/connections', array( 'connector' => 'reference-crm', 'config' => array( 'client_id' => 'c' ), 'secrets' => array( 'client_secret' => 'crm-client-secret-0123456789' ) ) )->get_data()['id'];
		$start = $this->call( 'POST', "/connections/$id/oauth/start" );
		parse_str( (string) wp_parse_url( $start->get_data()['url'], PHP_URL_QUERY ), $query );
		$this->actAs( 'anonymous' );
		$back = $this->call( 'GET', "/connections/$id/oauth/callback", array( 'state' => $query['state'], 'code' => 'good-code' ) );
		$this->assertStringEndsWith( "?signin=ok&connection=$id", $back->get_headers()['Location'] );
	}

	public function test_the_stable_callback_refuses_a_made_up_state_with_the_failed_redirect(): void {
		$this->actAs( 'anonymous' );
		$back = $this->call( 'GET', '/oauth/callback', array( 'state' => str_repeat( 'a', 43 ), 'code' => 'x' ) );
		$this->assertSame( 302, $back->get_status() );
		$this->assertStringEndsWith( '#/connections?signin=failed', $back->get_headers()['Location'] );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_the_organisation_route_is_for_administrators_and_for_sign_in_connectors_only(): void {
		$id = $this->connectCrm();
		$this->actAs( 'shop_manager' );
		$this->assertSame( 403, $this->call( 'POST', "/connections/$id/oauth/organisation", array( 'id' => 'tenant-7' ) )->get_status() );
		$this->actAs( 'administrator' );
		$odoo = $this->container->get( Services::CONNECTION_REPOSITORY )->create( array( 'connector' => 'odoo', 'type' => 'erp', 'label' => 'Odoo' ), array(), array() );
		$this->assertSame( 'SYNC-CFG-004', $this->call( 'POST', "/connections/$odoo/oauth/organisation", array( 'id' => 'x' ) )->get_data()['code'] );
	}

	public function test_a_cloud_app_connection_is_tested_with_a_token_request_before_it_is_saved(): void {
		$this->actAs( 'administrator' );
		$input = array( 'connector' => 'reference-cloud', 'config' => array( 'client_id' => 'cloud-1', 'tenant_id' => 'tenant-7' ), 'secrets' => array( 'client_secret' => 'cloud-secret-0123456789' ) );

		$this->crm->tokens->next = FakeHttpClient::json( array( 'error' => 'invalid_client', 'error_description' => 'cloud-secret-0123456789 is wrong' ), 401 );
		$refused = $this->call( 'POST', '/connections', $input );
		$this->assertSame( 401, $refused->get_status(), 'The same status a refused key gets.' );
		$this->assertSame( 'SYNC-AUTH-001', $refused->get_data()['code'] );
		$this->assertStringNotContainsString( 'cloud-secret-0123456789', (string) wp_json_encode( $refused->get_data() ) );
		$this->assertSame( array(), $this->container->get( Services::CONNECTION_REPOSITORY )->all(), 'A failed test saves nothing.' );

		$saved = $this->call( 'POST', '/connections', $input );
		$this->assertSame( 201, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$this->assertSame( 'active', $saved->get_data()['status'] );
		$this->assertSame( 'Acme CRM 9.1', $saved->get_data()['version'] );
		$grants = array_column( $this->crm->tokens->requests, 'grant_type' );
		$this->assertSame( array( 'client_credentials', 'client_credentials' ), $grants );
	}

	public function test_the_extra_values_of_the_vendors_redirect_are_kept_on_the_connection_for_the_connector(): void {
		$this->actAs( 'administrator' );
		$id    = (int) $this->call( 'POST', '/connections', array( 'connector' => 'reference-crm', 'config' => array( 'client_id' => 'c' ), 'secrets' => array( 'client_secret' => 'crm-client-secret-0123456789' ) ) )->get_data()['id'];
		$start = $this->call( 'POST', "/connections/$id/oauth/start" );
		parse_str( (string) wp_parse_url( $start->get_data()['url'], PHP_URL_QUERY ), $query );
		$this->actAs( 'anonymous' );
		$this->call(
			'GET',
			'/oauth/callback',
			array(
				'state'             => $query['state'],
				'code'              => 'good-code',
				'realmId'           => '9130350000000001',
				'businessId'        => 'bf-1',
				'error_description' => 'ignored',
				'bad key!'          => 'ignored',
				'long'              => str_repeat( 'x', 129 ),
			)
		);
		$config = $this->container->get( Services::CONNECTION_REPOSITORY )->find( $id )->config( 'oauth_callback' );
		$this->assertSame( array( 'realmId' => '9130350000000001', 'businessId' => 'bf-1' ), $config );
		$this->assertArrayNotHasKey( 'code', $config );
		$this->assertArrayNotHasKey( 'state', $config );
	}

	public function test_a_connector_can_say_per_connection_that_no_sign_in_page_is_needed(): void {
		$this->actAs( 'administrator' );
		$machine = $this->call( 'POST', '/connections', array( 'connector' => 'reference-either', 'config' => array( 'client_id' => 'c', 'auth_method' => 'machine', 'tenant_id' => 'tenant-7' ), 'secrets' => array( 'client_secret' => 'crm-client-secret-0123456789' ) ) );
		$this->assertSame( 201, $machine->get_status(), (string) wp_json_encode( $machine->get_data() ) );
		$this->assertSame( 'active', $machine->get_data()['status'], 'Tested with a token request and activated at once.' );
		$id = (int) $machine->get_data()['id'];
		$this->assertSame( 'SYNC-CFG-004', $this->call( 'POST', "/connections/$id/oauth/start" )->get_data()['code'], 'There is no page to sign in on.' );
	}

	/* ------------------------------------------------------------ exports on the real queue */

	private function exportConnection(): int {
		return $this->container->get( Services::CONNECTION_REPOSITORY )->create( array( 'connector' => 'reference-export', 'type' => 'accounting', 'label' => 'Acme Ledger' ), array(), array() );
	}

	/**
	 * Send an order into the open batch of a connection, with a real queue item behind it.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $orderId      WooCommerce order id.
	 * @param string $ledger       Ledger code, empty to have the order left out.
	 */
	private function exportOrder( int $connectionId, string $orderId, string $ledger = '4000' ): int {
		$queueId = $this->h->item( $connectionId, $orderId );
		$item    = new Item( $queueId, $connectionId, EntityType::Order, $orderId, 'created', 'order:' . $orderId, 'k' . $queueId, ItemState::Syncing );
		$payload = array( 'order' => array( 'reference' => 'WC-' . $orderId, 'customer' => 'Layla Haddad', 'currency' => 'AED' ), 'lines' => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 2, 'unit_price' => 4200, 'ledger' => $ledger ) ) );
		$result  = $this->container->get( Services::CONNECTOR_REGISTRY )->get( 'reference-export' )->send( $this->container->get( Services::CONNECTION_REPOSITORY )->find( $connectionId ), $item, $payload );
		$this->assertTrue( $result->isSuccess() );
		return $queueId;
	}

	public function test_exports_are_listed_made_downloaded_and_deleted_with_the_right_capabilities(): void {
		$connection = $this->exportConnection();
		$one        = $this->exportOrder( $connection, '5001' );
		$two        = $this->exportOrder( $connection, '5002', '' );

		$this->actAs( 'subscriber' );
		$this->assertSame( 403, $this->call( 'GET', '/exports' )->get_status() );
		$this->actAs( 'anonymous' );
		$this->assertSame( 401, $this->call( 'GET', '/exports' )->get_status() );

		$this->actAs( 'shop_manager' );
		$list = $this->call( 'GET', '/exports', array( 'connection_id' => $connection ) );
		$this->assertSame( 200, $list->get_status() );
		$this->assertSame( array(), $list->get_data()['files'] );
		$this->assertSame( 2, $list->get_data()['open_batch']['orders'] );
		$this->assertSame( $connection, $list->get_data()['open_batch']['connection_id'] );
		$this->assertSame( 403, $this->call( 'POST', '/exports', array( 'connection_id' => $connection ) )->get_status(), 'Creating needs the configure capability.' );

		$this->actAs( 'administrator' );
		$made = $this->call( 'POST', '/exports', array( 'connection_id' => $connection ) );
		$this->assertSame( 201, $made->get_status(), (string) wp_json_encode( $made->get_data() ) );
		$file = $made->get_data();
		$this->assertSame( 1, $file['orders'] );
		$this->assertSame( array( array( 'order' => '5002', 'reason' => 'no ledger code for CS-MUG-01' ) ), $file['left_out'] );
		$this->assertSame( 'Acme Ledger CSV import', $file['format_label'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $file['id'] );
		$this->assertSame( $this->h->row( $one )['external_ref'], $file['filename'], 'The queue shows the file name as the reference.' );
		$this->assertSame( 'failed', $this->h->row( $two )['state'], 'The order that was left out needs a person.' );
		$this->assertSame( 'SYNC-EXP-002', $this->h->row( $two )['error_code'] );

		$this->actAs( 'shop_manager' );
		$download = $this->call( 'GET', "/exports/{$file['id']}/download" );
		$this->assertSame( 200, $download->get_status() );
		$headers = $download->get_headers();
		$this->assertSame( 'attachment; filename="' . $file['filename'] . '"', $headers['Content-Disposition'] );
		$this->assertStringStartsWith( 'text/csv', $headers['Content-Type'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( (string) $file['size_bytes'], $headers['Content-Length'] );

		// WordPress then writes the file instead of JSON.
		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $download, new \WP_REST_Request(), rest_get_server() );
		$body   = (string) ob_get_clean();
		remove_all_filters( 'rest_pre_serve_request' );
		$this->assertTrue( $served );
		$this->assertStringStartsWith( "\xEF\xBB\xBFreference,date,customer", $body );
		$this->assertStringContainsString( 'WC-5001', $body );
		$this->assertStringNotContainsString( 'WC-5002', $body );

		$this->assertSame( 403, $this->call( 'DELETE', "/exports/{$file['id']}" )->get_status() );
		$this->actAs( 'administrator' );
		$this->assertSame( array( 'deleted' => true ), $this->call( 'DELETE', "/exports/{$file['id']}" )->get_data() );
		$this->assertSame( 404, $this->call( 'GET', "/exports/{$file['id']}/download" )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', "/exports/{$file['id']}" )->get_status() );
		$this->assertSame( array(), $this->call( 'GET', '/exports' )->get_data()['files'] );
	}

	public function test_nothing_to_export_and_all_left_out_have_their_own_answers(): void {
		$connection = $this->exportConnection();
		$this->actAs( 'administrator' );
		$empty = $this->call( 'POST', '/exports', array( 'connection_id' => $connection ) );
		$this->assertSame( 409, $empty->get_status() );
		$this->assertSame( 'SYNC-VAL-002', $empty->get_data()['code'] );
		$this->assertSame( 'No orders are waiting for an export file.', $empty->get_data()['message'], 'The reason as it is, not "<system> rejected a field: export".' );

		$this->exportOrder( $connection, '5003', '' );
		$none = $this->call( 'POST', '/exports', array( 'connection_id' => $connection ) );
		$this->assertSame( 422, $none->get_status() );
		$this->assertSame( 'SYNC-EXP-002', $none->get_data()['code'] );

		$odoo = $this->container->get( Services::CONNECTION_REPOSITORY )->create( array( 'connector' => 'odoo', 'type' => 'erp', 'label' => 'Odoo' ), array(), array() );
		$refused = $this->call( 'POST', '/exports', array( 'connection_id' => $odoo ) );
		$this->assertSame( 409, $refused->get_status(), 'Odoo does not make export files.' );
		$this->assertSame( 'This connection does not make export files.', $refused->get_data()['message'] );
	}

	public function test_a_file_id_is_not_a_path(): void {
		$this->actAs( 'administrator' );
		foreach ( array( '../../wp-config', '..%2F..%2Fwp-config', 'zz', str_repeat( 'a', 40 ) ) as $id ) {
			$this->assertContains( $this->call( 'GET', '/exports/' . $id . '/download' )->get_status(), array( 400, 404 ), $id );
		}
	}

	public function test_the_daily_clean_up_removes_expired_files_and_uninstall_knows_the_folder_and_the_lock_rows(): void {
		$connection = $this->exportConnection();
		$this->exportOrder( $connection, '5004' );
		$this->actAs( 'administrator' );
		$file = $this->call( 'POST', '/exports', array( 'connection_id' => $connection ) )->get_data();
		$root = (string) $this->container->get( ConnectorsModule::EXPORT_STORE )->root( false );
		$this->assertStringContainsString( '/' . \Estasoft\SyncEngine\Core\DataEraser::EXPORT_DIR . '/', $root, 'Core\DataEraser removes this folder on uninstall.' );

		$meta = $root . '/meta/' . $file['id'] . '.json';
		$data = json_decode( (string) file_get_contents( $meta ), true );
		$data['expires_at'] = '2020-01-01T00:00:00Z';
		file_put_contents( $meta, wp_json_encode( $data ) );
		do_action( 'estasoft_sync_prune_data' );
		$this->assertSame( array(), $this->call( 'GET', '/exports' )->get_data()['files'] );

		( new ConnectorsModule() )->hooks( $this->container ); // The test base isolates this hook; register the module's callbacks again.
		$this->assertContains( 'estasoft_sync_lock_*', OptionNames::all() );
	}

	public function test_the_provider_index_registers_the_shipped_connectors_lazily_in_the_real_plugin(): void {
		$registry = new ConnectorRegistry( (array) apply_filters( 'estasoft_sync_register_connectors', array() ) );
		$this->assertContains( 'odoo', $registry->ids() );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Connectors\Odoo\OdooConnector::class, $registry->get( 'odoo' ) );
		$resolver = new OAuthResolver( fn() => $registry );
		$this->assertNull( $resolver->providerFor( new Connection( 1, 'odoo', \Estasoft\SyncEngine\Domain\ConnectorType::Erp, 'Odoo', ConnectionStatus::Active ) ) );
		( new ConnectorsModule() )->hooks( $this->container );
		$this->assertContains( RefreshTask::HOOK, array_keys( \Estasoft\SyncEngine\Core\Queue\Scheduler::tasks() ) );
		$this->assertSame( 300, \Estasoft\SyncEngine\Core\Queue\Scheduler::tasks()[ RefreshTask::HOOK ] );
	}
}
