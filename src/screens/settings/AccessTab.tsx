import { __ } from '@wordpress/i18n';
import { useApp } from '../../app/AppContext';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { StatusBadge } from '../../components/StatusBadge';

/**
 * Who can do what: the two capabilities and what each allows. Shows what the current person has.
 *
 * @return The tab.
 */
export function AccessTab() {
	const { canManage, canConfigure } = useApp();
	const yes = (
		<StatusBadge tone="success">
			{ __( 'Yes', 'estasoft-erp-crm-sync-engine' ) }
		</StatusBadge>
	);
	const no = (
		<StatusBadge tone="neutral">
			{ __( 'No', 'estasoft-erp-crm-sync-engine' ) }
		</StatusBadge>
	);
	return (
		<div className="estasoft-sync-stack">
			<Card
				title={ __(
					'Who can do what',
					'estasoft-erp-crm-sync-engine'
				) }
				description={ __(
					'Two capabilities do the work: estasoft_sync_manage (view and operate) and estasoft_sync_configure (configure).',
					'estasoft-erp-crm-sync-engine'
				) }
				flush
			>
				<table className="estasoft-sync-plain-table estasoft-sync-plain-table--wide">
					<caption className="estasoft-sync-sr-only">
						{ __(
							'Roles and what they can do',
							'estasoft-erp-crm-sync-engine'
						) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Role', 'estasoft-erp-crm-sync-engine' ) }
							</th>
							<th scope="col">
								{ __(
									'View and operate',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<th scope="col">
								{ __(
									'Configure',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<th scope="row">
								{ __(
									'Administrator',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<td>{ yes }</td>
							<td>{ yes }</td>
						</tr>
						<tr>
							<th scope="row">
								{ __(
									'Shop manager',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<td>{ yes }</td>
							<td>{ no }</td>
						</tr>
						<tr>
							<th scope="row">
								{ __(
									'Other roles',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<td>{ no }</td>
							<td>{ no }</td>
						</tr>
					</tbody>
				</table>
			</Card>
			<Card
				title={ __(
					'What each one allows',
					'estasoft-erp-crm-sync-engine'
				) }
				flush
			>
				<table className="estasoft-sync-plain-table estasoft-sync-plain-table--wide">
					<caption className="estasoft-sync-sr-only">
						{ __(
							'What each capability allows',
							'estasoft-erp-crm-sync-engine'
						) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __(
									'Capability',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
							<th scope="col">
								{ __(
									'Allows',
									'estasoft-erp-crm-sync-engine'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<th scope="row">
								{ __(
									'View and operate',
									'estasoft-erp-crm-sync-engine'
								) }
								<span className="estasoft-sync-mono estasoft-sync-block">
									estasoft_sync_manage
								</span>
							</th>
							<td>
								{ __(
									'See the dashboard, queue, invoices, stock, reconciliation and logs. See connections, mapping and settings, read only. Retry, skip, restore, pause, resume and sync now. Accept or hold a stock conflict. Repair or ignore a reconciliation difference. Run the readiness check. Copy a support report.',
									'estasoft-erp-crm-sync-engine'
								) }
							</td>
						</tr>
						<tr>
							<th scope="row">
								{ __(
									'Configure',
									'estasoft-erp-crm-sync-engine'
								) }
								<span className="estasoft-sync-mono estasoft-sync-block">
									estasoft_sync_configure
								</span>
							</th>
							<td>
								{ __(
									'Add, edit, test and disconnect connections. Edit mapping, tax and stock rules. Publish and roll back mapping versions. End shadow mode and go live. Change settings. Export, import, clear logs, delete data, run the capacity test.',
									'estasoft-erp-crm-sync-engine'
								) }
							</td>
						</tr>
					</tbody>
				</table>
			</Card>
			<Callout tone="info">
				{ __(
					'The saved API key is never shown to anyone, including administrators. It can only be replaced. A shop manager can open a connection page but sees its address and user as text, with no buttons to change them.',
					'estasoft-erp-crm-sync-engine'
				) }
			</Callout>
			<Callout tone={ canConfigure ? 'success' : 'info' }>
				{ canConfigure
					? __(
							'Your account can view, operate and configure.',
							'estasoft-erp-crm-sync-engine'
						)
					: canManage
						? __(
								'Your account can view and operate. It cannot configure.',
								'estasoft-erp-crm-sync-engine'
							)
						: __(
								'Your account has no access to ESTASOFT SYNC.',
								'estasoft-erp-crm-sync-engine'
							) }
			</Callout>
		</div>
	);
}
