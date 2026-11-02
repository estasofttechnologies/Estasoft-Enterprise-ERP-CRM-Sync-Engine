<?php
/**
 * The rules every connector must keep (connector contract v1, spec section 10).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Contract;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use PHPUnit\Framework\TestCase;

/**
 * Extend this class for a connector and implement the hooks; the rules are then checked against that connector with a fake
 * of its system behind it. The rules, in the order of the spec:
 *
 * - the connector declares who it is, a contract version with the same major as the core, and capability flags;
 * - a flag that is true works: the method behind it answers with data or an error, never with an exception;
 * - sending the same item twice never makes a second record: the second answer is the first record again;
 * - every failure is a ConnectorError with a catalog code and a class; unknown failures are not silently "handled";
 * - a rate limit carries the wait the system asked for; a time-out and a server error retry; a refused sign-in needs a person;
 * - no secret is ever repeated in an error, a test result or a reference;
 * - Arabic text comes back as it went in;
 * - money is whole minor units on our side and the system's total comes back in minor units by the currency's exponent,
 *   including three-decimal currencies;
 * - requests never take longer than thirty seconds.
 */
abstract class ConnectorContractTestCase extends TestCase {

	/**
	 * The connector under test, connected to a fake system that holds the data the fixtures need.
	 */
	abstract protected function connectorUnderTest(): ConnectorInterface;

	/**
	 * A connection that works.
	 */
	abstract protected function workingConnection(): Connection;

	/**
	 * A connection whose secret is wrong.
	 */
	abstract protected function brokenConnection(): Connection;

	/**
	 * An order item and its mapped payload, valid for the system.
	 *
	 * @param string              $orderId WooCommerce order id.
	 * @param array<string,mixed> $options name, email, street, currency, quantity, unit_minor.
	 * @return array{0:Item,1:array<string,mixed>}
	 */
	abstract protected function orderFixture( string $orderId, array $options = array() ): array;

	/**
	 * Make the next write request fail.
	 *
	 * @param string $kind    rate_limit, timeout, server_error, unknown_fault, auth or certificate.
	 * @param int    $seconds Wait for rate_limit.
	 */
	abstract protected function failNextWrite( string $kind, int $seconds = 0 ): void;

	/**
	 * Every secret value of the connection, so no output can repeat one.
	 *
	 * @return array<int,string>
	 */
	abstract protected function secretValues(): array;

	/**
	 * The timeouts, in seconds, of every request the fake system has seen.
	 *
	 * @return array<int,int>
	 */
	abstract protected function requestTimeouts(): array;

	/**
	 * The total of a record in minor units, from what the reference carries.
	 *
	 * @param ExternalRef $ref Reference returned by the connector.
	 */
	protected function totalMinorOf( ExternalRef $ref ): ?int {
		return isset( $ref->extra['total_minor'] ) ? (int) $ref->extra['total_minor'] : null;
	}

	/**
	 * The customer name a lookup returns.
	 *
	 * @param ExternalRef $ref Reference from lookupCustomer.
	 */
	protected function customerNameFrom( ExternalRef $ref ): string {
		return $ref->ref;
	}

	/**
	 * All the text a result could leak a secret through.
	 *
	 * @param ConnectorError|null $error Error.
	 */
	private function textOf( ?ConnectorError $error ): string {
		if ( null === $error ) {
			return '';
		}
		return $error->message() . '|' . $error->detail . '|' . wp_json_encode( $error->context );
	}

	private function assertNoSecret( string $text, string $where ): void {
		foreach ( $this->secretValues() as $secret ) {
			$this->assertStringNotContainsString( $secret, $text, "A secret was repeated in {$where}." );
		}
	}

	/* ------------------------------------------------------------ identity and flags */

