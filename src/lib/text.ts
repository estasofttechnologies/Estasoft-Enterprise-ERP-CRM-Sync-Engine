/**
 * Words the screens share: state names, event names, error messages and the "what happened" text.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { ApiError } from '../api/client';
import { ERROR_CATALOG } from '../api/errors';
import type {
	ConnectionStatus,
	ConnectionSummary,
	EntityType,
	ItemState,
	QueueItem,
} from '../api/types';
import type { FormatConfig } from './format';
import { formatNumber, formatTime } from './format';

/**
 * Display name for a queue state.
 *
 * @param state Item state.
 * @return Word shown next to the state icon.
 */
export function stateLabel( state: ItemState ): string {
	switch ( state ) {
		case 'queued':
			return __( 'Queued', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'syncing':
			return __( 'Syncing', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'synced':
			return __( 'Synced', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'retrying':
			return __( 'Retrying', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'failed':
			return __( 'Failed', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'skipped':
			return __( 'Skipped', 'estasoft-enterprise-erpcrm-sync-engine' );
	}
}

/**
 * One sentence on what a state means (S02).
 *
 * @param state Item state.
 * @return Sentence.
 */
export function stateMeaning( state: ItemState ): string {
	switch ( state ) {
		case 'queued':
			return __(
				'Waiting its turn. Nothing has been sent yet.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'syncing':
			return __(
				'Being sent to the system right now.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'synced':
			return __(
				'The system accepted it. Its number is saved on the order.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'retrying':
			return __(
				'The system did not answer well. The plugin will try again on its own.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'failed':
			return __(
				'Retrying cannot fix it. A person has to change something.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'skipped':
			return __(
				'A person chose not to send it. It can be restored.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
	}
}

/**
 * Word for a connection status.
 *
 * @param status Connection status.
 * @return Word.
 */
export function connectionStatusLabel( status: ConnectionStatus ): string {
	switch ( status ) {
		case 'active':
			return __( 'Connected', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'paused':
		case 'paused_circuit':
			return __( 'Paused', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'needs_reconnect':
			return __(
				'Needs reconnect',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'disconnected':
			return __(
				'Disconnected',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
	}
}

/**
 * Short system name for sentences, such as "Odoo".
 *
 * @param connection Connection summary, if any.
 * @return Name, or "your system".
 */
export function systemName(
	connection:
		Pick< ConnectionSummary, 'connector' | 'label' > | null | undefined
): string {
	if ( ! connection ) {
		return __( 'your system', 'estasoft-enterprise-erpcrm-sync-engine' );
	}
	const known: Record< string, string > = { odoo: 'Odoo' };
	if ( known[ connection.connector ] ) {
		return known[ connection.connector ];
	}
	return (
		connection.label ||
		connection.connector.charAt( 0 ).toUpperCase() +
			connection.connector.slice( 1 )
	);
}

/**
 * Name of an event such as "Order created".
 *
 * @param event      Event key from the queue (`created`, `order.paid`, `refund`, ...).
 * @param entityType Entity type of the item.
 * @return Label.
 */
export function eventLabel( event: string, entityType: EntityType ): string {
	const key = event.replace( /^[a-z]+\./, '' ).toLowerCase();
	if ( entityType === 'refund' || key === 'refund' || key === 'refunded' ) {
		return __( 'Refund', 'estasoft-enterprise-erpcrm-sync-engine' );
	}
	switch ( entityType ) {
		case 'customer':
			return __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'stock':
			return __( 'Stock', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'tracking':
			return __( 'Tracking', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'invoice':
			return __( 'Invoice', 'estasoft-enterprise-erpcrm-sync-engine' );
		default:
			break;
	}
	switch ( key ) {
		case 'created':
		case 'create':
		case 'placed':
			return __(
				'Order created',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'paid':
		case 'payment':
		case 'payment_complete':
			return __( 'Order paid', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'completed':
		case 'complete':
			return __(
				'Order completed',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'cancelled':
		case 'canceled':
			return __(
				'Order cancelled',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		case 'updated':
		case 'update':
			return __(
				'Order updated',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
		default:
			return __( 'Order', 'estasoft-enterprise-erpcrm-sync-engine' );
	}
}

/**
 * Fills `{placeholder}` markers in a catalog message.
 *
 * @param template Message with markers.
 * @param values   Values by marker name.
 * @return The message; unknown markers are left out cleanly.
 */
export function fillPlaceholders(
	template: string,
	values: Record< string, string >
): string {
	return template
		.replace( /\{(\w+)\}/g, ( _match, key: string ) => values[ key ] ?? '' )
		.replace( /\s{2,}/g, ' ' )
		.trim();
}

/**
 * The message for an error code from the catalog, with the placeholders filled in.
 *
 * @param code    Error code such as SYNC-MAP-001.
 * @param context Placeholder values.
 * @param system  Name of the connected system.
 * @return Message, or an empty string for unknown codes.
 */
export function catalogMessage(
	code: string,
	context: Record< string, string > = {},
	system = ''
): string {
	const info = ERROR_CATALOG[ code ];
	if ( ! info ) {
		return '';
	}
	return fillPlaceholders( info.message, {
		system:
			system ||
			__( 'your system', 'estasoft-enterprise-erpcrm-sync-engine' ),
		...context,
	} );
}

/**
 * Message to show for any thrown value.
 *
 * @param error Thrown value.
 * @return Text for the person.
 */
export function errorText( error: unknown ): string {
	if ( error instanceof ApiError ) {
		return error.message || catalogMessage( error.code, error.context );
	}
	if ( error instanceof Error && error.message ) {
		return error.message;
	}
	return __(
		'Something went wrong. Try again.',
		'estasoft-enterprise-erpcrm-sync-engine'
	);
}

function problemOf( item: QueueItem, system: string ): string {
	return (
		item.error_message ||
		( item.error_code ? catalogMessage( item.error_code, {}, system ) : '' )
	);
}

/**
 * The "what happened" cell of a queue row.
 *
 * @param item   Queue item.
 * @param system Short system name.
 * @param cfg    Format configuration.
 * @return Text.
 */
export function whatHappened(
	item: QueueItem,
	system: string,
	cfg: FormatConfig
): string {
	if ( item.mode === 'shadow' && item.state === 'synced' ) {
		return __(
			'Compared with the system. Nothing was sent.',
			'estasoft-enterprise-erpcrm-sync-engine'
		);
	}
	switch ( item.state ) {
		case 'queued':
			return item.waiting_for_earlier
				? __(
						'Waiting for an earlier step of this order',
						'estasoft-enterprise-erpcrm-sync-engine'
					)
				: __(
						'Waiting to be sent',
						'estasoft-enterprise-erpcrm-sync-engine'
					);
		case 'syncing':
			return sprintf(
				/* translators: %s: system name such as Odoo */
				__( 'Sending to %s', 'estasoft-enterprise-erpcrm-sync-engine' ),
				system
			);
		case 'synced':
			if ( item.entity_type === 'refund' && item.external_ref ) {
				return sprintf(
					/* translators: %s: credit note number */
					__(
						'Credit note %s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					item.external_ref
				);
			}
			if ( item.external_ref ) {
				return sprintf(
					/* translators: 1: system name, 2: number in that system such as S00490 */
					__( '%1$s %2$s', 'estasoft-enterprise-erpcrm-sync-engine' ),
					system,
					item.external_ref
				);
			}
			return __( 'Synced', 'estasoft-enterprise-erpcrm-sync-engine' );
		case 'retrying': {
			const problem = problemOf( item, system );
			const retriesAllowed = Math.max( 1, item.max_attempts - 1 );
			const next = item.next_attempt_at
				? formatTime( item.next_attempt_at, cfg )
				: '';
			const reason =
				problem ||
				__(
					'The system did not answer well',
					'estasoft-enterprise-erpcrm-sync-engine'
				);
			const retry = Math.max( 1, item.attempt );
			if ( ! next ) {
				return sprintf(
					/* translators: 1: problem such as "Odoo answered 503", 2: retry number, 3: number of retries allowed */
					__(
						'%1$s. Retry %2$d of %3$d',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					reason,
					retry,
					retriesAllowed
				);
			}
			return sprintf(
				/* translators: 1: problem such as "Odoo answered 503", 2: retry number, 3: number of retries allowed, 4: clock time of the next try */
				__(
					'%1$s. Retry %2$d of %3$d, next try %4$s',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				reason,
				retry,
				retriesAllowed,
				next
			);
		}
		case 'failed':
			return (
				problemOf( item, system ) ||
				__( 'Failed', 'estasoft-enterprise-erpcrm-sync-engine' )
			);
		case 'skipped':
			if ( item.skip_reason ) {
				return sprintf(
					/* translators: 1: reason the person gave, 2: clock time */
					__(
						'%1$s. Skipped at %2$s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					item.skip_reason,
					formatTime( item.updated_at, cfg )
				);
			}
			return sprintf(
				/* translators: %s: clock time */
				__( 'Skipped at %s', 'estasoft-enterprise-erpcrm-sync-engine' ),
				formatTime( item.updated_at, cfg )
			);
	}
}

/**
 * Plural-safe "n days" phrase.
 *
 * @param count Number of days.
 * @return Text such as "7 days".
 */
export function daysPhrase( count: number ): string {
	return sprintf(
		/* translators: %d: number of days */
		_n(
			'%d day',
			'%d days',
			count,
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		count
	);
}

/**
 * Plural-safe "n groups" phrase (groups of failed orders that share a cause).
 *
 * @param count Number of groups.
 * @return Text such as "3 groups".
 */
export function groupsPhrase( count: number ): string {
	return sprintf(
		/* translators: %d: number of groups of failed orders */
		_n(
			'%d group',
			'%d groups',
			count,
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		count
	);
}

/**
 * Plural-safe "n notes" phrase.
 *
 * @param count Number of notes.
 * @return Text such as "2 notes".
 */
export function notesPhrase( count: number ): string {
	return sprintf(
		/* translators: %d: number of notes */
		_n(
			'%d note',
			'%d notes',
			count,
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		count
	);
}

/**
 * Plural-safe "n orders" phrase.
 *
 * @param count  Number of orders.
 * @param locale WordPress locale, to group digits (`2,104 orders`); plain digits when left out.
 * @return Text such as "3 orders".
 */
export function ordersPhrase( count: number, locale?: string ): string {
	return sprintf(
		/* translators: %s: number of orders */
		_n(
			'%s order',
			'%s orders',
			count,
			'estasoft-enterprise-erpcrm-sync-engine'
		),
		locale ? formatNumber( count, locale ) : String( count )
	);
}
