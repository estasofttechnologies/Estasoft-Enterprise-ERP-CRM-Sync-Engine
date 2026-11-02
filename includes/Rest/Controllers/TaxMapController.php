<?php
/**
 * Routes: tax mapping.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\RestModule;
use Estasoft\SyncEngine\Rest\Tax\TaxMapAssembler;

/**
 * `GET /tax-map` and `PUT /tax-map`. Tax mapping is edited live (no drafts); every change is logged by Mapping\TaxMap.
 */
final class TaxMapController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/tax-map', 'GET', self::MANAGE, $this->show( ... ), array( 'connection_id' => self::connectionArg() ) );
		$this->add(
			'/tax-map',
			'PUT',
			self::CONFIGURE,
			$this->save( ... ),
			array(
				'connection_id'   => self::connectionArg(),
				'rows'            => array(
					'type'     => 'array',
					'required' => true,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'country'      => array(
								'type'    => 'string',
								'pattern' => '^[A-Za-z]{2}$',
							),
							'tax_name'     => array( 'type' => 'string' ),
							'rate_percent' => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 100,
							),
							'system_code'  => array( 'type' => array( 'string', 'null' ) ),
						),
						'required'             => array( 'country', 'rate_percent' ),
					),
				),
				'buyer_tax_rules' => self::structureArg( 'array' ),
			)
		);
	}

	/**
	 * `GET /tax-map`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function show( \WP_REST_Request $request ): array {
		return $this->assembler()->build( $this->connectionId( $request ) );
	}

	/**
	 * `PUT /tax-map`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function save( \WP_REST_Request $request ): array {
		$buyer = $request->get_param( 'buyer_tax_rules' );
		return $this->assembler()->save(
			$this->connectionId( $request ),
			array_values( array_filter( (array) $request->get_param( 'rows' ), 'is_array' ) ),
			is_array( $buyer ) ? array_values( array_filter( $buyer, 'is_array' ) ) : null,
			$this->actorName()
		);
	}

	/**
	 * The assembler.
	 *
	 * @return TaxMapAssembler
	 */
	private function assembler(): object {
		return $this->service( RestModule::TAX_MAP );
	}
}
