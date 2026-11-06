<?php
/**
 * Settings of the nightly check.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Reconciliation;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Ranges;
use Estasoft\SyncEngine\Core\Support\ValidationException;

/**
 * Kept in the option `estasoft_sync_recon_settings`: the store-time hour of the nightly run, the window in days, the tolerance in
 * minor units, whether orders missing in the system are queued again by themselves, and the local date of the last nightly run.
 */
final class ReconSettings {

	/**
	 * Option name.
	 */
	public const OPTION = 'estasoft_sync_recon_settings';

	/**
	 * Window in days: min, max, default.
	 */
	public const WINDOW = array( 1, 30, 7 );

	/**
	 * Tolerance in minor units: min, max, default (one minor unit: 0.01 AED, 0.001 KWD).
	 */
	public const TOLERANCE = array( 0, 1000, 1 );

	/**
	 * Set up with what it depends on.
	 *
	 * @param OptionStore $options Options.
	 */
	public function __construct( private readonly OptionStore $options ) {
	}

	/**
	 * Defaults.
	 *
	 * @return array{schedule_time:string,window_days:int,tolerance_minor:int,auto_repair_missing:bool,last_tick_date:string}
	 */
	public function defaults(): array {
		return array(
			'schedule_time'       => '02:00',
			'window_days'         => self::WINDOW[2],
			'tolerance_minor'     => self::TOLERANCE[2],
			'auto_repair_missing' => false,
			'last_tick_date'      => '',
		);
	}

	/**
	 * The settings, stored values over defaults.
	 *
	 * @return array{schedule_time:string,window_days:int,tolerance_minor:int,auto_repair_missing:bool,last_tick_date:string}
	 */
	public function get(): array {
		$stored   = $this->options->get( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = $this->defaults();
		foreach ( $this->check( $stored ) as $key => $result ) {
			if ( null === $result['error'] ) {
				$settings[ $key ] = $result['value'];
			}
		}
		return $settings;
	}

	/**
	 * Validate and save the fields supplied.
	 *
	 * @param array<string,mixed> $changes schedule_time, window_days, tolerance_minor, auto_repair_missing.
	 * @return array{schedule_time:string,window_days:int,tolerance_minor:int,auto_repair_missing:bool,last_tick_date:string} The settings after saving.
	 *
	 * @throws ValidationException With one message per bad field.
	 */
	public function save( array $changes ): array {
		$checked = $this->check( array_intersect_key( $changes, array_flip( array( 'schedule_time', 'window_days', 'tolerance_minor', 'auto_repair_missing' ) ) ) );
		$errors  = array();
		foreach ( $checked as $key => $result ) {
			if ( null !== $result['error'] ) {
				$errors[ $key ] = $result['error'];
			}
		}
		if ( array() !== $errors ) {
			throw new ValidationException( array_map( 'esc_html', $errors ) );
		}
		$settings = $this->get();
		foreach ( $checked as $key => $result ) {
			$settings[ $key ] = $result['value'];
		}
		$this->options->set( self::OPTION, $settings );
		return $settings;
	}

	/**
	 * Remember the local date of the last nightly run.
	 *
	 * @param string $date Y-m-d in store time.
	 */
	public function markTick( string $date ): void {
		$settings                   = $this->get();
		$settings['last_tick_date'] = $date;
		$this->options->set( self::OPTION, $settings );
	}

	/**
	 * Check supplied fields.
	 *
	 * @param array<string,mixed> $input Fields.
	 * @return array<string,array{value:mixed,error:?string}>
	 */
	private function check( array $input ): array {
		$results = array();
		foreach ( $input as $key => $value ) {
			switch ( $key ) {
				case 'schedule_time':
					$results[ $key ] = is_string( $value ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value )
						? array(
							'value' => $value,
							'error' => null,
						)
						: array(
							'value' => null,
							'error' => __( 'Enter a time from 00:00 to 23:59.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						);
					break;
				case 'window_days':
					$number          = Ranges::read( $value, self::WINDOW );
					$results[ $key ] = null === $number
						? array(
							'value' => null,
							'error' => Ranges::message( self::WINDOW ),
						)
						: array(
							'value' => $number,
							'error' => null,
						);
					break;
				case 'tolerance_minor':
					$number          = Ranges::read( $value, self::TOLERANCE );
					$results[ $key ] = null === $number
						? array(
							'value' => null,
							'error' => Ranges::message( self::TOLERANCE ),
						)
						: array(
							'value' => $number,
							'error' => null,
						);
					break;
				case 'auto_repair_missing':
					$bool            = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$results[ $key ] = null === $bool
						? array(
							'value' => null,
							'error' => __( 'Choose on or off.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						)
						: array(
							'value' => $bool,
							'error' => null,
						);
					break;
				case 'last_tick_date':
					$results[ $key ] = is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value )
						? array(
							'value' => $value,
							'error' => null,
						)
						: array(
							'value' => null,
							'error' => 'invalid',
						);
					break;
			}
		}
		return $results;
	}
}
