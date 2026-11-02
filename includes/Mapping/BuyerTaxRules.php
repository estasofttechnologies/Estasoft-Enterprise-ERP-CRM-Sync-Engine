<?php
/**
 * Rules for the buyer's tax number, by country.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\Support\CountryIndex;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * A rule says how many digits the number has (or null for no fixed length) and whether business buyers must give one.
 * Default: Saudi Arabia, 15 digits, required for business buyers. A buyer counts as a business when the billing company
 * or a tax number is filled in. A failure is SYNC-VAL-001 and is raised before anything is sent.
 */
final class BuyerTaxRules {

	/**
	 * Set up with what it depends on.
	 *
	 * @param CountryIndex $countries Country lookup.
	 */
	public function __construct( private readonly CountryIndex $countries = new CountryIndex() ) {
	}

	/**
	 * The rules used when a store has saved none.
	 *
	 * @return array<int,array{country:string,digits:?int,required_for_business:bool}>
	 */
	public function defaults(): array {
		return array(
			array(
				'country'               => 'SA',
				'digits'                => 15,
				'required_for_business' => true,
			),
		);
	}

	/**
	 * Check an order against the rules.
	 *
	 * @param array<int,array{country:string,digits:?int,required_for_business:bool}> $rules Rules in force.
	 * @param CanonicalOrder                                                          $order Order.
	 * @return MappingException|null The failure, or null when the order passes.
	 */
	public function check( array $rules, CanonicalOrder $order ): ?MappingException {
		$country = $this->countryOf( $order );
		$rule    = null;
		foreach ( $rules as $candidate ) {
			if ( strtoupper( $candidate['country'] ) === $country ) {
				$rule = $candidate;
				break;
			}
		}
		if ( null === $rule ) {
			return null;
		}

		$number   = $this->digitsOnly( $order->get( 'order.buyer_tax_number' ) );
		$business = $order->has( 'billing.company' ) || '' !== $number;
		$context  = array(
			'country' => $this->countries->name( $country ) ?? $country,
			'digits'  => $rule['digits'] ?? '',
			'order'   => Text::toString( $this->scalar( $order->get( 'order.number' ) ) ),
		);

		if ( '' === $number ) {
			return $business && $rule['required_for_business'] ? new MappingException( ErrorCode::Val001, $context ) : null;
		}
		if ( null !== $rule['digits'] && ( ! ctype_digit( $number ) || strlen( $number ) !== $rule['digits'] ) ) {
			return new MappingException( ErrorCode::Val001, $context );
		}
		return null;
	}

	/**
	 * The billing country as an alpha-2 code.
	 *
	 * @param CanonicalOrder $order Order.
	 */
	private function countryOf( CanonicalOrder $order ): string {
		$raw = $order->get( 'billing.country' );
		return is_string( $raw ) ? ( $this->countries->alpha2( $raw ) ?? strtoupper( $raw ) ) : '';
	}

	/**
	 * A tax number with spaces, dashes and dots removed and Arabic-Indic digits turned into ASCII digits.
	 *
	 * @param mixed $value Value.
	 */
	private function digitsOnly( mixed $value ): string {
		$text = is_scalar( $value ) ? Text::asciiDigits( Text::toString( $value ) ) : '';
		return (string) preg_replace( '/[\s\-.\x{200E}\x{200F}]+/u', '', $text );
	}

	/**
	 * A value as a scalar for text conversion.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function scalar( mixed $value ): mixed {
		return is_scalar( $value ) ? $value : '';
	}
}
