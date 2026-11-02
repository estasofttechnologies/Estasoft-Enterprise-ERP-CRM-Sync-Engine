<?php
/**
 * Finds out which Odoo version answers at an address.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;

/**
 * Asks GET /web/version first (Odoo 19 and later answer it; it needs no sign-in). Older versions have no such route, so the
 * detector then asks the common service of /jsonrpc for its version, which also needs no sign-in. The database service is never called.
 */
final class OdooVersionDetector {

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface $http   The HTTP client (already paced).
	 * @param OdooErrorMapper     $errors Error mapping.
	 */
	public function __construct( private readonly HttpClientInterface $http, private readonly OdooErrorMapper $errors ) {
	}

	/**
	 * Detect the version.
	 *
	 * @param OdooAddress $address  Server address.
	 * @param string      $database Database name, sent as X-Odoo-Database when not empty.
	 * @return OdooVersion A supported version.
	 *
	 * @throws OdooException When the server cannot be reached, is not Odoo, or runs an unsupported version.
	 */
	public function detect( OdooAddress $address, string $database ): OdooVersion {
		$version = $this->fromWebVersion( $address, $database ) ?? $this->fromCommonService( $address );
		if ( ! $version->isSupported() ) {
			$exception = $this->errors->unsupportedVersion( $version );
			throw $exception;
		}
		return $version;
	}

	/**
	 * Cheap health probe: does anything answer with a version?
	 *
	 * @param OdooAddress $address  Server address.
	 * @param string      $database Database name.
	 */
	public function responds( OdooAddress $address, string $database ): bool {
		try {
			$this->detect( $address, $database );
			return true;
		} catch ( OdooException $exception ) {
			// An old version still answers; only a failure to answer counts as down.
			return \Estasoft\SyncEngine\Domain\ErrorCode::Val002 === $exception->error->code && str_starts_with( $exception->error->detail, 'unsupported version' );
		}
	}

	/**
	 * GET /web/version.
	 *
	 * @param OdooAddress $address  Server address.
	 * @param string      $database Database name.
	 * @return OdooVersion|null Null when the route does not exist (Odoo 17 and 18).
	 *
	 * @throws OdooException When the request did not complete or Odoo asked us to slow down.
	 */
	private function fromWebVersion( OdooAddress $address, string $database ): ?OdooVersion {
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $database ) {
			$headers['X-Odoo-Database'] = $database;
		}
		$response = $this->http->request( 'GET', $address->url( '/web/version' ), $headers, null, 30 );
		$this->assertCompleted( $response, 'web', 'version' );
		if ( 200 !== $response->status ) {
			return null;
		}
		$answer = $response->json();
		return is_array( $answer ) ? OdooVersion::fromAnswer( $answer ) : null;
	}

	/**
	 * POST /jsonrpc with common.version.
	 *
	 * @param OdooAddress $address Server address.
	 * @return OdooVersion
	 *
	 * @throws OdooException When no version can be read.
	 */
	private function fromCommonService( OdooAddress $address ): OdooVersion {
		$body     = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'call',
				'params'  => array(
					'service' => 'common',
					'method'  => 'version',
					'args'    => array(),
				),
				'id'      => 1,
			)
		);
		$response = $this->http->request(
			'POST',
			$address->url( '/jsonrpc' ),
			array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Accept'       => 'application/json',
			),
			is_string( $body ) ? $body : '{}',
			30
		);
		$this->assertCompleted( $response, 'common', 'version' );

		$answer = 200 === $response->status ? $response->json() : null;
		if ( is_array( $answer ) && isset( $answer['result'] ) && is_array( $answer['result'] ) ) {
			$version = OdooVersion::fromAnswer( $answer['result'] );
			if ( null !== $version ) {
				return $version;
			}
		}
		if ( is_array( $answer ) && isset( $answer['error'] ) && is_array( $answer['error'] ) ) {
			$exception = $this->errors->rpcFault( $answer['error'], $response, 'common', 'version' );
			throw $exception;
		}
		$exception = $this->errors->httpFailure( 200 === $response->status ? new HttpResponse( 404, $response->body ) : $response, null, 'common', 'version' );
		throw $exception;
	}

	/**
	 * Throw for a request that never completed and for a 429.
	 *
	 * @param HttpResponse $response Response.
	 * @param string       $model    Pseudo model for error texts.
	 * @param string       $method   Pseudo method for error texts.
	 *
	 * @throws OdooException On a transport failure or a rate limit.
	 */
	private function assertCompleted( HttpResponse $response, string $model, string $method ): void {
		if ( 0 === $response->status ) {
			$exception = $this->errors->transportFailure( $response, $model, $method );
			throw $exception;
		}
		if ( 429 === $response->status ) {
			$exception = $this->errors->rateLimited( $response, $model, $method );
			throw $exception;
		}
	}
}
