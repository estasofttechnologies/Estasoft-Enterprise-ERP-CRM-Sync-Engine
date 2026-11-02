<?php
/**
 * Transform: write a phone number in international (E.164) form.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\Support\CountryIndex;
use Estasoft\SyncEngine\Mapping\Support\DialCodes;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Args: default_country (an ISO code such as AE, or a canonical path such as billing.country; used when the number has no country
 * code). Reads spaces, dashes, brackets, "00" and "+" prefixes, a redundant "(0)" and Arabic-Indic digits. A number that cannot
 * be read becomes empty with a warning; the transform never invents digits.
 */
final class PhoneE164 extends BaseTransform {

	/**
	 * The longest E.164 number has fifteen digits including the country code.
	 */
	private const MAX_DIGITS = 15;

	/**
	 * Set up with what it depends on.
	 *
	 * @param DialCodes    $dialCodes Calling code table.
	 * @param CountryIndex $countries Country lookup for the default country.
	 */
	public function __construct(
		private readonly DialCodes $dialCodes = new DialCodes(),
		private readonly CountryIndex $countries = new CountryIndex(),
	) {
	}

	/**
	 * Transform name.
	 */
	public function name(): string {
		return 'phone_e164';
	}

	/**
	 * Normalise the number.
	 *
	 * @param mixed               $value   Value.
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return mixed
	 */
	public function apply( mixed $value, array $args, TransformContext $context ): mixed {
		$raw = $this->scalarText( $value );
		if ( null === $raw || '' === Text::trim( $raw ) ) {
			return $value;
		}
		$number = $this->normalise( $raw, $this->defaultRegion( $args, $context ) );
		if ( null === $number ) {
			$context->warn( __( 'A phone number could not be read as an international number and was left empty.', 'estasoft-erp-crm-sync-engine' ) );
			return '';
		}
		return $number;
	}

	/**
	 * Check arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,string>
	 */
	public function check( array $args ): array {
		$default = $this->text( $args, 'default_country' );
		if ( '' === $default || CanonicalOrder::isValidPath( $default ) ) {
			return array();
		}
		return array( $this->missing( 'default_country' ) );
	}

	/**
	 * Resolve the country to assume for numbers typed without a country code.
	 *
	 * @param array<string,mixed> $args    Arguments.
	 * @param TransformContext    $context Context.
	 * @return string|null Alpha-2 code, or null when unknown.
	 */
	private function defaultRegion( array $args, TransformContext $context ): ?string {
		$setting = $this->text( $args, 'default_country' );
		if ( '' === $setting ) {
			$setting = 0 === strpos( $context->source(), 'shipping.' ) ? 'shipping.country' : 'billing.country';
		}
		if ( 1 === preg_match( '/^[A-Za-z]{2}$/', $setting ) ) {
			return $this->countries->alpha2( $setting );
		}
		$read = $context->read( $setting );
		if ( ! is_scalar( $read ) ) {
			return null;
		}
		return $this->countries->alpha2( Text::toString( $read ) );
	}

	/**
	 * Turn typed text into "+<calling code><national number>".
	 *
	 * @param string      $raw    Typed text.
	 * @param string|null $region Default region for numbers without a country code.
	 * @return string|null E.164 number, or null when the text is not a plausible number.
	 */
	private function normalise( string $raw, ?string $region ): ?string {
		$text = Text::asciiDigits( Text::trim( $raw ) );
		$text = (string) preg_replace( '/\s*(?:ext\.?|extension|x|#)\s*\d+\s*$/i', '', $text );
		if ( 1 === preg_match( '/[A-Za-z\x{0600}-\x{06FF}]/u', $text ) ) {
			return null;
		}
		$digits = (string) preg_replace( '/\D+/', '', $text );
		if ( '' === $digits ) {
			return null;
		}
		$info = null === $region ? null : $this->dialCodes->region( $region );

		if ( 1 === preg_match( '/^[^\d]*\+/', $text ) ) {
			return $this->international( $digits );
		}
		if ( 0 === strpos( $digits, '00' ) ) {
			return $this->international( substr( $digits, 2 ) );
		}
		if ( null !== $info && '1' === $info[0] && 0 === strpos( $digits, '011' ) ) {
			return $this->international( substr( $digits, 3 ) );
		}
		if ( null === $info ) {
			return null;
		}
		return $this->national( $digits, $info );
	}

	/**
	 * A number that starts with a country code.
	 *
	 * @param string $digits Digits after the plus sign or the "00".
	 * @return string|null
	 */
	private function international( string $digits ): ?string {
		$dial = $this->dialCodes->dialCodeAt( $digits );
		if ( null === $dial ) {
			return null;
		}
		list( $min, $max ) = $this->dialCodes->range( $dial );
		$national          = substr( $digits, strlen( $dial ) );
		$length            = strlen( $national );
		if ( ( $length < $min || $length > $max ) ) {
			$trunk = $this->dialCodes->trunkFor( $dial );
			if ( '' !== $trunk && 0 === strpos( $national, $trunk ) ) {
				$national = substr( $national, strlen( $trunk ) );
				$length   = strlen( $national );
			}
		}
		if ( $length < $min || $length > $max || strlen( $dial ) + $length > self::MAX_DIGITS ) {
			return null;
		}
		return '+' . $dial . $national;
	}

	/**
	 * A number typed the way people dial it at home.
	 *
	 * @param string                               $digits Digits.
	 * @param array{0:string,1:int,2:int,3:string} $info   Calling code, minimum, maximum and trunk prefix of the default region.
	 * @return string|null
	 */
	private function national( string $digits, array $info ): ?string {
		list( $dial, $min, $max, $trunk ) = $info;
		$fits                             = static fn( string $number ): bool => strlen( $number ) >= $min && strlen( $number ) <= $max;

		if ( '' !== $trunk && 0 === strpos( $digits, $trunk ) && $fits( substr( $digits, strlen( $trunk ) ) ) ) {
			return '+' . $dial . substr( $digits, strlen( $trunk ) );
		}
		if ( $fits( $digits ) ) {
			return '+' . $dial . $digits;
		}
		if ( 0 === strpos( $digits, $dial ) && $fits( substr( $digits, strlen( $dial ) ) ) ) {
			return '+' . $digits;
		}
		return null;
	}
}
