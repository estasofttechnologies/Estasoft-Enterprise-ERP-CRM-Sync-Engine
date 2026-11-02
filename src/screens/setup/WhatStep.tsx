import { __, sprintf } from '@wordpress/i18n';
import type { Connection } from '../../api/types';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { CheckField, SelectField } from '../../components/Fields';
import { systemName } from '../../lib/text';
import type { WhatAnswers } from './what';
import { AccountingWhat, CrmWhat } from './WhatStepKinds';

export type { WhatAnswers } from './what';
export { DEFAULT_WHAT, readWhat, summariseWhat } from './what';

export interface WhatStepProps {
	connection: Connection;
	value: WhatAnswers;
	onChange: ( next: WhatAnswers ) => void;
}

/**
 * Step 3, What should sync? An accounting system, a CRM and an ERP each get their own questions
 * (boards B04b, B04c, B04). Everything shown depends on what the connected system can do; the
 * rest is listed under "Not offered".
 *
 * @param props            Connection, answers and handler.
 * @param props.connection
 * @param props.value
 * @param props.onChange
 * @return The step.
 */
export function WhatStep( props: WhatStepProps ) {
	if ( props.connection.type === 'accounting' ) {
		return <AccountingWhat { ...props } />;
	}
	if ( props.connection.type === 'crm' ) {
		return <CrmWhat { ...props } />;
	}
	return <ErpWhat { ...props } />;
}

/**
 * The questions for an ERP or business suite.
 *
 * @param props            Connection, answers and handler.
 * @param props.connection
 * @param props.value
 * @param props.onChange
 * @return The step.
 */
