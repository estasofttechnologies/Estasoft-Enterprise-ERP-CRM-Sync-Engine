import { __, _n, sprintf } from '@wordpress/i18n';
import { ApiError } from '../api/client';
import { CopyButton } from './CopyButton';

/**
 * The wp-config.php line for the encryption key, with a copy button.
 *
 * @param props      The line.
 * @param props.line
 * @return The block.
 */
export function KeyLineBlock( { line }: { line: string } ) {
	return (
		<>
			<p>
				{ __(
					'Add this line to wp-config.php above the line that says “That’s all, stop editing”, then reload this page.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
			<pre className="estasoft-sync-code estasoft-sync-code--inline">
				<code>{ line }</code>
			</pre>
			<CopyButton
				small
				text={ line }
				label={ __( 'Copy the line', 'estasoft-erp-crm-sync-engine' ) }
			/>
		</>
	);
}

export interface ErrorDetailsProps {
	/** Whatever was thrown. Only an `ApiError` with `details` shows anything. */
	error: unknown;
	/** Name of a rule for the problem list of a mapping that cannot be published. */
	ruleLabel?: ( ruleId: string ) => string;
}

/**
 * The extra structure the server sends with some errors (`data.details`):
 * - `key_line`: the line to add to wp-config.php when the encryption key is missing,
 * - `test`: the failed connection test, with what the user is not allowed to do,
 * - `errors`: every problem of a mapping that cannot be published, one per rule.
 *
 * @param props           The error and an optional rule namer.
 * @param props.error
 * @param props.ruleLabel
 * @return The details, or nothing.
 */
export function ErrorDetails( { error, ruleLabel }: ErrorDetailsProps ) {
	if ( ! ( error instanceof ApiError ) ) {
		return null;
	}
	const { errors, test, keyLine } = error.details;
	const missing = test?.missing_permissions ?? [];
	if ( errors.length === 0 && ! keyLine && missing.length === 0 ) {
		return null;
	}
	return (
		<div className="estasoft-sync-errordetails">
			{ keyLine ? <KeyLineBlock line={ keyLine } /> : null }
			{ missing.length > 0 ? (
				<p>
					{ sprintf(
						/* translators: %s: list of things the user is not allowed to do, separated by commas */
						_n(
							'The connection test found this access missing: %s.',
							'The connection test found this access missing: %s.',
							missing.length,
							'estasoft-erp-crm-sync-engine'
						),
						missing.join( ', ' )
					) }
				</p>
			) : null }
			{ errors.length > 0 ? (
				<>
					<p>
						{ sprintf(
							/* translators: %d: number of problems */
							_n(
								'%d problem to fix before this can go live:',
								'%d problems to fix before this can go live:',
								errors.length,
								'estasoft-erp-crm-sync-engine'
							),
							errors.length
						) }
					</p>
					<ul className="estasoft-sync-list">
						{ errors.map( ( problem, index ) => (
							<li
								key={ `${ problem.rule_id }|${ problem.field }|${ index }` }
							>
								{ problem.rule_id && ruleLabel
									? sprintf(
											/* translators: 1: name of the rule, 2: what is wrong with it */
											__(
												'%1$s: %2$s',
												'estasoft-erp-crm-sync-engine'
											),
											ruleLabel( problem.rule_id ),
											problem.message
										)
									: problem.message }
							</li>
						) ) }
					</ul>
				</>
			) : null }
		</div>
	);
}
