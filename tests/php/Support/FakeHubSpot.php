<?php
/**
 * A small in-memory HubSpot CRM for the connector tests.
 *
 * Shapes follow developers.hubspot.com: POST /crm/objects/{version}/{object}/search, create with properties and associations,
 * 409 "Contact already exists. Existing ID: n", error bodies with status, message, category and errors[]. The properties API creates a deal
 * property (name, label, type, fieldType, groupName, hasUniqueValue); a unique property refuses a second deal with the same value (409).
 * Search answers carry no rate-limit headers (usage guidelines: "Responses from the search API endpoints will not include any of the rate
 * limit headers"); other answers carry none here either, because the connector no longer reads them.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\HttpResponse;

final class FakeHubSpot extends FakeSalesServer {

	public const KEY = 'pat-eu1-synthetic-service-key-0001';

	/**
	 * Rows by object: id => row.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $objects = array(
		'contacts'   => array(),
		'deals'      => array(),
		'line_items' => array(),
		'notes'      => array(),
		'products'   => array(),
	);

	/**
	 * Currencies the account accepts on deals.
	 *
	 * @var array<int,string>
	 */
	public array $currencies = array( 'USD', 'EUR', 'AED', 'SAR' );

	/**
	 * When true a deal's amount is recalculated from its line items, as some accounts do.
	 */
	public bool $recalculate = false;

	/**
	 * Custom deal properties that exist: internal name => definition.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $properties = array();

	/**
	 * Whether the key lacks the schema scope to read properties.
	 */
	public bool $schemaReadDenied = false;

	/**
	 * Whether the key lacks the schema scope to create properties.
	 */
	public bool $schemaWriteDenied = false;

	/**
	 * Whether a deal with a property that does not exist is refused (PROPERTY_DOESNT_EXIST).
	 */
	public bool $strictProperties = false;

	/**
	 * When set, another worker makes a deal with this reference (in dealname and the unique property) just before the next deal create.
	 */
	public ?string $raceReference = null;

	/**
	 * Scopes the key lacks (object names).
	 *
	 * @var array<int,string>
	 */
	public array $deniedObjects = array();

	/**
	 * When true a PATCH cannot change the amount of a deal (HubSpot keeps its own figure).
	 */
	public bool $lockAmount = false;

	private int $counter = 1000;

	/**
	 * Add a deal the way an earlier pass or the merchant would.
	 *
	 * @param string $name     Deal name.
	 * @param string $amount   Amount as decimal text.
	 * @param string $currency Currency.
	 */
	public function addDeal( string $name, string $amount, string $currency = 'EUR' ): string {
		return $this->insert( 'deals', array( 'dealname' => $name, 'amount' => $amount, 'deal_currency_code' => $currency, 'pipeline' => 'default', 'dealstage' => 'appointmentscheduled' ) );
	}

	/**
	 * Add a product the way a merchant would.
	 *
	 * @param string $sku   SKU.
	 * @param string $name  Name.
	 */
	public function addProduct( string $sku, string $name ): string {
		return $this->insert( 'products', array( 'name' => $name, 'hs_sku' => $sku ) );
	}

	/**
	 * Count rows of an object that are not archived.
	 *
	 * @param string $object Object.
	 */
	public function count( string $object ): int {
		return count( array_filter( $this->objects[ $object ], static fn( array $row ): bool => empty( $row['archived'] ) ) );
	}

	/**
	 * @param string               $method  Method.
	 * @param string               $path    Path.
	 * @param array<string,mixed>  $query   Query.
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Headers.
	 */
	protected function route( string $method, string $path, array $query, array $body, array $headers ): HttpResponse {
		$extra = array();
		if ( 'Bearer ' . self::KEY !== ( $headers['authorization'] ?? '' ) ) {
			return $this->json( array( 'status' => 'error', 'message' => 'Authentication credentials not found.', 'category' => 'INVALID_AUTHENTICATION' ), 401, $extra );
		}
		if ( 1 === preg_match( '~^/crm/properties/[\d-]+/deals(?:/(\w+))?$~', $path, $m ) ) {
			return $this->propertyApi( $method, $m[1] ?? '', $body );
		}
		if ( 1 === preg_match( '~^/crm/objects/[\d-]+/(\w+)/search$~', $path, $m ) && 'POST' === $method ) {
			return $this->denied( $m[1] ) ?? $this->unknownProperty( $m[1], $body ) ?? $this->json( $this->search( $m[1], $body ), 200, $extra );
		}
		if ( 1 === preg_match( '~^/crm/objects/[\d-]+/(\w+)$~', $path, $m ) && 'GET' === $method ) {
			return $this->denied( $m[1] ) ?? $this->json( $this->search( $m[1], array( 'limit' => (int) ( $query['limit'] ?? 10 ) ) ), 200, $extra );
		}
		if ( 1 === preg_match( '~^/crm/objects/[\d-]+/(\w+)$~', $path, $m ) && 'POST' === $method ) {
			return $this->denied( $m[1] ) ?? $this->create( $m[1], $body, $extra );
		}
		if ( 1 === preg_match( '~^/crm/objects/[\d-]+/(\w+)/(\d+)$~', $path, $m ) ) {
			return $this->changeOrArchive( $method, $m[1], $m[2], $body, $extra );
		}
		if ( 1 === preg_match( '~^/crm/pipelines/[\d-]+/deals$~', $path ) ) {
			return $this->json( array( 'results' => array( $this->pipeline() ) ), 200, $extra );
		}
		if ( '/account-info/v3/details' === $path ) {
			return $this->json( array( 'portalId' => 1234567, 'accountType' => 'STANDARD', 'timeZone' => 'Asia/Dubai', 'companyCurrency' => 'AED' ), 200, $extra );
		}
		return $this->json( array( 'status' => 'error', 'message' => 'resource not found', 'category' => 'OBJECT_NOT_FOUND' ), 404 );
	}

	/**
	 * @param string $kind    Kind.
	 * @param int    $seconds Seconds.
	 */
	protected function failureResponse( string $kind, int $seconds ): HttpResponse {
		return match ( $kind ) {
			'rate_limit'   => $this->json( array( 'status' => 'error', 'message' => 'You have reached your secondly limit.', 'errorType' => 'RATE_LIMIT', 'policyName' => 'TEN_SECONDLY_ROLLING' ), 429, array( 'retry-after' => (string) $seconds ) ),
			'server_error' => $this->json( array( 'status' => 'error', 'message' => 'internal error' ), 502 ),
			'auth'         => $this->json( array( 'status' => 'error', 'message' => 'The OAuth token used to make this call is expired.', 'category' => 'EXPIRED_AUTHENTICATION' ), 401 ),
			default        => $this->json( array( 'unexpected' => 'shape' ), 418 ),
		};
	}

	/**
	 * A search on a deal property that does not exist is refused when the fake is strict.
	 *
	 * @param string              $object Object.
	 * @param array<string,mixed> $body   Search body.
	 */
	private function unknownProperty( string $object, array $body ): ?HttpResponse {
		if ( ! $this->strictProperties || 'deals' !== $object ) {
			return null;
		}
		foreach ( (array) ( $body['filterGroups'][0]['filters'] ?? array() ) as $filter ) {
			$name = (string) ( $filter['propertyName'] ?? '' );
			if ( ! in_array( $name, array( 'dealname', 'amount', 'createdate', 'closedate', 'pipeline', 'dealstage' ), true ) && ! isset( $this->properties[ $name ] ) ) {
				return $this->invalid( $name, 'The property "' . $name . '" does not exist', array() );
			}
		}
		return null;
	}

	/**
	 * The properties API for deals: read one, create one.
	 *
	 * @param string              $method Method.
	 * @param string              $name   Property name from the path; empty for a create.
	 * @param array<string,mixed> $body   Body.
	 */
	private function propertyApi( string $method, string $name, array $body ): HttpResponse {
		$scope = array( 'status' => 'error', 'message' => 'This app hasn\'t been granted all required scopes to make this call.', 'category' => 'MISSING_SCOPES', 'errors' => array( array( 'message' => 'One or more of the following scopes are required.', 'context' => array( 'requiredGranularScopes' => array( 'crm.schemas.deals.' . ( 'GET' === $method ? 'read' : 'write' ) ) ) ) ) );
		if ( 'GET' === $method ) {
			if ( $this->schemaReadDenied ) {
				return $this->json( $scope, 403 );
			}
			return isset( $this->properties[ $name ] )
				? $this->json( $this->properties[ $name ] )
				: $this->json( array( 'status' => 'error', 'message' => 'Property ' . $name . ' does not exist', 'category' => 'OBJECT_NOT_FOUND' ), 404 );
		}
		if ( $this->schemaWriteDenied ) {
			return $this->json( $scope, 403 );
		}
		foreach ( array( 'name', 'label', 'type', 'fieldType', 'groupName' ) as $required ) {
			if ( empty( $body[ $required ] ) ) {
				return $this->invalid( $required, $required . ' is required', array() );
			}
		}
		if ( isset( $this->properties[ (string) $body['name'] ] ) ) {
			return $this->json( array( 'status' => 'error', 'message' => 'Property already exists', 'category' => 'CONFLICT' ), 409 );
		}
		$this->properties[ (string) $body['name'] ] = $body + array( 'hasUniqueValue' => false );
		return $this->json( $this->properties[ (string) $body['name'] ], 201 );
	}

	/**
	 * @param string $object Object.
	 */
	private function denied( string $object ): ?HttpResponse {
		if ( ! in_array( $object, $this->deniedObjects, true ) ) {
			return null;
		}
		return $this->json(
			array(
				'status'   => 'error',
				'message'  => 'This app hasn\'t been granted all required scopes to make this call.',
				'category' => 'MISSING_SCOPES',
				'errors'   => array( array( 'message' => 'One or more of the following scopes are required.', 'context' => array( 'requiredGranularScopes' => array( 'crm.objects.' . $object . '.read' ) ) ) ),
			),
			403
		);
	}

	/**
	 * @param string               $object Object.
	 * @param array<string,mixed>  $props  Properties.
	 */
	private function insert( string $object, array $props ): string {
		$id = (string) ++$this->counter;
		$this->objects[ $object ][ $id ] = array(
			'id'         => $id,
			'properties' => $props + array( 'createdate' => gmdate( 'Y-m-d\TH:i:s', 1_800_000_000 + $this->counter ) . '.000Z', 'hs_object_id' => $id ),
			'assoc'      => array(),
			'archived'   => false,
		);
		return $id;
	}

	/**
	 * @param string              $object Object.
	 * @param array<string,mixed> $body   Search body.
	 * @return array<string,mixed>
	 */
	private function search( string $object, array $body ): array {
		$rows = array_values( array_filter( $this->objects[ $object ] ?? array(), static fn( array $row ): bool => empty( $row['archived'] ) ) );
		foreach ( (array) ( $body['filterGroups'][0]['filters'] ?? array() ) as $filter ) {
			$name  = (string) $filter['propertyName'];
			$value = (string) $filter['value'];
			$rows  = array_values(
				array_filter(
					$rows,
					function ( array $row ) use ( $name, $value, $filter ): bool {
						if ( 'associations.deal' === $name ) {
							return in_array( $value, $row['assoc']['deals'] ?? array(), true );
						}
						$have = (string) ( $row['properties'][ $name ] ?? '' );
						return 'GTE' === $filter['operator']
							? ( strtotime( $have ) * 1000 ) >= (int) $value
							: 0 === strcasecmp( $have, $value );
					}
				)
			);
		}
		if ( ! empty( $body['query'] ) ) {
			$needle = strtolower( (string) $body['query'] );
			$rows   = array_values( array_filter( $rows, static fn( array $row ): bool => str_contains( strtolower( wp_json_encode( $row['properties'] ) ), $needle ) ) );
		}
		$offset = (int) ( $body['after'] ?? 0 );
		$limit  = (int) ( $body['limit'] ?? 10 );
		$slice  = array_slice( $rows, $offset, $limit );
		$want   = (array) ( $body['properties'] ?? array() );
		$out    = array(
			'total'   => count( $rows ),
			'results' => array_map(
				static fn( array $row ): array => array(
					'id'         => $row['id'],
					'properties' => array() === $want ? $row['properties'] : array_intersect_key( $row['properties'], array_flip( array_merge( $want, array( 'createdate', 'hs_object_id' ) ) ) ),
					'archived'   => false,
				),
				$slice
			),
		);
		if ( $offset + $limit < count( $rows ) ) {
			$out['paging'] = array( 'next' => array( 'after' => (string) ( $offset + $limit ) ) );
		}
		return $out;
	}

	/**
	 * @param string               $object Object.
	 * @param array<string,mixed>  $body   Body.
	 * @param array<string,string> $extra  Headers.
	 */
	private function create( string $object, array $body, array $extra ): HttpResponse {
		$props = (array) ( $body['properties'] ?? array() );
		if ( 'contacts' === $object ) {
			foreach ( $this->objects['contacts'] as $row ) {
				if ( 0 === strcasecmp( (string) ( $row['properties']['email'] ?? '' ), (string) ( $props['email'] ?? '-' ) ) ) {
					return $this->json( array( 'status' => 'error', 'message' => 'Contact already exists. Existing ID: ' . $row['id'], 'category' => 'CONFLICT' ), 409, $extra );
				}
			}
		}
		if ( 'deals' === $object && null !== $this->raceReference ) {
			$this->insert( 'deals', array( 'dealname' => $this->raceReference, 'estasoft_order_ref' => $this->raceReference, 'pipeline' => 'default', 'dealstage' => 'appointmentscheduled' ) );
			$this->raceReference = null;
		}
		if ( 'deals' === $object ) {
			foreach ( array( 'dealname', 'pipeline', 'dealstage' ) as $required ) {
				if ( empty( $props[ $required ] ) ) {
					return $this->invalid( $required, $required . ' is required', $extra );
				}
			}
			if ( isset( $props['deal_currency_code'] ) && ! in_array( $props['deal_currency_code'], $this->currencies, true ) ) {
				return $this->invalid( 'deal_currency_code', $props['deal_currency_code'] . ' is not a valid currency code', $extra );
			}
			if ( $this->strictProperties ) {
				foreach ( array_keys( $props ) as $key ) {
					if ( ! in_array( $key, array( 'dealname', 'amount', 'deal_currency_code', 'closedate', 'description', 'pipeline', 'dealstage' ), true ) && ! isset( $this->properties[ $key ] ) ) {
						return $this->invalid( (string) $key, 'Property "' . $key . '" does not exist', $extra );
					}
				}
			}
			foreach ( $this->properties as $name => $definition ) {
				if ( ! empty( $definition['hasUniqueValue'] ) && isset( $props[ $name ] ) ) {
					foreach ( $this->objects['deals'] as $row ) {
						if ( empty( $row['archived'] ) && 0 === strcasecmp( (string) ( $row['properties'][ $name ] ?? '' ), (string) $props[ $name ] ) ) {
							return $this->json( array( 'status' => 'error', 'message' => 'A deal with the same unique value for ' . $name . ' exists. Existing ID: ' . $row['id'], 'category' => 'CONFLICT' ), 409, $extra );
						}
					}
				}
			}
		}
		if ( 'line_items' === $object && (float) ( $props['price'] ?? 0 ) < 0 ) {
			return $this->invalid( 'price', 'price cannot be negative', $extra );
		}
		if ( 'notes' === $object && empty( $props['hs_timestamp'] ) ) {
			return $this->invalid( 'hs_timestamp', 'hs_timestamp is required', $extra );
		}
		foreach ( (array) ( $body['associations'] ?? array() ) as $association ) {
			$to = (string) $association['to']['id'];
			if ( ! $this->exists( $to ) ) {
				return $this->invalid( 'associations', 'Record ' . $to . ' does not exist', $extra );
			}
		}
		$id = $this->insert( $object, $props );
		foreach ( (array) ( $body['associations'] ?? array() ) as $association ) {
			$to      = (string) $association['to']['id'];
			$targets = $this->kindOf( $to );
			$this->objects[ $object ][ $id ]['assoc'][ $targets ][] = $to;
			if ( 'deals' === $targets ) {
				$this->objects['deals'][ $to ]['assoc'][ $object ][] = $id;
			}
			if ( 'deals' === $object ) {
				$this->objects[ $targets ][ $to ]['assoc']['deals'][] = $id;
			}
		}
		if ( 'deals' === $object && $this->recalculate ) {
			$sum = 0;
			foreach ( $this->objects['deals'][ $id ]['assoc']['line_items'] ?? array() as $lineId ) {
				$line = $this->objects['line_items'][ $lineId ]['properties'];
				$sum += (int) round( (float) $line['price'] * 100 ) * (int) $line['quantity'];
			}
			$this->objects['deals'][ $id ]['properties']['amount'] = number_format( $sum / 100, 2, '.', '' );
		}
		return $this->json( array( 'id' => $id, 'properties' => $this->objects[ $object ][ $id ]['properties'] ), 201, $extra );
	}

	/**
	 * @param string               $method Method.
	 * @param string               $object Object.
	 * @param string               $id     Id.
	 * @param array<string,mixed>  $body   Body.
	 * @param array<string,string> $extra  Headers.
	 */
	private function changeOrArchive( string $method, string $object, string $id, array $body, array $extra ): HttpResponse {
		if ( ! isset( $this->objects[ $object ][ $id ] ) ) {
			return $this->json( array( 'status' => 'error', 'message' => 'resource not found', 'category' => 'OBJECT_NOT_FOUND' ), 404, $extra );
		}
		if ( 'DELETE' === $method ) {
			$this->objects[ $object ][ $id ]['archived'] = true;
			return new HttpResponse( 204, '', $extra );
		}
		$changes = (array) ( $body['properties'] ?? array() );
		if ( $this->lockAmount ) {
			unset( $changes['amount'] );
		}
		$this->objects[ $object ][ $id ]['properties'] = $changes + $this->objects[ $object ][ $id ]['properties'];
		return $this->json( array( 'id' => $id, 'properties' => $this->objects[ $object ][ $id ]['properties'] ), 200, $extra );
	}

	/**
	 * @param string               $field  Field.
	 * @param string               $reason Reason.
	 * @param array<string,string> $extra  Headers.
	 */
	private function invalid( string $field, string $reason, array $extra ): HttpResponse {
		return $this->json(
			array(
				'status'   => 'error',
				'message'  => 'Property values were not valid',
				'category' => 'VALIDATION_ERROR',
				'errors'   => array( array( 'message' => $reason, 'context' => array( 'propertyName' => array( $field ) ) ) ),
			),
			400,
			$extra
		);
	}

	/**
	 * @param string $id Record id.
	 */
	private function exists( string $id ): bool {
		return '' !== $this->kindOf( $id );
	}

	/**
	 * @param string $id Record id.
	 */
	private function kindOf( string $id ): string {
		foreach ( $this->objects as $object => $rows ) {
			if ( isset( $rows[ $id ] ) ) {
				return $object;
			}
		}
		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function pipeline(): array {
		$stage = static fn( string $id, int $order, string $closed, string $probability ): array => array(
			'id'           => $id,
			'label'        => $id,
			'displayOrder' => $order,
			'metadata'     => array( 'isClosed' => $closed, 'probability' => $probability ),
		);
		return array(
			'id'           => 'default',
			'label'        => 'Sales Pipeline',
			'displayOrder' => 0,
			'stages'       => array( $stage( 'appointmentscheduled', 0, 'false', '0.2' ), $stage( 'closedwon', 5, 'true', '1.0' ), $stage( 'closedlost', 6, 'true', '0.0' ) ),
		);
	}
}
