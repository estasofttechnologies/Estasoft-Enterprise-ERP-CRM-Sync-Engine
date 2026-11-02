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
 * 2. fewer than 30 messages arrived for it in the last minute (counted before anything else, so guessing is slow);
 * 3. the body is at most 64 KB;
 * 4. the timestamp is within 5 minutes of now;
 * 5. the signature of `{timestamp}.{rawbody}` matches (compared with `hash_equals`);
 * 6. the same signature was not seen in the last 10 minutes (replay).
 *
 * A refusal is logged as SYNC-INB-001 (info) and answered with `InboundRefused`. The body is only a hint: it names what changed
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
	 * Messages per connection per minute.
	 */
	public const RATE_LIMIT = 30;

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
	 * Set up with what it depends on.
	 *
	 * @param ConnectionRepository $connections Connections.
	 * @param SecretBox            $box         Encryption (the key must exist to enable).
	 * @param Transients           $transients  Rate counter and replay cache.
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 * @param \Closure|null        $schedule    Schedules the refresh; a single WordPress event by default.
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly SecretBox $box,
		private readonly Transients $transients,
		private readonly Logger $logger,
		private readonly Clock $clock,
		?\Closure $schedule = null
	) {
		$this->schedule = $schedule ?? static function ( int $connectionId, string $entity ): void {
			wp_schedule_single_event( time() + 5, 'estasoft_sync_inbound_refresh', array( $connectionId, $entity ) );
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
	 * @return array{accepted:bool}
	 *
	 * @throws InboundRefused When the message is refused; carries the HTTP status.
	 */
	public function handle( int $connectionId, string $rawBody, array $headers ): array {
		$connection = $this->connections->findWithoutSecrets( $connectionId );
		if ( null === $connection || ConnectionStatus::Disconnected === $connection->status || ! $this->enabled( $connectionId ) ) {
			throw new InboundRefused( __( 'Not found.', 'estasoft-erp-crm-sync-engine' ), 404 );
		}
		if ( $this->tooMany( $connectionId ) ) {
			throw new InboundRefused( __( 'Too many requests. Try again in a minute.', 'estasoft-erp-crm-sync-engine' ), 429 );
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
	 * Count a message and say whether the minute's limit is used up.
	 *
	 * @param int $connectionId Connection.
	 */
	private function tooMany( int $connectionId ): bool {
		$key   = 'estasoft_sync_inb_rl_' . $connectionId . '_' . (int) floor( $this->clock->now()->getTimestamp() / 60 );
		$count = (int) $this->transients->get( $key ) + 1;
		$this->transients->set( $key, $count, 120 );
		return $count > self::RATE_LIMIT;
	}

	/**
	 * Read the time header.
	 *
	 * @param string $value Header value.
	 * @return int|null Unix seconds.
	 */
	private function timestamp( string $value ): ?int {
		$value = trim( $value );
		if ( 1 === preg_match( '/^\d{9,11}$/', $value ) ) {
			return (int) $value;
		}
		$parsed = '' === $value ? false : date_create_immutable( $value );
		return false === $parsed ? null : $parsed->getTimestamp();
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
	 * @param int    $connectionId Connection.
	 * @param int    $status       HTTP status.
	 * @param string $message      Short sentence for the sender.
	 */
	private function refuse( int $connectionId, int $status, string $message ): InboundRefused {
		$this->logger->info(
			ErrorCode::Inb001->template(),
			array(
				'connection_id' => $connectionId,
				'code'          => ErrorCode::Inb001,
				'http_status'   => $status,
			)
		);
		return new InboundRefused( $message, $status );
	}
}
