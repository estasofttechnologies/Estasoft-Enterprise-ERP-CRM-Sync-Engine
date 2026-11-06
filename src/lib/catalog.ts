/**
 * Helpers for the catalog of 34 systems: ways to connect, filters, suggestions.
 */
import { __ } from '@wordpress/i18n';
import type { CatalogEntry, ConnectorType } from '../api/types';

export type CatalogFilter = 'all' | ConnectorType | 'uae';

/**
 * Name of a way to connect.
 *
 * @param method `connection_method` from the catalog.
 * @return Short label.
 */
export function methodLabel( method: string ): string {
	switch ( method ) {
		case 'key':
			return __(
				'Paste a key',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'own_app':
			return __(
				'Your own app, then sign in',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'cloud_app':
			return __(
				'Cloud app from your admin',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'server_login':
			return __(
				'Server sign-in',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'export_file':
			return __(
				'Export file',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'none':
			return __(
				'No public API yet',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		default:
			return __(
				'To be confirmed',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
	}
}

/**
 * What a way to connect asks of the person.
 *
 * @param method `connection_method` from the catalog.
 * @return One or two sentences.
 */
export function methodExplanation( method: string ): string {
	switch ( method ) {
		case 'key':
			return __(
				'You create a key inside the system and paste it here. No sign-in page.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'own_app':
			return __(
				'You create an app in the vendor’s developer area, paste its ID and secret, then sign in.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'cloud_app':
			return __(
				'Your company administrators create an app in the cloud directory. No sign-in page; the plugin uses the app’s own identity.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'server_login':
			return __(
				'An address, a company database, a user and a password, for a server that your website must be able to reach.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'export_file':
			return __(
				'A desktop program, or a link that only the vendor can approve. The plugin writes a file of your orders and you import it. Nothing runs outside WordPress.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'none':
			return __(
				'The vendor publishes no way to connect. Listed so you can see it is planned; it cannot be selected until the vendor opens an API.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		default:
			return __(
				'The way to connect depends on the version you run, or was not fully documented.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
	}
}

/**
 * Name of a system type.
 *
 * @param type Connector type.
 * @return Label.
 */
export function typeLabel( type: ConnectorType ): string {
	switch ( type ) {
		case 'erp':
			return __( 'ERP', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'accounting':
			return __( 'Accounting', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'crm':
			return __( 'CRM', 'estasoft-enterprise-erpcrm-sync-engine' );
	}
}

/**
 * Applies the filter chips and the search box.
 *
 * @param entries Catalog.
 * @param filter  Chip.
 * @param query   Search text.
 * @return Matching entries.
 */
export function filterCatalog(
	entries: CatalogEntry[],
	filter: CatalogFilter,
	query: string
): CatalogEntry[] {
	const needle = query.trim().toLowerCase();
	return entries.filter( ( entry ) => {
		if ( filter === 'uae' && ! /\bUAE\b/i.test( entry.regions ) ) {
			return false;
		}
		if ( filter !== 'all' && filter !== 'uae' && entry.type !== filter ) {
			return false;
		}
		if ( ! needle ) {
			return true;
		}
		return [
			entry.name,
			entry.regions,
			entry.needs,
			methodLabel( entry.connection_method ),
			typeLabel( entry.type ),
		].some( ( text ) => text.toLowerCase().includes( needle ) );
	} );
}

const CURRENCY_REGION: Record< string, { token: string; label: string } > = {
	AED: { token: 'UAE', label: 'the UAE' },
	SAR: { token: 'Saudi Arabia', label: 'Saudi Arabia' },
	GBP: { token: 'UK', label: 'the UK' },
	AUD: { token: 'Australia', label: 'Australia' },
	USD: { token: 'US', label: 'the US' },
};

/**
 * Picks up to three systems that suit a store, from the currency (the plugin does not guess more).
 *
 * @param entries  Catalog.
 * @param currency Store currency code.
 * @return Region label and suggested entries; empty when the currency says nothing.
 */
export function suggestFor(
	entries: CatalogEntry[],
	currency: string
): { region: string; entries: CatalogEntry[] } {
	const region = CURRENCY_REGION[ currency ];
	if ( ! region ) {
		return { region: '', entries: [] };
	}
	const matching = entries
		.filter(
			( entry ) =>
				entry.connection_method !== 'none' &&
				new RegExp( `\\b${ region.token }\\b`, 'i' ).test(
					entry.regions
				)
		)
		.sort(
			( a, b ) =>
				Number( b.available ) - Number( a.available ) ||
				a.wave - b.wave ||
				a.name.localeCompare( b.name )
		);
	return { region: region.label, entries: matching.slice( 0, 3 ) };
}

/**
 * First letter(s) for a letter tile.
 *
 * @param name System name.
 * @return One or two characters.
 */
export function tileLetter( name: string ): string {
	return name.trim().charAt( 0 ).toUpperCase();
}
