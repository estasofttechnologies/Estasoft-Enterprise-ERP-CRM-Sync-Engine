<?php
/**
 * Signed hints from the connected system: "something changed, look again".
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Inbound;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\Ports\Transients;

/**
 * The inbound route (spec 7.2). Off by default; a person turns it on per connection and sees the secret once.
 *
 * A message carries `X-Estasoft-Timestamp` (Unix seconds, or an ISO 8601 time) and `X-Estasoft-Signature`: the hex HMAC-SHA256,
 * with the connection's secret as key, of the string `{timestamp}.{rawbody}` where `{timestamp}` is the header value exactly as
 * sent (optionally prefixed `sha256=`). The timestamp is part of what is signed, so a captured message cannot be replayed with a
 * fresh timestamp. It is accepted only when:
 *
 * 1. the connection exists, is not disconnected, and has inbound enabled;
 * 2. fewer than 30 messages arrived for it from the same source address in the last minute, and fewer than 300 from all sources
 *    (counted before anything else, so guessing is slow; one noisy address cannot use up the sender's share);
 * 3. the body is at most 64 KB;
 * 4. the timestamp is Unix seconds in digits or a strict ISO 8601 time (`2026-11-02T12:00:00Z`, with an offset or fractions of
 *    a second if needed), never relative text such as "now", and is within 5 minutes of now;
 * 5. the signature of `{timestamp}.{rawbody}` matches (compared with `hash_equals`);
 * 6. the same signature was not seen in the last 10 minutes (replay).
 *
 * A refusal is logged as SYNC-INB-001 (info) and answered with `InboundRefused`. Not found and too many requests are logged too, but
 * at most once a minute each (per connection for too many requests, once for all unknown connections), so a flood cannot fill the log. The body is only a hint: it names what changed
 * (`stock`, `tracking`, `invoice` or `order`) and nothing else is read from it. The handler schedules one refresh of that kind;
 * the refresh reads the data again through the authenticated connector.
 *
 * The secret is 32 random bytes in hex, stored encrypted among the connection's secrets under the reserved name
 * `inbound_secret`, and returned once by `enable()`. Enabling again replaces it.
 */
final class InboundHandler {

	/**
	 * Name of the secret among the connection's secrets.
	 */
	public const SECRET_NAME = 'inbound_secret';

	/**
	 * Messages per connection and source address per minute.
	 */
	public const RATE_LIMIT = 30;

	/**
	 * Messages per connection per minute from all addresses together.
	 */
	public const CONNECTION_RATE_LIMIT = 300;

	/**
	 * Largest body in bytes.
	 */
	public const MAX_BODY = 65536;

	/**
	 * Seconds a timestamp may differ from now.
	 */
	public const WINDOW = 300;

	/**
	 * Seconds a signature is remembered.
	 */
	public const REPLAY_TTL = 600;

	/**
	 * What a hint may name.
	 */
	public const ENTITIES = array( 'stock', 'tracking', 'invoice', 'order' );

	/**
	 * Schedules the refresh: function( int $connectionId, string $entity ): void.
	 *
	 * @var \Closure
	 */
	private \Closure $schedule;

