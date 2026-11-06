<?php
/**
 * Routes: mappings.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Mapping\MappingService;
use Estasoft\SyncEngine\Mapping\Previewer;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * Read the live and draft versions, save a draft, publish, discard, roll back, and preview an order with the live or draft rules.
 * The history is linear and nothing is deleted (Mapping\MappingService). Preview sends nothing to the connected system.
 */
final class MappingsController extends BaseController {

	/**
	 * Route fragment for the six mapping types.
	 */
	private const TYPE = '(?P<type>orders|customers|products|refunds|stock|tracking)';

	/**
	 * The six mapping types.
	 */
	private const TYPES = array( 'orders', 'customers', 'products', 'refunds', 'stock', 'tracking' );

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$base = array(
			'type'          => self::enumArg( self::TYPES, true ),
			'connection_id' => self::connectionArg(),
		);
		$this->add( '/mappings/' . self::TYPE, 'GET', self::MANAGE, $this->show( ... ), $base );
		$this->add(
			'/mappings/' . self::TYPE,
			'PUT',
			self::CONFIGURE,
			$this->saveDraft( ... ),
			array_merge(
				$base,
				array(
					'rules' => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => 500,
						'items'    => array( 'type' => 'object' ),
					),
					'note'  => self::lineArg( 500 ),
				)
			)
		);
		$this->add(
			'/mappings/' . self::TYPE . '/versions/(?P<version>\d+)',
			'GET',
			self::MANAGE,
			$this->version( ... ),
			array_merge(
				$base,
				array(
					'version' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				)
			)
		);
		$this->add( '/mappings/' . self::TYPE . '/publish', 'POST', self::CONFIGURE, $this->publish( ... ), array_merge( $base, array( 'note' => self::lineArg( 500 ) ) ) );
		$this->add( '/mappings/' . self::TYPE . '/discard', 'POST', self::CONFIGURE, $this->discard( ... ), $base );
		$this->add(
			'/mappings/' . self::TYPE . '/rollback',
			'POST',
			self::CONFIGURE,
			$this->rollback( ... ),
			array_merge(
				$base,
				array(
					'version' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				)
			)
		);
		$this->add(
			'/mappings/preview',
			'POST',
			self::CONFIGURE,
			$this->preview( ... ),
			array(
				'type'          => self::enumArg( self::TYPES, true ),
				'order_id'      => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				),
				'use_draft'     => self::boolArg(),
				'lookups'       => self::boolArg(),
				'connection_id' => self::connectionArg(),
			),
			false
		);
	}

	/**
	 * `GET /mappings/{type}`: the `MappingSet`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function show( \WP_REST_Request $request ): array {
		return $this->mapping()->set( $this->connectionId( $request ), (string) $request['type'] );
	}

	/**
	 * `GET /mappings/{type}/versions/{version}`: one version with its rules, to look at an older one (the list in the set has none).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed> The `MappingVersion`.
	 *
	 * @throws ApiException 404 when that version does not exist.
	 */
	public function version( \WP_REST_Request $request ): array {
		$version = $this->mapping()->version( $this->connectionId( $request ), (string) $request['type'], (int) $request['version'] );
		if ( null === $version ) {
			throw ApiException::notFound( esc_html__( 'That version does not exist.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return $version;
	}

	/**
	 * `PUT /mappings/{type}`: save the rules as the draft and return the `MappingSet`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function saveDraft( \WP_REST_Request $request ): array {
		$connection = $this->connectionId( $request );
		$type       = (string) $request['type'];
		$note       = Sanitize::line( $request->get_param( 'note' ), 500 );
		$this->mapping()->saveDraft( $connection, $type, (array) $request->get_param( 'rules' ), '' === $note ? null : $note, $this->actorName() );
		return $this->mapping()->set( $connection, $type );
	}

	/**
	 * `POST /mappings/{type}/publish`: make the draft live. A note given here is kept with the version.
	 *
	 * Whether there is a draft is asked first, so that "no draft" is the only thing that answers 409. Any other failure (a draft with
	 * rule problems, a broken service) is left to the error mapper: rule problems become a 422 with every problem, the rest a logged
	 * SYNC-REST-001.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException 409 when there is no draft.
	 */
	public function publish( \WP_REST_Request $request ): array {
		$connection = $this->connectionId( $request );
		$type       = (string) $request['type'];
		$note       = Sanitize::line( $request->get_param( 'note' ), 500 );
		$mapping    = $this->mapping();
		$draft      = $mapping->set( $connection, $type )['draft'] ?? null;
		if ( null === $draft ) {
			throw ApiException::conflict( esc_html__( 'There is no draft to publish.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'SYNC-CFG-001' );
		}
		if ( '' !== $note && is_array( $draft['rules'] ?? null ) ) {
			$mapping->saveDraft( $connection, $type, $draft['rules'], $note, $this->actorName() );
		}
		$mapping->publish( $connection, $type, $this->actorName() );
		return $mapping->set( $connection, $type );
	}

	/**
	 * `POST /mappings/{type}/discard`: throw the draft away.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function discard( \WP_REST_Request $request ): array {
		$connection = $this->connectionId( $request );
		$type       = (string) $request['type'];
		$this->mapping()->discardDraft( $connection, $type, $this->actorName() );
		return $this->mapping()->set( $connection, $type );
	}

	/**
	 * `POST /mappings/{type}/rollback`: copy an older version into a new live version.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException 404 when that version does not exist.
	 */
	public function rollback( \WP_REST_Request $request ): array {
		$connection = $this->connectionId( $request );
		$type       = (string) $request['type'];
		$version    = (int) $request->get_param( 'version' );
		if ( null === $this->mapping()->version( $connection, $type, $version ) ) {
			throw ApiException::notFound( esc_html__( 'That version does not exist.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$this->mapping()->rollback( $connection, $type, $version, $this->actorName() );
		return $this->mapping()->set( $connection, $type );
	}

	/**
	 * `POST /mappings/preview`: build the payload of an order and show it beside the order. Nothing is sent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed> The `MappingPreview`.
	 */
	public function preview( \WP_REST_Request $request ): array {
		$previewer  = $this->serviceAs( 'mapping.previewer', Previewer::class );
		$connection = $this->connectionId( $request );
		$type       = (string) $request->get_param( 'type' );
		$draftRules = null;
		if ( Sanitize::flag( $request->get_param( 'use_draft' ) ) ) {
			$draft      = $this->mapping()->set( $connection, $type )['draft'] ?? null;
			$draftRules = is_array( $draft ) && is_array( $draft['rules'] ?? null ) ? $draft['rules'] : null;
		}
		return $previewer->preview( $connection, $type, (int) $request->get_param( 'order_id' ), $draftRules, Sanitize::flag( $request->get_param( 'lookups' ) ) );
	}

	/**
	 * The mapping service.
	 *
	 * @return MappingService
	 */
	private function mapping(): object {
		return $this->service( 'mapping.service' );
	}
}
