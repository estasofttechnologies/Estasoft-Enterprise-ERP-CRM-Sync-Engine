<?php
/**
 * A small CRM connector built on the shared base: the model for a sign-in (OAuth 2) connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\OAuth2Auth;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\CredentialFields;
use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Core\Support\Money;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The merchant registers an app with the vendor and types its client id and secret; the plugin never holds a secret of its own. The tenant is chosen after sign-in
 * (selectOrganisation) and sent on every call. The connector is its own OAuthProvider.
 */
class ReferenceCrmConnector extends AbstractApiConnector implements OAuthConnector, OAuthProvider {

	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'reference-crm',
			label: 'Acme CRM',
			type: ConnectorType::Crm,
			capabilities: array( Capability::Orders, Capability::Customers ),
			entities: array( EntityType::Order, EntityType::Customer ),
			auth: AuthStyle::OAuthAuthorizationCode,
			rateLimit: new RateLimitHints( requestsPerSecond: 10.0 ),
			hosts: array( 'api.acme-crm.test', 'login.acme-crm.test' ),
		);
	}

	public function oauthProvider(): OAuthProvider {
		return $this;
	}

	public function config( Connection $connection ): OAuthConfig {
		return new OAuthConfig(
			authorizeUrl: 'https://login.acme-crm.test/authorize',
			tokenUrl: 'https://login.acme-crm.test/oauth/token',
			scopes: array( 'deals.write', 'contacts.read', 'offline_access' ),
			extraAuthorizeParams: array( 'access_type' => 'offline' ),
			refreshTokenLifetimeDays: 60,
			allowedHosts: array( 'login.acme-crm.test' ),
		);
	}

	protected function baseUrl( Connection $connection ): string {
		return 'https://api.acme-crm.test/v1';
	}

	protected function authStrategy( Connection $connection ): AuthStrategy {
		return new OAuth2Auth( $this->oauthProvider() );
	}

	protected function defaultHeaders( Connection $connection ): array {
		return array( 'X-Tenant' => (string) $connection->config( 'tenant_id', '' ) );
	}

	protected function credentialDefinition(): array {
		return array( CredentialFields::clientId(), CredentialFields::clientSecret() );
	}

	public function organisations( Connection $connection ): array {
		$list = array();
		foreach ( $this->api( $connection )->get( '/connections' )->json()['items'] ?? array() as $tenant ) {
			$list[] = array( 'id' => (string) $tenant['id'], 'name' => (string) $tenant['name'] );
		}
		return $list;
	}

	public function selectOrganisation( Connection $connection, string $id ): array {
		return array( 'tenant_id' => $id );
	}

	protected function identify( Connection $connection ): TestResult {
		$me = $this->api( $connection )->get( '/me' )->json();
		return new TestResult( true, 'Acme CRM ' . $me['version'], (string) $me['org'], array(), array( 'write_deals' => in_array( 'deals.write', (array) $me['scopes'], true ) ) );
	}

	protected function fieldCatalog( string $mappingType ): array {
		return 'orders' === $mappingType
			? FieldCatalogBuilder::make()->group( 'Deal' )->text( 'deal.external_id', 'External id', true )->text( 'deal.title', 'Title', true )->money( 'deal.amount', 'Amount', true )->build()
			: array();
	}

	protected function mappings( string $mappingType ): array {
		return 'orders' === $mappingType
			? array(
				array( 'id' => 'ext', 'target' => 'deal.external_id', 'source' => 'order.client_ref', 'required' => true ),
				array( 'id' => 'title', 'target' => 'deal.title', 'source' => 'order.number', 'required' => true ),
				array( 'id' => 'amount', 'target' => 'deal.amount', 'source' => 'order.total', 'required' => true ),
			)
			: array();
	}

	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		$deal = $this->api( $connection )->get( '/deals', array( 'external_id' => $item->clientReference() ) )->json()['items'][0] ?? null;
		return null === $deal ? null : new ExternalRef( (string) $deal['id'], (string) $deal['title'] );
	}

	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		$found = $this->findExisting( $connection, $item );
		if ( $found instanceof ExternalRef ) {
			return SendResult::existing( $found );
		}
		$deal   = (array) $payload['deal'];
		$result = $this->api( $connection )->post( '/deals', array(), array( 'external_id' => $item->clientReference(), 'title' => (string) $deal['title'], 'amount' => Money::toDecimalString( (int) $deal['amount'], 'AED' ) ) )->json();
		return SendResult::created( new ExternalRef( (string) $result['id'], (string) $result['title'] ) );
	}
}
