<?php
/**
 * Reliability settings of one connection (`ReliabilitySettings` in src/api/types.ts).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Ranges;
use Estasoft\SyncEngine\Core\Support\ValidationException;

/**
 * Retry delays, circuit breaker, request rate and concurrency for one connection, stored in the
 * `settings_json` column. Same validation rules as the global settings.
 */
final class ConnectionSettings {

	/**
	 * Default retry delays in seconds: 1 minute, 5 minutes, 1 hour, 24 hours (five tries in all).
	 */
	public const DEFAULT_DELAYS = array( 60, 300, 3600, 86400 );

	/**
	 * Longest single delay in seconds (24 hours).
	 */
	public const MAX_DELAY = 86400;

	/**
	 * Most retry delays, so at most this many plus one tries.
	 */
	public const MAX_DELAYS = 10;

	/**
	 * Defaults, optionally started from the hints a connector gives for its system.
	 *
	 * @param array<string,mixed> $hints Result of ConnectorInterface::rateLimitHints() (requests_per_second, concurrency).
	 * @return array<string,mixed> The `ReliabilitySettings` shape.
	 */
	public function defaults( array $hints = array() ): array {
		$rate        = Ranges::REQUESTS_PER_SECOND[2];
		$concurrency = Ranges::CONCURRENCY[2];
		if ( isset( $hints['requests_per_second'] ) && is_numeric( $hints['requests_per_second'] ) ) {
			$rate = max( Ranges::REQUESTS_PER_SECOND[0], min( Ranges::REQUESTS_PER_SECOND[1], (int) ceil( (float) $hints['requests_per_second'] ) ) );
		}
		if ( isset( $hints['concurrency'] ) && is_numeric( $hints['concurrency'] ) ) {
			$concurrency = max( Ranges::CONCURRENCY[0], min( Ranges::CONCURRENCY[1], (int) $hints['concurrency'] ) );
		}
		return array(
			'retry_delays_seconds'   => self::DEFAULT_DELAYS,
			'jitter'                 => true,
			'breaker_threshold'      => Ranges::BREAKER_THRESHOLD[2],
			'probe_interval_minutes' => Ranges::PROBE_MINUTES[2],
			'requests_per_second'    => $rate,
			'concurrency'            => $concurrency,
			'obey_retry_after'       => true,
		);
	}

	/**
	 * Stored settings over defaults. Invalid stored values fall back to the default.
	 *
	 * @param array<string,mixed> $stored Decoded settings_json.
	 * @param array<string,mixed> $hints  Connector hints used for the defaults.
	 * @return array<string,mixed>
	 */
	public function normalise( array $stored, array $hints = array() ): array {
		$settings = $this->defaults( $hints );
		foreach ( $this->check( $stored ) as $key => $result ) {
			if ( null === $result['error'] ) {
				$settings[ $key ] = $result['value'];
			}
		}
		return $settings;
	}

	/**
	 * Validate supplied fields.
	 *
	 * @param array<string,mixed> $input Fields to check.
	 * @return array<string,string> Field to message; empty when valid.
	 */
	public function validate( array $input ): array {
		$errors = array();
		foreach ( $this->check( $input ) as $key => $result ) {
			if ( null !== $result['error'] ) {
				$errors[ $key ] = $result['error'];
			}
		}
		return $errors;
	}

	/**
	 * Merge validated changes into the current settings.
	 *
	 * @param array<string,mixed> $current Current normalised settings.
	 * @param array<string,mixed> $changes Fields to change.
	 * @return array<string,mixed> New settings.
	 *
	 * @throws ValidationException When any supplied field is invalid.
	 */
	public function merge( array $current, array $changes ): array {
		$checked = $this->check( $changes );
		$errors  = array();
		foreach ( $checked as $key => $result ) {
			if ( null !== $result['error'] ) {
				$errors[ $key ] = $result['error'];
			}
		}
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}
		foreach ( $checked as $key => $result ) {
			$current[ $key ] = $result['value'];
		}
		return $current;
	}

	/**
	 * Check every known field that is present in the input.
	 *
	 * @param array<string,mixed> $input Candidate fields.
	 * @return array<string,array{value:mixed,error:?string}>
	 */
	private function check( array $input ): array {
		$results = array();
		foreach ( $input as $key => $value ) {
			switch ( $key ) {
				case 'retry_delays_seconds':
					$results[ $key ] = $this->delays( $value );
					break;
				case 'jitter':
				case 'obey_retry_after':
					$bool            = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$results[ $key ] = null === $bool
						? array(
							'value' => null,
							'error' => __( 'Choose on or off.', 'estasoft-erp-crm-sync-engine' ),
						)
						: array(
							'value' => $bool,
							'error' => null,
						);
					break;
				case 'breaker_threshold':
					$results[ $key ] = $this->number( $value, Ranges::BREAKER_THRESHOLD );
					break;
				case 'probe_interval_minutes':
					$results[ $key ] = $this->number( $value, Ranges::PROBE_MINUTES );
					break;
				case 'requests_per_second':
					$results[ $key ] = $this->number( $value, Ranges::REQUESTS_PER_SECOND );
					break;
				case 'concurrency':
					$results[ $key ] = $this->number( $value, Ranges::CONCURRENCY );
					break;
			}
		}
		return $results;
	}

	/**
	 * A whole number inside a range.
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
	 * A list of retry delays in seconds.
	 *
	 * @param mixed $value Candidate.
	 * @return array{value:mixed,error:?string}
	 */
	private function delays( mixed $value ): array {
		$error = sprintf(
			/* translators: 1: most delays, 2: longest delay in seconds. */
			__( 'Enter up to %1$d delays in seconds, each from 1 to %2$d.', 'estasoft-erp-crm-sync-engine' ),
			self::MAX_DELAYS,
			self::MAX_DELAY
		);
		if ( ! is_array( $value ) || array() === $value || count( $value ) > self::MAX_DELAYS ) {
			return array(
				'value' => null,
				'error' => $error,
			);
		}
		$clean = array();
		foreach ( $value as $delay ) {
			$seconds = Ranges::read( $delay, array( 1, self::MAX_DELAY, 1 ) );
			if ( null === $seconds ) {
				return array(
					'value' => null,
					'error' => $error,
				);
			}
			$clean[] = $seconds;
		}
		return array(
			'value' => $clean,
			'error' => null,
		);
	}
}
