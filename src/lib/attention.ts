/**
 * Words for the groups on the Needs attention list. A group is one error code and one cause.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { ERROR_CATALOG } from '../api/errors';
import type { AttentionGroup } from '../api/types';
import { catalogMessage, fillPlaceholders } from './text';

/**
 * Removes the leading label of a cause such as "SKU CS-BOWL-04".
 *
 * @param cause Cause text from the server.
 * @return The cause without a "SKU " prefix.
 */
export function causeValue( cause: string ): string {
	return cause.replace( /^SKU\s+/i, '' ).trim();
}

/**
 * Short title of a group, for example "Product not found in Odoo".
 *
 * @param group  Attention group.
 * @param system Short system name.
 * @return Title.
 */
export function groupTitle( group: AttentionGroup, system: string ): string {
	switch ( group.error_code ) {
		case 'SYNC-MAP-001':
			return sprintf(
				/* translators: %s: system name such as Odoo */
				__( 'Product not found in %s', 'estasoft-erp-crm-sync-engine' ),
				system
			);
		case 'SYNC-MAP-002':
			return __(
				'Tax code is not mapped',
				'estasoft-erp-crm-sync-engine'
			);
		case 'SYNC-MAP-003':
			return __(
				'Customer email is missing',
				'estasoft-erp-crm-sync-engine'
			);
		case 'SYNC-VAL-001':
			return __(
				'Buyer tax number is not valid',
				'estasoft-erp-crm-sync-engine'
			);
		case 'SYNC-VAL-002':
			return sprintf(
				/* translators: %s: system name such as Odoo */
				__( '%s rejected a field', 'estasoft-erp-crm-sync-engine' ),
				system
			);
		case 'SYNC-CFG-001':
			return __(
				'No live mapping version',
				'estasoft-erp-crm-sync-engine'
			);
		case 'SYNC-COMP-001':
			return __(
				'The tax authority rejected an invoice',
				'estasoft-erp-crm-sync-engine'
			);
		default: {
			const info = ERROR_CATALOG[ group.error_code ];
			return info
				? catalogMessage( group.error_code, {}, system )
				: group.error_code;
		}
	}
}

/**
 * One sentence for the dashboard, for example "3 orders failed: product CS-BOWL-04 not found in Odoo".
 * Each cause is a full sentence with the count as its plural driver, so a translation can agree the
 * verb with the number.
 *
 * @param group  Attention group.
 * @param system Short system name.
 * @return Sentence.
 */
export function groupSentence( group: AttentionGroup, system: string ): string {
	const n = group.count;
	switch ( group.error_code ) {
		case 'SYNC-MAP-001':
			return sprintf(
				/* translators: 1: number of orders, 2: SKU, 3: system name such as Odoo */
				_n(
					'%1$d order failed: product %2$s not found in %3$s',
					'%1$d orders failed: product %2$s not found in %3$s',
					n,
					'estasoft-erp-crm-sync-engine'
				),
				n,
				causeValue( group.cause ),
				system
			);
		case 'SYNC-VAL-001':
			return group.cause
				? sprintf(
						/* translators: 1: number of orders, 2: country such as Saudi Arabia */
						_n(
							'%1$d order failed: buyer tax number is not valid for %2$s',
							'%1$d orders failed: buyer tax number is not valid for %2$s',
							n,
							'estasoft-erp-crm-sync-engine'
						),
						n,
						group.cause
					)
				: sprintf(
						/* translators: %d: number of orders */
						_n(
							'%d order failed: buyer tax number is not valid',
							'%d orders failed: buyer tax number is not valid',
							n,
							'estasoft-erp-crm-sync-engine'
						),
						n
					);
		case 'SYNC-MAP-002':
			return sprintf(
				/* translators: %d: number of orders */
				_n(
					'%d order failed: tax code is not mapped',
					'%d orders failed: tax code is not mapped',
					n,
					'estasoft-erp-crm-sync-engine'
				),
				n
			);
		case 'SYNC-MAP-003':
			return sprintf(
				/* translators: %d: number of orders */
				_n(
					'%d order failed: customer email is missing',
					'%d orders failed: customer email is missing',
					n,
					'estasoft-erp-crm-sync-engine'
				),
				n
			);
		default:
			return sprintf(
				/* translators: 1: number of orders, 2: what went wrong, a short sentence */
				_n(
					'%1$d order failed: %2$s',
					'%1$d orders failed: %2$s',
					n,
					'estasoft-erp-crm-sync-engine'
				),
				n,
				groupTitle( group, system )
			);
	}
}

/**
 * What the person should do about a group, from the error catalog.
 *
 * @param group  Attention group.
 * @param system Short system name.
 * @return Sentence; empty when the code is unknown.
 */
export function groupRemedy( group: AttentionGroup, system: string ): string {
	const info = ERROR_CATALOG[ group.error_code ];
	if ( ! info ) {
		return '';
	}
	return fillPlaceholders( info.remedy, { system } );
}
