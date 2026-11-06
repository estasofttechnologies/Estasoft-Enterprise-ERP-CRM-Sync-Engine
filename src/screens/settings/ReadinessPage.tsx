import { useEffect, useRef, useState } from '@wordpress/element';
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
	neverRan,
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
	// Nobody has run the checks yet: run them for the person who opened this page.
	const autoRan = useRef( false );
	useEffect( () => {
		if ( canManage && neverRan( data ) && ! autoRan.current ) {
			autoRan.current = true;
			void run.run();
		}
		// Only the arrival of the first result matters.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ data ] );
	return (
		<>
			<Card
				title={ __(
					'Readiness check',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				description={
					data ? (
						<>
							{ data.ran_at ? (
								<>
									{ __(
										'Last run',
										'estasoft-enterprise-erpcrm-sync-engine'
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
								'estasoft-enterprise-erpcrm-sync-engine'
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
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
							</Btn>
							<Btn small onClick={ () => setReport( true ) }>
								{ __(
									'Copy a support report',
									'estasoft-enterprise-erpcrm-sync-engine'
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
							'estasoft-enterprise-erpcrm-sync-engine'
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
										'estasoft-enterprise-erpcrm-sync-engine'
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
