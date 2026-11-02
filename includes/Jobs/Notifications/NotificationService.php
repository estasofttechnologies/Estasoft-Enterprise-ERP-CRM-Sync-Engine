<?php
/**
 * E-mail alerts: a connection paused, a daily list of failed orders, a queue that backs up.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Notifications;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Jobs\Ports\Mailer;
use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;
use Estasoft\SyncEngine\Jobs\Ports\Transients;
use Estasoft\SyncEngine\Jobs\Support\StoreTime;
use Estasoft\SyncEngine\Jobs\Urls;

/**
 * SET-2. Mail goes through `wp_mail` only (see `Adapters\WpMailer`); no external service is used. Every message has a plain-text
 * body and a simple HTML version built with escaping. Recipients are the ones in the settings (default: the site administrator).
 *
 * - Paused: sent once per incident. The incident starts when the connection pauses because of the circuit breaker or a refused
 *   sign-in (a pause a person asked for sends nothing) and ends when the connection resumes; a transient per connection holds
 *   the "already told" flag until then.
 * - Daily list: at the store-time hour in the settings, when there are failed orders, once per local date.
 * - Queue backlog: when more items wait than the limit in the settings, once until the queue is back under the limit.
 */
final class NotificationService {

	/**
	 * Option: dates and flags that keep the daily list and the backlog alert from repeating.
	 */
	public const STATE = 'estasoft_sync_notifications_state';

	/**
	 * Transient prefix for the paused flag.
	 */
	public const PAUSED_KEY = 'estasoft_sync_n_paused_';

	/**
	 * Hours the paused flag lasts if the connection never resumes.
	 */
	private const PAUSED_TTL = 12 * 3600;

	/**
	 * Failed orders listed in the daily mail.
	 */
	private const LIST = 25;

