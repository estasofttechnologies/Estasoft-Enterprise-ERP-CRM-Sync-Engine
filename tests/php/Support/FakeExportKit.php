<?php
/**
 * Test helpers for the export connectors: services, connections, items and payloads.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportBatch;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFile;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportStore;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;

/**
 * Builds what an export connector needs without WordPress or a database: an export store in a temporary folder, a clock, and
 * synthetic orders. No real names, tax numbers or addresses appear anywhere.
 */
final class FakeExportKit {

	/**
	 * Services for a connector. The token manager and the logger are never used by an export connector, so they are empty shells.
	 *
	 * @param string $dir Temporary uploads folder.
	 */
	public static function deps( string $dir ): ConnectorDependencies {
		$clock  = new CoreFakeClock( '2026-11-02 12:00:00' );
		$tokens = ( new \ReflectionClass( OAuthTokenManager::class ) )->newInstanceWithoutConstructor();
		$logger = ( new \ReflectionClass( Logger::class ) )->newInstanceWithoutConstructor();
		return new ConnectorDependencies( new FakeHttpClient(), $clock, $tokens, new ExportStore( static fn(): string => $dir, $clock ), $logger );
	}

	/**
	 * A fresh empty folder under the system temp folder.
	 *
	 * @param string $name Short name.
	 */
	public static function folder( string $name ): string {
		$dir = sys_get_temp_dir() . '/estasoft-export-' . $name . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		return $dir;
	}

	/**
	 * Remove a folder made by folder().
	 *
	 * @param string $dir Folder.
	 */
	public static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * A connection with the given settings.
	 *
	 * @param string              $connector Connector id.
	 * @param array<string,mixed> $options   Settings.
	 * @param int                 $id        Connection id.
	 */
	public static function connection( string $connector, array $options = array(), int $id = 7 ): Connection {
		return new Connection( $id, $connector, ConnectorType::Accounting, 'Test connection', ConnectionStatus::Active, array( 'options' => $options ) );
	}

	/**
	 * A queue item.
	 *
	 * @param int        $id       Item id.
	 * @param string     $entityId WooCommerce id.
	 * @param EntityType $type     Order or refund.
	 */
	public static function item( int $id, string $entityId, EntityType $type = EntityType::Order ): Item {
		return new Item( $id, 7, $type, $entityId, 'created', 'order:' . $entityId, hash( 'sha256', $id . $entityId ), ItemState::Queued );
	}

	/**
	 * A mapped order payload: two products at 5 percent, shipping at 5 percent, in AED.
	 *
	 * @param string              $number Order number.
	 * @param array<string,mixed> $with   Blocks that replace the default ones (document, customer, line, shipping, fee, payment).
	 * @return array<string,mixed>
	 */
	public static function order( string $number = '1001', array $with = array() ): array {
		$payload = array(
			'document' => array(
				'number'    => $number,
				'reference' => 'WC-' . $number,
				'date'      => '2026-11-02',
				'currency'  => 'AED',
				'total'     => 15750,
				'note'      => '',
			),
			'customer' => array(
				'name' => 'Layla Haddad',
				'id'   => 0,
			),
			'line'     => array(
				array(
					'sku'        => 'SKU-A',
					'name'       => 'Cedar shelf',
					'quantity'   => 2,
					'unit_price' => 5000,
					'net'        => 10000,
					'tax'        => 500,
					'tax_code'   => 'Output VAT 5%',
					'tax_rate'   => 5,
				),
				array(
					'sku'        => 'SKU-B',
					'name'       => 'Stone coaster',
					'quantity'   => 1,
					'unit_price' => 4000,
					'net'        => 4000,
					'tax'        => 200,
					'tax_code'   => 'Output VAT 5%',
					'tax_rate'   => 5,
				),
			),
			'shipping' => array(
				array(
					'name'     => 'Flat rate',
					'net'      => 1000,
					'tax'      => 50,
					'tax_code' => 'Output VAT 5%',
					'tax_rate' => 5,
				),
			),
		);
		foreach ( $with as $key => $value ) {
			$payload[ $key ] = $value;
		}
		return $payload;
	}

	/**
	 * A mapped refund payload: one refunded line and refunded shipping.
	 *
	 * @param string              $number Original order number.
	 * @param int                 $refund Refund id.
	 * @param array<string,mixed> $with   Blocks that replace the default ones.
	 * @return array<string,mixed>
	 */
	public static function refund( string $number = '1001', int $refund = 55, array $with = array() ): array {
		$payload = array(
			'document'          => array(
				'number'    => $number,
				'reference' => 'WC-' . $number,
				'refund_id' => $refund,
				'date'      => '2026-11-03',
				'currency'  => 'AED',
				'total'     => 5250,
				'note'      => 'Damaged in transit',
			),
			'customer'          => array(
				'name' => 'Layla Haddad',
				'id'   => 0,
			),
			'refund_line'       => array(
				array(
					'sku'      => 'SKU-A',
					'quantity' => 1,
					'net'      => 5000,
					'tax'      => 250,
				),
			),
			'refund_shipping'   => array( 'gross' => 0 ),
			'original_line'     => array(
				array(
					'sku'      => 'SKU-A',
					'tax_code' => 'Output VAT 5%',
					'tax_rate' => 5,
				),
			),
			'original_shipping' => array(
				array(
					'tax_code' => 'Output VAT 5%',
					'tax_rate' => 5,
				),
			),
		);
		foreach ( $with as $key => $value ) {
			$payload[ $key ] = $value;
		}
		return $payload;
	}

	/**
	 * Send an order through a connector and return what the batch holds.
	 *
	 * @param \Estasoft\SyncEngine\Contract\ConnectorInterface $connector Connector.
	 * @param Connection                                       $connection Connection.
	 * @param Item                                             $item       Item.
	 * @param array<string,mixed>                              $payload    Payload.
	 */
	public static function send( \Estasoft\SyncEngine\Contract\ConnectorInterface $connector, Connection $connection, Item $item, array $payload ): \Estasoft\SyncEngine\Contract\SendResult {
		return $connector->send( $connection, $item, $payload );
	}

	/**
	 * The open batch of a connection.
	 *
	 * @param ConnectorDependencies $deps         Services.
	 * @param int                   $connectionId Connection id.
	 */
	public static function batch( ConnectorDependencies $deps, int $connectionId = 7 ): ExportBatch {
		return $deps->exports->openBatch( $connectionId );
	}

	/**
	 * The bytes of a file a connector made: read from the work file when it was written to disk, otherwise the contents.
	 *
	 * @param ExportFile $file File from finish().
	 */
	public static function bytes( ExportFile $file ): string {
		if ( '' === $file->path ) {
			return $file->contents;
		}
		$bytes = file_get_contents( $file->path );
		return false === $bytes ? '' : $bytes;
	}

	/**
	 * The text of a second file of an export (the masters list), by position.
	 *
	 * @param ExportFile $file  File from finish().
	 * @param int        $index Position, 0 for the first.
	 */
	public static function attachment( ExportFile $file, int $index = 0 ): string {
		$part = $file->attachments[ $index ] ?? array();
		if ( isset( $part['path'] ) && '' !== (string) $part['path'] ) {
			return (string) file_get_contents( (string) $part['path'] );
		}
		return (string) ( $part['contents'] ?? '' );
	}
}
