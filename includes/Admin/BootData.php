<?php
/**
 * The `window.estasoftSync` object the app reads (BootData in src/api/types.ts).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Everything the app needs before its first request: where the API is, the REST nonce, what the person may do, the store's time zone
 * and currency, the notices to show, and, when the encryption key constant is missing, the line to add to wp-config.php.
 * Nothing secret goes in: the nonce proves a login that already exists, and the key line is a new random key that is not in use.
 */
final class BootData {

	/**
	 * Routes of the app, for the sections menu and for links.
	 */
	public const ROUTES = array( '/', '/queue', '/invoices', '/connections', '/mapping', '/stock', '/reconciliation', '/logs', '/settings', '/setup', '/shadow' );

	/**
	 * @param NoticeFacts      $facts   Facts the notices depend on.
	 * @param ScreenOptions    $screen  Screen Options (rows per page).
	 * @param string           $version Plugin version.
	 * @param string           $pluginUrl Address of the plugin folder.
	 */
	public function __construct(
		private readonly NoticeFacts $facts,
		private readonly ScreenOptions $screen,
		private readonly string $version,
		private readonly string $pluginUrl
	) {
	}

	/**
	 * The object for the current person.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$userId   = (int) get_current_user_id();
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
		$facts    = $this->facts->gather( $userId );
		$scheme   = (string) get_user_option( 'admin_color' );

		return array(
			'restUrl'           => rest_url( 'estasoft-sync/v1/' ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'adminUrl'          => admin_url(),
			'pluginUrl'         => $this->pluginUrl,
			'version'           => $this->version,
			'capabilities'      => array(
				'manage'    => Capabilities::userCanManage(),
				'configure' => Capabilities::userCanConfigure(),
			),
			'storeTimezone'     => wp_timezone_string(),
			'currency'          => $currency,
			'currencyDecimals'  => Money::exponent( $currency ),
			'locale'            => determine_locale(),
			'wpAdminColorScheme' => '' === $scheme ? 'fresh' : $scheme,
			'routes'            => self::ROUTES,
			'notices'           => ( new NoticeRules() )->forApp( $facts ),
			'encryptionKeyLine' => $facts['key_missing'] ? (string) $facts['key_line'] : null,
			'perPage'           => $this->screen->perPage( $userId ),
		);
	}
}
