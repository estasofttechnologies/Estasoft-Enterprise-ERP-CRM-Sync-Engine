<?php
/**
 * The phone_e164 transform.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Transforms\PhoneE164;

final class PhoneE164Test extends TransformTestCase {

	/**
	 * @dataProvider numbers
	 */
	public function test_numbers_become_e164( string $typed, string $country, string $expected ): void {
		$context = $this->context();
		$this->assertSame( $expected, ( new PhoneE164() )->apply( $typed, array( 'default_country' => $country ), $context ), $typed );
		$this->assertSame( array(), $this->warnings( $context ), 'A readable number gives no warning.' );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function numbers(): array {
		return array(
			'uae mobile with trunk zero'   => array( '050 123 4567', 'AE', '+971501234567' ),
			'uae mobile without zero'      => array( '501234567', 'AE', '+971501234567' ),
			'uae landline'                 => array( '04 123 4567', 'AE', '+97141234567' ),
			'uae with plus'                => array( '+971 50 123 4567', 'AE', '+971501234567' ),
			'uae with 00'                  => array( '00971501234567', 'AE', '+971501234567' ),
			'uae code without plus'        => array( '971501234567', 'AE', '+971501234567' ),
			'redundant zero in brackets'   => array( '+971 (0) 50 123 4567', 'AE', '+971501234567' ),
			'brackets and dashes'          => array( '(050) 123-4567', 'AE', '+971501234567' ),
			'arabic indic digits'          => array( '٠٥٠ ١٢٣ ٤٥٦٧', 'AE', '+971501234567' ),
			'extended arabic digits'       => array( '۰۵۰۱۲۳۴۵۶۷', 'AE', '+971501234567' ),
			'saudi mobile'                 => array( '0551234567', 'SA', '+966551234567' ),
			'saudi riyadh landline'        => array( '011 123 4567', 'SA', '+966111234567' ),
			'kuwait'                       => array( '9876 5432', 'KW', '+96598765432' ),
			'bahrain'                      => array( '3612 3456', 'BH', '+97336123456' ),
			'oman'                         => array( '9123 4567', 'OM', '+96891234567' ),
			'qatar'                        => array( '5512 3456', 'QA', '+97455123456' ),
			'egypt mobile'                 => array( '010 1234 5678', 'EG', '+201012345678' ),
			'jordan mobile'                => array( '079 123 4567', 'JO', '+962791234567' ),
			'lebanon mobile'               => array( '03 123 456', 'LB', '+9613123456' ),
			'iraq mobile'                  => array( '0750 123 4567', 'IQ', '+9647501234567' ),
			'turkey mobile'                => array( '0532 123 45 67', 'TR', '+905321234567' ),
			'us number'                    => array( '(415) 555-2671', 'US', '+14155552671' ),
			'us with leading one'          => array( '1 415 555 2671', 'US', '+14155552671' ),
			'canada'                       => array( '416-555-0199', 'CA', '+14165550199' ),
			'uk mobile'                    => array( '07912 345678', 'GB', '+447912345678' ),
			'uk with plus and zero'        => array( '+44 (0)7912 345678', 'GB', '+447912345678' ),
			'germany'                      => array( '0151 23456789', 'DE', '+4915123456789' ),
			'france'                       => array( '06 12 34 56 78', 'FR', '+33612345678' ),
			'italy keeps the zero'         => array( '06 1234 5678', 'IT', '+390612345678' ),
			'spain'                        => array( '612 345 678', 'ES', '+34612345678' ),
			'australia'                    => array( '0412 345 678', 'AU', '+61412345678' ),
			'new zealand'                  => array( '021 123 4567', 'NZ', '+64211234567' ),
			'india'                        => array( '098765 43210', 'IN', '+919876543210' ),
			'pakistan'                     => array( '0301 2345678', 'PK', '+923012345678' ),
			'foreign number, other default' => array( '+44 7912 345678', 'AE', '+447912345678' ),
			'extension is dropped'         => array( '050 123 4567 ext. 12', 'AE', '+971501234567' ),
			'lower case country'           => array( '050 123 4567', 'ae', '+971501234567' ),
		);
	}

	/**
	 * @dataProvider unreadable
	 */
	public function test_unreadable_numbers_become_empty_with_a_warning( string $typed, string $country ): void {
		$context = $this->context();
		$this->assertSame( '', ( new PhoneE164() )->apply( $typed, array( 'default_country' => $country ), $context ), $typed );
		$this->assertCount( 1, $this->warnings( $context ), $typed );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function unreadable(): array {
		return array(
			'too short'                 => array( '12345', 'AE' ),
			'too long'                  => array( '050 123 4567 89012', 'AE' ),
			'letters'                   => array( '1-800-FLOWERS', 'US' ),
			'arabic words'              => array( 'غير متوفر', 'AE' ),
			'only punctuation'          => array( '--', 'AE' ),
			'unknown calling code'      => array( '+999 1234 5678', 'AE' ),
			'plus but wrong length'     => array( '+971 5012', 'AE' ),
			'no default country'        => array( '501234567', '' ),
			'unknown default country'   => array( '501234567', 'ZZ' ),
		);
	}

	public function test_empty_values_pass_through_without_a_warning(): void {
		$context = $this->context();
		$phone   = new PhoneE164();
		$this->assertNull( $phone->apply( null, array( 'default_country' => 'AE' ), $context ) );
		$this->assertSame( '', $phone->apply( '', array( 'default_country' => 'AE' ), $context ) );
		$this->assertSame( '  ', $phone->apply( '  ', array( 'default_country' => 'AE' ), $context ) );
		$this->assertSame( array(), $this->warnings( $context ) );
	}

	public function test_default_country_can_be_a_path_to_the_billing_country(): void {
		$context = $this->context( array( 'billing' => array( 'country' => 'SA' ) ), 'billing.phone' );
		$this->assertSame( '+966551234567', ( new PhoneE164() )->apply( '055 123 4567', array( 'default_country' => 'billing.country' ), $context ) );
	}

	public function test_default_country_is_the_billing_or_shipping_country_when_not_given(): void {
		$order = array(
			'billing'  => array( 'country' => 'AE' ),
			'shipping' => array( 'country' => 'SA' ),
		);
		$this->assertSame( '+971501234567', ( new PhoneE164() )->apply( '050 123 4567', array(), $this->context( $order, 'billing.phone' ) ) );
		$this->assertSame( '+966551234567', ( new PhoneE164() )->apply( '055 123 4567', array(), $this->context( $order, 'shipping.phone' ) ) );
	}

	public function test_default_country_can_be_a_country_name(): void {
		$context = $this->context( array( 'billing' => array( 'country' => 'United Arab Emirates' ) ) );
		$this->assertSame( '+971501234567', ( new PhoneE164() )->apply( '050 123 4567', array( 'default_country' => 'billing.country' ), $context ) );
	}

	public function test_output_is_never_longer_than_fifteen_digits(): void {
		$context = $this->context();
		$this->assertSame( '', ( new PhoneE164() )->apply( '+1 415 555 2671 999 999', array(), $context ) );
	}

	public function test_check(): void {
		$phone = new PhoneE164();
		$this->assertSame( array(), $phone->check( array() ) );
		$this->assertSame( array(), $phone->check( array( 'default_country' => 'AE' ) ) );
		$this->assertSame( array(), $phone->check( array( 'default_country' => 'billing.country' ) ) );
		$this->assertCount( 1, $phone->check( array( 'default_country' => 'not a country!' ) ) );
	}
}
