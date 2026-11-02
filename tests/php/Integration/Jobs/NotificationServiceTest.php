<?php
/**
 * SET-2: alerts by e-mail, once per incident.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Core\Support\ValidationException;

final class NotificationServiceTest extends JobsTestCase {

	private int $connection = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = $this->j->connect();
	}

	public function test_the_test_mail_goes_to_the_admin_by_default_and_to_a_chosen_address_when_given(): void {
		$result = $this->j->notifications->sendTest();
		$this->assertTrue( $result['sent'] );
		$this->assertSame( 'admin@example.test', $result['to'] );
		$this->assertSame( array( 'admin@example.test' ), $this->j->mailer->sent[0]['to'] );
		$this->assertStringContainsString( 'Cedar & Stone', $this->j->mailer->sent[0]['subject'] );
		$this->assertStringContainsString( 'https://shop.example.test/wp-admin/admin.php?page=estasoft-sync#/settings/notifications', $this->j->mailer->sent[0]['text'] );

		$this->j->notifications->sendTest( 'ops@example.test' );
		$this->assertSame( array( 'ops@example.test' ), $this->j->mailer->sent[1]['to'] );

		$this->expectException( ValidationException::class );
		$this->j->notifications->sendTest( 'not an address' );
	}

	public function test_a_failing_mail_system_is_reported_and_logged(): void {
		$this->j->mailer->accepts = false;
		$this->assertFalse( $this->j->notifications->sendTest()['sent'] );
		$messages = array_column( $this->h->logs->latest( 5 ), 'message' );
		$this->assertNotSame( array(), array_filter( $messages, static fn( string $m ): bool => str_contains( $m, 'could not be sent' ) ) );
	}

	public function test_paused_is_sent_once_per_incident_and_again_after_the_connection_resumed(): void {
		$this->h->item( $this->connection, '1' );
		$this->assertTrue( $this->j->notifications->onPaused( $this->connection, 'circuit' ) );
		$this->assertFalse( $this->j->notifications->onPaused( $this->connection, 'circuit' ), 'same incident' );
		$this->assertCount( 1, $this->j->mailer->sent );
		$this->assertStringContainsString( 'paused', $this->j->mailer->sent[0]['subject'] );
		$this->assertStringContainsString( '1 item is waiting', $this->j->mailer->sent[0]['text'] );

		$this->j->notifications->onResumed( $this->connection );
		$this->assertTrue( $this->j->notifications->onPaused( $this->connection, 'circuit' ), 'a new incident' );
		$this->assertCount( 2, $this->j->mailer->sent );
	}

	public function test_a_pause_by_a_person_and_switched_off_triggers_send_nothing(): void {
		$this->assertFalse( $this->j->notifications->onPaused( $this->connection, 'person' ) );
		$this->h->settings->update( array( 'notifications' => array( 'on_paused' => false ) ) );
		$this->assertFalse( $this->j->notifications->onPaused( $this->connection, 'circuit' ) );
		$this->assertTrue( $this->j->notifications->onPaused( $this->connection, 'sign_in' ), 'the sign-in trigger has its own switch' );
		$this->assertCount( 1, $this->j->mailer->sent );
		$this->assertStringContainsString( 'refused the sign-in', $this->j->mailer->sent[0]['text'] );
	}

	public function test_the_html_version_is_escaped(): void {
		$this->h->connections->update( $this->connection, array( 'label' => '<b>Evil</b> & Co' ) );
		$this->j->notifications->onPaused( $this->connection, 'circuit' );
		$html = $this->j->mailer->sent[0]['html'];
		$this->assertStringNotContainsString( '<b>Evil</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Evil&lt;/b&gt; &amp; Co', $html );
	}

	public function test_the_daily_list_waits_for_the_hour_lists_failures_and_is_sent_once_a_day(): void {
		$id = $this->h->item( $this->connection, '10490', 'created', null, \Estasoft\SyncEngine\Domain\ItemMode::Live, array( 'label' => '10490' ) );
		$this->h->queue->markFailed( $id, 'SYNC-MAP-001', 'Product CS-LAMP-07 was not found in Fakesystem.', 'SKU CS-LAMP-07' );

		$this->h->clock->set( '2026-11-02 03:00:00' );
		$this->assertSame( 'not_yet', $this->j->notifications->digest()['reason'], '07:00 store time' );

		$this->h->clock->set( '2026-11-02 05:00:00' );
		$sent = $this->j->notifications->digest();
		$this->assertTrue( $sent['sent'] );
		$this->assertSame( 1, $sent['count'] );
		$this->assertStringContainsString( '#10490, SYNC-MAP-001: Product CS-LAMP-07', $this->j->mailer->sent[0]['text'] );

		$this->h->clock->set( '2026-11-02 08:00:00' );
		$this->assertSame( 'already', $this->j->notifications->digest()['reason'] );

		$this->h->clock->set( '2026-11-03 05:00:00' );
		$this->assertTrue( $this->j->notifications->digest()['sent'], 'the next date' );
		$this->assertCount( 2, $this->j->mailer->sent );
	}

	public function test_the_daily_list_sends_nothing_when_there_are_no_failures_or_it_is_off(): void {
		$this->assertSame( 'none', $this->j->notifications->digest()['reason'] );
		$this->assertSame( array(), $this->j->mailer->sent );

		$this->h->settings->update( array( 'notifications' => array( 'daily_digest' => false ) ) );
		$this->assertSame( 'off', $this->j->notifications->digest()['reason'] );
	}

	public function test_the_digest_time_is_a_store_time_setting(): void {
		$id = $this->h->item( $this->connection, '1' );
		$this->h->queue->markFailed( $id, 'SYNC-MAP-001', 'x' );
		$this->h->settings->update( array( 'notifications' => array( 'digest_time' => '18:30' ) ) );
		$this->h->clock->set( '2026-11-02 12:00:00' );
		$this->assertSame( 'not_yet', $this->j->notifications->digest()['reason'], '16:00 store time' );
		$this->h->clock->set( '2026-11-02 15:00:00' );
		$this->assertTrue( $this->j->notifications->digest()['sent'] );
	}

	public function test_a_backlog_alert_is_sent_once_until_the_queue_is_back_under_the_limit(): void {
		$this->h->settings->update( array( 'backlog_threshold' => 10 ) );
		$ids = array();
		for ( $i = 1; $i <= 11; ++$i ) {
			$ids[] = $this->h->item( $this->connection, (string) $i );
		}
		$first = $this->j->notifications->checkBacklog();
		$this->assertTrue( $first['sent'] );
		$this->assertSame( 11, $first['count'] );
		$this->assertSame( 'already', $this->j->notifications->checkBacklog()['reason'] );

		$this->h->queue->skip( $ids[0], 'x' );
		$this->assertSame( 'under', $this->j->notifications->checkBacklog()['reason'] );
		$this->h->queue->restore( $ids[0] );
		$this->assertTrue( $this->j->notifications->checkBacklog()['sent'], 'a new incident' );
		$this->assertCount( 2, $this->j->mailer->sent );
	}

	public function test_a_backlog_alert_can_be_switched_off(): void {
		$this->h->settings->update( array( 'backlog_threshold' => 10, 'notifications' => array( 'on_backlog' => false ) ) );
		for ( $i = 1; $i <= 11; ++$i ) {
			$this->h->item( $this->connection, (string) $i );
		}
		$this->assertSame( 'off', $this->j->notifications->checkBacklog()['reason'] );
		$this->assertSame( array(), $this->j->mailer->sent );
	}
}