	/**
	 * The address the request came from: function(): string. `REMOTE_ADDR` by default (never a forwarded header, which anyone can
	 * set).
	 *
	 * @var \Closure
	 */
	private \Closure $remoteAddress;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectionRepository $connections Connections.
	 * @param SecretBox            $box         Encryption (the key must exist to enable).
	 * @param Transients           $transients  Rate counter and replay cache.
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 * @param \Closure|null        $schedule    Schedules the refresh; a single WordPress event by default.
	 * @param \Closure|null        $remoteAddress The caller's address; `REMOTE_ADDR` by default.
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly SecretBox $box,
		private readonly Transients $transients,
		private readonly Logger $logger,
		private readonly Clock $clock,
		?\Closure $schedule = null,
		?\Closure $remoteAddress = null
	) {
		$this->schedule      = $schedule ?? static function ( int $connectionId, string $entity ): void {
			wp_schedule_single_event( time() + 5, 'estasoft_sync_inbound_refresh', array( $connectionId, $entity ) );
		};
		$this->remoteAddress = $remoteAddress ?? static function (): string {
			$address = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
			return false === filter_var( $address, FILTER_VALIDATE_IP ) ? '' : $address;
		};
	}

	/**
	 * Turn inbound on and make a new secret.
	 *
	 * @param int $connectionId Connection.
	 * @return string The secret. It is shown once and cannot be read again.
	 *
	 * @throws JobException With status 404 when the connection does not exist, 409 (SYNC-CFG-002) when no encryption key is defined.
	 */
	public function enable( int $connectionId ): string {
		$connection = $this->connections->findWithoutSecrets( $connectionId );
		if ( null === $connection || ConnectionStatus::Disconnected === $connection->status ) {
			throw new JobException( __( 'That connection does not exist or is disconnected.', 'estasoft-erp-crm-sync-engine' ), 404 );
		}
		if ( ! $this->box->keyAvailable() ) {
			throw new JobException( ErrorCode::Cfg002->template(), 409, ErrorCode::Cfg002, array( 'key_line' => $this->box->generateKeyLine() ) );
		}
		$secret = bin2hex( random_bytes( 32 ) );
		try {
			$this->connections->update(
				$connectionId,
				array(
					'secrets'         => array( self::SECRET_NAME => $secret ),
					'inbound_enabled' => true,
				)
			);
		} catch ( CryptoException $e ) {
			throw new JobException( $e->getMessage(), 409, $e->errorCode() );
		}
		return $secret;
	}

	/**
	 * Turn inbound off and forget the secret.
	 *
	 * @param int $connectionId Connection.
	 *
	 * @throws JobException With status 404 when the connection does not exist.
	 */
	public function disable( int $connectionId ): void {
		if ( null === $this->connections->findWithoutSecrets( $connectionId ) ) {
			throw new JobException( __( 'That connection does not exist.', 'estasoft-erp-crm-sync-engine' ), 404 );
		}
		try {
			$this->connections->update(
				$connectionId,
				array(
					'secrets'         => array( self::SECRET_NAME => null ),
					'inbound_enabled' => false,
				)
			);
		} catch ( CryptoException $e ) {
			$this->connections->update( $connectionId, array( 'inbound_enabled' => false ) );
		}
	}

	/**
	 * Handle one message.
	 *
	 * @param int                 $connectionId Connection from the route.
	 * @param string              $rawBody      The body exactly as received.
	 * @param array<string,mixed> $headers      Request headers (any case; a value may be a list).
	 * @param string|null         $source       Address of the caller, or null to read it from the request.
	 * @return array{accepted:bool}
	 *
	 * @throws InboundRefused When the message is refused; carries the HTTP status.
	 */
	public function handle( int $connectionId, string $rawBody, array $headers, ?string $source = null ): array {
		$connection = $this->connections->findWithoutSecrets( $connectionId );
		if ( null === $connection || ConnectionStatus::Disconnected === $connection->status || ! $this->enabled( $connectionId ) ) {
			throw $this->refuse( 0, 404, __( 'Not found.', 'estasoft-erp-crm-sync-engine' ), true );
		}
		if ( $this->tooMany( $connectionId, $source ?? ( $this->remoteAddress )() ) ) {
			throw $this->refuse( $connectionId, 429, __( 'Too many requests. Try again in a minute.', 'estasoft-erp-crm-sync-engine' ), true );
		}
		if ( strlen( $rawBody ) > self::MAX_BODY ) {
			throw $this->refuse( $connectionId, 413, __( 'The message is too large.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$normal    = array();
		foreach ( $headers as $name => $value ) {
			$normal[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}
		$signature = strtolower( trim( (string) preg_replace( '/^sha256=/i', '', trim( $normal['x-estasoft-signature'] ?? '' ) ) ) );
		$header    = trim( $normal['x-estasoft-timestamp'] ?? '' );
		$stamp     = $this->timestamp( $header );
		if ( '' === $signature || null === $stamp || abs( $this->clock->now()->getTimestamp() - $stamp ) > self::WINDOW ) {
			throw $this->refuse( $connectionId, 401, __( 'The signature or the time is wrong.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$secret = $this->secret( $connectionId );
		if ( '' === $secret || ! hash_equals( hash_hmac( 'sha256', $header . '.' . $rawBody, $secret ), $signature ) ) {
			throw $this->refuse( $connectionId, 401, __( 'The signature or the time is wrong.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$seen = 'estasoft_sync_inb_seen_' . substr( hash( 'sha256', $connectionId . ':' . $signature ), 0, 40 );
		if ( false !== $this->transients->get( $seen ) ) {
			throw $this->refuse( $connectionId, 409, __( 'This message was already received.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$this->transients->set( $seen, 1, self::REPLAY_TTL );
		( $this->schedule )( $connectionId, $this->entity( $rawBody ) );
		return array( 'accepted' => true );
	}

	/**
	 * Whether inbound is on for a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	private function enabled( int $connectionId ): bool {
		$public = $this->connections->findPublic( $connectionId );
		return null !== $public && ! empty( $public['inbound_enabled'] );
	}

	/**
	 * The stored secret, or an empty string when it cannot be read.
	 *
	 * @param int $connectionId Connection.
	 */
	private function secret( int $connectionId ): string {
		try {
			$connection = $this->connections->find( $connectionId );
		} catch ( CryptoException $e ) {
			unset( $e );
			return '';
		}
		return null === $connection ? '' : $connection->secret( self::SECRET_NAME );
	}

	/**
	 * Count a message and say whether a limit of the minute is used up: one per source address, one for the connection as a whole.
	 * A source that is already over its own limit does not count toward the connection's, so one noisy address cannot lock the
	 * real sender out.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $source       Address of the caller ('' when unknown).
	 */
	private function tooMany( int $connectionId, string $source ): bool {
		$minute = (int) floor( $this->clock->now()->getTimestamp() / 60 );
		$mine   = $this->transients->increment( 'estasoft_sync_inb_rl_' . $connectionId . '_' . substr( hash( 'sha256', $source ), 0, 16 ) . '_' . $minute, 120 );
		if ( $mine > self::RATE_LIMIT ) {
			return true;
		}
		return $this->transients->increment( 'estasoft_sync_inb_rt_' . $connectionId . '_' . $minute, 120 ) > self::CONNECTION_RATE_LIMIT;
	}

	/**
	 * Read the time header: Unix seconds as digits, or a strict ISO 8601 time with a `T`, whole seconds (fractions allowed) and
	 * `Z` or an offset. Anything else, relative text such as "now" or "+1 hour" included, is not a time.
	 *
	 * @param string $value Header value.
	 * @return int|null Unix seconds, or null when it is not an accepted time.
	 */
	private function timestamp( string $value ): ?int {
		$value = trim( $value );
		if ( 1 === preg_match( '/^\d{9,11}$/', $value ) ) {
			return (int) $value;
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $value ) ) {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat( str_contains( $value, '.' ) ? 'Y-m-d\TH:i:s.up' : 'Y-m-d\TH:i:sp', $value );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}
		return $parsed->getTimestamp();
	}

	/**
	 * What the hint names.
	 *
	 * @param string $rawBody Body.
	 */
	private function entity( string $rawBody ): string {
		$data   = json_decode( $rawBody, true, 5 );
		$entity = is_array( $data ) && isset( $data['entity'] ) && is_string( $data['entity'] ) ? strtolower( $data['entity'] ) : '';
		return in_array( $entity, self::ENTITIES, true ) ? $entity : 'all';
	}

	/**
	 * Log a refusal (SYNC-INB-001) and make the exception.
	 *
	 * @param int    $connectionId Connection (0 when it does not exist).
	 * @param int    $status       HTTP status.
	 * @param string $message      Short sentence for the sender.
	 * @param bool   $capped       Log it at most once a minute per status and connection (for the refusals anyone can cause at will).
	 */
	private function refuse( int $connectionId, int $status, string $message, bool $capped = false ): InboundRefused {
		$quiet = false;
		if ( $capped ) {
			$key   = 'estasoft_sync_inb_log_' . $status . '_' . $connectionId;
			$quiet = false !== $this->transients->get( $key );
			if ( ! $quiet ) {
				$this->transients->set( $key, 1, 60 );
			}
		}
		if ( ! $quiet ) {
			$this->logger->info(
				ErrorCode::Inb001->template(),
				array(
					'connection_id' => $connectionId,
					'code'          => ErrorCode::Inb001,
					'http_status'   => $status,
				)
			);
		}
		return new InboundRefused( $message, $status );
	}
}
