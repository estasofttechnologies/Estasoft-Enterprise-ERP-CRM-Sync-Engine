<?php
/**
 * Sending orders to Dynamics 365 Sales as deals.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\DynamicsSales;

use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeDynamicsSalesServer;

final class DynamicsSalesOrderTest extends DynamicsSalesTestCase {

	public function test_an_order_becomes_a_deal_with_a_contact_its_products_and_a_marker(): void {
		$result = $this->connector->send( $this->connection, $this->item( '1001' ), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$deals = $this->dv->all( 'opportunities' );
		$this->assertCount( 1, $deals );
		$this->assertSame( 'WC-1001 - Jane Doe', $deals[0]['name'] );
		$this->assertSame( 62.97, $deals[0]['estimatedvalue'] );
		$this->assertSame( '2026-11-01', $deals[0]['estimatedclosedate'] );
		$this->assertStringStartsWith( 'Gift wrap please', $deals[0]['description'] );
		$this->assertStringEndsWith( 'Estasoft Sync WC-1001 lines=1 email=jane.doe@example.test verified', $deals[0]['description'], 'The marker is sealed once the total was checked.' );
		$this->assertSame( $this->dv->all( 'transactioncurrencies' )[0]['transactioncurrencyid'], $deals[0]['_transactioncurrencyid_value'] );
		$contacts = $this->dv->all( 'contacts' );
		$this->assertCount( 1, $contacts );
		$this->assertSame( $contacts[0]['contactid'], $deals[0]['_customerid_value'] );
		$this->assertSame( 'Doe', $contacts[0]['lastname'] );
		$lines = $this->linesOfDeal();
		$this->assertCount( 1, $lines );
		$this->assertSame( 19.99, $lines[0]['priceperunit'] );
		$this->assertSame( 3, $lines[0]['quantity'] );
		$this->assertTrue( $lines[0]['ispriceoverridden'] );
		$this->assertSame( 3.0, $lines[0]['tax'] );
		$this->assertSame( FakeDynamicsSalesServer::UOM_ID, $lines[0]['_uomid_value'] );
		$this->assertSame( 6297, $result->ref->extra['total_minor'] );
		$this->assertSame( 'WC-1001 - Jane Doe', $result->ref->ref );
		$this->assertSame( 'open', $result->ref->extra['status'] );
	}

	public function test_the_same_order_twice_makes_one_deal(): void {
		$item = $this->item( '1002' );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$first  = $this->connector->send( $this->connection, $item, $this->payload() );
		$second = $this->connector->send( $this->connection, $item, $this->payload() );
		$found  = $this->connector->findExisting( $this->connection, $item );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $first->ref->id, $found->id );
		$this->assertCount( 1, $this->dv->all( 'opportunities' ) );
		$this->assertCount( 1, $this->dv->all( 'opportunityproducts' ) );
	}

	public function test_a_cut_off_pass_continues_without_duplicating_products(): void {
		$payload                  = $this->payload( array( 'total' => 6297 + 1000 ) );
		$payload['order_line'][]  = array( 'product' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => 1, 'price_unit' => 1000 );
		$item                     = $this->item( '1003' );
		$this->dv->failAfterWrites = array( 'opportunityproducts' => 1 );
		$first                    = $this->connector->send( $this->connection, $item, $payload );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ), 'Two products are expected and one is there.' );

		$second = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertCount( 2, $this->linesOfDeal() );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_a_lost_answer_to_the_deal_is_found_again(): void {
		$this->dv->loseAnswers = array( '/opportunities' );
		$item                  = $this->item( '1004' );
		$first                 = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertCount( 1, $this->dv->all( 'opportunities' ) );

		$second = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertTrue( $second->isSuccess() );
		$this->assertCount( 1, $this->dv->all( 'opportunities' ), 'No second deal.' );
		$this->assertCount( 1, $this->dv->all( 'contacts' ), 'No second contact.' );
	}

	public function test_a_prefix_never_matches_another_order(): void {
		$this->connector->send( $this->connection, $this->item( '12' ), $this->payload() );
		$this->assertNull( $this->connector->findExisting( $this->connection, $this->item( '123' ) ) );
		$this->assertCount( 1, $this->dv->all( 'opportunities' ) );
	}

	public function test_an_unknown_sku_stops_the_order_before_anything_is_created(): void {
		$payload                              = $this->payload();
		$payload['order_line'][0]['product'] = 'NO-SUCH';
		$result                               = $this->connector->send( $this->connection, $this->item( '1005' ), $payload );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 0, $this->dv->all( 'opportunities' ) );
		$this->assertCount( 0, $this->dv->all( 'contacts' ) );
	}

	public function test_the_write_in_policy_adds_a_free_text_line(): void {
		$connection                           = $this->connection( array( 'unknown_sku' => 'write_in' ) );
		$payload                              = $this->payload();
		$payload['order_line'][0]['product'] = 'NO-SUCH';
		$result                               = $this->connector->send( $connection, $this->item( '1006' ), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->linesOfDeal()[0];
		$this->assertTrue( $line['isproductoverridden'] );
		$this->assertSame( 'NO-SUCH Mug', $line['productdescription'] );
		$this->assertArrayNotHasKey( '_productid_value', $line );
	}

	public function test_the_contact_is_found_by_email_ignoring_case_and_not_made_twice(): void {
		$this->dv->addContact( 'JANE.doe@Example.test', 'Jane', 'Existing' );
		$this->connector->send( $this->connection, $this->item( '1007' ), $this->payload() );
		$this->connector->send( $this->connection, $this->item( '1008' ), $this->payload() );

		$this->assertCount( 1, $this->dv->all( 'contacts' ) );
		$this->assertSame( $this->dv->all( 'contacts' )[0]['contactid'], $this->dv->all( 'opportunities' )[1]['_customerid_value'] );
	}

	public function test_a_company_buyer_gets_an_account_and_the_deal_belongs_to_it(): void {
		$payload                            = $this->payload();
		$payload['partner']['company_name'] = 'Acme Trading LLC';
		$this->connector->send( $this->connection, $this->item( '1009' ), $payload );
		$this->connector->send( $this->connection, $this->item( '1010' ), $payload );

		$accounts = $this->dv->all( 'accounts' );
		$this->assertCount( 1, $accounts );
		$this->assertSame( 'Acme Trading LLC', $accounts[0]['name'] );
		$this->assertSame( $accounts[0]['accountid'], $this->dv->all( 'opportunities' )[0]['_customerid_value'] );
		$this->assertSame( $accounts[0]['accountid'], $this->dv->all( 'contacts' )[0]['_parentcustomerid_value'] );

		$no = $this->connection( array( 'create_accounts' => 'no' ) );
		$this->connector->send( $no, $this->item( '1011' ), $payload );
		$this->assertCount( 1, $this->dv->all( 'accounts' ), 'The option switches accounts off.' );
	}

	public function test_match_only_refuses_an_unknown_buyer_and_a_missing_email_is_map_003(): void {
		$only = $this->connection( array( 'customer_policy' => 'match_only' ) );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $only, $this->item( '1012' ), $this->payload() )->error->code );
		$this->assertCount( 0, $this->dv->all( 'contacts' ) );

		$payload = $this->payload();
		unset( $payload['partner']['email'] );
		$this->assertSame( ErrorCode::Map003, $this->connector->send( $this->connection, $this->item( '1013' ), $payload )->error->code );
	}

	public function test_names_in_arabic_come_back_unchanged(): void {
		$payload                       = $this->payload();
		$payload['partner']['first_name'] = 'نور';
		$payload['partner']['last_name']  = 'المنصوري';
		$payload['partner']['street']     = 'شارع الشيخ زايد، برج ٢٣';
		$this->connector->send( $this->connection, $this->item( '1014' ), $payload );

		$contact = $this->dv->all( 'contacts' )[0];
		$this->assertSame( 'نور المنصوري', $contact['fullname'] );
		$this->assertSame( 'شارع الشيخ زايد، برج ٢٣', $contact['address1_line1'] );
		$this->assertSame( 'WC-1014 - نور المنصوري', $this->dv->all( 'opportunities' )[0]['name'] );
	}

	public function test_a_currency_the_environment_lacks_or_keeps_too_few_decimals_for_is_refused(): void {
		$missing = $this->connector->send( $this->connection, $this->item( '1015' ), $this->payload( array( 'currency' => 'SAR' ) ) );
		$this->assertSame( ErrorCode::Val002, $missing->error->code );
		$this->assertStringContainsString( 'SAR', $missing->error->message() );

		$this->dv->data['transactioncurrencies'][1]['currencyprecision'] = 2;
		$short = $this->connector->send( $this->connection, $this->item( '1016' ), $this->payload( array( 'currency' => 'KWD', 'total' => 37035 ) ) );
		$this->assertSame( ErrorCode::Val002, $short->error->code );
		$this->assertStringContainsString( '3', $short->error->message() );
		$this->assertCount( 0, $this->dv->all( 'opportunities' ) );
	}

	public function test_an_organization_with_two_price_decimals_is_refused_for_a_three_decimal_currency_before_anything_is_written(): void {
		// The currency row keeps three places (the fake default), but PricePerUnit follows the organization's Pricing Decimal Precision.
		$this->assertSame( 3, $this->dv->all( 'transactioncurrencies' )[1]['currencyprecision'] );
		$payload                                = $this->payload( array( 'currency' => 'KWD', 'total' => 1234 ) );
		$payload['order_line'][0]['price_unit'] = 1234;
		$payload['order_line'][0]['quantity']   = 1;
		$payload['order_line'][0]['tax_amount'] = 0;
		$refused                                = $this->connector->send( $this->connection, $this->item( '1040' ), $payload );

		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertStringContainsString( 'Pricing Decimal Precision', $refused->error->message() );
		$this->assertStringContainsString( 'KWD', $refused->error->message() );
		$this->assertCount( 0, $this->dv->all( 'opportunities' ) );
		$this->assertCount( 0, $this->dv->all( 'contacts' ) );

		$this->dv->setPricingPrecision( 3 );
		$this->assertTrue( $this->connector->send( $this->connection, $this->item( '1040' ), $payload )->isSuccess() );
		$this->assertSame( 1.234, $this->linesOfDeal()[0]['priceperunit'] );

		// AED is fine on the default organization, and a precision of 0 is too low even for AED.
		$aed = $this->connector->send( $this->connection, $this->item( '1041' ), $this->payload() );
		$this->assertTrue( $aed->isSuccess(), (string) $aed->error?->message() );
		$this->dv->setPricingPrecision( 0 );
		$none = $this->connector->send( $this->connection, $this->item( '1042' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $none->error->code );
	}

	public function test_a_deal_that_failed_its_total_check_is_never_linked_by_a_retry_and_is_finished_when_it_passes(): void {
		$item   = $this->item( '1043' );
		$failed = $this->connector->send( $this->connection, $item, $this->payload( array( 'total' => 5997 ) ) );
		$this->assertSame( ErrorCode::Val002, $failed->error->code );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ), 'The marker is not verified, so the deal is not finished.' );
		$this->assertStringEndsNotWith( 'verified', $this->dv->all( 'opportunities' )[0]['description'] );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection, $item, $this->payload( array( 'total' => 5997 ) ) )->error->code );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$this->assertCount( 1, $this->dv->all( 'opportunities' ), 'No second deal, no second product.' );
		$this->assertCount( 1, $this->dv->all( 'opportunityproducts' ) );

		$fixed = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertTrue( $fixed->isSuccess(), (string) $fixed->error?->message() );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
		$this->assertCount( 1, $this->dv->all( 'opportunities' ) );
	}

	public function test_warn_mode_seals_a_deal_whose_total_differs(): void {
		$item = $this->item( '1044' );
		$this->assertTrue( $this->connector->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $item, $this->payload( array( 'total' => 5997 ) ) )->isSuccess() );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_a_marker_a_person_removed_is_written_again_by_the_next_send(): void {
		$item = $this->item( '1045' );
		$this->connector->send( $this->connection, $item, $this->payload() );
		$this->dv->data['opportunities'][0]['description'] = 'Gift wrap please';
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ), 'Without its marker the deal is unfinished.' );

		$again = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertTrue( $again->isSuccess(), (string) $again->error?->message() );
		$this->assertCount( 1, $this->dv->all( 'opportunities' ) );
		$this->assertCount( 1, $this->dv->all( 'opportunityproducts' ) );
		$this->assertSame( "Gift wrap please\nEstasoft Sync WC-1045 lines=1 email=jane.doe@example.test verified", $this->dv->all( 'opportunities' )[0]['description'] );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_three_decimal_currencies_keep_every_digit(): void {
		$this->dv->setPricingPrecision( 3 );
		$payload                             = $this->payload( array( 'currency' => 'KWD', 'total' => 37035 ) );
		$payload['order_line'][0]['price_unit'] = 12345;
		$payload['order_line'][0]['tax_amount'] = 0;
		$result                              = $this->connector->send( $this->connection, $this->item( '1017' ), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
		$this->assertSame( 12.345, $this->linesOfDeal()[0]['priceperunit'] );
	}

	public function test_a_total_that_differs_stops_the_order_and_a_rounding_unit_does_not(): void {
		$stopped = $this->connector->send( $this->connection, $this->item( '1018' ), $this->payload( array( 'total' => 5997 ) ) );
		$this->assertSame( ErrorCode::Val002, $stopped->error->code );
		$this->assertStringContainsString( '59.97 AED', $stopped->error->message() );
		$this->assertStringContainsString( '62.97 AED', $stopped->error->message() );

		$rounded = $this->connector->send( $this->connection, $this->item( '1019' ), $this->payload( array( 'total' => 6296 ) ) );
		$this->assertTrue( $rounded->isSuccess(), (string) $rounded->error?->message() );

		$warn   = $this->connection( array( 'total_mismatch' => 'warn' ) );
		$result = $this->connector->send( $warn, $this->item( '1020' ), $this->payload( array( 'total' => 5997 ) ) );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_shipping_and_fees_are_write_in_lines_and_the_discount_is_on_the_deal(): void {
		$payload             = $this->payload( array( 'total' => 6297 + 525 + 210 - 105 ) );
		$payload['shipping'] = array( 'total' => 500, 'name' => 'Courier', 'tax_amount' => 25 );
		$payload['fee']      = array( array( 'name' => 'Gift wrap', 'total' => 200, 'tax_amount' => 10 ) );
		$payload['discount'] = array( 'total' => 105 );
		$result              = $this->connector->send( $this->connection, $this->item( '1021' ), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->linesOfDeal();
		$this->assertCount( 3, $lines );
		$this->assertSame( 'Courier', $lines[1]['productdescription'] );
		$this->assertSame( 'Gift wrap', $lines[2]['productdescription'] );
		$this->assertSame( 1.05, $this->dv->all( 'opportunities' )[0]['discountamount'] );
		$this->assertStringContainsString( 'lines=3', $this->dv->all( 'opportunities' )[0]['description'] );
	}

	public function test_a_reference_column_is_written_and_used_to_find_the_deal(): void {
		$connection = $this->connection( array( 'reference_column' => 'new_wcorder' ) );
		$item       = $this->item( '1022' );
		$this->connector->send( $connection, $item, $this->payload() );
		$this->assertSame( 'WC-1022', $this->dv->all( 'opportunities' )[0]['new_wcorder'] );
		$this->dv->data['opportunities'][0]['name'] = 'Renamed by a person';
		$again = $this->connector->send( $connection, $item, $this->payload() );
		$this->assertTrue( $again->alreadyExisted, 'A renamed topic is still found.' );
		$this->assertCount( 1, $this->dv->all( 'opportunities' ) );
	}

	public function test_a_named_price_list_is_bound_and_a_missing_one_is_refused(): void {
		$missing = $this->connector->send( $this->connection( array( 'price_list' => 'Retail' ) ), $this->item( '1023' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $missing->error->code );
		$this->assertCount( 0, $this->dv->all( 'opportunities' ) );

		$this->dv->data['pricelevels'][] = array( 'pricelevelid' => 'dddddddd-0000-4000-8000-000000000001', 'name' => 'Retail' );
		$this->connector->send( $this->connection( array( 'price_list' => 'Retail' ) ), $this->item( '1023' ), $this->payload() );
		$this->assertSame( 'dddddddd-0000-4000-8000-000000000001', $this->dv->all( 'opportunities' )[0]['_pricelevelid_value'] );
	}

	public function test_a_missing_privilege_names_what_it_was_for(): void {
		$this->dv->denied = array( 'opportunities' => 'create' );
		$result           = $this->connector->send( $this->connection, $this->item( '1024' ), $this->payload() );

		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertSame( ErrorClass::NeedsPerson, $result->error->errorClass() );
		$this->assertStringContainsString( 'create opportunity', $result->error->message() );
	}

	public function test_a_payload_that_is_wrong_on_its_face_sends_nothing(): void {
		$payload                              = $this->payload();
		$payload['order_line'][0]['quantity'] = 0;
		$result                               = $this->connector->send( $this->connection, $this->item( '1025' ), $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_deal_changed_by_a_person_is_not_silently_continued(): void {
		$item = $this->item( '1026' );
		$this->connector->send( $this->connection, $item, $this->payload() );
		$this->dv->data['opportunityproducts'][0]['quantity'] = 4;
		$this->dv->data['opportunities'][0]['description']    = 'edited';
		$result = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}
}
