/**
 * Queue actions shared by the list, Needs attention and the order detail: retry, skip, restore,
 * each with the notice the design shows afterwards, and the three dialogs.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { QueueItem, RetryRequest } from '../../api/types';
import { useNotices } from '../../app/NoticeProvider';
import { useStatus } from '../../app/StatusProvider';
import { ConfirmDialog } from '../../components/Dialog';
import { useFormat } from '../../components/FormatContext';
import { SelectField, TextField } from '../../components/Fields';
import { formatMoney } from '../../lib/format';
import { ordersPhrase } from '../../lib/text';

export interface QueueActions {
	retryItems: ( ids: number[] ) => Promise< void >;
	retryGroup: ( request: RetryRequest, count: number ) => Promise< void >;
	skipItems: ( ids: number[], reason: string ) => Promise< void >;
	restoreItems: ( ids: number[] ) => Promise< void >;
}

/**
 * @param onChanged Called after any change, so the screen can reload.
 * @return The actions.
 */
export function useQueueActions( onChanged: () => void ): QueueActions {
	const { notify } = useNotices();
	const { reload } = useStatus();
	const changed = () => {
		onChanged();
		void reload();
	};

	const restoreItems = async ( ids: number[] ) => {
		await api.queue.bulk( { ids, action: 'restore' } );
		notify( {
			kind: 'success',
			text: sprintf(
				/* translators: %d: number of orders */
				_n(
					'%d order was restored. It goes back to the queue and is sent with its latest data.',
					'%d orders were restored. They go back to the queue and are sent with their latest data.',
					ids.length,
					'estasoft-erp-crm-sync-engine'
				),
				ids.length
			),
		} );
		changed();
	};

	return {
		retryItems: async ( ids ) => {
			if ( ids.length === 1 ) {
				await api.queue.retry( ids[ 0 ] );
			} else {
				await api.queue.bulk( { ids, action: 'retry' } );
			}
			notify( {
				kind: 'success',
				text: sprintf(
					/* translators: %d: number of orders */
					_n(
						'%d order was sent back to the queue. It will be sent next.',
						'%d orders were sent back to the queue. They will be sent oldest first, two at a time.',
						ids.length,
						'estasoft-erp-crm-sync-engine'
					),
					ids.length
				),
			} );
			changed();
		},
		retryGroup: async ( request, count ) => {
			const result = await api.queue.retryMany( request );
			notify( {
				kind: 'success',
				text: sprintf(
					/* translators: %d: number of orders */
					_n(
						'%d order was sent back to the queue. It will be sent next.',
						'%d orders were sent back to the queue. They will be sent oldest first, two at a time.',
						result.retried || count,
						'estasoft-erp-crm-sync-engine'
					),
					result.retried || count
				),
			} );
			changed();
		},
		skipItems: async ( ids, reason ) => {
			await api.queue.bulk( { ids, action: 'skip', reason } );
			notify( {
				kind: 'success',
				text: sprintf(
					/* translators: %d: number of orders */
					_n(
						'%d order skipped. It is in the Skipped list and is not sent.',
						'%d orders skipped. They are in the Skipped list and are not sent.',
						ids.length,
						'estasoft-erp-crm-sync-engine'
					),
					ids.length
				),
				actionLabel: __( 'Undo', 'estasoft-erp-crm-sync-engine' ),
				onAction: () => void restoreItems( ids ),
			} );
			changed();
		},
		restoreItems,
	};
}

function itemLine(
	item: QueueItem,
	locale: ReturnType< typeof useFormat >
): string {
	const customer = item.customer_name ? ` ${ item.customer_name }` : '';
	const total = item.total ? `, ${ formatMoney( item.total, locale ) }` : '';
	return `#${ item.entity_label }${ customer }${ total }`;
}

export interface RetryDialogProps {
	items: QueueItem[];
	/** Sentence about why they failed, shown first. */
	intro?: string;
	onClose: () => void;
	onConfirm: () => Promise< void >;
}

/**
 * "Retry 3 orders?" with the list. Items that fail again stay in Needs attention.
 *
 * @param props           Items and handlers.
 * @param props.items
 * @param props.intro
 * @param props.onClose
 * @param props.onConfirm
 * @return The dialog.
 */
export function RetryDialog( {
	items,
	intro,
	onClose,
	onConfirm,
}: RetryDialogProps ) {
	const cfg = useFormat();
	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: %s: "3 orders" */ __(
					'Retry %s?',
					'estasoft-erp-crm-sync-engine'
				),
				ordersPhrase( items.length )
			) }
			onClose={ onClose }
			onConfirm={ onConfirm }
			confirmLabel={ sprintf(
				/* translators: %s: "3 orders" */ __(
					'Retry %s',
					'estasoft-erp-crm-sync-engine'
				),
				ordersPhrase( items.length )
			) }
		>
			{ intro ? <p>{ intro }</p> : null }
			<ul className="estasoft-sync-list">
				{ items.slice( 0, 10 ).map( ( item ) => (
					<li key={ item.id } dir="auto">
						{ itemLine( item, cfg ) }
					</li>
				) ) }
				{ items.length > 10 ? (
					<li>
						{ sprintf(
							/* translators: %d: number of further orders */ __(
								'and %d more',
								'estasoft-erp-crm-sync-engine'
							),
							items.length - 10
						) }
					</li>
				) : null }
			</ul>
			<p className="estasoft-sync-muted">
				{ __(
					'They are sent oldest first, two at a time. Each one that fails again stays in Needs attention.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
		</ConfirmDialog>
	);
}

