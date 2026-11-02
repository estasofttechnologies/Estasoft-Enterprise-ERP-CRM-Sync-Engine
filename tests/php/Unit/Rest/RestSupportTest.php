<?php
/**
 * The idempotency cache, the connection resolver and the sign-in state.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Rest;

use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\IdempotencyCache;
use Estasoft\SyncEngine\Rest\OAuth\OAuthState;
use Estasoft\SyncEngine\Rest\Support\AtomicKeyValueStore;
use Estasoft\SyncEngine\Rest\Support\ConnectionResolver;
use Estasoft\SyncEngine\Rest\Support\KeyValueStore;
use Estasoft\SyncEngine\Tests\Support\RestArrayStore;
use PHPUnit\Framework\TestCase;

final class RestSupportTest extends TestCase {

	private RestArrayStore $store;

	protected function setUp(): void {
		$this->store = new RestArrayStore();
	}

	private function cache(): IdempotencyCache {
		return new IdempotencyCache( $this->store, 'salt' );
	}

	public function test_no_header_means_no_key_and_a_good_key_is_returned(): void {
		$this->assertNull( $this->cache()->validKey( '' ) );
		$this->assertNull( $this->cache()->validKey( '   ' ) );
		$this->assertSame( 'a1b2-C3_d4.e5:f6', $this->cache()->validKey( ' a1b2-C3_d4.e5:f6 ' ) );
		$this->assertSame( str_repeat( 'a', 128 ), $this->cache()->validKey( str_repeat( 'a', 128 ) ) );
	}

	/**
	 * @dataProvider badKeys
	 *
	 * @param string $key Header value.
	 */
	public function test_a_key_that_is_too_long_or_has_odd_characters_is_a_400( string $key ): void {
		try {
			$this->cache()->validKey( $key );
			$this->fail( 'expected a refusal' );
		} catch ( ApiException $error ) {
			$this->assertSame( array( 400, 'estasoft_sync_bad_idempotency_key' ), array( $error->status, $error->apiCode ) );
		}
	}

	/**
	 * Keys that are refused.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function badKeys(): array {
		return array(
			'too long' => array( str_repeat( 'a', 129 ) ),
			'space'    => array( 'two words' ),
			'quote'    => array( "k'; --" ),
			'unicode'  => array( 'مفتاح' ),
		);
	}

	public function test_a_new_key_has_no_stored_answer_and_a_stored_one_is_replayed_for_ten_minutes(): void {
		$cache = $this->cache();
		$this->assertNull( $cache->replay( 7, 'k1', 'POST', '/estasoft-sync/v1/queue/1/retry', '' ) );
		$cache->remember( 7, 'k1', 'POST', '/estasoft-sync/v1/queue/1/retry', '', 200, array( 'id' => 1 ) );
		$this->assertSame( array( 'status' => 200, 'data' => array( 'id' => 1 ) ), $cache->replay( 7, 'k1', 'POST', '/estasoft-sync/v1/queue/1/retry', '' ) );
		$this->assertSame( array( 600 ), array_values( $this->store->ttl ) );
		$this->store->expireAll();
		$this->assertNull( $cache->replay( 7, 'k1', 'POST', '/estasoft-sync/v1/queue/1/retry', '' ), 'after ten minutes the key is new again' );
	}

	public function test_the_same_key_for_another_person_method_or_route_is_a_different_key(): void {
		$cache = $this->cache();
		$cache->remember( 7, 'k1', 'POST', '/r', '', 200, 'first' );
		$this->assertNull( $cache->replay( 8, 'k1', 'POST', '/r', '' ), 'another person' );
		$this->assertNull( $cache->replay( 7, 'k1', 'PUT', '/r', '' ), 'another method' );
		$this->assertNull( $cache->replay( 7, 'k1', 'POST', '/other', '' ), 'another route' );
		$this->assertNull( $cache->replay( 7, 'k2', 'POST', '/r', '' ), 'another key' );
	}

	public function test_the_same_key_with_a_different_body_is_a_409(): void {
		$cache = $this->cache();
		$cache->remember( 7, 'k1', 'POST', '/r', '{"ids":[1]}', 200, 'first' );
		$this->assertSame( 'first', $cache->replay( 7, 'k1', 'POST', '/r', '{"ids":[1]}' )['data'] );
		try {
			$cache->replay( 7, 'k1', 'POST', '/r', '{"ids":[2]}' );
			$this->fail( 'expected a conflict' );
		} catch ( ApiException $error ) {
			$this->assertSame( array( 409, 'estasoft_sync_idempotency_conflict' ), array( $error->status, $error->apiCode ) );
		}
	}

	public function test_the_same_key_with_another_query_or_route_parameter_is_a_409_and_the_order_of_parameters_does_not_matter(): void {
		$cache = $this->cache();
		$cache->remember( 7, 'k1', 'POST', '/r', '', 200, 'first', array( 'connection_id' => '1', 'tags' => array( 'b' => '2', 'a' => '1' ) ), array( 'id' => '5' ) );

		$same = $cache->replay( 7, 'k1', 'POST', '/r', '', array( 'tags' => array( 'a' => '1', 'b' => '2' ), 'connection_id' => '1' ), array( 'id' => '5' ) );
		$this->assertSame( 'first', $same['data'] );

		foreach (
			array(
				'another value'   => array( array( 'connection_id' => '2', 'tags' => array( 'b' => '2', 'a' => '1' ) ), array( 'id' => '5' ) ),
				'a missing query' => array( array(), array( 'id' => '5' ) ),
				'a nested value'  => array( array( 'connection_id' => '1', 'tags' => array( 'b' => '3', 'a' => '1' ) ), array( 'id' => '5' ) ),
				'a route value'   => array( array( 'connection_id' => '1', 'tags' => array( 'b' => '2', 'a' => '1' ) ), array( 'id' => '6' ) ),
			) as $label => $other
		) {
			try {
				$cache->replay( 7, 'k1', 'POST', '/r', '', $other[0], $other[1] );
				$this->fail( 'expected a conflict for ' . $label );
			} catch ( ApiException $error ) {
				$this->assertSame( array( 409, 'estasoft_sync_idempotency_conflict' ), array( $error->status, $error->apiCode ), $label );
			}
		}
	}

	public function test_the_store_holds_no_query_value_in_the_clear(): void {
		$this->cache()->remember( 7, 'k1', 'POST', '/r', '', 200, array( 'ok' => true ), array( 'token' => 'TOPSECRET-IN-QUERY' ), array( 'id' => '5' ) );
		$this->assertStringNotContainsString( 'TOPSECRET-IN-QUERY', (string) json_encode( array( $this->store->values, array_keys( $this->store->values ) ) ) );
	}

	public function test_failed_answers_are_not_kept(): void {
		$cache = $this->cache();
		$cache->remember( 7, 'k1', 'POST', '/r', '', 409, array( 'code' => 'x' ) );
		$cache->remember( 7, 'k2', 'POST', '/r', '', 500, array( 'code' => 'x' ) );
		$this->assertSame( array(), $this->store->values );
	}

	public function test_the_store_holds_neither_the_body_nor_the_key_in_the_clear(): void {
		$this->cache()->remember( 7, 'my-visible-key', 'POST', '/connections/test', '{"secrets":{"api_key":"sk-live-TOPSECRET"}}', 200, array( 'ok' => true ) );
		$dump = (string) json_encode( array( $this->store->values, array_keys( $this->store->values ) ) );
		$this->assertStringNotContainsString( 'TOPSECRET', $dump );
		$this->assertStringNotContainsString( 'my-visible-key', $dump );
		$this->assertStringNotContainsString( 'api_key', $dump );
	}

	/**
	 * A resolver over a list of connections.
	 *
	 * @param array<int,array<string,mixed>> $connections Connections.
	 */
	private function resolver( array $connections ): ConnectionResolver {
		return new ConnectionResolver( static fn(): array => $connections );
	}

	public function test_the_resolver_takes_the_one_asked_for_or_the_first_that_is_not_disconnected(): void {
		$all = array( array( 'id' => 3, 'status' => 'disconnected' ), array( 'id' => 5, 'status' => 'paused' ), array( 'id' => 8, 'status' => 'active' ) );
		$this->assertSame( 5, $this->resolver( $all )->resolve() );
		$this->assertSame( 5, $this->resolver( $all )->resolve( null ) );
		$this->assertSame( 5, $this->resolver( $all )->resolve( 0 ) );
		$this->assertSame( 8, $this->resolver( $all )->resolve( 8 ) );
		$this->assertSame( 3, $this->resolver( $all )->resolve( 3 ), 'a person may ask for a disconnected one by id' );
	}

	public function test_the_resolver_is_a_404_for_an_unknown_id_and_a_409_without_any_connection(): void {
		try {
			$this->resolver( array( array( 'id' => 1, 'status' => 'active' ) ) )->resolve( 2 );
			$this->fail( 'expected a 404' );
		} catch ( ApiException $error ) {
			$this->assertSame( 404, $error->status );
		}
		foreach ( array( array(), array( array( 'id' => 1, 'status' => 'disconnected' ) ) ) as $none ) {
			try {
				$this->resolver( $none )->resolve();
				$this->fail( 'expected a 409' );
			} catch ( ApiException $error ) {
				$this->assertSame( array( 409, 'estasoft_sync_no_connection' ), array( $error->status, $error->apiCode ) );
			}
		}
	}

	public function test_a_sign_in_state_is_random_bound_to_the_person_and_connection_and_valid_for_ten_minutes(): void {
		$state = new OAuthState( $this->store );
		$a     = $state->begin( 7, 3, '/connections/3' );
		$b     = $state->begin( 7, 3 );
		$this->assertNotSame( $a['state'], $b['state'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_\-]{40,}$/', $a['state'] );
		$this->assertSame( 43, strlen( $a['challenge'] ), 'base64url of a SHA-256 digest' );
		$this->assertSame( array( 600, 600 ), array_values( $this->store->ttl ) );

		$peek = $state->peek( $a['state'], 3 );
		$this->assertSame( 7, $peek['user'] );
		$this->assertSame( '/connections/3', $peek['route'] );
		$this->assertSame( $a['challenge'], $state->challenge( $peek['verifier'] ), 'the stored verifier belongs to the challenge' );
		$this->assertNotNull( $state->peek( $a['state'], 3 ), 'looking does not use it up' );
		$this->assertNull( $state->peek( $a['state'], 4 ), 'another connection' );
	}

	public function test_a_sign_in_state_can_be_used_once_and_expires(): void {
		$state = new OAuthState( $this->store );
		$begin = $state->begin( 7, 3 );
		$this->assertNotNull( $state->consume( $begin['state'], 3 ) );
		$this->assertNull( $state->consume( $begin['state'], 3 ), 'used up' );
		$this->assertNull( $state->peek( $begin['state'], 3 ) );

		$later = $state->begin( 7, 3 );
		$this->store->expireAll();
		$this->assertNull( $state->peek( $later['state'], 3 ), 'ten minutes passed' );
	}

	public function test_the_stable_callback_uses_a_state_once_whichever_way_it_is_asked(): void {
		$state = new OAuthState( $this->store );
		$begin = $state->begin( 7, 3, '/connections/3' );
		$first = $state->consumeAny( $begin['state'] );
		$this->assertSame( 3, $first['connection'] );
		$this->assertSame( 7, $first['user'] );
		$this->assertNull( $state->consumeAny( $begin['state'] ), 'used up' );
		$this->assertNull( $state->consume( $begin['state'], 3 ) );
	}

	public function test_of_two_callbacks_that_read_the_state_together_only_one_succeeds(): void {
		$racing = new class( $this->store ) implements AtomicKeyValueStore {
			public bool $other = false;
			public function __construct( private RestArrayStore $inner ) {}
			public function get( string $key ): mixed {
				$value = $this->inner->get( $key );
				if ( $this->other ) {
					$this->other = false;
					$this->inner->take( $key ); // The other callback took it right after this one read it.
				}
				return $value;
			}
			public function set( string $key, mixed $value, int $seconds ): void {
				$this->inner->set( $key, $value, $seconds );
			}
			public function delete( string $key ): void {
				$this->inner->delete( $key );
			}
			public function take( string $key ): mixed {
				return $this->inner->take( $key );
			}
		};
		$state = new OAuthState( $racing );
		$begin = $state->begin( 7, 3 );
		$racing->other = true;
		$this->assertNull( $state->consume( $begin['state'], 3 ), 'The read succeeded, but the other callback had already taken the state.' );

		$again = $state->begin( 7, 3 );
		$this->assertNotNull( $state->consumeAny( $again['state'] ) );
		$this->assertNull( $state->consumeAny( $again['state'] ) );
	}

	public function test_a_store_without_an_atomic_take_still_gives_a_state_out_once(): void {
		$plain = new class( $this->store ) implements KeyValueStore {
			public function __construct( private RestArrayStore $inner ) {}
			public function get( string $key ): mixed {
				return $this->inner->get( $key );
			}
			public function set( string $key, mixed $value, int $seconds ): void {
				$this->inner->set( $key, $value, $seconds );
			}
			public function delete( string $key ): void {
				$this->inner->delete( $key );
			}
		};
		$state = new OAuthState( $plain );
		$begin = $state->begin( 7, 3 );
		$this->assertNotNull( $state->consumeAny( $begin['state'] ) );
		$this->assertNull( $state->consumeAny( $begin['state'] ) );
	}

	public function test_a_made_up_or_malformed_state_is_never_valid_and_the_state_itself_is_not_stored(): void {
		$state = new OAuthState( $this->store );
		$begin = $state->begin( 7, 3 );
		foreach ( array( '', 'short', str_repeat( 'a', 43 ), $begin['state'] . '!', str_repeat( 'a', 200 ) ) as $bad ) {
			$this->assertNull( $state->peek( $bad, 3 ), $bad );
		}
		$this->assertStringNotContainsString( $begin['state'], (string) json_encode( array_keys( $this->store->values ) ), 'only a hash is a key' );
	}

	public function test_well_formed_only_says_the_text_has_the_shape_of_a_state(): void {
		$state = new OAuthState( $this->store );
		$begin = $state->begin( 7, 3 );
		$this->assertTrue( OAuthState::wellFormed( $begin['state'] ) );
		$this->assertTrue( OAuthState::wellFormed( str_repeat( 'a', 43 ) ), 'a made-up one has the shape too; it is refused later by consume()' );
		$this->assertNull( $state->consume( str_repeat( 'a', 43 ), 3 ) );
		foreach ( array( null, 12, array( 'a' ), true, '', 'short', str_repeat( 'a', 19 ), str_repeat( 'a', 129 ), $begin['state'] . '!', $begin['state'] . "\n", "a\nb" . str_repeat( 'a', 30 ) ) as $bad ) {
			$this->assertFalse( OAuthState::wellFormed( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_the_pkce_challenge_matches_the_example_in_rfc_7636(): void {
		$state = new OAuthState( $this->store );
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $state->challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
	}
}
