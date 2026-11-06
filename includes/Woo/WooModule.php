<?php
/**
 * WooCommerce module: events into the queue, order and product meta, and the small server-rendered screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Mapping\Adapters\CoreIdMapStore;
use Estasoft\SyncEngine\Mapping\Adapters\CoreEventLog;
use Estasoft\SyncEngine\ModuleInterface;
use Estasoft\SyncEngine\Woo\Adapters\CoreConnectionInfo;
use Estasoft\SyncEngine\Woo\Adapters\CoreGoLiveInfo;
use Estasoft\SyncEngine\Woo\Adapters\CoreQueueGateway;
use Estasoft\SyncEngine\Woo\Adapters\CoreSendChoices;

defined( 'ABSPATH' ) || exit;

/**
 * Container keys set by this module:
 *
 * - woo.order_reader      WooOrderReader (the canonical source the mapping module reads orders through)
 * - woo.order_meta        OrderMeta
 * - woo.product_meta      ProductMeta
 * - woo.eligibility       OrderEligibility (drafts and excluded products)
 * - woo.go_live           GoLive (was an order placed after go-live?), over Ports\GoLiveInfo and the core onboarding state
 * - woo.send_choices      Ports\SendChoices (what was chosen in setup: send orders, send when, send refunds), over the core onboarding state
 * - woo.queue_gateway     Ports\QueueGateway over the core queue
 * - woo.state_mirror      StateMirror (copies queue state onto orders)
 * - woo.order_hooks       OrderHooks
 * - woo.order_actions     OrderActions
 *
 * Every hook is added only when WooCommerce is loaded, and every callback checks for the WooCommerce functions it uses, so the plugin
 * cannot break a site where WooCommerce is deactivated or still loading.
 */
final class WooModule implements ModuleInterface {

	/**
	 * Register services.
	 *
	 * @param Container $container Service container.
	 */
	public function services( Container $container ): void {
		$container->set( 'woo.product_meta', static fn (): ProductMeta => new ProductMeta() );
		$container->set( 'woo.order_meta', static fn (): OrderMeta => new OrderMeta() );
		$container->set( 'woo.chips', static fn (): StateChip => new StateChip() );
		$container->set( 'woo.times', static fn ( Container $c ): TimeLabel => new TimeLabel( $c->get( Services::CLOCK ) ) );
		$container->set( 'woo.eligibility', static fn ( Container $c ): OrderEligibility => new OrderEligibility( $c->get( 'woo.product_meta' ) ) );
		$container->set( 'woo.go_live', static fn ( Container $c ): GoLive => new GoLive( new CoreGoLiveInfo( $c->get( Services::ONBOARDING ) ) ) );
		$container->set( 'woo.send_choices', static fn ( Container $c ): CoreSendChoices => new CoreSendChoices( $c->get( Services::ONBOARDING ) ) );
		$container->set( 'woo.order_reader', static fn ( Container $c ): WooOrderReader => new WooOrderReader( $c->get( 'woo.product_meta' ) ) );

		$container->set(
			'woo.queue_gateway',
			static fn ( Container $c ): CoreQueueGateway => new CoreQueueGateway( static fn ( string $key ): object => $c->get( $key ) )
		);
		$container->set(
			'woo.connection_info',
			static fn ( Container $c ): CoreConnectionInfo => new CoreConnectionInfo(
				$c->get( Services::CONNECTION_REPOSITORY ),
				static fn () => $c->get( Services::CONNECTOR_REGISTRY )
			)
		);
		$container->set(
			'woo.log',
			static fn ( Container $c ): CoreEventLog => new CoreEventLog( $c->get( Services::LOGGER ) )
		);
		$container->set(
			'woo.state_mirror',
			static fn ( Container $c ): StateMirror => new StateMirror( $c->get( 'woo.order_meta' ), $c->get( 'woo.queue_gateway' ), $c->get( 'woo.log' ) )
		);
		$container->set(
			'woo.order_actions',
			static fn ( Container $c ): OrderActions => new OrderActions( $c->get( 'woo.queue_gateway' ), $c->get( 'woo.state_mirror' ), $c->get( 'woo.eligibility' ) )
		);
		$container->set(
			'woo.order_hooks',
			static fn ( Container $c ): OrderHooks => new OrderHooks(
				$c->get( 'woo.queue_gateway' ),
				$c->get( 'woo.order_meta' ),
				$c->get( 'woo.eligibility' ),
				$c->get( 'woo.state_mirror' ),
				$c->get( 'woo.log' ),
				$c->get( 'woo.go_live' ),
				$c->get( 'woo.send_choices' )
			)
		);
	}

	/**
	 * Add hooks.
	 *
	 * @param Container $container Service container.
	 */
	public function hooks( Container $container ): void {
		$compatibility = new Compatibility( defined( 'ESTASOFT_SYNC_FILE' ) ? ESTASOFT_SYNC_FILE : '' );
		$compatibility->register();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$container->get( 'woo.order_hooks' )->register();
		$container->get( 'woo.state_mirror' )->register();
		( new QueueDisplay() )->register();
		( new ProductMatchRecorder( $container->get( 'woo.product_meta' ) ) )->register();

		// Core removes the keys listed by MetaKeys::product() on uninstall; this adds the one this module writes.
		add_filter(
			'estasoft_sync_meta_keys',
			static function ( mixed $keys, mixed $kind = '' ): mixed {
				return 'product' === $kind && is_array( $keys ) ? array_merge( $keys, ProductMeta::EXTRA_KEYS ) : $keys;
			},
			10,
			2
		);

		if ( is_admin() ) {
			$this->adminHooks( $container );
		}
	}

	/**
	 * Hooks that only matter in the admin.
	 *
	 * @param Container $container Service container.
	 */
	private function adminHooks( Container $container ): void {
		$manage = Capabilities::MANAGE;
		$chips  = $container->get( 'woo.chips' );
		$times  = $container->get( 'woo.times' );
		$page   = admin_url( 'admin.php?page=estasoft-sync' );

		( new ActionNotices( $manage ) )->register();
		( new Assets( defined( 'ESTASOFT_SYNC_URL' ) ? ESTASOFT_SYNC_URL : '', defined( 'ESTASOFT_SYNC_VERSION' ) ? ESTASOFT_SYNC_VERSION : '' ) )->register();

		( new OrderBox(
			$container->get( 'woo.queue_gateway' ),
			$container->get( 'woo.order_meta' ),
			$container->get( 'woo.eligibility' ),
			$container->get( 'woo.go_live' ),
			$container->get( 'woo.order_actions' ),
			$container->get( 'woo.connection_info' ),
			$chips,
			$times,
			$manage,
			$page
		) )->register();

		( new OrdersList(
			$container->get( 'woo.order_meta' ),
			$container->get( 'woo.order_actions' ),
			$chips,
			$manage
		) )->register();

		( new ProductBox(
			$container->get( 'woo.product_meta' ),
			$container->get( 'woo.connection_info' ),
			$chips,
			$times,
			$manage,
			static fn (): int => (int) apply_filters( 'estasoft_sync_stock_buffer', 0 )
		) )->register();

		( new CustomerLink(
			new CoreIdMapStore( $container->get( Services::ID_MAP_REPOSITORY ) ),
			$container->get( 'woo.connection_info' ),
			$manage
		) )->register();
	}
}
