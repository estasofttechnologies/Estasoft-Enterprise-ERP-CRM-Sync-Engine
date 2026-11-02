<?php
/**
 * Shared set-up for the Sage 50 connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage50;

use Estasoft\SyncEngine\Connectors\Sage50\Sage50Connector;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use PHPUnit\Framework\TestCase;

/**
 * A connector over a temporary uploads folder, plus an independent reader of the files it writes (PHP's own CSV parser after an
 * independent character set conversion; none of the writer's code).
 */
abstract class Sage50TestCase extends TestCase {

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
	 * @var Sage50Connector
	 */
	protected Sage50Connector $connector;

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
		$this->dir       = FakeExportKit::folder( 'sage50' );
		$this->deps      = FakeExportKit::deps( $this->dir );
		$this->connector = new Sage50Connector( $this->deps );
	}

	/**
	 * Remove the folder.
	 */
	protected function tearDown(): void {
		FakeExportKit::remove( $this->dir );
	}

	/**
	 * A UK connection.
	 *
	 * @param array<string,mixed> $options Settings that replace the defaults.
	 */
	protected function uk( array $options = array() ): Connection {
		return FakeExportKit::connection( 'sage50-export', $options + array( 'edition' => 'uk', 'currency' => 'GBP', 'header_row' => 'yes' ) );
	}

	/**
	 * A US connection.
	 *
	 * @param array<string,mixed> $options Settings that replace the defaults.
	 */
	protected function us( array $options = array() ): Connection {
		return FakeExportKit::connection(
			'sage50-export',
			$options + array(
				'edition'       => 'us',
				'header_row'    => 'yes',
				'currency'      => 'USD',
				'ar_account'    => '11000',
				'sales_account' => '40000',
				'tax_gl_account' => '23000',
				'customer_id'   => 'WEB',
			)
		);
	}

	/**
	 * An order at 20 percent: two lines and shipping, 180.00 in all.
	 *
	 * @param string              $number   Order number.
	 * @param string              $currency Currency.
	 * @param string              $code     Tax code.
	 * @param array<string,mixed> $with     Blocks to replace.
	 * @return array<string,mixed>
	 */
	protected function order( string $number = '1001', string $currency = 'GBP', string $code = 'T1', array $with = array() ): array {
		$payload                         = FakeExportKit::order( $number, $with );
		$payload['document']['currency'] = $currency;
		$payload['document']['total']    = 18000;
		$net                             = array( 10000, 4000 );
		foreach ( $payload['line'] as $i => $line ) {
			$payload['line'][ $i ]['net']      = $net[ $i ];
			$payload['line'][ $i ]['tax']      = intdiv( $net[ $i ] * 20, 100 );
			$payload['line'][ $i ]['tax_code'] = $code;
			$payload['line'][ $i ]['tax_rate'] = 20;
		}
		$payload['shipping'][0]['net']      = 1000;
		$payload['shipping'][0]['tax']      = 200;
		$payload['shipping'][0]['tax_code'] = $code;
		$payload['shipping'][0]['tax_rate'] = 20;
		return $payload;
	}

	/**
	 * Send a payload.
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
	 * Build the file of the open batch.
	 *
	 * @return array{bytes:string,mime:string,left_out:array<int,array{item_id:int,order:string,reason:string}>,attachments:array<string,string>}
	 */
	protected function file(): array {
		$batch = $this->deps->exports->openBatch( 7 );
		$file  = $this->connector->finish( $batch );
		return array(
			'bytes'    => FakeExportKit::bytes( $file ),
			'mime'     => $file->format->mime,
			'left_out' => $batch->leftOut(),
			'attachments' => array_column( $file->attachments, 'contents', 'name' ),
		);
	}

	/**
	 * The records of a file, read with PHP's CSV parser.
	 *
	 * @param string $bytes    File bytes.
	 * @param string $encoding windows-1252 or utf-8-bom.
	 * @return array<int,array<int,string>>
	 */
	protected function records( string $bytes, string $encoding = 'windows-1252' ): array {
		if ( 'utf-8-bom' === $encoding ) {
			$this->assertSame( "\xEF\xBB\xBF", substr( $bytes, 0, 3 ) );
			$text = substr( $bytes, 3 );
		} else {
			$this->assertNotSame( "\xEF\xBB\xBF", substr( $bytes, 0, 3 ) );
			$text = mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
		}
		$this->assertStringEndsWith( "\r\n", $text );
		$this->assertSame( 0, preg_match( '/(?<!\r)\n(?=(?:[^"]*"[^"]*")*[^"]*$)/', $text ), 'No bare line feeds outside quotes.' );
		$rows = array();
		$fh   = fopen( 'php://memory', 'w+' );
		fwrite( $fh, $text );
		rewind( $fh );
		while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
			$rows[] = array_map( 'strval', $row );
		}
		fclose( $fh );
		return $rows;
	}

	/**
	 * An amount text as minor units.
	 *
	 * @param string $decimal Amount text with two decimals.
	 */
	protected function minor( string $decimal ): int {
		return (int) str_replace( '.', '', $decimal );
	}
}
