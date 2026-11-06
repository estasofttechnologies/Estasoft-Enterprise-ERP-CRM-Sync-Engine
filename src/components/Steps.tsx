import { __, sprintf } from '@wordpress/i18n';
import { Icon } from './Icon';

/**
 * Names of the five wizard steps.
 *
 * @return Translated names, in order.
 */
export function getStepLabels(): string[] {
	return [
		__( 'Check your store', 'estasoft-enterprise-erpcrm-sync-engine' ),
		__( 'Connect a system', 'estasoft-enterprise-erpcrm-sync-engine' ),
		__( 'Choose what to sync', 'estasoft-enterprise-erpcrm-sync-engine' ),
		__( 'Preview an order', 'estasoft-enterprise-erpcrm-sync-engine' ),
		__( 'Go live', 'estasoft-enterprise-erpcrm-sync-engine' ),
	];
}

/**
 * The five wizard steps. The current one is marked in words (`aria-current="step"`) and shape.
 *
 * @param props         Current step number, 1 to 5.
 * @param props.current
 * @return The step list.
 */
export function Steps( { current }: { current: number } ) {
	const steps = getStepLabels();
	return (
		<ol
			className="estasoft-sync-steps"
			aria-label={ __(
				'Setup steps',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
		>
			{ steps.map( ( label, index ) => {
				const number = index + 1;
				const done = number < current;
				return (
					<li
						key={ label }
						aria-current={ number === current ? 'step' : undefined }
						className={ done ? 'is-done' : undefined }
					>
						<span
							className="estasoft-sync-steps__num"
							aria-hidden="true"
						>
							{ done ? (
								<Icon name="check" size={ 14 } />
							) : (
								number
							) }
						</span>
						<span>
							{ label }
							{ done ? (
								<span className="estasoft-sync-sr-only">
									{ ' ' +
										__(
											'(done)',
											'estasoft-enterprise-erpcrm-sync-engine'
										) }
								</span>
							) : null }
							{ number === current ? (
								<span className="estasoft-sync-sr-only">
									{ ' ' +
										sprintf(
											/* translators: 1: current step, 2: number of steps */ __(
												'(step %1$d of %2$d)',
												'estasoft-enterprise-erpcrm-sync-engine'
											),
											number,
											steps.length
										) }
								</span>
							) : null }
						</span>
					</li>
				);
			} ) }
		</ol>
	);
}
