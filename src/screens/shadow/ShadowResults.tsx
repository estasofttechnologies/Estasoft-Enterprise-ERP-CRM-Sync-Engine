import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ShadowReport } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { navigate, routeTo } from '../../app/router';
import { useStatus, useSystemName } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { ConfirmDialog } from '../../components/Dialog';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { KpiGrid } from '../../components/Kpi';
import { PageHeader } from '../../components/PageHeader';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { useResource } from '../../hooks/useResource';
import { toCsv } from '../../lib/csv';
import { daysPhrase } from '../../lib/text';
import { downloadText } from '../../lib/download';

interface Cause {
	field: string;
	orders: ShadowReport[ 'rows' ];
}

function causesOf( report: ShadowReport ): Cause[] {
	const map = new Map< string, ShadowReport[ 'rows' ] >();
	for ( const row of report.rows ) {
		if ( row.result !== 'differs' ) {
			continue;
		}
		for ( const field of row.fields.length > 0
			? row.fields
			: [ __( 'Other', 'estasoft-enterprise-erpcrm-sync-engine' ) ] ) {
			map.set( field, [ ...( map.get( field ) ?? [] ), row ] );
		}
	}
	return [ ...map.entries() ]
		.map( ( [ field, orders ] ) => ( { field, orders } ) )
		.sort( ( a, b ) => b.orders.length - a.orders.length );
}

/**
 * Shadow mode results (C07): what would have been sent, compared with what the team entered.
 *
 * @return The screen.
 */
