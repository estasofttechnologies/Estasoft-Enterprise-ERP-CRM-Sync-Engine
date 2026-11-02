<?php
/**
 * iso_country and iso_state: AS-17 (Arabic names).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Transforms;

use Estasoft\SyncEngine\Mapping\Transforms\IsoCountry;
use Estasoft\SyncEngine\Mapping\Transforms\IsoState;

final class IsoTransformsTest extends TransformTestCase {

	/**
	 * @dataProvider countries
	 */
	public function test_country_names_and_codes( string $typed, string $expected ): void {
		$context = $this->context();
		$this->assertSame( $expected, ( new IsoCountry() )->apply( $typed, array(), $context ), $typed );
		$this->assertSame( array(), $this->warnings( $context ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function countries(): array {
		return array(
			'alpha 2'                 => array( 'AE', 'AE' ),
			'alpha 2 lower case'      => array( 'ae', 'AE' ),
			'alpha 3'                 => array( 'ARE', 'AE' ),
			'english name'            => array( 'United Arab Emirates', 'AE' ),
			'english with spaces'     => array( '  united  arab emirates ', 'AE' ),
			'abbreviation'            => array( 'U.A.E', 'AE' ),
			'arabic uae'              => array( 'الإمارات العربية المتحدة', 'AE' ),
			'arabic uae short'        => array( 'الامارات', 'AE' ),
			'arabic saudi'            => array( 'المملكة العربية السعودية', 'SA' ),
			'arabic saudi short'      => array( 'السعودية', 'SA' ),
			'arabic kuwait'           => array( 'الكويت', 'KW' ),
			'arabic qatar'            => array( 'قطر', 'QA' ),
			'arabic bahrain'          => array( 'البحرين', 'BH' ),
			'arabic oman'             => array( 'سلطنة عمان', 'OM' ),
			'arabic oman short'       => array( 'عمان', 'OM' ),
			'arabic egypt'            => array( 'مصر', 'EG' ),
			'arabic jordan'           => array( 'الأردن', 'JO' ),
			'arabic lebanon'          => array( 'لبنان', 'LB' ),
			'arabic iraq'             => array( 'العراق', 'IQ' ),
			'arabic usa'              => array( 'الولايات المتحدة الأمريكية', 'US' ),
			'usa'                     => array( 'USA', 'US' ),
			'united states'           => array( 'United States', 'US' ),
			'uk'                      => array( 'UK', 'GB' ),
			'britain'                 => array( 'Great Britain', 'GB' ),
			'turkey'                  => array( 'Turkey', 'TR' ),
			'turkiye with accent'     => array( 'Türkiye', 'TR' ),
			'cote d ivoire'           => array( "Côte d'Ivoire", 'CI' ),
			'korea'                   => array( 'South Korea', 'KR' ),
			'russia'                  => array( 'Russia', 'RU' ),
			'india'                   => array( 'India', 'IN' ),
			'pakistan'                => array( 'Pakistan', 'PK' ),
		);
	}

	public function test_country_output_can_be_alpha3(): void {
		$this->assertSame( 'ARE', ( new IsoCountry() )->apply( 'الإمارات', array( 'output' => 'alpha3' ), $this->context() ) );
	}

	public function test_unknown_country_becomes_empty_with_a_warning(): void {
		$context = $this->context();
		$this->assertSame( '', ( new IsoCountry() )->apply( 'Atlantis', array(), $context ) );
		$this->assertCount( 1, $this->warnings( $context ) );
	}

	public function test_empty_country_passes_through(): void {
		$context = $this->context();
		$this->assertSame( '', ( new IsoCountry() )->apply( '', array(), $context ) );
		$this->assertNull( ( new IsoCountry() )->apply( null, array(), $context ) );
		$this->assertSame( array(), $this->warnings( $context ) );
	}

	public function test_country_check(): void {
		$this->assertSame( array(), ( new IsoCountry() )->check( array() ) );
		$this->assertSame( array(), ( new IsoCountry() )->check( array( 'output' => 'alpha3' ) ) );
		$this->assertCount( 1, ( new IsoCountry() )->check( array( 'output' => 'numeric' ) ) );
	}

	/**
	 * @dataProvider states
	 */
	public function test_states( string $typed, string $country, string $expected ): void {
		$context = $this->context( array( 'billing' => array( 'country' => $country ) ), 'billing.state' );
		$this->assertSame( $expected, ( new IsoState() )->apply( $typed, array(), $context ), $typed );
		$this->assertSame( array(), $this->warnings( $context ), $typed );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function states(): array {
		return array(
			'woocommerce code for dubai'  => array( 'DU', 'AE', 'AE-DU' ),
			'iso code'                    => array( 'AE-DU', 'AE', 'AE-DU' ),
			'dubai in english'            => array( 'Dubai', 'AE', 'AE-DU' ),
			'dubai in english lower'      => array( ' dubai ', 'AE', 'AE-DU' ),
			'dubai in arabic'             => array( 'دبي', 'AE', 'AE-DU' ),
			'dubai emirate in arabic'     => array( 'إمارة دبي', 'AE', 'AE-DU' ),
			'abu dhabi'                   => array( 'Abu Dhabi', 'AE', 'AE-AZ' ),
			'abu dhabi arabic'            => array( 'أبوظبي', 'AE', 'AE-AZ' ),
			'sharjah'                     => array( 'Sharjah', 'AE', 'AE-SH' ),
			'ras al khaimah'              => array( 'Ras Al Khaimah', 'AE', 'AE-RK' ),
			'umm al quwain arabic'        => array( 'أم القيوين', 'AE', 'AE-UQ' ),
			'riyadh'                      => array( 'Riyadh', 'SA', 'SA-01' ),
			'riyadh arabic'               => array( 'الرياض', 'SA', 'SA-01' ),
			'makkah'                      => array( 'مكة المكرمة', 'SA', 'SA-02' ),
			'eastern province'            => array( 'Eastern Province', 'SA', 'SA-04' ),
			'kuwait capital'              => array( 'العاصمة', 'KW', 'KW-KU' ),
			'hawalli'                     => array( 'Hawalli', 'KW', 'KW-HA' ),
			'muscat'                      => array( 'مسقط', 'OM', 'OM-MA' ),
			'doha'                        => array( 'الدوحة', 'QA', 'QA-DA' ),
			'us state code'               => array( 'CA', 'US', 'US-CA' ),
			'us state name'               => array( 'California', 'US', 'US-CA' ),
			'us dc'                       => array( 'District of Columbia', 'US', 'US-DC' ),
			'canadian province'           => array( 'Ontario', 'CA', 'CA-ON' ),
			'australian state'            => array( 'New South Wales', 'AU', 'AU-NSW' ),
			'woocommerce egypt style'     => array( 'EGALX', 'EG', 'EG-ALX' ),
			'german land'                 => array( 'Bayern', 'DE', 'DE-BY' ),
			'country without a table, code' => array( 'AB', 'FR', 'FR-AB' ),
		);
	}

	public function test_unknown_state_becomes_empty_with_a_warning(): void {
		$context = $this->context( array( 'billing' => array( 'country' => 'AE' ) ), 'billing.state' );
		$this->assertSame( '', ( new IsoState() )->apply( 'Narnia', array(), $context ) );
		$this->assertCount( 1, $this->warnings( $context ) );
	}

	public function test_state_name_in_a_country_without_a_table_is_not_guessed(): void {
		$context = $this->context( array( 'billing' => array( 'country' => 'FR' ) ), 'billing.state' );
		$this->assertSame( '', ( new IsoState() )->apply( 'Île-de-France', array(), $context ) );
		$this->assertCount( 1, $this->warnings( $context ) );
	}

	public function test_state_without_a_known_country_is_empty_with_a_warning(): void {
		$context = $this->context( array(), 'billing.state' );
		$this->assertSame( '', ( new IsoState() )->apply( 'Dubai', array(), $context ) );
		$this->assertCount( 1, $this->warnings( $context ) );
	}

	public function test_state_uses_the_shipping_country_for_shipping_sources(): void {
		$order = array(
			'billing'  => array( 'country' => 'US' ),
			'shipping' => array( 'country' => 'AE' ),
		);
		$this->assertSame( 'AE-DU', ( new IsoState() )->apply( 'DU', array(), $this->context( $order, 'shipping.state' ) ) );
	}

	public function test_state_country_argument_can_be_a_code_or_a_path(): void {
		$state = new IsoState();
		$this->assertSame( 'AE-DU', $state->apply( 'Dubai', array( 'country' => 'AE' ), $this->context() ) );
		$this->assertSame( 'AE-DU', $state->apply( 'Dubai', array( 'country' => 'shipping.country' ), $this->context( array( 'shipping' => array( 'country' => 'AE' ) ) ) ) );
		$this->assertCount( 1, $state->check( array( 'country' => 'no good!' ) ) );
		$this->assertSame( array(), $state->check( array( 'country' => 'shipping.country' ) ) );
	}

	public function test_empty_state_passes_through(): void {
		$context = $this->context( array( 'billing' => array( 'country' => 'AE' ) ), 'billing.state' );
		$this->assertSame( '', ( new IsoState() )->apply( '', array(), $context ) );
		$this->assertSame( array(), $this->warnings( $context ) );
	}
}
