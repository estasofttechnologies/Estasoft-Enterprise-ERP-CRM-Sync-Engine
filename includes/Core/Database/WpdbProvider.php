<?php
/**
 * Access to the WordPress database object.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that reads the `$wpdb` global. Everything else receives the database object through its constructor.
 */
final class WpdbProvider {

	/**
	 * The database object of the current site.
	 */
	public function get(): \wpdb {
		global $wpdb;
		return $wpdb;
	}
}
