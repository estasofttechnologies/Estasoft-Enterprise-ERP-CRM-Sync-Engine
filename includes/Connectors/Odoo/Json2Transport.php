<?php
/**
 * Odoo 19 and later: the JSON-2 API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\HttpClientInterface;

/**
 * POST /json/2/{model}/{method} with "Authorization: bearer <API key>" and the optional X-Odoo-Database header.
 * The body is a JSON object with ids, context and the method's named parameters. A 200 answer holds the result;
 * any other status holds an error object with name, message, arguments, context and debug. Each call is its own
 * database transaction, so calls that must be atomic are single Odoo methods (action_confirm, action_post).
 */
final class Json2Transport extends AbstractOdooTransport {

	/**
	 * User id once read.
	 *
	 * @var int|null
	 */
	private ?int $uid = null;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface $http     The only way to reach the network.
	 * @param OdooErrorMapper     $errors   Error mapping.
	 * @param OdooAddress         $address  Server address.
	 * @param string              $database Database name; sent as X-Odoo-Database when not empty.
	 * @param string              $apiKey   API key.
	 */
	public function __construct(
		HttpClientInterface $http,
		OdooErrorMapper $errors,
		OdooAddress $address,
		string $database,
		private readonly string $apiKey,
	) {
		parent::__construct( $http, $errors, $address, $database );
	}

	/**
	 * Id of the protocol.
	 */
	public function id(): string {
		return 'json2';
	}

	/**
	 * The id of the key's user, read with res.users/context_get (the JSON-2 API has no sign-in call).
	 *
	 * @throws OdooException When the key is refused or the server cannot be reached.
	 */
	public function userId(): int {
		if ( null === $this->uid ) {
			$context = $this->callModel( 'res.users', 'context_get' );
			$uid     = is_array( $context ) && isset( $context['uid'] ) ? $context['uid'] : 0;
			if ( ! is_int( $uid ) || $uid < 1 ) {
				$exception = $this->errors->signInRefused( 'the key did not give a user' );
				throw $exception;
			}
			$this->uid = $uid;
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
	 * Create one record (vals_list holds one set of values).
	 *
	 * @param string              $model   Model name.
	 * @param array<string,mixed> $values  Field values.
	 * @param array<string,mixed> $context Context values.
	 *
	 * @throws OdooException On any failure.
	 */
	public function create( string $model, array $values, array $context = array() ): int {
		return $this->createdId( $this->invoke( $model, 'create', null, array( 'vals_list' => array( array() === $values ? new \stdClass() : $values ) ), $context ), $model );
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
		return true === $this->callRecords( $model, 'write', $ids, array( 'vals' => $values ), $context );
	}

	/**
	 * Run one method of one model.
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
		$body = $params;
		if ( null !== $ids && array() !== $ids ) {
			$body['ids'] = $ids;
		}
		if ( array() !== $context ) {
			$body['context'] = $context;
		}

		$headers = array( 'Authorization' => 'bearer ' . $this->apiKey );
		if ( '' !== $this->database ) {
			$headers['X-Odoo-Database'] = $this->database;
		}

		$response                 = $this->post( '/json/2/' . rawurlencode( $model ) . '/' . rawurlencode( $method ), $headers, array() === $body ? new \stdClass() : $body, $model, $method );
		list( $isJson, $decoded ) = $this->decode( $response );

		if ( 200 !== $response->status ) {
			$exception = $this->errors->withDatabase( $this->errors->httpFailure( $response, $isJson && is_array( $decoded ) ? $decoded : null, $model, $method ), $this->database );
			throw $exception;
		}
		if ( ! $isJson ) {
			$exception = $this->errors->unreadable( $response, $model, $method );
			throw $exception;
		}
		return $decoded;
	}
}
