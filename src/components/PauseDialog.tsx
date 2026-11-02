import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useNotices } from '../app/NoticeProvider';
import { api } from '../api/endpoints';
import { formatTime } from '../lib/format';
import { errorText } from '../lib/text';
import { useFormat } from './FormatContext';
import { ChoiceGroup } from './Fields';
import { ConfirmDialog } from './Dialog';

export interface PauseDialogProps {
	connectionId?: number;
	onClose: () => void;
	/** Called after the pause was accepted, so the caller can reload. */
	onDone: () => void;
	/** "Pause syncing" for everything, or "Pause this connection". */
	scope?: 'all' | 'connection';
}

/**
 * Pause syncing for one hour or until resumed. Orders wait in the queue.
 *
 * @param props              Connection and handlers.
 * @param props.connectionId
 * @param props.onClose
 * @param props.onDone
 * @param props.scope
 * @return The dialog.
 */
export function PauseDialog( {
	connectionId,
	onClose,
	onDone,
	scope = 'all',
}: PauseDialogProps ) {
	const [ length, setLength ] = useState< 'hour' | 'until' >( 'hour' );
	const { notify, dismiss } = useNotices();
	const cfg = useFormat();
	const resume = async () => {
		await api.resume( { connection_id: connectionId } );
		dismiss( 'paused' );
		notify( {
			kind: 'success',
			text: __(
				'Syncing resumed. Waiting orders are sent oldest first.',
				'estasoft-erp-crm-sync-engine'
			),
		} );
		onDone();
	};
	return (
		<ConfirmDialog
			title={
				scope === 'connection'
					? __(
							'Pause this connection?',
							'estasoft-erp-crm-sync-engine'
						)
					: __( 'Pause syncing?', 'estasoft-erp-crm-sync-engine' )
			}
			onClose={ onClose }
			confirmLabel={
				scope === 'connection'
					? __( 'Pause', 'estasoft-erp-crm-sync-engine' )
					: __( 'Pause syncing', 'estasoft-erp-crm-sync-engine' )
			}
			onConfirm={ async () => {
				const summary = await api.pause( {
					connection_id: connectionId,
					minutes: length === 'hour' ? 60 : undefined,
				} );
				const until = summary.paused_until;
				notify( {
					kind: 'info',
					id: 'paused',
					text:
						length === 'hour' && until
							? sprintf(
									/* translators: %s: clock time when syncing resumes */
									__(
										'Syncing is paused for 1 hour. Orders wait in the queue. It resumes at %s.',
										'estasoft-erp-crm-sync-engine'
									),
									formatTime( until, cfg )
								)
							: __(
									'Syncing is paused. Orders wait in the queue until you resume.',
									'estasoft-erp-crm-sync-engine'
								),
					actionLabel: __(
						'Resume now',
						'estasoft-erp-crm-sync-engine'
					),
					onAction: () => {
						// A refused resume must be said out loud, not dropped.
						resume().catch( ( error: unknown ) =>
							notify( {
								kind: 'error',
								text: errorText( error ),
							} )
						);
					},
				} );
				onDone();
			} }
		>
			<ChoiceGroup
				legend={ __( 'How long?', 'estasoft-erp-crm-sync-engine' ) }
				hideLegend
				cards={ false }
				value={ length }
				onChange={ ( value ) => setLength( value as 'hour' | 'until' ) }
				choices={ [
					{
						value: 'hour',
						label: __(
							'For 1 hour',
							'estasoft-erp-crm-sync-engine'
						),
					},
					{
						value: 'until',
						label: __(
							'Until I resume',
							'estasoft-erp-crm-sync-engine'
						),
					},
				] }
			/>
			<p className="estasoft-sync-muted">
				{ scope === 'connection'
					? __(
							'Orders wait in the queue. Other connections keep running.',
							'estasoft-erp-crm-sync-engine'
						)
					: __(
							'Customers can still check out. Orders wait in the queue. An item that is being sent right now finishes first.',
							'estasoft-erp-crm-sync-engine'
						) }
			</p>
		</ConfirmDialog>
	);
}