function ErpWhat( { connection, value, onChange }: WhatStepProps ) {
	const system = systemName( connection );
	const caps = connection.capabilities;
	const set = < K extends keyof WhatAnswers >(
		key: K,
		next: WhatAnswers[ K ]
	) => onChange( { ...value, [ key ]: next } );
	const warehouses = connection.warehouses
		.map( ( warehouse ) => warehouse.name )
		.join( ', ' );

	const notOffered: { key: string; title: string; text: string }[] = [];
	if ( caps.stock_in === false ) {
		notOffered.push( {
			key: 'stock',
			title: __( 'Stock', 'estasoft-erp-crm-sync-engine' ),
			text: sprintf(
				/* translators: %s: system name */ __(
					'%s does not give the plugin stock quantities to read, so WooCommerce stock stays as it is.',
					'estasoft-erp-crm-sync-engine'
				),
				system
			),
		} );
	}
	if ( caps.tracking_in === false ) {
		notOffered.push( {
			key: 'tracking',
			title: __( 'Tracking numbers', 'estasoft-erp-crm-sync-engine' ),
			text: sprintf(
				/* translators: %s: system name */ __(
					'%s does not give the plugin tracking numbers to read. Tracking stays in WooCommerce.',
					'estasoft-erp-crm-sync-engine'
				),
				system
			),
		} );
	}
	if ( caps.prices_in === false ) {
		notOffered.push( {
			key: 'prices',
			title: __( 'Prices', 'estasoft-erp-crm-sync-engine' ),
			text: __(
				'Prices stay in WooCommerce.',
				'estasoft-erp-crm-sync-engine'
			),
		} );
	}
	if ( caps.refunds === false ) {
		notOffered.push( {
			key: 'refunds',
			title: __( 'Refunds', 'estasoft-erp-crm-sync-engine' ),
			text: sprintf(
				/* translators: %s: system name */ __(
					'Refunds are not sent to %s.',
					'estasoft-erp-crm-sync-engine'
				),
				system
			),
		} );
	}

	return (
		<div className="estasoft-sync-stack">
			<Card
				title={ sprintf(
					/* translators: %s: system name */ __(
						'What should sync to %s?',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			>
				{ caps.orders !== false ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ sprintf(
								/* translators: %s: system name */ __(
									'Orders to %s',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						</legend>
						<CheckField
							label={ __(
								'Send orders',
								'estasoft-erp-crm-sync-engine'
							) }
							hint={ sprintf(
								/* translators: %s: system name */ __(
									'Each order becomes a sale order in %s.',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
							checked={ value.send_orders }
							onChange={ ( next ) => set( 'send_orders', next ) }
						/>
						<SelectField
							label={ __(
								'Send when',
								'estasoft-erp-crm-sync-engine'
							) }
							value={ value.send_when }
							onChange={ ( next ) =>
								set(
									'send_when',
									next as WhatAnswers[ 'send_when' ]
								)
							}
							disabled={ ! value.send_orders }
							options={ [
								{
									value: 'paid_or_cod',
									label: __(
										'An order is paid, or placed with cash on delivery',
										'estasoft-erp-crm-sync-engine'
									),
								},
								{
									value: 'placed',
									label: __(
										'An order is placed',
										'estasoft-erp-crm-sync-engine'
									),
								},
								{
									value: 'completed',
									label: __(
										'An order is completed',
										'estasoft-erp-crm-sync-engine'
									),
								},
							] }
						/>
						<SelectField
							label={ sprintf(
								/* translators: %s: system name */ __(
									'Documents in %s',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
							value={ value.documents }
							onChange={ ( next ) =>
								set(
									'documents',
									next as WhatAnswers[ 'documents' ]
								)
							}
							disabled={ ! value.send_orders }
							options={ [
								{
									value: 'order',
									label: __(
										'Sale order only',
										'estasoft-erp-crm-sync-engine'
									),
								},
								{
									value: 'order_invoice',
									label: __(
										'Sale order and invoice',
										'estasoft-erp-crm-sync-engine'
									),
								},
								{
									value: 'order_invoice_payment',
									label: __(
										'Sale order, invoice and payment',
										'estasoft-erp-crm-sync-engine'
									),
								},
							] }
							hint={ sprintf(
								/* translators: %s: system name */ __(
									'Your %s issues and reports tax invoices. This plugin does not sign them.',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						/>
					</fieldset>
				) : null }
				{ caps.customers !== false ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ __(
								'Customers',
								'estasoft-erp-crm-sync-engine'
							) }
						</legend>
						<SelectField
							label={ __(
								'When a customer is new',
								'estasoft-erp-crm-sync-engine'
							) }
							value={ value.customer_match }
							onChange={ ( next ) =>
								set(
									'customer_match',
									next as WhatAnswers[ 'customer_match' ]
								)
							}
							options={ [
								{
									value: 'match_create',
									label: __(
										'Match by email, create if missing',
										'estasoft-erp-crm-sync-engine'
									),
								},
								{
									value: 'match_only',
									label: __(
										'Only match, never create',
										'estasoft-erp-crm-sync-engine'
									),
								},
								{
									value: 'always_create',
									label: __(
										'Always create a new contact',
										'estasoft-erp-crm-sync-engine'
									),
								},
							] }
						/>
					</fieldset>
				) : null }
				{ caps.refunds !== false ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ __( 'Refunds', 'estasoft-erp-crm-sync-engine' ) }
						</legend>
						<CheckField
							label={ __(
								'Send refunds as credit notes',
								'estasoft-erp-crm-sync-engine'
							) }
							checked={ value.send_refunds }
							onChange={ ( next ) => set( 'send_refunds', next ) }
						/>
					</fieldset>
				) : null }
				{ caps.stock_in ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ sprintf(
								/* translators: %s: system name */ __(
									'Stock from %s',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						</legend>
						<CheckField
							label={ __(
								'Update WooCommerce stock',
								'estasoft-erp-crm-sync-engine'
							) }
							hint={
								warehouses
									? sprintf(
											/* translators: %s: warehouse names */ __(
												'Warehouses: %s.',
												'estasoft-erp-crm-sync-engine'
											),
											warehouses
										)
									: undefined
							}
							checked={ value.stock_in }
							onChange={ ( next ) => set( 'stock_in', next ) }
						/>
					</fieldset>
				) : null }
				{ caps.tracking_in ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ sprintf(
								/* translators: %s: system name */ __(
									'Tracking from %s',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						</legend>
						<CheckField
							label={ __(
								'Update order status and tracking numbers',
								'estasoft-erp-crm-sync-engine'
							) }
							checked={ value.tracking_in }
							onChange={ ( next ) => set( 'tracking_in', next ) }
						/>
					</fieldset>
				) : null }
				{ caps.prices_in ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ sprintf(
								/* translators: %s: system name */ __(
									'Prices from %s',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						</legend>
						<CheckField
							label={ __(
								'Update WooCommerce prices',
								'estasoft-erp-crm-sync-engine'
							) }
							hint={ sprintf(
								/* translators: %s: system name */ __(
									'Leave off unless %s is your price list.',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
							checked={ value.prices_in }
							onChange={ ( next ) => set( 'prices_in', next ) }
						/>
					</fieldset>
				) : null }
			</Card>

			{ notOffered.length > 0 ? (
				<Card
					title={ sprintf(
						/* translators: %s: system name */ __(
							'Not offered for %s',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
					flush
				>
					<ul className="estasoft-sync-attlist">
						{ notOffered.map( ( entry ) => (
							<li key={ entry.key } className="estasoft-sync-att">
								<div className="estasoft-sync-att__text">
									<p className="estasoft-sync-strong">
										{ entry.title }
									</p>
									<p className="estasoft-sync-muted">
										{ entry.text }
									</p>
								</div>
							</li>
						) ) }
					</ul>
				</Card>
			) : null }

			<Card
				title={ __(
					'When both sides change something',
					'estasoft-erp-crm-sync-engine'
				) }
				description={ __(
					'Choosing one winner per field stops the two systems from overwriting each other.',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				{ caps.stock_in ? (
					<Callout tone="info">
						{ sprintf(
							/* translators: %s: system name */ __(
								'Stock quantity: %s wins. WooCommerce never writes stock to it.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					</Callout>
				) : null }
				{ caps.tracking_in ? (
					<SelectField
						label={ __(
							'Order status',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ value.winner_status }
						onChange={ ( next ) =>
							set(
								'winner_status',
								next as WhatAnswers[ 'winner_status' ]
							)
						}
						options={ [
							{
								value: 'woo_until_shipped',
								label: sprintf(
									/* translators: %s: system name */ __(
										'WooCommerce until shipped, then %s',
										'estasoft-erp-crm-sync-engine'
									),
									system
								),
							},
							{
								value: 'woo',
								label: __(
									'WooCommerce wins',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: 'system',
								label: sprintf(
									/* translators: %s: system name */ __(
										'%s wins',
										'estasoft-erp-crm-sync-engine'
									),
									system
								),
							},
						] }
					/>
				) : null }
				<SelectField
					label={ __(
						'Customer address',
						'estasoft-erp-crm-sync-engine'
					) }
					value={ value.winner_address }
					onChange={ ( next ) =>
						set(
							'winner_address',
							next as WhatAnswers[ 'winner_address' ]
						)
					}
					options={ [
						{
							value: 'woo',
							label: __(
								'WooCommerce wins',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							value: 'system',
							label: sprintf(
								/* translators: %s: system name */ __(
									'%s wins',
									'estasoft-erp-crm-sync-engine'
								),
								system
							),
						},
					] }
				/>
			</Card>
		</div>
	);
}
