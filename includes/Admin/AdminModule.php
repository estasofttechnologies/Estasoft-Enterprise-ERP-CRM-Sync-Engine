<?php
/**
 * Admin module: the WordPress pieces around the React app.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\ModuleInterface;
use Estasoft\SyncEngine\Rest\RestModule;

/**
 * PHP renders only what WordPress requires: the menu entry and its mount point, the script and style loading, the admin bar item, the
 * dashboard box, notices on other screens, Help and Screen Options, the Plugins screen links and the one-time welcome redirect. The
 * React app does everything else.
 *
 * Container keys set by this module:
 *
 * - admin.urls            Urls
 * - admin.snapshot        Snapshot
 * - admin.notice_facts    NoticeFacts
 * - admin.screen_options  ScreenOptions (also the constant ScreenOptions::SERVICE)
 * - admin.boot_data       BootData
 * - admin.assets          Assets
 * - admin.menu            Menu
 * - admin.admin_bar       AdminBar
 * - admin.widget          DashboardWidget
 * - admin.notices         Notices
 * - admin.help            Help
 * - admin.plugin_links    PluginLinks
 * - admin.welcome         Welcome
 *
 * The hooks are added on admin requests only (which include admin-post.php and admin-ajax.php).
 */
final class AdminModule implements ModuleInterface {

	public const URLS         = 'admin.urls';
	public const SNAPSHOT     = 'admin.snapshot';
	public const NOTICE_FACTS = 'admin.notice_facts';
	public const BOOT_DATA    = 'admin.boot_data';
	public const ASSETS       = 'admin.assets';
	public const MENU         = 'admin.menu';
	public const ADMIN_BAR    = 'admin.admin_bar';
	public const WIDGET       = 'admin.widget';
	public const NOTICES      = 'admin.notices';
	public const HELP         = 'admin.help';
	public const PLUGIN_LINKS = 'admin.plugin_links';
	public const WELCOME      = 'admin.welcome';

	/**
	 * Register services.
	 *
	 * @param Container $container Service container.
	 */
	public function services( Container $container ): void {
		$container->set( self::URLS, static fn(): Urls => new Urls( admin_url( 'admin.php?page=' . Urls::PAGE ) ) );
		$container->set( ScreenOptions::SERVICE, static fn(): ScreenOptions => new ScreenOptions() );
		$container->set(
			self::SNAPSHOT,
			static fn( Container $c ): Snapshot => new Snapshot(
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::ONBOARDING ),
				$c->get( Services::CLOCK ),
				$c->get( RestModule::STORE )
			)
		);
		$container->set(
			self::NOTICE_FACTS,
			static fn( Container $c ): NoticeFacts => new NoticeFacts(
				$c->get( self::SNAPSHOT ),
				$c->get( Services::SECRET_BOX ),
				$c->get( Services::SETTINGS ),
				$c->get( Services::ONBOARDING ),
				$c->get( Services::CLOCK )
			)
		);
		$container->set(
			self::BOOT_DATA,
			static fn( Container $c ): BootData => new BootData(
				$c->get( self::NOTICE_FACTS ),
				$c->get( ScreenOptions::SERVICE ),
				defined( 'ESTASOFT_SYNC_VERSION' ) ? ESTASOFT_SYNC_VERSION : '',
				defined( 'ESTASOFT_SYNC_URL' ) ? ESTASOFT_SYNC_URL : ''
			)
		);
		$container->set(
			self::ASSETS,
			static fn( Container $c ): Assets => new Assets(
				defined( 'ESTASOFT_SYNC_DIR' ) ? ESTASOFT_SYNC_DIR : '',
				defined( 'ESTASOFT_SYNC_URL' ) ? ESTASOFT_SYNC_URL : '',
				defined( 'ESTASOFT_SYNC_VERSION' ) ? ESTASOFT_SYNC_VERSION : '',
				static fn(): string => $c->get( self::MENU )->hookSuffix(),
				static fn(): array => $c->get( self::BOOT_DATA )->build(),
				static fn(): bool => $c->get( self::ADMIN_BAR )->shows()
			)
		);
		$container->set(
			self::MENU,
			static fn( Container $c ): Menu => new Menu(
				$c->get( self::ASSETS ),
				static fn(): int => (int) $c->get( JobServices::DASHBOARD )->failedCount()
			)
		);
		$container->set(
			self::ADMIN_BAR,
			static fn( Container $c ): AdminBar => new AdminBar( $c->get( self::SNAPSHOT ), $c->get( Services::SETTINGS ), $c->get( self::URLS ) )
		);
		$container->set(
			self::WIDGET,
			static function ( Container $c ): DashboardWidget {
				$data = new WidgetData( static fn(): object => $c->get( JobServices::DASHBOARD ), static fn(): object => $c->get( JobServices::CONNECTIONS ) );
				return new DashboardWidget(
					$data( ... ),
					$c->get( self::URLS ),
					static fn( string $iso ): string => (string) wp_date( (string) get_option( 'time_format', 'H:i' ), (int) strtotime( $iso ) )
				);
			}
		);
		$container->set(
			self::NOTICES,
			static fn( Container $c ): Notices => new Notices(
				$c->get( self::NOTICE_FACTS ),
				new NoticeRules(),
				$c->get( self::URLS ),
				static function () use ( $c ): bool {
					$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
					$hook   = $c->get( self::MENU )->hookSuffix();
					return null !== $screen && '' !== $hook && $screen->id === $hook;
				}
			)
		);
		$container->set( self::HELP, static fn( Container $c ): Help => new Help( $c->get( self::URLS ) ) );
		$container->set(
			self::PLUGIN_LINKS,
			static fn( Container $c ): PluginLinks => new PluginLinks( plugin_basename( defined( 'ESTASOFT_SYNC_FILE' ) ? ESTASOFT_SYNC_FILE : '' ), $c->get( self::URLS ) )
		);
		$container->set( self::WELCOME, static fn( Container $c ): Welcome => new Welcome( $c->get( self::URLS ) ) );
	}

	/**
	 * Add hooks on admin requests.
	 *
	 * @param Container $container Service container.
	 */
	public function hooks( Container $container ): void {
		if ( ! is_admin() ) {
			return;
		}
		$this->addAdminHooks( $container );
	}

	/**
	 * Add every admin hook. Separate from hooks() so the integration tests can call it without a real admin request.
	 *
	 * @param Container $container Service container.
	 */
	public function addAdminHooks( Container $container ): void {
		$menu = $container->get( self::MENU );
		$menu->onLoad( array( $container->get( self::HELP ), 'load' ) );
		$menu->onLoad( array( $container->get( ScreenOptions::SERVICE ), 'load' ) );
		$menu->register();

		$container->get( ScreenOptions::SERVICE )->register();
		$container->get( self::ASSETS )->register();
		$container->get( self::ADMIN_BAR )->register();
		$container->get( self::WIDGET )->register();
		$container->get( self::NOTICES )->register();
		$container->get( self::PLUGIN_LINKS )->register();
		$container->get( self::WELCOME )->register();
	}
}
