<?php
/**
 * Opens the setup guide once after activation (ONB-2).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\Installer;

/**
 * Activation sets a one-minute flag (Core\Installer::WELCOME_TRANSIENT) only for a single-site, interactive activation. The next admin
 * request by an administrator uses the flag up and goes to `#/setup`. A bulk or network activation, WP-CLI, cron, AJAX and
 * `admin-post.php` never get here with the flag set, and this class checks the same conditions again because the flag is only a hint.
 * The flag is used up only by a person who can use it: a request by someone who may not configure leaves it for the administrator.
 *
 * After `wp_safe_redirect()` the request ends with `exit`, as WordPress core does for its own redirects, so nothing else in the
 * admin request can send output or a second redirect. The `exit` is behind a closure only so that a test can replace it; the scan in
 * bin/red-flags.php allows it for this file in bin/red-flags-allowlist.txt.
 */
final class Welcome {

	/**
	 * Ends the request after the redirect.
	 *
	 * @var \Closure():void
	 */
	private \Closure $terminate;

	/**
	 * Create the welcome redirect.
	 *
	 * @param Urls          $urls      Addresses of the app.
	 * @param \Closure|null $terminate Ends the request after a redirect; `exit` by default. Tests pass their own.
	 */
	public function __construct( private readonly Urls $urls, ?\Closure $terminate = null ) {
		$this->terminate = $terminate ?? static function (): void {
			exit;
		};
	}

	/**
	 * Add the hook.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybeRedirect' ), 5 );
	}

	/**
	 * Redirect when the flag is set and the request qualifies. Runs on `admin_init`.
	 */
	public function maybeRedirect(): void {
		if ( ! get_transient( Installer::WELCOME_TRANSIENT ) ) {
			return;
		}
		if ( $this->excluded() || ! Capabilities::userCanConfigure() ) {
			return; // The flag stays for the administrator who opens an admin page next.
		}
		delete_transient( Installer::WELCOME_TRANSIENT );
		wp_safe_redirect( $this->urls->setup(), 302, 'ESTASOFT SYNC' );
		( $this->terminate )();
	}

	/**
	 * Requests that must not be redirected: AJAX, `admin-post.php` (form handlers answer with their own redirect), cron, WP-CLI,
	 * the network admin and bulk activation.
	 */
	private function excluded(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || is_network_admin() ) {
			return true;
		}
		$page = isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		if ( in_array( $page, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only tests for the presence of a core query argument.
		return isset( $_GET['activate-multi'] );
	}
}