	/**
	 * Set up with what it depends on.
	 *
	 * @param Settings             $settings    Notification settings.
	 * @param Mailer               $mailer      Mail.
	 * @param Transients           $transients  Paused flags.
	 * @param ConnectionRepository $connections Connections.
	 * @param QueueRepository      $queue       Queue.
	 * @param ShopEnvironment      $environment Site name, time zone, admin e-mail.
	 * @param Urls                 $urls        Links.
	 * @param OptionStore          $options     Options (state).
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Mailer $mailer,
		private readonly Transients $transients,
		private readonly ConnectionRepository $connections,
		private readonly QueueRepository $queue,
		private readonly ShopEnvironment $environment,
		private readonly Urls $urls,
		private readonly OptionStore $options,
		private readonly Logger $logger,
		private readonly Clock $clock
	) {
	}

	/**
	 * Send the test e-mail.
	 *
	 * @param string $recipient One address, or an empty string for the addresses in the settings.
	 * @return array{sent:bool,to:string}
	 *
	 * @throws ValidationException When the address is not valid.
	 */
	public function sendTest( string $recipient = '' ): array {
		$recipient = trim( $recipient );
		if ( '' !== $recipient && false === is_email( $recipient ) ) {
			throw new ValidationException( array( 'recipient' => __( 'Enter a valid email address.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$to   = '' === $recipient ? $this->recipients() : array( $recipient );
		$site = $this->environment->siteName();
		$sent = $this->mail(
			$to,
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Test email from Estasoft Sync', 'estasoft-erp-crm-sync-engine' ),
				$site
			),
			array(
				__( 'This is a test. If you can read it, alert emails reach this address.', 'estasoft-erp-crm-sync-engine' ),
				__( 'Estasoft Sync sends alerts for a paused connection, a daily list of failed orders and a queue that backs up. Nothing is sent to any outside service.', 'estasoft-erp-crm-sync-engine' ),
			),
			$this->urls->app( '/settings/notifications' ),
			__( 'Open notification settings', 'estasoft-erp-crm-sync-engine' )
		);
		return array(
			'sent' => $sent,
			'to'   => implode( ', ', $to ),
		);
	}

	/**
	 * A connection paused (hooked to `estasoft_sync_connection_paused`).
	 *
	 * @param mixed $connectionId Connection.
	 * @param mixed $reason       circuit, sign_in or person.
	 * @return bool Whether an e-mail was sent.
	 */
	public function onPaused( mixed $connectionId, mixed $reason = 'circuit' ): bool {
		$connectionId = (int) $connectionId;
		$reason       = (string) $reason;
		$notify       = $this->settings->notifications();
		if ( $connectionId <= 0 || 'person' === $reason || ( 'sign_in' === $reason ? ! $notify['on_signin_expired'] : ! $notify['on_paused'] ) ) {
			return false;
		}
		$key = self::PAUSED_KEY . $connectionId;
		if ( false !== $this->transients->get( $key ) ) {
			return false;
		}
		$connection = $this->connections->findPublic( $connectionId );
		if ( null === $connection ) {
			return false;
		}
		$this->transients->set( $key, 1, self::PAUSED_TTL );
		$waiting = $this->waiting( $connectionId );
		$system  = '' !== (string) $connection['label'] ? (string) $connection['label'] : (string) $connection['connector'];
		$lines   = 'sign_in' === $reason
			? array(
				sprintf(
					/* translators: %s: name of the connected system. */
					__( '%s refused the sign-in, so syncing is paused. Orders wait in the queue.', 'estasoft-erp-crm-sync-engine' ),
					$system
				),
				__( 'Open Connections, check the user and the key, and test the connection again. Syncing resumes when it works.', 'estasoft-erp-crm-sync-engine' ),
			)
			: array(
				sprintf(
					/* translators: %s: name of the connected system. */
					__( '%s did not answer several times in a row, so syncing is paused. Orders wait in the queue.', 'estasoft-erp-crm-sync-engine' ),
					$system
				),
				__( 'The plugin checks again every few minutes and resumes by itself when the system answers.', 'estasoft-erp-crm-sync-engine' ),
			);
		$lines[] = sprintf(
			/* translators: %d: number of orders waiting. */
			_n( '%d item is waiting.', '%d items are waiting.', $waiting, 'estasoft-erp-crm-sync-engine' ),
			$waiting
		);
		return $this->mail(
			$this->recipients(),
			sprintf(
				/* translators: 1: site name, 2: name of the connected system. */
				__( '[%1$s] Syncing with %2$s is paused', 'estasoft-erp-crm-sync-engine' ),
				$this->environment->siteName(),
				$system
			),
			$lines,
			$this->urls->app( '/connections' ),
			__( 'Open Connections', 'estasoft-erp-crm-sync-engine' )
		);
	}

	/**
	 * A connection resumed (hooked to `estasoft_sync_connection_resumed`): the incident is over.
	 *
	 * @param mixed $connectionId Connection.
	 */
	public function onResumed( mixed $connectionId ): void {
		$this->transients->delete( self::PAUSED_KEY . (int) $connectionId );
	}

	/**
	 * The daily list of failed orders, when the store-time hour has passed and it was not sent today.
	 *
	 * @return array{sent:bool,reason:string,count:int}
	 */
	public function digest(): array {
		$notify = $this->settings->notifications();
		if ( ! $notify['daily_digest'] ) {
			return $this->outcome( false, 'off' );
		}
		$zone  = $this->environment->timezone();
		$now   = $this->clock->now();
		$today = StoreTime::date( $now, $zone );
		$state = $this->state();
		if ( ! StoreTime::reached( $now, $zone, $notify['digest_time'] ) ) {
			return $this->outcome( false, 'not_yet' );
		}
		if ( $state['digest_date'] === $today ) {
			return $this->outcome( false, 'already' );
		}
		$state['digest_date'] = $today;
		$this->options->set( self::STATE, $state );
		$failed = $this->queue->countsByState( null, 'live' )['failed'];
		if ( $failed <= 0 ) {
			return $this->outcome( false, 'none' );
		}
		$items = $this->queue->query( new QueueQuery( 'failed', null, null, null, null, null, 'live', 1, 25 ) )->items;
		$lines = array(
			sprintf(
				/* translators: %d: number of failed orders. */
				_n( '%d item needs your attention. It will not be tried again until you act.', '%d items need your attention. They will not be tried again until you act.', $failed, 'estasoft-erp-crm-sync-engine' ),
				$failed
			),
		);
		foreach ( array_slice( $items, 0, self::LIST ) as $item ) {
			$lines[] = sprintf(
				/* translators: 1: order number, 2: error code, 3: what went wrong. */
				__( '#%1$s, %2$s: %3$s', 'estasoft-erp-crm-sync-engine' ),
				(string) ( '' !== (string) ( $item['entity_label'] ?? '' ) ? $item['entity_label'] : $item['entity_id'] ),
				(string) ( $item['error_code'] ?? '-' ),
				mb_substr( (string) ( $item['error_message'] ?? '' ), 0, 160 )
			);
		}
		if ( $failed > self::LIST ) {
			$lines[] = sprintf(
				/* translators: %d: number of items not listed. */
				__( 'And %d more. Open the queue to see them all.', 'estasoft-erp-crm-sync-engine' ),
				$failed - self::LIST
			);
		}
		$sent = $this->mail(
			$this->recipients(),
			sprintf(
				/* translators: 1: site name, 2: number of failed orders. */
				_n( '[%1$s] %2$d order needs attention', '[%1$s] %2$d orders need attention', $failed, 'estasoft-erp-crm-sync-engine' ),
				$this->environment->siteName(),
				$failed
			),
			$lines,
			$this->urls->app( '/queue?state=failed' ),
			__( 'Open the queue', 'estasoft-erp-crm-sync-engine' )
		);
		return array(
			'sent'   => $sent,
			'reason' => $sent ? 'sent' : 'mail_failed',
			'count'  => $failed,
		);
	}

	/**
	 * The queue backs up: more items waiting than the limit. Sent once until the queue is back under the limit.
	 *
	 * @return array{sent:bool,reason:string,count:int}
	 */
	public function checkBacklog(): array {
		$counts    = $this->queue->countsByState( null, 'live' );
		$waiting   = (int) $counts['queued'] + (int) $counts['retrying'] + (int) $counts['syncing'];
		$threshold = $this->settings->backlogThreshold();
		$state     = $this->state();
		if ( $waiting <= $threshold ) {
			if ( $state['backlog'] ) {
				$state['backlog'] = false;
				$this->options->set( self::STATE, $state );
			}
			return $this->outcome( false, 'under', $waiting );
		}
		if ( $state['backlog'] ) {
			return $this->outcome( false, 'already', $waiting );
		}
		$state['backlog'] = true;
		$this->options->set( self::STATE, $state );
		if ( ! $this->settings->notifications()['on_backlog'] ) {
			return $this->outcome( false, 'off', $waiting );
		}
		$sent = $this->mail(
			$this->recipients(),
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] The sync queue is backing up', 'estasoft-erp-crm-sync-engine' ),
				$this->environment->siteName()
			),
			array(
				sprintf(
					/* translators: 1: number of items waiting, 2: the limit set in the settings. */
					__( '%1$d items are waiting, which is more than the limit of %2$d.', 'estasoft-erp-crm-sync-engine' ),
					$waiting,
					$threshold
				),
				__( 'Check that the connection is not paused and that background tasks are running. You get this email once until the queue is back under the limit.', 'estasoft-erp-crm-sync-engine' ),
			),
			$this->urls->app( '/queue?state=waiting' ),
			__( 'Open the queue', 'estasoft-erp-crm-sync-engine' )
		);
		return $this->outcome( $sent, $sent ? 'sent' : 'mail_failed', $waiting );
	}

	/**
	 * The recurring task: the daily list and the backlog check.
	 *
	 * @return array{digest:array<string,mixed>,backlog:array<string,mixed>}
	 */
	public function tick(): array {
		return array(
			'digest'  => $this->digest(),
			'backlog' => $this->checkBacklog(),
		);
	}

	/**
	 * Where alerts go: the settings' addresses, or the site administrator.
	 *
	 * @return array<int,string>
	 */
	public function recipients(): array {
		$list = $this->settings->notifications()['recipients'];
		if ( array() === $list ) {
			$admin = $this->environment->adminEmail();
			return false === is_email( $admin ) ? array() : array( $admin );
		}
		return $list;
	}

	/**
	 * Items waiting for a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	private function waiting( int $connectionId ): int {
		$counts = $this->queue->countsByState( $connectionId, 'live' );
		return (int) $counts['queued'] + (int) $counts['retrying'] + (int) $counts['syncing'];
	}

	/**
	 * Build and send one message.
	 *
	 * @param array<int,string> $to        Recipients.
	 * @param string            $subject   Subject.
	 * @param array<int,string> $lines     Paragraphs.
	 * @param string            $link      Link to the admin app.
	 * @param string            $linkLabel Text of the link.
	 */
	private function mail( array $to, string $subject, array $lines, string $link, string $linkLabel ): bool {
		if ( array() === $to ) {
			return false;
		}
		$text = implode( "\n\n", $lines ) . "\n\n" . $linkLabel . ': ' . $link . "\n";
		$html = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;">';
		foreach ( $lines as $line ) {
			$html .= '<p style="margin:0 0 12px;">' . esc_html( $line ) . '</p>';
		}
		$html .= '<p style="margin:16px 0 0;"><a href="' . esc_url( $link ) . '">' . esc_html( $linkLabel ) . '</a></p></div>';
		$sent  = $this->mailer->send( $to, $subject, $text, $html );
		if ( ! $sent ) {
			$this->logger->warning( __( 'An alert email could not be sent. Check that your site can send email.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $sent;
	}

	/**
	 * The stored state.
	 *
	 * @return array{digest_date:string,backlog:bool}
	 */
	private function state(): array {
		$stored = $this->options->get( self::STATE, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'digest_date' => (string) ( $stored['digest_date'] ?? '' ),
			'backlog'     => (bool) ( $stored['backlog'] ?? false ),
		);
	}

	/**
	 * A result.
	 *
	 * @param bool   $sent   Whether mail was sent.
	 * @param string $reason Why or why not.
	 * @param int    $count  Items counted.
	 * @return array{sent:bool,reason:string,count:int}
	 */
	private function outcome( bool $sent, string $reason, int $count = 0 ): array {
		return array(
			'sent'   => $sent,
			'reason' => $reason,
			'count'  => $count,
		);
	}
}
