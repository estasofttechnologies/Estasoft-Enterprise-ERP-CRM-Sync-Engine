<?php
/**
 * The OAuth 2 settings of one connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\Connection;

/**
 * Where to sign in and where to get tokens, how the app proves itself, and what to ask for. A provider builds one per connection, so
 * data-centre or tenant specific addresses can come from the connection's config.
 */
final class OAuthConfig {

	/**
	 * Grants the token manager can run.
	 */
	public const GRANTS = array( 'authorization_code', 'client_credentials', 'jwt_bearer', 'password' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param string               $authorizeUrl             The vendor's sign-in page (authorization_code only; empty for the other grants).
	 * @param string               $tokenUrl                 The vendor's token endpoint.
	 * @param array<int,string>    $scopes                   Scopes to ask for.
	 * @param string               $grant                    authorization_code, client_credentials, jwt_bearer or password.
	 * @param bool                 $usePkce                  Send a PKCE S256 challenge (authorization_code).
	 * @param string               $clientAuth               `basic` sends the client id and secret in a Basic header, `body` in the form body.
	 * @param array<string,string> $extraAuthorizeParams     Added to the sign-in address (access_type=offline, prompt=consent).
	 * @param array<string,string> $extraTokenParams         Added to every token request.
	 * @param string|null          $resource                 `resource` parameter, for vendors that want one.
	 * @param string|null          $audience                 `audience` parameter (token request and, for the JWT grant, the `aud` claim when jwtAudience is empty).
	 * @param int|null             $refreshTokenLifetimeDays How long a refresh token lives, for the expiry warning (Xero 60, QuickBooks 100); null when unknown.
	 * @param int                  $accessTokenFallbackSeconds Lifetime assumed when the answer has no expires_in.
	 * @param string               $scopeSeparator           Separator of the scope list in requests (space; a comma for a few vendors).
	 * @param string               $clientIdKey              Name of the client id on the connection (config first, then secrets).
	 * @param string               $clientSecretKey          Name of the client secret on the connection (secrets).
	 * @param string               $privateKeyKey            Name of the private key secret (jwt_bearer).
	 * @param string|null          $jwtIssuer                `iss` claim; null uses the client id.
	 * @param string|null          $jwtSubject               `sub` claim; null uses the client id.
	 * @param string|null          $jwtAudience              `aud` claim; null uses the audience, then the token URL.
	 * @param array<string,mixed>  $jwtClaims                More claims.
	 * @param array<string,mixed>  $jwtHeaders               More header fields (`kid` for NetSuite).
	 * @param int                  $jwtLifetimeSeconds       Lifetime of the assertion.
	 * @param string               $jwtAlgorithm             Signing algorithm: RS256 (default), RS384, RS512, PS256, PS384, PS512, ES256, ES384 or ES512.
	 * @param string               $jwtMode                  `assertion` sends grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=...; `client_assertion` sends grant_type=client_credentials with client_assertion (NetSuite).
	 * @param string               $usernameKey              Name of the user on the connection (password grant).
	 * @param string               $passwordKey              Name of the password secret (password grant).
	 * @param array<int,string>    $allowedHosts             Hosts the sign-in and token addresses must have (a leading `*.` matches subdomains). Empty allows nothing unless `merchantHost` is true.
	 * @param bool                 $refreshOnDemand          True for a vendor whose access tokens live only minutes (Sage: 5): the scheduled task leaves the token to the next call and only keeps the refresh token from running out.
	 * @param bool                 $merchantHost             True when the merchant types the host of the sign-in and token addresses (a server of their own). The host is then looked up and every address must be public. Use `allowedHosts` for a vendor's own hosts.
	 */
	public function __construct(
		public readonly string $authorizeUrl,
		public readonly string $tokenUrl,
		public readonly array $scopes = array(),
		public readonly string $grant = 'authorization_code',
		public readonly bool $usePkce = true,
		public readonly string $clientAuth = 'basic',
		public readonly array $extraAuthorizeParams = array(),
		public readonly array $extraTokenParams = array(),
		public readonly ?string $resource = null, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- the name is part of the connector contract.
		public readonly ?string $audience = null,
		public readonly ?int $refreshTokenLifetimeDays = null,
		public readonly int $accessTokenFallbackSeconds = 3600,
		public readonly string $scopeSeparator = ' ',
		public readonly string $clientIdKey = 'client_id',
		public readonly string $clientSecretKey = 'client_secret',
		public readonly string $privateKeyKey = 'private_key',
		public readonly ?string $jwtIssuer = null,
		public readonly ?string $jwtSubject = null,
		public readonly ?string $jwtAudience = null,
		public readonly array $jwtClaims = array(),
		public readonly array $jwtHeaders = array(),
		public readonly int $jwtLifetimeSeconds = 300,
		public readonly string $jwtAlgorithm = 'RS256',
		public readonly string $jwtMode = 'assertion',
		public readonly string $usernameKey = 'username',
		public readonly string $passwordKey = 'password',
		public readonly array $allowedHosts = array(),
		public readonly bool $refreshOnDemand = false,
		public readonly bool $merchantHost = false,
	) {
	}

	/**
	 * The client id of the merchant's app: from the connection's config, else from its secrets.
	 *
	 * @param Connection $connection Connection.
	 */
	public function clientId( Connection $connection ): string {
		$value = $connection->config( $this->clientIdKey );
		return is_string( $value ) && '' !== $value ? $value : $connection->secret( $this->clientIdKey );
	}

	/**
	 * Whether this grant keeps a refresh token that the vendor rotates or expires.
	 */
	public function usesRefreshToken(): bool {
		return 'authorization_code' === $this->grant;
	}

	/**
	 * The scopes as one request value.
	 */
	public function scopeString(): string {
		return implode( $this->scopeSeparator, $this->scopes );
	}
}
