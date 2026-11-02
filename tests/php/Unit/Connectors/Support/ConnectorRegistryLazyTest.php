<?php
/**
 * The registry builds connectors only when they are asked for.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

use Estasoft\SyncEngine\Contract\ConnectorInterface;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceBooksConnector;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceCrmConnector;
use PHPUnit\Framework\TestCase;

final class ConnectorRegistryLazyTest extends TestCase {

	private ApiTestKit $kit;

	protected function setUp(): void {
		$this->kit = ApiTestKit::make();
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	public function test_a_closure_under_an_id_is_built_on_first_use_and_only_once(): void {
		$built    = 0;
		$registry = new ConnectorRegistry(
			array(
				'reference-books' => function () use ( &$built ): ConnectorInterface {
					++$built;
					return new ReferenceBooksConnector( $this->kit->deps );
				},
			)
		);
		$this->assertSame( 0, $built );
		$this->assertTrue( $registry->has( 'reference-books' ) );
		$this->assertSame( array( 'reference-books' ), $registry->ids() );
		$this->assertSame( 0, $built, 'Asking for ids builds nothing.' );
		$this->assertSame( $registry->get( 'reference-books' ), $registry->get( 'reference-books' ) );
		$this->assertSame( 1, $built );
		$this->assertNull( $registry->get( 'unknown' ) );
	}

	public function test_all_builds_every_connector_and_objects_and_closures_mix(): void {
		$registry = new ConnectorRegistry(
			array(
				new ReferenceBooksConnector( $this->kit->deps ),
				'reference-crm' => fn() => new ReferenceCrmConnector( $this->kit->deps ),
				'broken'        => static function (): ConnectorInterface {
					throw new \RuntimeException( 'a connector that cannot be built' );
				},
			)
		);
		$this->assertSame( array( 'reference-books', 'reference-crm' ), array_keys( $registry->all() ) );
		$this->assertSame( array( 'broken' => 'error' ), $registry->rejected(), 'One broken connector never stops the others.' );
	}

	public function test_an_object_with_the_same_id_replaces_a_closure(): void {
		$replacement = new ReferenceBooksConnector( $this->kit->deps );
		$registry    = new ConnectorRegistry( array( 'reference-books' => fn() => new ReferenceCrmConnector( $this->kit->deps ), $replacement ) );
		$this->assertSame( $replacement, $registry->get( 'reference-books' ) );
	}

	public function test_a_connector_with_another_contract_major_is_rejected_after_it_is_built(): void {
		$old = new class( $this->kit->deps ) extends ReferenceBooksConnector {
			public function contractVersion(): string {
				return '2.0.0';
			}
		};
		$registry = new ConnectorRegistry( array( 'reference-books' => fn() => $old ) );
		$this->assertNull( $registry->get( 'reference-books' ) );
		$this->assertSame( array( 'reference-books' => 'contract_version' ), $registry->rejected() );
	}
}
