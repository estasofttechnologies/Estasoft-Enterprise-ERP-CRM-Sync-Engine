<?php
/**
 * The connect form of each installed connector, for the catalog.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Connections;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Rest\OAuth\SignInConnector;

/**
 * What the connect screen needs to draw a system's form without a second request: the fields, whether the person signs in on the
 * system's page, and the sign-in style. Connectors are built only when the catalog is asked for, and the answer is kept for ten
 * minutes (one entry per connector id, per plugin version and language). Fields never carry a secret default.
 */
final class CatalogForms {

	/**
	 * Seconds the answer is kept.
	 */
	public const TTL = 600;

	/**
	 * Start of the transient name.
	 */
	private const PREFIX = 'estasoft_sync_catalog_forms_';

	/**
	 * Reads the kept answer: a map from connector id to its form, or null.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $load;

	/**
	 * Keeps the answer for TTL seconds.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $save;

	/**
	 * Set up with what it depends on. Without `$load` and `$save` nothing is kept.
	 *
	 * @param \Closure      $registry Returns the connector registry.
	 * @param \Closure|null $load     `fn(): ?array` the kept map.
	 * @param \Closure|null $save     `fn( array $map ): void`.
	 */
	public function __construct( private readonly \Closure $registry, ?\Closure $load = null, ?\Closure $save = null ) {
		$this->load = $load;
		$this->save = $save;
	}

	/**
	 * Keep the answer in a WordPress transient, named by plugin version and language so an update or a language change starts fresh.
	 *
	 * @param \Closure $registry Returns the connector registry.
	 */
	public static function cached( \Closure $registry ): self {
		$name = static function (): string {
			$version = defined( 'ESTASOFT_SYNC_VERSION' ) ? (string) ESTASOFT_SYNC_VERSION : '0';
			return self::PREFIX . md5( $version . '|' . determine_locale() );
		};
		return new self(
			$registry,
			static function () use ( $name ): ?array {
				$kept = get_transient( $name() );
				return is_array( $kept ) ? $kept : null;
			},
			static function ( array $map ) use ( $name ): void {
				set_transient( $name(), $map, self::TTL );
			}
		);
	}

	/**
	 * The form of each connector id asked for. An id that is not installed (or whose connector fails to build) is left out.
	 *
	 * @param array<int,string> $ids Connector ids.
	 * @return array<string,array{credential_fields:array<int,array<string,mixed>>,can_sign_in:bool,auth_style:string}>
	 */
	public function forConnectors( array $ids ): array {
		$ids = array_values( array_unique( array_filter( $ids, static fn( string $id ): bool => '' !== $id ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$kept  = null === $this->load ? null : ( $this->load )();
		$map   = is_array( $kept ) ? $kept : array();
		$added = false;
		foreach ( $ids as $id ) {
			if ( isset( $map[ $id ] ) && is_array( $map[ $id ] ) ) {
				continue;
			}
			$connector = $this->connector( $id );
			if ( null === $connector ) {
				continue;
			}
			try {
				$map[ $id ] = self::describe( $connector );
			} catch ( \Throwable $throwable ) {
				unset( $throwable ); // One broken connector must not take the catalog down.
				continue;
			}
			$added = true;
		}
		if ( $added && null !== $this->save ) {
			( $this->save )( $map );
		}
		return array_intersect_key( $map, array_flip( $ids ) );
	}

	/**
	 * How a system is shown when no connector gives its style: from the connection method in the catalog row.
	 *
	 * @param string $method key, own_app, cloud_app, server_login, export_file, none or unknown.
	 */
	public static function styleFromMethod( string $method ): string {
		return match ( $method ) {
			'key'          => AuthStyle::Key->value,
			'own_app'      => AuthStyle::OAuthAuthorizationCode->value,
			'cloud_app'    => AuthStyle::OAuthClientCredentials->value,
			'server_login' => AuthStyle::ServerLogin->value,
			'export_file'  => AuthStyle::ExportFile->value,
			default        => AuthStyle::None->value,
		};
	}

	/**
	 * One connector's form.
	 *
	 * @param ConnectorInterface $connector Connector (wrapped or not).
	 * @return array{credential_fields:array<int,array<string,mixed>>,can_sign_in:bool,auth_style:string}
	 */
	public static function describe( ConnectorInterface $connector ): array {
		$real  = $connector instanceof GuardedConnector ? $connector->inner() : $connector;
		$style = $real instanceof AbstractApiConnector ? $real->authStyle()->value : '';
		return array(
			'credential_fields' => ConnectionPresenter::definitions( $connector->credentialFields() ),
			'can_sign_in'       => $connector instanceof SignInConnector,
			'auth_style'        => $style,
		);
	}

	/**
	 * The connector, or null when it is not installed or cannot be built.
	 *
	 * @param string $id Connector id.
	 */
	private function connector( string $id ): ?ConnectorInterface {
		$registry = ( $this->registry )();
		if ( ! $registry->has( $id ) ) {
			return null;
		}
		try {
			return $registry->get( $id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return null;
		}
	}
}
