<?php
/**
 * Builds and remembers signed-in Odoo clients.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Turns a Connection into an OdooClient: checks the address, detects the version, picks the transport. Clients are kept in
 * memory for a few minutes (a worker run) so that one run does not detect the version and sign in again for every item.
 * The memory key is a hash; the key and the user name are never stored in it.
 */
final class OdooClientFactory {

	/**
	 * Seconds a client is reused.
	 */
	private const CLIENT_TTL = 300;

	/**
	 * Default time between two requests, in milliseconds.
	 */
	public const DEFAULT_INTERVAL_MS = 1000;

	/**
	 * Clients by hash, with the time they were made.
	 *
	 * @var array<string,array{0:int,1:OdooClient}>
	 */
	private array $clients = array();

	/**
	 * Pacing wrappers by server and interval, so the pace carries over from one call to the next.
	 *
	 * @var array<string,PacedHttpClient>
	 */
	private array $paced = array();

	/**
	 * Returns the current time in seconds.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface   $http       The engine's HTTP client.
	 * @param OdooAddressNormalizer $normalizer Address checker.
	 * @param callable|null         $now        Returns the time in seconds; defaults to time().
	 * @param callable|null         $sleep      Receives milliseconds; defaults to usleep. Tests pass a recorder.
	 * @param callable|null         $clock      Returns milliseconds as a float; defaults to microtime.
	 */
	public function __construct(
		private readonly HttpClientInterface $http,
		private readonly OdooAddressNormalizer $normalizer,
		?callable $now = null,
		private $sleep = null,
		private $clock = null,
	) {
		$this->now = $now ?? static fn(): int => time();
	}

	/**
	 * A client for a connection.
	 *
	 * @param Connection $connection Connection with its decrypted key.
	 * @param bool       $paced      False to skip the one-call-a-second pacing (Test connection).
	 * @param bool       $fresh      True to ignore and replace any remembered client.
	 *
	 * @throws OdooException When the address is not allowed, the sign-in data is missing, or the server cannot be used.
	 */
	public function forConnection( Connection $connection, bool $paced = true, bool $fresh = false ): OdooClient {
		$address = $this->normalizer->normalize( (string) $connection->config( 'address', '' ) );
		if ( ! $address instanceof OdooAddress ) {
			$exception = new OdooException( $address );
			throw $exception;
		}

		$database = trim( (string) $connection->config( 'database', '' ) );
		$login    = trim( (string) $connection->config( 'user', '' ) );
		$key      = $connection->secret( 'api_key' );
		$company  = trim( (string) $connection->config( 'company', '' ) );
		$options  = OdooOptions::fromConnection( $connection );
		$errors   = new OdooErrorMapper( array( $key, $login ), $address->isOdooOnline(), $database );

		// The key and the database name travel in request headers, so they must be plain text without spaces or line breaks.
		if ( '' === $key || 1 === preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
			$exception = $errors->signInRefused( 'the API key is missing or has characters a key cannot have' );
			throw $exception;
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,62}$/', $database ) ) {
			$exception = $errors->databaseMissing( $errors->scrub( $database ) );
			throw $exception;
		}

		$interval = $paced ? $options->integer( 'call_interval_ms', self::DEFAULT_INTERVAL_MS, 0, 10000 ) : 0;
		$hash     = hash( 'sha256', implode( "\n", array( $address->baseUrl, $database, $login, hash( 'sha256', $key ), $company, (string) $interval ) ) );
		$now      = (int) call_user_func( $this->now );

		if ( ! $fresh && isset( $this->clients[ $hash ] ) && $now - $this->clients[ $hash ][0] < self::CLIENT_TTL ) {
			return $this->clients[ $hash ][1];
		}

		$http      = $this->pacedClient( $address, $interval );
		$version   = ( new OdooVersionDetector( $http, $errors ) )->detect( $address, $database );
		$transport = $version->usesJson2()
			? new Json2Transport( $http, $errors, $address, $database, $key )
			: new JsonRpcTransport( $http, $errors, $address, $database, $login, $key );
		$client    = new OdooClient( $transport, $version, $address, $errors, $company );

		$this->clients[ $hash ] = array( $now, $client );
		return $client;
	}

	/**
	 * Whether the server at an address answers with a version (the cheap health probe).
	 *
	 * @param Connection $connection Connection.
	 */
	public function responds( Connection $connection ): bool {
		$address = $this->normalizer->normalize( (string) $connection->config( 'address', '' ) );
		if ( ! $address instanceof OdooAddress ) {
			return false;
		}
		$errors = new OdooErrorMapper( array( $connection->secret( 'api_key' ) ), $address->isOdooOnline(), trim( (string) $connection->config( 'database', '' ) ) );
		return ( new OdooVersionDetector( $this->http, $errors ) )->responds( $address, trim( (string) $connection->config( 'database', '' ) ) );
	}

	/**
	 * Forget every remembered client (used when credentials change).
	 */
	public function forget(): void {
		$this->clients = array();
	}

	/**
	 * The HTTP client for a server with pacing.
	 *
	 * @param OdooAddress $address    Server address.
	 * @param int         $intervalMs Time between requests in milliseconds; 0 for none.
	 */
	private function pacedClient( OdooAddress $address, int $intervalMs ): HttpClientInterface {
		if ( $intervalMs <= 0 ) {
			return $this->http;
		}
		$key = $address->baseUrl . '|' . $intervalMs;
		if ( ! isset( $this->paced[ $key ] ) ) {
			$this->paced[ $key ] = new PacedHttpClient( $this->http, $intervalMs, $this->sleep, $this->clock );
		}
		return $this->paced[ $key ];
	}
}
