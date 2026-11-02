<?php
/**
 * Addresses of the plugin's admin screen.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The whole admin app lives on one WooCommerce submenu page and uses hash routes, so a link into it is the page address plus
 * `#/route`.
 */
final class Urls {

	/**
	 * Slug of the admin page that hosts the app.
	 */
	public const PAGE = 'estasoft-sync';

	/**
	 * Create the address book.
	 *
	 * @param string $pageUrl Full address of the admin page, `admin.php?page=estasoft-sync`.
	 */
	public function __construct( private readonly string $pageUrl ) {
	}

	/**
	 * The admin page.
	 */
	public function page(): string {
		return $this->pageUrl;
	}

	/**
	 * A route inside the app.
	 *
	 * @param string $route Route such as /queue or /queue?state=failed.
	 */
	public function route( string $route ): string {
		return $this->pageUrl . '#/' . ltrim( $route, '/' );
	}

	/**
	 * The setup guide.
	 */
	public function setup(): string {
		return $this->route( '/setup' );
	}

	/**
	 * The settings screen.
	 */
	public function settings(): string {
		return $this->route( '/settings' );
	}
}
