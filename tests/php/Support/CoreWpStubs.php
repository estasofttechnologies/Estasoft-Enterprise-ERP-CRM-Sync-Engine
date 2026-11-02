<?php
/**
 * WordPress functions the Core unit tests need that Support/wp-stubs.php does not provide.
 *
 * Separate from wp-stubs.php so agents never edit the same file. Every function is guarded.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Minimal stand-in for is_email().
	 *
	 * @param string $email Candidate.
	 * @return string|false
	 */
	function is_email( $email ) { // phpcs:ignore
		return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? false : $email;
	}
}
