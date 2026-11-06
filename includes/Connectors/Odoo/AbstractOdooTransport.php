<?php
/**
 * Shared request handling for the Odoo transports.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Sends one POST with a JSON body through the engine's HTTP client and decodes the answer. Odoo is asked for one
 * thing at a time (the HTTP client and the pacing around it keep to about one call a second), with the 30 second limit.
 */
abstract class AbstractOdooTransport implements OdooTransport {

	/**
	 * Seconds one request may take.
	 */
	protected const TIMEOUT = 30;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface $http     The only way to reach the network.
	 * @param OdooErrorMapper     $errors   Error mapping that removes secrets from texts.
	 * @param OdooAddress         $address  Checked server address.
	 * @param string              $database Database name.
	 */
	public function __construct(
		protected readonly HttpClientInterface $http,
		protected readonly OdooErrorMapper $errors,
		protected readonly OdooAddress $address,
		protected readonly string $database,
	) {
	}

	/**
	 * Search and read in one transaction.
	 *
	 * @param string                                      $model   Model name such as sale.order.
	 * @param array<int,mixed>                            $domain  Odoo domain.
	 * @param array<int,string>                           $fields  Field names to read.
	 * @param array{limit?:int,offset?:int,order?:string} $options Paging and order.
	 * @param array<string,mixed>                         $context Context values.
	 * @return array<int,array<string,mixed>> Records.
	 *
	 * @throws OdooException On any failure.
	 */
	public function searchRead( string $model, array $domain, array $fields, array $options = array(), array $context = array() ): array {
		$params = array(
			'domain' => $domain,
			'fields' => $fields,
		);
		foreach ( array( 'limit', 'offset', 'order' ) as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$params[ $key ] = $options[ $key ];
			}
		}
		$result = $this->callModel( $model, 'search_read', $params, $context );
		return $this->records( $result, $model, 'search_read' );
	}

	/**
	 * Call a model-level method.
	 *
	 * @param string              $model   Model name.
	 * @param string              $method  Method name.
	 * @param array<string,mixed> $params  Named parameters.
	 * @param array<string,mixed> $context Context values.
	 * @return mixed Decoded result.
	 *
	 * @throws OdooException On any failure.
	 */
	public function callModel( string $model, string $method, array $params = array(), array $context = array() ): mixed {
		return $this->invoke( $model, $method, null, $params, $context );
	}

	/**
	 * Call a method on records.
	 *
	 * @param string              $model   Model name.
	 * @param string              $method  Method name.
	 * @param array<int,int>      $ids     Record ids.
	 * @param array<string,mixed> $params  Named parameters.
	 * @param array<string,mixed> $context Context values.
	 * @return mixed Decoded result.
	 *
	 * @throws OdooException On any failure.
	 */
	public function callRecords( string $model, string $method, array $ids, array $params = array(), array $context = array() ): mixed {
		return $this->invoke( $model, $method, array_values( $ids ), $params, $context );
	}

	/**
	 * Run one method of one model.
	 *
	 * @param string              $model   Model name.
	 * @param string              $method  Method name.
	 * @param array<int,int>|null $ids     Record ids, or null for a model-level method.
	 * @param array<string,mixed> $params  Named parameters.
	 * @param array<string,mixed> $context Context values.
	 * @return mixed Decoded result.
	 *
	 * @throws OdooException On any failure.
	 */
	abstract protected function invoke( string $model, string $method, ?array $ids, array $params, array $context ): mixed;

	/**
	 * POST a JSON body and return the response when the request completed.
	 *
	 * @param string               $path    Path below the server root.
	 * @param array<string,string> $headers Extra headers.
	 * @param array<mixed>|object  $body    Value to encode as the JSON body.
	 * @param string               $model   Model of the call, for error texts.
	 * @param string               $method  Method of the call, for error texts.
	 *
	 * @throws OdooException When the request did not complete.
	 */
	protected function post( string $path, array $headers, array|object $body, string $model, string $method ): HttpResponse {
		$headers += array(
			'Content-Type' => 'application/json; charset=utf-8',
			'Accept'       => 'application/json',
		);
		$response = $this->http->request( 'POST', $this->address->url( $path ), $headers, $this->encode( $body, $model, $method ), self::TIMEOUT );
		if ( 0 === $response->status ) {
			$exception = $this->errors->withDatabase( $this->errors->transportFailure( $response, $model, $method ), $this->database );
			throw $exception;
		}
		return $response;
	}

	/**
	 * Decode a JSON body.
	 *
	 * @param HttpResponse $response Response.
	 * @return array{0:bool,1:mixed} Whether the body is JSON, and the decoded value.
	 */
	protected function decode( HttpResponse $response ): array {
		$body = trim( $response->body );
		if ( '' === $body ) {
			return array( false, null );
		}
		$value = json_decode( $body, true );
		return array( JSON_ERROR_NONE === json_last_error(), $value );
	}

	/**
	 * Encode a request body as JSON that keeps Arabic and other scripts as they are.
	 *
	 * @param array<mixed>|object $body   Value.
	 * @param string              $model  Model of the call.
	 * @param string              $method Method of the call.
	 *
	 * @throws OdooException When the value cannot be encoded (for example text that is not valid UTF-8).
	 */
	private function encode( array|object $body, string $model, string $method ): string {
		$json = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		if ( ! is_string( $json ) ) {
			$exception = new OdooException(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => OdooErrorMapper::SYSTEM,
						'field'  => $model,
						'reason' => __( 'A value could not be written as text. Check for characters that are not valid.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					),
					null,
					null,
					null,
					'request body could not be encoded'
				),
				'',
				$model,
				$method
			);
			throw $exception;
		}
		return $json;
	}

	/**
	 * Check that a result is a list of records.
	 *
	 * @param mixed  $result Decoded result.
	 * @param string $model  Model of the call.
	 * @param string $method Method of the call.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws OdooException When the result is not a list.
	 */
	protected function records( mixed $result, string $model, string $method ): array {
		if ( ! is_array( $result ) ) {
			$exception = $this->errors->unreadable( new HttpResponse( 200 ), $model, $method );
			throw $exception;
		}
		$records = array();
		foreach ( $result as $record ) {
			if ( is_array( $record ) ) {
				$records[] = $record;
			}
		}
		return $records;
	}

	/**
	 * The id from the result of create (a list with one id, or the id itself).
	 *
	 * @param mixed  $result Decoded result.
	 * @param string $model  Model of the call.
	 *
	 * @throws OdooException When no id came back.
	 */
	protected function createdId( mixed $result, string $model ): int {
		if ( is_array( $result ) && array() !== $result ) {
			$result = reset( $result );
		}
		if ( is_int( $result ) && $result > 0 ) {
			return $result;
		}
		$exception = $this->errors->unreadable( new HttpResponse( 200 ), $model, 'create' );
		throw $exception;
	}
}
