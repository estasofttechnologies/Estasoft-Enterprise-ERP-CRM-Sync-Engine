<?php
/**
 * Help tabs for the plugin's screen (UI-8, board A06b).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The Help button at the top of the screen opens five tabs: what the queue is, what each state means, how to fix a failed order, how
 * retries and pausing work, and where to get help. The sidebar links to the documentation, the support forum, the issue tracker and the
 * support report. Everything is plain text in the person's language; the links are ordinary links, not requests: nothing is sent.
 */
final class Help {

	/**
	 * Address of the documentation and issue tracker.
	 */
	public const REPOSITORY_URL = 'https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine';

	/**
	 * Address of the support forum.
	 */
	public const FORUM_URL = 'https://wordpress.org/support/plugin/estasoft-erp-crm-sync-engine/';

	/**
	 * @param Urls $urls Addresses of the app.
	 */
	public function __construct( private readonly Urls $urls ) {
	}

	/**
	 * Add the tabs and the sidebar to the current screen. Runs on `load-{page}`.
	 */
	public function load(): void {
		$screen = get_current_screen();
		if ( null === $screen ) {
			return;
		}
		foreach ( $this->tabs() as $id => $tab ) {
			$screen->add_help_tab(
				array(
					'id'      => 'estasoft-sync-' . $id,
					'title'   => $tab['title'],
					'content' => $tab['content'],
				)
			);
		}
		$screen->set_help_sidebar( $this->sidebar() );
	}

	/**
	 * The tabs, in order. The content is HTML built from escaped text.
	 *
	 * @return array<string,array{title:string,content:string}>
	 */
	public function tabs(): array {
		return array(
			'queue'   => array(
				'title'   => __( 'The queue', 'estasoft-erp-crm-sync-engine' ),
				'content' => $this->paragraphs(
					array(
						__( 'Every order, refund and stock change becomes an item in the queue. Items are sent to your system in the background, so checkout never waits for it.', 'estasoft-erp-crm-sync-engine' ),
						__( 'Use the buttons above the table to show what is waiting or what failed. Select rows to retry or skip several at once.', 'estasoft-erp-crm-sync-engine' ),
						__( 'An order that failed needs a person: open it, fix the cause, then retry. The queue never retries those by itself.', 'estasoft-erp-crm-sync-engine' ),
					)
				),
			),
			'states'  => array(
				'title'   => __( 'Sync states', 'estasoft-erp-crm-sync-engine' ),
				'content' => $this->definitions(
					array(
						__( 'Queued', 'estasoft-erp-crm-sync-engine' )   => __( 'Waiting to be sent. Orders of one customer go in the order they happened.', 'estasoft-erp-crm-sync-engine' ),
						__( 'Syncing', 'estasoft-erp-crm-sync-engine' )  => __( 'Being sent to your system right now.', 'estasoft-erp-crm-sync-engine' ),
						__( 'Synced', 'estasoft-erp-crm-sync-engine' )   => __( 'Your system has it. The order shows its number in your system.', 'estasoft-erp-crm-sync-engine' ),
						__( 'Retrying', 'estasoft-erp-crm-sync-engine' ) => __( 'Your system was busy or did not answer. The plugin tries again by itself, after 1 minute, 5 minutes, 1 hour and 24 hours.', 'estasoft-erp-crm-sync-engine' ),
						__( 'Failed', 'estasoft-erp-crm-sync-engine' )   => __( 'Something needs a person, for example a product that your system does not have. Fix it, then retry.', 'estasoft-erp-crm-sync-engine' ),
						__( 'Skipped', 'estasoft-erp-crm-sync-engine' )  => __( 'You chose not to send it. It stays in the log and you can restore it.', 'estasoft-erp-crm-sync-engine' ),
					)
				),
			),
			'fix'     => array(
				'title'   => __( 'Fix a failed order', 'estasoft-erp-crm-sync-engine' ),
				'content' => $this->paragraphs(
					array(
						__( 'Open the order from the queue. The message says what is wrong and the code (for example SYNC-MAP-001) says what to do about it.', 'estasoft-erp-crm-sync-engine' ),
						__( 'Several orders often fail for one reason, such as a product that is not in your system yet. Needs attention groups them: fix the cause once, then retry the whole group. Orders are sent oldest first.', 'estasoft-erp-crm-sync-engine' ),
						__( 'If an order should not be sent at all, skip it and write why. A skipped order can be restored later.', 'estasoft-erp-crm-sync-engine' ),
					)
				),
			),
			'retries' => array(
				'title'   => __( 'Retries and pausing', 'estasoft-erp-crm-sync-engine' ),
				'content' => $this->paragraphs(
					array(
						__( 'When your system does not answer five times in a row, the plugin pauses that connection, keeps the orders waiting, and checks again every few minutes. It resumes by itself when your system answers.', 'estasoft-erp-crm-sync-engine' ),
						__( 'You can also pause syncing yourself, for example before maintenance in your system. Orders keep arriving in the queue and are sent when you resume.', 'estasoft-erp-crm-sync-engine' ),
						__( 'A refused sign-in pauses the connection too, so that no order is marked failed. Edit the credentials and test the connection to continue.', 'estasoft-erp-crm-sync-engine' ),
					)
				),
			),
			'support' => array(
				'title'   => __( 'Getting help', 'estasoft-erp-crm-sync-engine' ),
				'content' => $this->paragraphs(
					array(
						__( 'Copy a support report from the Logs screen and paste it in your message. It lists versions, queue counts and the last errors. Order numbers are hidden unless you choose to include them, and nothing is sent anywhere by the plugin.', 'estasoft-erp-crm-sync-engine' ),
						__( 'ESTASOFT SYNC is free and every feature is included. Support is given in the WordPress.org forum and on GitHub.', 'estasoft-erp-crm-sync-engine' ),
					)
				),
			),
		);
	}

	/**
	 * The sidebar: links for more help.
	 */
	public function sidebar(): string {
		$links = array(
			self::REPOSITORY_URL             => __( 'Read the documentation', 'estasoft-erp-crm-sync-engine' ),
			self::FORUM_URL                  => __( 'Ask in the support forum', 'estasoft-erp-crm-sync-engine' ),
			self::REPOSITORY_URL . '/issues' => __( 'Open an issue on GitHub', 'estasoft-erp-crm-sync-engine' ),
			$this->urls->route( '/logs' )    => __( 'Copy a support report', 'estasoft-erp-crm-sync-engine' ),
		);
		$html  = '<p><strong>' . esc_html__( 'For more help', 'estasoft-erp-crm-sync-engine' ) . '</strong></p>';
		foreach ( $links as $url => $label ) {
			$external = str_starts_with( (string) $url, 'https://' ) && ! str_starts_with( (string) $url, $this->urls->page() );
			$html    .= '<p><a href="' . esc_url( (string) $url ) . '"' . ( $external ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $label ) . '</a></p>';
		}
		return $html;
	}

	/**
	 * Paragraphs of escaped text.
	 *
	 * @param array<int,string> $texts Plain text.
	 */
	private function paragraphs( array $texts ): string {
		return implode( '', array_map( static fn( string $text ): string => '<p>' . esc_html( $text ) . '</p>', $texts ) );
	}

	/**
	 * A list of terms and what they mean.
	 *
	 * @param array<string,string> $terms Term to meaning.
	 */
	private function definitions( array $terms ): string {
		$html = '<dl>';
		foreach ( $terms as $term => $meaning ) {
			$html .= '<dt><strong>' . esc_html( $term ) . '</strong></dt><dd>' . esc_html( $meaning ) . '</dd>';
		}
		return $html . '</dl>';
	}
}
