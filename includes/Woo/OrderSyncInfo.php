<?php
/**
 * What the order meta says about an order's sync.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable snapshot read by OrderMeta. Times are UTC "Y-m-d H:i:s".
 */
final class OrderSyncInfo {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string                                        $state           queued, syncing, synced, retrying, failed, skipped, shadow, or an empty string when nothing was queued.
	 * @param string                                        $externalId      The system's id.
	 * @param string                                        $externalRef     The system's number, for example S00490.
	 * @param string                                        $externalUrl     Link to the record.
	 * @param string                                        $lastSync        When the last successful sync finished.
	 * @param int|null                                      $mappingVersion  Mapping version used at the last send.
	 * @param string                                        $invoiceNumber   Invoice number read back from the system.
	 * @param string                                        $invoiceStatus   Invoice or tax authority status read back.
	 * @param string                                        $invoicePdf      Link to the invoice PDF.
	 * @param string                                        $trackingNumber  Tracking number read back.
	 * @param string                                        $trackingCarrier Carrier read back.
	 * @param array{reason:string,by:string,at:string}|null $skip Why, by whom and when the order was skipped.
	 */
	public function __construct(
		public readonly string $state = '',
		public readonly string $externalId = '',
		public readonly string $externalRef = '',
		public readonly string $externalUrl = '',
		public readonly string $lastSync = '',
		public readonly ?int $mappingVersion = null,
		public readonly string $invoiceNumber = '',
		public readonly string $invoiceStatus = '',
		public readonly string $invoicePdf = '',
		public readonly string $trackingNumber = '',
		public readonly string $trackingCarrier = '',
		public readonly ?array $skip = null,
	) {
	}
}
