<?php
/**
 * ONB-3, LOG-6, AS-02, AS-24.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Jobs\Readiness\ReadinessService;

final class ReadinessAndSupportTest extends JobsTestCase {

	/**
	 * The checks of a result by id.
	 *
	 * @param array<string,mixed> $result Readiness.
	 * @return array<string,array<string,mixed>>
	 */
	private function byId( array $result ): array {
		return array_column( $result['checks'], null, 'id' );
	}

	public function test_there_are_ten_checks_and_a_healthy_store_passes_them_all(): void {
		$this->j->lastQueueRun = '2026-11-02 11:55:00';

		$result = $this->j->readiness->run();

		$this->assertSame( array( 'hpos', 'cron_running', 'cron_visits', 'dup_sku', 'no_sku', 'state', 'currency', 'charset', 'key', 'php' ), array_column( $result['checks'], 'id' ) );
		$this->assertSame( array( 'passed' ), array_values( array_unique( array_column( $result['checks'], 'status' ) ) ) );
		$this->assertFalse( $result['blocked'] );
		$this->assertSame( '2026-11-02T12:00:00Z', $result['ran_at'] );
		$this->assertSame( array( 'id', 'title', 'status', 'detail', 'link', 'link_label' ), array_keys( $result['checks'][0] ) );
	}

	public function test_six_products_without_a_sku_are_worth_fixing_listed_and_do_not_block_setup(): void {
		$this->j->lastQueueRun = '2026-11-02 11:55:00';
		for ( $i = 1; $i <= 6; ++$i ) {
			$this->j->catalog->missing[] = array( 'id' => $i, 'name' => 'Lamp ' . $i );
		}

		$result = $this->j->readiness->run();

		$check = $this->byId( $result )['no_sku'];
		$this->assertSame( 'worth_fixing', $check['status'] );
		$this->assertSame( '6 products have no SKU', $check['title'] );
		$this->assertStringContainsString( 'Lamp 1, Lamp 2, Lamp 3, Lamp 4, Lamp 5, Lamp 6', $check['detail'] );
		$this->assertSame( '/mapping/products', $check['link'] );
		$this->assertSame( 'View the 6 products', $check['link_label'] );
		$this->assertFalse( $result['blocked'], 'worth fixing never blocks setup' );
	}

	public function test_duplicate_skus_and_free_text_states_are_worth_fixing(): void {
		$this->j->lastQueueRun       = '2026-11-02 11:55:00';
		$this->j->catalog->duplicates = array( array( 'sku' => 'CS-1', 'ids' => array( 1, 2 ) ) );
		$this->j->catalog->freeText   = array( array( 'id' => 9, 'name' => 'A', 'state' => 'Dubayy', 'country' => 'AE' ), array( 'id' => 10, 'name' => 'B', 'state' => 'Dxb', 'country' => 'AE' ) );

		$checks = $this->byId( $this->j->readiness->run() );

		$this->assertSame( 'worth_fixing', $checks['dup_sku']['status'] );
		$this->assertStringContainsString( 'CS-1', $checks['dup_sku']['detail'] );
		$this->assertSame( 'worth_fixing', $checks['state']['status'] );
		$this->assertSame( '2 customers have a free-text state', $checks['state']['title'] );
	}

	public function test_things_that_stop_the_sync_need_fixing_and_block(): void {
		$this->j->lastQueueRun = '2026-11-02 10:00:00';
		$this->j->env->charset = 'latin1';
		$this->j->env->php     = '8.0.30';
		$noKey                 = new ReadinessService( $this->j->env, $this->j->catalog, $this->j->catalog, new \Estasoft\SyncEngine\Core\Crypto\SecretBox( null ), $this->h->connections, $this->j->urls, $this->h->options, $this->h->clock, fn() => $this->j->lastQueueRun );

		$result = $noKey->run();

		$checks = $this->byId( $result );
		foreach ( array( 'cron_running', 'charset', 'key', 'php' ) as $id ) {
			$this->assertSame( 'needs_fixing', $checks[ $id ]['status'], $id );
		}
		$this->assertTrue( $result['blocked'] );
		$this->assertSame( '/connections', $checks['key']['link'] );
	}

	public function test_background_tasks_that_never_ran_and_cron_on_visits_are_worth_fixing(): void {
		$this->j->env->visits = true;

		$checks = $this->byId( $this->j->readiness->run() );

		$this->assertSame( 'worth_fixing', $checks['cron_running']['status'] );
		$this->assertSame( 'worth_fixing', $checks['cron_visits']['status'] );
		$this->assertSame( '/settings/performance', $checks['cron_visits']['link'] );
	}

	public function test_currency_decimals_are_compared_with_the_system_once_a_connection_exists(): void {
		$this->j->lastQueueRun = '2026-11-02 11:55:00';
		$this->assertSame( 'passed', $this->byId( $this->j->readiness->run() )['currency']['status'] );

		$id = $this->j->connect();
		$this->h->connections->update( $id, array( 'config' => array( 'detected' => array( 'currency' => 'AED', 'currency_decimals' => 2 ) ) ) );
		$this->assertStringContainsString( 'AED uses 2 decimals in WooCommerce and in the system', $this->byId( $this->j->readiness->run() )['currency']['detail'] );

		$this->j->env->currency = 'KWD';
		$this->j->env->decimals = 2;
		$this->h->connections->update( $id, array( 'config' => array( 'detected' => array( 'currency' => 'KWD', 'currency_decimals' => 3 ) ) ) );
		$result = $this->j->readiness->run();
		$this->assertSame( 'needs_fixing', $this->byId( $result )['currency']['status'] );
		$this->assertTrue( $result['blocked'] );

		$this->h->connections->update( $id, array( 'config' => array( 'detected' => array( 'currency' => 'USD', 'currency_decimals' => 2 ) ) ) );
		$this->assertSame( 'worth_fixing', $this->byId( $this->j->readiness->run() )['currency']['status'], 'another main currency is worth a word, not a block' );
	}

	public function test_other_plugins_can_add_checks_through_the_filter_and_bad_entries_are_dropped(): void {
		add_filter(
			'estasoft_sync_readiness_checks',
			static function ( array $checks ): array {
				$checks[] = array( 'id' => 'extra', 'title' => 'Extra', 'status' => 'worth_fixing', 'detail' => 'Look at it', 'link' => 'https://example.test/x', 'link_label' => 'Open' );
				$checks[] = array( 'id' => 'broken', 'title' => 'Broken', 'status' => 'maybe', 'detail' => '' );
				$checks[] = 'not a check';
				return $checks;
			}
		);
		$result = $this->j->readiness->run();
		remove_all_filters( 'estasoft_sync_readiness_checks' );

		$ids = array_column( $result['checks'], 'id' );
		$this->assertContains( 'extra', $ids );
		$this->assertNotContains( 'broken', $ids );
		$this->assertCount( 11, $ids );
	}

	public function test_the_last_result_is_kept_and_an_unchecked_store_says_so(): void {
		$this->assertSame( array( 'ran_at' => null, 'checks' => array(), 'blocked' => false ), $this->j->readiness->last() );
		$ran = $this->j->readiness->run();
		$this->assertSame( $ran, $this->j->readiness->last() );
		$this->assertArrayHasKey( ReadinessService::OPTION, $this->h->options->values );
	}

	public function test_the_support_report_masks_order_numbers_and_hides_an_email_and_a_phone_number(): void {
		$id = $this->j->connect();
		$this->h->settings->update( array( 'redaction' => false ) );
		$this->h->logger->error(
			'Order #10490 failed for noor.almansouri@example.com, phone +971 50 123 4567, order number 10490.',
			array(
				'connection_id' => $id,
				'entity_ref'    => 'Order #10490',
				'code'          => 'SYNC-MAP-003',
			)
		);

		$masked = $this->j->support->build();

		$this->assertFalse( $masked['includes_order_numbers'] );
		$this->assertStringNotContainsString( 'noor.almansouri@example.com', $masked['text'] );
		$this->assertStringNotContainsString( '+971 50 123 4567', $masked['text'] );
		$this->assertStringNotContainsString( '10490', $masked['text'] );
		$this->assertStringContainsString( '****', $masked['text'] );
		$this->assertStringContainsString( 'SYNC-MAP-003', $masked['text'] );

		$plain = $this->j->support->build( true );
		$this->assertTrue( $plain['includes_order_numbers'] );
		$this->assertStringContainsString( '10490', $plain['text'] );
		$this->assertStringNotContainsString( 'noor.almansouri@example.com', $plain['text'], 'personal data is never included' );
		$this->assertStringNotContainsString( '+971 50 123 4567', $plain['text'] );
	}

	public function test_the_support_report_has_versions_runtime_connections_queue_and_readiness_but_no_secret(): void {
		$id = $this->j->connect();
		$this->h->item( $id, '1' );
		$this->j->readiness->run();

		$text = $this->j->support->build()['text'];

		foreach ( array( 'Versions', 'WordPress: 6.4.3', 'WooCommerce: 9.4.0', 'PHP: 8.3.6', 'Runtime', 'Memory limit: 256M', 'HPOS', 'Encryption key: present', 'Connections', 'fake: active', 'host erp.example.test', 'Queue', 'queued 1', 'Readiness', 'Last 20 errors', 'None' ) as $expected ) {
			$this->assertStringContainsString( $expected, $text );
		}
		$this->assertStringNotContainsString( 'sk-test', $text );
		$this->assertStringNotContainsString( 'https://erp.example.test', $text, 'only the host name of the address' );
		$this->assertStringNotContainsString( 'jobs-test-key', $text );
	}

	public function test_the_support_report_lists_at_most_twenty_errors(): void {
		for ( $i = 1; $i <= 25; ++$i ) {
			$this->h->logger->error( 'Failure number ' . $i );
		}
		$text   = $this->j->support->build()['text'];
		$errors = array_filter( explode( "\n", $text ), static fn( string $line ): bool => str_contains( $line, 'Failure number' ) );
		$this->assertCount( 20, $errors );
		$this->assertStringContainsString( 'Failure number 25', $text, 'the newest first' );
		$this->assertStringNotContainsString( 'Failure number 5 ', $text . ' ' );
	}
}
