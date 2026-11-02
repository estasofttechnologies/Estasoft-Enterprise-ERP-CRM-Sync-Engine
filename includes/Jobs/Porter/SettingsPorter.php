<?php
/**
 * Export and import of settings, mappings and limits.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Porter;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\ConnectionSettings;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\MappingRepository;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;
use Estasoft\SyncEngine\Jobs\Stock\StockService;
use Estasoft\SyncEngine\Jobs\Tracking\TrackingSettings;

/**
 * SET-4 and SET-5.
 *
 * The file is versioned JSON:
 *
 *     { "estasoft_sync": 1, "exported_at": "...", "plugin_version": "...",
 *       "settings": { ...Settings... },
 *       "mappings": { "<connector>": { "<type>": [ rules ] } },
 *       "connection_settings": { "<connector>": { "reliability": {...}, "stock": {...}, "tracking": {...} } } }
 *
 * It holds no credential, no address, no order data and no log. Import rejects bad JSON, any other schema version, a file over
 * 1 MB and any key it does not know. The preview lists the changes in words and changes nothing. Applying is all or nothing: every
 * value of every group (settings, reliability, stock, tracking, and the rules of each mapping) is checked before the first write.
 * It then updates the settings and the limits, and saves each mapping as a DRAFT of the connection that has the same connector
 * (never a live version); a mapping for a system that is not connected here is skipped and said so.
 */
final class SettingsPorter {

	/**
	 * Schema version written and read.
	 */
	public const SCHEMA = 1;

	/**
	 * Largest file accepted, in bytes.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Mapping types that are versioned and so travel with the file.
	 */
	public const TYPES = array( 'orders', 'customers', 'refunds', 'stock', 'tracking' );

	/**
	 * Most rules accepted for one mapping.
	 */
	private const MAX_RULES = 500;

	/**
	 * Top-level keys of the file.
	 */
	private const KEYS = array( 'estasoft_sync', 'exported_at', 'plugin_version', 'settings', 'mappings', 'connection_settings' );

	/**
	 * Saves a mapping draft: function( int $connectionId, string $type, array $rules, string $note, string $by ): mixed.
	 *
	 * @var \Closure
	 */
	private \Closure $saveDraft;

	/**
	 * Names a user: function( int $userId ): string.
	 *
	 * @var \Closure
	 */
	private \Closure $userName;

