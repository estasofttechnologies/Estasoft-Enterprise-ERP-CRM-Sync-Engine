<?php
/**
 * Tax mapping (AS-15) and the buyer's tax number (AS-16).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\MappingException;
use Estasoft\SyncEngine\Mapping\Presets;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\TaxMap;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\InMemoryMappingStore;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use PHPUnit\Framework\TestCase;

final class TaxMapTest extends TestCase {

	private InMemoryMappingStore $store;
	private RecordingLog $log;
	private TaxMap $taxMap;

	protected function setUp(): void {
		$this->store  = new InMemoryMappingStore();
		$this->log    = new RecordingLog();
		$this->taxMap = new TaxMap( $this->store, $this->log );
	}

	/**
	 * Rows of the sample shop's tax screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		return array(
			array( 'country' => 'AE', 'tax_name' => 'Standard VAT', 'rate_percent' => 5, 'system_code' => 'VAT 5% (AE)' ),
			array( 'country' => 'AE', 'tax_name' => 'Zero-rated export', 'rate_percent' => 0, 'system_code' => 'VAT 0% (AE export)' ),
			array( 'country' => 'SA', 'tax_name' => 'Standard VAT', 'rate_percent' => 15, 'system_code' => 'VAT 15% (SA)' ),
			array( 'country' => 'OM', 'tax_name' => 'Standard VAT', 'rate_percent' => 5, 'system_code' => '' ),
		);
	}

	public function test_a_rate_resolves_to_its_code_by_country_and_rate(): void {
		$this->taxMap->save( 1, $this->rows(), null, 'Layla Haddad' );
		$this->assertSame( 'VAT 5% (AE)', $this->taxMap->resolve( 1, 'AE', '', '5' ) );
		$this->assertSame( 'VAT 5% (AE)', $this->taxMap->resolve( 1, 'ae', '', '5' ) );
		$this->assertSame( 'VAT 0% (AE export)', $this->taxMap->resolve( 1, 'AE', '', '0' ) );
		$this->assertSame( 'VAT 15% (SA)', $this->taxMap->resolve( 1, 'SA', '', '15' ) );
	}

	public function test_a_rate_with_no_code_does_not_resolve(): void {
		$this->taxMap->save( 1, $this->rows(), null, 'Layla Haddad' );
		$this->assertNull( $this->taxMap->resolve( 1, 'OM', '', '5' ), 'Oman has a row but no code yet.' );
		$this->assertNull( $this->taxMap->resolve( 1, 'AE', '', '7.5' ) );
		$this->assertNull( $this->taxMap->resolve( 1, 'KW', '', '5' ) );
		$this->assertNull( $this->taxMap->resolve( 2, 'AE', '', '5' ), 'Another connection has its own mapping.' );
	}

	public function test_tax_class_matches_exactly_first_then_any_class(): void {
		$this->taxMap->save(
			1,
			array(
				array( 'country' => 'AE', 'tax_class' => '', 'rate_percent' => 5, 'system_code' => 'STANDARD' ),
				array( 'country' => 'AE', 'tax_class' => 'reduced-rate', 'rate_percent' => 5, 'system_code' => 'REDUCED' ),
				array( 'country' => 'SA', 'tax_class' => 'zero-rate', 'rate_percent' => 15, 'system_code' => 'SA ZERO' ),
			),
			null,
			'x'
		);
		$this->assertSame( 'STANDARD', $this->taxMap->resolve( 1, 'AE', '', '5' ) );
		$this->assertSame( 'REDUCED', $this->taxMap->resolve( 1, 'AE', 'reduced-rate', '5' ) );
		$this->assertNull( $this->taxMap->resolve( 1, 'AE', 'other-class', '5' ), 'Two rows with different codes for the same rate are ambiguous.' );
		$this->assertSame( 'SA ZERO', $this->taxMap->resolve( 1, 'SA', '', '15' ), 'A single code for the country and rate is used for any class.' );
	}

	public function test_rates_compare_numerically_not_as_text(): void {
		$this->taxMap->save( 1, array( array( 'country' => 'DE', 'rate_percent' => '19.00', 'system_code' => 'VAT 19%' ), array( 'country' => 'CA', 'rate_percent' => 7.5, 'system_code' => 'PST' ) ), null, 'x' );
		$this->assertSame( 'VAT 19%', $this->taxMap->resolve( 1, 'DE', '', '19' ) );
		$this->assertSame( 'PST', $this->taxMap->resolve( 1, 'CA', '', '7.5' ) );
	}

	public function test_saving_is_live_at_once_with_no_draft_and_no_new_version(): void {
		$this->taxMap->save( 1, $this->rows(), null, 'Layla Haddad' );
		$this->taxMap->save( 1, array( array( 'country' => 'AE', 'rate_percent' => 5, 'system_code' => 'CHANGED' ) ), null, 'Layla Haddad' );
		$this->assertSame( array( 'replaceLive', 'replaceLive' ), $this->store->calls );
		$this->assertSame( array( 1 => 'live' ), $this->store->statuses( 1, 'tax' ) );
		$this->assertSame( 'CHANGED', $this->taxMap->resolve( 1, 'AE', '', '5' ), 'The cached rows were dropped on save.' );
	}

	public function test_each_change_is_written_to_the_log_with_who_made_it(): void {
		$this->taxMap->save( 1, $this->rows(), null, 'Layla Haddad' );
		$this->assertCount( 3, $this->log->level( 'info' ), 'Three codes were set; the row with no code yet is not a change.' );
		$this->log->entries = array();

		$changed = $this->rows();
		$changed[3]['system_code'] = 'VAT 5% (OM)';
		unset( $changed[1] );
		$this->taxMap->save( 1, array_values( $changed ), null, 'Layla Haddad' );

		$entries = $this->log->level( 'info' );
		$this->assertCount( 2, $entries, 'Oman got a code, the zero-rated row was removed.' );
		$this->assertSame( 'Layla Haddad', $entries[0][2]['by'] );
		$this->assertSame( 1, $entries[0][2]['connection_id'] );
		$tos = array_map( static fn( array $entry ): string => $entry[2]['from'] . '>' . $entry[2]['to'], $entries );
		sort( $tos );
		$this->assertSame( array( '>VAT 5% (OM)', 'VAT 0% (AE export)>' ), $tos );
	}

	public function test_saving_without_changes_logs_nothing(): void {
		$this->taxMap->save( 1, $this->rows(), null, 'x' );
		$this->log->entries = array();
		$this->taxMap->save( 1, $this->rows(), null, 'x' );
		$this->assertSame( array(), $this->log->entries );
	}

	public function test_bad_rows_are_refused_and_nothing_is_saved(): void {
		foreach ( array(
			array( array( 'country' => '', 'rate_percent' => 5, 'system_code' => 'x' ) ),
			array( array( 'country' => 'UAE', 'rate_percent' => 5, 'system_code' => 'x' ) ),
			array( array( 'country' => 'AE', 'rate_percent' => 'five', 'system_code' => 'x' ) ),
			array( array( 'country' => 'AE', 'rate_percent' => 120, 'system_code' => 'x' ) ),
			array( array( 'country' => 'AE', 'rate_percent' => -1, 'system_code' => 'x' ) ),
		) as $rows ) {
			try {
				$this->taxMap->save( 1, $rows, null, 'x' );
				$this->fail( 'A bad row must be refused.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( array(), $this->store->calls );
			}
		}
	}

	public function test_codes_are_cleaned_of_markup_and_cut_to_length(): void {
		$this->taxMap->save( 1, array( array( 'country' => 'ae', 'rate_percent' => 5, 'tax_name' => '<b>VAT</b>', 'system_code' => "  VAT <script>5%\x07 " . str_repeat( 'x', 300 ) ) ), null, 'x' );
		$row = $this->taxMap->rows( 1 )[0];
		$this->assertSame( 'AE', $row['country'] );
		$this->assertSame( 'bVAT/b', $row['tax_name'] );
		$this->assertStringNotContainsString( '<', (string) $row['system_code'] );
		$this->assertSame( 191, strlen( (string) $row['system_code'] ) );
	}

	public function test_overview_lists_woocommerce_rates_that_have_no_row_yet(): void {
		$this->taxMap->save( 1, $this->rows(), null, 'x' );
		$overview = $this->taxMap->overview(
			1,
			array(
				array( 'country' => 'AE', 'tax_class' => '', 'tax_name' => 'Standard VAT', 'rate_percent' => 5 ),
				array( 'country' => 'BH', 'tax_class' => '', 'tax_name' => 'Bahrain VAT', 'rate_percent' => 10 ),
			)
		);
		$this->assertCount( 5, $overview );
		$this->assertSame( 'BH', $overview[4]['country'] );
		$this->assertNull( $overview[4]['system_code'] );
	}

	public function test_as_15_an_unmapped_rate_stops_the_order_until_a_code_is_chosen(): void {
		$engine = new RuleEngine( TransformRegistry::standard() );
		$rules  = array( Presets::rule( 'tax', 'lines[].tax', 'lines[].tax_rate', array( Presets::transform( 'tax_lookup' ) ) ) );
		$order  = new CanonicalOrder( Orders::dubai( array( 'billing' => array( 'country' => 'OM' ), 'shipping' => array( 'country' => 'OM' ) ) ) );
		$env    = new MappingEnvironment( 1, 'Odoo', $this->taxMap );

		$this->taxMap->save( 1, $this->rows(), null, 'x' );
		try {
			$engine->apply( $rules, $order, $env );
			$this->fail( 'Orders that use Oman VAT 5% must fail until a code is chosen.' );
		} catch ( MappingException $e ) {
			$this->assertSame( ErrorCode::Map002, $e->errorCode );
			$this->assertSame( 'Tax code not mapped for Oman 5%.', $e->toConnectorError()->message() );
		}

		$withOman                  = $this->rows();
		$withOman[3]['system_code'] = 'VAT 5% (OM)';
		$this->taxMap->save( 1, $withOman, null, 'x' );
		$output = $engine->apply( $rules, $order, $env );
		$this->assertSame( 'VAT 5% (OM)', $output->payload['lines'][0]['tax'] );
	}

	/**
	 * A Saudi order.
	 *
	 * @param array<string,mixed> $billing Billing overrides.
	 * @param string              $number  Buyer tax number.
	 */
	private function saudiOrder( array $billing = array(), string $number = '' ): CanonicalOrder {
		return new CanonicalOrder( Orders::dubai( array( 'billing' => array_merge( array( 'country' => 'SA' ), $billing ), 'order' => array( 'buyer_tax_number' => $number ) ) ) );
	}

	public function test_as_16_a_saudi_business_buyer_needs_fifteen_digits(): void {
		foreach ( array( '12345678901234', '1234567890123456', '3000000000abc03', 'ABC' ) as $bad ) {
			try {
				$this->taxMap->assertBuyerTaxNumber( 1, $this->saudiOrder( array(), $bad ) );
				$this->fail( 'Expected SYNC-VAL-001 for ' . $bad );
			} catch ( MappingException $e ) {
				$this->assertSame( ErrorCode::Val001, $e->errorCode, $bad );
				$this->assertSame( 'Buyer tax number must be 15 digits for Saudi Arabia.', $e->toConnectorError()->message() );
			}
		}
		$this->taxMap->assertBuyerTaxNumber( 1, $this->saudiOrder( array(), '300000000000003' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_a_saudi_company_must_give_a_number(): void {
		$this->expectException( MappingException::class );
		$this->taxMap->assertBuyerTaxNumber( 1, $this->saudiOrder( array( 'company' => 'شركة الأمل' ) ) );
	}

	public function test_a_saudi_consumer_without_a_number_is_fine(): void {
		$this->taxMap->assertBuyerTaxNumber( 1, $this->saudiOrder() );
		$this->addToAssertionCount( 1 );
	}

	public function test_numbers_with_spaces_dashes_and_arabic_digits_are_accepted(): void {
		foreach ( array( '3000 0000 0000 003', '300-000-000-000-003', '٣٠٠٠٠٠٠٠٠٠٠٠٠٠٣' ) as $good ) {
			$this->taxMap->assertBuyerTaxNumber( 1, $this->saudiOrder( array(), $good ) );
		}
		$this->addToAssertionCount( 1 );
	}

	public function test_other_countries_have_no_rule_by_default(): void {
		$this->taxMap->assertBuyerTaxNumber( 1, new CanonicalOrder( Orders::dubai( array( 'order' => array( 'buyer_tax_number' => '123' ), 'billing' => array( 'company' => 'Acme' ) ) ) ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_a_store_can_add_its_own_buyer_rules_and_switch_the_default_off(): void {
		$this->taxMap->save(
			1,
			array(),
			array(
				array( 'country' => 'AE', 'digits' => 15, 'required_for_business' => true ),
				array( 'country' => 'SA', 'digits' => null, 'required_for_business' => false ),
			),
			'x'
		);
		$rules = $this->taxMap->buyerRules( 1 );
		$this->assertCount( 2, $rules );

		$this->taxMap->assertBuyerTaxNumber( 1, $this->saudiOrder( array( 'company' => 'Acme' ), '123' ) );

		$this->expectException( MappingException::class );
		$this->taxMap->assertBuyerTaxNumber( 1, new CanonicalOrder( Orders::dubai( array( 'billing' => array( 'company' => 'Acme' ) ) ) ) );
	}

	public function test_default_rules_apply_until_a_tax_mapping_is_saved(): void {
		$rules = $this->taxMap->buyerRules( 1 );
		$this->assertSame( array( array( 'country' => 'SA', 'digits' => 15, 'required_for_business' => true ) ), $rules );

		$this->taxMap->save( 1, $this->rows(), null, 'x' );
		$this->assertSame( $rules, $this->taxMap->buyerRules( 1 ), 'The first save writes the default rule down, so it can be seen and changed.' );
		$stored = array_filter( (array) $this->store->live( 1, 'tax' )['rules'], static fn( array $entry ): bool => 'buyer_tax' === $entry['kind'] );
		$this->assertCount( 1, $stored );

		$this->taxMap->save( 1, $this->rows(), array(), 'x' );
		$this->assertSame( array(), $this->taxMap->buyerRules( 1 ), 'Saving an empty list switches the rule off.' );
	}
}
