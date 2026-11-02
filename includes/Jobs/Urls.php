<?php
/**
 * Links from Jobs output to the admin app and to WordPress screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;

/**
 * The admin app lives on one wp-admin page and uses hash routes, so a link into it is either the route alone
 * (for example `/settings/performance`, which the app opens without a page load) or the full admin URL plus the hash (for
 * e-mail). Links to WordPress screens come from the environment.
 */
final class Urls {

	/**
	 * Slug of the admin page that hosts the app.
	 */
	public const PAGE = 'estasoft-sync';

	/**
	 * Set up with what it depends on.
	 *
	 * @param ShopEnvironment $environment Shop and server facts.
	 */
	public function __construct( private readonly ShopEnvironment $environment ) {
	}

	/**
	 * A route inside the app, for links shown inside the app.
	 *
	 * @param string $route Route such as /queue or /settings/performance.
	 */
	public function route( string $route ): string {
		return '/' . ltrim( $route, '/' );
	}

	/**
	 * The full admin URL of a route inside the app, for e-mail.
	 *
	 * @param string $route Route such as /queue.
	 */
	public function app( string $route = '' ): string {
		$base = $this->environment->adminUrl( 'admin.php?page=' . self::PAGE );
		return '' === $route ? $base : $base . '#/' . ltrim( $route, '/' );
	}

	/**
	 * The product list of WooCommerce.
	 */
	public function products(): string {
		return $this->environment->adminUrl( 'edit.php?post_type=product' );
	}

	/**
	 * The users list filtered to customers.
	 */
	public function customers(): string {
		return $this->environment->adminUrl( 'users.php?role=customer' );
	}

	/**
	 * The WooCommerce general settings screen.
	 */
	public function wooSettings(): string {
		return $this->environment->adminUrl( 'admin.php?page=wc-settings' );
	}
}
