import { useEffect, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { Readiness } from '../../api/types';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import type { Resource } from '../../hooks/useResource';
import { errorText } from '../../lib/text';
import {
	checkSummary,
	neverRan,
	ReadinessList,
	ReadinessVerdict,
} from '../settings/ReadinessChecks';

/**
 * Step 1, Is your store ready?
 *
 * @param props                  The readiness resource owned by the wizard (Continue depends on it).
 * @param props.readiness
 * @param props.onContinueAnyway
 * @return The step.
 */
export function CheckStep( {
	readiness,
	onContinueAnyway,
}: {
	readiness: Resource< Readiness >;
	/** Moves on although the check could not run. Offered only then. */
	onContinueAnyway?: () => void;
} ) {
	const rerun = useAsyncAction( async () => {
		readiness.setData( await api.readiness.run() );
	} );
	const data = readiness.data;
	// The server only remembers the last run. The first time this step opens there is none, so run the checks
	// now: an empty list must not read as "everything passed".
	const autoRan = useRef( false );
	useEffect( () => {
		if ( neverRan( data ) && ! autoRan.current ) {
			autoRan.current = true;
			void rerun.run();
		}
		// Only the arrival of the first result matters.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ data ] );
	const waiting = readiness.loading || ( rerun.busy && neverRan( data ) );
	const worth = data
		? data.checks.filter( ( check ) => check.status === 'worth_fixing' )
				.length
		: 0;
	const passed = data
		? data.checks.filter( ( check ) => check.status === 'passed' ).length
		: 0;
	return (
		<Card
			title={ __(
				'Is your store ready?',
				'estasoft-erp-crm-sync-engine'
			) }
			description={
				data
					? worth > 0
						? sprintf(
								/* translators: 1: number worth fixing, 2: number passed */
								_n(
									'%1$d thing is worth fixing, %2$d passed. None of them stops you from continuing.',
									'%1$d things are worth fixing, %2$d passed. None of them stops you from continuing.',
									worth,
									'estasoft-erp-crm-sync-engine'
								),
								worth,
								passed
							)
						: checkSummary( data )
					: undefined
			}
			actions={
				<Btn
					small
					busy={ rerun.busy }
					onClick={ () => void rerun.run() }
				>
					{ __(
						'Run the checks again',
						'estasoft-erp-crm-sync-engine'
					) }
				</Btn>
			}
			flush
		>
			{ waiting ? (
				<LoadingState
					rows={ 4 }
					label={ __(
						'Checking your store',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ readiness.error && ! data ? (
				<>
					<ErrorState
						error={ readiness.error }
						onRetry={ () => void readiness.reload() }
						title={ __(
							'The store check could not run.',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
					{ onContinueAnyway ? (
						<div className="estasoft-sync-pad">
							<Callout
								tone="warning"
								action={
									<Btn small onClick={ onContinueAnyway }>
										{ __(
											'Continue anyway',
											'estasoft-erp-crm-sync-engine'
										) }
									</Btn>
								}
							>
								{ __(
									'The check could not run, so nothing is known about your store yet. A check that did not run is not a pass. You can continue and run it later under Settings, then Readiness.',
									'estasoft-erp-crm-sync-engine'
								) }
							</Callout>
						</div>
					) : null }
				</>
			) : null }
			{ rerun.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( rerun.error ) }
				</Callout>
			) : null }
			{ data && ! waiting ? (
				<>
					<ReadinessList readiness={ data } />
					<div className="estasoft-sync-pad">
						<ReadinessVerdict readiness={ data } />
					</div>
				</>
			) : null }
		</Card>
	);
}
