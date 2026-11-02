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
 * request by an administrator uses the flag up and goes to `#/setup`. A bulk or network activation, WP-CLI, cron and AJAX never get
 * here with the flag set, and this class checks the same conditions again because the flag is only a hint.
 */
final class Welcome {

	/**
	 * @param Urls $urls Addresses of the app.
	 */
	public function __construct( private readonly Urls $urls ) {
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
		if ( $this->excluded() ) {
			return;
		}
		delete_transient( Installer::WELCOME_TRANSIENT );
		if ( ! Capabilities::userCanConfigure() ) {
			return;
		}
		// The caller's page carries on after this and the browser follows the Location header first; core's own activation screen does the same.
		wp_safe_redirect( $this->urls->setup(), 302, 'ESTASOFT SYNC' );
	}

	/**
	 * Requests that must not be redirected: AJAX, cron, WP-CLI, the network admin and bulk activation.
	 */
	private function excluded(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || is_network_admin() ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only tests for the presence of a core query argument.
		return isset( $_GET['activate-multi'] );
	}
}
