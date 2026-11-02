<?php
/**
 * Settings and Setup guide links on the Plugins screen (UI-10).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Capabilities;

/**
 * The first place people look. The links go to the app's own routes and show only to people who can open them.
 */
final class PluginLinks {

	/**
	 * Create the plugin row links.
	 *
	 * @param string $basename Plugin basename, for example estasoft-erp-crm-sync-engine/estasoft-erp-crm-sync-engine.php.
	 * @param Urls   $urls     Addresses of the app.
	 */
	public function __construct(
		private readonly string $basename,
		private readonly Urls $urls
	) {
	}

	/**
	 * Add the filter.
	 */
	public function register(): void {
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'links' ) );
	}

	/**
	 * Put Settings and Setup guide before the links WordPress adds.
	 *
	 * @param mixed $links Links so far.
	 * @return array<int|string,string>
	 */
	public function links( mixed $links ): array {
		$links = is_array( $links ) ? $links : array();
		$mine  = array();
		if ( Capabilities::userCanManage() ) {
			$mine['estasoft-sync-settings'] = '<a href="' . esc_url( $this->urls->settings() ) . '">' . esc_html__( 'Settings', 'estasoft-erp-crm-sync-engine' ) . '</a>';
		}
		if ( Capabilities::userCanConfigure() ) {
			$mine['estasoft-sync-setup'] = '<a href="' . esc_url( $this->urls->setup() ) . '">' . esc_html__( 'Setup guide', 'estasoft-erp-crm-sync-engine' ) . '</a>';
		}
		return array_merge( $mine, $links );
	}
}
