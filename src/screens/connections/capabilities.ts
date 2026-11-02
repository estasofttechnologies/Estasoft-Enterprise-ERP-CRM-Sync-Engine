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
		[ 'orders', __( 'Orders', 'estasoft-erp-crm-sync-engine' ) ],
		[ 'customers', __( 'customers', 'estasoft-erp-crm-sync-engine' ) ],
		[ 'refunds', __( 'refunds', 'estasoft-erp-crm-sync-engine' ) ],
		[ 'stock_in', __( 'stock', 'estasoft-erp-crm-sync-engine' ) ],
		[ 'tracking_in', __( 'tracking', 'estasoft-erp-crm-sync-engine' ) ],
		[ 'prices_in', __( 'prices', 'estasoft-erp-crm-sync-engine' ) ],
		[
			'invoice_readback',
			__( 'invoices', 'estasoft-erp-crm-sync-engine' ),
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
