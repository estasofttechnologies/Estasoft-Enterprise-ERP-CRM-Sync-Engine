<?php
/**
 * Shared set-up for the DATEV connector tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Datev;

use Estasoft\SyncEngine\Connectors\Datev\DatevConnector;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use PHPUnit\Framework\TestCase;

/**
 * A connector over a temporary uploads folder, plus an independent reader of the files it writes (PHP's own CSV parser after an
 * independent character set conversion; none of the writer's code).
 */
abstract class DatevTestCase extends TestCase {

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
	 * @var DatevConnector
	 */
	protected DatevConnector $connector;

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
		$this->dir       = FakeExportKit::folder( 'datev' );
		$this->deps      = FakeExportKit::deps( $this->dir );
		$this->connector = new DatevConnector( $this->deps );
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
			'datev-export',
			$options + array(
				'consultant_number' => 29098,
				'client_number'     => 55003,
			)
		);
	}

	/**
	 * An order payload in euros: two lines and shipping at 19 percent into account 8400.
	 *
	 * @param string              $number Order number.
	 * @param array<string,mixed> $with   Blocks to replace.
	 * @return array<string,mixed>
	 */
	protected function order( string $number = '1001', array $with = array() ): array {
		$payload                         = FakeExportKit::order( $number, $with );
		$payload['document']['currency'] = 'EUR';
		$payload['document']['total']    = 11900 + 5950 + 1190;
		foreach ( $payload['line'] as $i => $line ) {
			$net                                 = 0 === $i ? 10000 : 5000;
			$payload['line'][ $i ]['net']        = $net;
			$payload['line'][ $i ]['tax']        = intdiv( $net * 19, 100 );
			$payload['line'][ $i ]['tax_code']   = '8400';
			$payload['line'][ $i ]['tax_rate']   = 19;
			$payload['line'][ $i ]['unit_price'] = 0 === $i ? 5000 : 5000;
		}
		$payload['shipping'][0]['net']      = 1000;
		$payload['shipping'][0]['tax']      = 190;
		$payload['shipping'][0]['tax_code'] = '8400';
		$payload['shipping'][0]['tax_rate'] = 19;
		return $payload;
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
	 * Build the file of the open batch.
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
	 * The text of a file in UTF-8, converted by PHP's own functions.
	 *
	 * @param string $bytes    File bytes.
	 * @param string $encoding windows-1252 or utf-8-bom.
	 */
	protected function utf8( string $bytes, string $encoding = 'windows-1252' ): string {
		if ( 'utf-8-bom' === $encoding ) {
			$this->assertSame( "\xEF\xBB\xBF", substr( $bytes, 0, 3 ), 'The UTF-8 file starts with a byte order mark.' );
			return substr( $bytes, 3 );
		}
		$this->assertNotSame( "\xEF\xBB\xBF", substr( $bytes, 0, 3 ) );
		return mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
	}

	/**
	 * The raw lines of a file.
	 *
	 * @param string $bytes    File bytes.
	 * @param string $encoding Encoding.
	 * @return array<int,string>
	 */
	protected function lines( string $bytes, string $encoding = 'windows-1252' ): array {
		$text = $this->utf8( $bytes, $encoding );
		$this->assertStringEndsWith( "\r\n", $text, 'Every line, the last too, ends with CR LF.' );
		$this->assertSame( 0, preg_match( '/(?<!\r)\n/', $text ), 'No line ends with a bare line feed.' );
		return explode( "\r\n", substr( $text, 0, -2 ) );
	}

	/**
	 * The records of a file as lists of fields, read with PHP's CSV parser (quotes undoubled).
	 *
	 * @param string $bytes    File bytes.
	 * @param string $encoding Encoding.
	 * @return array<int,array<int,string>> Row 0 is the header, row 1 the column names, then the postings.
	 */
	protected function records( string $bytes, string $encoding = 'windows-1252' ): array {
		$rows = array();
		foreach ( $this->lines( $bytes, $encoding ) as $line ) {
			$rows[] = str_getcsv( $line, ';', '"', '' );
		}
		return $rows;
	}
}
