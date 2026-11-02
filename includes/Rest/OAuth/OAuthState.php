<?php
/**
 * One-time state and PKCE storage for sign-in flows.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Rest\Support\AtomicKeyValueStore;
use Estasoft\SyncEngine\Rest\Support\KeyValueStore;

/**
 * Starting a sign-in creates a random `state`, bound to the person and the connection, kept for ten minutes together with the PKCE
 * verifier. The callback checks the state, the connection and the person's capability, and uses it once. The state itself is
 * never stored, only its hash, so a copy of the database does not reveal a valid one.
 */
final class OAuthState {

	/**
	 * Seconds a state stays valid.
	 */
	public const TTL = 600;

	/**
	 * Set up with what it depends on.
	 *
	 * @param KeyValueStore $store Short-lived storage.
	 */
	public function __construct( private readonly KeyValueStore $store ) {
	}

	/**
	 * Start a sign-in.
	 *
	 * @param int    $userId       The person who starts it.
	 * @param int    $connectionId The connection being signed in.
	 * @param string $returnRoute  App route to open afterwards, for example /connections/3.
	 * @return array{state:string,challenge:string} The state to send to the provider and the PKCE challenge.
	 */
	public function begin( int $userId, int $connectionId, string $returnRoute = '/connections' ): array {
		$state    = $this->random( 32 );
		$verifier = $this->random( 48 );
		$this->store->set(
			$this->key( $state ),
			array(
				'user'       => $userId,
				'connection' => $connectionId,
				'verifier'   => $verifier,
				'route'      => $returnRoute,
			),
			self::TTL
		);
		return array(
			'state'     => $state,
			'challenge' => $this->challenge( $verifier ),
		);
	}

	/**
	 * Whether a text has the shape of a state this class makes. This says nothing about whether the state is valid; it only keeps
	 * requests that could never have come from a sign-in away from the store.
	 *
	 * @param mixed $state Value of the `state` parameter.
	 */
	public static function wellFormed( mixed $state ): bool {
		return is_string( $state ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{20,128}$/D', $state );
	}

	/**
	 * Look at a state without using it.
	 *
	 * @param string $state        State from the callback.
	 * @param int    $connectionId The connection of the callback route.
	 * @return array{user:int,connection:int,verifier:string,route:string}|null Null when unknown, expired or for another connection.
	 */
	public function peek( string $state, int $connectionId ): ?array {
		if ( ! self::wellFormed( $state ) ) {
			return null;
		}
		$entry = $this->store->get( $this->key( $state ) );
		if ( ! is_array( $entry ) || (int) ( $entry['connection'] ?? 0 ) !== $connectionId ) {
			return null;
		}
		return array(
			'user'       => (int) $entry['user'],
			'connection' => (int) $entry['connection'],
			'verifier'   => (string) ( $entry['verifier'] ?? '' ),
			'route'      => (string) ( $entry['route'] ?? '/connections' ),
		);
	}

	/**
	 * Use a state without knowing the connection beforehand (the stable callback address carries no connection id): the state says which
	 * connection it belongs to. It is made invalid at once, exactly as consume() does.
	 *
	 * @param string $state State from the callback.
	 * @return array{user:int,connection:int,verifier:string,route:string}|null Null when it is unknown, expired or malformed.
	 */
	public function consumeAny( string $state ): ?array {
		if ( ! self::wellFormed( $state ) ) {
			return null;
		}
		$entry = $this->claim( $state );
		if ( null === $entry ) {
			return null;
		}
		return array(
			'user'       => (int) ( $entry['user'] ?? 0 ),
			'connection' => (int) ( $entry['connection'] ?? 0 ),
			'verifier'   => (string) ( $entry['verifier'] ?? '' ),
			'route'      => (string) ( $entry['route'] ?? '/connections' ),
		);
	}

	/**
	 * Use a state: return what was stored and make it invalid. Of two callbacks that arrive together only one gets it.
	 *
	 * @param string $state        State from the callback.
	 * @param int    $connectionId The connection of the callback route.
	 * @return array{user:int,connection:int,verifier:string,route:string}|null Null when it cannot be used.
	 */
	public function consume( string $state, int $connectionId ): ?array {
		$entry = $this->peek( $state, $connectionId );
		if ( null === $entry || null === $this->claim( $state ) ) {
			return null;
		}
		return $entry;
	}

	/**
	 * Take the stored entry out of the store. With a store that can do it (`AtomicKeyValueStore`) reading and deleting are one step, so two
	 * simultaneous callbacks cannot both succeed; any other store reads and then deletes.
	 *
	 * @param string $state State.
	 * @return array<string,mixed>|null
	 */
	private function claim( string $state ): ?array {
		$key = $this->key( $state );
		if ( $this->store instanceof AtomicKeyValueStore ) {
			$entry = $this->store->take( $key );
		} else {
			$entry = $this->store->get( $key );
			$this->store->delete( $key );
		}
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * The PKCE verifier of a stored state, needed by the callback.
	 *
	 * @param string $verifier Verifier.
	 */
	public function challenge( string $verifier ): string {
		return $this->urlSafe( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Where a state is kept.
	 *
	 * @param string $state State.
	 */
	private function key( string $state ): string {
		return 'oauth_' . hash( 'sha256', $state );
	}

	/**
	 * A random URL-safe string.
	 *
	 * @param int $bytes Random bytes (the string is longer).
	 */
	private function random( int $bytes ): string {
		return $this->urlSafe( random_bytes( $bytes ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $binary Bytes.
	 */
	private function urlSafe( string $binary ): string {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes random bytes for a URL, not code.
	}
}
