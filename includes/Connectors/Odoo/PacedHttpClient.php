<?php
/**
 * Keeps requests to one server about a second apart.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Odoo's acceptable use policy asks outside programs for about one call a second and none at the same time. This wrapper
 * waits until the interval since the previous request has passed. Requests are never made in parallel by the connector.
 * The wait is bounded, and a zero interval turns pacing off (used by Test connection, which is a short burst a person asked for).
 */
final class PacedHttpClient implements HttpClientInterface {

	/**
	 * The longest single wait, in milliseconds.
	 */
	private const MAX_WAIT_MS = 5000;

	/**
	 * Time of the previous request in milliseconds, or null before the first one.
	 *
	 * @var float|null
	 */
	private ?float $last = null;

	/**
	 * Sleeps for a number of milliseconds.
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Returns the current time in milliseconds.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface $inner      The real client.
	 * @param int                 $intervalMs Smallest time between two request starts; 0 means no pacing.
	 * @param callable|null       $sleep      Receives milliseconds; defaults to usleep.
	 * @param callable|null       $clock      Returns milliseconds as a float; defaults to microtime.
	 */
	public function __construct( private readonly HttpClientInterface $inner, private readonly int $intervalMs, ?callable $sleep = null, ?callable $clock = null ) {
		$this->sleep = $sleep ?? static function ( int $milliseconds ): void {
			usleep( $milliseconds * 1000 );
		};
		$this->clock = $clock ?? static fn(): float => microtime( true ) * 1000;
	}

	/**
	 * Wait if the previous request was too recent, then send.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Headers.
	 * @param string|null          $body    Raw body.
	 * @param int                  $timeout Seconds.
	 */
	public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 30 ): HttpResponse {
		if ( $this->intervalMs > 0 && null !== $this->last ) {
			$wait = (int) ceil( $this->last + $this->intervalMs - (float) call_user_func( $this->clock ) );
			if ( $wait > 0 ) {
				call_user_func( $this->sleep, min( $wait, self::MAX_WAIT_MS ) );
			}
		}
		$response   = $this->inner->request( $method, $url, $headers, $body, $timeout );
		$this->last = (float) call_user_func( $this->clock );
		return $response;
	}
}
