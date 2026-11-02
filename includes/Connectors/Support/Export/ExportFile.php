<?php
/**
 * An export file: what finish() builds, and what the store keeps.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

/**
 * A connector's finish() returns `new ExportFile( contents: $text, format: $this->exportFormat() )` for a file that fits in memory, or
 * `ExportFile::fromPath( $this->deps->exports->workFile(), $format )` after writing the file to a stream (ten thousand orders never sit in memory).
 * A second file the person may download with the first (a list of customers and items to import before the orders) goes into `attachments`.
 * The store fills in the rest when it writes the file; the REST layer shows the result as ExportFileInfo.
 */
final class ExportFile {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                                       $contents     File contents; empty when `$path` is used and once stored.
	 * @param ExportFormat                                 $format       Format.
	 * @param int                                          $orders       Number of orders in the file (the service sets it from the batch).
	 * @param array<int,array{order:string,reason:string}> $leftOut      Orders left out and why.
	 * @param string                                       $id           Random id used in the download address.
	 * @param int                                          $connectionId Connection.
	 * @param string                                       $filename     File name on disk and in the download.
	 * @param int                                          $sizeBytes    Size in bytes.
	 * @param string                                       $createdAt    ISO 8601 time, UTC.
	 * @param string                                       $expiresAt    ISO 8601 time, UTC.
	 * @param string                                       $createdBy    Who made it (display name), empty when a background task did.
	 * @param string                                       $path         A finished file on disk (made with ExportStore::workFile()); the store moves it into place.
	 * @param array<int,array<string,mixed>>               $attachments  More files: before storing `name`, `filename`, `mime`, `contents` (or `path`); after storing `name`, `filename`, `mime`, `size_bytes`.
	 */
	public function __construct(
		public readonly string $contents,
		public readonly ExportFormat $format,
		public readonly int $orders = 0,
		public readonly array $leftOut = array(),
		public readonly string $id = '',
		public readonly int $connectionId = 0,
		public readonly string $filename = '',
		public readonly int $sizeBytes = 0,
		public readonly string $createdAt = '',
		public readonly string $expiresAt = '',
		public readonly string $createdBy = '',
		public readonly string $path = '',
		public readonly array $attachments = array(),
	) {
	}

	/**
	 * A file that was written to disk in pieces.
	 *
	 * @param string                         $path        Path from ExportStore::workFile().
	 * @param ExportFormat                   $format      Format.
	 * @param array<int,array<string,mixed>> $attachments More files (see the constructor).
	 */
	public static function fromPath( string $path, ExportFormat $format, array $attachments = array() ): self {
		return new self( '', $format, 0, array(), '', 0, '', 0, '', '', '', $path, $attachments );
	}

	/**
	 * The file as stored: details filled in and the contents dropped.
	 *
	 * @param string                                       $id           Id.
	 * @param int                                          $connectionId Connection.
	 * @param string                                       $filename     File name.
	 * @param int                                          $sizeBytes    Size.
	 * @param string                                       $createdAt    ISO time.
	 * @param string                                       $expiresAt    ISO time.
	 * @param string                                       $createdBy    Display name.
	 * @param int                                          $orders       Orders in the file.
	 * @param array<int,array{order:string,reason:string}> $leftOut      Orders left out.
	 * @param array<int,array<string,mixed>>               $attachments  Stored attachments (name, filename, mime, size_bytes).
	 */
	public function stored( string $id, int $connectionId, string $filename, int $sizeBytes, string $createdAt, string $expiresAt, string $createdBy, int $orders, array $leftOut, array $attachments = array() ): self {
		return new self( '', $this->format, $orders, $leftOut, $id, $connectionId, $filename, $sizeBytes, $createdAt, $expiresAt, $createdBy, '', $attachments );
	}

	/**
	 * The shape the REST layer returns (ExportFileInfo in src/api/types.ts).
	 *
	 * @return array{id:string,connection_id:int,filename:string,size_bytes:int,orders:int,created_at:string,expires_at:string,created_by:string,format_label:string,left_out:array<int,array{order:string,reason:string}>,attachments:array<int,array{name:string,filename:string,size_bytes:int}>}
	 */
	public function info(): array {
		$attachments = array();
		foreach ( $this->attachments as $attachment ) {
			$attachments[] = array(
				'name'       => (string) ( $attachment['name'] ?? '' ),
				'filename'   => (string) ( $attachment['filename'] ?? '' ),
				'size_bytes' => (int) ( $attachment['size_bytes'] ?? 0 ),
			);
		}
		return array(
			'id'            => $this->id,
			'connection_id' => $this->connectionId,
			'filename'      => $this->filename,
			'size_bytes'    => $this->sizeBytes,
			'orders'        => $this->orders,
			'created_at'    => $this->createdAt,
			'expires_at'    => $this->expiresAt,
			'created_by'    => $this->createdBy,
			'format_label'  => $this->format->label,
			'left_out'      => $this->leftOut,
			'attachments'   => $attachments,
		);
	}
}
