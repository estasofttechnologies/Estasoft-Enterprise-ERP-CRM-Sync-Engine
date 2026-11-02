<?php
/**
 * The helpers the four enterprise connectors share: the store-zone date of the default rules and the labelled shop total marker.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\EnterpriseCommon;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\DefaultRules;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\ShopTotalMarker;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\Transforms\DateUtc;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;
use PHPUnit\Framework\TestCase;

final class EnterpriseCommonTest extends TestCase {

	/**
	 * The order date rule of the default rules, run for a store in a time zone.
	 *
	 * @param string $createdAt ISO date of the order (UTC).
	 * @param string $zone      Store time zone.
	 */
	private function orderDate( string $createdAt, string $zone ): string {
		$rules  = array_values( array_filter( ( new DefaultRules( 'none', false, false, false ) )->rules( 'orders' ), static fn( array $rule ): bool => 'order-date' === $rule['id'] ) );
		$engine = new RuleEngine( new TransformRegistry( array( new DateUtc( static fn(): \DateTimeZone => new \DateTimeZone( $zone ) ) ) ) );
		$output = $engine->apply( $rules, new CanonicalOrder( Orders::dubai( array( 'order' => array( 'created_at' => $createdAt ) ) ) ), new MappingEnvironment( 1, 'Test' ) );
		$this->assertTrue( $output->isValid() );
		return (string) $output->payload['order_date'];
	}

	public function test_the_order_date_is_the_shops_own_date_not_the_utc_date(): void {
		$this->assertSame( '2026-11-01 01:30:00', $this->orderDate( '2026-10-31T21:30:00Z', 'Asia/Dubai' ), 'A Gulf shop at UTC+4 is already in November.' );
		$this->assertSame( '2026-10-31 21:30:00', $this->orderDate( '2026-10-31T21:30:00Z', 'UTC' ) );
		$this->assertSame( '2026-10-31 17:30:00', $this->orderDate( '2026-10-31T21:30:00Z', 'America/New_York' ), 'A shop west of UTC keeps its earlier date.' );
	}

	public function test_the_refund_date_rule_uses_the_store_zone_too(): void {
		foreach ( ( new DefaultRules( 'none', false, false, false ) )->rules( 'refunds' ) as $rule ) {
			if ( 'refund-date' === $rule['id'] ) {
				$this->assertSame( 'store', $rule['transforms'][0]['args']['output_timezone'] );
			}
		}
		foreach ( ( new DefaultRules( 'none', false, false, false ) )->rules( 'orders' ) as $rule ) {
			if ( 'order-date' === $rule['id'] ) {
				$this->assertSame( 'store', $rule['transforms'][0]['args']['output_timezone'] );
			}
		}
	}

	public function test_the_marker_label_can_say_net_and_each_label_reads_only_its_own_line(): void {
		$amounts = new Amounts();
		$total   = new ShopTotalMarker( $amounts );
		$net     = new ShopTotalMarker( $amounts, 'Shop net' );
		$this->assertSame( "Gift\nShop net 63.00 AED", $net->append( 'Gift', 6300, 'AED' ) );
		$this->assertSame( array( 'minor' => 6300, 'currency' => 'AED' ), $net->read( "Gift\nShop net 63.00 AED" ) );
		$this->assertNull( $net->read( "Gift\nShop total 63.00 AED" ), 'A net reader does not take a total line.' );
		$this->assertNull( $total->read( "Gift\nShop net 63.00 AED" ) );
		$this->assertSame( array( 'minor' => 59970, 'currency' => 'KWD' ), $total->read( 'Shop total 59.970 KWD' ) );
	}
}