export function ShadowResults() {
	const { canConfigure } = useApp();
	const system = useSystemName();
	const { reload } = useStatus();
	const { notify } = useNotices();
	const report = useResource< ShadowReport >(
		( signal ) => api.shadow.report( signal ),
		[]
	);
	const [ dialog, setDialog ] = useState( false );
	const crumbs = [
		{
			label: __( 'Dashboard', 'estasoft-enterprise-erpcrm-sync-engine' ),
			to: '/',
		},
		{
			label: __(
				'Shadow mode',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		},
	];
	if ( report.loading ) {
		return (
			<>
				<PageHeader
					title={ __(
						'Shadow mode results',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
					breadcrumb={ crumbs }
				/>
				<LoadingState
					rows={ 4 }
					label={ __(
						'Loading the comparison',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				/>
			</>
		);
	}
	const data = report.data;
	if ( ! data ) {
		return (
			<>
				<PageHeader
					title={ __(
						'Shadow mode results',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
					breadcrumb={ crumbs }
				/>
				<ErrorState
					error={ report.error }
					onRetry={ () => void report.reload() }
				/>
			</>
		);
	}
	const causes = causesOf( data );
	const csv = () => {
		downloadText(
			'estasoft-sync-shadow-results.csv',
			toCsv( [
				[ 'order', 'result', 'fields' ],
				...data.rows.map( ( row ) => [
					row.order_label,
					row.result,
					row.fields.join( '; ' ),
				] ),
			] ),
			'text/csv'
		);
	};
	return (
		<>
			<PageHeader
				title={ __(
					'Shadow mode results',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				breadcrumb={ crumbs }
				description={ sprintf(
					/* translators: 1: day, 2: total days, 3: system name */ __(
						'Day %1$d of %2$d. Nothing has been sent to %3$s.',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					data.info.day,
					data.info.of_days,
					system
				) }
				actions={
					<Btn icon="download" onClick={ csv }>
						{ __(
							'Download as CSV',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</Btn>
				}
			/>
			<KpiGrid
				items={ [
					{
						label: __(
							'Compared',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						value: data.info.items,
						hint: sprintf(
							/* translators: %s: number of days such as "3 days" */
							__(
								'orders in %s',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							daysPhrase( data.info.day )
						),
					},
					{
						label: __(
							'Match',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						value: data.info.matches,
						hint: __(
							'identical',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
					},
					{
						label: __(
							'Differ',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						value: data.info.differs,
						hint: sprintf(
							/* translators: %d: number of causes */ _n(
								'in %d cause',
								'in %d causes',
								causes.length,
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							causes.length
						),
					},
					{
						label: sprintf(
							/* translators: %s: system name */ __(
								'Not in %s yet',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							system
						),
						value: data.info.not_found,
						hint: __(
							'expected in shadow mode',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
					},
				] }
			/>
			<Card
				title={ __(
					'Differences by cause',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				description={ __(
					'Matched by the reference WC- plus the order number, which your team copies into the system.',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				flush
			>
				{ causes.length === 0 ? (
					<EmptyState
						title={ __(
							'No differences',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					>
						{ __(
							'Every order that your team entered matches what the plugin would have sent.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</EmptyState>
				) : (
					<ResponsiveTable< Cause, string >
						caption={ __(
							'Differences by cause',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						rows={ causes }
						rowKey={ ( cause ) => cause.field }
						columns={ [
							{
								id: 'cause',
								header: __(
									'Cause',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								primary: true,
								cell: ( cause ) => (
									<span className="estasoft-sync-strong">
										{ cause.field }
									</span>
								),
							},
							{
								id: 'orders',
								header: __(
									'Orders',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								cell: ( cause ) => cause.orders.length,
							},
							{
								id: 'example',
								header: __(
									'Examples',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								wide: true,
								cell: ( cause ) =>
									cause.orders
										.slice( 0, 4 )
										.map( ( row ) => row.order_label )
										.join( ', ' ),
							},
							{
								id: 'open',
								header: __(
									'Open',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								hideHeader: true,
								align: 'end',
								cell: ( cause ) => (
									<Btn
										small
										onClick={ () =>
											navigate(
												routeTo(
													`/queue/${ cause.orders[ 0 ].queue_id }`
												)
											)
										}
										aria-label={ sprintf(
											/* translators: 1: number of orders, 2: cause */ __(
												'Open the first of %1$d orders: %2$s',
												'estasoft-enterprise-erpcrm-sync-engine'
											),
											cause.orders.length,
											cause.field
										) }
									>
										{ sprintf(
											/* translators: %d: number of orders */ _n(
												'Open %d order',
												'Open %d orders',
												cause.orders.length,
												'estasoft-enterprise-erpcrm-sync-engine'
											),
											cause.orders.length
										) }
									</Btn>
								),
							},
						] }
					/>
				) }
			</Card>
			<Card
				title={ __(
					'Ready to go live?',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			>
				<p>
					{ sprintf(
						/* translators: 1: day, 2: total days, 3: number of compared orders */
						_n(
							'Shadow mode has run for %1$d of %2$d days. You can go live now. Only new orders are sent; the %3$d compared order is not sent again.',
							'Shadow mode has run for %1$d of %2$d days. You can go live now. Only new orders are sent; the %3$d compared orders are not sent again.',
							data.info.items,
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						data.info.day,
						data.info.of_days,
						data.info.items
					) }
				</p>
				{ canConfigure ? (
					<div className="estasoft-sync-actions">
						<Btn
							variant="primary"
							onClick={ () => setDialog( true ) }
						>
							{ __(
								'Go live',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</Btn>
						<Btn onClick={ () => navigate( '/' ) }>
							{ __(
								'Keep comparing',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</Btn>
					</div>
				) : (
					<Callout tone="info">
						{ __(
							'Only an administrator can go live.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</Callout>
				) }
			</Card>
			{ dialog ? (
				<ConfirmDialog
					title={ __(
						'Go live now?',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
					confirmLabel={ __(
						'Go live',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
					onClose={ () => setDialog( false ) }
					onConfirm={ async () => {
						await api.onboarding.goLive( 'live' );
						await reload();
						notify( {
							kind: 'success',
							text: __(
								'You are live. New orders are sent as they arrive.',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
						} );
						navigate( '/' );
					} }
				>
					<p>
						{ sprintf(
							/* translators: %s: system name */ __(
								'New orders will be sent to %s as they arrive. Orders compared in shadow mode are not sent again.',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							system
						) }
					</p>
				</ConfirmDialog>
			) : null }
		</>
	);
}
