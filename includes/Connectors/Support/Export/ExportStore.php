<?php
/**
 * The protected folder that holds export files and the batches waiting for the next file.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Clock;

/**
 * Layout, under the uploads folder: `estasoft-sync/exports/{random 32 hex}/` with `files/` (the export files), `meta/` (one small JSON per file)
 * and `batches/{connection}/` (one JSON per waiting order). Every folder gets an `index.php` that prints nothing, an `.htaccess` that denies
 * access and a `web.config` that denies access; the random name stops anyone from guessing the address; the web server never lists it; files
 * are downloaded only through the authenticated REST route. Files live 30 days and are removed by the daily clean-up. The whole `estasoft-sync`
 * folder is removed with the plugin's data (Core\DataEraser).
 *
 * File names are `{connection}-{Ymd-His}-{random}.{ext}`. A batch row is one file named after the queue item, so two workers adding orders
 * at the same moment never overwrite each other.
 */
final class ExportStore {

	/**
	 * Days a file is kept.
	 */
	public const RETENTION_DAYS = 30;

	/**
	 * Folder under the uploads folder. DataEraser removes `estasoft-sync` on uninstall, so this one goes with it.
	 */
	public const FOLDER = 'estasoft-sync/exports';

	/**
	 * Set up with what it depends on.
	 *
	 * @param \Closure $baseDir Returns the uploads base directory, or an empty string when uploads are not available.
	 * @param Clock    $clock   Time source.
	 */
	public function __construct( private readonly \Closure $baseDir, private readonly Clock $clock ) {
	}

