<?php
/**
 * Scripts and styles of the admin app, and the small stylesheet for the admin bar and the dashboard widget.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The app (`build/index.js` and `build/index.css`, with the dependencies and version that @wordpress/scripts writes to
 * `build/index.asset.php`) loads only on the plugin's own page. The only inline script is the boot data, `window.estasoftSync`,
 * printed before the app with wp_add_inline_script. A small stylesheet loads on the screens that show the admin bar item or the
 * dashboard widget.
 */
final class Assets {

	/**
	 * Script and style handle of the app.
	 */
	public const HANDLE = 'estasoft-sync-admin';

	/**
	 * Style handle of the admin bar and widget styles.
	 */
	public const CHROME_HANDLE = 'estasoft-sync-admin-chrome';

	/**
	 * Create the asset loader.
	 *
	 * @param string   $dir      Plugin directory with a trailing slash.
	 * @param string   $url      Plugin address with a trailing slash.
	 * @param string   $version  Plugin version, used when the asset file is missing.
	 * @param \Closure $pageHook Returns the hook suffix of the plugin's page (empty before the menu is built).
	 * @param \Closure $boot     Builds the boot data.
	 * @param \Closure $barShows Returns whether the admin bar item shows for this person.
	 *
	 * @phpstan-param \Closure():string              $pageHook
	 * @phpstan-param \Closure():array<string,mixed> $boot
	 * @phpstan-param \Closure():bool                $barShows
	 */
	public function __construct(
		private readonly string $dir,
		private readonly string $url,
		private readonly string $version,
		private readonly \Closure $pageHook,
		private readonly \Closure $boot,
		private readonly \Closure $barShows
	) {
	}

	/**
	 * Add the hook.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the compiled app exists.
	 */
	public function bundleAvailable(): bool {
		return is_readable( $this->dir . 'build/index.js' ) && is_readable( $this->dir . 'build/index.asset.php' );
	}

	/**
	 * Load what the current screen needs. Runs on `admin_enqueue_scripts`.
	 *
	 * @param string $hookSuffix Hook suffix of the current screen.
	 */
	public function enqueue( string $hookSuffix ): void {
		if ( ( $this->barShows )() || 'index.php' === $hookSuffix ) {
			wp_enqueue_style( self::CHROME_HANDLE, $this->url . 'assets/css/admin-chrome.css', array(), $this->version );
		}
		$page = ( $this->pageHook )();
		if ( '' === $page || $hookSuffix !== $page || ! $this->bundleAvailable() ) {
			return;
		}
		$asset = include $this->dir . 'build/index.asset.php';
		$asset = is_array( $asset ) ? $asset : array();
		$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? array_map( 'strval', $asset['dependencies'] ) : array();
		$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : $this->version;

		wp_register_script( self::HANDLE, $this->url . 'build/index.js', $deps, $ver, true );
		wp_set_script_translations( self::HANDLE, 'estasoft-enterprise-erpcrm-sync-engine', $this->dir . 'languages' );
		wp_add_inline_script( self::HANDLE, 'window.estasoftSync = ' . self::encode( ( $this->boot )() ) . ';', 'before' );
		wp_enqueue_script( self::HANDLE );

		wp_enqueue_style( self::HANDLE, $this->url . 'build/index.css', array( 'wp-components' ), $ver );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * JSON that is safe inside a script element: angle brackets, ampersands and quotes are written as escapes, so no value can
	 * close the element or start a comment.
	 *
	 * @param array<string,mixed> $data Boot data.
	 */
	public static function encode( array $data ): string {
		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		return false === $json ? '{}' : $json;
	}
}
