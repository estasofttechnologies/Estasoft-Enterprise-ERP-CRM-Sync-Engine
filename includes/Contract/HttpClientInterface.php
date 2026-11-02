<?php
/**
 * Outgoing HTTP, so connectors can be tested without WordPress.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * The only way a connector talks to the network. Production uses WpHttpClient; tests use a fake.
 */
interface HttpClientInterface {

	/**
	 * Send a request. Never throws for HTTP errors; transport failures come back in the response.
	 *
	 * @param string               $method  GET, POST, PATCH, PUT or DELETE.
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Raw body.
	 * @param int                  $timeout Seconds (30 by default; the engine never allows more).
	 */
	public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 30 ): HttpResponse;
}
