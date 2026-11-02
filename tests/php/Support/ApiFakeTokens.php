<?php
/**
 * A fake OAuth 2 token endpoint and the bearer check of the API behind it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Registers a token route on an ApiFakeServer. It issues access tokens that live `$lifetime` seconds, rotates refresh tokens, and
 * remembers which access tokens are valid, so the API routes can answer 401 for a token that ran out or was replaced:
 * `if ( null !== $response = $tokens->check( $request ) ) { return $response; }`.
 */
final class ApiFakeTokens {

	/**
	 * Valid codes (a code works once).
	 *
	 * @var array<string,bool>
	 */
	public array $codes = array( 'good-code' => true );

	/**
	 * Valid refresh tokens by token.
	 *
	 * @var array<string,bool>
	 */
	public array $refreshTokens = array( 'refresh-token-AAAAAAAAAAAA' => true );

	/**
	 * Valid access tokens.
	 *
	 * @var array<string,bool>
	 */
	public array $accessTokens = array();

	/**
	 * Token requests received, as decoded forms.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $requests = array();

	/**
	 * Number of tokens issued so far.
	 */
	public int $issued = 0;

	/**
	 * The next token answer, replacing the normal one.
	 */
	public ?HttpResponse $next = null;

	/**
	 * Whether refresh tokens rotate.
	 */
	public bool $rotate = true;

	/**
	 * @param ApiFakeServer $server   Server.
	 * @param string        $path     Token path.
	 * @param int           $lifetime Seconds an access token lives (the answer's expires_in).
	 */
	public function __construct( ApiFakeServer $server, string $path = '/oauth/token', private readonly int $lifetime = 1800 ) {
		$server->route(
			'POST',
			$path,
			function ( ApiFakeRequest $request ): HttpResponse {
				$form             = $request->form();
				$this->requests[] = $form + array( '_authorization' => (string) $request->header( 'Authorization' ) );
				if ( null !== $this->next ) {
					$answer     = $this->next;
					$this->next = null;
					return $answer;
				}
				return $this->issue( $form );
			}
		);
	}

	/**
	 * Mark every access token as no longer valid (the vendor expired them).
	 */
	public function expireAccessTokens(): void {
		$this->accessTokens = array();
	}

	/**
	 * Revoke a refresh token.
	 *
	 * @param string $token Token.
	 */
	public function revoke( string $token ): void {
		unset( $this->refreshTokens[ $token ] );
	}

	/**
	 * The API-side check: null when the bearer token is valid, a 401 answer otherwise.
	 *
	 * @param ApiFakeRequest $request Request.
	 */
	public function check( ApiFakeRequest $request ): ?HttpResponse {
		if ( isset( $this->accessTokens[ $request->bearer() ] ) ) {
			return null;
		}
		return FakeHttpClient::status( 401, '{"message":"The access token is invalid or expired"}' );
	}

	/**
	 * Answer a token request.
	 *
	 * @param array<string,mixed> $form Decoded request.
	 */
	private function issue( array $form ): HttpResponse {
		$grant = (string) ( $form['grant_type'] ?? '' );
		if ( 'authorization_code' === $grant ) {
			if ( empty( $this->codes[ (string) ( $form['code'] ?? '' ) ] ) ) {
				return FakeHttpClient::json( array( 'error' => 'invalid_grant', 'error_description' => 'code is invalid or used' ), 400 );
			}
			unset( $this->codes[ (string) $form['code'] ] );
			return $this->tokens( true );
		}
		if ( 'refresh_token' === $grant ) {
			$token = (string) ( $form['refresh_token'] ?? '' );
			if ( empty( $this->refreshTokens[ $token ] ) ) {
				return FakeHttpClient::json( array( 'error' => 'invalid_grant', 'error_description' => 'refresh token revoked or expired' ), 400 );
			}
			if ( $this->rotate ) {
				unset( $this->refreshTokens[ $token ] );
			}
			return $this->tokens( $this->rotate, $token );
		}
		if ( in_array( $grant, array( 'client_credentials', 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'password' ), true ) ) {
			return $this->tokens( false );
		}
		return FakeHttpClient::json( array( 'error' => 'unsupported_grant_type' ), 400 );
	}

	/**
	 * A successful answer.
	 *
	 * @param bool        $withRefresh Whether to send a (new) refresh token.
	 * @param string|null $keep        Refresh token to leave valid when none is rotated.
	 */
	private function tokens( bool $withRefresh, ?string $keep = null ): HttpResponse {
		++$this->issued;
		$access                         = 'access-' . $this->issued . '-' . bin2hex( random_bytes( 6 ) );
		$this->accessTokens[ $access ] = true;
		$answer                         = array( 'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $this->lifetime );
		if ( $withRefresh ) {
			$refresh                         = 'refresh-' . $this->issued . '-' . bin2hex( random_bytes( 6 ) );
			$this->refreshTokens[ $refresh ] = true;
			$answer['refresh_token']         = $refresh;
		} elseif ( null !== $keep ) {
			$this->refreshTokens[ $keep ] = true;
		}
		return FakeHttpClient::json( $answer );
	}
}
