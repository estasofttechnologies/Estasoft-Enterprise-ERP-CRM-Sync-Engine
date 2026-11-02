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
	ReadinessList,
	ReadinessVerdict,
} from '../settings/ReadinessChecks';

/**
 * Step 1, Is your store ready?
 *
 * @param props           The readiness resource owned by the wizard (Continue depends on it).
 * @param props.readiness
 * @return The step.
 */
export function CheckStep( {
	readiness,
}: {
	readiness: Resource< Readiness >;
} ) {
	const rerun = useAsyncAction( async () => {
		readiness.setData( await api.readiness.run() );
	} );
	const data = readiness.data;
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
			{ readiness.loading ? (
				<LoadingState
					rows={ 4 }
					label={ __(
						'Checking your store',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ readiness.error && ! data ? (
				<ErrorState
					error={ readiness.error }
					onRetry={ () => void readiness.reload() }
				/>
			) : null }
			{ rerun.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( rerun.error ) }
				</Callout>
			) : null }
			{ data ? (
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
