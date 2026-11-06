<?php
/**
 * Requirements guard (ONB-1).
 *
 * This file must stay parseable by very old PHP versions: no type declarations, no namespaces,
 * no short arrays. It decides whether the rest of the plugin may load at all.
 *
 * @package Estasoft\SyncEngine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return a code for the first unmet requirement, or an empty string when all are met.
 *
 * Nothing is translated here: this runs on plugins_loaded and while the main file is read, both before WordPress may load
 * translations (WordPress 6.7 and later report a translation loaded before init). The codes are php, wp, sodium,
 * woocommerce and woocommerce-version.
 *
 * @param bool $include_woocommerce Whether WooCommerce has to be active too.
 * @return string
 */
function estasoft_sync_unmet_requirement( $include_woocommerce = true ) {
	if ( version_compare( PHP_VERSION, ESTASOFT_SYNC_MIN_PHP, '<' ) ) {
		return 'php';
	}
	if ( version_compare( get_bloginfo( 'version' ), ESTASOFT_SYNC_MIN_WP, '<' ) ) {
		return 'wp';
	}
	if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
		return 'sodium';
	}
	if ( $include_woocommerce ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return 'woocommerce';
		}
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, ESTASOFT_SYNC_MIN_WC, '<' ) ) {
			return 'woocommerce-version';
		}
	}
	return '';
}

/**
 * Return the first unmet requirement as a short sentence, or an empty string when all are met.
 *
 * Translates, so call it on init or later (for example while an admin notice is printed).
 *
 * @param bool $include_woocommerce Whether WooCommerce has to be active too.
 * @return string
 */
function estasoft_sync_missing_requirement( $include_woocommerce = true ) {
	switch ( estasoft_sync_unmet_requirement( $include_woocommerce ) ) {
		case 'php':
			/* translators: 1: required PHP version, 2: current PHP version. */
			return sprintf( __( 'PHP %1$s or newer is required. This site runs PHP %2$s.', 'estasoft-enterprise-erpcrm-sync-engine' ), ESTASOFT_SYNC_MIN_PHP, PHP_VERSION );
		case 'wp':
			/* translators: %s: required WordPress version. */
			return sprintf( __( 'WordPress %s or newer is required.', 'estasoft-enterprise-erpcrm-sync-engine' ), ESTASOFT_SYNC_MIN_WP );
		case 'sodium':
			return __( 'The PHP sodium extension is required to keep credentials encrypted.', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'woocommerce':
			return __( 'WooCommerce must be installed and active.', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'woocommerce-version':
			/* translators: %s: required WooCommerce version. */
			return sprintf( __( 'WooCommerce %s or newer is required.', 'estasoft-enterprise-erpcrm-sync-engine' ), ESTASOFT_SYNC_MIN_WC );
	}
	return '';
}

/**
 * Show an admin notice that names the missing requirement.
 *
 * The sentence is worked out when the notice is printed, because translations may not be loaded before then.
 *
 * @param bool $include_woocommerce Whether WooCommerce counts as a requirement (the same value given to estasoft_sync_unmet_requirement()).
 * @return void
 */
function estasoft_sync_requirement_notice( $include_woocommerce = true ) {
	add_action(
		'admin_notices',
		static function () use ( $include_woocommerce ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			$message = estasoft_sync_missing_requirement( $include_woocommerce );
			if ( '' === $message ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Estasoft Enterprise ERP/CRM Sync Engine is not running.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				esc_html( $message )
			);
		}
	);
}

/**
 * Boot the plugin once WooCommerce can be known to be loaded. Plugins load in alphabetical order,
 * so WooCommerce is not available while this plugin's main file is read; plugins_loaded is.
 *
 * @return void
 */
function estasoft_sync_boot() {
	if ( '' !== estasoft_sync_unmet_requirement( true ) ) {
		estasoft_sync_requirement_notice( true );
		return;
	}
	Estasoft\SyncEngine\Plugin::instance()->boot();
}
