<?php
/**
 * The connector contract rules plus the rules of the shared API base.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Contract;

require_once dirname( __DIR__ ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;

/**
 * A vendor test extends this class, builds its connector on `$this->kit->deps` and a fake vendor server attached to `$this->kit->http`, and implements:
 *
 *   - apiConnector(): the connector (a subclass of AbstractApiConnector);
 *   - workingConnection(), brokenConnection(), orderFixture(), failNextWrite(), secretValues(), requestTimeouts() as in ConnectorContractTestCase.
 *
 * `$this->kit` is created in setUp() and cleaned in tearDown(). The connector under test is wrapped in GuardedConnector, as the registry does in
 * production, so exceptions from the HTTP client come back as ConnectorErrors. Rules added here: the id uses only lower case letters, digits and
 * dashes; the connector declares the hosts it contacts (or is marked as using a merchant host); secret fields are marked secret; no two credential fields share a name;
 * every capability flag that is true has a method that works (inherited rule); and a connector that signs in with a key or an app has at least one secret field.
 */
abstract class ApiConnectorContractTestCase extends ConnectorContractTestCase {

	/**
	 * Fakes and dependencies for the connector.
	 */
	protected ApiTestKit $kit;

	/**
	 * The wrapped connector, built once per test.
	 */
	private ?ConnectorInterface $wrapped = null;

	/**
	 * The connector (a subclass of AbstractApiConnector) built on `$this->kit->deps`.
	 */
	abstract protected function apiConnector(): AbstractApiConnector;

	protected function setUp(): void {
		parent::setUp();
		$this->kit     = ApiTestKit::make();
		$this->wrapped = null;
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
		parent::tearDown();
	}

	/**
	 * The connector as the engine sees it.
	 */
	protected function connectorUnderTest(): ConnectorInterface {
		if ( null === $this->wrapped ) {
			$this->wrapped = GuardedConnector::wrap( $this->apiConnector(), $this->kit->tokens );
		}
		return $this->wrapped;
	}

	/**
	 * The timeouts of the requests the fake network saw.
	 *
	 * @return array<int,int>
	 */
	protected function requestTimeouts(): array {
		return array_map( static fn( array $request ): int => $request['timeout'], $this->kit->http->requests );
	}

