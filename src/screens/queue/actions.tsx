/**
 * Queue actions shared by the list, Needs attention and the order detail: retry, skip, restore,
 * each with the notice the design shows afterwards, and the three dialogs.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { ItemState, QueueItem, RetryRequest } from '../../api/types';
import { useNotices } from '../../app/NoticeProvider';
import { useStatus } from '../../app/StatusProvider';
import { ConfirmDialog } from '../../components/Dialog';
import { useFormat } from '../../components/FormatContext';
import { SelectField, TextField } from '../../components/Fields';
import { formatMoney } from '../../lib/format';
import { errorText, ordersPhrase } from '../../lib/text';

/** States the server accepts for each bulk action (`QueueService`). */
export const RETRY_STATES: readonly ItemState[] = [ 'failed', 'retrying' ];
export const SKIP_STATES: readonly ItemState[] = [
	'queued',
	'retrying',
	'failed',
];
export const RESTORE_STATES: readonly ItemState[] = [ 'skipped' ];

export type QueueActionKind = 'retry' | 'skip' | 'restore';

const STATES: Record< QueueActionKind, readonly ItemState[] > = {
	retry: RETRY_STATES,
	skip: SKIP_STATES,
	restore: RESTORE_STATES,
};

/**
 * The items an action applies to. The server leaves the others untouched, so the screen only
 * offers what can work.
 *
 * @param items Selected items.
 * @param kind  The action.
 * @return The items in a state that allows the action.
 */
export function actionable(
	items: QueueItem[],
	kind: QueueActionKind
): QueueItem[] {
	return items.filter( ( item ) => STATES[ kind ].includes( item.state ) );
}

/**
 * The reason an action is unavailable for a selection that holds nothing it can work on.
 *
 * @param kind The action.
 * @return A short sentence.
 */
