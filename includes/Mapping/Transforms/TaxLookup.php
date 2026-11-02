<?php
/**
 * Transform: tax rate to the connected system's tax code.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingException;
use Estasoft\SyncEngine\Mapping\Support\CountryIndex;
use Estasoft\SyncEngine\Mapping\Support\MinorUnits;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The source is a tax rate in percent (lines[].tax_rate). Args: country (ISO code or canonical path; default shipping.country, then
 * billing.country), class (canonical path of the tax class; default lines[].tax_class for line sources). A rate with no entry in
 * the tax mapping stops the order with SYNC-MAP-002 before anything is sent. An empty rate (an untaxed line) gives no code.
 */
final class TaxLookup extends BaseTransform {

	/**
	 * Set up with what it depends on.
	 *
	 * @param CountryIndex $countries Country lookup.
	 */
	public function __construct( private readonly CountryIndex $countries = new CountryIndex() ) {
	}

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'tax_lookup';
	}

	/**
	 * Find the code.
	 *
	 * @param mixed               $value   Rate in percent.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 *
	 * @throws MappingException SYNC-MAP-002 when the rate is not mapped.
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$rate = is_scalar( $value ) && ! Text::isEmpty( $value ) ? MinorUnits::rateKey( $value ) : null;
		if ( null === $rate ) {
			return null;
		}
		$resolver = $context->environment->taxes;
		if ( null === $resolver ) {
			$context->warn( __( 'No tax mapping is available, so no tax code was added.', 'estasoft-erp-crm-sync-engine' ) );
			return null;
		}
		$country = $this->country( $args, $context );
		$class   = $this->taxClass( $args, $context );
		$code    = $resolver->resolve( $context->environment->connectionId, $country, $class, $rate );
		if ( null !== $code && '' !== $code ) {
			return $code;
		}
		$failure = new MappingException(
			ErrorCode::Map002,
			array(
				'country' => $this->countries->name( $country ) ?? $country,
				'rate'    => $rate . '%',
			) + $context->messageContext(),
			$context->ruleId(),
			$context->target()
		);
		throw $failure;
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$problems = array();
		foreach ( array( 'country', 'class' ) as $key ) {
			$setting = $this->text( $args, $key );
			if ( '' !== $setting && ! CanonicalOrder::isValidPath( $setting ) && ! ( 'country' === $key && 1 === preg_match( '/^[A-Za-z]{2}$/', $setting ) ) ) {
				$problems[] = $this->missing( $key );
			}
		}
		return $problems;
	}

	/**
	 * Country of the tax.
	 *
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return string Alpha-2 code, or an empty string when unknown.
	 */
	private function country( array $args, TransformContext $context ): string {
		$setting = $this->text( $args, 'country' );
		if ( 1 === preg_match( '/^[A-Za-z]{2}$/', $setting ) ) {
			return strtoupper( $setting );
		}
		$paths = '' === $setting ? array( 'shipping.country', 'billing.country' ) : array( $setting );
		foreach ( $paths as $path ) {
			$read = $context->read( $path );
			if ( is_scalar( $read ) && ! Text::isEmpty( $read ) ) {
				return $this->countries->alpha2( Text::toString( $read ) ) ?? strtoupper( Text::toString( $read ) );
			}
		}
		return '';
	}

	/**
	 * Tax class of the line.
	 *
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 */
	private function taxClass( array $args, TransformContext $context ): string {
		$setting = $this->text( $args, 'class' );
		if ( '' === $setting && 0 === strpos( $context->source(), 'lines[]' ) ) {
			$setting = 'lines[].tax_class';
		}
		if ( '' === $setting ) {
			return '';
		}
		$read = $context->read( $setting );
		return is_scalar( $read ) ? Text::toString( $read ) : '';
	}
}
