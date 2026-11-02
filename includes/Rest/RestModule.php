<?php
/**
 * REST module: the `estasoft-sync/v1` API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\ModuleInterface;
use Estasoft\SyncEngine\Rest\Controllers\CapacityController;
use Estasoft\SyncEngine\Rest\Controllers\CatalogController;
use Estasoft\SyncEngine\Rest\Controllers\ConnectionsController;
use Estasoft\SyncEngine\Rest\Controllers\ExportsController;
use Estasoft\SyncEngine\Rest\Controllers\InboundController;
use Estasoft\SyncEngine\Rest\Controllers\InvoicesController;
use Estasoft\SyncEngine\Rest\Controllers\LogsController;
use Estasoft\SyncEngine\Rest\Controllers\MappingsController;
use Estasoft\SyncEngine\Rest\Controllers\OAuthController;
use Estasoft\SyncEngine\Rest\Controllers\OnboardingController;
use Estasoft\SyncEngine\Rest\Controllers\OrdersController;
use Estasoft\SyncEngine\Rest\Controllers\ProductsController;
use Estasoft\SyncEngine\Rest\Controllers\QueueController;
use Estasoft\SyncEngine\Rest\Controllers\ReadinessController;
use Estasoft\SyncEngine\Rest\Controllers\ReconciliationController;
use Estasoft\SyncEngine\Rest\Controllers\SettingsController;
use Estasoft\SyncEngine\Rest\Controllers\ShadowController;
use Estasoft\SyncEngine\Rest\Controllers\StatusController;
use Estasoft\SyncEngine\Rest\Controllers\StockController;
use Estasoft\SyncEngine\Rest\Controllers\SupportReportController;
use Estasoft\SyncEngine\Rest\Controllers\SyncController;
use Estasoft\SyncEngine\Rest\Controllers\TaxMapController;
use Estasoft\SyncEngine\Rest\Controllers\ToolsController;
use Estasoft\SyncEngine\Rest\OAuth\OAuthState;
use Estasoft\SyncEngine\Rest\Products\ProductMatchService;
use Estasoft\SyncEngine\Rest\Products\WooProductSource;
use Estasoft\SyncEngine\Rest\Support\ConnectionResolver;
use Estasoft\SyncEngine\Rest\Support\TransientStore;
use Estasoft\SyncEngine\Rest\Tax\ConnectorTaxCodes;
use Estasoft\SyncEngine\Rest\Tax\TaxMapAssembler;
use Estasoft\SyncEngine\Rest\Tax\WooTaxSource;

/**
 * Registers the routes on `rest_api_init` and keeps the few shared services in the container:
 *
 * - rest.store            Support\TransientStore
 * - rest.idempotency      IdempotencyCache
 * - rest.errors           ErrorMapper
 * - rest.resolver         Support\ConnectionResolver
 * - rest.oauth_state      OAuth\OAuthState
 * - rest.tax_map          Tax\TaxMapAssembler
 * - rest.product_matching Products\ProductMatchService
 *
 * Every controller is built when WordPress asks for the routes, which is on each REST request; building one costs nothing because
 * the services a route needs are resolved only when that route runs.
 */
final class RestModule implements ModuleInterface {

	public const STORE            = 'rest.store';
	public const IDEMPOTENCY      = 'rest.idempotency';
	public const ERRORS           = 'rest.errors';
	public const RESOLVER         = 'rest.resolver';
	public const OAUTH_STATE      = 'rest.oauth_state';
	public const TAX_MAP          = 'rest.tax_map';
	public const PRODUCT_MATCHING = 'rest.product_matching';

	/**
	 * The controllers, one per group of routes.
	 *
	 * @var array<int,class-string<BaseController>>
	 */
	public const CONTROLLERS = array(
		StatusController::class,
		QueueController::class,
		SyncController::class,
		ConnectionsController::class,
		CatalogController::class,
		MappingsController::class,
		TaxMapController::class,
		ProductsController::class,
		StockController::class,
		ReconciliationController::class,
		InvoicesController::class,
		LogsController::class,
		ReadinessController::class,
		SupportReportController::class,
		SettingsController::class,
		ToolsController::class,
		CapacityController::class,
		OnboardingController::class,
		ShadowController::class,
		OrdersController::class,
		InboundController::class,
		OAuthController::class,
		ExportsController::class,
	);

