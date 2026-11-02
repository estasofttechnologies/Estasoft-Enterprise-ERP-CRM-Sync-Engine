import { __, sprintf } from '@wordpress/i18n';
import type { Status } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { navigate } from '../../app/router';
import { Btn } from '../../components/Btn';
import { Card } from '../../components/Card';
import { DateTime } from '../../components/DateTime';
import type { KeyValueItem } from '../../components/KeyValueList';
import { KeyValueList } from '../../components/KeyValueList';
import { Meter } from '../../components/Meter';
import { StateChip } from '../../components/StateChip';
import { formatDuration } from '../../lib/format';
import { systemName } from '../../lib/text';

/**
 * The connection card shared by the normal and shadow dashboards.
 *
 * @param props          Status and handlers.
 * @param props.status
 * @param props.onCheck
 * @param props.checking
 * @return The card.
 */
export function ConnectionCard( {
	status,
	onCheck,
	checking,
}: {
	status: Status;
	onCheck?: () => void;
	checking?: boolean;
} ) {
	const { canConfigure } = useApp();
	const connection = status.connection;
	if ( ! connection ) {
		return null;
	}
	const pausedByCircuit =
		connection.paused_reason === 'circuit' ||
		connection.status === 'paused_circuit';
	const items: KeyValueItem[] = [
		{
			label: __( 'System', 'estasoft-erp-crm-sync-engine' ),
			value: connection.version || connection.label,
		},
		{
			label: __( 'Address', 'estasoft-erp-crm-sync-engine' ),
			value: <span dir="ltr">{ connection.address }</span>,
		},
	];
	if ( status.mode === 'shadow' ) {
		items.push( {
			label: __( 'Mode', 'estasoft-erp-crm-sync-engine' ),
			value: (
				<StateChip
					state="queued"
					label={ __( 'Shadow', 'estasoft-erp-crm-sync-engine' ) }
				/>
			),
		} );
	} else if ( connection.status !== 'active' ) {
		items.push( {
			label: __( 'State', 'estasoft-erp-crm-sync-engine' ),
			value: (
				<StateChip
					state="paused"
					label={
						pausedByCircuit
							? sprintf(
									/* translators: %s: system name */ __(
										'Paused: %s is not answering',
										'estasoft-erp-crm-sync-engine'
									),
									systemName( connection )
								)
							: __( 'Paused', 'estasoft-erp-crm-sync-engine' )
					}
				/>
			),
		} );
	}
	items.push(
		{
			label: __( 'Response time', 'estasoft-erp-crm-sync-engine' ),
			value:
				connection.typical_response_ms === null
					? '—'
					: sprintf(
							/* translators: %s: duration such as 340 ms */ __(
								'%s typical',
								'estasoft-erp-crm-sync-engine'
							),
							formatDuration( connection.typical_response_ms )
						),
		},
		{
			label: __( 'Last success', 'estasoft-erp-crm-sync-engine' ),
			value: <DateTime value={ connection.last_success_at } />,
		},
		{
			label: __( 'Last failure', 'estasoft-erp-crm-sync-engine' ),
			value: <DateTime value={ connection.last_failure_at } />,
		}
	);
	return (
		<Card title={ __( 'Connection', 'estasoft-erp-crm-sync-engine' ) }>
			<KeyValueList items={ items } stacked />
			<div className="estasoft-sync-actions">
				{ onCheck && canConfigure ? (
					<Btn small busy={ checking } onClick={ onCheck }>
						{ __(
							'Test connection',
							'estasoft-erp-crm-sync-engine'
						) }
					</Btn>
				) : null }
				<Btn
					small
					onClick={ () =>
						navigate( `/connections/${ connection.id }` )
					}
				>
					{ __( 'Open connection', 'estasoft-erp-crm-sync-engine' ) }
				</Btn>
			</div>
		</Card>
	);
}

/**
 * Four bars for the queue states.
 *
 * @param props        Status.
 * @param props.status
 * @return The meters.
 */
export function QueueMeters( { status }: { status: Status } ) {
	const counts = status.by_state;
	const max = Math.max(
		1,
		counts.queued,
		counts.syncing,
		counts.retrying,
		counts.failed
	);
	return (
		<div className="estasoft-sync-meters">
			<Meter
				label={ __( 'Queued', 'estasoft-erp-crm-sync-engine' ) }
				value={ counts.queued }
				max={ max }
				tone="queued"
			/>
			<Meter
				label={ __( 'Syncing', 'estasoft-erp-crm-sync-engine' ) }
				value={ counts.syncing }
				max={ max }
			/>
			<Meter
				label={ __( 'Retrying', 'estasoft-erp-crm-sync-engine' ) }
				value={ counts.retrying }
				max={ max }
				tone="retrying"
			/>
			<Meter
				label={ __( 'Failed', 'estasoft-erp-crm-sync-engine' ) }
				value={ counts.failed }
				max={ max }
				tone="failed"
			/>
		</div>
	);
}
