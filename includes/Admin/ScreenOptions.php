<?php
/**
 * Screen Options: rows per page, saved for each person.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress shows a "Number of items per page" field under Screen Options and saves it as a user option through the
 * `set_screen_option_{option}` filter. The queue accepts 25, 50 or 100 rows, so the saved number is moved to the nearest of those.
 * The queue route uses the saved number when a request does not name a page size, and the boot data hands it to the app.
 */
final class ScreenOptions {

	/**
	 * Container key.
	 */
	public const SERVICE = 'admin.screen_options';

	/**
	 * User option that holds the page size.
	 */
	public const OPTION = 'estasoft_sync_per_page';

	/**
	 * Page sizes the queue offers.
	 */
	public const SIZES = array( 25, 50, 100 );

	/**
	 * Add the filter that saves the option.
	 */
	public function register(): void {
		add_filter( 'set_screen_option_' . self::OPTION, array( $this, 'save' ), 10, 3 );
	}

	/**
	 * Show the page size field on the plugin screen. Runs on `load-{screen}`.
	 */
	public function load(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Items per page', 'estasoft-erp-crm-sync-engine' ),
				'default' => self::SIZES[0],
				'option'  => self::OPTION,
			)
		);
	}

	/**
	 * Filter `set_screen_option_{option}`: the value to save.
	 *
	 * @param mixed  $status Value so far (false when nothing decided).
	 * @param string $option Option name.
	 * @param mixed  $value  Number typed by the person.
	 * @return mixed
	 */
	public function save( mixed $status, string $option, mixed $value ): mixed {
		unset( $status, $option );
		return $this->nearest( $value );
	}

	/**
	 * The saved page size of a person.
	 *
	 * @param int $userId User id; 0 for the current user.
	 */
	public function perPage( int $userId = 0 ): int {
		$value = get_user_option( self::OPTION, $userId );
		return in_array( (int) $value, self::SIZES, true ) ? (int) $value : self::SIZES[0];
	}

	/**
	 * The allowed size closest to a number.
	 *
	 * @param mixed $value Candidate.
	 */
	public function nearest( mixed $value ): int {
		$number  = is_numeric( $value ) ? (int) $value : self::SIZES[0];
		$closest = self::SIZES[0];
		foreach ( self::SIZES as $size ) {
			if ( abs( $size - $number ) < abs( $closest - $number ) ) {
				$closest = $size;
			}
		}
		return $closest;
	}
}
