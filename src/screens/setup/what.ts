import { __, sprintf } from '@wordpress/i18n';
import type { ConnectorType } from '../../api/types';

/** What the person chose on step 3. Stored in `Onboarding.answers.what`. */
export interface WhatAnswers {
	send_orders: boolean;
	send_when: 'paid_or_cod' | 'placed' | 'completed';
	documents: 'order' | 'order_invoice' | 'order_invoice_payment';
	customer_match: 'match_create' | 'match_only' | 'always_create';
	send_refunds: boolean;
	stock_in: boolean;
	tracking_in: boolean;
	prices_in: boolean;
	winner_status: 'woo_until_shipped' | 'system' | 'woo';
	winner_address: 'woo' | 'system';
	/** Accounting: what an order becomes. Payment is off by default: it needs an account for each payment method. */
	accounting_create: 'invoice_payment' | 'invoice' | 'draft_invoice';
	/** Accounting: the account in the system that each WooCommerce payment method is paid into, as typed. */
	payment_accounts: Record< string, string >;
	/** Accounting: save the invoice number and a link on the order. */
	save_invoice_link: boolean;
	/** CRM: what a new customer becomes. */
	crm_contacts: 'contact_company' | 'contacts_only';
	crm_match_email: boolean;
	/** CRM: the pipeline for deals, as the connector's own option holds it ('' is the default pipeline). */
	crm_pipeline: string;
	/** CRM: how an order shows up. */
	crm_order_as: 'deal_per_order' | 'deal_per_customer' | 'note_only';
	crm_keep_value: boolean;
	crm_marketing_consent: boolean;
	/** CRM: the pipeline stage for each WooCommerce order status, as typed. */
	crm_stages: Record< string, string >;
}

export const DEFAULT_WHAT: WhatAnswers = {
	send_orders: true,
	send_when: 'paid_or_cod',
	documents: 'order_invoice',
	customer_match: 'match_create',
	send_refunds: true,
	stock_in: true,
	tracking_in: true,
	prices_in: false,
	winner_status: 'woo_until_shipped',
	winner_address: 'woo',
	accounting_create: 'invoice',
	payment_accounts: {},
	save_invoice_link: true,
	crm_contacts: 'contact_company',
	crm_match_email: true,
	crm_pipeline: '',
	crm_order_as: 'deal_per_order',
	crm_keep_value: true,
	crm_marketing_consent: true,
	crm_stages: {},
};

/**
 * Merges stored answers over the defaults, ignoring anything of the wrong type.
 *
 * @param stored Value from the server.
 * @return Complete answers.
 */
export function readWhat( stored: unknown ): WhatAnswers {
	const result: WhatAnswers = { ...DEFAULT_WHAT };
	if ( stored && typeof stored === 'object' ) {
		for ( const key of Object.keys(
			DEFAULT_WHAT
		) as ( keyof WhatAnswers )[] ) {
			const value = ( stored as Record< string, unknown > )[ key ];
			if ( typeof value !== typeof DEFAULT_WHAT[ key ] ) {
				continue;
			}
			if ( key === 'payment_accounts' || key === 'crm_stages' ) {
				// A table of typed text: keep the entries that are text.
				const table: Record< string, string > = {};
				Object.entries( value as Record< string, unknown > ).forEach(
					( [ name, entry ] ) => {
						if ( typeof entry === 'string' ) {
							table[ name ] = entry;
						}
					}
				);
				result[ key ] = table;
				continue;
			}
			( result as unknown as Record< string, unknown > )[ key ] = value;
		}
	}
	return result;
}

/**
 * Plain words for a chosen setting, for the final summary.
 *
 * @param what   Answers.
 * @param system System name.
 * @param type   Type of the connected system, which decides the wording of the orders and customers lines.
 * @return Lines for the summary.
 */
export function summariseWhat(
	what: WhatAnswers,
	system: string,
	type: ConnectorType = 'erp'
): { orders: string; customers: string; stock: string; tracking: string } {
	const when: Record< WhatAnswers[ 'send_when' ], string > = {
		paid_or_cod: __(
			'Sent when paid, or when placed with cash on delivery.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		placed: __(
			'Sent when placed.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		completed: __(
			'Sent when completed.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
	};
	const docs: Record< WhatAnswers[ 'documents' ], string > = {
		order: __(
			'Sale order only.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		order_invoice: __(
			'Sale order and invoice.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		order_invoice_payment: __(
			'Sale order, invoice and payment.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
	};
	const customers: Record< WhatAnswers[ 'customer_match' ], string > = {
		match_create: __(
			'Matched by email, created if missing',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		match_only: __(
			'Matched by email, never created',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		always_create: __(
			'A new contact for every order',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
	};
	const accounting: Record< WhatAnswers[ 'accounting_create' ], string > = {
		invoice_payment: __(
			'Invoice and payment.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		invoice: __(
			'Invoice only.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		draft_invoice: __(
			'Draft invoice, for you to check.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
	};
	const deals: Record< WhatAnswers[ 'crm_order_as' ], string > = {
		deal_per_order: __(
			'One deal for each order.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		deal_per_customer: __(
			'One deal for each customer, updated each time.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		note_only: __(
			'A note on the contact only.',
			'estasoft-enterprise-erpcrm-sync-engine'
		),
	};
	if ( type === 'accounting' ) {
		return {
			orders: accounting[ what.accounting_create ],
			customers:
				what.customer_match === 'match_only'
					? customers.match_only
					: customers.match_create,
			stock: what.stock_in
				? sprintf(
						/* translators: %s: system name */ __(
							'From %s',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						system
					)
				: __( 'Not synced', 'estasoft-enterprise-erpcrm-sync-engine' ),
			tracking: __(
				'Not synced',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		};
	}
	if ( type === 'crm' ) {
		return {
			orders: deals[ what.crm_order_as ],
			customers:
				what.crm_contacts === 'contacts_only'
					? __(
							'Contacts only',
							'estasoft-enterprise-erpcrm-sync-engine'
						)
					: __(
							'A contact for every customer, plus a company when filled',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
			stock: __( 'Not synced', 'estasoft-enterprise-erpcrm-sync-engine' ),
			tracking: __(
				'Not synced',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		};
	}
	return {
		orders: what.send_orders
			? `${ when[ what.send_when ] } ${ docs[ what.documents ] }`
			: __( 'Not sent', 'estasoft-enterprise-erpcrm-sync-engine' ),
		customers: customers[ what.customer_match ],
		stock: what.stock_in
			? sprintf(
					/* translators: %s: system name */ __(
						'From %s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				)
			: __( 'Not synced', 'estasoft-enterprise-erpcrm-sync-engine' ),
		tracking: what.tracking_in
			? sprintf(
					/* translators: %s: system name */ __(
						'From %s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				)
			: __( 'Not synced', 'estasoft-enterprise-erpcrm-sync-engine' ),
	};
}