export function unavailableReason( kind: QueueActionKind ): string {
	switch ( kind ) {
		case 'retry':
			return __(
				'Retry needs at least one failed or retrying order in the selection.',
				'estasoft-erp-crm-sync-engine'
			);
		case 'skip':
			return __(
				'Skip needs at least one queued, retrying or failed order in the selection.',
				'estasoft-erp-crm-sync-engine'
			);
		default:
			return __(
				'Restore needs at least one skipped order in the selection.',
				'estasoft-erp-crm-sync-engine'
			);
	}
}

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

	/**
	 * Says what the server did. `done` is the number the server reports, which can be lower than
	 * the number sent when some items had changed state since the list was loaded.
	 *
	 * @param kind      The action.
	 * @param done      Items the server changed.
	 * @param requested Items sent.
	 * @param undo      Offer to undo (skip only).
	 */
	const report = (
		kind: QueueActionKind,
		done: number,
		requested: number,
		undo?: () => void
	) => {
		const left = Math.max( 0, requested - done );
		let text: string;
		if ( done === 0 ) {
			text =
				kind === 'retry'
					? __(
							'No order was sent back to the queue. Only a failed or retrying order can be retried, and these may have changed since the list was loaded.',
							'estasoft-erp-crm-sync-engine'
						)
					: kind === 'skip'
						? __(
								'No order was skipped. An order that has already finished cannot be skipped.',
								'estasoft-erp-crm-sync-engine'
							)
						: __(
								'No order was restored. Only a skipped order can be restored.',
								'estasoft-erp-crm-sync-engine'
							);
		} else if ( left > 0 ) {
			text =
				kind === 'retry'
					? sprintf(
							/* translators: 1: orders sent back to the queue, 2: orders that could not be */
							_n(
								'%1$d order was sent back to the queue. %2$d more could not be sent back, because only a failed or retrying order can be.',
								'%1$d orders were sent back to the queue. %2$d more could not be sent back, because only a failed or retrying order can be.',
								done,
								'estasoft-erp-crm-sync-engine'
							),
							done,
							left
						)
					: kind === 'skip'
						? sprintf(
								/* translators: 1: orders skipped, 2: orders that could not be */
								_n(
									'%1$d order skipped. %2$d more could not be skipped, because an order that has already finished cannot be.',
									'%1$d orders skipped. %2$d more could not be skipped, because an order that has already finished cannot be.',
									done,
									'estasoft-erp-crm-sync-engine'
								),
								done,
								left
							)
						: sprintf(
								/* translators: 1: orders restored, 2: orders that could not be */
								_n(
									'%1$d order was restored. %2$d more could not be restored, because only a skipped order can be.',
									'%1$d orders were restored. %2$d more could not be restored, because only a skipped order can be.',
									done,
									'estasoft-erp-crm-sync-engine'
								),
								done,
								left
							);
		} else if ( kind === 'retry' ) {
			text = sprintf(
				/* translators: %d: number of orders */
				_n(
					'%d order was sent back to the queue. It will be sent next.',
					'%d orders were sent back to the queue. They will be sent oldest first, two at a time.',
					done,
					'estasoft-erp-crm-sync-engine'
				),
				done
			);
		} else if ( kind === 'skip' ) {
			text = sprintf(
				/* translators: %d: number of orders */
				_n(
					'%d order skipped. It is in the Skipped list and is not sent.',
					'%d orders skipped. They are in the Skipped list and are not sent.',
					done,
					'estasoft-erp-crm-sync-engine'
				),
				done
			);
		} else {
			text = sprintf(
				/* translators: %d: number of orders */
				_n(
					'%d order was restored. It goes back to the queue and is sent with its latest data.',
					'%d orders were restored. They go back to the queue and are sent with their latest data.',
					done,
					'estasoft-erp-crm-sync-engine'
				),
				done
			);
		}
		notify( {
			kind: done === 0 ? 'warning' : 'success',
			text,
			...( undo && done > 0
				? {
						actionLabel: __(
							'Undo',
							'estasoft-erp-crm-sync-engine'
						),
						onAction: undo,
					}
				: {} ),
		} );
	};

	const restoreItems = async ( ids: number[] ) => {
		const result = await api.queue.bulk( { ids, action: 'restore' } );
		report( 'restore', result.done, ids.length );
		changed();
	};

	return {
		retryItems: async ( ids ) => {
			let done = ids.length;
			if ( ids.length === 1 ) {
				await api.queue.retry( ids[ 0 ] );
			} else {
				done = ( await api.queue.bulk( { ids, action: 'retry' } ) )
					.done;
			}
			report( 'retry', done, ids.length );
			changed();
		},
		retryGroup: async ( request, count ) => {
			const result = await api.queue.retryMany( request );
			report(
				'retry',
				result.retried,
				Math.max( count, result.retried )
			);
			changed();
		},
		skipItems: async ( ids, reason ) => {
			const result = await api.queue.bulk( {
				ids,
				action: 'skip',
				reason,
			} );
			report( 'skip', result.done, ids.length, () => {
				// A failed undo must say so: the notice would otherwise promise a restore that did not happen.
				restoreItems( ids ).catch( ( error: unknown ) =>
					notify( { kind: 'error', text: errorText( error ) } )
				);
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
	/** Selected orders the action leaves alone because of their state. */
	leftOut?: number;
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
 * @param props.leftOut
 * @param props.intro
 * @param props.onClose
 * @param props.onConfirm
 * @return The dialog.
 */
export function RetryDialog( {
	items,
	leftOut = 0,
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
			{ leftOut > 0 ? (
				<p>
					{ sprintf(
						/* translators: %d: number of selected orders left alone */
						_n(
							'%d selected order is not failed or retrying, so it is left as it is.',
							'%d selected orders are not failed or retrying, so they are left as they are.',
							leftOut,
							'estasoft-erp-crm-sync-engine'
						),
						leftOut
					) }
				</p>
			) : null }
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
	/** Selected orders the action leaves alone because of their state. */
	leftOut?: number;
	onClose: () => void;
	onConfirm: ( reason: string ) => Promise< void >;
	system: string;
}

/**
 * "Skip 3 orders?" asks why. A skipped order stays in the log and can be restored.
 *
 * @param props           Items and handlers.
 * @param props.items
 * @param props.leftOut
 * @param props.onClose
 * @param props.onConfirm
 * @param props.system
 * @return The dialog.
 */
export function SkipDialog( {
	items,
	leftOut = 0,
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
			? sprintf(
					/* translators: %s: the few words the person typed after choosing "Other" */
					__( 'Other: %s', 'estasoft-erp-crm-sync-engine' ),
					detail.trim()
				)
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
			{ leftOut > 0 ? (
				<p>
					{ sprintf(
						/* translators: %d: number of selected orders left alone */
						_n(
							'%d selected order has already finished, so it is left as it is.',
							'%d selected orders have already finished, so they are left as they are.',
							leftOut,
							'estasoft-erp-crm-sync-engine'
						),
						leftOut
					) }
				</p>
			) : null }
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

export interface RestoreManyDialogProps {
	items: QueueItem[];
	/** Selected orders the action leaves alone because they are not skipped. */
	leftOut?: number;
	onClose: () => void;
	onConfirm: () => Promise< void >;
}

/**
 * "Restore 3 orders?" for a selection that holds skipped orders.
 *
 * @param props           Items and handlers.
 * @param props.items
 * @param props.leftOut
 * @param props.onClose
 * @param props.onConfirm
 * @return The dialog.
 */
export function RestoreManyDialog( {
	items,
	leftOut = 0,
	onClose,
	onConfirm,
}: RestoreManyDialogProps ) {
	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: %s: "3 orders" */ __(
					'Restore %s?',
					'estasoft-erp-crm-sync-engine'
				),
				ordersPhrase( items.length )
			) }
			onClose={ onClose }
			onConfirm={ onConfirm }
			confirmLabel={ sprintf(
				/* translators: %s: "3 orders" */ __(
					'Restore %s',
					'estasoft-erp-crm-sync-engine'
				),
				ordersPhrase( items.length )
			) }
		>
			<p>
				{ __(
					'They go back to the queue and are sent with their latest data.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
			{ leftOut > 0 ? (
				<p>
					{ sprintf(
						/* translators: %d: number of selected orders left alone */
						_n(
							'%d selected order is not skipped, so it is left as it is.',
							'%d selected orders are not skipped, so they are left as they are.',
							leftOut,
							'estasoft-erp-crm-sync-engine'
						),
						leftOut
					) }
				</p>
			) : null }
		</ConfirmDialog>
	);
}
