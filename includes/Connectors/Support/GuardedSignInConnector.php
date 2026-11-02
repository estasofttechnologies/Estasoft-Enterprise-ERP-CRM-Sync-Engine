<?php
/**
 * A guarded connector that signs in with OAuth 2.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthSignIn;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Rest\OAuth\SignInConnector;

/**
 * The registry hands this out for an OAuthConnector so the REST layer's `instanceof SignInConnector` check finds it. The sign-in work is done by OAuthSignIn.
 */
final class GuardedSignInConnector extends GuardedConnector implements SignInConnector {

	/**
	 * Sign-in adapter.
	 *
	 * @var OAuthSignIn
	 */
	private OAuthSignIn $signIn;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectorInterface $inner  The connector (must implement OAuthConnector).
	 * @param OAuthTokenManager  $tokens Token manager.
	 *
	 * @throws \InvalidArgumentException When the connector is not an OAuthConnector.
	 */
	public function __construct( ConnectorInterface $inner, OAuthTokenManager $tokens ) {
		if ( ! $inner instanceof OAuthConnector ) {
			throw new \InvalidArgumentException( 'GuardedSignInConnector needs an OAuthConnector.' );
		}
		parent::__construct( $inner );
		$this->signIn = new OAuthSignIn( $inner, $tokens, $inner->label() );
	}

	/**
	 * The vendor's sign-in page.
	 *
	 * @param Connection $connection    Connection.
	 * @param string     $state         State.
	 * @param string     $redirectUri   Redirect address.
	 * @param string     $codeChallenge PKCE challenge.
	 */
	public function authorizeUrl( Connection $connection, string $state, string $redirectUri, string $codeChallenge ): string {
		return $this->signIn->authorizeUrl( $connection, $state, $redirectUri, $codeChallenge );
	}

	/**
	 * Trade the code for tokens.
	 *
	 * @param Connection $connection   Connection.
	 * @param string     $code         Code.
	 * @param string     $redirectUri  Redirect address.
	 * @param string     $codeVerifier PKCE verifier.
	 * @return bool|ConnectorError
	 */
	public function completeSignIn( Connection $connection, string $code, string $redirectUri, string $codeVerifier ): bool|ConnectorError {
		return $this->signIn->completeSignIn( $connection, $code, $redirectUri, $codeVerifier );
	}

	/**
	 * Whether a person has to sign in on the vendor's page for this connection. True unless the connector says otherwise with its own
	 * `requiresSignIn( Connection ): bool` (Salesforce, which can also connect with a signed token and no sign-in page).
	 *
	 * @param Connection $connection Connection (its config says which way the merchant chose).
	 */
	public function requiresSignIn( Connection $connection ): bool {
		return ! method_exists( $this->inner, 'requiresSignIn' ) || (bool) $this->inner->requiresSignIn( $connection );
	}

	/**
	 * Remember the organisation the person chose.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @param string     $id         Id from organisations().
	 * @return array<string,mixed>|ConnectorError Config keys to store.
	 */
	public function selectOrganisation( Connection $connection, string $id ): array|ConnectorError {
		try {
			$inner = $this->inner;
			return $inner instanceof OAuthConnector ? $inner->selectOrganisation( $connection, $id ) : array();
		} catch ( \Throwable $throwable ) {
			return $this->error( $throwable );
		}
	}

	/**
	 * Organisations.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{id:string,name:string}>|ConnectorError
	 */
	public function organisations( Connection $connection ): array|ConnectorError {
		return $this->signIn->organisations( $connection );
	}
}
