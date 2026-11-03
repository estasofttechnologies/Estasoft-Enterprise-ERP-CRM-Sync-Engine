<?php
/**
 * Connections to external systems.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\ConnectionSettings;
use Estasoft\SyncEngine\Core\Crypto\CryptoException;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Database\Tables;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;

/**
 * Reads and writes `estasoft_sync_connections`. Secrets are encrypted before they reach the database and
 * are decrypted only by find() and forConnector(), which exist for sending. Everything else returns data
 * without secrets. A secret is write-only: no method returns one to a screen, and not one character of it either
 * (no "last four"). The row keeps only a presence flag per secret name.
 */
final class ConnectionRepository extends Repository {

	/**
	 * Set up the connection repository.
	 *
	 * @param \wpdb              $wpdb     Database.
	 * @param Tables             $tables   Table names.
	 * @param Clock              $clock    Time source.
	 * @param SecretBox          $box      Encryption.
	 * @param ConnectionSettings $settings Reliability settings rules.
	 */
	public function __construct(
		\wpdb $wpdb,
		Tables $tables,
		Clock $clock,
		private readonly SecretBox $box,
		private readonly ConnectionSettings $settings
	) {
		parent::__construct( $wpdb, $tables, $clock );
	}

	/**
	 * A connection with its secrets decrypted, for sending. Keep the object only for the length of a request.
	 *
	 * @param int $id Connection id.
	 * @return Connection|null Null when there is no such connection.
	 *
	 * @throws CryptoException When secrets exist and cannot be decrypted (key missing: SYNC-CFG-002; key changed: no code).
	 */
	public function find( int $id ): ?Connection {
		$row = $this->rowById( $id );
		return null === $row ? null : $this->hydrate( $row, true );
	}

	/**
	 * A connection without secrets, for places that never send.
	 *
	 * @param int $id Connection id.
	 */
	public function findWithoutSecrets( int $id ): ?Connection {
		$row = $this->rowById( $id );
		return null === $row ? null : $this->hydrate( $row, false );
	}

	/**
	 * The connection shaped for the screens (`Connection` in types.ts, without the `credentials` list, which the
	 * REST layer builds from the connector's fields). Never contains a secret.
	 *
	 * @param int $id Connection id.
	 * @return array<string,mixed>|null
	 */
	public function findPublic( int $id ): ?array {
		$row = $this->rowById( $id );
		return null === $row ? null : $this->publicArray( $row );
	}

	/**
	 * Every connection shaped for the screens, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$rows = $this->results( "SELECT * FROM {$this->tables->connections()} ORDER BY id ASC" );
		return array_map( fn( array $row ): array => $this->publicArray( $row ), $rows );
	}

	/**
	 * Every connection as an object without secrets, oldest first.
	 *
	 * @return array<int,Connection>
	 */
	public function allWithoutSecrets(): array {
		$rows = $this->results( "SELECT * FROM {$this->tables->connections()} ORDER BY id ASC" );
		return array_map( fn( array $row ): Connection => $this->hydrate( $row, false ), $rows );
	}

	/**
	 * The first connection of a connector that is not disconnected, with secrets, for sending.
	 *
	 * @param string $connectorId Connector id such as odoo.
	 *
	 * @throws CryptoException When secrets cannot be decrypted.
	 */
	public function forConnector( string $connectorId ): ?Connection {
		$row = $this->row(
			"SELECT * FROM {$this->tables->connections()} WHERE connector = %s AND status <> %s ORDER BY id ASC LIMIT 1",
			array( $connectorId, ConnectionStatus::Disconnected->value )
		);
		return null === $row ? null : $this->hydrate( $row, true );
	}

	/**
	 * Ids of connections that may send now: active, and not inside a timed pause.
	 *
	 * @return array<int,int>
	 */
	public function activeIds(): array {
		$rows = $this->results(
			"SELECT id FROM {$this->tables->connections()} WHERE status = %s AND (paused_until IS NULL OR paused_until <= %s) ORDER BY id ASC",
			array( ConnectionStatus::Active->value, $this->now() )
		);
		return array_map( static fn( array $row ): int => (int) $row['id'], $rows );
	}

