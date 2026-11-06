<?php
/**
 * Turns the error answers of the Sage X3 web services into connector errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageX3;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;

/**
 * 401, 403, 429 and 5xx are left to the base mapping. A 404 means the folder does not expose the class with the representation. Other 4xx
 * answers carry their reason in `$diagnoses` or in a message. The same `$diagnoses` list decides a 200 or 201 answer too: a 201 only
 * means the class and representation were found and the body accepted.
 */
final class SageX3ErrorMapper {

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $system   System name.
	 */
	public function map( ApiResponse $response, string $system ): ?ConnectorError {
		$status = $response->status();
		if ( in_array( $status, array( 401, 403, 429 ), true ) || $status >= 500 ) {
			return null;
		}
		$text = self::firstError( $response->jsonValue() );
		if ( 404 === $status ) {
			return Problems::refused( $system, 'order_class', '' !== $text ? $text : __( 'This folder does not expose the class with the representation named in the connection settings, or the folder name is wrong.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'class not found' );
		}
		if ( 1 === preg_match( '/^\s*(<!doctype|<html|<head|<body)/i', $response->body() ) ) {
			return Problems::refused( $system, 'address', __( 'This address did not answer like Sage X3 web services. Check the address and the folder.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'not x3' );
		}
		return Problems::refused( $system, 'request', '' !== $text ? $text : __( 'Sage X3 refused the request.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'sagex3 ' . $status );
	}

	/**
	 * The first error message in an answer: the first `$diagnoses` entry of severity error, else a message field.
	 *
	 * @param mixed $node Decoded JSON.
	 * @return string Empty when there is none.
	 */
	public static function firstError( mixed $node ): string {
		if ( ! is_array( $node ) ) {
			return '';
		}
		foreach ( (array) ( $node['$diagnoses'] ?? array() ) as $diagnosis ) {
			if ( is_array( $diagnosis ) && 'error' === strtolower( (string) ( $diagnosis['$severity'] ?? '' ) ) ) {
				$message = self::plain( (string) ( $diagnosis['$message'] ?? '' ) );
				return '' !== $message ? $message : 'error';
			}
		}
		foreach ( array( '$message', 'message', 'error_description' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && '' !== trim( $node[ $key ] ) ) {
				return self::plain( $node[ $key ] );
			}
		}
		foreach ( $node as $key => $child ) {
			if ( '$diagnoses' === $key || ! is_array( $child ) ) {
				continue;
			}
			$found = self::firstError( $child );
			if ( '' !== $found ) {
				return $found;
			}
		}
		return '';
	}

	/**
	 * Text without tags, entities and runs of white space.
	 *
	 * @param string $text Server text.
	 */
	private static function plain( string $text ): string {
		$text = html_entity_decode( (string) preg_replace( '/<[^>]*>/', ' ', $text ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
