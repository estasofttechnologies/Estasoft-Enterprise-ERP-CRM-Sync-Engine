<?php
/**
 * Orders, contacts, accounts, refunds and lookups of the SuiteCRM connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SuiteCrm;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;

/**
 * Everything above the wire, against the fake SuiteCRM.
 */
final class SuiteCrmOrdersTest extends SuiteCrmTestCase {

	public function test_an_order_becomes_an_opportunity_linked_to_its_contact_with_the_lines_as_text(): void {
		list( $item, $payload ) = $this->order( '1001' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$deals = $this->records( 'Opportunities' );
		$this->assertCount( 1, $deals );
		$this->assertSame( 'WC-1001 Layla Haddad', $deals[0]['name'] );
		$this->assertSame( '141.000000', $deals[0]['amount'] );
		$this->assertSame( '-99', $deals[0]['currency_id'], 'The default currency of the CRM.' );
		$this->assertSame( '2026-10-30', $deals[0]['date_closed'] );
		$this->assertSame( 'Closed Won', $deals[0]['sales_stage'], 'The order is paid.' );
		$this->assertSame( 100, $deals[0]['probability'] );
		$this->assertSame( 'Web Site', $deals[0]['lead_source'] );
		$this->assertStringStartsWith( "WC-1001\n", $deals[0]['description'], 'The reference alone on the first line.' );
		$this->assertStringContainsString( 'Tee (CS-TEE-02)', $deals[0]['description'] );
		$contacts = $this->records( 'Contacts' );
		$this->assertCount( 1, $contacts );
		$this->assertSame( 'layla@example.test', $contacts[0]['email1'] );
		$this->assertSame( 'Haddad', $contacts[0]['last_name'] );
		$this->assertSame( 'Layla', $contacts[0]['first_name'] );
		$this->assertSame( 'Amsterdam', $contacts[0]['primary_address_city'] );
		$this->assertSame( array(), $this->records( 'Accounts' ), 'No company, no account.' );
		$dealId = array_key_first( $this->server->modules['Opportunities'] );
		$this->assertSame( array( array_key_first( $this->server->modules['Contacts'] ) ), $this->server->links[ 'Opportunities|' . $dealId . '|contacts' ] );
		$this->assertSame( 14100, $result->ref->extra['total_minor'] );
	}

	public function test_an_unpaid_order_gets_the_new_stage_and_its_probability(): void {
		list( $item, $payload ) = $this->order( '1002', array( 'paid_at' => '' ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$deal = $this->records( 'Opportunities' )[0];
		$this->assertSame( 'Prospecting', $deal['sales_stage'] );
		$this->assertSame( 10, $deal['probability'] );
		list( $item2, $payload2 ) = $this->order( '1003', array( 'paid_at' => '' ) );
		$this->connector->send( $this->connection( $this->options( array( 'stage_new' => 'Negotiation/Review' ) ), array(), 2 ), $item2, $payload2 );
		$this->assertSame( 80, $this->records( 'Opportunities' )[1]['probability'] );
	}

	public function test_a_company_buyer_gets_an_account_found_by_name_the_second_time(): void {
		$connection = $this->connection();
		list( $a, $pa ) = $this->order( '1004', array( 'contact' => array( 'email' => 'a@example.test', 'first_name' => 'A', 'last_name' => 'One', 'company' => 'Haddad Trading' ) ) );
		list( $b, $pb ) = $this->order( '1005', array( 'contact' => array( 'email' => 'b@example.test', 'first_name' => 'B', 'last_name' => 'Two', 'company' => 'haddad trading' ) ) );
		$this->connector->send( $connection, $a, $pa );
		$this->connector->send( $connection, $b, $pb );
		$this->assertCount( 1, $this->records( 'Accounts' ), 'One account for the company.' );
		$this->assertCount( 2, $this->records( 'Contacts' ) );
		$accountId = array_key_first( $this->server->modules['Accounts'] );
		foreach ( array_keys( $this->server->modules['Opportunities'] ) as $dealId ) {
			$this->assertSame( array( $accountId ), $this->server->links[ 'Opportunities|' . $dealId . '|accounts' ] );
		}
	}

	public function test_accounts_can_be_switched_off(): void {
		list( $item, $payload ) = $this->order( '1006', array( 'contact' => array( 'email' => 'c@example.test', 'last_name' => 'Three', 'company' => 'Some Co' ) ) );
		$this->connector->send( $this->connection( $this->options( array( 'create_accounts' => 'no' ) ) ), $item, $payload );
		$this->assertSame( array(), $this->records( 'Accounts' ) );
	}

	public function test_the_second_send_finds_the_opportunity_and_makes_nothing_new(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '1007' );
		$first  = $this->connector->send( $connection, $item, $payload );
		$second = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->records( 'Opportunities' ) );
		$this->assertCount( 1, $this->records( 'Contacts' ) );
		$found = $this->connector->findExisting( $connection, $item );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $first->ref->id, $found->id );
	}

