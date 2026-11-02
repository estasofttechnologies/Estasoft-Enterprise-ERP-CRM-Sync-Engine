<?php
/**
 * Routes: stock and tracking.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Ranges;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Stock\StockService;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * Stock rules (including the tracking switches that live on the same screen), the overview with recent changes and conflicts,
 * and the two answers to a conflict. The system is the source of truth; no route writes stock to it.
 */
final class StockController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/stock/rules', 'GET', self::MANAGE, $this->rules( ... ), array( 'connection_id' => self::connectionArg() ) );
		$this->add(
			'/stock/rules',
			'PUT',
			self::CONFIGURE,
			$this->saveRules( ... ),
			array(
				'connection_id'           => self::connectionArg(),
				'enabled'                 => self::boolArg(),
				'interval_minutes'        => array(
					'type'    => 'integer',
					'minimum' => 5,
					'maximum' => 1440,
				),
				'safety_buffer'           => array(
					'type'    => 'integer',
					'minimum' => Ranges::SAFETY_BUFFER[0],
					'maximum' => Ranges::SAFETY_BUFFER[1],
				),
				'subtract_held'           => self::boolArg(),
				'warehouses'              => array(
					'type'     => 'array',
					'maxItems' => 200,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'id'                  => array( 'type' => 'string' ),
							'counts_toward_stock' => array( 'type' => 'boolean' ),
							'countries'           => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'tracking_enabled'        => self::boolArg(),
				'delivered_becomes'       => self::lineArg( 64 ),
				'cod_payment_on_delivery' => self::boolArg(),
			)
		);
		$this->add( '/stock/overview', 'GET', self::MANAGE, $this->overview( ... ), array( 'connection_id' => self::connectionArg() ) );
		$this->add( '/stock/conflicts/(?P<id>\d+)/accept', 'POST', self::MANAGE, $this->accept( ... ), array( 'id' => self::idArg() ) );
		$this->add( '/stock/conflicts/(?P<id>\d+)/hold', 'POST', self::MANAGE, $this->hold( ... ), array( 'id' => self::idArg() ) );
	}

	/**
	 * `GET /stock/rules`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function rules( \WP_REST_Request $request ): array {
		return $this->stock()->rules( $this->connectionId( $request ) );
	}

	/**
	 * `PUT /stock/rules`: save the fields that were sent and return the rules.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function saveRules( \WP_REST_Request $request ): array {
		$fields = array( 'enabled', 'interval_minutes', 'safety_buffer', 'subtract_held', 'warehouses', 'tracking_enabled', 'delivered_becomes', 'cod_payment_on_delivery' );
		$input  = array();
		foreach ( $fields as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}
		if ( isset( $input['delivered_becomes'] ) ) {
			$input['delivered_becomes'] = Sanitize::name( $input['delivered_becomes'] );
		}
		return $this->stock()->saveRules( $this->connectionId( $request ), $input );
	}

	/**
	 * `GET /stock/overview`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function overview( \WP_REST_Request $request ): array {
		$id = $request->get_param( 'connection_id' );
		return $this->stock()->overview( is_numeric( $id ) ? (int) $id : null );
	}

	/**
	 * `POST /stock/conflicts/{id}/accept`: take the system's quantity.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{ok:true}
	 */
	public function accept( \WP_REST_Request $request ): array {
		$this->stock()->acceptConflict( (string) (int) $request['id'] );
		return array( 'ok' => true );
	}

	/**
	 * `POST /stock/conflicts/{id}/hold`: keep the shop quantity until a person decides.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{ok:true}
	 */
	public function hold( \WP_REST_Request $request ): array {
		$this->stock()->holdConflict( (string) (int) $request['id'] );
		return array( 'ok' => true );
	}

	/**
	 * The stock service.
	 *
	 * @return StockService
	 */
	private function stock(): object {
		return $this->service( JobServices::STOCK );
	}
}
