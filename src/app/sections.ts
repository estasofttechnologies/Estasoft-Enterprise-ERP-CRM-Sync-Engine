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
			label: __( 'Dashboard', 'estasoft-enterprise-erpcrm-sync-engine' ),
			to: '/',
		},
		{
			id: 'queue',
			label: __( 'Queue', 'estasoft-enterprise-erpcrm-sync-engine' ),
			to: '/queue',
		},
		{
			id: 'invoices',
			label: __( 'Invoices', 'estasoft-enterprise-erpcrm-sync-engine' ),
			to: '/invoices',
		},
		{
			id: 'connections',
			label: __(
				'Connections',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			to: '/connections',
		},
		{
			id: 'mapping',
			label: __( 'Mapping', 'estasoft-enterprise-erpcrm-sync-engine' ),
			to: '/mapping',
		},
		{
			id: 'stock',
			label: __( 'Stock', 'estasoft-enterprise-erpcrm-sync-engine' ),
			to: '/stock',
		},
		{
			id: 'reconciliation',
			label: __(
				'Reconciliation',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			to: '/reconciliation',
		},
		{
			id: 'logs',
			label: __( 'Logs', 'estasoft-enterprise-erpcrm-sync-engine' ),
			to: '/logs',
		},
		{
			id: 'settings',
			label: __( 'Settings', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