	public function test_a_reference_is_not_found_by_a_longer_number_that_starts_the_same_way(): void {
		$connection = $this->connection();
		list( $long, $payload ) = $this->order( '12' );
		$this->connector->send( $connection, $long, $payload );
		list( $short ) = $this->order( '1' );
		$this->assertNull( $this->connector->findExisting( $connection, $short ), 'WC-1 must not match WC-12 Layla Haddad.' );
	}

	public function test_an_opportunity_whose_contact_link_is_missing_is_not_finished_and_send_completes_it(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '1008' );
		$this->server->add( 'Opportunities', array( 'name' => 'WC-1008 Layla Haddad', 'amount' => '141.000000', 'currency_id' => '-99', 'sales_stage' => 'Closed Won', 'date_closed' => '2026-10-30' ) );
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'Created but never linked: not finished.' );
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertCount( 1, $this->records( 'Opportunities' ), 'No second opportunity.' );
		$this->assertCount( 1, $this->records( 'Contacts' ) );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $connection, $item ) );
		$this->connector->send( $connection, $item, $payload );
		$dealId = array_key_first( $this->server->modules['Opportunities'] );
		$this->assertCount( 1, $this->server->links[ 'Opportunities|' . $dealId . '|contacts' ], 'The link is made once.' );
	}

	public function test_a_contact_is_found_by_email_in_any_letter_case_and_the_oldest_wins(): void {
		$old = $this->server->add( 'Contacts', array( 'last_name' => 'Old', 'email1' => 'Layla@Example.test' ) );
		$this->server->add( 'Contacts', array( 'last_name' => 'Newer', 'email1' => 'layla@example.test' ) );
		list( $item, $payload ) = $this->order( '1009' );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$this->assertCount( 2, $this->records( 'Contacts' ) );
		$dealId = array_key_first( $this->server->modules['Opportunities'] );
		$this->assertSame( array( $old ), $this->server->links[ 'Opportunities|' . $dealId . '|contacts' ] );
	}

	public function test_a_contact_on_a_later_page_is_found(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->server->add( 'Contacts', array( 'last_name' => 'Other' . $i, 'email1' => 'other' . $i . '@example.test' ) );
		}
		$wanted = $this->server->add( 'Contacts', array( 'last_name' => 'Haddad', 'email1' => 'layla@example.test' ) );
		$ref    = $this->connector->lookupCustomer( $this->connection(), 'layla@example.test' );
		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( $wanted, $ref->id );
		$this->assertCount( 0, $this->logged( '#^GET .*module/Contacts$#' ), 'Every list names a page size.' );
	}

	public function test_every_list_request_names_a_page_size_because_without_one_suitecrm_returns_every_record(): void {
		list( $item, $payload ) = $this->order( '1010' );
		$this->connector->send( $this->connection(), $item, $payload );
		foreach ( $this->server->log as $line ) {
			if ( 1 === preg_match( '#^GET /legacy/Api/V8/module/[A-Za-z_]+\?#', $line ) ) {
				$this->assertStringContainsString( 'page[size]=50', $line, $line );
			}
		}
	}

	public function test_a_currency_suitecrm_does_not_know_stops_the_order_before_anything_is_created(): void {
		list( $item, $payload ) = $this->order( '1011', array( 'currency' => 'AED', 'total' => 14100 ) );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'currency', $result->error->context['field'] );
		$this->assertStringContainsString( 'AED', $result->error->context['reason'] );
		$this->assertSame( array(), $this->records( 'Opportunities' ) );
		$this->assertSame( array(), $this->records( 'Contacts' ) );
	}

	public function test_a_currency_defined_in_suitecrm_is_used_by_its_id(): void {
		$id = $this->server->add( 'Currencies', array( 'iso4217' => 'AED', 'status' => 'Active', 'name' => 'Dirham' ) );
		list( $item, $payload ) = $this->order( '1012', array( 'currency' => 'AED' ) );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$this->assertSame( $id, $this->records( 'Opportunities' )[0]['currency_id'] );
	}

	public function test_an_amount_that_suitecrm_records_differently_fails_loudly_leaves_the_opportunity_unlinked_and_the_retry_makes_no_second(): void {
		$this->server->amountFactor = 1.1;
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '1013' );
		$first = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( ErrorCode::Val002, $first->error->code );
		$this->assertSame( 'total', $first->error->context['field'] );
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'Never linked unchecked.' );
		$again = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( ErrorCode::Val002, $again->error->code );
		$this->assertCount( 1, $this->records( 'Opportunities' ) );
	}

	public function test_a_difference_is_accepted_with_a_warning_when_the_option_says_warn(): void {
		$this->server->amountFactor = 1.1;
		list( $item, $payload ) = $this->order( '1014' );
		$result = $this->connector->send( $this->connection( $this->options( array( 'total_mismatch' => 'warn' ) ) ), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_products_are_checked_only_when_the_option_asks_and_a_free_text_line_is_allowed_on_request(): void {
		$this->server->add( 'AOS_Products', array( 'name' => 'Mug', 'part_number' => 'CS-MUG-01' ) );
		list( $item, $payload ) = $this->order( '1015' );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess(), 'No check by default.' );
		list( $item2, $payload2 ) = $this->order( '1016' );
		$refused = $this->connector->send( $this->connection( $this->options( array( 'match_products' => 'yes' ) ), array(), 2 ), $item2, $payload2 );
		$this->assertSame( ErrorCode::Map001, $refused->error->code );
		$this->assertSame( 'CS-TEE-02', $refused->error->context['sku'] );
		$this->assertCount( 1, $this->records( 'Opportunities' ), 'Nothing was made for the refused order.' );
		$allowed = $this->connector->send( $this->connection( $this->options( array( 'match_products' => 'yes', 'unknown_sku' => 'free_text' ) ), array(), 3 ), $item2, $payload2 );
		$this->assertTrue( $allowed->isSuccess() );
	}

	public function test_a_missing_email_is_map_003_and_match_only_refuses_an_unknown_buyer(): void {
		list( $item, $payload ) = $this->order( '1017', array( 'contact' => array( 'email' => '', 'last_name' => 'X' ) ) );
		$this->assertSame( ErrorCode::Map003, $this->connector->send( $this->connection(), $item, $payload )->error->code );
		list( $item2, $payload2 ) = $this->order( '1018' );
		$result = $this->connector->send( $this->connection( $this->options( array( 'customer_policy' => 'match_only' ) ), array(), 2 ), $item2, $payload2 );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->records( 'Opportunities' ) );
	}

	public function test_a_long_name_is_cut_to_fifty_characters_with_the_reference_kept_whole(): void {
		list( $item, $payload ) = $this->order( '123456', array( 'contact' => array( 'email' => 'long@example.test', 'first_name' => str_repeat( 'Long', 20 ), 'last_name' => 'Name' ) ) );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$name = $this->records( 'Opportunities' )[0]['name'];
		$this->assertSame( 50, mb_strlen( $name ) );
		$this->assertStringStartsWith( 'WC-123456 ', $name );
		$this->assertStringEndsWith( "\u{2026}", $name );
		$this->assertNotNull( $this->connector->findExisting( $this->connection(), $item ) );
	}

	public function test_arabic_text_round_trips_in_the_contact_and_the_description(): void {
		$name = 'المنصوري';
		list( $item, $payload ) = $this->order( '1019', array( 'contact' => array( 'email' => 'noor@example.test', 'first_name' => 'نور', 'last_name' => $name, 'street' => 'شارع الشيخ زايد، برج ٢٣' ) ) );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$ref = $this->connector->lookupCustomer( $this->connection(), 'noor@example.test' );
		$this->assertSame( 'نور ' . $name, $ref->ref );
		$this->assertSame( 'شارع الشيخ زايد، برج ٢٣', $this->records( 'Contacts' )[0]['primary_address_street'] );
		$this->assertStringContainsString( 'نور المنصوري', $this->records( 'Opportunities' )[0]['description'] );
	}

	public function test_a_three_decimal_currency_keeps_its_exponent(): void {
		$this->server->add( 'Currencies', array( 'iso4217' => 'KWD', 'status' => 'Active', 'name' => 'Dinar' ) );
		list( $item, $payload ) = $this->order( '1020', array( 'currency' => 'KWD', 'total' => 37035, 'lines' => array( array( 'sku' => 'X', 'name' => 'Mug', 'quantity' => '3', 'unit_price' => 12345, 'total' => 37035, 'tax' => 0 ) ) ) );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( '37.035000', $this->records( 'Opportunities' )[0]['amount'] );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
	}

	public function test_a_customer_item_finds_or_creates_the_contact(): void {
		$connection = $this->connection();
		$item       = new Item( 5, 1, EntityType::Customer, '5', 'created', 'customer:5', 'c-5', ItemState::Syncing );
		$payload    = array( 'contact' => array( 'email' => 'sara@example.test', 'first_name' => 'Sara', 'last_name' => 'Ali' ) );
		$first      = $this->connector->send( $connection, $item, $payload );
		$second     = $this->connector->send( $connection, $item, $payload );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->records( 'Contacts' ) );
	}

	public function test_a_dedicated_reference_field_finds_the_order_even_when_somebody_renames_the_opportunity(): void {
		$this->server->customFields['Opportunities'] = array( 'wc_reference_c' );
		$connection = $this->connection( $this->options( array( 'reference_field' => 'wc_reference_c' ) ) );
		list( $item, $payload ) = $this->order( '6001' );
		$first = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$this->assertSame( 'WC-6001', $this->records( 'Opportunities' )[0]['wc_reference_c'] );
		$dealId = array_key_first( $this->server->modules['Opportunities'] );
		$this->server->modules['Opportunities'][ $dealId ]['attributes']['name'] = 'Big autumn deal (renamed by sales)';
		$again = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->records( 'Opportunities' ), 'No second opportunity after the rename.' );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $connection, $item ) );
		list( $other ) = $this->order( '600' );
		$this->assertNull( $this->connector->findExisting( $connection, $other ), 'WC-600 is not WC-6001.' );
	}

	public function test_a_reference_field_that_is_not_a_field_name_or_does_not_exist_stops_the_order_before_anything_is_made(): void {
		list( $item, $payload ) = $this->order( '6002' );
		$bad = $this->connector->send( $this->connection( $this->options( array( 'reference_field' => 'wc ref; drop' ) ) ), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $bad->error->code );
		$this->assertSame( 'reference_field', $bad->error->context['field'] );
		$missing = $this->connector->send( $this->connection( $this->options( array( 'reference_field' => 'no_such_field_c' ) ), array(), 2 ), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $missing->error->code );
		$this->assertSame( array(), $this->records( 'Opportunities' ) );
		$this->assertSame( array(), $this->records( 'Contacts' ) );
	}

	public function test_a_refund_becomes_a_note_on_the_opportunity_and_is_made_once(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3001' );
		$sent = $this->connector->send( $connection, $item, $payload );
		list( $refund, $refundPayload ) = $this->refund( '3002', '3001' );
		$result = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$note = $this->records( 'Notes' )[0];
		$this->assertSame( 'Refund WC-3002 20.00 EUR', $note['name'] );
		$this->assertSame( 'Opportunities', $note['parent_type'] );
		$this->assertSame( $sent->ref->id, $note['parent_id'] );
		$this->assertStringStartsWith( 'WC-3002', $note['description'] );
		$this->assertStringContainsString( 'Damaged', $note['description'] );
		$again = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->records( 'Notes' ) );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $connection, $refund ) );
		$this->assertSame( '141.000000', $this->records( 'Opportunities' )[0]['amount'], 'The amount of the deal is not changed.' );
	}

	public function test_a_refund_note_is_not_found_by_a_longer_number_and_a_refund_without_its_order_fails_first(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3003' );
		$this->connector->send( $connection, $item, $payload );
		list( $long, $longPayload ) = $this->refund( '77', '3003' );
		$this->connector->send( $connection, $long, $longPayload );
		list( $short ) = $this->refund( '7', '3003' );
		$this->assertNull( $this->connector->findExisting( $connection, $short ), 'Refund WC-7 must not match Refund WC-77.' );
		list( $orphan, $orphanPayload ) = $this->refund( '3005', '3004' );
		$result = $this->connector->send( $connection, $orphan, $orphanPayload );
		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertCount( 1, $this->records( 'Notes' ) );
	}

	public function test_products_are_looked_up_by_part_number_and_searched_by_name(): void {
		$this->server->add( 'AOS_Products', array( 'name' => 'Coffee mug', 'part_number' => 'CS-MUG-01' ) );
		$this->server->add( 'AOS_Products', array( 'name' => 'Tea', 'part_number' => 'CS-TEE-02' ) );
		$connection = $this->connection();
		$found      = $this->connector->lookupProduct( $connection, 'cs-mug-01' );
		$this->assertSame( 'CS-MUG-01', $found->ref );
		$this->assertSame( 'Coffee mug', $found->extra['name'] );
		$this->assertNull( $this->connector->lookupProduct( $connection, 'MUG' ) );
		$this->assertSame( array( 'CS-MUG-01' ), array_map( static fn( ExternalRef $r ) => $r->ref, $this->connector->searchProducts( $connection, 'mug' ) ) );
	}

	public function test_the_nightly_list_holds_the_shop_s_opportunities_from_the_day_on(): void {
		$connection = $this->connection();
		foreach ( array( '5001', '5002' ) as $id ) {
			list( $item, $payload ) = $this->order( $id );
			$this->connector->send( $connection, $item, $payload );
		}
		$this->server->add( 'Opportunities', array( 'name' => 'Manual deal', 'amount' => '5.000000', 'currency_id' => '-99' ) );
		$list = $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2020-01-01T00:00:00Z' ) );
		$this->assertSame( array( 'WC-5001', 'WC-5002' ), array_keys( $list ) );
		$this->assertSame( 14100, $list['WC-5001']->extra['total_minor'] );
		$this->assertSame( 0, $list['WC-5001']->extra['refunded_minor'] );
		$this->assertSame( 'EUR', $list['WC-5001']->extra['currency'] );
		$this->assertSame( array(), $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2100-01-01T00:00:00Z' ) ) );
	}

	public function test_the_nightly_list_adds_up_the_refund_notes_of_each_opportunity_once_per_refund(): void {
		$connection = $this->connection();
		foreach ( array( '5101', '5102' ) as $id ) {
			list( $item, $payload ) = $this->order( $id );
			$this->connector->send( $connection, $item, $payload );
		}
		list( $first, $firstPayload ) = $this->refund( '5201', '5101', array( 'amount' => 2000 ) );
		list( $second, $secondPayload ) = $this->refund( '5202', '5101', array( 'amount' => 550 ) );
		$this->connector->send( $connection, $first, $firstPayload );
		$this->connector->send( $connection, $second, $secondPayload );
		$dealId = array_key_first( $this->server->modules['Opportunities'] );
		$this->server->add( 'Notes', array( 'name' => 'Refund WC-5201 20.00 EUR', 'parent_type' => 'Opportunities', 'parent_id' => $dealId ) );
		$this->server->add( 'Notes', array( 'name' => 'Refund WC-5299 99.00 EUR', 'parent_type' => 'Contacts', 'parent_id' => $dealId ) );
		$this->server->add( 'Notes', array( 'name' => 'Refund WC-5298 by phone', 'parent_type' => 'Opportunities', 'parent_id' => $dealId ) );
		$list = $this->connector->listForReconcile( $connection, new \DateTimeImmutable( '2020-01-01T00:00:00Z' ) );
		$this->assertSame( 2550, $list['WC-5101']->extra['refunded_minor'], 'Twice 20.00 for one refund and notes of another shape do not count.' );
		$this->assertSame( 0, $list['WC-5102']->extra['refunded_minor'] );
		$found = $this->connector->findExisting( $connection, $this->order( '5102' )[0] );
		$this->assertSame( 14100, $found->extra['total_minor'] );
		$this->assertSame( 'EUR', $found->extra['currency'] );
	}

	public function test_the_default_mapping_is_the_one_every_crm_shares_and_targets_known_fields(): void {
		foreach ( array( 'orders', 'customers', 'products', 'refunds' ) as $type ) {
			$paths = array_map( static fn( array $f ): string => (string) preg_replace( '/\[\d*\]/', '', $f['path'] ), $this->connector->describeFields( $type ) );
			foreach ( $this->connector->defaultMapping( $type ) as $rule ) {
				$this->assertContains( (string) preg_replace( '/\[\d*\]/', '', (string) $rule['target'] ), $paths, $type . ': ' . $rule['id'] );
			}
		}
		$this->assertSame( array(), $this->connector->describeFields( 'stock' ) );
	}

	public function test_no_taxes_are_offered(): void {
		$this->assertSame( array(), $this->connector->listTaxes( $this->connection() ) );
	}
}