	/**
	 * Write a file and its details.
	 *
	 * @param ExportFile                                   $file         File built by the connector.
	 * @param int                                          $connectionId Connection.
	 * @param string                                       $createdBy    Display name of the person, or empty.
	 * @param int                                          $orders       Orders in the file.
	 * @param array<int,array{order:string,reason:string}> $leftOut      Orders left out.
	 *
	 * @throws ExportException With SYNC-EXP-001 when the folder cannot be written.
	 */
	public function write( ExportFile $file, int $connectionId, string $createdBy, int $orders, array $leftOut ): ExportFile {
		$root = $this->root( true );
		if ( null === $root || ! $this->ensure( $root . '/files' ) || ! $this->ensure( $root . '/meta' ) ) {
			throw ExportException::notWritable();
		}
		$now       = $this->clock->now();
		$id        = bin2hex( random_bytes( 8 ) );
		$base      = $connectionId . '-' . $now->format( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) );
		$extension = $file->format->safeExtension();
		$filename  = $base . '.' . $extension;
		$size      = $this->place( $file->path, $file->contents, $root . '/files/' . $filename, $file->format->addsByteOrderMark() );
		if ( null === $size ) {
			throw ExportException::notWritable();
		}
		$attachments = array();
		foreach ( array_values( $file->attachments ) as $index => $attachment ) {
			$name       = (string) ( $attachment['name'] ?? 'part' . ( $index + 1 ) );
			$display    = $base . '-' . ( '' === $this->slug( $name ) ? 'part' . ( $index + 1 ) : $this->slug( $name ) ) . '.' . $this->extensionOf( (string) ( $attachment['filename'] ?? '' ) );
			$attachSize = $this->place( (string) ( $attachment['path'] ?? '' ), (string) ( $attachment['contents'] ?? '' ), $root . '/files/' . $display, false );
			if ( null === $attachSize ) {
				wp_delete_file( $root . '/files/' . $filename );
				foreach ( $attachments as $done ) {
					wp_delete_file( $root . '/files/' . $done['filename'] );
				}
				throw ExportException::notWritable();
			}
			$attachments[] = array(
				'name'       => $name,
				'filename'   => $display,
				'mime'       => (string) ( $attachment['mime'] ?? 'text/plain; charset=utf-8' ),
				'size_bytes' => $attachSize,
			);
		}
		$stored = $file->stored(
			$id,
			$connectionId,
			$filename,
			$size,
			$now->format( 'Y-m-d\TH:i:s\Z' ),
			$now->modify( '+' . self::RETENTION_DAYS . ' days' )->format( 'Y-m-d\TH:i:s\Z' ),
			$createdBy,
			$orders,
			$leftOut,
			$attachments
		);
		$meta   = array_merge(
			$stored->info(),
			array(
				'mime'        => $file->format->mime,
				'extension'   => $extension,
				'attachments' => $attachments,
			)
		);
		if ( false === file_put_contents( $root . '/meta/' . $id . '.json', (string) wp_json_encode( $meta ), LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $root . '/files/' . $filename );
			foreach ( $attachments as $done ) {
				wp_delete_file( $root . '/files/' . $done['filename'] );
			}
			throw ExportException::notWritable();
		}
		return $stored;
	}

	/**
	 * A path inside the protected folder for a file a connector writes in pieces (a stream), to hand over as `ExportFile::fromPath()`.
	 *
	 * @return string|null The path, or null when the folder cannot be written.
	 */
	public function workFile(): ?string {
		$root = $this->root( true );
		if ( null === $root || ! $this->ensure( $root . '/tmp' ) ) {
			return null;
		}
		return $root . '/tmp/' . bin2hex( random_bytes( 8 ) ) . '.part';
	}

	/**
	 * Put the contents of a file in place: move a finished work file or write a string, adding the byte order mark when asked.
	 *
	 * @param string $source   Work file, or empty.
	 * @param string $contents Contents when there is no work file.
	 * @param string $target   Where the file goes.
	 * @param bool   $bom      Add a UTF-8 byte order mark.
	 * @return int|null Size in bytes, or null when it could not be written.
	 */
	private function place( string $source, string $contents, string $target, bool $bom ): ?int {
		if ( '' !== $source ) {
			if ( ! is_file( $source ) || ! $this->insideRoot( $source ) ) {
				return null;
			}
			if ( ! $bom ) {
				return rename( $source, $target ) ? (int) filesize( $target ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
			$in  = fopen( $source, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$out = fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $in || false === $out ) {
				return null;
			}
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$copied = stream_copy_to_stream( $in, $out );
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_delete_file( $source );
			return false === $copied ? null : $copied + 3;
		}
		if ( $bom && ! str_starts_with( $contents, "\xEF\xBB\xBF" ) ) {
			$contents = "\xEF\xBB\xBF" . $contents;
		}
		// The plugin's own file in its own protected uploads folder; WP_Filesystem is not needed for a direct write without credentials.
		return false === file_put_contents( $target, $contents, LOCK_EX ) ? null : strlen( $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Whether a path is inside the protected folder (a work file must be, so a connector cannot make the store move another file).
	 *
	 * @param string $path Path.
	 */
	private function insideRoot( string $path ): bool {
		$root = $this->root( false );
		$real = realpath( $path );
		return null !== $root && false !== $real && str_starts_with( $real, (string) realpath( $root ) . DIRECTORY_SEPARATOR );
	}

	/**
	 * A file name part made of letters, digits and dashes.
	 *
	 * @param string $text Text.
	 */
	private function slug( string $text ): string {
		return trim( strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $text ) ), '-' );
	}

	/**
	 * The extension of a file name, cleaned (txt when there is none).
	 *
	 * @param string $filename File name.
	 */
	private function extensionOf( string $filename ): string {
		$extension = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', pathinfo( $filename, PATHINFO_EXTENSION ) ) );
		return '' === $extension ? 'txt' : substr( $extension, 0, 8 );
	}

	/**
	 * The details of one file.
	 *
	 * @param string $id File id.
	 */
	public function find( string $id ): ?ExportFile {
		$meta = $this->meta( $id );
		return null === $meta ? null : $this->fromMeta( $meta );
	}

	/**
	 * The files, newest first.
	 *
	 * @param int|null $connectionId Only this connection, or all.
	 * @return array<int,ExportFile>
	 */
	public function list( ?int $connectionId = null ): array {
		$root = $this->root( false );
		if ( null === $root || ! is_dir( $root . '/meta' ) ) {
			return array();
		}
		$files = array();
		foreach ( (array) scandir( $root . '/meta' ) as $name ) {
			if ( ! is_string( $name ) || ! str_ends_with( $name, '.json' ) ) {
				continue;
			}
			$meta = $this->meta( substr( $name, 0, -5 ) );
			if ( null !== $meta && ( null === $connectionId || (int) $meta['connection_id'] === $connectionId ) ) {
				$files[] = $this->fromMeta( $meta );
			}
		}
		usort( $files, static fn( ExportFile $a, ExportFile $b ): int => strcmp( $b->createdAt, $a->createdAt ) );
		return $files;
	}

	/**
	 * The path and type of a file's data for the download route.
	 *
	 * @param string $id   File id.
	 * @param int    $part 0 for the file, 1 and up for its attachments.
	 * @return array{path:string,mime:string,filename:string,size:int}|null
	 */
	public function download( string $id, int $part = 0 ): ?array {
		$meta = $this->meta( $id );
		$root = $this->root( false );
		if ( null === $meta || null === $root ) {
			return null;
		}
		$filename = (string) $meta['filename'];
		$mime     = (string) ( $meta['mime'] ?? 'application/octet-stream' );
		if ( $part > 0 ) {
			$attachment = array_values( (array) ( $meta['attachments'] ?? array() ) )[ $part - 1 ] ?? null;
			if ( ! is_array( $attachment ) || empty( $attachment['filename'] ) ) {
				return null;
			}
			$filename = (string) $attachment['filename'];
			$mime     = (string) ( $attachment['mime'] ?? 'text/plain; charset=utf-8' );
		}
		$path = $root . '/files/' . basename( $filename );
		if ( ! is_file( $path ) ) {
			return null;
		}
		return array(
			'path'     => $path,
			'mime'     => $mime,
			'filename' => basename( $filename ),
			'size'     => (int) filesize( $path ),
		);
	}

	/**
	 * Delete one file, its attachments and its details.
	 *
	 * @param string $id File id.
	 * @return bool False when there was no such file.
	 */
	public function delete( string $id ): bool {
		$meta = $this->meta( $id );
		$root = $this->root( false );
		if ( null === $meta || null === $root ) {
			return false;
		}
		wp_delete_file( $root . '/files/' . basename( (string) $meta['filename'] ) );
		foreach ( (array) ( $meta['attachments'] ?? array() ) as $attachment ) {
			if ( is_array( $attachment ) && ! empty( $attachment['filename'] ) ) {
				wp_delete_file( $root . '/files/' . basename( (string) $attachment['filename'] ) );
			}
		}
		wp_delete_file( $root . '/meta/' . $id . '.json' );
		return true;
	}

	/**
	 * Delete the files older than the retention time, and work files left behind by a run that stopped.
	 *
	 * @return int Files deleted.
	 */
	public function purgeExpired(): int {
		$now     = $this->clock->now()->format( 'Y-m-d\TH:i:s\Z' );
		$deleted = 0;
		foreach ( $this->list() as $file ) {
			if ( '' !== $file->expiresAt && strcmp( $file->expiresAt, $now ) <= 0 && $this->delete( $file->id ) ) {
				++$deleted;
			}
		}
		$root = $this->root( false );
		if ( null !== $root && is_dir( $root . '/tmp' ) ) {
			foreach ( (array) scandir( $root . '/tmp' ) as $name ) {
				$path = $root . '/tmp/' . $name;
				if ( is_string( $name ) && str_ends_with( $name, '.part' ) && is_file( $path ) && filemtime( $path ) < $this->clock->now()->getTimestamp() - 86400 ) {
					wp_delete_file( $path );
				}
			}
		}
		return $deleted;
	}

	/**
	 * Add or replace the row of one order in the batch of a connection. One small file per order, so two workers never overwrite each other.
	 *
	 * @param int                 $connectionId Connection.
	 * @param int                 $itemId       Queue item id.
	 * @param string              $orderId      WooCommerce order id.
	 * @param array<string,mixed> $row          The connector's row.
	 * @param string|null         $leftOut      Reason when the order is already known to be left out.
	 *
	 * @throws ExportException With SYNC-EXP-001 when the folder cannot be written.
	 */
	public function appendToBatch( int $connectionId, int $itemId, string $orderId, array $row, ?string $leftOut = null ): void {
		$root = $this->root( true );
		$dir  = null === $root ? '' : $root . '/batches/' . $connectionId;
		if ( null === $root || ! $this->ensure( $root . '/batches' ) || ! $this->ensure( $dir ) ) {
			throw ExportException::notWritable();
		}
		$json = wp_json_encode(
			array(
				'item_id'  => $itemId,
				'order'    => $orderId,
				'row'      => $row,
				'left_out' => $leftOut,
			)
		);
		$name = null !== $leftOut && '' !== $leftOut ? $itemId . '.left.json' : $itemId . '.json';
		$tmp  = $dir . '/' . $itemId . '.tmp';
		if ( false === $json || false === file_put_contents( $tmp, $json, LOCK_EX ) || ! rename( $tmp, $dir . '/' . $name ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw ExportException::notWritable();
		}
		wp_delete_file( $dir . '/' . ( $itemId . '.json' === $name ? $itemId . '.left.json' : $itemId . '.json' ) );
	}

	/**
	 * Whether an order is already in the batch of a connection (one file check, whatever the size of the batch).
	 *
	 * @param int $connectionId Connection.
	 * @param int $itemId       Queue item id.
	 */
	public function hasItem( int $connectionId, int $itemId ): bool {
		$root = $this->root( false );
		$dir  = null === $root ? '' : $root . '/batches/' . $connectionId;
		return null !== $root && ( is_file( $dir . '/' . $itemId . '.json' ) || is_file( $dir . '/' . $itemId . '.left.json' ) );
	}

	/**
	 * The batch waiting for the next file of a connection (empty when nothing waits). Rows are read from disk when asked, one at a time
	 * with `iterate()`, or all at once with `rows()` and `included()`.
	 *
	 * @param int $connectionId Connection.
	 */
	public function openBatch( int $connectionId ): ExportBatch {
		$root = $this->root( false );
		$dir  = null === $root ? '' : $root . '/batches/' . $connectionId;
		if ( null === $root || ! is_dir( $dir ) ) {
			return new ExportBatch( $connectionId );
		}
		$files  = array();
		$oldest = null;
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/^(\d+)(\.left)?\.json$/', $name, $m ) ) {
				$files[ (int) $m[1] ] = $dir . '/' . $name;
				$time                 = (int) filemtime( $dir . '/' . $name );
				$oldest               = null === $oldest ? $time : min( $oldest, $time );
			}
		}
		ksort( $files );
		$leftOut = array();
		foreach ( $files as $itemId => $path ) {
			if ( str_ends_with( $path, '.left.json' ) ) {
				$data = $this->readJson( $path );
				if ( is_array( $data ) ) {
					$leftOut[] = array(
						'item_id' => $itemId,
						'order'   => (string) ( $data['order'] ?? '' ),
						'reason'  => (string) ( $data['left_out'] ?? '' ),
					);
				}
			}
		}
		$source = function () use ( $files ): \Generator {
			foreach ( $files as $itemId => $path ) {
				$data = $this->readJson( $path );
				if ( is_array( $data ) ) {
					yield array(
						'item_id'  => $itemId,
						'order'    => (string) ( $data['order'] ?? '' ),
						'row'      => (array) ( $data['row'] ?? array() ),
						'left_out' => $data['left_out'] ?? null,
					);
				}
			}
		};
		return ExportBatch::backed( $connectionId, null === $oldest ? '' : gmdate( 'Y-m-d H:i:s', $oldest ), array_keys( $files ), $source, $leftOut );
	}

	/**
	 * Orders waiting and since when, for the Exports screen.
	 *
	 * @param int $connectionId Connection.
	 * @return array{connection_id:int,orders:int,since:string}|null Null when nothing waits.
	 */
	public function batchSummary( int $connectionId ): ?array {
		$batch = $this->openBatch( $connectionId );
		$total = count( $batch->includedIds() ) + count( $batch->leftOut() );
		if ( 0 === $total ) {
			return null;
		}
		return array(
			'connection_id' => $connectionId,
			'orders'        => $total,
			'since'         => '' === $batch->openedAt ? '' : str_replace( ' ', 'T', $batch->openedAt ) . 'Z',
		);
	}

	/**
	 * Remove the waiting rows of a connection after a file was made. Give the ids of the rows the file dealt with: a row added to the batch
	 * after the snapshot was taken is not in the list and stays for the next file. Without ids every row goes (erasing a connection's batch).
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<int,int>|null $itemIds      Only these rows, or all.
	 */
	public function closeBatch( int $connectionId, ?array $itemIds = null ): void {
		$root = $this->root( false );
		$dir  = null === $root ? '' : $root . '/batches/' . $connectionId;
		if ( null === $root || ! is_dir( $dir ) ) {
			return;
		}
		$wanted = null === $itemIds ? null : array_flip( $itemIds );
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/^(\d+)(\.left)?\.(json|tmp)$/', $name, $m ) && ( null === $wanted || isset( $wanted[ (int) $m[1] ] ) ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
	}

	/**
	 * Read and decode a small JSON file of the store.
	 *
	 * @param string $path File.
	 * @return array<string,mixed>|null
	 */
	private function readJson( string $path ): ?array {
		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = false === $raw ? null : json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * The protected folder, created when asked.
	 *
	 * @param bool $create Create it (and its protection files) if missing.
	 * @return string|null Path without trailing slash, or null when it does not exist or cannot be made.
	 */
	public function root( bool $create ): ?string {
		$base = (string) ( $this->baseDir )();
		if ( '' === $base || ! is_dir( $base ) ) {
			return null;
		}
		$parent = rtrim( $base, '/\\' ) . '/' . self::FOLDER;
		$found  = $this->randomFolder( $parent );
		if ( null !== $found ) {
			return $found;
		}
		if ( ! $create ) {
			return null;
		}
		if ( ! $this->ensure( dirname( $parent ) ) || ! $this->ensure( $parent ) ) {
			return null;
		}
		$mine = $parent . '/' . bin2hex( random_bytes( 16 ) );
		if ( ! $this->ensure( $mine ) ) {
			return null;
		}
		// Two requests may have created a folder at the same moment: everybody uses the smallest name, the other one is removed.
		$winner = $this->randomFolder( $parent );
		if ( null !== $winner && $winner !== $mine ) {
			$this->removeDir( $mine );
			return $winner;
		}
		return $mine;
	}

	/**
	 * Delete the whole export folder (used when the plugin's data is erased).
	 */
	public function eraseAll(): void {
		$base = (string) ( $this->baseDir )();
		if ( '' !== $base ) {
			$this->removeDir( rtrim( $base, '/\\' ) . '/' . self::FOLDER );
		}
	}

	/**
	 * The random folder inside the exports folder, if one exists.
	 *
	 * @param string $folder Exports folder.
	 */
	private function randomFolder( string $folder ): ?string {
		if ( ! is_dir( $folder ) ) {
			return null;
		}
		$names = array();
		foreach ( (array) scandir( $folder ) as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $name ) && is_dir( $folder . '/' . $name ) ) {
				$names[] = $name;
			}
		}
		sort( $names );
		return array() === $names ? null : $folder . '/' . $names[0];
	}

	/**
	 * Create a folder with its protection files.
	 *
	 * @param string $dir Path.
	 */
	private function ensure( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n<system.webServer>\n<security>\n<authorization>\n<remove users=\"*\" roles=\"\" verbs=\"\" />\n<add accessType=\"Deny\" users=\"*\" />\n</authorization>\n</security>\n</system.webServer>\n</configuration>\n",
		);
		foreach ( $files as $name => $text ) {
			if ( ! is_file( $dir . '/' . $name ) && false === file_put_contents( $dir . '/' . $name, $text ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return false;
			}
		}
		return true;
	}

	/**
	 * Read the details file of an export.
	 *
	 * @param string $id File id.
	 * @return array<string,mixed>|null
	 */
	private function meta( string $id ): ?array {
		$root = $this->root( false );
		if ( null === $root || 1 !== preg_match( '/^[a-f0-9]{16}$/', $id ) || ! is_file( $root . '/meta/' . $id . '.json' ) ) {
			return null;
		}
		$raw  = file_get_contents( $root . '/meta/' . $id . '.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = false === $raw ? null : json_decode( $raw, true );
		return is_array( $data ) && isset( $data['filename'], $data['connection_id'] ) ? $data : null;
	}

	/**
	 * Build an ExportFile from its details.
	 *
	 * @param array<string,mixed> $meta Details.
	 */
	private function fromMeta( array $meta ): ExportFile {
		$format = new ExportFormat( (string) ( $meta['extension'] ?? 'txt' ), (string) ( $meta['mime'] ?? 'application/octet-stream' ), (string) ( $meta['format_label'] ?? '' ) );
		$left   = array();
		foreach ( (array) ( $meta['left_out'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) ) {
				$left[] = array(
					'order'  => (string) ( $entry['order'] ?? '' ),
					'reason' => (string) ( $entry['reason'] ?? '' ),
				);
			}
		}
		return new ExportFile(
			'',
			$format,
			(int) ( $meta['orders'] ?? 0 ),
			$left,
			(string) $meta['id'],
			(int) $meta['connection_id'],
			(string) $meta['filename'],
			(int) ( $meta['size_bytes'] ?? 0 ),
			(string) ( $meta['created_at'] ?? '' ),
			(string) ( $meta['expires_at'] ?? '' ),
			(string) ( $meta['created_by'] ?? '' ),
			'',
			array_values( array_filter( (array) ( $meta['attachments'] ?? array() ), 'is_array' ) )
		);
	}

	/**
	 * Delete a folder and everything in it.
	 *
	 * @param string $dir Path inside the uploads folder.
	 */
	private function removeDir( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( ! is_string( $name ) || '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->removeDir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
