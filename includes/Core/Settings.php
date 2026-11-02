<?php
/**
 * Plugin settings (the `Settings` shape in src/api/types.ts).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Ranges;
use Estasoft\SyncEngine\Core\Support\ValidationException;

/**
 * Typed getters with defaults, and validation that returns one message per field. Stored in a single
 * option that is never autoloaded. A value that fails validation is never saved.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'estasoft_sync_settings';

	/**
	 * Allowed log retention in days.
	 */
	public const RETENTION_DAYS = array( 7, 14, 30 );

	/**
	 * Allowed log excerpt sizes in bytes (0 keeps none).
	 */
	public const EXCERPT_BYTES = array( 0, 2048, 8192 );

	/**
	 * Set up the settings.
	 *
	 * @param OptionStore $options      Options.
	 * @param string      $defaultEmail Site admin email, the default alert recipient.
	 */
	public function __construct(
		private readonly OptionStore $options,
		private readonly string $defaultEmail = ''
	) {
	}

	/**
	 * Defaults for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'display_time'        => 'store',
			'log_retention_days'  => 14,
			'log_excerpt_bytes'   => 2048,
			'redaction'           => true,
			'payload_cap_bytes'   => Ranges::PAYLOAD_CAP_BYTES[2],
			'delete_on_uninstall' => false,
			'runtime_profile'     => 'standard',
			'global_concurrency'  => Ranges::GLOBAL_CONCURRENCY[2],
			'backlog_threshold'   => Ranges::BACKLOG[2],
			'admin_bar'           => true,
			'notifications'       => array(
				'recipients'        => '' !== $this->defaultEmail ? array( $this->defaultEmail ) : array(),
				'on_paused'         => true,
				'daily_digest'      => true,
				'digest_time'       => '08:00',
				'on_backlog'        => true,
				'on_signin_expired' => true,
			),
		);
	}

	/**
	 * All settings, stored values over defaults. A stored value that is no longer valid falls back to its default.
	 *
	 * @return array<string,mixed> The `Settings` shape of src/api/types.ts.
	 */
	public function get(): array {
		$stored   = $this->options->get( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = $this->defaults();
		foreach ( $settings as $key => $default ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}
			if ( 'notifications' === $key ) {
				$settings[ $key ] = $this->mergeNotifications( $default, is_array( $stored[ $key ] ) ? $stored[ $key ] : array() );
				continue;
			}
			$result = $this->checkField( $key, $stored[ $key ] );
			if ( null === $result['error'] ) {
				$settings[ $key ] = $result['value'];
			}
		}
		return $settings;
	}

	/**
	 * Validate changes without saving them.
	 *
	 * @param array<string,mixed> $changes Fields to change; notifications may be partial.
	 * @return array<string,string> Field (dotted for nested fields) to message; empty when valid.
	 */
	public function validate( array $changes ): array {
		return $this->apply( $this->get(), $changes )['errors'];
	}

	/**
	 * Save changes. All or nothing: when any field is invalid nothing is saved.
	 *
	 * @param array<string,mixed> $changes Fields to change; notifications may be partial.
	 * @return array<string,mixed> The settings after saving.
	 *
	 * @throws ValidationException With one message per invalid field.
	 */
	public function update( array $changes ): array {
		$before = $this->get();
		$result = $this->apply( $before, $changes );
		if ( array() !== $result['errors'] ) {
			throw new ValidationException( array_map( 'esc_html', $result['errors'] ) );
		}
		$after = $result['settings'];
		$this->options->set( self::OPTION, $after );
		/**
		 * Fires after settings were saved.
		 *
		 * @param array<string,mixed> $after  Settings now.
		 * @param array<string,mixed> $before Settings before.
		 */
		do_action( 'estasoft_sync_settings_updated', $after, $before );
		return $after;
	}

	/**
	 * Show times in store time or UTC.
	 */
	public function displayTime(): string {
		return (string) $this->get()['display_time'];
	}

	/**
	 * Days to keep log entries: 7, 14 or 30.
	 */
	public function logRetentionDays(): int {
		return (int) $this->get()['log_retention_days'];
	}

	/**
	 * Bytes of request and answer kept in a log entry: 0, 2048 or 8192.
	 */
	public function logExcerptBytes(): int {
		return (int) $this->get()['log_excerpt_bytes'];
	}

	/**
	 * Whether names, emails and addresses are removed from log messages.
	 */
	public function redactionEnabled(): bool {
		return (bool) $this->get()['redaction'];
	}

	/**
	 * Largest payload the plugin will serialise for hashing and logging, in bytes.
	 */
	public function payloadCapBytes(): int {
		return (int) $this->get()['payload_cap_bytes'];
	}

	/**
	 * Whether all data is deleted when the plugin is deleted.
	 */
	public function deleteOnUninstall(): bool {
		return (bool) $this->get()['delete_on_uninstall'];
	}

	/**
	 * Standard (WordPress background tasks) or high_volume (command-line worker).
	 */
	public function runtimeProfile(): string {
		return (string) $this->get()['runtime_profile'];
	}

	/**
	 * Items at once, all connections together.
	 */
	public function globalConcurrency(): int {
		return (int) $this->get()['global_concurrency'];
	}

	/**
	 * Waiting items above which a warning is sent.
	 */
	public function backlogThreshold(): int {
		return (int) $this->get()['backlog_threshold'];
	}

	/**
	 * Whether the admin bar item is shown.
	 */
	public function adminBar(): bool {
		return (bool) $this->get()['admin_bar'];
	}

	/**
	 * Alert settings.
	 *
	 * @return array{recipients:array<int,string>,on_paused:bool,daily_digest:bool,digest_time:string,on_backlog:bool,on_signin_expired:bool}
	 */
	public function notifications(): array {
		return $this->get()['notifications'];
	}

	/**
	 * Merge changes into the current settings and validate every changed field.
	 *
	 * @param array<string,mixed> $current Current settings.
	 * @param array<string,mixed> $changes Requested changes.
	 * @return array{settings:array<string,mixed>,errors:array<string,string>}
	 */
	private function apply( array $current, array $changes ): array {
		$errors = array();
		foreach ( $changes as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) ) {
				continue;
			}
			if ( 'notifications' === $key ) {
				if ( ! is_array( $value ) ) {
					$errors['notifications'] = __( 'Notification settings are not valid.', 'estasoft-erp-crm-sync-engine' );
					continue;
				}
				foreach ( $this->checkNotifications( $value, $current['notifications'] ) as $field => $result ) {
					if ( null !== $result['error'] ) {
						$errors[ 'notifications.' . $field ] = $result['error'];
					} else {
						$current['notifications'][ $field ] = $result['value'];
					}
				}
				continue;
			}
			$result = $this->checkField( (string) $key, $value );
			if ( null !== $result['error'] ) {
				$errors[ (string) $key ] = $result['error'];
			} else {
				$current[ $key ] = $result['value'];
			}
		}
		return array(
			'settings' => $current,
			'errors'   => $errors,
		);
	}

	/**
	 * Check one top-level field.
	 *
	 * @param string $key   Field name.
	 * @param mixed  $value Candidate.
	 * @return array{value:mixed,error:?string}
	 */
	private function checkField( string $key, mixed $value ): array {
		switch ( $key ) {
			case 'display_time':
				return $this->oneOf( $value, array( 'store', 'utc' ), __( 'Choose store time or UTC.', 'estasoft-erp-crm-sync-engine' ) );
			case 'runtime_profile':
				return $this->oneOf( $value, array( 'standard', 'high_volume' ), __( 'Choose Standard or High volume.', 'estasoft-erp-crm-sync-engine' ) );
			case 'log_retention_days':
				return $this->oneOfInt( $value, self::RETENTION_DAYS, __( 'Choose 7, 14 or 30 days.', 'estasoft-erp-crm-sync-engine' ) );
			case 'log_excerpt_bytes':
				return $this->oneOfInt( $value, self::EXCERPT_BYTES, __( 'Choose nothing, 2 KB or 8 KB.', 'estasoft-erp-crm-sync-engine' ) );
			case 'redaction':
			case 'delete_on_uninstall':
			case 'admin_bar':
				return $this->boolean( $value );
			case 'payload_cap_bytes':
				return $this->number( $value, Ranges::PAYLOAD_CAP_BYTES );
			case 'global_concurrency':
				return $this->number( $value, Ranges::GLOBAL_CONCURRENCY );
			case 'backlog_threshold':
				return $this->number( $value, Ranges::BACKLOG );
		}
		return array(
			'value' => null,
			'error' => __( 'Unknown setting.', 'estasoft-erp-crm-sync-engine' ),
		);
	}

	/**
	 * Check the fields of the notifications group that were supplied.
	 *
	 * @param array<string,mixed> $input   Supplied fields.
	 * @param array<string,mixed> $current Current notification settings.
	 * @return array<string,array{value:mixed,error:?string}>
	 */
	private function checkNotifications( array $input, array $current ): array {
		$results = array();
		foreach ( $input as $field => $value ) {
			if ( ! array_key_exists( $field, $current ) ) {
				continue;
			}
			switch ( $field ) {
				case 'recipients':
					$results[ $field ] = $this->recipients( $value );
					break;
				case 'digest_time':
					$results[ $field ] = ( is_string( $value ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) )
						? array(
							'value' => $value,
							'error' => null,
						)
						: array(
							'value' => null,
							'error' => __( 'Enter a time from 00:00 to 23:59.', 'estasoft-erp-crm-sync-engine' ),
						);
					break;
				default:
					$results[ $field ] = $this->boolean( $value );
			}
		}
		return $results;
	}

	/**
	 * Validate a list of recipient addresses.
	 *
	 * @param mixed $value Candidate.
	 * @return array{value:mixed,error:?string}
	 */
	private function recipients( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array(
				'value' => null,
				'error' => __( 'Enter at least one email address.', 'estasoft-erp-crm-sync-engine' ),
			);
		}
		$clean = array();
		foreach ( $value as $address ) {
			$address = is_string( $address ) ? trim( $address ) : '';
			if ( '' === $address || false === is_email( $address ) ) {
				return array(
					'value' => null,
					/* translators: %s: the text that is not an email address. */
					'error' => sprintf( __( '%s is not a valid email address.', 'estasoft-erp-crm-sync-engine' ), '' === $address ? '""' : $address ),
				);
			}
			$clean[ strtolower( $address ) ] ??= $address;
		}
		$clean = array_values( $clean );
		if ( array() === $clean ) {
			return array(
				'value' => null,
				'error' => __( 'Enter at least one email address.', 'estasoft-erp-crm-sync-engine' ),
			);
		}
		if ( count( $clean ) > Ranges::MAX_RECIPIENTS ) {
			return array(
				'value' => null,
				/* translators: %d: most recipients allowed. */
				'error' => sprintf( __( 'Use at most %d email addresses.', 'estasoft-erp-crm-sync-engine' ), Ranges::MAX_RECIPIENTS ),
			);
		}
		return array(
			'value' => $clean,
			'error' => null,
		);
	}

	/**
	 * Merge stored notification settings over defaults, ignoring invalid stored values.
	 *
	 * @param array<string,mixed> $defaults Default group.
	 * @param array<string,mixed> $stored   Stored group.
	 * @return array<string,mixed>
	 */
	private function mergeNotifications( array $defaults, array $stored ): array {
		foreach ( $this->checkNotifications( $stored, $defaults ) as $field => $result ) {
			if ( null === $result['error'] ) {
				$defaults[ $field ] = $result['value'];
			}
		}
		return $defaults;
	}

	/**
	 * Value must be one of a list of strings.
	 *
	 * @param mixed             $value   Candidate.
	 * @param array<int,string> $allowed Allowed values.
	 * @param string            $message Message when it is not.
	 * @return array{value:mixed,error:?string}
	 */
	private function oneOf( mixed $value, array $allowed, string $message ): array {
		if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
			return array(
				'value' => $value,
				'error' => null,
			);
		}
		return array(
			'value' => null,
			'error' => $message,
		);
	}

	/**
	 * Value must be one of a list of whole numbers.
	 *
	 * @param mixed          $value   Candidate (int or digits).
	 * @param array<int,int> $allowed Allowed values.
	 * @param string         $message Message when it is not.
	 * @return array{value:mixed,error:?string}
	 */
	private function oneOfInt( mixed $value, array $allowed, string $message ): array {
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			$value = (int) $value;
		}
		if ( is_int( $value ) && in_array( $value, $allowed, true ) ) {
			return array(
				'value' => $value,
				'error' => null,
			);
		}
		return array(
			'value' => null,
			'error' => $message,
		);
	}

	/**
	 * Value must be a whole number inside a range.
	 *
	 * @param mixed                    $value Candidate.
	 * @param array{0:int,1:int,2:int} $range Range.
	 * @return array{value:mixed,error:?string}
	 */
	private function number( mixed $value, array $range ): array {
		$number = Ranges::read( $value, $range );
		return null === $number
			? array(
				'value' => null,
				'error' => Ranges::message( $range ),
			)
			: array(
				'value' => $number,
				'error' => null,
			);
	}

	/**
	 * Value must be true or false.
	 *
	 * @param mixed $value Candidate (bool, 1/0, "1"/"0", "true"/"false").
	 * @return array{value:mixed,error:?string}
	 */
	private function boolean( mixed $value ): array {
		$bool = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return null === $bool
			? array(
				'value' => null,
				'error' => __( 'Choose on or off.', 'estasoft-erp-crm-sync-engine' ),
			)
			: array(
				'value' => $bool,
				'error' => null,
			);
	}
}
