<?php
/**
 * One-time state and PKCE storage for sign-in flows.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\OAuth;

defined( 'ABSPATH' ) || exit;

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
	 * Use a state: return what was stored and make it invalid.
	 *
	 * @param string $state        State from the callback.
	 * @param int    $connectionId The connection of the callback route.
	 * @return array{user:int,connection:int,verifier:string,route:string}|null Null when it cannot be used.
	 */
	public function consume( string $state, int $connectionId ): ?array {
		$entry = $this->peek( $state, $connectionId );
		if ( null !== $entry ) {
			$this->store->delete( $this->key( $state ) );
		}
		return $entry;
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
