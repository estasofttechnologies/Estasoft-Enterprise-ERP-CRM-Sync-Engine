import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { Connection } from '../../api/types';
import { Link } from '../../app/router';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { CheckField, SelectField, TextField } from '../../components/Fields';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { errorText, systemName } from '../../lib/text';
import { savedFields } from '../connections/ConnectionDialogs';
import { AppliedNote } from './AppliedNote';
import type { WhatAnswers } from './what';

interface KindProps {
	connection: Connection;
	value: WhatAnswers;
	onChange: ( next: WhatAnswers ) => void;
}

interface Missing {
	key: string;
	title: string;
	text: string;
}

function NotOffered( { system, items }: { system: string; items: Missing[] } ) {
	if ( items.length === 0 ) {
		return null;
	}
	return (
		<Card
			title={ sprintf(
				/* translators: %s: system name */ __(
					'Not offered for %s',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				system
			) }
			flush
		>
			<ul className="estasoft-sync-attlist">
				{ items.map( ( entry ) => (
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
	);
}

/** WooCommerce payment methods the table asks about. */
function paymentRows(): { id: string; label: string; note: string }[] {
	return [
		{
			id: 'cod',
			label: __(
				'Cash on delivery',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			note: __(
				'Paid when delivered',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		},
		{
			id: 'card',
			label: __(
				'Card payments',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			note: '',
		},
		{
			id: 'bank',
			label: __(
				'Bank transfer',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			note: '',
		},
		{
			id: 'other',
			label: __(
				'Any other payment method',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			note: '',
		},
	];
}

/**
 * Step 3 for an accounting system (board B04b): what an order becomes, which account each payment
 * method is paid into, customers, refunds and the invoice link. Stock is offered only when the
 * system can be read for it.
 *
 * @param props            Connection, answers and handler.
 * @param props.connection
 * @param props.value
 * @param props.onChange
 * @return The step.
 */
export function AccountingWhat( { connection, value, onChange }: KindProps ) {
	const system = systemName( connection );
	const caps = connection.capabilities;
	const set = < K extends keyof WhatAnswers >(
		key: K,
		next: WhatAnswers[ K ]
	) => onChange( { ...value, [ key ]: next } );
	const notOffered: Missing[] = [];
	if ( ! caps.stock_in ) {
		notOffered.push( {
			key: 'stock',
			title: __(
				'Warehouses and stock',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			text: __(
				'An accounting system does not run your warehouse. Stock from the system is only offered when it tracks inventory, and then only to read quantities.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		} );
	}
	if ( ! caps.tracking_in || ! caps.prices_in ) {
		notOffered.push( {
			key: 'tracking',
			title: __(
				'Tracking numbers and prices',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			text: __(
				'Tracking numbers and prices stay in WooCommerce.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		} );
	}
	return (
		<div className="estasoft-sync-stack">
			<AppliedNote />
			<Card
				title={ sprintf(
					/* translators: %s: system name */ __(
						'What should sync to %s?',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				) }
			>
				{ caps.orders !== false ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ sprintf(
								/* translators: %s: system name */ __(
									'Orders in %s',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								system
							) }
						</legend>
						<SelectField
							label={ __(
								'Create',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
							value={ value.accounting_create }
							onChange={ ( next ) =>
								set(
									'accounting_create',
									next as WhatAnswers[ 'accounting_create' ]
								)
							}
							options={ [
								{
									value: 'invoice',
									label: __(
										'Invoice only',
										'estasoft-enterprise-erpcrm-sync-engine'
									),
								},
								{
									value: 'invoice_payment',
									label: __(
										'Invoice and payment',
										'estasoft-enterprise-erpcrm-sync-engine'
									),
								},
								{
									value: 'draft_invoice',
									label: __(
										'Draft invoice, for you to check',
										'estasoft-enterprise-erpcrm-sync-engine'
									),
								},
							] }
							hint={ sprintf(
								/* translators: %s: system name */ __(
									'%s issues the invoice. This plugin does not sign tax invoices.',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								system
							) }
						/>
					</fieldset>
				) : null }
				{ caps.orders !== false &&
				value.accounting_create === 'invoice_payment' ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ __(
								'Payments',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</legend>
						<p className="estasoft-sync-muted">
							{ sprintf(
								/* translators: 1: system name, 2: system name again */ __(
									'For each WooCommerce payment method, type the account in %1$s that the payment is paid into, exactly as %2$s shows it. A payment is recorded only for an order that is paid.',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								system,
								system
							) }
						</p>
						<ResponsiveTable<
							ReturnType< typeof paymentRows >[ number ],
							string
						>
							caption={ __(
								'Payment methods and the account each is paid into',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
							rows={ paymentRows() }
							rowKey={ ( row ) => row.id }
							columns={ [
								{
									id: 'method',
									header: __(
										'Payment method in WooCommerce',
										'estasoft-enterprise-erpcrm-sync-engine'
									),
									label: __(
										'Payment method',
										'estasoft-enterprise-erpcrm-sync-engine'
									),
									primary: true,
									cell: ( row ) => (
										<>
											<span className="estasoft-sync-strong">
												{ row.label }
											</span>
											{ row.note ? (
												<span className="estasoft-sync-muted estasoft-sync-block">
													{ row.note }
												</span>
											) : null }
										</>
									),
								},
								{
									id: 'account',
									header: sprintf(
										/* translators: %s: system name */ __(
											'Pay into this %s account',
											'estasoft-enterprise-erpcrm-sync-engine'
										),
										system
									),
									label: __(
										'Account',
										'estasoft-enterprise-erpcrm-sync-engine'
									),
									wide: true,
									cell: ( row ) => (
										<TextField
											hideLabel
											label={ sprintf(
												/* translators: 1: payment method, 2: system name */ __(
													'Account in %2$s for %1$s',
													'estasoft-enterprise-erpcrm-sync-engine'
												),
												row.label,
												system
											) }
											value={
												value.payment_accounts[
													row.id
												] ?? ''
											}
											onChange={ ( next ) =>
												set( 'payment_accounts', {
													...value.payment_accounts,
													[ row.id ]: next,
												} )
											}
											spellCheck={ false }
											autoComplete="off"
										/>
									),
								},
							] }
						/>
					</fieldset>
				) : null }
				{ caps.customers !== false ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ __(
								'Customers',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</legend>
						<SelectField
							label={ __(
								'When a customer is new',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
							value={
								value.customer_match === 'match_only'
									? 'match_only'
									: 'match_create'
							}
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
										'estasoft-enterprise-erpcrm-sync-engine'
									),
								},
								{
									value: 'match_only',
									label: __(
										'Only match, never create',
										'estasoft-enterprise-erpcrm-sync-engine'
									),
								},
							] }
						/>
					</fieldset>
				) : null }
				{ caps.refunds !== false ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ __(
								'Refunds',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</legend>
						<CheckField
							label={ __(
								'Send refunds as credit notes',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
							checked={ value.send_refunds }
							onChange={ ( next ) => set( 'send_refunds', next ) }
						/>
					</fieldset>
				) : null }
				{ caps.invoice_readback ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ __(
								'Invoice details',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</legend>
						<CheckField
							label={ __(
								'Save the invoice number and a link on the order',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
							hint={ __(
								'Shown in the order screen and in the Invoices tab.',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
							checked={ value.save_invoice_link }
							onChange={ ( next ) =>
								set( 'save_invoice_link', next )
							}
						/>
					</fieldset>
				) : null }
				{ caps.stock_in ? (
					<fieldset className="estasoft-sync-fieldset">
						<legend className="estasoft-sync-subtitle">
							{ sprintf(
								/* translators: %s: system name */ __(
									'Stock from %s',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								system
							) }
						</legend>
						<CheckField
							label={ __(
								'Update WooCommerce stock',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
							checked={ value.stock_in }
							onChange={ ( next ) => set( 'stock_in', next ) }
						/>
					</fieldset>
				) : null }
				<fieldset className="estasoft-sync-fieldset">
					<legend className="estasoft-sync-subtitle">
						{ __(
							'Tax',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</legend>
					<p>
						{ __(
							'Each order uses the',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }{ ' ' }
						<Link to="/mapping/tax" className="estasoft-sync-link">
							{ __(
								'tax mapping',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</Link>
						.
					</p>
				</fieldset>
			</Card>
			<NotOffered system={ system } items={ notOffered } />
			<Card
				title={ __(
					'When both sides change something',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				description={ __(
					'Choosing one winner per field stops the two systems from overwriting each other.',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			>
				{ caps.stock_in ? (
					<Callout tone="info">
						{ sprintf(
							/* translators: %s: system name */ __(
								'Stock quantity: %s wins. WooCommerce never writes stock to it.',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							system
						) }
					</Callout>
				) : null }
				<SelectField
					label={ __(
						'Customer address',
						'estasoft-enterprise-erpcrm-sync-engine'
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
								'estasoft-enterprise-erpcrm-sync-engine'
							),
						},
						{
							value: 'system',
							label: sprintf(
								/* translators: %s: system name */ __(
									'%s wins',
									'estasoft-enterprise-erpcrm-sync-engine'
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

/** WooCommerce order statuses the stage table asks about. */
function statusRows(): { id: string; label: string }[] {
	return [
		{
			id: 'pending',
			label: __(
				'Pending payment',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		},
		{
			id: 'processing',
			label: __( 'Processing', 'estasoft-enterprise-erpcrm-sync-engine' ),
		},
		{
			id: 'on-hold',
			label: __( 'On hold', 'estasoft-enterprise-erpcrm-sync-engine' ),
		},
		{
			id: 'failed',
			label: __(
				'Failed payment',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		},
		{
			id: 'completed',
			label: __( 'Completed', 'estasoft-enterprise-erpcrm-sync-engine' ),
		},
		{
			id: 'refunded',
			label: __( 'Refunded', 'estasoft-enterprise-erpcrm-sync-engine' ),
		},
		{
			id: 'cancelled',
			label: __( 'Cancelled', 'estasoft-enterprise-erpcrm-sync-engine' ),
		},
	];
}

/**
 * Step 3 for a CRM (board B04c): what a customer becomes, which pipeline the deals go to, how an
 * order shows up, and the stage for each order status. A pipeline is chosen through the connector's
 * own option when it has one; otherwise deals go to the system's default pipeline.
 *
 * @param props            Connection, answers and handler.
 * @param props.connection
 * @param props.value
 * @param props.onChange
 * @return The step.
 */
export function CrmWhat( { connection, value, onChange }: KindProps ) {
	const system = systemName( connection );
	const set = < K extends keyof WhatAnswers >(
		key: K,
		next: WhatAnswers[ K ]
	) => onChange( { ...value, [ key ]: next } );
	// The connector offers its pipeline as one of its own options (a connection setting).
	const pipelineField = savedFields( connection ).find(
		( field ) => ! field.secret && /pipeline/i.test( field.name )
	);
	const savePipeline = useAsyncAction( async ( next: string ) => {
		if ( pipelineField ) {
			await api.connections.update( connection.id, {
				config: { [ pipelineField.name ]: next },
			} );
		}
	} );
	const pipelineValue = value.crm_pipeline || pipelineField?.value || '';
	const setPipeline = ( next: string ) => set( 'crm_pipeline', next );
	return (
		<div className="estasoft-sync-stack">
			<AppliedNote />
			<Card
				title={ sprintf(
					/* translators: %s: system name */ __(
						'What should sync to %s?',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				) }
			>
				<fieldset className="estasoft-sync-fieldset">
					<legend className="estasoft-sync-subtitle">
						{ __(
							'Customers',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</legend>
					<SelectField
						label={ sprintf(
							/* translators: %s: system name */ __(
								'Create in %s',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							system
						) }
						value={ value.crm_contacts }
						onChange={ ( next ) =>
							set(
								'crm_contacts',
								next as WhatAnswers[ 'crm_contacts' ]
							)
						}
						options={ [
							{
								value: 'contact_company',
								label: __(
									'A contact for every customer, plus a company when filled',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
							},
							{
								value: 'contacts_only',
								label: __(
									'Contacts only',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
							},
						] }
					/>
					<CheckField
						label={ __(
							'Match by email, so repeat customers never become duplicates',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						checked={ value.crm_match_email }
						onChange={ ( next ) => set( 'crm_match_email', next ) }
					/>
				</fieldset>
				<fieldset className="estasoft-sync-fieldset">
					<legend className="estasoft-sync-subtitle">
						{ __(
							'Pipeline',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</legend>
					{ pipelineField ? (
						pipelineField.type === 'select' &&
						pipelineField.options ? (
							<SelectField
								label={ __(
									'Show deals in',
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
								value={ pipelineValue }
								onChange={ ( next ) => {
									setPipeline( next );
									void savePipeline.run( next );
								} }
								options={ Object.entries(
									pipelineField.options
								).map( ( [ optionValue, label ] ) => ( {
									value: optionValue,
									label,
								} ) ) }
								hint={ __(
									'Deal stages below come from this pipeline.',
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
							/>
						) : (
							<TextField
								label={ __(
									'Show deals in',
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
								value={ pipelineValue }
								onChange={ setPipeline }
								onBlur={ () =>
									void savePipeline.run( pipelineValue )
								}
								hint={
									pipelineField.hint ||
									__(
										'Type the pipeline as it is named in your system. Leave it empty for the default pipeline.',
										'estasoft-enterprise-erpcrm-sync-engine'
									)
								}
							/>
						)
					) : (
						<p>
							{ sprintf(
								/* translators: %s: system name */ __(
									'Deals go to the default pipeline of %s.',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								system
							) }
						</p>
					) }
					{ savePipeline.error ? (
						<Callout tone="danger" role="alert">
							{ errorText( savePipeline.error ) }
						</Callout>
					) : null }
				</fieldset>
				<fieldset className="estasoft-sync-fieldset">
					<legend className="estasoft-sync-subtitle">
						{ __(
							'Orders',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</legend>
					<SelectField
						label={ __(
							'Show each order as',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						value={ value.crm_order_as }
						onChange={ ( next ) =>
							set(
								'crm_order_as',
								next as WhatAnswers[ 'crm_order_as' ]
							)
						}
						options={ [
							{
								value: 'deal_per_order',
								label: __(
									'One deal per order',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
							},
							{
								value: 'deal_per_customer',
								label: __(
									'One deal per customer, updated each time',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
							},
							{
								value: 'note_only',
								label: __(
									'A note on the contact only',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
							},
						] }
					/>
				</fieldset>
				<fieldset className="estasoft-sync-fieldset">
					<legend className="estasoft-sync-subtitle">
						{ __(
							'Customer value',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</legend>
					<CheckField
						label={ __(
							'Keep these contact properties up to date',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						hint={ __(
							'Total spent, number of orders, date of the last order.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						checked={ value.crm_keep_value }
						onChange={ ( next ) => set( 'crm_keep_value', next ) }
					/>
				</fieldset>
				<fieldset className="estasoft-sync-fieldset">
					<legend className="estasoft-sync-subtitle">
						{ __(
							'Marketing',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</legend>
					<CheckField
						label={ __(
							'Respect marketing consent',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						hint={ __(
							'A contact is marked marketing-ready only if the customer ticked the box. Turning this off marks every customer, which can break marketing laws.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						checked={ value.crm_marketing_consent }
						onChange={ ( next ) =>
							set( 'crm_marketing_consent', next )
						}
					/>
				</fieldset>
			</Card>
			<Card
				title={ __(
					'Order status to deal stage',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				description={ sprintf(
					/* translators: %s: system name */ __(
						'Type the stage of your own pipeline, as it is named in %s. Leave a row empty to leave the deal where it is.',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					system
				) }
				flush
			>
				<ResponsiveTable<
					ReturnType< typeof statusRows >[ number ],
					string
				>
					caption={ __(
						'WooCommerce order status and the deal stage for it',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
					rows={ statusRows() }
					rowKey={ ( row ) => row.id }
					columns={ [
						{
							id: 'status',
							header: __(
								'WooCommerce order status',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							label: __(
								'Order status',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							primary: true,
							cell: ( row ) => (
								<span className="estasoft-sync-strong">
									{ row.label }
								</span>
							),
						},
						{
							id: 'stage',
							header: sprintf(
								/* translators: %s: system name */ __(
									'%s deal stage',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								system
							),
							label: __(
								'Deal stage',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							wide: true,
							cell: ( row ) => (
								<TextField
									hideLabel
									label={ sprintf(
										/* translators: 1: order status, 2: system name */ __(
											'%2$s deal stage for %1$s',
											'estasoft-enterprise-erpcrm-sync-engine'
										),
										row.label,
										system
									) }
									value={ value.crm_stages[ row.id ] ?? '' }
									onChange={ ( next ) =>
										set( 'crm_stages', {
											...value.crm_stages,
											[ row.id ]: next,
										} )
									}
									spellCheck={ false }
									autoComplete="off"
								/>
							),
						},
					] }
				/>
			</Card>
			<NotOffered
				system={ system }
				items={ [
					{
						key: 'inventory',
						title: __(
							'Stock, tracking numbers, tax invoices and refund documents',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						text: __(
							'A CRM does not hold inventory or issue tax invoices. A refund moves the deal to a refunded stage and adds a note.',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
					},
				] }
			/>
		</div>
	);
}
