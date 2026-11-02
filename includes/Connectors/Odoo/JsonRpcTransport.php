<?php
/**
 * Odoo 17 and 18: JSON-RPC at /jsonrpc.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\HttpClientInterface;

/**
 * Signs in with the user's login and the API key in place of the password (common.authenticate), then calls
 * object.execute_kw with the user id and the key. The endpoint exists in 17.0, 18.0 and 19.0; Odoo marks it deprecated
 * from 19 and plans to remove it in Odoo 22 and Online 21.1, which is why Odoo 19 and later use Json2Transport.
 */
final class JsonRpcTransport extends AbstractOdooTransport {

	/**
	 * User id after the sign-in.
	 *
	 * @var int|null
	 */
	private ?int $uid;

	/**
	 * Counter for the JSON-RPC request id.
	 *
	 * @var int
	 */
	private int $requestId = 0;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface $http     The only way to reach the network.
	 * @param OdooErrorMapper     $errors   Error mapping.
	 * @param OdooAddress         $address  Server address.
	 * @param string              $database Database name.
	 * @param string              $login    Login of the sync user.
	 * @param string              $apiKey   API key, used as the password.
	 * @param int|null            $uid      User id when a sign-in was already done.
	 */
	public function __construct(
		HttpClientInterface $http,
		OdooErrorMapper $errors,
		OdooAddress $address,
		string $database,
		private readonly string $login,
		private readonly string $apiKey,
		?int $uid = null,
	) {
		parent::__construct( $http, $errors, $address, $database );
		$this->uid = $uid;
	}

	/**
	 * Id of the protocol.
	 */
	public function id(): string {
		return 'jsonrpc';
	}

	/**
	 * The user id, signing in on the first call.
	 *
	 * @throws OdooException When the sign-in is refused or the server cannot be reached.
	 */
	public function userId(): int {
		if ( null === $this->uid ) {
			$this->uid = $this->authenticate();
		}
		return $this->uid;
	}

	/**
	 * Read records by id.
	 *
	 * @param string              $model   Model name.
	 * @param array<int,int>      $ids     Record ids.
	 * @param array<int,string>   $fields  Field names.
	 * @param array<string,mixed> $context Context values.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws OdooException On any failure.
	 */
	public function read( string $model, array $ids, array $fields, array $context = array() ): array {
		return $this->records( $this->callRecords( $model, 'read', $ids, array( 'fields' => $fields ), $context ), $model, 'read' );
	}

	/**
	 * Create one record. The values go as the first positional argument, because Odoo's JSON-RPC dispatch of create reads it by position.
	 *
	 * @param string              $model   Model name.
	 * @param array<string,mixed> $values  Field values.
	 * @param array<string,mixed> $context Context values.
	 *
	 * @throws OdooException On any failure.
	 */
	public function create( string $model, array $values, array $context = array() ): int {
		return $this->createdId( $this->executeKw( $model, 'create', array( array( array() === $values ? new \stdClass() : $values ) ), $context ), $model );
	}

	/**
	 * Change records.
	 *
	 * @param string              $model   Model name.
	 * @param array<int,int>      $ids     Record ids.
	 * @param array<string,mixed> $values  Field values.
	 * @param array<string,mixed> $context Context values.
	 *
	 * @throws OdooException On any failure.
	 */
	public function write( string $model, array $ids, array $values, array $context = array() ): bool {
		return true === $this->executeKw( $model, 'write', array( array_values( $ids ), $values ), $context );
	}

	/**
	 * Run one method of one model through execute_kw.
	 *
	 * @param string              $model   Model name.
	 * @param string              $method  Method name.
	 * @param array<int,int>|null $ids     Record ids, or null for a model-level method.
	 * @param array<string,mixed> $params  Named parameters.
	 * @param array<string,mixed> $context Context values.
	 * @return mixed
	 *
	 * @throws OdooException On any failure.
	 */
	protected function invoke( string $model, string $method, ?array $ids, array $params, array $context ): mixed {
		$args = null === $ids ? array() : array( $ids );
		return $this->executeKw( $model, $method, $args, $context, $params );
	}

	/**
	 * Call object.execute_kw.
	 *
	 * @param string              $model   Model name.
	 * @param string              $method  Method name.
	 * @param array<int,mixed>    $args    Positional arguments.
	 * @param array<string,mixed> $context Context values.
	 * @param array<string,mixed> $kwargs  Keyword arguments.
	 * @return mixed
	 *
	 * @throws OdooException On any failure.
	 */
	private function executeKw( string $model, string $method, array $args, array $context, array $kwargs = array() ): mixed {
		if ( array() !== $context ) {
			$kwargs['context'] = $context;
		}
		$result = $this->rpc(
			'object',
			'execute_kw',
			array( $this->database, $this->userId(), $this->apiKey, $model, $method, $args, array() === $kwargs ? new \stdClass() : $kwargs ),
			$model,
			$method
		);
		return $result;
	}

	/**
	 * Sign in with common.authenticate.
	 *
	 * @throws OdooException When the sign-in is refused.
	 */
	private function authenticate(): int {
		if ( '' === $this->login ) {
			$exception = $this->errors->signInRefused( 'the sync user is missing; Odoo 17 and 18 need it with the key' );
			throw $exception;
		}
		$uid = $this->rpc( 'common', 'authenticate', array( $this->database, $this->login, $this->apiKey, new \stdClass() ), 'common', 'authenticate' );
		if ( ! is_int( $uid ) || $uid < 1 ) {
			$exception = $this->errors->signInRefused();
			throw $exception;
		}
		return $uid;
	}

	/**
	 * Send one JSON-RPC call and return its result.
	 *
	 * @param string           $service Service: common or object.
	 * @param string           $method  Service method.
	 * @param array<int,mixed> $args    Arguments.
	 * @param string           $model   Model for error texts.
	 * @param string           $call    Method for error texts.
	 * @return mixed
	 *
	 * @throws OdooException On any failure.
	 */
	private function rpc( string $service, string $method, array $args, string $model, string $call ): mixed {
		++$this->requestId;
		$response = $this->post(
			'/jsonrpc',
			array(),
			array(
				'jsonrpc' => '2.0',
				'method'  => 'call',
				'params'  => array(
					'service' => $service,
					'method'  => $method,
					'args'    => $args,
				),
				'id'      => $this->requestId,
			),
			$model,
			$call
		);

		list( $isJson, $decoded ) = $this->decode( $response );

		if ( 200 !== $response->status ) {
			$exception = $this->errors->withDatabase( $this->errors->httpFailure( $response, $isJson && is_array( $decoded ) ? $decoded : null, $model, $call ), $this->database );
			throw $exception;
		}
		if ( ! $isJson || ! is_array( $decoded ) ) {
			$exception = $this->errors->unreadable( $response, $model, $call );
			throw $exception;
		}
		if ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$exception = $this->errors->withDatabase( $this->errors->rpcFault( $decoded['error'], $response, $model, $call ), $this->database );
			throw $exception;
		}
		return $decoded['result'] ?? null;
	}
}
