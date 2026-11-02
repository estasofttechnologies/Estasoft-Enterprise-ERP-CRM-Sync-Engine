import type { ReactNode } from 'react';
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { errorText } from '../lib/text';
import { Btn } from './Btn';
import { Callout } from './Callout';

/**
 * Explains why a screen is empty and what to do. No illustrations, no cheering.
 *
 * @param props          Title, text and an optional action.
 * @param props.title
 * @param props.children
 * @param props.action
 * @param props.as
 * @return The state.
 */
export function EmptyState( {
	title,
	children,
	action,
	as: Heading = 'h3',
}: {
	title: string;
	children?: ReactNode;
	action?: ReactNode;
	as?: 'h2' | 'h3';
} ) {
	return (
		<div className="estasoft-sync-empty">
			<Heading className="estasoft-sync-empty__title">{ title }</Heading>
			{ children ? (
				<p className="estasoft-sync-muted">{ children }</p>
			) : null }
			{ action ? (
				<div className="estasoft-sync-empty__action">{ action }</div>
			) : null }
		</div>
	);
}

/**
 * Grey blocks with a polite status; no spinner on cards.
 *
 * @param props       Text for screen readers and the number of blocks.
 * @param props.label
 * @param props.rows
 * @return The placeholder.
 */
export function LoadingState( {
	label,
	rows = 3,
}: {
	label?: string;
	rows?: number;
} ) {
	return (
		<div className="estasoft-sync-loading" role="status" aria-live="polite">
			<span className="estasoft-sync-sr-only">
				{ label ?? __( 'Loading', 'estasoft-erp-crm-sync-engine' ) }
			</span>
			{ Array.from( { length: rows } ).map( ( _unused, index ) => (
				<span
					key={ index }
					className="estasoft-sync-loading__block"
					aria-hidden="true"
				/>
			) ) }
		</div>
	);
}

/**
 * A small spinner with a label, for buttons and dialogs that wait.
 *
 * @param props       Label.
 * @param props.label
 * @return The spinner.
 */
export function Busy( { label }: { label: string } ) {
	return (
		<span className="estasoft-sync-busy" role="status">
			<Spinner />
			<span>{ label }</span>
		</span>
	);
}

/**
 * Shows a failed load with the reason and a Try again button.
 *
 * @param props         Error and retry handler.
 * @param props.error
 * @param props.onRetry
 * @param props.title
 * @return The message.
 */
export function ErrorState( {
	error,
	onRetry,
	title,
}: {
	error: unknown;
	onRetry?: () => void;
	title?: string;
} ) {
	return (
		<Callout
			tone="danger"
			role="alert"
			action={
				onRetry ? (
					<Btn small onClick={ onRetry }>
						{ __( 'Try again', 'estasoft-erp-crm-sync-engine' ) }
					</Btn>
				) : undefined
			}
		>
			<strong>
				{ title ??
					__(
						'This could not be loaded.',
						'estasoft-erp-crm-sync-engine'
					) }
			</strong>
			<span> { errorText( error ) }</span>
		</Callout>
	);
}
