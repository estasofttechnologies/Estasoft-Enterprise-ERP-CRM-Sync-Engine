import { __ } from '@wordpress/i18n';

export type SectionId =
	| 'dashboard'
	| 'queue'
	| 'invoices'
	| 'connections'
	| 'mapping'
	| 'stock'
	| 'reconciliation'
	| 'logs'
	| 'settings';

export interface Section {
	id: SectionId;
	label: string;
	/** Route to open. */
	to: string;
}

/**
 * The nine sections, in the order of the tabs.
 *
 * @return Sections with translated labels.
 */
export function getSections(): Section[] {
	return [
		{
			id: 'dashboard',
			label: __( 'Dashboard', 'estasoft-erp-crm-sync-engine' ),
			to: '/',
		},
		{
			id: 'queue',
			label: __( 'Queue', 'estasoft-erp-crm-sync-engine' ),
			to: '/queue',
		},
		{
			id: 'invoices',
			label: __( 'Invoices', 'estasoft-erp-crm-sync-engine' ),
			to: '/invoices',
		},
		{
			id: 'connections',
			label: __( 'Connections', 'estasoft-erp-crm-sync-engine' ),
			to: '/connections',
		},
		{
			id: 'mapping',
			label: __( 'Mapping', 'estasoft-erp-crm-sync-engine' ),
			to: '/mapping',
		},
		{
			id: 'stock',
			label: __( 'Stock', 'estasoft-erp-crm-sync-engine' ),
			to: '/stock',
		},
		{
			id: 'reconciliation',
			label: __( 'Reconciliation', 'estasoft-erp-crm-sync-engine' ),
			to: '/reconciliation',
		},
		{
			id: 'logs',
			label: __( 'Logs', 'estasoft-erp-crm-sync-engine' ),
			to: '/logs',
		},
		{
			id: 'settings',
			label: __( 'Settings', 'estasoft-erp-crm-sync-engine' ),
			to: '/settings',
		},
	];
}

/**
 * Which section a route belongs to.
 *
 * @param segments Path segments of the route.
 * @return The section id, or null on the setup wizard.
 */
export function sectionOf( segments: string[] ): SectionId | null {
	const first = segments[ 0 ] ?? '';
	switch ( first ) {
		case '':
		case 'dashboard':
		case 'shadow':
			return 'dashboard';
		case 'setup':
			return null;
		case 'queue':
		case 'invoices':
		case 'connections':
		case 'mapping':
		case 'stock':
		case 'reconciliation':
		case 'logs':
		case 'settings':
			return first;
		default:
			return 'dashboard';
	}
}
