/**
 * Words for the groups on the Needs attention list. A group is one error code and one cause.
 */
import { __, sprintf } from '@wordpress/i18n';
import { ERROR_CATALOG } from '../api/errors';
import type { AttentionGroup } from '../api/types';
import { catalogMessage, fillPlaceholders, ordersPhrase } from './text';

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
 *
 * @param group  Attention group.
 * @param system Short system name.
 * @return Sentence.
 */
export function groupSentence( group: AttentionGroup, system: string ): string {
	const count = ordersPhrase( group.count );
	let detail: string;
	switch ( group.error_code ) {
		case 'SYNC-MAP-001':
			detail = sprintf(
				/* translators: 1: SKU, 2: system name */
				__(
					'product %1$s not found in %2$s',
					'estasoft-erp-crm-sync-engine'
				),
				causeValue( group.cause ),
				system
			);
			break;
		case 'SYNC-VAL-001':
			detail = group.cause
				? sprintf(
						/* translators: %s: country such as Saudi Arabia */
						__(
							'buyer tax number is not valid for %s',
							'estasoft-erp-crm-sync-engine'
						),
						group.cause
					)
				: __(
						'buyer tax number is not valid',
						'estasoft-erp-crm-sync-engine'
					);
			break;
		case 'SYNC-MAP-003':
			detail = __(
				'customer email is missing',
				'estasoft-erp-crm-sync-engine'
			);
			break;
		default:
			detail = groupTitle( group, system ).toLowerCase();
	}
	return sprintf(
		/* translators: 1: number of orders such as "3 orders", 2: what went wrong */
		__( '%1$s failed: %2$s', 'estasoft-erp-crm-sync-engine' ),
		count,
		detail
	);
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