	/**
	 * Ids of connections whose pause has a time limit that has passed or whose circuit probe is due.
	 *
	 * @return array<int,array{id:int,status:string,paused_reason:?string}>
	 */
	public function pausesDue(): array {
		$rows = $this->results(
			"SELECT id, status, paused_reason FROM {$this->tables->connections()} WHERE status IN (%s,%s) AND paused_until IS NOT NULL AND paused_until <= %s ORDER BY id ASC",
			array( ConnectionStatus::Paused->value, ConnectionStatus::PausedCircuit->value, $this->now() )
		);
		return array_map(
			static fn( array $row ): array => array(
				'id'            => (int) $row['id'],
				'status'        => (string) $row['status'],
				'paused_reason' => null === $row['paused_reason'] ? null : (string) $row['paused_reason'],
			),
			$rows
		);
	}

	/**
	 * Create a connection.
	 *
	 * `$config` holds the non-secret values. Three keys are lifted into columns: `connector` (required), `type`
	 * (erp, accounting or crm; default erp) and `label` (default: the connector id). The rest is stored as
	 * the connection's config (address, database, company, warehouses, version, capabilities).
	 *
	 * A CryptoException (SYNC-CFG-002) is passed on when secrets are given and no key is defined, and a ValidationException
	 * when a setting is out of range.
	 *
	 * @param array<string,mixed>  $config   Non-secret values.
	 * @param array<string,string> $secrets  Secret name to value (encrypted before saving).
	 * @param array<string,mixed>  $settings Reliability settings (validated; defaults fill the gaps).
	 * @return int The new connection id.
	 *
	 * @throws \InvalidArgumentException When `connector` is missing.
	 * @throws \RuntimeException         When the row could not be saved.
	 */
	public function create( array $config, array $secrets = array(), array $settings = array() ): int {
		$connector = isset( $config['connector'] ) ? trim( (string) $config['connector'] ) : '';
		if ( '' === $connector ) {
			throw new \InvalidArgumentException( 'A connection needs a connector id.' );
		}
		$type  = ConnectorType::tryFrom( (string) ( $config['type'] ?? 'erp' ) ) ?? ConnectorType::Erp;
		$label = isset( $config['label'] ) && '' !== trim( (string) $config['label'] ) ? trim( (string) $config['label'] ) : $connector;
		unset( $config['connector'], $config['type'], $config['label'] );

		$settings = $this->settings->merge( $this->settings->defaults(), $settings );
		$secrets  = $this->cleanSecrets( $secrets );
		$now      = $this->now();

		$id = $this->insert(
			$this->tables->connections(),
			array(
				'connector'         => $connector,
				'type'              => $type->value,
				'label'             => mb_substr( $label, 0, 191 ),
				'status'            => ConnectionStatus::Active->value,
				'config_json'       => Json::encode( $config ),
				'settings_json'     => Json::encode( $settings ),
				'secrets_enc'       => $this->box->encrypt( $secrets ),
				'secret_hints_json' => Json::encode( $this->hints( $secrets ) ),
				'key_fingerprint'   => array() === $secrets ? '' : $this->box->fingerprint(),
				'breaker_failures'  => 0,
				'inbound_enabled'   => 0,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		if ( 0 === $id ) {
			throw new \RuntimeException( 'The connection could not be saved.' );
		}
		return $id;
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- CryptoException and ValidationException come from the calls update() makes, not from a throw in its own body.
	/**
	 * Change a connection. Supported keys: `label`, `config` (merged; a null value removes a key), `secrets`
	 * (merged; an empty string keeps the saved value, null removes it), `settings` (merged and validated),
	 * `credential_expires_at` (MySQL datetime or null), `credential_warn_days`, `inbound_enabled`.
	 *
	 * When the saved secrets were written with another key (the key fingerprint differs) they cannot be read, so they
	 * cannot be merged. Then every secret that is saved has to be given again (a value) or removed (null); an empty
	 * string, which means "keep", is refused, so a partial save never leaves unreadable secrets behind.
	 *
	 * A CryptoException is passed on when secrets are changed and no key is defined, or only some secrets are sent while the
	 * key has changed, and a ValidationException when a setting is out of range.
	 *
	 * @param int                 $id      Connection id.
	 * @param array<string,mixed> $changes Changes.
	 *
	 * @throws \OutOfBoundsException When the connection does not exist.
	 * @throws CryptoException       When secrets are changed and cannot be encrypted or merged (see above).
	 * @throws ValidationException   When a setting is out of range.
	 */
	public function update( int $id, array $changes ): void {
		$row = $this->rowById( $id );
		if ( null === $row ) {
			throw new \OutOfBoundsException( 'Connection not found.' );
		}
		$data = array( 'updated_at' => $this->now() );

		if ( isset( $changes['label'] ) ) {
			$data['label'] = mb_substr( trim( (string) $changes['label'] ), 0, 191 );
		}
		if ( isset( $changes['config'] ) && is_array( $changes['config'] ) ) {
			$config = Json::decode( (string) $row['config_json'] );
			foreach ( $changes['config'] as $key => $value ) {
				if ( null === $value ) {
					unset( $config[ $key ] );
				} else {
					$config[ $key ] = $value;
				}
			}
			$data['config_json'] = Json::encode( $config );
		}
		if ( isset( $changes['settings'] ) && is_array( $changes['settings'] ) ) {
			$current               = $this->settings->normalise( Json::decode( (string) $row['settings_json'] ) );
			$data['settings_json'] = Json::encode( $this->settings->merge( $current, $changes['settings'] ) );
		}
		if ( isset( $changes['secrets'] ) && is_array( $changes['secrets'] ) ) {
			$secrets                   = $this->mergeSecrets( $row, $changes['secrets'] );
			$data['secrets_enc']       = $this->box->encrypt( $secrets );
			$data['secret_hints_json'] = Json::encode( $this->hints( $secrets ) );
			$data['key_fingerprint']   = array() === $secrets ? '' : $this->box->fingerprint();
		}
		if ( array_key_exists( 'credential_expires_at', $changes ) ) {
			$data['credential_expires_at'] = null === $changes['credential_expires_at'] ? null : (string) $changes['credential_expires_at'];
		}
		if ( isset( $changes['credential_warn_days'] ) ) {
			$data['credential_warn_days'] = max( 1, min( 90, (int) $changes['credential_warn_days'] ) );
		}
		if ( isset( $changes['inbound_enabled'] ) ) {
			$data['inbound_enabled'] = $changes['inbound_enabled'] ? 1 : 0;
		}
		$this->updateById( $this->tables->connections(), $data, $id );
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Set the status. Becoming active clears the pause and the failure count.
	 *
	 * @param int              $id          Connection id.
	 * @param ConnectionStatus $status      New status.
	 * @param string|null      $pausedUntil MySQL datetime (UTC) when a pause ends or the next circuit probe is due.
	 * @param string|null      $reason      Why it is paused: `person`, `circuit`, `sign_in` or `key_missing`.
	 */
	public function setStatus( int $id, ConnectionStatus $status, ?string $pausedUntil = null, ?string $reason = null ): void {
		$now  = $this->now();
		$data = array(
			'status'          => $status->value,
			'paused_until'    => ConnectionStatus::Active === $status ? null : $pausedUntil,
			'paused_reason'   => ConnectionStatus::Active === $status ? null : $reason,
			'disconnected_at' => ConnectionStatus::Disconnected === $status ? $now : null,
			'updated_at'      => $now,
		);
		if ( ConnectionStatus::Active === $status ) {
			$data['breaker_failures'] = 0;
		}
		$this->updateById( $this->tables->connections(), $data, $id );
	}

	/**
	 * Pause an active connection because of the circuit breaker. Only one caller can win: the statement changes the
	 * row only while it is still active.
	 *
	 * @param int    $id          Connection id.
	 * @param string $nextProbeAt MySQL datetime (UTC) of the next health probe.
	 * @return bool True when this call tripped the breaker.
	 */
	public function tripCircuit( int $id, string $nextProbeAt ): bool {
		return $this->run(
			"UPDATE {$this->tables->connections()} SET status = %s, paused_until = %s, paused_reason = %s, updated_at = %s WHERE id = %d AND status = %s",
			array( ConnectionStatus::PausedCircuit->value, $nextProbeAt, 'circuit', $this->now(), $id, ConnectionStatus::Active->value )
		) > 0;
	}

	/**
	 * Move the time of the next health probe of a circuit-paused connection.
	 *
	 * @param int    $id          Connection id.
	 * @param string $nextProbeAt MySQL datetime (UTC).
	 */
	public function scheduleProbe( int $id, string $nextProbeAt ): void {
		$this->run(
			"UPDATE {$this->tables->connections()} SET paused_until = %s WHERE id = %d AND status = %s",
			array( $nextProbeAt, $id, ConnectionStatus::PausedCircuit->value )
		);
	}

	/**
	 * Resume a connection that the circuit breaker paused. Only one caller can win.
	 *
	 * @param int $id Connection id.
	 * @return bool True when this call resumed it.
	 */
	public function resumeFromCircuit( int $id ): bool {
		return $this->run(
			"UPDATE {$this->tables->connections()} SET status = %s, paused_until = NULL, paused_reason = NULL, breaker_failures = 0, updated_at = %s WHERE id = %d AND status = %s",
			array( ConnectionStatus::Active->value, $this->now(), $id, ConnectionStatus::PausedCircuit->value )
		) > 0;
	}

	/**
	 * Add one to the count of failures in a row, in one statement.
	 *
	 * @param int $id Connection id.
	 * @return int The count after the change.
	 */
	public function incrementFailures( int $id ): int {
		$this->run(
			"UPDATE {$this->tables->connections()} SET breaker_failures = breaker_failures + 1, updated_at = %s WHERE id = %d",
			array( $this->now(), $id )
		);
		return (int) $this->value( "SELECT breaker_failures FROM {$this->tables->connections()} WHERE id = %d", array( $id ) );
	}

	/**
	 * Set the count of failures in a row to zero.
	 *
	 * @param int $id Connection id.
	 */
	public function resetFailures( int $id ): void {
		$this->run( "UPDATE {$this->tables->connections()} SET breaker_failures = 0 WHERE id = %d AND breaker_failures <> 0", array( $id ) );
	}

	/**
	 * Note a successful call: the time, and a moving average of the response time.
	 *
	 * @param int $id Connection id.
	 * @param int $ms Response time in milliseconds.
	 */
	public function recordSuccess( int $id, int $ms ): void {
		$ms = max( 0, $ms );
		$this->run(
			"UPDATE {$this->tables->connections()} SET last_success_at = %s, typical_response_ms = CASE WHEN typical_response_ms IS NULL THEN %d ELSE ROUND(typical_response_ms * 0.8 + %d * 0.2) END WHERE id = %d",
			array( $this->now(), $ms, $ms, $id )
		);
	}

	/**
	 * Note a failed call.
	 *
	 * @param int $id Connection id.
	 * @param int $ms Time spent in milliseconds.
	 */
	public function recordFailure( int $id, int $ms ): void {
		unset( $ms );
		$this->run( "UPDATE {$this->tables->connections()} SET last_failure_at = %s WHERE id = %d", array( $this->now(), $id ) );
	}

	/**
	 * Disconnect: wipe the secrets and mark the connection disconnected. The row stays, so the queue, mapping and
	 * history keep pointing at it, and "Replace the key" or a new sign-in can bring it back.
	 *
	 * @param int $id Connection id.
	 */
	public function delete( int $id ): void {
		$now = $this->now();
		$this->updateById(
			$this->tables->connections(),
			array(
				'status'                => ConnectionStatus::Disconnected->value,
				'secrets_enc'           => '',
				'secret_hints_json'     => '{}',
				'key_fingerprint'       => '',
				'paused_until'          => null,
				'paused_reason'         => null,
				'breaker_failures'      => 0,
				'credential_expires_at' => null,
				'disconnected_at'       => $now,
				'updated_at'            => $now,
			),
			$id
		);
	}

	/**
	 * Raw row by id.
	 *
	 * @param int $id Connection id.
	 * @return array<string,mixed>|null
	 */
	private function rowById( int $id ): ?array {
		return $this->row( "SELECT * FROM {$this->tables->connections()} WHERE id = %d", array( $id ) );
	}

	/**
	 * Build the domain object.
	 *
	 * @param array<string,mixed> $row            Raw row.
	 * @param bool                $withSecrets    Whether to decrypt the secrets.
	 *
	 * @throws CryptoException When decryption was asked for and failed.
	 */
	private function hydrate( array $row, bool $withSecrets ): Connection {
		return new Connection(
			(int) $row['id'],
			(string) $row['connector'],
			ConnectorType::tryFrom( (string) $row['type'] ) ?? ConnectorType::Erp,
			(string) $row['label'],
			ConnectionStatus::tryFrom( (string) $row['status'] ) ?? ConnectionStatus::Disconnected,
			Json::decode( (string) $row['config_json'] ),
			$this->settings->normalise( Json::decode( (string) $row['settings_json'] ) ),
			$withSecrets ? $this->box->decrypt( (string) $row['secrets_enc'] ) : array(),
			(int) $row['breaker_failures']
		);
	}

	/**
	 * The screen shape of a row.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function publicArray( array $row ): array {
		$config = Json::decode( (string) $row['config_json'] );
		$hints  = Json::decode( (string) $row['secret_hints_json'] );
		$secret = array();
		foreach ( array_keys( $hints ) as $name ) {
			$secret[ (string) $name ] = true;
		}
		$reason = $row['paused_reason'] ?? null;
		return array(
			'id'                    => (int) $row['id'],
			'connector'             => (string) $row['connector'],
			'label'                 => (string) $row['label'],
			'type'                  => (string) $row['type'],
			'status'                => (string) $row['status'],
			'version'               => (string) ( $config['version'] ?? '' ),
			'address'               => (string) ( $config['address'] ?? '' ),
			'paused_until'          => Time::iso( self::stringOrNull( $row['paused_until'] ) ),
			'paused_reason'         => self::publicReason( null === $reason ? null : (string) $reason ),
			'last_success_at'       => Time::iso( self::stringOrNull( $row['last_success_at'] ) ),
			'last_failure_at'       => Time::iso( self::stringOrNull( $row['last_failure_at'] ) ),
			'failures_in_a_row'     => (int) $row['breaker_failures'],
			'typical_response_ms'   => self::intOrNull( $row['typical_response_ms'] ),
			'credential_expires_at' => Time::iso( self::stringOrNull( $row['credential_expires_at'] ) ),
			'capabilities'          => is_array( $config['capabilities'] ?? null ) ? $config['capabilities'] : array(),
			'company'               => (string) ( $config['company'] ?? '' ),
			'warehouses'            => is_array( $config['warehouses'] ?? null ) ? $config['warehouses'] : array(),
			'settings'              => $this->settings->normalise( Json::decode( (string) $row['settings_json'] ) ),
			'inbound_enabled'       => (bool) $row['inbound_enabled'],
			'created_at'            => Time::iso( (string) $row['created_at'] ),
			'config'                => $config,
			'secret_hints'          => $secret,
			'secrets_readable'      => '' === (string) $row['secrets_enc'] || $this->box->reads( (string) $row['key_fingerprint'] ),
			'credential_warn_days'  => (int) $row['credential_warn_days'],
		);
	}

	/**
	 * The pause reason as the screens and hooks name it: `person`, `circuit`, `sign_in` or `key_missing`. Rows written by
	 * the first development builds say `auth`, which was always a sign-in problem.
	 *
	 * @param string|null $reason Stored value.
	 */
	public static function publicReason( ?string $reason ): ?string {
		if ( null === $reason || '' === $reason ) {
			return null;
		}
		if ( 'auth' === $reason ) {
			return 'sign_in';
		}
		return in_array( $reason, array( 'circuit', 'sign_in', 'key_missing' ), true ) ? $reason : 'person';
	}

	/**
	 * Merge changed secrets over the saved ones.
	 *
	 * @param array<string,mixed> $row     Raw row.
	 * @param array<string,mixed> $changes Name to value; '' keeps, null removes.
	 * @return array<string,string>
	 *
	 * @throws CryptoException When no key is defined, or the saved secrets are unreadable and the change is partial.
	 */
	private function mergeSecrets( array $row, array $changes ): array {
		try {
			$existing = $this->box->decrypt( (string) $row['secrets_enc'] );
		} catch ( CryptoException $e ) {
			if ( null !== $e->errorCode() ) {
				throw $e;
			}
			// The key changed or the data is damaged. The saved values cannot be kept, so the change has to cover every
			// one of them; otherwise a partial save would silently drop the rest.
			$saved = array_keys( Json::decode( (string) $row['secret_hints_json'] ) );
			foreach ( $saved as $name ) {
				$name = (string) $name;
				if ( ! array_key_exists( $name, $changes ) || ( null !== $changes[ $name ] && '' === (string) $changes[ $name ] ) ) {
					throw CryptoException::partialSaveRefused();
				}
			}
			$existing = array();
		}
		foreach ( $changes as $name => $value ) {
			$name = (string) $name;
			if ( null === $value ) {
				unset( $existing[ $name ] );
			} elseif ( '' !== (string) $value ) {
				$existing[ $name ] = (string) $value;
			}
		}
		return $this->cleanSecrets( $existing );
	}

	/**
	 * Keep only non-empty string values.
	 *
	 * @param array<string,mixed> $secrets Candidate secrets.
	 * @return array<string,string>
	 */
	private function cleanSecrets( array $secrets ): array {
		$clean = array();
		foreach ( $secrets as $name => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$clean[ (string) $name ] = (string) $value;
			}
		}
		return $clean;
	}

	/**
	 * Which secrets exist: a presence flag per name, never a character of the value.
	 *
	 * @param array<string,string> $secrets Secrets.
	 * @return array<string,bool>
	 */
	private function hints( array $secrets ): array {
		return array_fill_keys( array_keys( $secrets ), true );
	}
}
