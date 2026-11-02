<?php
/**
 * A connector that uses a sign-in page, for the sign-in route tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Rest\OAuth\SignInConnector;

/**
 * Records what the routes pass to it.
 */
final class RestFakeSignInConnector extends RestFakeConnector implements SignInConnector {

	/**
	 * What completeSignIn() answers.
	 *
	 * @var bool|ConnectorError
	 */
	public bool|ConnectorError $completes = true;

	/**
	 * Organisations to offer.
	 *
	 * @var array<int,array{id:string,name:string}>
	 */
	public array $organisations = array(
		array(
			'id'   => 'org-1',
			'name' => 'Cedar & Stone',
		),
	);

	/**
	 * Arguments of the last authorizeUrl() call.
	 *
	 * @var array<string,string>
	 */
	public array $authorized = array();

	/**
	 * Arguments of the last completeSignIn() call.
	 *
	 * @var array<string,string>
	 */
	public array $completed = array();

	/**
	 * The address of the sign-in page.
	 *
	 * @param Connection $connection   Connection.
	 * @param string     $state        State.
	 * @param string     $redirectUri  Callback address.
	 * @param string     $codeChallenge PKCE challenge.
	 */
	public function authorizeUrl( Connection $connection, string $state, string $redirectUri, string $codeChallenge ): string {
		$this->authorized = compact( 'state', 'redirectUri', 'codeChallenge' );
		return 'https://login.example.test/authorize?state=' . rawurlencode( $state );
	}

	/**
	 * Finish the sign-in.
	 *
	 * @param Connection $connection   Connection.
	 * @param string     $code         Code.
	 * @param string     $redirectUri  Callback address.
	 * @param string     $codeVerifier PKCE verifier.
	 */
	public function completeSignIn( Connection $connection, string $code, string $redirectUri, string $codeVerifier ): bool|ConnectorError {
		$this->completed = compact( 'code', 'redirectUri', 'codeVerifier' );
		return $this->completes;
	}

	/**
	 * Organisations.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{id:string,name:string}>
	 */
	public function organisations( Connection $connection ): array|ConnectorError {
		return $this->organisations;
	}
}