	public function test_the_connector_says_who_it_is(): void {
		$connector = $this->connectorUnderTest();
		$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_]*$/', $connector->id() );
		$this->assertNotSame( '', $connector->label() );
		$this->assertInstanceOf( ConnectorType::class, $connector->type() );
		$this->assertTrue( ConnectorRegistry::sameMajor( $connector->contractVersion(), ConnectorInterface::CONTRACT_VERSION ), 'The contract major version must match the core.' );
		$this->assertSame( array(), ( new ConnectorRegistry( array( $connector ) ) )->rejected() );
	}

	public function test_capability_flags_are_complete_and_boolean(): void {
		$flags = $this->connectorUnderTest()->capabilities();
		foreach ( Capability::cases() as $capability ) {
			$this->assertArrayHasKey( $capability->value, $flags, $capability->value );
			$this->assertIsBool( $flags[ $capability->value ], $capability->value );
		}
		$this->assertCount( count( Capability::cases() ), $flags, 'No flag the core does not know.' );
	}

	public function test_a_declared_entity_is_sent_and_an_undeclared_one_is_refused_without_throwing(): void {
		$connector = $this->connectorUnderTest();
		$flags     = $connector->capabilities();
		$entities  = $connector->supportedEntities();
		$this->assertSame( $flags[ Capability::Orders->value ], in_array( EntityType::Order, $entities, true ) );
		$this->assertSame( $flags[ Capability::Refunds->value ], in_array( EntityType::Refund, $entities, true ) );
		$this->assertSame( $flags[ Capability::Customers->value ], in_array( EntityType::Customer, $entities, true ) );

		foreach ( array( EntityType::Stock, EntityType::Tracking, EntityType::Invoice ) as $type ) {
			if ( in_array( $type, $entities, true ) ) {
				continue;
			}
			$result = $connector->send( $this->workingConnection(), new Item( 9, 1, $type, '5', 'created', 'x:5', 'k', \Estasoft\SyncEngine\Domain\ItemState::Syncing ), array() );
			$this->assertFalse( $result->isSuccess(), $type->value );
			$this->assertInstanceOf( ConnectorError::class, $result->error );
		}
	}

	public function test_a_flag_that_is_true_has_a_method_that_works(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->workingConnection();
		$flags      = $connector->capabilities();
		$since      = new \DateTimeImmutable( '2026-01-01T00:00:00Z' );

		if ( $flags[ Capability::Orders->value ] ) {
			list( $item, $payload ) = $this->orderFixture( '9001' );
			$this->assertTrue( $connector->send( $connection, $item, $payload )->isSuccess(), 'orders' );
		}
		if ( $flags[ Capability::StockIn->value ] ) {
			$this->assertIsArray( $connector->fetchStock( $connection, array( 'CS-MUG-01' ), array() ), 'stock_in' );
		}
		if ( $flags[ Capability::TrackingIn->value ] ) {
			$this->assertIsArray( $connector->fetchTracking( $connection, $since ), 'tracking_in' );
		}
		if ( $flags[ Capability::Reconciliation->value ] ) {
			$list = $connector->listForReconcile( $connection, $since );
			$this->assertIsArray( $list, 'reconciliation' );
			foreach ( $list as $key => $ref ) {
				$this->assertIsString( $key );
				$this->assertInstanceOf( ExternalRef::class, $ref );
				$this->assertArrayHasKey( 'total_minor', $ref->extra );
				$this->assertIsInt( $ref->extra['total_minor'] );
				$this->assertArrayHasKey( 'refunded_minor', $ref->extra );
				$this->assertArrayHasKey( 'email', $ref->extra );
			}
		}
		if ( $flags[ Capability::InvoiceReadback->value ] ) {
			$result = $connector->fetchInvoice( $connection, new ExternalRef( '1', 'S1' ) );
			$this->assertTrue( null === $result || is_array( $result ) || $result instanceof ConnectorError, 'invoice_readback' );
		}
		if ( $flags[ Capability::ShadowLookup->value ] ) {
			$this->assertTrue( null === $connector->lookupCustomer( $connection, 'nobody@example.test' ) || $connector->lookupCustomer( $connection, 'nobody@example.test' ) instanceof ExternalRef );
			$this->assertTrue( null === $connector->lookupProduct( $connection, 'NO-SUCH-SKU' ) );
			$this->assertIsArray( $connector->searchProducts( $connection, 'zzzz-no-match' ) );
		}
	}

	public function test_credential_fields_have_unique_names_and_mark_the_secret(): void {
		$names   = array();
		$secrets = 0;
		foreach ( $this->connectorUnderTest()->credentialFields() as $field ) {
			$this->assertInstanceOf( CredentialField::class, $field );
			$this->assertNotContains( $field->name, $names );
			$this->assertNotSame( '', $field->label );
			$names[]  = $field->name;
			$secrets += $field->secret ? 1 : 0;
		}
		$this->assertGreaterThanOrEqual( 1, $secrets, 'A key-style connector has at least one secret field.' );
	}

	public function test_rate_limit_hints_are_sane(): void {
		$hints = $this->connectorUnderTest()->rateLimitHints();
		$this->assertGreaterThan( 0, $hints['requests_per_second'] );
		$this->assertGreaterThanOrEqual( 1, $hints['concurrency'] );
	}

	/* ------------------------------------------------------------ idempotency */

	public function test_the_same_key_gives_the_same_reference_and_one_record(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->workingConnection();
		list( $item, $payload ) = $this->orderFixture( '9100' );

		$this->assertNull( $connector->findExisting( $connection, $item ), 'Nothing exists before the first send.' );
		$first  = $connector->send( $connection, $item, $payload );
		$second = $connector->send( $connection, $item, $payload );
		$found  = $connector->findExisting( $connection, $item );

		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->isSuccess() );
		$this->assertTrue( $second->alreadyExisted, 'The second send links to the first record.' );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $first->ref->ref, $second->ref->ref );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $first->ref->id, $found->id );
	}

	public function test_another_item_gets_another_record(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->workingConnection();
		list( $a, $payloadA ) = $this->orderFixture( '9201' );
		list( $b, $payloadB ) = $this->orderFixture( '9202' );
		$first  = $connector->send( $connection, $a, $payloadA );
		$second = $connector->send( $connection, $b, $payloadB );
		$this->assertNotSame( $first->ref->id, $second->ref->id );
		$this->assertFalse( $second->alreadyExisted );
	}

	/* ------------------------------------------------------------ failures */

	/**
	 * @return array<string,array{0:string,1:ErrorCode,2:ErrorClass}>
	 */
	public static function provide_failures(): array {
		return array(
			'time out'     => array( 'timeout', ErrorCode::Net001, ErrorClass::Retry ),
			'server error' => array( 'server_error', ErrorCode::Net002, ErrorClass::Retry ),
			'refused key'  => array( 'auth', ErrorCode::Auth001, ErrorClass::NeedsPerson ),
			'certificate'  => array( 'certificate', ErrorCode::Net003, ErrorClass::NeedsPerson ),
		);
	}

	/**
	 * @dataProvider provide_failures
	 *
	 * @param string    $kind  Failure kind.
	 * @param ErrorCode $code  Expected code.
	 * @param ErrorClass $class Expected class.
	 */
	public function test_failures_are_classified_the_way_the_catalog_says( string $kind, ErrorCode $code, ErrorClass $class ): void {
		list( $item, $payload ) = $this->orderFixture( '9300' );
		$this->failNextWrite( $kind );
		$result = $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload );

		$this->assertFalse( $result->isSuccess() );
		$this->assertInstanceOf( ConnectorError::class, $result->error );
		$this->assertSame( $code, $result->error->code );
		$this->assertSame( $class, $this->classOf( $result->error ) );
	}

	public function test_a_failure_the_connector_does_not_know_is_still_classified_and_never_marked_handled(): void {
		list( $item, $payload ) = $this->orderFixture( '9301' );
		$this->failNextWrite( 'unknown_fault' );
		$result = $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload );

		$this->assertFalse( $result->isSuccess() );
		$class = $this->classOf( $result->error );
		$this->assertContains( $class, array( ErrorClass::Retry, ErrorClass::NeedsPerson ), 'An unknown failure is tried again or shown to a person, never hidden.' );
		$this->assertNotSame( '', $result->error->message() );
	}

	public function test_a_rate_limit_carries_the_wait_the_system_asked_for(): void {
		list( $item, $payload ) = $this->orderFixture( '9302' );
		$this->failNextWrite( 'rate_limit', 37 );
		$result = $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload );

		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 37, $result->error->retryAfter );
		$this->assertSame( 429, $result->error->httpStatus );
		$this->assertSame( ErrorClass::Retry, $this->classOf( $result->error ) );
	}

	public function test_a_failed_send_creates_nothing_that_a_retry_would_duplicate(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->workingConnection();
		list( $item, $payload ) = $this->orderFixture( '9303' );
		$this->failNextWrite( 'server_error' );
		$this->assertFalse( $connector->send( $connection, $item, $payload )->isSuccess() );

		$retry = $connector->send( $connection, $item, $payload );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertFalse( $retry->alreadyExisted );
		$again = $connector->send( $connection, $item, $payload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $retry->ref->id, $again->ref->id );
	}

	public function test_methods_return_errors_and_do_not_throw_when_the_secret_is_wrong(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->brokenConnection();
		list( $item, $payload ) = $this->orderFixture( '9400' );
		$since = new \DateTimeImmutable( '2026-01-01T00:00:00Z' );

		$this->assertFalse( $connector->send( $connection, $item, $payload )->isSuccess() );
		foreach ( array(
			$connector->findExisting( $connection, $item ),
			$connector->lookupCustomer( $connection, 'a@example.test' ),
			$connector->lookupProduct( $connection, 'X' ),
			$connector->searchProducts( $connection, 'x' ),
			$connector->fetchStock( $connection, array( 'X' ), array() ),
			$connector->fetchTracking( $connection, $since ),
			$connector->fetchInvoice( $connection, new ExternalRef( '1' ) ),
			$connector->listForReconcile( $connection, $since ),
		) as $result ) {
			$this->assertInstanceOf( ConnectorError::class, $result );
			$this->assertSame( ErrorCode::Auth001, $result->code );
			$this->assertSame( ErrorClass::NeedsPerson, $this->classOf( $result ) );
		}
		$test = $connector->test( $connection );
		$this->assertFalse( $test->ok );
		$this->assertSame( ErrorCode::Auth001, $test->error->code );
	}

	public function test_test_connection_gives_a_result_with_what_the_screen_needs(): void {
		$result = $this->connectorUnderTest()->test( $this->workingConnection() );
		$this->assertInstanceOf( TestResult::class, $result );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertNotSame( '', $result->version );
		$this->assertNotSame( '', $result->company );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertNull( $result->error );
	}

	public function test_ping_answers_yes_for_a_working_system_and_never_throws(): void {
		$this->assertTrue( $this->connectorUnderTest()->ping( $this->workingConnection() ) );
	}

	/* ------------------------------------------------------------ secrets */

	public function test_secrets_are_never_repeated_in_any_output(): void {
		$connector = $this->connectorUnderTest();
		foreach ( array( 'timeout', 'server_error', 'auth', 'certificate', 'unknown_fault', 'rate_limit' ) as $index => $kind ) {
			list( $item, $payload ) = $this->orderFixture( (string) ( 9500 + $index ) );
			$this->failNextWrite( $kind, 11 );
			$result = $connector->send( $this->workingConnection(), $item, $payload );
			$this->assertNoSecret( $this->textOf( $result->error ), "the {$kind} error" );
		}
		$broken = $connector->test( $this->brokenConnection() );
		$this->assertNoSecret( $this->textOf( $broken->error ) . wp_json_encode( $broken->detected ), 'a failed test' );
		$good = $connector->test( $this->workingConnection() );
		$this->assertNoSecret( wp_json_encode( array( $good->version, $good->company, $good->warehouses, $good->permissions, $good->detected ) ), 'a passing test' );

		list( $item, $payload ) = $this->orderFixture( '9599' );
		$sent = $connector->send( $this->workingConnection(), $item, $payload );
		$this->assertNoSecret( wp_json_encode( array( $sent->ref?->id, $sent->ref?->ref, $sent->ref?->url, $sent->ref?->extra ) ), 'a reference' );
		$this->assertNoSecret( print_r( $this->workingConnection(), true ), 'a printed connection' );
	}

	/* ------------------------------------------------------------ text and money */

	public function test_arabic_text_comes_back_exactly_as_it_went_in(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->workingConnection();
		$name       = 'نور المنصوري';
		list( $item, $payload ) = $this->orderFixture( '9600', array( 'name' => $name, 'email' => 'noor.ar@example.test', 'street' => 'شارع الشيخ زايد، برج ٢٣' ) );
		$this->assertTrue( $connector->send( $connection, $item, $payload )->isSuccess() );

		$ref = $connector->lookupCustomer( $connection, 'noor.ar@example.test' );
		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( $name, $this->customerNameFrom( $ref ), 'No escaping, no transliteration, no normalisation.' );
	}

	public function test_money_round_trips_by_the_currency_exponent_without_float_drift(): void {
		$connector  = $this->connectorUnderTest();
		$connection = $this->workingConnection();

		list( $two, $payloadTwo ) = $this->orderFixture( '9701', array( 'currency' => 'AED', 'unit_minor' => 1999, 'quantity' => 3 ) );
		$sent = $connector->send( $connection, $two, $payloadTwo );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 5997, $this->totalMinorOf( $sent->ref ), '3 x 19.99, two decimals, exactly.' );

		list( $three, $payloadThree ) = $this->orderFixture( '9702', array( 'currency' => 'KWD', 'unit_minor' => 12345, 'quantity' => 3 ) );
		$sent = $connector->send( $connection, $three, $payloadThree );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 37035, $this->totalMinorOf( $sent->ref ), '3 x 12.345 KWD, three decimals, exactly.' );

		$found = $connector->findExisting( $connection, $three );
		$this->assertSame( 37035, $this->totalMinorOf( $found ) );
	}

	public function test_requests_never_take_longer_than_thirty_seconds(): void {
		$connector = $this->connectorUnderTest();
		list( $item, $payload ) = $this->orderFixture( '9800' );
		$connector->test( $this->workingConnection() );
		$connector->send( $this->workingConnection(), $item, $payload );
		$timeouts = $this->requestTimeouts();
		$this->assertNotEmpty( $timeouts );
		$this->assertLessThanOrEqual( 30, max( $timeouts ) );
	}

	/* ------------------------------------------------------------ fields and rules */

	public function test_every_mapping_type_has_fields_and_every_default_rule_targets_a_known_field(): void {
		$connector = $this->connectorUnderTest();
		foreach ( array( 'orders', 'customers', 'products', 'refunds' ) as $type ) {
			$fields = $connector->describeFields( $type );
			$paths  = array_map( static fn( array $field ): string => (string) preg_replace( '/\[\d*\]/', '', $field['path'] ), $fields );
			$this->assertNotEmpty( $fields, $type );
			foreach ( $connector->defaultMapping( $type ) as $rule ) {
				$this->assertContains( (string) preg_replace( '/\[\d*\]/', '', (string) $rule['target'] ), $paths, $type . ': ' . $rule['id'] );
			}
		}
	}

	/**
	 * The class that applies to an error: the connector's override, else the catalog's.
	 *
	 * @param ConnectorError $error Error.
	 */
	private function classOf( ConnectorError $error ): ErrorClass {
		return $this->connectorUnderTest()->classifyError( $error ) ?? $error->errorClass();
	}

	/**
	 * Send results are objects the engine reads through these two methods.
	 */
	public function test_a_send_result_is_either_a_record_or_an_error_never_both(): void {
		list( $item, $payload ) = $this->orderFixture( '9900' );
		$ok = $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload );
		$this->assertInstanceOf( SendResult::class, $ok );
		$this->assertTrue( $ok->isSuccess() );
		$this->assertNull( $ok->error );
		$this->assertNotNull( $ok->ref );
		$this->assertNotSame( '', $ok->ref->id );

		list( $item, $payload ) = $this->orderFixture( '9901' );
		$this->failNextWrite( 'timeout' );
		$bad = $this->connectorUnderTest()->send( $this->workingConnection(), $item, $payload );
		$this->assertFalse( $bad->isSuccess() );
		$this->assertNull( $bad->ref );
		$this->assertNotNull( $bad->error );
	}
}
