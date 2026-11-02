<?php
/**
 * When each admin notice shows (board A07).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Admin;

use Estasoft\SyncEngine\Admin\NoticeRules;
use Estasoft\SyncEngine\Tests\Support\RestStubCase;

/**
 * Runs in its own process because it relies on global WordPress stand-ins.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class NoticeRulesTest extends RestStubCase {

	/**
	 * Facts for an administrator on a calm site with a system connected.
	 *
	 * @param array<string,mixed> $changes Facts to change.
	 * @return array<string,mixed>
	 */
	private function facts( array $changes = array() ): array {
		return $changes + array(
			'can_manage'        => true,
			'can_configure'     => true,
			'key_missing'       => false,
			'key_line'          => '',
			'connected'         => true,
			'setup_done'        => true,
			'waiting'           => 0,
			'failed'            => 0,
			'reconnect'         => array(),
			'backlog_threshold' => 200,
			'shadow_ends_soon'  => false,
			'dismissed'         => array(),
		);
	}

	/**
	 * Ids of the notices that apply.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<int,string>
	 */
	private function ids( array $facts ): array {
		return array_column( ( new NoticeRules() )->build( $facts ), 'id' );
	}

	public function test_a_calm_site_has_no_notice(): void {
		$this->assertSame( array(), $this->ids( $this->facts() ) );
	}

	public function test_the_welcome_notice_is_for_an_administrator_before_any_system_is_connected_and_until_setup_or_dismissal(): void {
		$fresh = array( 'connected' => false, 'setup_done' => false );
		$this->assertSame( array( 'welcome' ), $this->ids( $this->facts( $fresh ) ) );
		$this->assertSame( array(), $this->ids( $this->facts( $fresh + array( 'can_configure' => false ) ) ), 'not for a shop manager' );
		$this->assertSame( array(), $this->ids( $this->facts( $fresh + array( 'dismissed' => array( 'welcome' ) ) ) ) );
		$this->assertSame( array(), $this->ids( $this->facts( array( 'connected' => true, 'setup_done' => false ) ) ) );
		$this->assertSame( array(), $this->ids( $this->facts( array( 'connected' => false, 'setup_done' => true ) ) ) );
	}

	public function test_only_the_welcome_notice_can_be_dismissed_and_it_says_what_the_board_says(): void {
		$all = ( new NoticeRules() )->build(
			$this->facts(
				array(
					'connected'        => false,
					'setup_done'       => false,
					'key_missing'      => true,
					'key_line'         => "define( 'ESTASOFT_SYNC_KEY', 'abc' );",
					'reconnect'        => array( array( 'id' => 4, 'label' => 'Odoo' ) ),
					'failed'           => 5,
					'waiting'          => 240,
					'shadow_ends_soon' => true,
				)
			)
		);
		$this->assertSame( array( 'welcome', 'encryption_key', 'reconnect_4', 'failed_orders', 'backlog', 'shadow_ends' ), array_column( $all, 'id' ) );
		$byId = array_column( $all, null, 'id' );
		$this->assertSame( array( 'welcome' ), array_keys( array_filter( $byId, static fn( array $n ): bool => $n['dismissible'] ) ) );
		$this->assertSame( 'ESTASOFT SYNC is active. Set it up in about ten minutes. Nothing is sent to your system until you say so.', $byId['welcome']['text'] );
		$this->assertSame( 'The encryption key is missing from wp-config.php. Saved credentials cannot be stored safely until you add it.', $byId['encryption_key']['text'] );
		$this->assertSame( "define( 'ESTASOFT_SYNC_KEY', 'abc' );", $byId['encryption_key']['key_line'] );
		$this->assertSame( '5 orders failed to sync. They need a person.', $byId['failed_orders']['text'] );
		$this->assertSame( '240 items are waiting. That is more than the limit of 200. The queue is moving, but slower than orders arrive.', $byId['backlog']['text'] );
		$this->assertSame( 'Shadow mode ends tomorrow. Review the results, then go live.', $byId['shadow_ends']['text'] );
		$this->assertSame( array( 'info', 'error', 'error', 'warning', 'warning', 'info' ), array_column( $all, 'kind' ) );
	}

	public function test_the_key_notice_is_for_administrators_and_the_queue_notices_for_anyone_who_can_view_the_queue(): void {
		$facts = $this->facts( array( 'can_configure' => false, 'key_missing' => true, 'failed' => 1, 'reconnect' => array( array( 'id' => 2, 'label' => 'Odoo' ) ), 'shadow_ends_soon' => true ) );
		$this->assertSame( array( 'reconnect_2', 'failed_orders' ), $this->ids( $facts ), 'a shop manager sees the queue notices only' );
		$this->assertSame( array(), $this->ids( $this->facts( array( 'can_manage' => false, 'can_configure' => false, 'failed' => 1, 'key_missing' => true ) ) ) );
	}

	public function test_one_failed_order_uses_the_singular(): void {
		$text = ( new NoticeRules() )->build( $this->facts( array( 'failed' => 1 ) ) )[0]['text'];
		$this->assertSame( '1 order failed to sync. It needs a person.', $text );
	}

	public function test_the_backlog_notice_needs_more_waiting_than_the_limit(): void {
		$this->assertSame( array(), $this->ids( $this->facts( array( 'waiting' => 200 ) ) ) );
		$this->assertSame( array( 'backlog' ), $this->ids( $this->facts( array( 'waiting' => 201 ) ) ) );
		$this->assertSame( array(), $this->ids( $this->facts( array( 'waiting' => 5000, 'backlog_threshold' => 0 ) ) ) );
	}

	public function test_the_app_gets_every_notice_but_the_welcome_one_in_the_shape_of_the_contract(): void {
		$rules = new NoticeRules();
		$facts = $this->facts( array( 'connected' => false, 'setup_done' => false, 'failed' => 2 ) );
		$app   = $rules->forApp( $facts );
		$this->assertSame( array( 'failed_orders' ), array_column( $app, 'id' ) );
		$this->assertSame( array( 'id', 'kind', 'text', 'action_label', 'action_route', 'dismissible' ), array_keys( $app[0] ) );
		$this->assertSame( array( 'welcome' ), array_column( $rules->forScreens( $facts ), 'id' ), 'PHP prints the welcome notice; the failed-order notice is for the app' );
	}
}
