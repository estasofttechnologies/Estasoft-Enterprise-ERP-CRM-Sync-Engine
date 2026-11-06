<?php
/**
 * Shows a customer's record in the connected system on the user profile screen.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Mapping\Ports\IdMapStore;
use Estasoft\SyncEngine\Woo\Ports\ConnectionInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a small section to the profile of a customer who has been synced, with a link to the contact in the connected system.
 * It reads the id map; it makes no request to the connected system.
 */
final class CustomerLink {

	/**
	 * Id map type for customers.
	 */
	private const ENTITY = 'customer';

	/**
	 * Set up with what it depends on.
	 *
	 * @param IdMapStore     $idMap      Links between WooCommerce and system ids.
	 * @param ConnectionInfo $connection Connection facts.
	 * @param string         $capability Capability needed to see the section.
	 */
	public function __construct(
		private readonly IdMapStore $idMap,
		private readonly ConnectionInfo $connection,
		private readonly string $capability
	) {
	}

	/**
	 * Add the hooks.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
	}

	/**
	 * Print the section.
	 *
	 * @param mixed $user The user being shown.
	 */
	public function render( mixed $user = null ): void {
		if ( ! current_user_can( $this->capability ) || ! is_object( $user ) || ! isset( $user->ID ) ) {
			return;
		}
		$html = $this->html( (int) $user->ID );
		if ( '' !== $html ) {
			echo wp_kses(
				$html,
				array(
					'h2'    => array(),
					'table' => array(
						'class' => true,
						'role'  => true,
					),
					'tbody' => array(),
					'tr'    => array(),
					'th'    => array( 'scope' => true ),
					'td'    => array( 'dir' => true ),
					'a'     => array(
						'href' => true,
						'rel'  => true,
					),
				)
			);
		}
	}

	/**
	 * The section as HTML, or an empty string when the customer has not been synced.
	 *
	 * @param int $userId User id.
	 */
	public function html( int $userId ): string {
		$connectionId = $this->connection->activeId();
		$link         = null === $connectionId ? null : $this->idMap->get( $connectionId, self::ENTITY, (string) $userId );
		if ( null === $link ) {
			return '';
		}
		$label = '' !== (string) $link['external_ref'] ? (string) $link['external_ref'] : $link['external_id'];
		$value = null === $link['external_url'] || '' === $link['external_url']
			? esc_html( $label )
			: sprintf( '<a href="%1$s" rel="noopener noreferrer">%2$s</a>', esc_url( $link['external_url'] ), esc_html( $label ) );

		/* translators: %s: name of the connected system. */
		$row = sprintf( __( '%s contact', 'estasoft-enterprise-erpcrm-sync-engine' ), $this->connection->systemName( $connectionId ) );
		return sprintf(
			'<h2>%1$s</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row">%2$s</th><td dir="auto">%3$s</td></tr></tbody></table>',
			esc_html__( 'ESTASOFT SYNC', 'estasoft-enterprise-erpcrm-sync-engine' ),
			esc_html( $row ),
			$value
		);
	}
}
