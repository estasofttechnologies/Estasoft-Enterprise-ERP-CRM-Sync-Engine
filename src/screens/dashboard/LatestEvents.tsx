import { __ } from '@wordpress/i18n';
import type { LogEntry } from '../../api/types';
import { Btn } from '../../components/Btn';
import { Card } from '../../components/Card';
import { DateTime } from '../../components/DateTime';
import { Icon } from '../../components/Icon';
import { navigate, routeTo } from '../../app/router';

/**
 * The latest failures and changes. Routine successes are counted, not listed.
 *
 * @param props        Log entries, newest first.
 * @param props.events
 * @return The card.
 */
export function LatestEvents( { events }: { events: LogEntry[] } ) {
	return (
		<Card
			title={ __( 'Latest events', 'estasoft-erp-crm-sync-engine' ) }
			description={ __(
				'Failures and changes. Routine successes are counted, not listed.',
				'estasoft-erp-crm-sync-engine'
			) }
			flush
		>
			{ events.length === 0 ? (
				<p className="estasoft-sync-pad estasoft-sync-muted">
					{ __(
						'Nothing to report in the last hour.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
			) : (
				<ul className="estasoft-sync-events">
					{ events.map( ( event ) => (
						<li key={ event.id }>
							<Icon
								name={
									event.level === 'error'
										? 'failed'
										: event.level === 'warning'
											? 'warning'
											: 'synced'
								}
							/>
							<div className="estasoft-sync-events__text">
								<p className="estasoft-sync-strong">
									{ event.message }
								</p>
								<p className="estasoft-sync-muted estasoft-sync-small">
									{ [ event.entity_ref, event.code ]
										.filter( Boolean )
										.join( ' · ' ) }
								</p>
							</div>
							<span className="estasoft-sync-muted">
								<DateTime value={ event.ts } />
							</span>
						</li>
					) ) }
				</ul>
			) }
			<div className="estasoft-sync-card__foot">
				<span className="estasoft-sync-muted">
					{ __(
						'The latest events from the last hour',
						'estasoft-erp-crm-sync-engine'
					) }
				</span>
				<Btn small onClick={ () => navigate( routeTo( '/logs' ) ) }>
					{ __( 'Open the log', 'estasoft-erp-crm-sync-engine' ) }
				</Btn>
			</div>
		</Card>
	);
}
