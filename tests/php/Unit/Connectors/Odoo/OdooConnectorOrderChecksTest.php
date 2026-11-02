<?php
/**
 * What the connector makes sure of before an order is confirmed: its price list and currency, how taxes treat prices, the
 * total Odoo works out, the order date, and the company the records come from.
 *
 * Each test sets up the Odoo behaviour the check depends on (the fake models it) so that a connector without the check fails.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeOdooServer;

final class OdooConnectorOrderChecksTest extends OdooTestCase {

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function provide_majors(): array {
		return array(
			'odoo 17 json-rpc' => array( 17 ),
			'odoo 18 json-rpc' => array( 18 ),
			'odoo 19 json-2'   => array( 19 ),
		);
	}

	private function send( FakeOdooServer $server, array $payload, array $options = array() ): SendResult {
		return $this->connector( $server )->send(
			$this->connection( $server, array( 'options' => $options + array( 'call_interval_ms' => 0 ) ) ),
			$this->item( EntityType::Order, '10490' ),
			$payload
		);
	}

	/* ------------------------------------------------------------ price list and currency */

	/**
	 * @dataProvider provide_majors
	 */
	public function test_a_customer_with_a_price_list_in_another_currency_still_gets_an_order_in_the_shop_currency( int $major ): void {
		$server = $this->server( $major );
		$usd    = $server->db->addPricelist( 'Export USD', 'USD' );
		$server->db->addPartner( 'Noor (old record)', 'noor@example.com', array( '_pricelist' => $usd ) );

		$result = $this->send( $server, $this->orderPayload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'AED', $result->ref->extra['currency'], 'Odoo would have used the customer\'s USD list if the order had not been pinned.' );
		$this->assertSame( 48615, $result->ref->extra['total_minor'] );
		$order = $server->db->all( 'sale.order' )[0];
		$this->assertSame( 1, $order['pricelist_id'], 'Pinned to the list in AED.' );
	}

	public function test_a_price_list_shared_by_all_companies_is_found_and_another_companys_list_is_not_used(): void {
		$server = $this->server( 18 );
		$other  = $server->db->addCompany( 'Other Trading Co' );
		$server->db->addPricelist( 'Other company USD', 'USD', $other, 1 );
		$shared = $server->db->addPricelist( 'Shared USD', 'USD', false, 10 );

		$result = $this->send( $server, $this->orderPayload( array( 'currency' => 'USD' ) ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( $shared, $server->db->all( 'sale.order' )[0]['pricelist_id'] );
		$this->assertSame( 'USD', $result->ref->extra['currency'] );
	}

	public function test_a_price_list_of_the_company_wins_by_sequence_over_a_later_one(): void {
		$server = $this->server( 18 );
		$server->db->addPricelist( 'Late USD', 'USD', false, 50 );
		$early = $server->db->addPricelist( 'Early USD', 'USD', 1, 5 );
		$this->send( $server, $this->orderPayload( array( 'currency' => 'USD' ) ) );
		$this->assertSame( $early, $server->db->all( 'sale.order' )[0]['pricelist_id'] );
	}

	public function test_a_missing_price_list_is_made_when_the_options_say_so(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload( array( 'currency' => 'USD' ) ), array( 'create_pricelist' => 'yes' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lists = $server->db->all( 'product.pricelist' );
		$made  = end( $lists );
		$this->assertSame( 'WooCommerce USD', $made['name'] );
		$this->assertSame( 2, $made['currency_id'] );
		$this->assertSame( 1, $made['company_id'], 'Made for the company the connector works in.' );
		$this->assertSame( 'USD', $result->ref->extra['currency'] );
	}

	public function test_a_price_list_cannot_be_made_for_a_currency_odoo_does_not_have(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload( array( 'currency' => 'EUR' ) ), array( 'create_pricelist' => 'yes' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'EUR', $result->error->context['reason'] );
		$this->assertCount( 0, $server->db->all( 'sale.order' ) );
		$this->assertCount( 0, $server->db->all( 'res.partner' ) );
	}

	public function test_the_option_pricelist_picks_one_list_and_it_must_be_in_the_order_currency(): void {
		$server = $this->server( 18 );
		$vip    = $server->db->addPricelist( 'Wholesale', 'AED', false, 90 );
		$server->db->addPricelist( 'Wholesale USD', 'USD' );

		$ok = $this->send( $server, $this->orderPayload(), array( 'pricelist' => 'wholesale' ) );
		$this->assertTrue( $ok->isSuccess(), (string) $ok->error?->message() );
		$this->assertSame( $vip, $server->db->all( 'sale.order' )[0]['pricelist_id'], 'The named list, although another AED list comes first.' );

		$other  = $this->server( 18 );
		$other->db->addPricelist( 'Wholesale USD', 'USD' );
		$result = $this->send( $other, $this->orderPayload(), array( 'pricelist' => 'Wholesale USD' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Wholesale USD', $result->error->context['reason'] );
		$this->assertStringContainsString( 'AED', $result->error->context['reason'] );
		$this->assertCount( 0, $other->db->all( 'sale.order' ) );
	}

	public function test_an_order_that_comes_back_in_another_currency_is_not_confirmed(): void {
		$server                         = $this->server( 18 );
		$server->db->currencyOverride = 'USD';

		$result = $this->send( $server, $this->orderPayload() );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'currency', $result->error->context['field'] );
		$this->assertStringContainsString( 'USD', $result->error->context['reason'] );
		$this->assertStringContainsString( 'AED', $result->error->context['reason'] );
		$this->assertSame( array(), $server->callsTo( 'sale.order', 'action_confirm' ), 'Never confirmed.' );
		$this->assertCount( 0, $server->db->all( 'sale.order' ), 'The quotation was removed again.' );
		$this->assertStringContainsString( 'removed', $result->error->context['reason'] );
	}

	/* ------------------------------------------------------------ the total */

	/**
	 * @dataProvider provide_majors
	 */
	public function test_net_prices_are_converted_for_a_tax_that_is_included_in_the_price( int $major ): void {
		$server = $this->server( $major );
		$server->db->addTax( 'VAT 5% incl', 5.0, 'sale', true );
		$payload                         = $this->orderPayload( array( 'total' => 48615 ) );
		$payload['order_line'][0]['tax'] = 'VAT 5% incl';
		$payload['shipping']['tax']      = 'VAT 5% incl';

		$result = $this->send( $server, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$prices = array_column( $server->db->all( 'sale.order.line' ), 'price_unit' );
		$this->assertSame( array( 229.95, 26.25 ), $prices, '219.00 and 25.00 plus 5% tax, because Odoo counts the tax inside the price.' );
		$this->assertSame( 48615, $result->ref->extra['total_minor'] );
	}

	public function test_without_the_conversion_the_total_would_have_been_wrong_and_is_caught(): void {
		$server = $this->server( 18 );
		// Net prices sent as they are to a tax included in the price: the connector must not do that. The total check is the
		// second line of defence when the tax is not named in the mapping and Odoo applies a product's default tax.
		$server->db->records['account.tax'][1]['price_include'] = true;
		$payload = $this->orderPayload( array( 'total' => 48615 ) );
		unset( $payload['order_line'][0]['tax'], $payload['shipping']['tax'] );
		$result = $this->send( $server, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'total', $result->error->context['field'] );
	}

	public function test_gross_prices_are_converted_for_a_tax_that_is_not_included(): void {
		$server  = $this->server( 18 );
		$payload = $this->orderPayload( array( 'total' => 48615, 'prices_include_tax' => true ) );
		$payload['order_line'][0]['price_unit'] = 22995;
		$payload['shipping']['total']           = 2625;

		$result = $this->send( $server, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array( 219.0, 25.0 ), array_column( $server->db->all( 'sale.order.line' ), 'price_unit' ) );
		$this->assertSame( 48615, $result->ref->extra['total_minor'] );
	}

	public function test_a_discount_line_is_converted_with_its_sign(): void {
		$server = $this->server( 18 );
		$server->db->addTax( 'VAT 5% incl', 5.0, 'sale', true );
		$payload                         = $this->orderPayload();
		$payload['order_line'][0]['tax'] = 'VAT 5% incl';
		$payload['shipping']['tax']      = 'VAT 5% incl';
		$payload['discount']             = array( 'total' => 1000, 'name' => 'SUMMER10', 'tax' => 'VAT 5% incl' );

		$result = $this->send( $server, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertContains( -10.5, array_column( $server->db->all( 'sale.order.line' ), 'price_unit' ), '10.00 off becomes 10.50 off with the tax inside.' );
	}

	public function test_several_taxes_that_disagree_with_the_shop_are_refused_naming_the_tax(): void {
		$server = $this->server( 18 );
		$server->db->addTax( 'VAT 5% incl', 5.0, 'sale', true );
		$server->db->addTax( 'Tourism levy 2%', 2.0 );
		$payload                         = $this->orderPayload();
		$payload['order_line'][0]['tax'] = array( 'VAT 5% incl', 'Tourism levy 2%' );

		$result = $this->send( $server, $payload );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'tax', $result->error->context['field'] );
		$this->assertStringContainsString( 'VAT 5% incl', $result->error->context['reason'] );
		$this->assertCount( 0, $server->db->all( 'sale.order' ) );
		$this->assertCount( 0, $server->db->all( 'res.partner' ), 'Refused before the customer is created.' );
	}

	public function test_a_fixed_tax_that_disagrees_is_refused(): void {
		$server = $this->server( 18 );
		$server->db->insert( 'account.tax', array( 'name' => 'Bottle deposit', 'amount' => 1.0, 'amount_type' => 'fixed', 'type_tax_use' => 'sale', 'active' => true, 'company_id' => 1, 'price_include' => true ) );
		$payload                         = $this->orderPayload();
		$payload['order_line'][0]['tax'] = 'Bottle deposit';

		$result = $this->send( $server, $payload );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Bottle deposit', $result->error->context['reason'] );
	}

	public function test_a_tax_without_a_rate_needs_no_conversion(): void {
		$server = $this->server( 18 );
		$server->db->addTax( 'Zero incl', 0.0, 'sale', true );
		$payload                         = $this->orderPayload( array( 'total' => 46300 ) );
		$payload['order_line'][0]['tax'] = 'Zero incl';
		$payload['shipping']['tax']      = 'Zero incl';

		$result = $this->send( $server, $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array( 219.0, 25.0 ), array_column( $server->db->all( 'sale.order.line' ), 'price_unit' ) );
	}

	public function test_a_total_that_differs_stops_the_order_before_it_is_confirmed(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload( array( 'total' => 50000 ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'total', $result->error->context['field'] );
		$this->assertStringContainsString( '486.15 AED', $result->error->context['reason'], 'What Odoo worked out.' );
		$this->assertStringContainsString( '500.00 AED', $result->error->context['reason'], 'What the shop says.' );
		$this->assertSame( array(), $server->callsTo( 'sale.order', 'action_confirm' ) );
		$this->assertCount( 0, $server->db->all( 'sale.order' ), 'The quotation was removed.' );
	}

	public function test_a_rejected_quotation_that_cannot_be_removed_stays_a_draft_and_is_checked_again(): void {
		$server = $this->server( 18 );
		$server->db->deny( 'sale.order', 'unlink' );
		$payload = $this->orderPayload( array( 'total' => 50000 ) );

		$first = $this->send( $server, $payload );
		$this->assertSame( 'total', $first->error->context['field'] );
		$this->assertStringContainsString( 'delete it in Odoo', $first->error->context['reason'] );
		$this->assertCount( 1, $server->db->all( 'sale.order' ) );
		$this->assertSame( 'draft', $server->db->all( 'sale.order' )[0]['state'] );

		$second = $this->send( $server, $payload );
		$this->assertSame( 'total', $second->error->context['field'], 'The retry checks the quotation again instead of confirming it.' );
		$this->assertCount( 1, $server->db->all( 'sale.order' ), 'No second quotation.' );
		$this->assertSame( array(), $server->callsTo( 'sale.order', 'action_confirm' ) );

		$fixed = $this->send( $server, $this->orderPayload( array( 'total' => 48615 ) ) );
		$this->assertTrue( $fixed->isSuccess(), (string) $fixed->error?->message() );
		$this->assertSame( 'sale', $server->db->all( 'sale.order' )[0]['state'], 'Once the shop and Odoo agree the same quotation is confirmed.' );
		$this->assertCount( 1, $server->db->all( 'sale.order' ) );
	}

	public function test_with_the_warn_option_a_different_total_goes_on_with_a_warning_on_the_result(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload( array( 'total' => 50000 ) ), array( 'total_mismatch' => 'warn' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'sale', $result->ref->extra['state'] );
		$warning = $result->ref->extra['warnings'][0];
		$this->assertSame( 'total_mismatch', $warning['code'] );
		$this->assertSame( 50000, $warning['expected_minor'] );
		$this->assertSame( 48615, $warning['odoo_minor'] );
		$this->assertSame( 'AED', $warning['currency'] );
	}

	public function test_one_minor_unit_is_rounding_and_two_are_not(): void {
		$server = $this->server( 18 );
		$ok     = $this->send( $server, $this->orderPayload( array( 'total' => 48616 ) ) );
		$this->assertTrue( $ok->isSuccess(), (string) $ok->error?->message() );
		$this->assertArrayNotHasKey( 'warnings', $ok->ref->extra );

		$other = $this->server( 18 );
		$bad   = $this->send( $other, $this->orderPayload( array( 'total' => 48617 ) ) );
		$this->assertSame( 'total', $bad->error->context['field'] );
	}

	public function test_the_part_of_the_order_left_out_of_the_sync_is_not_expected_in_odoo(): void {
		$server = $this->server( 18 );
		$result = $this->send( $server, $this->orderPayload( array( 'total' => 59615, 'excluded_total' => 11000 ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	public function test_a_payload_without_a_total_is_not_compared(): void {
		$server = $this->server( 18 );
		$this->assertTrue( $this->send( $server, $this->orderPayload() )->isSuccess() );
	}

	public function test_a_three_decimal_currency_is_compared_in_fils(): void {
		$server  = $this->server( 18, array( 'currency' => 'KWD', 'country' => 'KW' ) );
		$payload = $this->orderPayload( array( 'currency' => 'KWD', 'total' => 48615 ) );
		$result  = $this->send( $server, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 48615, $result->ref->extra['total_minor'], '48.615 KWD: three decimals, so 48615 fils.' );
	}

	/* ------------------------------------------------------------ the order date */

	/**
	 * @dataProvider provide_majors
	 */
	public function test_the_shop_s_date_survives_the_confirmation( int $major ): void {
		$server         = $this->server( $major );
		$server->db->now = '2026-11-03 10:15:00';

		$result = $this->send( $server, $this->orderPayload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'sale', $server->db->all( 'sale.order' )[0]['state'] );
		$this->assertSame( '2026-11-02 07:40:00', $server->db->all( 'sale.order' )[0]['date_order'], 'Odoo set the date to the time of confirmation; the shop\'s date was written back.' );
	}

	public function test_a_later_pass_puts_the_date_back_when_the_first_one_could_not(): void {
		$server         = $this->server( 18 );
		$server->db->now = '2026-11-03 10:15:00';
		$server->failNext( \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::status( 503 ), '"write"' );
		$server->failNext( \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::status( 503 ), '"write"' );

		$first = $this->send( $server, $this->orderPayload() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( 'sale', $server->db->all( 'sale.order' )[0]['state'], 'Confirmed, but the date is still Odoo\'s.' );
		$this->assertSame( '2026-11-03 10:15:00', $server->db->all( 'sale.order' )[0]['date_order'] );

		$second = $this->send( $server, $this->orderPayload() );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertSame( '2026-11-02 07:40:00', $server->db->all( 'sale.order' )[0]['date_order'] );
		$this->assertCount( 1, $server->db->all( 'sale.order' ) );
	}

	/* ------------------------------------------------------------ the company */

	/**
	 * A database with a second company whose records have the same names as the first company's, created first so that they
	 * would win a search that does not name the company.
	 */
	private function twoCompanies( int $major ): FakeOdooServer {
		$server = new FakeOdooServer( $major, 'erp.example.com', 'testdb', 'sync@example.com', 'sk-test-0123456789abcdef' );
		$other  = $server->db->addCompany( 'Other Trading Co' );
		$server->db->addTax( 'VAT 5% (AE)', 5.0, 'sale', false, $other );
		$server->db->addPartner( 'Noor (other company)', 'noor@example.com', array( 'company_id' => $other ) );
		$foreign = $server->db->addProduct( 'CS-BOWL-02', 'Bowl set of the other company', 219.0, array( 1 => 14.0 ) );
		$server->db->records['product.product'][ $foreign ]['company_id'] = $other;

		$server->db->addTax( 'VAT 5% (AE)', 5.0 );
		$server->db->addTax( 'VAT 0% (AE)', 0.0 );
		$server->db->addProduct( 'CS-BOWL-02', 'Stoneware bowl set', 219.0, array( 1 => 14.0 ) );
		return $server;
	}

	/**
	 * @dataProvider provide_majors
	 */
	public function test_a_user_of_two_companies_resolves_only_the_records_of_the_company_in_use( int $major ): void {
		$server = $this->twoCompanies( $major );

		$result = $this->send( $server, $this->orderPayload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $server->db->all( 'sale.order.line' )[0];
		$this->assertSame( 2, $line['product_id'], 'The product of company 1 (id 2), not the other company\'s (id 1).' );
		$taxField = $major >= 19 ? 'tax_ids' : 'tax_id';
		$this->assertSame( array( 2 ), $line[ $taxField ], 'The tax of company 1 (id 2), not the other company\'s tax of the same name (id 1).' );
		$partners = $server->db->all( 'res.partner' );
		$this->assertCount( 2, $partners, 'The other company\'s customer was not used; a new one was made.' );
		$this->assertSame( $partners[1]['id'], $server->db->all( 'sale.order' )[0]['partner_id'] );
		$this->assertSame( 1, $server->db->all( 'sale.order' )[0]['company_id'] );
	}

	public function test_every_call_names_the_company_even_when_the_connection_does_not(): void {
		$server = $this->twoCompanies( 18 );
		$this->send( $server, $this->orderPayload() );

		$this->assertNotEmpty( $server->db->calls );
		foreach ( $server->db->calls as $call ) {
			if ( 'res.users' === $call['model'] ) {
				continue;
			}
			$this->assertSame( array( 1 ), $call['context']['allowed_company_ids'] ?? null, $call['model'] . '.' . $call['method'] );
		}
	}

	public function test_lookups_ask_for_records_of_the_company_or_of_no_company(): void {
		$server = $this->server( 18 );
		$this->send( $server, $this->orderPayload() );
		$expected = array( 'company_id', 'in', array( false, 1 ) );
		foreach ( array( 'product.product', 'account.tax', 'res.partner', 'product.pricelist' ) as $model ) {
			$searches = $server->callsTo( $model, 'search_read' );
			$this->assertNotEmpty( $searches, $model );
			$this->assertContains( $expected, $searches[0]['params']['domain'], $model );
		}
	}
}
