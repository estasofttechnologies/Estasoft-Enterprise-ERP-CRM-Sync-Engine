/**
 * Dialogs on the WordPress Modal (focus is trapped and returned, Esc closes). Centre at 521 px
 * and wider, bottom sheet at 520 px and below (see styles).
 */
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { FormEvent, ReactNode } from 'react';
import { useAsyncAction } from '../hooks/useAsyncAction';
import { errorText } from '../lib/text';
import { Btn } from './Btn';
import { Callout } from './Callout';
import { ErrorDetails } from './ErrorDetails';

export interface DialogProps {
	title: string;
	onClose: () => void;
	children: ReactNode;
	/** Buttons at the foot. */
	footer?: ReactNode;
	size?: 'small' | 'medium' | 'large';
	/** Do not close on Esc or click outside while something is being saved. */
	locked?: boolean;
	className?: string;
}

/**
 * A modal with a title, content and a foot.
 *
 * @param props           Dialog content.
 * @param props.title
 * @param props.onClose
 * @param props.children
 * @param props.footer
 * @param props.size
 * @param props.locked
 * @param props.className
 * @return The modal.
 */
export function Dialog( {
	title,
	onClose,
	children,
	footer,
	size = 'medium',
	locked = false,
	className = '',
}: DialogProps ) {
	return (
		<Modal
			title={ title }
			onRequestClose={ locked ? () => undefined : onClose }
			shouldCloseOnClickOutside={ false }
			shouldCloseOnEsc={ ! locked }
			size={ size }
			className={ `estasoft-sync-modal ${ className }`.trim() }
			overlayClassName="estasoft-sync-overlay"
		>
			<div className="estasoft-sync-dialog__body">{ children }</div>
			{ footer ? (
				<div className="estasoft-sync-dialog__foot">{ footer }</div>
			) : null }
		</Modal>
	);
}

export interface ConfirmDialogProps {
	title: string;
	onClose: () => void;
	/** Runs when the person confirms. The dialog closes when it resolves and stays open with the error when it throws. */
	onConfirm: () => Promise< unknown > | unknown;
	children: ReactNode;
	confirmLabel: string;
	cancelLabel?: string;
	tone?: 'primary' | 'danger';
	confirmDisabled?: boolean;
	/** Extra content for the foot, before Cancel. */
	extraAction?: ReactNode;
	/** Names a rule in the list of problems that comes with a refused publish. */
	ruleLabel?: ( ruleId: string ) => string;
}

/**
 * A dialog with Cancel and one confirm button. Shows the error from the confirm action inside
 * the dialog and keeps it open so nothing is lost.
 *
 * @param props                 Dialog content and handlers.
 * @param props.title
 * @param props.onClose
 * @param props.onConfirm
 * @param props.children
 * @param props.confirmLabel
 * @param props.cancelLabel
 * @param props.tone
 * @param props.confirmDisabled
 * @param props.extraAction
 * @param props.ruleLabel
 * @return The modal.
 */
export function ConfirmDialog( {
	title,
	onClose,
	onConfirm,
	children,
	confirmLabel,
	cancelLabel,
	tone = 'primary',
	confirmDisabled = false,
	extraAction,
	ruleLabel,
}: ConfirmDialogProps ) {
	const action = useAsyncAction( async () => {
		await onConfirm();
		onClose();
	} );
	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		if ( ! confirmDisabled ) {
			void action.run();
		}
	};
	return (
		<Dialog
			title={ title }
			onClose={ onClose }
			locked={ action.busy }
			footer={
				<>
					{ extraAction }
					<Btn
						variant="tertiary"
						onClick={ onClose }
						disabled={ action.busy }
					>
						{ cancelLabel ??
							__(
								'Cancel',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
					</Btn>
					<Btn
						variant="primary"
						danger={ tone === 'danger' }
						onClick={ () => void action.run() }
						busy={ action.busy }
						disabled={ confirmDisabled || action.busy }
					>
						{ confirmLabel }
					</Btn>
				</>
			}
		>
			<form onSubmit={ submit } className="estasoft-sync-dialog__form">
				{ children }
				{ action.error ? (
					<Callout tone="danger" role="alert">
						<p>{ errorText( action.error ) }</p>
						<ErrorDetails
							error={ action.error }
							ruleLabel={ ruleLabel }
						/>
					</Callout>
				) : null }
			</form>
		</Dialog>
	);
}
