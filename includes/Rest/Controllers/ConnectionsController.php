<?php
/**
 * Routes: connections.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Connections\ConnectionService;
use Estasoft\SyncEngine\Jobs\Inbound\InboundHandler;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * List, read, create, update, test and disconnect. Secrets are write-only: no route returns one. The one exception to "nothing
 * secret in an answer" is the signing secret of the inbound route, which is shown once when the person switches it on and is
 * never stored by this layer (that answer is not kept for an Idempotency-Key either).
 */
final class ConnectionsController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$connector = array(
			'type'      => 'string',
			'pattern'   => '^[a-z0-9_\-]+$',
			'maxLength' => 64,
		);
		$input     = array(
			'label'    => self::lineArg( 191 ),
			'config'   => array(
				'type'              => 'object',
				'sanitize_callback' => array( Sanitize::class, 'textMap' ),
			),
			'secrets'  => array(
				'type'              => 'object',
				'sanitize_callback' => array( Sanitize::class, 'secretMap' ),
			),
			'settings' => self::structureArg( 'object' ),
		);
		$required  = array( 'connector' => array_merge( $connector, array( 'required' => true ) ) );

		$this->add( '/connections', 'GET', self::MANAGE, $this->index( ... ) );
		$this->add( '/connections', 'POST', self::CONFIGURE, $this->create( ... ), array_merge( $required, $input ) );
		$this->add(
			'/connections/test',
			'POST',
			self::CONFIGURE,
			$this->testInput( ... ),
			array_merge( $required, array( 'connection_id' => self::connectionArg() ), $input ),
			false
		);
		$this->add( '/connections/(?P<id>\d+)', 'GET', self::MANAGE, $this->show( ... ), array( 'id' => self::idArg() ) );
		$this->add(
			'/connections/(?P<id>\d+)',
			'POST',
			self::CONFIGURE,
			$this->update( ... ),
			array_merge( array( 'id' => self::idArg(), 'connector' => $connector ), $input )
		);
		$this->add( '/connections/(?P<id>\d+)', 'DELETE', self::CONFIGURE, $this->disconnect( ... ), array( 'id' => self::idArg() ) );
		$this->add( '/connections/(?P<id>\d+)/test', 'POST', self::CONFIGURE, $this->test( ... ), array( 'id' => self::idArg() ), false );
		$this->add(
			'/connections/(?P<id>\d+)/inbound',
			'POST',
			self::CONFIGURE,
			$this->inbound( ... ),
			array(
				'id'      => self::idArg(),
				'enabled' => array(
					'type'     => 'boolean',
					'required' => true,
				),
			),
			false
		);
	}

	/**
	 * `GET /connections`: every connection as a `ConnectionSummary`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function index(): array {
		/** @var ConnectionService $connections */
		$connections = $this->service( JobServices::CONNECTIONS );
		return $connections->summaries();
	}

	/**
	 * `GET /connections/{id}`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function show( \WP_REST_Request $request ): array {
		/** @var ConnectionService $connections */
		$connections = $this->service( JobServices::CONNECTIONS );
		return $connections->get( (int) $request['id'] );
	}

	/**
	 * `POST /connections`: test first, save only when the test passes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var ConnectionService $connections */
		$connections = $this->service( JobServices::CONNECTIONS );
		return new \WP_REST_Response( $connections->create( $this->connectionInput( $request ) ), 201 );
	}

	/**
	 * `POST /connections/{id}`: change the label, the fields, the secrets (an empty value keeps the saved one) or the limits.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function update( \WP_REST_Request $request ): array {
		/** @var ConnectionService $connections */
		$connections = $this->service( JobServices::CONNECTIONS );
		return $connections->update( (int) $request['id'], $this->connectionInput( $request ) );
	}

	/**
	 * `DELETE /connections/{id}`: wipe the secrets, keep the history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{disconnected:true}
	 */
	public function disconnect( \WP_REST_Request $request ): array {
		/** @var ConnectionService $connections */
		$connections = $this->service( JobServices::CONNECTIONS );
		$connections->disconnect( (int) $request['id'] );
		return array( 'disconnected' => true );
	}

	/**
	 * `POST /connections/{id}/test`: test the saved connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function test( \WP_REST_Request $request ): array {
		/** @var ConnectionService $connections */
		$connections = $this->service( JobServices::CONNECTIONS );
		return $this->testResult( $connections->test( array(), (int) $request['id'] ) );
	}

	/**
	 * `POST /connections/test`: test typed values before saving. With `connection_id`, secrets left empty are taken from the saved connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function testInput( \WP_REST_Request $request ): array {
		/** @var ConnectionService $connections */
		$connections = $this->service( JobServices::CONNECTIONS );
		$existing    = $request->get_param( 'connection_id' );
		return $this->testResult( $connections->test( $this->connectionInput( $request ), is_numeric( $existing ) ? (int) $existing : null ) );
	}

	/**
	 * The `ConnectionTestResult` of types.ts. The service also returns what the connector detected (its own free-form facts, which
	 * are kept in the saved connection); that is not part of the contract and may hold values a connector chose, so it is left out.
	 *
	 * @param array<string,mixed> $result The service's answer.
	 * @return array<string,mixed>
	 */
	private function testResult( array $result ): array {
		unset( $result['detected'] );
		return $result;
	}

	/**
	 * `POST /connections/{id}/inbound`: switch signed hints from the system on or off. Turning them on returns the secret once.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{enabled:bool,secret?:string,url?:string}
	 */
	public function inbound( \WP_REST_Request $request ): array {
		/** @var InboundHandler $inbound */
		$inbound = $this->service( JobServices::INBOUND );
		$id      = (int) $request['id'];
		if ( ! rest_sanitize_boolean( $request->get_param( 'enabled' ) ) ) {
			$inbound->disable( $id );
			return array( 'enabled' => false );
		}
		return array(
			'enabled' => true,
			'secret'  => $inbound->enable( $id ),
			'url'     => rest_url( self::REST_NAMESPACE . '/inbound/' . $id ),
		);
	}

	/**
	 * The `ConnectionInput` of types.ts from the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException 422 when a field has the wrong type.
	 */
	private function connectionInput( \WP_REST_Request $request ): array {
		$input = array();
		foreach ( array( 'connector', 'label' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = (string) $request->get_param( $key );
			}
		}
		foreach ( array( 'config', 'secrets', 'settings' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null === $value ) {
				continue;
			}
			if ( ! is_array( $value ) ) {
				throw ApiException::invalid( __( 'Some fields need attention.', 'estasoft-erp-crm-sync-engine' ), array( $key => __( 'This must be a list of fields.', 'estasoft-erp-crm-sync-engine' ) ) );
			}
			$input[ $key ] = $value;
		}
		return $input;
	}
}
