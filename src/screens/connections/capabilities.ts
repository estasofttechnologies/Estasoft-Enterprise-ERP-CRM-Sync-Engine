import { __ } from '@wordpress/i18n';

export type Capability = string;

/**
 * "Orders, customers, refunds, stock, tracking" from a capability map.
 *
 * @param capabilities Flags by capability id.
 * @return Comma separated words.
 */
export function usedFor( capabilities: Record< string, boolean > ): string {
	const words: [ string, string ][] = [
		[ 'orders', __( 'Orders', 'estasoft-enterprise-erpcrm-sync-engine' ) ],
		[
			'customers',
			__( 'customers', 'estasoft-enterprise-erpcrm-sync-engine' ),
		],
		[
			'refunds',
			__( 'refunds', 'estasoft-enterprise-erpcrm-sync-engine' ),
		],
		[ 'stock_in', __( 'stock', 'estasoft-enterprise-erpcrm-sync-engine' ) ],
		[
			'tracking_in',
			__( 'tracking', 'estasoft-enterprise-erpcrm-sync-engine' ),
		],
		[
			'prices_in',
			__( 'prices', 'estasoft-enterprise-erpcrm-sync-engine' ),
		],
		[
			'invoice_readback',
			__( 'invoices', 'estasoft-enterprise-erpcrm-sync-engine' ),
		],
	];
	const on = words
		.filter( ( [ key ] ) => capabilities[ key ] )
		.map( ( [ , word ] ) => word );
	return on.length > 0 ? on.join( ', ' ) : '—';
}

/**
 * Whole days from `now` until `iso`, rounded up; negative when past.
 *
 * @param iso ISO 8601 time.
 * @param now ISO 8601 reference.
 * @return Days.
 */
export function daysUntil( iso: string, now: string ): number {
	return Math.ceil(
		( new Date( iso ).getTime() - new Date( now ).getTime() ) / 86400000
	);
}
