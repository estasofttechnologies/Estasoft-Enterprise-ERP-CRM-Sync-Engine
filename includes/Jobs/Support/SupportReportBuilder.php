<?php
/**
 * The support report: facts to paste into a help request.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Logging\Redactor;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\LogQuery;
use Estasoft\SyncEngine\Core\Repositories\LogRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;
use Estasoft\SyncEngine\Jobs\Readiness\ReadinessService;

/**
 * LOG-6. Plain text with versions, runtime facts, a summary of each connection, queue counts, the readiness summary and the last
 * 20 errors. It never contains a secret (it reads no secret), an address beyond its host name, or personal data: every free text
 * goes through the redactor with personal-data redaction forced on, whatever the setting says. Order numbers show as `****`
 * unless the person asks for them. Nothing is sent anywhere; the person copies or downloads the text.
 */
final class SupportReportBuilder {

	/**
	 * Errors listed.
	 */
	public const ERRORS = 20;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ShopEnvironment      $environment Versions and runtime.
	 * @param ConnectionRepository $connections Connections.
	 * @param QueueRepository      $queue       Queue counts.
	 * @param LogRepository        $logs        Log.
	 * @param ReadinessService     $readiness   Readiness results.
	 * @param Redactor             $redactor    Redaction.
	 * @param SecretBox            $box         Encryption key (present or not; never the key).
	 * @param Clock                $clock       Time source.
	 * @param \Closure             $usesActionScheduler Returns whether Action Scheduler runs the tasks.
	 */
	public function __construct(
		private readonly ShopEnvironment $environment,
		private readonly ConnectionRepository $connections,
		private readonly QueueRepository $queue,
		private readonly LogRepository $logs,
		private readonly ReadinessService $readiness,
		private readonly Redactor $redactor,
		private readonly SecretBox $box,
		private readonly Clock $clock,
		private readonly \Closure $usesActionScheduler
	) {
	}

