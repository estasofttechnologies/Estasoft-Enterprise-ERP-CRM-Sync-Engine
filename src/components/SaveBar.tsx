import { __, _n, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { Btn } from './Btn';

export interface SaveBarProps {
	/** Number of unsaved changes. 0 means nothing to save. */
	changes: number;
	saving?: boolean;
	onSave: () => void;
	onDiscard: () => void;
	/** Replaces the "N unsaved changes" text (for example "Fix 1 field to save"). */
	message?: ReactNode;
	saveDisabled?: boolean;
	saveLabel?: string;
}

/**
 * Sticks to the bottom. Save and Discard are off when there is nothing to save.
 *
 * @param props              Counts and handlers.
 * @param props.changes
 * @param props.saving
 * @param props.onSave
 * @param props.onDiscard
 * @param props.message
 * @param props.saveDisabled
 * @param props.saveLabel
 * @return The bar.
 */
export function SaveBar( {
	changes,
	saving = false,
	onSave,
	onDiscard,
	message,
	saveDisabled = false,
	saveLabel,
}: SaveBarProps ) {
	const dirty = changes > 0;
	return (
		<div
			className="estasoft-sync-savebar"
			role="region"
			aria-label={ __(
				'Save changes',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
		>
			<p className="estasoft-sync-muted" aria-live="polite">
				{ message ??
					( dirty
						? sprintf(
								/* translators: %d: number of unsaved changes */
								_n(
									'%d unsaved change',
									'%d unsaved changes',
									changes,
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								changes
							)
						: __(
								'No changes',
								'estasoft-enterprise-erpcrm-sync-engine'
							) ) }
			</p>
			<div className="estasoft-sync-savebar__buttons">
				<Btn
					variant="tertiary"
					disabled={ ! dirty || saving }
					onClick={ onDiscard }
				>
					{ __(
						'Discard',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</Btn>
				<Btn
					variant="primary"
					disabled={ ! dirty || saving || saveDisabled }
					busy={ saving }
					onClick={ onSave }
				>
					{ saveLabel ??
						__(
							'Save changes',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
				</Btn>
			</div>
		</div>
	);
}
