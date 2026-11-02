<?php
/**
 * The four invoice statuses the Invoices screen knows.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Systems word the state of an invoice toward a tax authority in many ways. The screen has four words: waiting, rejected,
 * reported and not_required. This class does the translation, and says which statuses are final (never polled again).
 */
final class InvoiceStatus {

	public const WAITING      = 'waiting';
	public const REJECTED     = 'rejected';
	public const REPORTED     = 'reported';
	public const NOT_REQUIRED = 'not_required';

	/**
	 * All four, in the order the filter shows them.
	 */
	public const ALL = array( self::WAITING, self::REJECTED, self::REPORTED, self::NOT_REQUIRED );

	/**
	 * Words the connectors and systems are known to use.
	 */
	private const SYNONYMS = array(
		'waiting'      => self::WAITING,
		'pending'      => self::WAITING,
		'to_send'      => self::WAITING,
		'queued'       => self::WAITING,
		'submitted'    => self::WAITING,
		'rejected'     => self::REJECTED,
		'failed'       => self::REJECTED,
		'error'        => self::REJECTED,
		'reported'     => self::REPORTED,
		'cleared'      => self::REPORTED,
		'accepted'     => self::REPORTED,
		'approved'     => self::REPORTED,
		'sent'         => self::REPORTED,
		'not_required' => self::NOT_REQUIRED,
		'none'         => self::NOT_REQUIRED,
	);

	/**
	 * Translate the system's authority status.
	 *
	 * @param string $authority The word the connector returned.
	 * @param string $number    The invoice number ('' when the invoice is still a draft).
	 */
	public static function from( string $authority, string $number ): string {
		$key = strtolower( trim( str_replace( array( ' ', '-' ), '_', $authority ) ) );
		if ( isset( self::SYNONYMS[ $key ] ) ) {
			return self::SYNONYMS[ $key ];
		}
		return '' === $key || '' === $number ? self::NOT_REQUIRED : self::WAITING;
	}

	/**
	 * Whether the invoice needs no more polling: reported, or not required for a posted invoice.
	 *
	 * @param string $status Status word.
	 * @param string $number Invoice number.
	 */
	public static function isFinal( string $status, string $number ): bool {
		return self::REPORTED === $status || ( self::NOT_REQUIRED === $status && '' !== $number );
	}
}
