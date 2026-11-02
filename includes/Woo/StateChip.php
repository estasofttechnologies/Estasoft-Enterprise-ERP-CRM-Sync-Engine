<?php
/**
 * The sync state as a small label: an icon and a word, never colour alone.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Markup shared by the order box, the orders list and the product box. Styles are in assets/css/woo-boxes.css.
 */
final class StateChip {

	/**
	 * The word for a state.
	 *
	 * @param string $state queued, syncing, synced, retrying, failed, skipped, shadow, not_sent, excluded, matched, unmatched, no_sku, unchecked.
	 */
	public function label( string $state ): string {
		return match ( $state ) {
			'queued'    => __( 'Queued', 'estasoft-erp-crm-sync-engine' ),
			'syncing'   => __( 'Syncing', 'estasoft-erp-crm-sync-engine' ),
			'synced'    => __( 'Synced', 'estasoft-erp-crm-sync-engine' ),
			'retrying'  => __( 'Retrying', 'estasoft-erp-crm-sync-engine' ),
			'failed'    => __( 'Failed', 'estasoft-erp-crm-sync-engine' ),
			'skipped'   => __( 'Skipped', 'estasoft-erp-crm-sync-engine' ),
			'shadow'    => __( 'Shadow mode', 'estasoft-erp-crm-sync-engine' ),
			'excluded'  => __( 'Excluded', 'estasoft-erp-crm-sync-engine' ),
			'matched'   => __( 'Matched', 'estasoft-erp-crm-sync-engine' ),
			'unmatched' => __( 'Not matched', 'estasoft-erp-crm-sync-engine' ),
			'no_sku'    => __( 'No SKU', 'estasoft-erp-crm-sync-engine' ),
			'unchecked' => __( 'Not checked yet', 'estasoft-erp-crm-sync-engine' ),
			default     => __( 'Not sent', 'estasoft-erp-crm-sync-engine' ),
		};
	}

	/**
	 * The label as escaped HTML.
	 *
	 * @param string $state  State.
	 * @param string $suffix Optional text after the word, such as a time (escaped here).
	 */
	public function html( string $state, string $suffix = '' ): string {
		$modifier = sanitize_html_class( $this->modifier( $state ) );
		return sprintf(
			'<span class="estasoft-sync-chip estasoft-sync-chip--%1$s"><span class="estasoft-sync-chip__icon" aria-hidden="true">%2$s</span><span class="estasoft-sync-chip__word">%3$s</span>%4$s</span>',
			esc_attr( $modifier ),
			esc_html( $this->icon( $state ) ),
			esc_html( $this->label( $state ) ),
			'' === $suffix ? '' : ' <span class="estasoft-sync-chip__extra">' . esc_html( $suffix ) . '</span>'
		);
	}

	/**
	 * The CSS modifier: matched and synced share a look, so do the neutral states.
	 *
	 * @param string $state State.
	 */
	private function modifier( string $state ): string {
		return match ( $state ) {
			'matched'              => 'synced',
			'unmatched', 'no_sku'  => 'failed',
			'unchecked', 'excluded', 'skipped' => 'neutral',
			'shadow'               => 'syncing',
			'queued'               => 'queued',
			'syncing', 'retrying', 'synced', 'failed' => $state,
			default                => 'neutral',
		};
	}

	/**
	 * A plain symbol for a state (decorative; the word carries the meaning).
	 *
	 * @param string $state State.
	 */
	private function icon( string $state ): string {
		return match ( $state ) {
			'synced', 'matched'             => "\u{2713}",
			'failed', 'unmatched', 'no_sku' => "\u{2715}",
			'retrying', 'syncing'           => "\u{21BB}",
			'queued'                        => "\u{25CB}",
			'shadow'                        => "\u{25D0}",
			default                         => "\u{2212}",
		};
	}
}
