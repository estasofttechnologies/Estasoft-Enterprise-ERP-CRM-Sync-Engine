<?php
/**
 * Shared set-up for the TallyPrime connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Tally;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Tally\TallyConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use PHPUnit\Framework\TestCase;

/**
 * A connector over a temporary uploads folder, plus an independent reader of the files it writes (libxml, not the writer's own code).
 */
abstract class TallyTestCase extends TestCase {

	/**
	 * Temporary folder.
	 *
	 * @var string
	 */
	protected string $dir = '';

	/**
	 * Services.
	 *
	 * @var ConnectorDependencies
	 */
	protected ConnectorDependencies $deps;

	/**
	 * Connector.
	 *
	 * @var TallyConnector
	 */
	protected TallyConnector $connector;

	/**
	 * Next queue item id.
	 *
	 * @var int
	 */
	private int $nextItem = 1;

	/**
	 * Fresh folder and connector.
	 */
	protected function setUp(): void {
		$this->dir       = FakeExportKit::folder( 'tally' );
		$this->deps      = FakeExportKit::deps( $this->dir );
		$this->connector = new TallyConnector( $this->deps );
	}

	/**
	 * Remove the folder.
	 */
	protected function tearDown(): void {
		FakeExportKit::remove( $this->dir );
	}

	/**
	 * A connection with the minimum settings and any extras.
	 *
	 * @param array<string,mixed> $options Settings that replace the defaults.
	 */
	protected function connection( array $options = array() ): Connection {
		return FakeExportKit::connection(
			'tallyprime-export',
			$options + array(
				'company'  => 'Cedar & Stone Trading LLC',
				'currency' => 'AED',
			)
		);
	}

	/**
	 * Send an order payload and return the result.
	 *
	 * @param Connection          $connection Connection.
	 * @param array<string,mixed> $payload    Payload.
	 * @param EntityType          $type       Order or refund.
	 */
	protected function send( Connection $connection, array $payload, EntityType $type = EntityType::Order ): \Estasoft\SyncEngine\Contract\SendResult {
		$id     = $this->nextItem++;
		$number = (string) ( $payload['document']['number'] ?? $id );
		return $this->connector->send( $connection, FakeExportKit::item( $id, $number, $type ), $payload );
	}

	/**
	 * Build the file of the open batch and return its bytes and the left-out orders.
	 *
	 * @return array{bytes:string,mime:string,left_out:array<int,array{item_id:int,order:string,reason:string}>}
	 */
	protected function file(): array {
		$batch = $this->deps->exports->openBatch( 7 );
		$file  = $this->connector->finish( $batch );
		return array(
			'bytes'    => FakeExportKit::bytes( $file ),
			'mime'     => $file->format->mime,
			'left_out' => $batch->leftOut(),
		);
	}

	/**
	 * Parse file bytes with libxml. UTF-16 files are given to libxml as they are, with their byte order mark.
	 *
	 * @param string $bytes File bytes.
	 */
	protected function dom( string $bytes ): \DOMDocument {
		$dom = new \DOMDocument();
		$old = libxml_use_internal_errors( true );
		$ok  = $dom->loadXML( $bytes );
		$err = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		$this->assertTrue( $ok, 'The file is not well-formed XML: ' . ( isset( $err[0] ) ? trim( $err[0]->message ) : '' ) );
		return $dom;
	}

	/**
	 * An XPath over a document.
	 *
	 * @param \DOMDocument $dom DOM.
	 */
	protected function xpath( \DOMDocument $dom ): \DOMXPath {
		return new \DOMXPath( $dom );
	}

	/**
	 * Text of the first node an expression finds.
	 *
	 * @param \DOMXPath $xp      XPath.
	 * @param string    $query   Expression.
	 * @param \DOMNode  $context Context node.
	 */
	protected function text( \DOMXPath $xp, string $query, ?\DOMNode $context = null ): string {
		$nodes = null === $context ? $xp->query( $query ) : $xp->query( $query, $context );
		$this->assertNotFalse( $nodes );
		$this->assertGreaterThan( 0, $nodes->length, 'Nothing found for ' . $query );
		return (string) $nodes->item( 0 )->textContent;
	}

	/**
	 * An amount written with its decimals as minor units ("-21546.00" is -2154600).
	 *
	 * @param string $decimal Amount text.
	 */
	protected function minor( string $decimal ): int {
		return (int) str_replace( '.', '', trim( $decimal ) );
	}

	/**
	 * The sum of the amounts of every ledger entry directly under a voucher, in minor units. A balanced voucher sums to zero.
	 *
	 * @param \DOMXPath $xp      XPath.
	 * @param \DOMNode  $voucher Voucher node.
	 */
	protected function ledgerSum( \DOMXPath $xp, \DOMNode $voucher ): int {
		$sum   = 0;
		$nodes = $xp->query( 'LEDGERENTRIES.LIST/AMOUNT | ALLLEDGERENTRIES.LIST/AMOUNT', $voucher );
		foreach ( $nodes as $node ) {
			$sum += $this->minor( $node->textContent );
		}
		return $sum;
	}
}
