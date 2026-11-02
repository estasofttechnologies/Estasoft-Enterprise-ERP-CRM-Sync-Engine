<?php
/**
 * Routes: product matching.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\Products\ProductMatchService;
use Estasoft\SyncEngine\Rest\RestModule;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * `GET /products/unmatched`, `GET /products/search` and `POST /products/map`.
 */
final class ProductsController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add(
			'/products/unmatched',
			'GET',
			self::MANAGE,
			$this->unmatched( ... ),
			array(
				'page'          => self::pageArg(),
				'connection_id' => self::connectionArg(),
			)
		);
		$this->add(
			'/products/search',
			'GET',
			self::CONFIGURE,
			$this->search( ... ),
			array(
				'q'             => self::lineArg( 100, true ),
				'connection_id' => self::connectionArg(),
			)
		);
		$this->add(
			'/products/map',
			'POST',
			self::CONFIGURE,
			$this->map( ... ),
			array(
				'product_id'    => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				),
				'external_id'   => array(
					'type'              => array( 'string', 'null' ),
					'sanitize_callback' => static fn( $value ): ?string => null === $value ? null : Sanitize::line( $value, 191 ),
				),
				'external_ref'  => array(
					'type'              => array( 'string', 'null' ),
					'sanitize_callback' => static fn( $value ): ?string => null === $value ? null : Sanitize::line( $value, 191 ),
				),
				'connection_id' => self::connectionArg(),
			)
		);
	}

	/**
	 * `GET /products/unmatched`.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function unmatched( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->paged( $this->matching()->unmatched( $this->connectionId( $request ), max( 1, (int) $request->get_param( 'page' ) ) ) );
	}

	/**
	 * `GET /products/search`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<int,array{id:string,ref:string,name:string}>
	 */
	public function search( \WP_REST_Request $request ): array {
		return $this->matching()->search( $this->connectionId( $request ), Sanitize::line( $request->get_param( 'q' ), 100 ) );
	}

	/**
	 * `POST /products/map`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function map( \WP_REST_Request $request ): array {
		$id  = $request->get_param( 'external_id' );
		$ref = $request->get_param( 'external_ref' );
		return $this->matching()->map(
			$this->connectionId( $request ),
			(int) $request->get_param( 'product_id' ),
			is_string( $id ) && '' !== $id ? $id : null,
			is_string( $ref ) && '' !== $ref ? $ref : null
		);
	}

	/**
	 * The matching service.
	 *
	 * @return ProductMatchService
	 */
	private function matching(): object {
		return $this->service( RestModule::PRODUCT_MATCHING );
	}
}