	/**
	 * Build the report.
	 *
	 * @param bool $withOrderNumbers Show order numbers instead of ****.
	 * @return array{text:string,generated_at:string,includes_order_numbers:bool} A `SupportReport`.
	 */
	public function build( bool $withOrderNumbers = false ): array {
		$now   = $this->clock->now();
		$lines = array(
			'ESTASOFT SYNC',
			sprintf(
				/* translators: %s: date and time in UTC. */
				__( 'Support report, %s UTC', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$now->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i' )
			),
			'',
		);
		$lines = array_merge( $lines, $this->versions(), $this->runtime(), $this->connectionLines(), $this->queueLines(), $this->readinessLines(), $this->errorLines( $withOrderNumbers ) );
		$text  = implode( "\n", $lines );
		if ( ! $withOrderNumbers ) {
			$text = $this->maskOrders( $text );
		}
		return array(
			'text'                   => $text,
			'generated_at'           => (string) Time::iso( Time::sql( $now ) ),
			'includes_order_numbers' => $withOrderNumbers,
		);
	}

	/**
	 * Versions.
	 *
	 * @return array<int,string>
	 */
	private function versions(): array {
		$woo = $this->environment->wooVersion();
		return array(
			__( 'Versions', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'  ' . __( 'Plugin', 'estasoft-enterprise-erpcrm-sync-engine' ) . ': ' . $this->environment->pluginVersion(),
			'  WordPress: ' . $this->environment->wpVersion(),
			'  WooCommerce: ' . ( '' === $woo ? __( 'not active', 'estasoft-enterprise-erpcrm-sync-engine' ) : $woo ),
			'  PHP: ' . $this->environment->phpVersion(),
			'  ' . __( 'Database', 'estasoft-enterprise-erpcrm-sync-engine' ) . ': ' . $this->environment->databaseVersion(),
			'',
		);
	}

	/**
	 * Runtime facts.
	 *
	 * @return array<int,string>
	 */
	private function runtime(): array {
		$zone = $this->environment->timezone()->getName();
		return array(
			__( 'Runtime', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'  ' . __( 'Memory limit', 'estasoft-enterprise-erpcrm-sync-engine' ) . ': ' . $this->environment->memoryLimit(),
			'  ' . __( 'Background tasks', 'estasoft-enterprise-erpcrm-sync-engine' ) . ': ' . ( ( $this->usesActionScheduler )() ? 'Action Scheduler' : 'WP-Cron' )
				. ', ' . ( $this->environment->cronRunsOnVisits() ? __( 'on site visits', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'real cron job', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			'  ' . __( 'Order storage', 'estasoft-enterprise-erpcrm-sync-engine' ) . ': ' . ( $this->environment->hposEnabled() ? 'HPOS' : __( 'posts', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			'  ' . __( 'Store', 'estasoft-enterprise-erpcrm-sync-engine' ) . ': ' . $zone . ', ' . $this->environment->currency() . ' (' . $this->environment->currencyDecimals() . ' ' . __( 'decimals', 'estasoft-enterprise-erpcrm-sync-engine' ) . ')',
			'  ' . __( 'Encryption key', 'estasoft-enterprise-erpcrm-sync-engine' ) . ': ' . ( $this->box->keyAvailable() ? __( 'present', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'missing', 'estasoft-enterprise-erpcrm-sync-engine' ) ),
			'',
		);
	}

	/**
	 * One line per connection, with no secret.
	 *
	 * @return array<int,string>
	 */
	private function connectionLines(): array {
		$lines = array( __( 'Connections', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		$all   = $this->connections->all();
		if ( array() === $all ) {
			$lines[] = '  ' . __( 'None', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		foreach ( $all as $connection ) {
			$host    = (string) wp_parse_url( (string) $connection['address'], PHP_URL_HOST );
			$lines[] = sprintf(
				/* translators: 1: connector id, 2: status, 3: system version, 4: host name, 5: failures in a row, 6: last success, 7: last failure. */
				__( '  %1$s: %2$s, version %3$s, host %4$s, %5$d failures in a row, last success %6$s, last failure %7$s', 'estasoft-enterprise-erpcrm-sync-engine' ),
				(string) $connection['connector'],
				(string) $connection['status'],
				'' === (string) $connection['version'] ? '-' : (string) $connection['version'],
				'' === $host ? '-' : $host,
				(int) $connection['failures_in_a_row'],
				$connection['last_success_at'] ?? '-',
				$connection['last_failure_at'] ?? '-'
			);
		}
		$lines[] = '';
		return $lines;
	}

	/**
	 * Queue counts.
	 *
	 * @return array<int,string>
	 */
	private function queueLines(): array {
		$counts = $this->queue->countsByState( null, 'live' );
		$shadow = array_sum( $this->queue->countsByState( null, 'shadow' ) );
		$parts  = array();
		foreach ( $counts as $state => $n ) {
			$parts[] = $state . ' ' . $n;
		}
		return array(
			__( 'Queue', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'  ' . implode( ', ', $parts ),
			'  ' . sprintf(
				/* translators: %d: number of items built in shadow mode. */
				__( 'Shadow items: %d', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$shadow
			),
			'',
		);
	}

	/**
	 * Readiness summary.
	 *
	 * @return array<int,string>
	 */
	private function readinessLines(): array {
		$last  = $this->readiness->last();
		$lines = array( __( 'Readiness', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		if ( array() === $last['checks'] ) {
			$lines[] = '  ' . __( 'Not checked yet', 'estasoft-enterprise-erpcrm-sync-engine' );
			$lines[] = '';
			return $lines;
		}
		$counts  = array_count_values( array_column( $last['checks'], 'status' ) );
		$lines[] = '  ' . sprintf(
			/* translators: 1: passed, 2: worth fixing, 3: needs fixing. */
			__( '%1$d passed, %2$d worth fixing, %3$d need fixing', 'estasoft-enterprise-erpcrm-sync-engine' ),
			$counts['passed'] ?? 0,
			$counts['worth_fixing'] ?? 0,
			$counts['needs_fixing'] ?? 0
		);
		foreach ( $last['checks'] as $check ) {
			if ( 'passed' !== $check['status'] ) {
				$lines[] = '  - ' . $check['status'] . ': ' . $this->redactor->message( (string) $check['title'], true );
			}
		}
		$lines[] = '';
		return $lines;
	}

	/**
	 * The last errors, redacted.
	 *
	 * @param bool $withOrderNumbers Whether order numbers stay.
	 * @return array<int,string>
	 */
	private function errorLines( bool $withOrderNumbers ): array {
		$lines   = array( sprintf( /* translators: %d: number of errors listed. */ __( 'Last %d errors', 'estasoft-enterprise-erpcrm-sync-engine' ), self::ERRORS ) );
		$entries = $this->logs->query( new LogQuery( 'error', null, null, null, null, 1, self::ERRORS ) )->items;
		if ( array() === $entries ) {
			$lines[] = '  ' . __( 'None', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		foreach ( $entries as $entry ) {
			$ref     = $withOrderNumbers && ! empty( $entry['entity_ref'] ) ? ' ' . $this->redactor->message( (string) $entry['entity_ref'], true ) : '';
			$lines[] = '  ' . (string) $entry['ts'] . ' ' . ( $entry['code'] ?? '-' ) . $ref . ' ' . $this->redactor->message( (string) $entry['message'], true );
		}
		return $lines;
	}

	/**
	 * Replace order numbers with ****.
	 *
	 * @param string $text Report text.
	 */
	private function maskOrders( string $text ): string {
		$text = (string) preg_replace( '/(\border\s*(?:no\.?|number|id)?\s*#?\s*)\d+/i', '$1****', $text );
		$text = (string) preg_replace( '/#\s?\d+/', '#****', $text );
		return $text;
	}
}
