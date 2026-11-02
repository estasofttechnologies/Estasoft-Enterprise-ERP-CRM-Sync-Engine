<?php
/**
 * Mapping versions, the tax map, the id map and the log against the real repositories and database (AS-13).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Mapping;

require_once dirname( __DIR__, 2 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Mapping\Adapters\CoreConnectionSource;
use Estasoft\SyncEngine\Mapping\Adapters\CoreEventLog;
use Estasoft\SyncEngine\Mapping\Adapters\CoreIdMapStore;
use Estasoft\SyncEngine\Mapping\Adapters\CoreMappingStore;
use Estasoft\SyncEngine\Mapping\CanonicalFields;
use Estasoft\SyncEngine\Mapping\MappingService;
use Estasoft\SyncEngine\Mapping\Presets;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleSanitizer;
use Estasoft\SyncEngine\Mapping\SkuMatcher;
use Estasoft\SyncEngine\Mapping\TaxMap;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class MappingPersistenceTest extends CoreIntegrationTestCase {

	private int $conn;
	private MappingService $service;
	private CoreMappingStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->h->connection();
		$this->store = new CoreMappingStore( $this->h->mappings );
		$this->service = new MappingService(
			$this->store,
			new RuleEngine( TransformRegistry::standard() ),
			new RuleSanitizer(),
			new CoreConnectionSource( $this->h->connections ),
			$this->h->registry,
			new CanonicalFields(),
			new CoreEventLog( $this->h->logger )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function rules( string $country ): array {
		return array(
			Presets::rule( 'name', 'partner.name', array( 'billing.first_name', 'billing.last_name' ), array( Presets::transform( 'join' ) ) ),
			Presets::rule( 'phone', 'partner.phone', 'billing.phone', array( Presets::transform( 'phone_e164', array( 'default_country' => $country ) ) ) ),
		);
	}

	public function test_as_13_publish_and_rollback_are_a_linear_history_in_the_database(): void {
		$this->service->saveDraft( $this->conn, 'orders', $this->rules( 'AE' ), 'First', 'Layla Haddad' );
		$this->assertSame( 1, $this->service->publish( $this->conn, 'orders', 'Layla Haddad' )['version'] );
		$this->service->saveDraft( $this->conn, 'orders', $this->rules( 'SA' ), 'Saudi', 'Layla Haddad' );
		$this->assertSame( 2, $this->service->publish( $this->conn, 'orders', 'Layla Haddad' )['version'] );
		$this->service->saveDraft( $this->conn, 'orders', $this->rules( 'KW' ), 'Kuwait', 'Layla Haddad' );

		$rolled = $this->service->rollback( $this->conn, 'orders', 1, 'Layla Haddad' );
		$this->assertSame( 4, $rolled['version'], 'The unpublished draft keeps its number 3; the copy is 4.' );
		$this->assertSame( $this->service->version( $this->conn, 'orders', 1 )['rules'], $rolled['rules'] );

		$statuses = array_column( $this->service->versions( $this->conn, 'orders' ), 'status', 'version' );
		$this->assertSame( array( 4 => 'live', 3 => 'draft', 2 => 'retired', 1 => 'retired' ), $statuses );
		$this->assertSame( 4, $this->store->live( $this->conn, 'orders' )['version'] );
		$this->assertSame( 3, $this->store->draft( $this->conn, 'orders' )['version'] );
	}

	public function test_a_draft_with_problems_is_refused_and_the_draft_survives(): void {
		$this->service->saveDraft( $this->conn, 'orders', array( Presets::rule( 'x', 'partner.name', 'billing.shoe_size' ) ), null, 'x' );
		$this->expectException( \Estasoft\SyncEngine\Mapping\RuleValidationException::class );
		try {
			$this->service->publish( $this->conn, 'orders', 'x' );
		} finally {
			$this->assertNull( $this->store->live( $this->conn, 'orders' ) );
			$this->assertNotNull( $this->store->draft( $this->conn, 'orders' ) );
		}
	}

	public function test_the_tax_map_is_edited_live_in_place_and_logged(): void {
		$taxes = new TaxMap( $this->store, new CoreEventLog( $this->h->logger ) );
		$taxes->save( $this->conn, array( array( 'country' => 'AE', 'tax_name' => 'Standard VAT', 'rate_percent' => 5, 'system_code' => 'VAT 5% (AE)' ) ), null, 'Layla Haddad' );
		$taxes->save( $this->conn, array( array( 'country' => 'AE', 'tax_name' => 'Standard VAT', 'rate_percent' => 5, 'system_code' => 'VAT 5% (AE) v2' ) ), null, 'Layla Haddad' );

		$this->assertCount( 1, $this->store->versions( $this->conn, 'tax' ), 'No new version per save.' );
		$fresh = new TaxMap( $this->store, new CoreEventLog( $this->h->logger ) );
		$this->assertSame( 'VAT 5% (AE) v2', $fresh->resolve( $this->conn, 'AE', '', '5' ) );
		$this->assertSame( array( array( 'country' => 'SA', 'digits' => 15, 'required_for_business' => true ) ), $fresh->buyerRules( $this->conn ) );

		$logged = $this->h->logs->query( new \Estasoft\SyncEngine\Core\Repositories\LogQuery() );
		$this->assertGreaterThanOrEqual( 2, $logged->total, 'Each change is in the log.' );
	}

	public function test_sku_matching_uses_the_real_id_map_and_never_replaces_a_choice_made_by_hand(): void {
		$idMap   = new CoreIdMapStore( $this->h->idMap );
		$matcher = new SkuMatcher( $idMap, new CoreConnectionSource( $this->h->connections ), $this->h->registry );

		$matcher->setOverride( $this->conn, 501, '99', 'CS-BOWL-04L', 'CS-BOWL-04' );
		$found = $matcher->resolve( $this->conn, 'CS-BOWL-04', 501 );
		$this->assertSame( '99', $found?->id );
		$this->assertSame( 'CS-BOWL-04L', $found?->ref );
		$this->assertSame( 'override', $matcher->status( $this->conn, 501, 'CS-BOWL-04' ) );

		$this->assertTrue( $idMap->get( $this->conn, 'product', '501' )['is_override'] );
		$this->assertFalse( $idMap->link( $this->conn, 'product', '501', 'other', null, null, null, false ), 'An automatic link cannot replace an override.' );

		$matcher->setOverride( $this->conn, 501, null );
		$this->assertNull( $idMap->get( $this->conn, 'product', '501' ) );
		$this->assertNull( $matcher->resolve( $this->conn, 'CS-BOWL-04', 501 ), 'The fake system does not know this SKU.' );
	}
}