	/**
	 * Ids may use dashes (the provider rule is [a-z0-9-]).
	 */
	public function test_the_connector_says_who_it_is(): void {
		$connector = $this->connectorUnderTest();
		$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9-]*$/', $connector->id() );
		$this->assertNotSame( '', $connector->label() );
		$this->assertTrue( \Estasoft\SyncEngine\Contract\ConnectorRegistry::sameMajor( $connector->contractVersion(), ConnectorInterface::CONTRACT_VERSION ) );
		$this->assertSame( array(), ( new \Estasoft\SyncEngine\Contract\ConnectorRegistry( array( $connector ) ) )->rejected() );
	}

	/**
	 * Only connectors that connect with a key, a login or an app have secrets; a file export has none.
	 */
	public function test_credential_fields_have_unique_names_and_mark_the_secret(): void {
		$connector = $this->apiConnector();
		$names     = array();
		$secrets   = 0;
		foreach ( $connector->credentialFields() as $field ) {
			$this->assertInstanceOf( CredentialField::class, $field );
			$this->assertNotContains( $field->name, $names, 'Duplicate field ' . $field->name );
			$this->assertNotSame( '', $field->label );
			$this->assertContains( $field->type, CredentialField::TYPES );
			$this->assertContains( $field->group, CredentialField::GROUPS );
			if ( 'select' === $field->type ) {
				$this->assertNotEmpty( $field->options, $field->name . ' is a select without options.' );
			}
			if ( in_array( $field->type, array( 'password', 'textarea' ), true ) && 'display' !== $field->type && in_array( $field->name, array( 'api_key', 'password', 'client_secret', 'private_key', 'token', 'api_token', 'secret' ), true ) ) {
				$this->assertTrue( $field->secret, $field->name . ' holds a secret and must be marked secret.' );
			}
			$names[]  = $field->name;
			$secrets += $field->secret ? 1 : 0;
		}
		$auth = $this->metaOf( $connector )->auth;
		if ( ! in_array( $auth, array( AuthStyle::ExportFile, AuthStyle::None ), true ) ) {
			$this->assertGreaterThanOrEqual( 1, $secrets, 'A connector that signs in has at least one secret field.' );
		}
	}

	/**
	 * Every host the plugin contacts is declared (the WordPress.org external services disclosure is built from it).
	 */
	public function test_the_connector_declares_the_hosts_it_contacts(): void {
		$meta = $this->metaOf( $this->apiConnector() );
		if ( in_array( $meta->auth, array( AuthStyle::ExportFile, AuthStyle::None ), true ) ) {
			$this->assertSame( array(), $meta->hosts, 'A file export contacts nobody.' );
			return;
		}
		$this->assertTrue( array() !== $meta->hosts || $meta->merchantHost, 'Declare hosts: or set merchantHost: true when the merchant types the address.' );
		foreach ( $meta->hosts as $host ) {
			$this->assertMatchesRegularExpression( '/^(\*\.)?[a-z0-9.-]+\.[a-z]{2,}$/', $host );
		}
	}

	/**
	 * With a wrong secret every method the connector really implements answers with SYNC-AUTH-001 and never throws. A method of a capability the
	 * connector does not flag is allowed to answer with an empty result (the base default), because Jobs never calls it.
	 */
	public function test_methods_return_errors_and_do_not_throw_when_the_secret_is_wrong(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->brokenConnection();
		$flags      = $connector->capabilities();
		list( $item, $payload ) = $this->orderFixture( '9400' );
		$since = new \DateTimeImmutable( '2026-01-01T00:00:00Z' );

		$this->assertFalse( $connector->send( $connection, $item, $payload )->isSuccess() );
		$results = array(
			'findExisting' => $connector->findExisting( $connection, $item ),
		);
		if ( $flags['shadow_lookup'] ) {
			$results['lookupCustomer'] = $connector->lookupCustomer( $connection, 'a@example.test' );
			$results['lookupProduct']  = $connector->lookupProduct( $connection, 'X' );
			$results['searchProducts'] = $connector->searchProducts( $connection, 'x' );
		}
		if ( $flags['stock_in'] ) {
			$results['fetchStock'] = $connector->fetchStock( $connection, array( 'X' ), array() );
		}
		if ( $flags['tracking_in'] ) {
			$results['fetchTracking'] = $connector->fetchTracking( $connection, $since );
		}
		if ( $flags['invoice_readback'] ) {
			$results['fetchInvoice'] = $connector->fetchInvoice( $connection, new \Estasoft\SyncEngine\Contract\ExternalRef( '1' ) );
		}
		if ( $flags['reconciliation'] ) {
			$results['listForReconcile'] = $connector->listForReconcile( $connection, $since );
		}
		foreach ( $results as $name => $result ) {
			$this->assertInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $result, $name );
			$this->assertSame( \Estasoft\SyncEngine\Domain\ErrorCode::Auth001, $result->code, $name );
		}
		$test = $connector->test( $connection );
		$this->assertFalse( $test->ok );
		$this->assertSame( \Estasoft\SyncEngine\Domain\ErrorCode::Auth001, $test->error->code );
	}

	/**
	 * A mapping type the system has no objects for (refunds for a system without credit notes) may have no fields and no rules; whatever has rules must have fields they target.
	 */
	public function test_every_mapping_type_has_fields_and_every_default_rule_targets_a_known_field(): void {
		$connector = $this->connectorUnderTest();
		$this->assertNotEmpty( $connector->describeFields( 'orders' ), 'Every connector maps orders.' );
		foreach ( array( 'orders', 'customers', 'products', 'refunds' ) as $type ) {
			$fields = $connector->describeFields( $type );
			$paths  = array_map( static fn( array $field ): string => (string) preg_replace( '/\[\d*\]/', '', $field['path'] ), $fields );
			foreach ( $connector->defaultMapping( $type ) as $rule ) {
				$this->assertNotEmpty( $fields, $type . ' has rules but no fields.' );
				$this->assertContains( (string) preg_replace( '/\[\d*\]/', '', (string) $rule['target'] ), $paths, $type . ': ' . $rule['id'] );
			}
		}
	}

	/**
	 * The meta of a connector (it is protected, so read it through reflection).
	 *
	 * @param AbstractApiConnector $connector Connector.
	 */
	protected function metaOf( AbstractApiConnector $connector ): \Estasoft\SyncEngine\Connectors\Support\ConnectorMeta {
		$method = new \ReflectionMethod( $connector, 'connectorMeta' );
		$method->setAccessible( true );
		return $method->invoke( $connector );
	}

	/**
	 * A connection value for tests that only need an object.
	 */
	protected function bareConnection(): Connection {
		return $this->workingConnection();
	}
}
