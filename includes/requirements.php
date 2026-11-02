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
 * Return the first unmet requirement as a short sentence, or an empty string when all are met.
 *
 * @return string
 */
function estasoft_sync_missing_requirement( $include_woocommerce = true ) {
	if ( version_compare( PHP_VERSION, ESTASOFT_SYNC_MIN_PHP, '<' ) ) {
		/* translators: 1: required PHP version, 2: current PHP version. */
		return sprintf( __( 'PHP %1$s or newer is required. This site runs PHP %2$s.', 'estasoft-erp-crm-sync-engine' ), ESTASOFT_SYNC_MIN_PHP, PHP_VERSION );
	}
	if ( version_compare( get_bloginfo( 'version' ), ESTASOFT_SYNC_MIN_WP, '<' ) ) {
		/* translators: %s: required WordPress version. */
		return sprintf( __( 'WordPress %s or newer is required.', 'estasoft-erp-crm-sync-engine' ), ESTASOFT_SYNC_MIN_WP );
	}
	if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
		return __( 'The PHP sodium extension is required to keep credentials encrypted.', 'estasoft-erp-crm-sync-engine' );
	}
	if ( $include_woocommerce ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'WooCommerce must be installed and active.', 'estasoft-erp-crm-sync-engine' );
		}
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, ESTASOFT_SYNC_MIN_WC, '<' ) ) {
			/* translators: %s: required WooCommerce version. */
			return sprintf( __( 'WooCommerce %s or newer is required.', 'estasoft-erp-crm-sync-engine' ), ESTASOFT_SYNC_MIN_WC );
		}
	}
	return '';
}

/**
 * Show an admin notice that names the missing requirement.
 *
 * @param string $message Sentence from estasoft_sync_missing_requirement().
 * @return void
 */
function estasoft_sync_requirement_notice( $message ) {
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Estasoft Enterprise ERP/CRM Sync Engine is not running.', 'estasoft-erp-crm-sync-engine' ),
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
	$message = estasoft_sync_missing_requirement( true );
	if ( '' !== $message ) {
		estasoft_sync_requirement_notice( $message );
		return;
	}
	Estasoft\SyncEngine\Plugin::instance()->boot();
}