	/**
	 * Checks mapping rules: function( int $connectionId, string $type, array $rules ): array of problems, each with a `message` (a
	 * RuleError from the mapping service) or a string. Empty means the rules are acceptable. Null skips the check.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $validateRules;

	/**
	 * Set up with what it depends on.
	 *
	 * @param Settings             $settings    Plugin settings.
	 * @param ConnectionSettings   $limits      Rules for the reliability settings.
	 * @param ConnectionRepository $connections Connections.
	 * @param MappingRepository    $mappings    Mapping versions (live rules for export).
	 * @param StockService         $stock       Stock rules.
	 * @param TrackingSettings     $tracking    Tracking settings.
	 * @param ShopEnvironment      $environment Plugin version.
	 * @param Logger               $logger      Log.
	 * @param Clock                $clock       Time source.
	 * @param \Closure             $saveDraft   Saves a mapping draft (the mapping service, which validates the rules).
	 * @param \Closure|null        $userName    Names a user; WordPress by default.
	 * @param \Closure|null        $validateRules Checks mapping rules before anything is written (the mapping service's validator).
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ConnectionSettings $limits,
		private readonly ConnectionRepository $connections,
		private readonly MappingRepository $mappings,
		private readonly StockService $stock,
		private readonly TrackingSettings $tracking,
		private readonly ShopEnvironment $environment,
		private readonly Logger $logger,
		private readonly Clock $clock,
		\Closure $saveDraft,
		?\Closure $userName = null,
		?\Closure $validateRules = null
	) {
		$this->saveDraft     = $saveDraft;
		$this->validateRules = $validateRules;
		$this->userName      = $userName ?? static function ( int $userId ): string {
			$user = get_userdata( $userId );
			/* translators: %d: user id. */
			return false === $user ? sprintf( __( 'User #%d', 'estasoft-erp-crm-sync-engine' ), $userId ) : (string) $user->display_name;
		};
	}

	/**
	 * Build the export file.
	 *
	 * @return array{filename:string,json:string}
	 */
	public function export(): array {
		$now      = $this->clock->now();
		$mappings = array();
		$limits   = array();
		foreach ( $this->connections->all() as $connection ) {
			if ( ConnectionStatus::Disconnected->value === $connection['status'] || isset( $limits[ $connection['connector'] ] ) ) {
				continue;
			}
			$id        = (int) $connection['id'];
			$connector = (string) $connection['connector'];
			foreach ( self::TYPES as $type ) {
				$live = $this->mappings->live( $id, $type );
				if ( null !== $live && is_array( $live['rules'] ?? null ) ) {
					$mappings[ $connector ][ $type ] = $live['rules'];
				}
			}
			$rules                = $this->stock->rules( $id );
			$limits[ $connector ] = array(
				'reliability' => $connection['settings'],
				'stock'       => array_intersect_key( $rules, array_flip( array( 'enabled', 'interval_minutes', 'safety_buffer', 'subtract_held' ) ) ),
				'tracking'    => $this->tracking->get( $id ),
			);
		}
		$document = array(
			'estasoft_sync'       => self::SCHEMA,
			'exported_at'         => $now->format( Time::ISO ),
			'plugin_version'      => $this->environment->pluginVersion(),
			'settings'            => $this->settings->get(),
			'mappings'            => (object) $mappings,
			'connection_settings' => (object) $limits,
		);
		return array(
			'filename' => 'estasoft-sync-settings-' . $now->format( 'Y-m-d' ) . '.json',
			'json'     => (string) wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Say in words what applying a file would change. Changes nothing.
	 *
	 * @param string $json File contents.
	 * @return array{preview:array<int,string>}
	 *
	 * @throws ValidationException When the file is not acceptable.
	 */
	public function preview( string $json ): array {
		$file  = $this->read( $json );
		$lines = array();

		$current = $this->settings->get();
		foreach ( $file['settings'] as $key => $value ) {
			if ( 'notifications' === $key ) {
				foreach ( (array) $value as $field => $new ) {
					$old = $current['notifications'][ $field ] ?? null;
					if ( $this->same( $old, $new ) ) {
						continue;
					}
					$lines[] = sprintf(
						/* translators: 1: setting name, 2: old value, 3: new value. */
						__( 'Notifications, %1$s: from %2$s to %3$s.', 'estasoft-erp-crm-sync-engine' ),
						$this->label( (string) $field ),
						$this->show( $old ),
						$this->show( $new )
					);
				}
				continue;
			}
			if ( ! $this->same( $current[ $key ] ?? null, $value ) ) {
				$lines[] = sprintf(
					/* translators: 1: setting name, 2: old value, 3: new value. */
					__( '%1$s: from %2$s to %3$s.', 'estasoft-erp-crm-sync-engine' ),
					$this->label( (string) $key ),
					$this->show( $current[ $key ] ?? null ),
					$this->show( $value )
				);
			}
		}

		foreach ( $file['connection_settings'] as $connector => $groups ) {
			$connection = $this->connectionFor( (string) $connector );
			if ( null === $connection ) {
				$lines[] = sprintf(
					/* translators: %s: connector id. */
					__( 'Limits for %s are skipped because it is not connected here.', 'estasoft-erp-crm-sync-engine' ),
					(string) $connector
				);
				continue;
			}
			$now = array(
				'reliability' => $connection['settings'],
				'stock'       => array_intersect_key( $this->stock->rules( (int) $connection['id'] ), array_flip( array( 'enabled', 'interval_minutes', 'safety_buffer', 'subtract_held' ) ) ),
				'tracking'    => $this->tracking->get( (int) $connection['id'] ),
			);
			foreach ( $groups as $group => $values ) {
				foreach ( (array) $values as $key => $value ) {
					if ( ! $this->same( $now[ $group ][ $key ] ?? null, $value ) ) {
						$lines[] = sprintf(
							/* translators: 1: connector id, 2: setting name, 3: old value, 4: new value. */
							__( '%1$s, %2$s: from %3$s to %4$s.', 'estasoft-erp-crm-sync-engine' ),
							(string) $connector,
							$this->label( (string) $key ),
							$this->show( $now[ $group ][ $key ] ?? null ),
							$this->show( $value )
						);
					}
				}
			}
		}

		foreach ( $file['mappings'] as $connector => $types ) {
			$connection = $this->connectionFor( (string) $connector );
			foreach ( $types as $type => $rules ) {
				if ( null === $connection ) {
					$lines[] = sprintf(
						/* translators: 1: mapping type, 2: connector id. */
						__( 'The %1$s mapping for %2$s is skipped because it is not connected here.', 'estasoft-erp-crm-sync-engine' ),
						(string) $type,
						(string) $connector
					);
					continue;
				}
				if ( $this->sameAsLive( (int) $connection['id'], (string) $type, $rules ) ) {
					continue;
				}
				$lines[] = sprintf(
					/* translators: 1: connector id, 2: mapping type, 3: number of rules. */
					_n( 'A draft of the %2$s mapping for %1$s is created with %3$d rule. The live mapping does not change.', 'A draft of the %2$s mapping for %1$s is created with %3$d rules. The live mapping does not change.', count( $rules ), 'estasoft-erp-crm-sync-engine' ),
					(string) $connector,
					(string) $type,
					count( $rules )
				);
			}
		}
		if ( array() === $lines ) {
			$lines[] = __( 'Nothing would change.', 'estasoft-erp-crm-sync-engine' );
		}
		return array( 'preview' => $lines );
	}

	/**
	 * Apply a file: settings and limits are updated, mappings become drafts.
	 *
	 * @param string $json   File contents.
	 * @param int    $userId Who imports.
	 * @return array{applied:bool,drafts:int,skipped:array<int,string>}
	 *
	 * @throws ValidationException When the file is not acceptable or a value is out of range.
	 */
	public function apply( string $json, int $userId ): array {
		$file = $this->read( $json );

		// All or nothing: every group is checked before the first write, so a bad file changes nothing.
		$this->assertAcceptable( $file );

		$skipped = array();
		$drafts  = 0;
		if ( array() !== $file['settings'] ) {
			$this->settings->update( $file['settings'] );
		}
		foreach ( $file['connection_settings'] as $connector => $groups ) {
			$connection = $this->connectionFor( (string) $connector );
			if ( null === $connection ) {
				$skipped[] = (string) $connector;
				continue;
			}
			$id = (int) $connection['id'];
			if ( ! empty( $groups['reliability'] ) ) {
				$this->connections->update( $id, array( 'settings' => $groups['reliability'] ) );
			}
			$extra = array_merge( (array) ( $groups['stock'] ?? array() ), (array) ( $groups['tracking'] ?? array() ) );
			if ( array() !== $extra ) {
				$this->stock->saveRules( $id, $extra );
			}
		}
		$by = ( $this->userName )( $userId );
		foreach ( $file['mappings'] as $connector => $types ) {
			$connection = $this->connectionFor( (string) $connector );
			if ( null === $connection ) {
				$skipped[] = (string) $connector;
				continue;
			}
			foreach ( $types as $type => $rules ) {
				if ( $this->sameAsLive( (int) $connection['id'], (string) $type, $rules ) ) {
					continue;
				}
				try {
					( $this->saveDraft )( (int) $connection['id'], (string) $type, $rules, __( 'Imported from a settings file', 'estasoft-erp-crm-sync-engine' ), $by );
					++$drafts;
				} catch ( \Throwable $e ) {
					$skipped[] = $connector . ':' . $type;
				}
			}
		}
		$this->logger->info(
			sprintf(
				/* translators: 1: user id, 2: number of mapping drafts created. */
				_n( 'Settings were imported by user #%1$d. %2$d mapping draft was created.', 'Settings were imported by user #%1$d. %2$d mapping drafts were created.', $drafts, 'estasoft-erp-crm-sync-engine' ),
				$userId,
				$drafts
			)
		);
		return array(
			'applied' => true,
			'drafts'  => $drafts,
			'skipped' => array_values( array_unique( $skipped ) ),
		);
	}

	/**
	 * Check every value of a parsed file: the plugin settings, each connection's reliability, stock and tracking fields, and the
	 * rules of every mapping that would become a draft. Writes nothing.
	 *
	 * @param array{settings:array<string,mixed>,mappings:array<string,array<string,array<int,array<string,mixed>>>>,connection_settings:array<string,array<string,array<string,mixed>>>} $file Parsed file.
	 *
	 * @throws ValidationException With the message under `json` naming the first problem.
	 */
	private function assertAcceptable( array $file ): void {
		$problems = $this->settings->validate( $file['settings'] );
		foreach ( $file['connection_settings'] as $connector => $groups ) {
			$problems  += $this->limits->validate( (array) ( $groups['reliability'] ?? array() ) );
			$connection = $this->connectionFor( (string) $connector );
			if ( null !== $connection ) {
				$extra = array_merge( (array) ( $groups['stock'] ?? array() ), (array) ( $groups['tracking'] ?? array() ) );
				if ( array() !== $extra ) {
					$problems += $this->stock->validateRules( (int) $connection['id'], $extra );
				}
			}
		}
		if ( array() !== $problems ) {
			throw new ValidationException(
				array(
					'json' => sprintf(
						/* translators: %s: name of the setting that is not valid. */
						__( 'The file has a value that is not allowed (%s). Nothing was imported.', 'estasoft-erp-crm-sync-engine' ),
						$this->label( (string) array_key_first( $problems ) )
					),
				)
			);
		}
		if ( null === $this->validateRules ) {
			return;
		}
		foreach ( $file['mappings'] as $connector => $types ) {
			$connection = $this->connectionFor( (string) $connector );
			if ( null === $connection ) {
				continue;
			}
			foreach ( $types as $type => $rules ) {
				if ( $this->sameAsLive( (int) $connection['id'], (string) $type, $rules ) ) {
					continue;
				}
				$found = ( $this->validateRules )( (int) $connection['id'], (string) $type, $rules );
				if ( array() !== $found ) {
					$first   = reset( $found );
					$message = is_object( $first ) && isset( $first->message ) ? (string) $first->message : ( is_array( $first ) ? (string) ( $first['message'] ?? '' ) : (string) $first );
					throw new ValidationException(
						array(
							'json' => sprintf(
								/* translators: 1: mapping type, 2: connector id, 3: what is wrong. */
								__( 'The %1$s mapping for %2$s has a problem: %3$s Nothing was imported.', 'estasoft-erp-crm-sync-engine' ),
								$this->plain( (string) $type ),
								$this->plain( (string) $connector ),
								'' === $message ? __( 'its rules are not valid.', 'estasoft-erp-crm-sync-engine' ) : rtrim( mb_substr( $message, 0, 200 ), '.' ) . '.'
							),
						)
					);
				}
			}
		}
	}

	/**
	 * Parse and check a file.
	 *
	 * @param string $json File contents.
	 * @return array{settings:array<string,mixed>,mappings:array<string,array<string,array<int,array<string,mixed>>>>,connection_settings:array<string,array<string,array<string,mixed>>>}
	 *
	 * @throws ValidationException With the message under `json`.
	 */
	private function read( string $json ): array {
		$fail = static function ( string $message ): ValidationException {
			return new ValidationException( array( 'json' => $message ) );
		};
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw $fail( __( 'The file is larger than 1 MB, so it is not a settings file from this plugin.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$data = json_decode( $json, true, 12 );
		if ( ! is_array( $data ) ) {
			throw $fail( __( 'The file is not valid JSON.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( ( $data['estasoft_sync'] ?? null ) !== self::SCHEMA ) {
			throw $fail( __( 'This is not a settings file from this plugin, or it was made by a version with a different layout.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$unknown = array_diff( array_keys( $data ), self::KEYS );
		if ( array() !== $unknown ) {
			throw $fail( sprintf( /* translators: %s: the key. */ __( 'The file has a part this plugin does not know (%s).', 'estasoft-erp-crm-sync-engine' ), $this->plain( (string) reset( $unknown ) ) ) );
		}
		$settings = $data['settings'] ?? array();
		$mappings = $data['mappings'] ?? array();
		$limits   = $data['connection_settings'] ?? array();
		if ( ! is_array( $settings ) || ! is_array( $mappings ) || ! is_array( $limits ) ) {
			throw $fail( __( 'The file is not laid out as expected.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$known = array_keys( $this->settings->defaults() );
		foreach ( array_keys( $settings ) as $key ) {
			if ( ! in_array( $key, $known, true ) ) {
				throw $fail( sprintf( /* translators: %s: the setting. */ __( 'The file has a setting this plugin does not know (%s).', 'estasoft-erp-crm-sync-engine' ), $this->plain( (string) $key ) ) );
			}
		}
		foreach ( $mappings as $connector => $types ) {
			if ( ! is_string( $connector ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_\-]{0,39}$/', $connector ) || ! is_array( $types ) ) {
				throw $fail( __( 'The mappings in the file are not laid out as expected.', 'estasoft-erp-crm-sync-engine' ) );
			}
			foreach ( $types as $type => $rules ) {
				if ( ! in_array( $type, self::TYPES, true ) || ! is_array( $rules ) || ! array_is_list( $rules ) || count( $rules ) > self::MAX_RULES ) {
					throw $fail( sprintf( /* translators: %s: mapping type. */ __( 'The %s mapping in the file is not valid.', 'estasoft-erp-crm-sync-engine' ), $this->plain( (string) $type ) ) );
				}
				foreach ( $rules as $rule ) {
					if ( ! is_array( $rule ) ) {
						throw $fail( sprintf( /* translators: %s: mapping type. */ __( 'The %s mapping in the file is not valid.', 'estasoft-erp-crm-sync-engine' ), $this->plain( (string) $type ) ) );
					}
				}
			}
		}
		$allowed = array(
			'reliability' => array( 'retry_delays_seconds', 'jitter', 'breaker_threshold', 'probe_interval_minutes', 'requests_per_second', 'concurrency', 'obey_retry_after' ),
			'stock'       => array( 'enabled', 'interval_minutes', 'safety_buffer', 'subtract_held' ),
			'tracking'    => array( 'tracking_enabled', 'delivered_becomes', 'cod_payment_on_delivery' ),
		);
		foreach ( $limits as $connector => $groups ) {
			if ( ! is_string( $connector ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_\-]{0,39}$/', $connector ) || ! is_array( $groups ) ) {
				throw $fail( __( 'The limits in the file are not laid out as expected.', 'estasoft-erp-crm-sync-engine' ) );
			}
			foreach ( $groups as $group => $values ) {
				if ( ! isset( $allowed[ $group ] ) || ! is_array( $values ) || array() !== array_diff( array_keys( $values ), $allowed[ $group ] ) ) {
					throw $fail( __( 'The limits in the file have a part this plugin does not know.', 'estasoft-erp-crm-sync-engine' ) );
				}
			}
		}
		return array(
			'settings'            => $settings,
			'mappings'            => $mappings,
			'connection_settings' => $limits,
		);
	}

	/**
	 * The connection (not disconnected) of a connector, as the screens see it.
	 *
	 * @param string $connector Connector id.
	 * @return array<string,mixed>|null
	 */
	private function connectionFor( string $connector ): ?array {
		foreach ( $this->connections->all() as $connection ) {
			if ( $connection['connector'] === $connector && ConnectionStatus::Disconnected->value !== $connection['status'] ) {
				return $connection;
			}
		}
		return null;
	}

	/**
	 * Whether rules are exactly the live version's rules, so a draft would change nothing.
	 *
	 * @param int                            $connectionId Connection.
	 * @param string                         $type         Mapping type.
	 * @param array<int,array<string,mixed>> $rules        Rules from the file.
	 */
	private function sameAsLive( int $connectionId, string $type, array $rules ): bool {
		$live = $this->mappings->live( $connectionId, $type );
		return null !== $live && is_array( $live['rules'] ?? null ) && Json::canonical( $live['rules'] ) === Json::canonical( $rules );
	}

	/**
	 * Whether two setting values are the same.
	 *
	 * @param mixed $a First.
	 * @param mixed $b Second.
	 */
	private function same( mixed $a, mixed $b ): bool {
		return Json::canonical( $a ) === Json::canonical( $b );
	}

	/**
	 * A value in words.
	 *
	 * @param mixed $value Value.
	 */
	private function show( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'on', 'estasoft-erp-crm-sync-engine' ) : __( 'off', 'estasoft-erp-crm-sync-engine' );
		}
		if ( null === $value ) {
			return __( 'not set', 'estasoft-erp-crm-sync-engine' );
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( static fn( $item ): string => is_scalar( $item ) ? (string) $item : '…', $value ) );
		}
		return (string) $value;
	}

	/**
	 * A setting name in words.
	 *
	 * @param string $key Setting key.
	 */
	private function label( string $key ): string {
		$labels = array(
			'display_time'            => __( 'Time display', 'estasoft-erp-crm-sync-engine' ),
			'log_retention_days'      => __( 'Days to keep logs', 'estasoft-erp-crm-sync-engine' ),
			'log_excerpt_bytes'       => __( 'Log excerpt size', 'estasoft-erp-crm-sync-engine' ),
			'redaction'               => __( 'Redaction', 'estasoft-erp-crm-sync-engine' ),
			'payload_cap_bytes'       => __( 'Largest payload kept', 'estasoft-erp-crm-sync-engine' ),
			'delete_on_uninstall'     => __( 'Delete data on uninstall', 'estasoft-erp-crm-sync-engine' ),
			'runtime_profile'         => __( 'Runtime profile', 'estasoft-erp-crm-sync-engine' ),
			'global_concurrency'      => __( 'Items at once, all connections', 'estasoft-erp-crm-sync-engine' ),
			'backlog_threshold'       => __( 'Queue warning limit', 'estasoft-erp-crm-sync-engine' ),
			'admin_bar'               => __( 'Admin bar item', 'estasoft-erp-crm-sync-engine' ),
			'recipients'              => __( 'recipients', 'estasoft-erp-crm-sync-engine' ),
			'on_paused'               => __( 'when syncing pauses', 'estasoft-erp-crm-sync-engine' ),
			'daily_digest'            => __( 'daily list of failed orders', 'estasoft-erp-crm-sync-engine' ),
			'digest_time'             => __( 'time of the daily list', 'estasoft-erp-crm-sync-engine' ),
			'on_backlog'              => __( 'when the queue backs up', 'estasoft-erp-crm-sync-engine' ),
			'on_signin_expired'       => __( 'when a sign-in expires', 'estasoft-erp-crm-sync-engine' ),
			'retry_delays_seconds'    => __( 'Retry delays', 'estasoft-erp-crm-sync-engine' ),
			'jitter'                  => __( 'Random extra wait', 'estasoft-erp-crm-sync-engine' ),
			'breaker_threshold'       => __( 'Pause after failures in a row', 'estasoft-erp-crm-sync-engine' ),
			'probe_interval_minutes'  => __( 'Check again every (minutes)', 'estasoft-erp-crm-sync-engine' ),
			'requests_per_second'     => __( 'Requests per second', 'estasoft-erp-crm-sync-engine' ),
			'concurrency'             => __( 'Items at once', 'estasoft-erp-crm-sync-engine' ),
			'obey_retry_after'        => __( 'Obey Retry-After', 'estasoft-erp-crm-sync-engine' ),
			'enabled'                 => __( 'Stock sync', 'estasoft-erp-crm-sync-engine' ),
			'interval_minutes'        => __( 'Stock check every (minutes)', 'estasoft-erp-crm-sync-engine' ),
			'safety_buffer'           => __( 'Safety buffer', 'estasoft-erp-crm-sync-engine' ),
			'subtract_held'           => __( 'Subtract held stock', 'estasoft-erp-crm-sync-engine' ),
			'tracking_enabled'        => __( 'Delivery tracking', 'estasoft-erp-crm-sync-engine' ),
			'delivered_becomes'       => __( 'Delivered orders become', 'estasoft-erp-crm-sync-engine' ),
			'cod_payment_on_delivery' => __( 'Cash on delivery payment', 'estasoft-erp-crm-sync-engine' ),
		);
		return $labels[ $key ] ?? $this->plain( $key );
	}

	/**
	 * Keep a name from the file short and harmless before it is shown.
	 *
	 * @param string $text Text from the file.
	 */
	private function plain( string $text ): string {
		return mb_substr( (string) preg_replace( '/[^A-Za-z0-9_\-. ]/', '', $text ), 0, 60 );
	}
}
