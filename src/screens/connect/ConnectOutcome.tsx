import { __, sprintf } from '@wordpress/i18n';
import { ApiError } from '../../api/client';
import type { ConnectionTestResult } from '../../api/types';
import { Callout } from '../../components/Callout';
import { ErrorDetails } from '../../components/ErrorDetails';
import { Icon } from '../../components/Icon';
import type { ConnectShape, TestCheck } from '../../lib/connect';
import { testChecks } from '../../lib/connect';
import { catalogMessage, errorText } from '../../lib/text';

/**
 * The test of a connection as a list, one line for each check, so the person sees where it stopped.
 *
 * @param props        The checks.
 * @param props.checks
 * @return The list, or nothing when there are no checks.
 */
export function TestChecks( { checks }: { checks: TestCheck[] } ) {
	if ( checks.length === 0 ) {
		return null;
	}
	return (
		<ul
			className="estasoft-sync-checks"
			aria-label={ __(
				'The steps of the test',
				'estasoft-erp-crm-sync-engine'
			) }
		>
			{ checks.map( ( check ) => (
				<li
					key={ check.id }
					className={ `is-${ check.state.replace( '_', '-' ) }` }
				>
					<Icon
						name={
							check.state === 'passed'
								? 'synced'
								: check.state === 'failed'
									? 'failed'
									: 'minus'
						}
					/>
					<span>{ check.label }</span>
				</li>
			) ) }
		</ul>
	);
}

/**
 * What a failed save carries: the test inside the error (`data.details.test`) wins over the result.
 * @param result
 * @param failure
 */
export function shownTestOf(
	result: ConnectionTestResult | null,
	failure: ApiError | Error | null
): ConnectionTestResult | null {
	return failure instanceof ApiError && failure.details.test
		? failure.details.test
		: result;
}

/**
 * The error code of a failed test, from the result or from a thrown error.
 * @param shown
 * @param failure
 */
export function errorCodeOf(
	shown: ConnectionTestResult | null,
	failure: ApiError | Error | null
): string {
	const error = shown && ! shown.ok ? shown.error : null;
	return error?.code ?? ( failure instanceof ApiError ? failure.code : '' );
}

export interface ConnectOutcomeProps {
	shape: ConnectShape;
	system: string;
	result: ConnectionTestResult | null;
	failure: ApiError | Error | null;
	/** "Nothing was saved." for a test before saving; false when something is already saved. */
	nothingSaved?: boolean;
}

/**
 * What a test found: the checks, a partly connected warning that lists the missing access, or the
 * reason it failed with the code. A cloud or sign-in system says what to do in its own words.
 *
 * @param props              Test answer or failure.
 * @param props.shape
 * @param props.system
 * @param props.result
 * @param props.failure
 * @param props.nothingSaved
 * @return The messages, or nothing before a test ran.
 */
export function ConnectOutcome( {
	shape,
	system,
	result,
	failure,
	nothingSaved = true,
}: ConnectOutcomeProps ) {
	const shown = shownTestOf( result, failure );
	if ( ! shown && ! failure ) {
		return null;
	}
	const error = shown && ! shown.ok ? shown.error : null;
	const code = errorCodeOf( shown, failure );
	const partial = Boolean(
		shown && shown.ok && shown.missing_permissions.length > 0
	);
	const checks = testChecks( shape, shown, code, system );
	return (
		<>
			<TestChecks checks={ checks } />
			{ partial && shown ? (
				<Callout tone="warning" role="status">
					<p className="estasoft-sync-strong">
						{ __(
							'Connected, but one thing is missing.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					<p>
						{ shape === 'own_app'
							? sprintf(
									/* translators: 1: system name, 2: list of missing permissions */ __(
										'%1$s did not give access to everything we need. We could not: %2$s. Sign in again and approve all the items listed above.',
										'estasoft-erp-crm-sync-engine'
									),
									system,
									shown.missing_permissions.join( ', ' )
								)
							: sprintf(
									/* translators: 1: system and version, 2: list of missing permissions, 3: system name */ __(
										'%1$s answered, but this user cannot do the following: %2$s. In %3$s, give the user the access right it needs, then test again.',
										'estasoft-erp-crm-sync-engine'
									),
									shown.version || system,
									shown.missing_permissions.join( ', ' ),
									system
								) }{ ' ' }
						<span className="estasoft-sync-mono">
							SYNC-AUTH-004
						</span>
						{ nothingSaved ? (
							<>
								{ ' ' }
								{ __(
									'Nothing was saved yet.',
									'estasoft-erp-crm-sync-engine'
								) }
							</>
						) : null }
					</p>
				</Callout>
			) : null }
			{ error || failure ? (
				<Callout tone="danger" role="alert">
					<p>
						{ error?.message ||
							( failure ? errorText( failure ) : '' ) ||
							catalogMessage(
								code,
								error?.data?.context,
								system
							) ||
							sprintf(
								/* translators: %s: system name */
								__(
									'%s could not be reached or refused the test.',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }{ ' ' }
						{ code ? (
							<span className="estasoft-sync-mono">{ code }</span>
						) : null }
						{ nothingSaved ? (
							<>
								{ ' ' }
								{ __(
									'Nothing was saved.',
									'estasoft-erp-crm-sync-engine'
								) }
							</>
						) : null }
					</p>
					<ErrorDetails error={ failure } />
				</Callout>
			) : null }
		</>
	);
}
