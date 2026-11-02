import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import { useApp } from '../../app/AppContext';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { DateTime } from '../../components/DateTime';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useResource } from '../../hooks/useResource';
import { errorText } from '../../lib/text';
import { SupportReportDialog } from '../logs/SupportReportDialog';
import {
	checkSummary,
	ReadinessList,
	ReadinessVerdict,
} from './ReadinessChecks';

/**
 * The readiness check (I02): the things that most often break syncing.
 *
 * @return The page.
 */
export function ReadinessPage() {
	const { canManage } = useApp();
	const readiness = useResource(
		( signal ) => api.readiness.get( signal ),
		[]
	);
	const [ report, setReport ] = useState( false );
	const run = useAsyncAction( async () => {
		readiness.setData( await api.readiness.run() );
	} );
	const data = readiness.data;
	return (
		<>
			<Card
				title={ __(
					'Readiness check',
					'estasoft-erp-crm-sync-engine'
				) }
				description={
					data ? (
						<>
							{ data.ran_at ? (
								<>
									{ __(
										'Last run',
										'estasoft-erp-crm-sync-engine'
									) }{ ' ' }
									<DateTime
										value={ data.ran_at }
										kind="smart"
									/>
									.{ ' ' }
								</>
							) : null }
							{ __(
								'These checks find the things that most often break syncing.',
								'estasoft-erp-crm-sync-engine'
							) }
						</>
					) : undefined
				}
				actions={
					canManage ? (
						<>
							<Btn
								variant="primary"
								small
								busy={ run.busy }
								onClick={ () => void run.run() }
							>
								{ __(
									'Run the checks again',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
							<Btn small onClick={ () => setReport( true ) }>
								{ __(
									'Copy a support report',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						</>
					) : undefined
				}
				flush
			>
				{ readiness.loading ? (
					<LoadingState
						rows={ 5 }
						label={ __(
							'Running the checks',
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
				{ run.error ? (
					<Callout tone="danger" role="alert">
						{ errorText( run.error ) }
					</Callout>
				) : null }
				{ data ? (
					<>
						<div className="estasoft-sync-pad">
							<ReadinessVerdict readiness={ data } />
							<p className="estasoft-sync-muted">
								{ sprintf(
									/* translators: %s: summary such as "7 passed, 3 worth fixing" */ __(
										'Checks: %s',
										'estasoft-erp-crm-sync-engine'
									),
									checkSummary( data )
								) }
							</p>
						</div>
						<ReadinessList readiness={ data } />
					</>
				) : null }
			</Card>
			{ report ? (
				<SupportReportDialog onClose={ () => setReport( false ) } />
			) : null }
		</>
	);
}
