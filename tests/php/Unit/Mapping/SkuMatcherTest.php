<?php
/**
 * SKU matching (MAP-1 of AS-08): overrides, stored matches, lookups, unmatched and SKU-less products.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\LookupFailedException;
use Estasoft\SyncEngine\Mapping\ReadOnlyProductResolver;
use Estasoft\SyncEngine\Mapping\SkuMatcher;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\ArrayConnections;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\FakeConnector;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\InMemoryIdMap;
use PHPUnit\Framework\TestCase;

final class SkuMatcherTest extends TestCase {

	private InMemoryIdMap $idMap;
	private FakeConnector $connector;
	private SkuMatcher $matcher;

	/**
	 * Verdicts reported to the listener.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $verdicts = array();

	protected function setUp(): void {
		$this->idMap     = new InMemoryIdMap();
		$this->connector = new FakeConnector();
		$this->connector->products['CS-BOWL-02'] = new ExternalRef( '77', 'CS-BOWL-02' );
		$this->verdicts = array();
		$this->matcher  = new SkuMatcher(
			$this->idMap,
			new ArrayConnections(),
			new ConnectorRegistry( array( $this->connector ) ),
			function ( int $connectionId, int $productId, string $sku, ?ExternalRef $found ): void {
				$this->verdicts[] = array( $connectionId, $productId, $sku, null !== $found );
			}
		);
	}

	public function test_a_sku_the_system_knows_is_matched_and_remembered(): void {
		$found = $this->matcher->resolve( 1, ' CS-BOWL-02 ', 501 );
		$this->assertSame( '77', $found?->id );
		$this->assertSame( array( 'lookupProduct:CS-BOWL-02' ), $this->connector->calls );
		$this->assertSame( '77', $this->idMap->get( 1, 'product', '501' )['external_id'] );
		$this->assertFalse( $this->idMap->get( 1, 'product', '501' )['is_override'] );
		$this->assertSame( array( array( 1, 501, 'CS-BOWL-02', true ) ), $this->verdicts );
	}

	public function test_a_remembered_match_is_used_without_asking_the_system_again(): void {
		$this->matcher->resolve( 1, 'CS-BOWL-02', 501 );
		$this->connector->calls = array();
		$again                  = $this->matcher->resolve( 1, 'cs-bowl-02', 501 );
		$this->assertSame( '77', $again?->id );
		$this->assertSame( array(), $this->connector->calls );
	}

	public function test_a_changed_sku_is_looked_up_again(): void {
		$this->matcher->resolve( 1, 'CS-BOWL-02', 501 );
		$this->connector->products['CS-BOWL-03'] = new ExternalRef( '78', 'CS-BOWL-03' );
		$this->connector->calls                  = array();
		$this->assertSame( '78', $this->matcher->resolve( 1, 'CS-BOWL-03', 501 )?->id );
		$this->assertSame( array( 'lookupProduct:CS-BOWL-03' ), $this->connector->calls );
	}

	public function test_a_match_made_by_hand_wins_over_everything(): void {
		$this->matcher->setOverride( 1, 501, '99', 'CS-BOWL-04L', 'CS-BOWL-02' );
		$found = $this->matcher->resolve( 1, 'CS-BOWL-02', 501 );
		$this->assertSame( '99', $found?->id );
		$this->assertSame( 'CS-BOWL-04L', $found?->ref );
		$this->assertSame( array(), $this->connector->calls, 'The system is not asked when a person chose.' );
	}

	public function test_an_override_applies_even_when_the_sku_is_not_in_the_system(): void {
		$this->matcher->setOverride( 1, 502, '55', 'REF-55' );
		$this->assertSame( '55', $this->matcher->resolve( 1, 'CS-BOWL-04', 502 )?->id );
	}

	public function test_a_variation_override_wins_over_its_parent(): void {
		$this->matcher->setOverride( 1, 501, '99' );
		$this->matcher->setOverride( 1, 601, '100' );
		$this->assertSame( '100', $this->matcher->resolve( 1, 'X', 501, 601 )?->id );
		$this->assertSame( '99', $this->matcher->resolve( 1, 'X', 501, 0 )?->id );
	}

	public function test_a_lookup_never_replaces_an_override(): void {
		$this->matcher->setOverride( 1, 501, '99' );
		$this->matcher->resolve( 1, 'CS-BOWL-02', 501 );
		$this->assertSame( '99', $this->idMap->get( 1, 'product', '501' )['external_id'] );
		$this->assertTrue( $this->idMap->get( 1, 'product', '501' )['is_override'] );
	}

	public function test_clearing_an_override_goes_back_to_looking_up(): void {
		$this->matcher->setOverride( 1, 501, '99' );
		$this->matcher->setOverride( 1, 501, null );
		$this->assertSame( '77', $this->matcher->resolve( 1, 'CS-BOWL-02', 501 )?->id );
	}

	public function test_an_unmatched_sku_gives_null_and_a_negative_verdict(): void {
		$this->assertNull( $this->matcher->resolve( 1, 'CS-LAMP-07', 503 ) );
		$this->assertSame( array( array( 1, 503, 'CS-LAMP-07', false ) ), $this->verdicts );
		$this->assertNull( $this->idMap->get( 1, 'product', '503' ) );
		$this->assertSame( 0, $this->idMap->writes, 'Nothing is written for a product the system does not have.' );
	}

	public function test_a_product_without_a_sku_is_not_looked_up(): void {
		$this->assertNull( $this->matcher->resolve( 1, '   ', 504 ) );
		$this->assertSame( array(), $this->connector->calls );
		$this->assertSame( array( array( 1, 504, '', false ) ), $this->verdicts );
	}

	public function test_a_failed_lookup_is_not_an_unmatched_product(): void {
		$this->connector->lookupError = new ConnectorError( ErrorCode::Net001, array( 'system' => 'Fake' ) );
		try {
			$this->matcher->resolve( 1, 'CS-BOWL-02', 501 );
			$this->fail( 'A lookup failure must reach the queue so the item is retried.' );
		} catch ( LookupFailedException $e ) {
			$this->assertSame( ErrorCode::Net001, $e->error->code );
		}
		$this->assertSame( array(), $this->verdicts );
	}

	public function test_a_connection_without_a_connector_finds_nothing(): void {
		$matcher = new SkuMatcher( $this->idMap, new ArrayConnections(), new ConnectorRegistry( array() ) );
		$this->assertNull( $matcher->resolve( 1, 'CS-BOWL-02', 501 ) );
		$this->assertNull( ( new SkuMatcher( $this->idMap, new ArrayConnections(), new ConnectorRegistry( array( $this->connector ) ) ) )->resolve( 9, 'CS-BOWL-02', 501 ) );
	}

	public function test_products_sharing_a_sku_share_the_match(): void {
		$this->matcher->resolve( 1, 'CS-BOWL-02', 501 );
		$this->connector->calls = array();
		$this->assertSame( '77', $this->matcher->resolve( 1, 'CS-BOWL-02', 999 )?->id );
		$this->assertSame( array(), $this->connector->calls );
	}

	public function test_a_hand_made_match_for_another_product_is_not_used_for_this_sku(): void {
		// A person mapped product 900 (which also carries the SKU in the stored list) to a system product by hand.
		$this->matcher->setOverride( 1, 900, '500', 'OTHER', 'CS-BOWL-02' );
		$this->connector->calls = array();

		$found = $this->matcher->resolve( 1, 'CS-BOWL-02', 501 );
		$this->assertSame( '77', $found?->id, 'Product 501 is not product 900: its own lookup decides.' );
		$this->assertSame( array( 'lookupProduct:CS-BOWL-02' ), $this->connector->calls );
		$this->assertSame( '500', $this->idMap->get( 1, 'product', '900' )['external_id'], 'The hand-made match of product 900 is left alone.' );
		$this->assertTrue( $this->idMap->get( 1, 'product', '900' )['is_override'] );
	}

	public function test_a_hand_made_match_still_applies_to_its_own_product_and_variation(): void {
		$this->matcher->setOverride( 1, 900, '500', 'OTHER', 'CS-BOWL-02' );
		$this->assertSame( '500', $this->matcher->resolve( 1, 'CS-BOWL-02', 900 )?->id );
		$this->assertSame( '500', $this->matcher->resolve( 1, 'CS-BOWL-02', 12, 900 )?->id, 'As the line variation.' );
		$this->assertSame( '500', $this->matcher->resolve( 1, 'CS-BOWL-02', 900, 12 )?->id, 'As the line product, when the variation has no match of its own.' );
	}

	public function test_a_stored_match_for_the_same_sku_on_another_id_is_still_used(): void {
		$this->idMap->link( 1, 'product', '300', '88', 'CS-BOWL-02', null, 'CS-BOWL-02', false );
		$this->connector->calls = array();
		$this->assertSame( '88', $this->matcher->resolve( 1, 'CS-BOWL-02', 501 )?->id, 'The product was re-created with a new id; the SKU still matches.' );
		$this->assertSame( array(), $this->connector->calls );
	}

	public function test_the_match_of_this_very_product_is_tried_before_a_search_by_sku(): void {
		$this->idMap->link( 1, 'product', '300', '88', 'CS-BOWL-02', null, 'CS-BOWL-02', false );
		$this->idMap->link( 1, 'product', '501', '99', 'CS-BOWL-02', null, 'CS-BOWL-02', false );
		$this->assertSame( '99', $this->matcher->resolve( 1, 'CS-BOWL-02', 501 )?->id );
	}

	public function test_status_reads_only_what_is_stored(): void {
		$this->assertSame( 'unmatched', $this->matcher->status( 1, 501, 'CS-BOWL-02' ) );
		$this->assertSame( 'no_sku', $this->matcher->status( 1, 501, ' ' ) );
		$this->matcher->resolve( 1, 'CS-BOWL-02', 501 );
		$this->assertSame( 'matched', $this->matcher->status( 1, 501, 'cs-bowl-02' ) );
		$this->assertSame( 'unmatched', $this->matcher->status( 1, 501, 'SOMETHING-ELSE' ) );
		$this->matcher->setOverride( 1, 501, '99' );
		$this->assertSame( 'override', $this->matcher->status( 1, 501, 'CS-BOWL-02' ) );
	}

	public function test_peek_writes_nothing_and_asks_only_when_told_to(): void {
		$this->assertNull( $this->matcher->peek( 1, 'CS-BOWL-02', 501 ) );
		$this->assertSame( array(), $this->connector->calls );

		$this->assertSame( '77', $this->matcher->peek( 1, 'CS-BOWL-02', 501, 0, true )?->id );
		$this->assertSame( array( 'lookupProduct:CS-BOWL-02' ), $this->connector->calls );
		$this->assertSame( 0, $this->idMap->writes );
		$this->assertSame( array(), $this->verdicts );
	}

	public function test_the_read_only_resolver_is_a_preview_safe_wrapper(): void {
		$resolver = new ReadOnlyProductResolver( $this->matcher, true );
		$this->assertSame( '77', $resolver->resolve( 1, 'CS-BOWL-02', 501 )?->id );
		$this->assertSame( 0, $this->idMap->writes );
	}
}