export interface SkipDialogProps {
	items: QueueItem[];
	onClose: () => void;
	onConfirm: ( reason: string ) => Promise< void >;
	system: string;
}

/**
 * "Skip 3 orders?" asks why. A skipped order stays in the log and can be restored.
 *
 * @param props           Items and handlers.
 * @param props.items
 * @param props.onClose
 * @param props.onConfirm
 * @param props.system
 * @return The dialog.
 */
export function SkipDialog( {
	items,
	onClose,
	onConfirm,
	system,
}: SkipDialogProps ) {
	const reasons = [
		__( 'Handled another way', 'estasoft-erp-crm-sync-engine' ),
		__( 'Test order', 'estasoft-erp-crm-sync-engine' ),
		__( 'Duplicate of another order', 'estasoft-erp-crm-sync-engine' ),
		__( 'Other', 'estasoft-erp-crm-sync-engine' ),
	];
	const [ reason, setReason ] = useState< string >( reasons[ 0 ] );
	const [ detail, setDetail ] = useState( '' );
	const labels = items.map( ( item ) => `#${ item.entity_label }` );
	const list =
		labels.length <= 3
			? labels.join( ', ' )
			: sprintf(
					/* translators: 1: first three order numbers, 2: number of further orders */ __(
						'%1$s and %2$d more',
						'estasoft-erp-crm-sync-engine'
					),
					labels.slice( 0, 3 ).join( ', ' ),
					labels.length - 3
				);
	const finalReason =
		reason === reasons[ 3 ] && detail.trim()
			? `${ reason }: ${ detail.trim() }`
			: reason;
	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: %s: "3 orders" */ __(
					'Skip %s?',
					'estasoft-erp-crm-sync-engine'
				),
				ordersPhrase( items.length )
			) }
			onClose={ onClose }
			onConfirm={ () => onConfirm( finalReason ) }
			confirmLabel={ sprintf(
				/* translators: %s: "3 orders" */ __(
					'Skip %s',
					'estasoft-erp-crm-sync-engine'
				),
				ordersPhrase( items.length )
			) }
		>
			<p dir="auto">
				{ sprintf(
					/* translators: 1: order numbers, 2: system name */ __(
						'%1$s will not be sent to %2$s.',
						'estasoft-erp-crm-sync-engine'
					),
					list,
					system
				) }
			</p>
			<SelectField
				label={ __(
					'Why are you skipping them?',
					'estasoft-erp-crm-sync-engine'
				) }
				value={ reason }
				onChange={ setReason }
				options={ reasons.map( ( entry ) => ( {
					label: entry,
					value: entry,
				} ) ) }
			/>
			{ reason === reasons[ 3 ] ? (
				<TextField
					label={ __(
						'Add a few words (optional)',
						'estasoft-erp-crm-sync-engine'
					) }
					value={ detail }
					onChange={ setDetail }
				/>
			) : null }
			<p className="estasoft-sync-muted">
				{ __(
					'A skipped order is not sent. It stays in the log and in the Skipped list, and you can restore it later.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
		</ConfirmDialog>
	);
}

export interface RestoreDialogProps {
	item: QueueItem;
	onClose: () => void;
	onConfirm: () => Promise< void >;
}

/**
 * "Restore order #10470?" puts a skipped order back in the queue.
 *
 * @param props           Item and handlers.
 * @param props.item
 * @param props.onClose
 * @param props.onConfirm
 * @return The dialog.
 */
export function RestoreDialog( {
	item,
	onClose,
	onConfirm,
}: RestoreDialogProps ) {
	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: %s: order number */ __(
					'Restore order #%s?',
					'estasoft-erp-crm-sync-engine'
				),
				item.entity_label
			) }
			onClose={ onClose }
			onConfirm={ onConfirm }
			confirmLabel={ __(
				'Restore order',
				'estasoft-erp-crm-sync-engine'
			) }
		>
			<p>
				{ __(
					'It goes back to the queue and is sent with its latest data.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
			{ item.skip_reason ? (
				<p className="estasoft-sync-muted">
					{ sprintf(
						/* translators: %s: the reason it was skipped */ __(
							'The reason it was skipped, “%s”, stays in the log.',
							'estasoft-erp-crm-sync-engine'
						),
						item.skip_reason
					) }
				</p>
			) : null }
		</ConfirmDialog>
	);
}
