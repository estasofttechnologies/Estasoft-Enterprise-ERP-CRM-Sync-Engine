<?php
/**
 * The logo shown next to a system on the Choose a system screen.
 *
 * A logo is a 160 px square PNG in `assets/logos/`, named after the connector id (`xero.png`). The file is the
 * vendor's own artwork, shown only to say which system a row is about. A system with no file keeps its letter tile, so
 * leaving a file out (or deleting the folder) is all it takes to stop showing a logo. See docs/release/LOGOS.md.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the logo file of a connector.
 */
final class Logos {

	/**
	 * Folder of the files, relative to the plugin folder.
	 */
	public const DIRECTORY = 'assets/logos/';

	/**
	 * Connectors that use the file of another one (the products of one vendor share its logo).
	 *
	 * @var array<string,string>
	 */
	private const SAME_AS = array(
		'sage200' => 'sage-accounting',
		'sage300' => 'sage-accounting',
		'sagex3'  => 'sage-accounting',
	);

	/**
	 * The address of the logo of a connector, or an empty string when there is none.
	 *
	 * @param string $connector Connector id, for example `xero`.
	 * @param string $dir       Plugin folder with a trailing slash.
	 * @param string $url       Plugin address with a trailing slash.
	 */
	public static function urlFor( string $connector, string $dir, string $url ): string {
		$file = self::fileFor( $connector );
		if ( '' === $file || ! is_file( $dir . self::DIRECTORY . $file ) ) {
			return '';
		}
		return $url . self::DIRECTORY . $file;
	}

	/**
	 * The file name a connector's logo would have, or an empty string for an id that cannot be a file name.
	 *
	 * @param string $connector Connector id.
	 */
	public static function fileFor( string $connector ): string {
		$id = self::SAME_AS[ $connector ] ?? $connector;
		return 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id ) ? $id . '.png' : '';
	}
}
