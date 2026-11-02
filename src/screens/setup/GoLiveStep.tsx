import { __, sprintf } from '@wordpress/i18n';
import type { Connection, Readiness } from '../../api/types';
import { Link } from '../../app/router';
import { Card } from '../../components/Card';
import { ChoiceGroup } from '../../components/Fields';
import { KeyValueList } from '../../components/KeyValueList';
import { systemName } from '../../lib/text';
import type { WhatAnswers } from './WhatStep';
import { summariseWhat } from './WhatStep';

export interface GoLiveStepProps {
	connection: Connection;
	what: WhatAnswers;
	readiness: Readiness | undefined;
	previewLabel: string | null;
	mode: 'shadow' | 'live';
	onMode: ( mode: 'shadow' | 'live' ) => void;
	onChange: ( step: number ) => void;
}

/**
 * Step 5, Ready to start: a summary of the choices, then shadow mode (recommended) or going live now.
 *
 * @param props              Choices so far and the chosen start mode.
 * @param props.connection
 * @param props.what
 * @param props.readiness
 * @param props.previewLabel
 * @param props.mode
 * @param props.onMode
 * @param props.onChange
 * @return The step.
 */
export function GoLiveStep( {
	connection,
	what,
	readiness,
	previewLabel,
	mode,
	onMode,
	onChange,
}: GoLiveStepProps ) {
	const system = systemName( connection );
	const summary = summariseWhat( what, system );
	const open = readiness
		? readiness.checks.filter( ( check ) => check.status !== 'passed' )
				.length
		: 0;
	return (
		<div className="estasoft-sync-stack">
			<Card
				title={ __( 'Ready to start', 'estasoft-erp-crm-sync-engine' ) }
			>
				<KeyValueList
					items={ [
						{
							label: __(
								'System',
								'estasoft-erp-crm-sync-engine'
							),
							value: (
								<span dir="auto">
									{ [ connection.version, connection.company ]
										.filter( Boolean )
										.join( ', ' ) }
								</span>
							),
						},
						{
							label: __(
								'Orders',
								'estasoft-erp-crm-sync-engine'
							),
							value: summary.orders,
						},
						{
							label: __(
								'Customers',
								'estasoft-erp-crm-sync-engine'
							),
							value: summary.customers,
						},
						...( connection.capabilities.stock_in
							? [
									{
										label: __(
											'Stock',
											'estasoft-erp-crm-sync-engine'
										),
										value: summary.stock,
									},
								]
							: [] ),
						...( connection.capabilities.tracking_in
							? [
									{
										label: __(
											'Tracking',
											'estasoft-erp-crm-sync-engine'
										),
										value: summary.tracking,
									},
								]
							: [] ),
						{
							label: __(
								'Store checks',
								'estasoft-erp-crm-sync-engine'
							),
							value:
								open > 0 ? (
									<Link
										to="/settings/readiness"
										className="estasoft-sync-link"
									>
										{ sprintf(
											/* translators: %d: number of notes */ __(
												'%d notes still open',
												'estasoft-erp-crm-sync-engine'
											),
											open
										) }
									</Link>
								) : (
									__(
										'All passed',
										'estasoft-erp-crm-sync-engine'
									)
								),
						},
						{
							label: __(
								'Mapping',
								'estasoft-erp-crm-sync-engine'
							),
							value: (
								<>
									{ previewLabel
										? sprintf(
												/* translators: 1: system name, 2: order label */ __(
													'%1$s presets, previewed on order %2$s',
													'estasoft-erp-crm-sync-engine'
												),
												system,
												previewLabel
											)
										: sprintf(
												/* translators: %s: system name */ __(
													'%s presets',
													'estasoft-erp-crm-sync-engine'
												),
												system
											) }{ ' ' }
									<Link
										to="/mapping/orders"
										className="estasoft-sync-link"
									>
										{ __(
											'Change',
											'estasoft-erp-crm-sync-engine'
										) }
									</Link>
								</>
							),
						},
					] }
				/>
				<p>
					<button
						type="button"
						className="estasoft-sync-linkbutton"
						onClick={ () => onChange( 3 ) }
					>
						{ __(
							'Change what syncs',
							'estasoft-erp-crm-sync-engine'
						) }
					</button>
				</p>
			</Card>
			<Card
				title={ __(
					'How do you want to start?',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				<ChoiceGroup
					legend={ __(
						'How do you want to start?',
						'estasoft-erp-crm-sync-engine'
					) }
					hideLegend
					value={ mode }
					onChange={ ( value ) =>
						onMode( value as 'shadow' | 'live' )
					}
					choices={ [
						{
							value: 'shadow',
							label: __(
								'Start in shadow mode for 7 days (recommended)',
								'estasoft-erp-crm-sync-engine'
							),
							description: sprintf(
								/* translators: 1: system name */
								__(
									'The plugin builds every request and sends nothing. Each night it compares the result with the sale orders your team enters in %1$s and lists the differences. Nothing changes in WooCommerce or %1$s. After day 7 we ask you whether to go live; nothing switches by itself.',
									'estasoft-erp-crm-sync-engine'
								),
								system
							),
						},
						{
							value: 'live',
							label: __(
								'Go live now',
								'estasoft-erp-crm-sync-engine'
							),
							description: sprintf(
								/* translators: %s: system name */
								__(
									'Orders are sent to %s as they arrive. Orders placed before now are not sent; you can send one by hand. You will be asked to confirm first.',
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
