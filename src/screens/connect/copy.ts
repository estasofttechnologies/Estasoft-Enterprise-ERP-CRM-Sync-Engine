/**
 * Words of the connect screens that depend on the system. Odoo keeps its own hand-written text from
 * the design (boards B03); every other system gets text built from what the catalog says about it
 * (`needs`, `steps`, `developer_url`) and from its type.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { ConnectorField, SystemEntry } from '../../api/local-types';
import type { ConnectShape } from '../../lib/connect';

/**
 * The connect form of Odoo, for a catalog answer that does not carry the fields yet.
 *
 * @return The four fields of the Odoo form.
 */
export function odooFields(): ConnectorField[] {
	return [
		{
			name: 'address',
			label: __(
				'Odoo address',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			secret: false,
			required: true,
			hint: __(
				'The web address you use to sign in to Odoo.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			default: '',
			type: 'url',
		},
		{
			name: 'database',
			label: __( 'Database', 'estasoft-enterprise-erpcrm-sync-engine' ),
			secret: false,
			required: true,
			hint: __(
				'Shown on Odoo’s sign-in page.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			default: '',
		},
		{
			name: 'user',
			label: __(
				'Sync user (email)',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			secret: false,
			required: false,
			hint: __(
				'A dedicated Odoo user for syncing. Odoo 17 and 18 need it with the key. Odoo 19 and later use the key alone, and this field can stay empty.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			default: '',
		},
		{
			name: 'api_key',
			label: __( 'API key', 'estasoft-enterprise-erpcrm-sync-engine' ),
			secret: true,
			required: true,
			hint: __(
				'Saved encrypted. It is never shown again.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			default: '',
			type: 'password',
		},
	];
}

/**
 * The standard fields of a connector that the catalog answer did not describe: a pasted key, or the
 * ID and secret of a person's own app. These are the names every connector of the shared base uses.
 * Any other way to connect has no standard form, so it is explained instead of guessed at.
 *
 * @param entry Catalog row.
 * @return The standard fields, or an empty list.
 */
function standardFields( entry: SystemEntry ): ConnectorField[] {
	const base = { required: true, hint: '', default: '' };
	if ( entry.connection_method === 'key' ) {
		return [
			{
				...base,
				name: 'api_key',
				label: __(
					'API key',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				secret: true,
				type: 'password',
				hint: __(
					'Saved encrypted. It is never shown again.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			},
		];
	}
	if ( entry.connection_method === 'own_app' ) {
		return [
			{
				...base,
				name: 'client_id',
				label: __(
					'Client ID',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				secret: false,
				group: 'app',
			},
			{
				...base,
				name: 'client_secret',
				label: __(
					'Client secret',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				secret: true,
				type: 'password',
				group: 'app',
				hint: __(
					'Saved encrypted. It is never shown again.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			},
		];
	}
	return [];
}

/**
 * The connect form of a system: what the catalog carries, Odoo's own for Odoo, or the standard
 * fields when the catalog answer has no form (an older server).
 *
 * @param entry Catalog row, with the product chosen.
 * @return The fields; empty for a system with no form to draw.
 */
export function fieldsOf( entry: SystemEntry ): ConnectorField[] {
	if ( entry.credential_fields && entry.credential_fields.length > 0 ) {
		return entry.credential_fields;
	}
	if ( entry.connector === 'odoo' ) {
		return odooFields();
	}
	return entry.credential_fields ? [] : standardFields( entry );
}

/**
 * The numbered steps of a system: the catalog's, or Odoo's own.
 *
 * @param entry Catalog row.
 * @return At most six steps.
 */
export function stepsOf( entry: SystemEntry ): string[] {
	if ( entry.steps && entry.steps.length > 0 ) {
		return entry.steps;
	}
	if ( entry.connector === 'odoo' ) {
		return [
			__(
				'In Odoo, open Settings, then Users, and create a user just for syncing. Give it access to Sales and Inventory.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			__(
				'Open that user’s profile, go to Account Security, and create an API key. Odoo shows it once and asks for an end date, at most 3 months away. We remind you before it ends.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			__(
				'The database name is on Odoo’s sign-in page. Paste the address, database, user and key below.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		];
	}
	return [];
}

/**
 * What the system needs before it lets this plugin in, said before the steps.
 *
 * @param entry Catalog row.
 * @return The sentence.
 */
export function planNoteOf( entry: SystemEntry ): string {
	if ( entry.connector === 'odoo' ) {
		return __(
			'Odoo Online needs the Custom plan. The free One App plan and the Standard plan do not allow outside programs. Odoo.sh and your own Odoo server have no such limit. If the key is refused with a plan message, this is the reason.',
			'estasoft-enterprise-erpcrm-sync-engine'
		);
	}
	return entry.needs;
}

/**
 * Lines for "What to expect from {system}".
 *
 * @param entry Catalog row.
 * @return The lines.
 */
export function expectationsOf( entry: SystemEntry ): string[] {
	if ( entry.connector === 'odoo' ) {
		return [
			__(
				'Orders become sale orders and invoices, and refunds become credit notes. Tracking numbers need the Odoo Delivery module.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			__(
				'Odoo asks outside programs to make about one call a second, with none at the same time. The plugin keeps to that, so a large backlog empties slowly.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			__(
				'In Saudi Arabia, the sales journal must be onboarded with ZATCA inside Odoo before invoices are posted. That is done by hand in Odoo, and cannot be undone once Production starts.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			__(
				'Odoo 17 and 18 and Odoo 19 and later use different connection methods. The plugin detects which one you have.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		];
	}
	const lines: string[] = [];
	if ( entry.type === 'accounting' ) {
		lines.push(
			sprintf(
				/* translators: %s: system name */
				__(
					'Orders can be sent to %s as invoices, and refunds as credit notes. Stock and tracking numbers come back only if the system offers them to read.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				entry.name
			)
		);
	} else if ( entry.type === 'crm' ) {
		lines.push(
			sprintf(
				/* translators: %s: system name */
				__(
					'Customers can become contacts in %s, and each order can show as a deal. Stock, tracking numbers and tax invoices are not part of a CRM.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				entry.name
			)
		);
	} else {
		lines.push(
			sprintf(
				/* translators: %s: system name */
				__(
					'Orders, customers and refunds can be sent to %s. Stock and tracking numbers come back only if the system offers them to read.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				entry.name
			)
		);
	}
	lines.push(
		__(
			'The plugin keeps to the call limits the system documents, so a busy hour is slower, not lost. Orders wait in the queue until they are sent.',
			'estasoft-enterprise-erpcrm-sync-engine'
		)
	);
	lines.push(
		__(
			'Your system issues and reports tax invoices. This plugin does not sign them.',
			'estasoft-enterprise-erpcrm-sync-engine'
		)
	);
	return lines;
}

/**
 * Titles and the button label of the guide card for a way to connect.
 *
 * @param shape  Way to connect.
 * @param system System name.
 * @return Texts.
 */
export function guideTexts( shape: ConnectShape, system: string ) {
	switch ( shape ) {
		case 'own_app':
			return {
				title: sprintf(
					/* translators: %s: system name */
					__(
						'Step 1: create your own %s app',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				),
				copyLabel: __(
					'Copy these steps for my accountant',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				formTitle: __(
					'Your app’s details',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			};
		case 'cloud_app':
			return {
				title: __(
					'Steps for your administrators',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				copyLabel: __(
					'Copy the steps for my administrators',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				formTitle: __(
					'Paste the details',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			};
		case 'server_login':
			return {
				title: __(
					'Before you start',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				copyLabel: __(
					'Copy the steps for my IT team',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				formTitle: sprintf(
					/* translators: %s: system name */
					__(
						'Connect to %s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				),
			};
		case 'export_file':
			return {
				title: __(
					'How it works',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				copyLabel: __(
					'Copy these steps for my accountant',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				formTitle: __(
					'Settings for this connection',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
			};
		default:
			return {
				title: __(
					'Where do I find these?',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				copyLabel:
					system === 'Odoo'
						? __(
								'Copy these steps for your Odoo administrator',
								'estasoft-enterprise-erpcrm-sync-engine'
							)
						: sprintf(
								/* translators: %s: system name */
								__(
									'Copy these steps for my %s administrator',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								system
							),
				formTitle: sprintf(
					/* translators: %s: system name */
					__(
						'Connect to %s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				),
			};
	}
}
