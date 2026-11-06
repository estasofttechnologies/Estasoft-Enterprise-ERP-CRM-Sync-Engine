<?php
/**
 * Transform: an amount to integer minor units.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\Support\MinorUnits;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: from ("major", the default, for text such as "12.345"; "minor" for a whole number that already is minor units),
 * currency (an ISO code or a canonical path; the order's currency by default). Uses the currency's decimals (3 for KWD,
 * 0 for JPY, otherwise 2) and integer arithmetic only. Text that is not a number becomes empty with a warning.
 */
final class MoneyMinor extends BaseTransform {

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'money_minor';
	}

	/**
	 * Convert the amount.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		if ( Text::isEmpty( $value ) || ! is_scalar( $value ) ) {
			return $value;
		}
		$minor = 'minor' === $this->text( $args, 'from', 'major' )
			? MinorUnits::parse( $value, 0 )
			: MinorUnits::parse( $value, MinorUnits::exponent( $this->currency( $args, $context ) ) );
		if ( null === $minor ) {
			$context->warn( __( 'An amount could not be read as a number and was left empty.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			return '';
		}
		return $minor;
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$problems = array();
		if ( ! in_array( $this->text( $args, 'from', 'major' ), array( 'major', 'minor' ), true ) ) {
			$problems[] = $this->missing( 'from' );
		}
		$currency = $this->text( $args, 'currency' );
		if ( '' !== $currency && 1 !== preg_match( '/^[A-Za-z]{3}$/', $currency ) && ! CanonicalOrder::isValidPath( $currency ) ) {
			$problems[] = $this->missing( 'currency' );
		}
		return $problems;
	}

	/**
	 * The currency to use.
	 *
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 */
	private function currency( array $args, TransformContext $context ): string {
		$setting = $this->text( $args, 'currency' );
		if ( 1 === preg_match( '/^[A-Za-z]{3}$/', $setting ) ) {
			return $setting;
		}
		$read = $context->read( '' === $setting ? 'order.currency' : $setting );
		return is_string( $read ) ? $read : '';
	}
}
