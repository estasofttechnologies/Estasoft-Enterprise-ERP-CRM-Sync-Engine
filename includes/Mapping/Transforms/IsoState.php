<?php
/**
 * Transform: state or province to an ISO 3166-2 code.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\Support\CountryIndex;
use Estasoft\SyncEngine\Mapping\Support\SubdivisionIndex;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: country (an ISO code, or a canonical path such as billing.country; by default shipping.country for shipping.* sources and
 * billing.country otherwise). Reads WooCommerce state codes (DU), ISO codes (AE-DU) and English or Arabic names, and writes the ISO code (AE-DU).
 * Unknown states become empty with a warning.
 */
final class IsoState extends BaseTransform {

	/**
	 * Set up with what it depends on.
	 *
	 * @param SubdivisionIndex $states    State lookup.
	 * @param CountryIndex     $countries Country lookup.
	 */
	public function __construct(
		private readonly SubdivisionIndex $states = new SubdivisionIndex(),
		private readonly CountryIndex $countries = new CountryIndex(),
	) {
	}

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'iso_state';
	}

	/**
	 * Find the code.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$text = $this->scalarText( $value );
		if ( null === $text || '' === Text::trim( $text ) ) {
			return $value;
		}
		$country = $this->country( $args, $context );
		$code    = null === $country ? null : $this->states->code( $country, $text );
		if ( null === $code ) {
			$context->warn( __( 'A state or province was not recognised and was left empty.', 'estasoft-erp-crm-sync-engine' ) );
			return '';
		}
		return $code;
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$country = $this->text( $args, 'country' );
		return '' === $country || CanonicalOrder::isValidPath( $country ) ? array() : array( $this->missing( 'country' ) );
	}

	/**
	 * The country the state belongs to.
	 *
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return string|null Alpha-2 code.
	 */
	private function country( array $args, TransformContext $context ): ?string {
		$setting = $this->text( $args, 'country' );
		if ( '' === $setting ) {
			$setting = 0 === strpos( $context->source(), 'shipping.' ) ? 'shipping.country' : 'billing.country';
		}
		if ( 1 === preg_match( '/^[A-Za-z]{2}$/', $setting ) ) {
			return $this->countries->alpha2( $setting );
		}
		$read = $context->read( $setting );
		return is_scalar( $read ) ? $this->countries->alpha2( Text::toString( $read ) ) : null;
	}
}