	/**
	 * Register services.
	 *
	 * @param Container $container Service container.
	 */
	public function services( Container $container ): void {
		$container->set( self::STORE, static fn(): TransientStore => new TransientStore() );
		$container->set(
			self::IDEMPOTENCY,
			static fn( Container $c ): IdempotencyCache => new IdempotencyCache( $c->get( self::STORE ), wp_salt( 'auth' ) )
		);
		$container->set(
			self::ERRORS,
			static fn( Container $c ): ErrorMapper => new ErrorMapper(
				static function ( string $description ) use ( $c ): void {
					$c->get( Services::LOGGER )->error( 'Unexpected failure in the REST API: ' . $description );
				}
			)
		);
		$container->set(
			self::RESOLVER,
			static fn( Container $c ): ConnectionResolver => new ConnectionResolver( static fn(): array => $c->get( Services::CONNECTION_REPOSITORY )->all() )
		);
		$container->set( self::OAUTH_STATE, static fn( Container $c ): OAuthState => new OAuthState( $c->get( self::STORE ) ) );
		$container->set(
			self::TAX_MAP,
			static fn( Container $c ): TaxMapAssembler => new TaxMapAssembler(
				$c->get( 'mapping.tax_map' ),
				new WooTaxSource( $c->get( self::STORE ) ),
				new ConnectorTaxCodes(
					$c->get( Services::CONNECTION_REPOSITORY ),
					$c->get( Services::CONNECTOR_REGISTRY ),
					static function ( string $exceptionClass ) use ( $c ): void {
						$c->get( Services::LOGGER )->warning( 'The tax codes of the connected system could not be read: ' . $exceptionClass );
					}
				)
			)
		);
		$container->set(
			self::PRODUCT_MATCHING,
			static fn( Container $c ): ProductMatchService => new ProductMatchService(
				new WooProductSource(),
				$c->get( 'mapping.sku_matcher' ),
				$c->get( Services::ID_MAP_REPOSITORY ),
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::CONNECTOR_REGISTRY )
			)
		);
	}

	/**
	 * Add the hooks: route registration, and the check that makes the permission answer come before a complaint about parameters.
	 *
	 * @param Container $container Service container.
	 */
	public function hooks( Container $container ): void {
		add_filter( 'rest_request_before_callbacks', array( $this, 'permissionFirst' ), 10, 3 );
		add_action(
			'rest_api_init',
			function () use ( $container ): void {
				foreach ( $this->controllers( $container ) as $controller ) {
					$controller->registerRoutes();
				}
			}
		);
	}

	/**
	 * WordPress checks the parameters of a request before it asks the route whether the person may call it, so a visitor who is not
	 * signed in and sends a wrong parameter learns the shape of the route from a 400. For this plugin's routes, a failed parameter check is
	 * replaced by the permission answer (401 or 403) when the person may not call the route. The signed inbound route allows everyone,
	 * so it keeps its parameter errors. Filter `rest_request_before_callbacks`.
	 *
	 * @param mixed $response The result so far: a WP_Error when parameter validation failed.
	 * @param mixed $handler  The matched route handler.
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public function permissionFirst( mixed $response, mixed $handler, mixed $request ): mixed {
		if ( ! is_wp_error( $response ) || ! is_array( $handler ) || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 0 !== strpos( $request->get_route(), '/' . BaseController::REST_NAMESPACE . '/' ) || ! is_callable( $handler['permission_callback'] ?? null ) ) {
			return $response;
		}
		$permission = call_user_func( $handler['permission_callback'], $request );
		if ( true === $permission ) {
			return $response;
		}
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to do this.', 'estasoft-erp-crm-sync-engine' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * One instance of every controller.
	 *
	 * @param Container $container Service container.
	 * @return array<int,BaseController>
	 */
	public function controllers( Container $container ): array {
		$controllers = array();
		foreach ( self::CONTROLLERS as $class ) {
			$controllers[] = new $class( $container, $container->get( self::ERRORS ), $container->get( self::IDEMPOTENCY ), $container->get( self::RESOLVER ) );
		}
		return $controllers;
	}
}
