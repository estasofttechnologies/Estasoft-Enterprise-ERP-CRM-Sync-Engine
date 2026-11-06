<?php
/**
 * The Sage X3 REST web services behind small methods.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageX3;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\SameOrigin;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Resources are `/{class}?representation={representation}.${facet}`. A list answers `{"$itemsPerPage", "$resources", "$links"}` and pages
 * are followed through `$links.$next.$url`. A body that is not JSON (a login page) is reported as such.
 */
final class SageX3Client {

	/**
	 * Most pages a list helper follows.
	 */
	public const MAX_PAGES = 50;

	/**
	 * Set up with the services it uses.
	 *
	 * @param ApiClient $api    Shared client.
	 * @param string    $system System name.
	 * @param string    $origin Scheme, host and port of the server, for `$next` links that are only a path.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $system, private readonly string $origin ) {
	}

	/**
	 * A string literal for a `where` clause: single quotes, embedded quotes doubled.
	 *
	 * @param string $value Text.
	 */
	public static function literal( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	/**
	 * Rows of a class, all pages.
	 *
	 * @param string $classCode          Class code.
	 * @param string $representation Representation code.
	 * @param string $where          `where` predicate, or empty.
	 * @param int    $max            Most rows to collect.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure, and when the answer holds an error diagnosis.
	 */
	public function query( string $classCode, string $representation, string $where = '', int $max = 500 ): array {
		$query = array( 'representation' => $representation . '.$query' );
		if ( '' !== $where ) {
			$query['where'] = $where;
		}
		$response = $this->api->get( '/' . rawurlencode( $classCode ), $query, null, array( 'action' => 'read ' . $classCode ) );
		$rows     = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$json = $this->json( $response );
			foreach ( (array) ( $json['$resources'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			$next = $json['$links']['$next']['$url'] ?? '';
			if ( count( $rows ) >= $max || ! is_string( $next ) || '' === $next ) {
				break;
			}
			$url = SameOrigin::resolve( $next, $this->origin );
			if ( null === $url ) {
				$exception = new ApiException( Problems::refused( $this->system, 'address', __( 'The server pointed to the next page on another server, so it was not followed.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'foreign next link' ), $response );
				throw $exception;
			}
			$response = $this->api->get( $url, array(), null, array( 'action' => 'read ' . $classCode ) );
		}
		return array_slice( $rows, 0, $max );
	}

	/**
	 * Create a record.
	 *
	 * @param string              $classCode          Class code.
	 * @param string              $representation Representation code.
	 * @param array<string,mixed> $body           Body.
	 * @return array{body:array<string,mixed>,location:string} The answer and its Location header.
	 *
	 * @throws ApiException On failure, and when the answer holds an error diagnosis.
	 */
	public function create( string $classCode, string $representation, array $body ): array {
		$response = $this->api->post( '/' . rawurlencode( $classCode ), array( 'representation' => $representation . '.$create' ), $body, array( 'action' => 'write ' . $classCode ) );
		return array(
			'body'     => $this->json( $response ),
			'location' => (string) $response->header( 'Location' ),
		);
	}

	/**
	 * The template of a representation's create facet, to prove that the class and representation exist.
	 *
	 * @param string $classCode          Class code.
	 * @param string $representation Representation code.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On failure.
	 */
	public function template( string $classCode, string $representation ): array {
		$response = $this->api->get( '/' . rawurlencode( $classCode ) . '/$template', array( 'representation' => $representation . '.$create' ), null, array( 'action' => 'read template' ) );
		return $this->json( $response );
	}

	/**
	 * The property names of a representation, from its prototype; null when the folder does not describe it.
	 *
	 * @param string $representation Representation code.
	 * @return array{props:array<int,string>,children:array<string,array<int,string>>}|null
	 *
	 * @throws ApiException On failure other than not found.
	 */
	public function prototype( string $representation ): ?array {
		$response = $this->api->get(
			"/\$prototypes('" . rawurlencode( $representation ) . ".\$edit')",
			array(),
			null,
			array(
				'allow'  => array( 404 ),
				'action' => 'read prototype',
			)
		);
		if ( 404 === $response->status() ) {
			return null;
		}
		$json       = $this->json( $response );
		$properties = isset( $json['$properties'] ) && is_array( $json['$properties'] ) ? $json['$properties'] : null;
		if ( null === $properties ) {
			return null;
		}
		$children = array();
		foreach ( $properties as $name => $definition ) {
			$inner = is_array( $definition ) ? ( $definition['$item']['$properties'] ?? $definition['$properties'] ?? null ) : null;
			if ( is_array( $inner ) ) {
				$children[ (string) $name ] = array_map( 'strval', array_keys( $inner ) );
			}
		}
		return array(
			'props'    => array_map( 'strval', array_keys( $properties ) ),
			'children' => $children,
		);
	}

	/**
	 * The error `$diagnoses` entries of a decoded answer, anywhere in it.
	 *
	 * @param mixed $node Decoded JSON.
	 * @return array<int,string> The messages.
	 */
	public static function errors( mixed $node ): array {
		$messages = array();
		if ( ! is_array( $node ) ) {
			return $messages;
		}
		foreach ( $node as $key => $child ) {
			if ( '$diagnoses' === $key && is_array( $child ) ) {
				foreach ( $child as $diagnosis ) {
					if ( is_array( $diagnosis ) && 'error' === strtolower( (string) ( $diagnosis['$severity'] ?? '' ) ) ) {
						$messages[] = trim( (string) ( $diagnosis['$message'] ?? 'error' ) );
					}
				}
			} elseif ( is_array( $child ) ) {
				$messages = array_merge( $messages, self::errors( $child ) );
			}
		}
		return $messages;
	}

	/**
	 * The decoded body of an answer; an error diagnosis or a body that is not JSON is an error.
	 *
	 * @param ApiResponse $response Response.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException With SYNC-VAL-002 when the body holds an error diagnosis, and with SYNC-AUTH-001 for a login page.
	 */
	private function json( ApiResponse $response ): array {
		$data = $response->jsonValue();
		if ( ! is_array( $data ) ) {
			$exception = new ApiException( $this->notX3( $response ), $response );
			throw $exception;
		}
		$errors = self::errors( $data );
		if ( array() !== $errors ) {
			$exception = new ApiException( Problems::refused( $this->system, 'request', $errors[0], 'diagnosis' ), $response );
			throw $exception;
		}
		return $data;
	}

	/**
	 * The error for a body that is not JSON.
	 *
	 * @param ApiResponse $response Response.
	 */
	private function notX3( ApiResponse $response ): ConnectorError {
		if ( 1 === preg_match( '/^\s*(<!doctype|<html|<head|<body)/i', $response->body() ) ) {
			return new ConnectorError(
				ErrorCode::Auth001,
				array( 'system' => $this->system ),
				$response->status(),
				null,
				null,
				'login page'
			);
		}
		return Problems::refused( $this->system, 'address', __( 'This address did not answer like Sage X3 web services. Check the address and the folder.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'not x3' );
	}
}
