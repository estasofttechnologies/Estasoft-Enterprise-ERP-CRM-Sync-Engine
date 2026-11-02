<?php
/**
 * Everything a connector test needs from the shared base, wired to fakes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

require_once __DIR__ . '/FakeOdooWpFunctions.php';
require_once __DIR__ . '/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportStore;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Connectors\Support\RequestPacer;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;

/**
 * `ApiTestKit::make()` gives a FakeHttpClient, a controllable clock, in-memory token storage and lock, an OAuth token manager, an export store in a
 * temporary folder, a pacer that records its sleeps, and the ConnectorDependencies built from them. Call `cleanup()` in tearDown().
 *
 *     $kit = ApiTestKit::make();
 *     $connector = new XeroConnector( $kit->deps );
 *     $connection = $kit->connection( 'xero', array( 'client_id' => 'abc' ), array( 'client_secret' => 's3cret-value' ) );
 */
final class ApiTestKit {

	/**
	 * Fake network.
	 */
	public FakeHttpClient $http;

	/**
	 * Controllable clock.
	 */
	public CoreFakeClock $clock;

	/**
	 * In-memory connections.
	 */
	public ApiMemoryTokenStorage $storage;

	/**
	 * In-memory refresh lock.
	 */
	public ApiMemoryLock $lock;

	/**
	 * Token manager over the fakes.
	 */
	public OAuthTokenManager $tokens;

	/**
	 * Export store in a temporary folder.
	 */
	public ExportStore $exports;

	/**
	 * Pacer over an in-memory store.
	 */
	public RequestPacer $pacer;

	/**
	 * In-memory rate counters.
	 */
	public CoreArrayRateStore $rates;

	/**
	 * The dependencies a connector receives.
	 */
	public ConnectorDependencies $deps;

	/**
	 * Temporary uploads folder.
	 */
	public string $uploads;

	/**
	 * Microseconds the pacer and the token manager slept, in order.
	 *
	 * @var array<int,int>
	 */
	public array $slept = array();

	/**
	 * Build the kit.
	 *
	 * @param OAuthProvider|null $provider Provider the token manager uses when a caller does not pass one.
	 */
	public static function make( ?OAuthProvider $provider = null ): self {
		$kit          = new self();
		$kit->http    = new FakeHttpClient();
		$kit->clock   = new CoreFakeClock();
		$kit->storage = new ApiMemoryTokenStorage();
		$kit->lock    = new ApiMemoryLock();
		$kit->rates   = new CoreArrayRateStore();
		$kit->uploads = sys_get_temp_dir() . '/estasoft-kit-' . bin2hex( random_bytes( 6 ) );
		mkdir( $kit->uploads, 0777, true );
		$sleep         = function ( int $microseconds ) use ( $kit ): void {
			$kit->slept[] = $microseconds;
			$kit->clock->advanceMilliseconds( (int) round( $microseconds / 1000 ) );
		};
		$kit->tokens   = new OAuthTokenManager( $kit->http, $kit->clock, $kit->storage, $kit->lock, null === $provider ? null : static fn(): OAuthProvider => $provider, $sleep );
		$kit->exports  = new ExportStore( fn(): string => $kit->uploads, $kit->clock );
		$kit->pacer    = new RequestPacer( $kit->rates, $kit->clock, $sleep );
		$kit->deps     = new ConnectorDependencies( $kit->http, $kit->clock, $kit->tokens, $kit->exports, self::silentLogger(), $kit->pacer );
		return $kit;
	}

	/**
	 * A connection saved in the in-memory storage.
	 *
	 * @param string               $connector Connector id.
	 * @param array<string,mixed>  $config    Non-secret fields.
	 * @param array<string,string> $secrets   Secrets.
	 * @param int                  $id        Connection id.
	 * @param ConnectionStatus     $status    Status.
	 */
	public function connection( string $connector, array $config = array(), array $secrets = array(), int $id = 1, ConnectionStatus $status = ConnectionStatus::Active ): Connection {
		return $this->storage->add( $id, $connector, $config, $secrets, $status );
	}

	/**
	 * A connection that is not saved (id 0), as Test connection sees it before the first save.
	 *
	 * @param string               $connector Connector id.
	 * @param array<string,mixed>  $config    Config.
	 * @param array<string,string> $secrets   Secrets.
	 */
	public function unsaved( string $connector, array $config = array(), array $secrets = array() ): Connection {
		return new Connection( 0, $connector, ConnectorType::Accounting, $connector, ConnectionStatus::Active, $config, array(), $secrets );
	}

	/**
	 * A logger that writes nowhere (the real Logger never throws, so an unwired one is silent).
	 */
	public static function silentLogger(): Logger {
		return ( new \ReflectionClass( Logger::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Remove the temporary folder.
	 */
	public function cleanup(): void {
		self::removeDir( $this->uploads );
	}

	/**
	 * Delete a folder tree.
	 *
	 * @param string $dir Folder.
	 */
	public static function removeDir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name || ! is_string( $name ) ) {
				continue;
			}
			is_dir( $dir . '/' . $name ) ? self::removeDir( $dir . '/' . $name ) : unlink( $dir . '/' . $name );
		}
		rmdir( $dir );
	}
}
