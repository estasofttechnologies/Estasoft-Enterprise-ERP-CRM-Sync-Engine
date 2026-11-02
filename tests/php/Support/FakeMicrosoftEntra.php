<?php
/**
 * A fake Microsoft Entra token endpoint for connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Answers `POST https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` for the client credentials grant, the way the Microsoft
 * identity platform documents it: a form body with client_id, client_secret, scope and grant_type, an answer with token_type, expires_in
 * and access_token, and `{"error","error_description"}` with an AADSTS code when the app is refused.
 */
final class FakeMicrosoftEntra {

	/**
	 * Tokens handed out, in order.
	 *
	 * @var array<int,string>
	 */
	public array $issued = array();

	/**
	 * Token requests made, as decoded form fields plus the tenant.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $requests = array();

	/**
	 * Failure for the next token request: '', 'invalid_secret', 'expired_secret', 'unknown_tenant', 'unknown_app', 'server_error'.
	 *
	 * @var string
	 */
	public string $failNext = '';

	/**
	 * Seconds a token lives.
	 *
	 * @var int
	 */
	public int $lifetime = 3599;

	/**
	 * @param string $tenant   Tenant GUID the fake knows.
	 * @param string $clientId Client id.
	 * @param string $secret   Client secret.
	 * @param string $resource Resource the tokens are for, for example https://api.businesscentral.dynamics.com.
	 */
	public function __construct( private string $tenant, private string $clientId, private string $secret, private string $resource ) {
	}

	/**
	 * Whether a request is for the token endpoint.
	 *
	 * @param string $url URL.
	 */
	public function handles( string $url ): bool {
		return str_starts_with( $url, 'https://login.microsoftonline.com/' );
	}

	/**
	 * Answer a token request.
	 *
	 * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int} $request Request.
	 */
	public function answer( array $request ): HttpResponse {
		$fields = array();
		parse_str( (string) $request['body'], $fields );
		$fields['tenant']  = (string) preg_replace( '#^https://login\.microsoftonline\.com/([^/]+)/oauth2/v2\.0/token$#', '$1', $request['url'] );
		$this->requests[] = array_map( 'strval', $fields );
		$fail             = $this->failNext;
		$this->failNext   = '';
		if ( 'server_error' === $fail ) {
			return FakeHttpClient::status( 503, '{"error":"temporarily_unavailable"}' );
		}
		if ( 'unknown_tenant' === $fail || $fields['tenant'] !== $this->tenant ) {
			return FakeHttpClient::json( array(
				'error'             => 'invalid_request',
				'error_description' => 'AADSTS90002: Tenant not found.',
				'error_codes'       => array( 90002 ),
			), 400 );
		}
		if ( 'expired_secret' === $fail ) {
			return FakeHttpClient::json( array(
				'error'             => 'invalid_client',
				'error_description' => 'AADSTS7000222: The provided client secret keys for app are expired. Visit the Azure portal to create new keys.',
				'error_codes'       => array( 7000222 ),
			), 401 );
		}
		if ( 'invalid_secret' === $fail || ( $fields['client_secret'] ?? '' ) !== $this->secret ) {
			return FakeHttpClient::json( array(
				'error'             => 'invalid_client',
				'error_description' => 'AADSTS7000215: Invalid client secret provided. Ensure the secret being sent in the request is the client secret value.',
				'error_codes'       => array( 7000215 ),
			), 401 );
		}
		if ( 'unknown_app' === $fail || ( $fields['client_id'] ?? '' ) !== $this->clientId || ( $fields['grant_type'] ?? '' ) !== 'client_credentials' || ( $fields['scope'] ?? '' ) !== $this->resource . '/.default' ) {
			return FakeHttpClient::json( array(
				'error'             => 'invalid_client',
				'error_description' => 'AADSTS700016: Application with identifier was not found in the directory.',
				'error_codes'       => array( 700016 ),
			), 401 );
		}
		$token          = 'tok-' . bin2hex( random_bytes( 8 ) ) . '-' . ( count( $this->issued ) + 1 );
		$this->issued[] = $token;
		return FakeHttpClient::json( array(
			'token_type'     => 'Bearer',
			'expires_in'     => $this->lifetime,
			'ext_expires_in' => $this->lifetime,
			'access_token'   => $token,
		) );
	}

	/**
	 * Whether a token was issued by this fake.
	 *
	 * @param string $token Token.
	 */
	public function accepts( string $token ): bool {
		return in_array( $token, $this->issued, true );
	}

	/**
	 * Forget every token, so the next API call is refused with 401 until a new one is fetched.
	 */
	public function revokeAll(): void {
		$this->issued = array();
	}
}
