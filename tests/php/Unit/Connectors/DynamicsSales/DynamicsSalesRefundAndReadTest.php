<?php
/**
 * Refund notes, lookups and the nightly check for Dynamics 365 Sales.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\DynamicsSales;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class DynamicsSalesRefundAndReadTest extends DynamicsSalesTestCase {

	private function refund( array $override = array() ): array {
		return $override + array(
			'order_ref' => 'WC-3001',
			'amount'    => 2099,
			'reason'    => 'Chipped',
		);
	}

	public function test_a_refund_becomes_a_note_on_the_deal_with_a_marker(): void {
		$this->connector->send( $this->connection, $this->item( '3001' ), $this->payload() );
		$item   = $this->item( '3101', EntityType::Refund );
		$result = $this->connector->send( $this->connection, $item, $this->refund() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$notes = $this->dv->all( 'annotations' );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Refund WC-3101 of WC-3001', $notes[0]['subject'] );
		$this->assertSame( $this->dv->all( 'opportunities' )[0]['opportunityid'], $notes[0]['_objectid_value'] );
		$this->assertStringContainsString( '20.99 AED', $notes[0]['notetext'] );
		$this->assertStringContainsString( 'Reason: Chipped', $notes[0]['notetext'] );
		$this->assertStringContainsString( '[estasoft:refund WC-3101 minor=2099 AED]', $notes[0]['notetext'] );
		$this->assertSame( 2099, $result->ref->extra['refunded_minor'] );
		$this->assertSame( 62.97, $this->dv->all( 'opportunities' )[0]['estimatedvalue'], 'The deal value is not changed.' );
	}

	public function test_a_refund_sent_twice_makes_one_note(): void {
		$this->connector->send( $this->connection, $this->item( '3001' ), $this->payload() );
		$item = $this->item( '3102', EntityType::Refund );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$first  = $this->connector->send( $this->connection, $item, $this->refund() );
		$second = $this->connector->send( $this->connection, $item, $this->refund() );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->dv->all( 'annotations' ) );
		$this->assertSame( 2099, $this->connector->findExisting( $this->connection, $item )->extra['refunded_minor'] );
	}

	public function test_a_refund_of_an_order_without_a_deal_fails_before_a_note_is_made(): void {
		$result = $this->connector->send( $this->connection, $this->item( '3103', EntityType::Refund ), $this->refund( array( 'order_ref' => 'WC-9999' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'WC-9999', $result->error->message() );
		$this->assertCount( 0, $this->dv->all( 'annotations' ) );
	}

	public function test_a_refund_without_an_amount_or_order_is_refused_on_its_face(): void {
		foreach ( array( array( 'order_ref' => 'WC-1' ), array( 'order_ref' => 'X', 'amount' => 5 ), array( 'order_ref' => 'WC-1', 'amount' => 0 ) ) as $payload ) {
			$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection, $this->item( '3104', EntityType::Refund ), $payload )->error->code );
		}
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_contacts_and_products_are_found_without_regard_to_case(): void {
		$this->dv->addContact( 'Noor@Example.test', 'نور', 'المنصوري' );
		$ref = $this->connector->lookupCustomer( $this->connection, 'noor@EXAMPLE.test' );
		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( 'نور المنصوري', $ref->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, 'nobody@example.test' ) );

		$this->assertSame( 'CS-MUG-01', $this->connector->lookupProduct( $this->connection, 'cs-mug-01' )->ref );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, 'NO-SUCH' ) );
		$retired = $this->dv->addProduct( 'OLD-1', array( 'statecode' => 1 ) );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, $retired['productnumber'] ), 'A retired product cannot be sold.' );
	}

	public function test_product_search_looks_at_the_id_and_the_name(): void {
		$found = $this->connector->searchProducts( $this->connection, 'tee' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'CS-TEE-02', $found[0]->ref );
		$this->assertCount( 2, $this->connector->searchProducts( $this->connection, 'CS-' ) );
		$this->assertSame( array(), $this->connector->searchProducts( $this->connection, 'zzzz-no-match' ) );
	}

	public function test_the_nightly_check_lists_deals_with_values_email_and_refunds(): void {
		$this->connector->send( $this->connection, $this->item( '5001' ), $this->payload() );
		$this->connector->send( $this->connection, $this->item( '5002' ), $this->payload() );
		$this->connector->send( $this->connection, $this->item( '5101', EntityType::Refund ), $this->refund( array( 'order_ref' => 'WC-5001', 'amount' => 1000 ) ) );
		$this->connector->send( $this->connection, $this->item( '5102', EntityType::Refund ), $this->refund( array( 'order_ref' => 'WC-5001', 'amount' => 500 ) ) );

		$list = $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) );
		$this->assertSame( array( 'WC-5001', 'WC-5002' ), array_keys( $list ) );
		$this->assertSame( 6297, $list['WC-5001']->extra['total_minor'] );
		$this->assertSame( 1500, $list['WC-5001']->extra['refunded_minor'] );
		$this->assertSame( 0, $list['WC-5002']->extra['refunded_minor'] );
		$this->assertSame( 'jane.doe@example.test', $list['WC-5001']->extra['email'] );
		$this->assertSame( 'AED', $list['WC-5001']->extra['currency'] );
		$this->assertSame( array(), $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2027-01-01T00:00:00Z' ) ) );
	}

	public function test_lists_are_read_page_by_page(): void {
		$this->dv->odata->pageSize = 2;
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->dv->addContact( 'buyer' . $i . '@example.test', 'Buyer', (string) $i );
		}
		$this->assertInstanceOf( ExternalRef::class, $this->connector->lookupCustomer( $this->connection, 'buyer5@example.test' ) );
		for ( $i = 6001; $i <= 6005; $i++ ) {
			$this->assertTrue( $this->connector->send( $this->connection, $this->item( (string) $i ), $this->payload() )->isSuccess() );
		}
		$this->assertCount( 5, $this->connector->listForReconcile( $this->connection, new \DateTimeImmutable( '2026-10-01T00:00:00Z' ) ) );
	}

	public function test_a_contact_item_is_found_or_created(): void {
		$item    = $this->item( '8001', EntityType::Customer );
		$payload = array( 'partner' => array( 'email' => 'new@example.test', 'first_name' => 'New', 'last_name' => 'Buyer' ) );
		$one     = $this->connector->send( $this->connection, $item, $payload );
		$two     = $this->connector->send( $this->connection, $item, $payload );
		$this->assertFalse( $one->alreadyExisted );
		$this->assertTrue( $two->alreadyExisted );
		$this->assertCount( 1, $this->dv->all( 'contacts' ) );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection, $item, array( 'partner' => array( 'first_name' => 'No email' ) ) )->error->code );
	}

	public function test_the_connector_refuses_what_it_does_not_send(): void {
		$result = $this->connector->send( $this->connection, $this->item( '9', EntityType::Tracking ), array() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 0, $this->kit->http->count() );
	}
}
