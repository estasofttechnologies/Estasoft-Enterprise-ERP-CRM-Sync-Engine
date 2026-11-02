import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { Status } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { navigate } from '../../app/router';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { ConfirmDialog } from '../../components/Dialog';
import { useFormat } from '../../components/FormatContext';
import { Icon } from '../../components/Icon';
import { PageHeader } from '../../components/PageHeader';
import { useResource } from '../../hooks/useResource';
import { formatFullDateTime } from '../../lib/format';
import { systemName } from '../../lib/text';
import { Kpi } from '../../components/Kpi';
import { ConnectionCard } from './parts';

/**
 * Dashboard while shadow mode runs: counts of the comparison and four things to check before going live.
 *
 * @param props        Status from the server.
 * @param props.status
 * @return The screen.
 */
export function ShadowDashboard( { status }: { status: Status } ) {
	const { canConfigure } = useApp();
	const { reload } = useStatus();
	const { notify } = useNotices();
	const cfg = useFormat();
	const [ dialog, setDialog ] = useState< 'end' | 'live' | null >( null );
	const shadow = status.shadow;
	const system = systemName( status.connection );
	const unmatched = useResource(
		( signal ) => api.products.unmatched( 1, signal ),
		[]
	);
	const failuresInRow = status.connection?.failures_in_a_row ?? 0;
	const healthy =
		status.connection?.status === 'active' && failuresInRow === 0;

	if ( ! shadow ) {
		return (
			<>
				<PageHeader
					title={ __( 'Dashboard', 'estasoft-erp-crm-sync-engine' ) }
				/>
				<Callout tone="info">
					{ __(
						'Shadow mode is on. The comparison has not produced numbers yet.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			</>
		);
	}
	const finish = async ( mode: 'live' | 'end' ) => {
		if ( mode === 'live' ) {
			await api.onboarding.goLive( 'live' );
			notify( {
				kind: 'success',
				text: __(
					'You are live. New orders are sent to your system as they arrive.',
					'estasoft-erp-crm-sync-engine'
				),
			} );
		} else {
			await api.shadow.end();
			notify( {
				kind: 'info',
				text: __(
					'Shadow mode ended. Nothing was sent. Choose when to go live under Setup.',
					'estasoft-erp-crm-sync-engine'
				),
			} );
		}
		await reload();
	};
	const unmatchedCount = unmatched.data?.total ?? 0;
	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'estasoft-erp-crm-sync-engine' ) }
				description={ formatFullDateTime( status.now, cfg ) }
				actions={
					canConfigure ? (
						<Btn onClick={ () => setDialog( 'end' ) }>
							{ __(
								'End shadow mode',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					) : null
				}
			/>
			<Callout
				tone="info"
				action={
					<Btn
						variant="primary"
						small
						onClick={ () => navigate( '/shadow' ) }
					>
						{ __(
							'See the comparison',
							'estasoft-erp-crm-sync-engine'
						) }
					</Btn>
				}
			>
				{ sprintf(
					/* translators: 1: day of shadow mode, 2: total days, 3: system name */
					__(
						'Shadow mode, day %1$d of %2$d. Nothing is sent to %3$s. Each order is built exactly as it would be sent and compared with the sale orders your team enters in %3$s.',
						'estasoft-erp-crm-sync-engine'
					),
					shadow.day,
					shadow.of_days,
					system
				) }
			</Callout>
			<div className="estasoft-sync-grid estasoft-sync-kpis">
				<Kpi
					label={ __(
						'Orders compared',
						'estasoft-erp-crm-sync-engine'
					) }
					value={ shadow.items }
					hint={ __(
						'since shadow mode began',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<Kpi
					label={ __( 'Match', 'estasoft-erp-crm-sync-engine' ) }
					value={ shadow.matches }
					hint={ sprintf(
						/* translators: %s: system name */ __(
							'same as in %s',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
				/>
				<Kpi
					label={ __( 'Differ', 'estasoft-erp-crm-sync-engine' ) }
					value={ shadow.differs }
					hint={ __(
						'see the comparison',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<Kpi
					label={ sprintf(
						/* translators: %s: system name */ __(
							'Not in %s yet',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
					value={ shadow.not_found }
					hint={ __(
						'your team has not entered them',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</div>
			<div className="estasoft-sync-split">
				<div className="estasoft-sync-split__main">
					<Card
						title={ __(
							'Before you go live',
							'estasoft-erp-crm-sync-engine'
						) }
						description={ __(
							'Four things to check.',
							'estasoft-erp-crm-sync-engine'
						) }
						flush
					>
						<ul className="estasoft-sync-attlist">
							<li className="estasoft-sync-att">
								<Icon
									name={
										shadow.differs > 0
											? 'warning'
											: 'synced'
									}
									size={ 20 }
								/>
								<div className="estasoft-sync-att__text">
									<p className="estasoft-sync-strong">
										{ sprintf(
											/* translators: %d: number of orders or products */
											_n(
												'Review %d difference',
												'Review %d differences',
												shadow.differs,
												'estasoft-erp-crm-sync-engine'
											),
											shadow.differs
										) }
									</p>
									<p className="estasoft-sync-muted">
										{ __(
											'Check that the rules do what you want.',
											'estasoft-erp-crm-sync-engine'
										) }
									</p>
								</div>
								<Btn
									small
									onClick={ () => navigate( '/shadow' ) }
								>
									{ __(
										'Open the comparison',
										'estasoft-erp-crm-sync-engine'
									) }
								</Btn>
							</li>
							<li className="estasoft-sync-att">
								<Icon
									name={
										unmatchedCount > 0
											? 'warning'
											: 'synced'
									}
									size={ 20 }
								/>
								<div className="estasoft-sync-att__text">
									<p className="estasoft-sync-strong">
										{ sprintf(
											/* translators: %d: number of orders or products */
											_n(
												'Fix %d product without a match',
												'Fix %d products without a match',
												unmatchedCount,
												'estasoft-erp-crm-sync-engine'
											),
											unmatchedCount
										) }
									</p>
									<p className="estasoft-sync-muted">
										{ __(
											'Products without a matching SKU cannot be matched in your system.',
											'estasoft-erp-crm-sync-engine'
										) }
									</p>
								</div>
								<Btn
									small
									onClick={ () =>
										navigate( '/mapping/products' )
									}
								>
									{ __(
										'View the products',
										'estasoft-erp-crm-sync-engine'
									) }
								</Btn>
							</li>
							<li className="estasoft-sync-att">
								<Icon
									name={ healthy ? 'synced' : 'warning' }
									size={ 20 }
								/>
								<div className="estasoft-sync-att__text">
									<p className="estasoft-sync-strong">
										{ healthy
											? __(
													'Connection healthy',
													'estasoft-erp-crm-sync-engine'
												)
											: __(
													'Check the connection',
													'estasoft-erp-crm-sync-engine'
												) }
									</p>
									<p className="estasoft-sync-muted">
										{ healthy
											? __(
													'No failures in the last days.',
													'estasoft-erp-crm-sync-engine'
												)
											: __(
													'The connection has had trouble. Open it to test it.',
													'estasoft-erp-crm-sync-engine'
												) }
									</p>
								</div>
							</li>
							<li className="estasoft-sync-att">
								<Icon name="info" size={ 20 } />
								<div className="estasoft-sync-att__text">
									<p className="estasoft-sync-strong">
										{ __(
											'Go live when you are ready',
											'estasoft-erp-crm-sync-engine'
										) }
									</p>
									<p className="estasoft-sync-muted">
										{ __(
											'You can end shadow mode early. Only new orders are sent; nothing from shadow mode is back-filled.',
											'estasoft-erp-crm-sync-engine'
										) }
									</p>
								</div>
								{ canConfigure ? (
									<Btn
										small
										onClick={ () => setDialog( 'live' ) }
									>
										{ __(
											'Go live',
											'estasoft-erp-crm-sync-engine'
										) }
									</Btn>
								) : null }
							</li>
						</ul>
					</Card>
				</div>
				<div className="estasoft-sync-split__side">
					<ConnectionCard status={ status } />
				</div>
			</div>
			{ dialog === 'live' ? (
				<ConfirmDialog
					title={ __(
						'Go live now?',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setDialog( null ) }
					confirmLabel={ __(
						'Go live',
						'estasoft-erp-crm-sync-engine'
					) }
					onConfirm={ () => finish( 'live' ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: system name */ __(
								'New orders will be sent to %s as they arrive. Orders compared in shadow mode are not sent again.',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					</p>
				</ConfirmDialog>
			) : null }
			{ dialog === 'end' ? (
				<ConfirmDialog
					title={ __(
						'End shadow mode?',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setDialog( null ) }
					confirmLabel={ __(
						'End shadow mode',
						'estasoft-erp-crm-sync-engine'
					) }
					onConfirm={ () => finish( 'end' ) }
				>
					<p>
						{ __(
							'The comparison stops. Nothing has been sent, and nothing is sent now. You can start shadow mode again or go live from the setup guide.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</ConfirmDialog>
			) : null }
		</>
	);
}
