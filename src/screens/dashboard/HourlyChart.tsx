import { __, sprintf } from '@wordpress/i18n';
import type { HourlyPoint } from '../../api/types';
import { Card } from '../../components/Card';
import { useFormat } from '../../components/FormatContext';
import { formatNumber, formatTime } from '../../lib/format';

/**
 * Orders synced per hour as bars. The bars are decorative: a sentence gives the totals and the
 * peak, and a table with every hour is one click away.
 *
 * @param props        Hourly points, oldest first.
 * @param props.points
 * @return The card.
 */
export function HourlyChart( { points }: { points: HourlyPoint[] } ) {
	const cfg = useFormat();
	const max = Math.max(
		1,
		...points.map( ( point ) => point.synced + point.failed )
	);
	const total = points.reduce( ( sum, point ) => sum + point.synced, 0 );
	const failed = points.reduce( ( sum, point ) => sum + point.failed, 0 );
	const peak = points.reduce< HourlyPoint | null >(
		( best, point ) =>
			best === null || point.synced > best.synced ? point : best,
		null
	);
	const description = peak
		? sprintf(
				/* translators: 1: orders synced today, 2: orders failed today, 3: busiest hour such as 10:00, 4: orders synced in that hour */
				__(
					'Today: %1$s orders synced and %2$s failed. The busiest hour was %3$s with %4$s synced.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				formatNumber( total, cfg.locale ),
				formatNumber( failed, cfg.locale ),
				formatTime( peak.hour, cfg ),
				formatNumber( peak.synced, cfg.locale )
			)
		: __(
				'Nothing has been synced yet today.',
				'estasoft-enterprise-erpcrm-sync-engine'
			);
	return (
		<Card
			title={ __(
				'Orders synced per hour',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
			description={ __(
				'Today, store time. The last bar is the hour in progress.',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
			flush
		>
			<p className="estasoft-sync-chart__summary">{ description }</p>
			<div className="estasoft-sync-chart" aria-hidden="true">
				{ points.map( ( point, index ) => {
					const height = Math.round( ( point.synced / max ) * 120 );
					const failedHeight = Math.round(
						( point.failed / max ) * 120
					);
					return (
						<div
							key={ point.hour }
							className={ `estasoft-sync-chart__col${ index === points.length - 1 ? ' is-now' : '' }` }
						>
							<span className="estasoft-sync-small estasoft-sync-muted">
								{ point.synced }
							</span>
							<span className="estasoft-sync-chart__stack">
								{ failedHeight > 0 ? (
									<span
										className="estasoft-sync-chart__bar estasoft-sync-chart__bar--failed"
										style={ { blockSize: failedHeight } }
									/>
								) : null }
								<span
									className="estasoft-sync-chart__bar"
									style={ {
										blockSize: Math.max(
											point.synced > 0 ? 2 : 0,
											height
										),
									} }
								/>
							</span>
							<span className="estasoft-sync-small estasoft-sync-muted">
								{ formatTime( point.hour, cfg ).slice( 0, 2 ) }
							</span>
						</div>
					);
				} ) }
			</div>
			<details className="estasoft-sync-chart__table">
				<summary>
					{ __(
						'Show the numbers as a table',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</summary>
				<table className="estasoft-sync-plain-table">
					<caption className="estasoft-sync-sr-only">
						{ __(
							'Orders synced and failed in each hour today',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __(
									'Hour',
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
							</th>
							<th scope="col">
								{ __(
									'Synced',
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
							</th>
							<th scope="col">
								{ __(
									'Failed',
									'estasoft-enterprise-erpcrm-sync-engine'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ points.map( ( point ) => (
							<tr key={ point.hour }>
								<th scope="row">
									{ formatTime( point.hour, cfg ) }
								</th>
								<td>{ point.synced }</td>
								<td>{ point.failed }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</details>
		</Card>
	);
}
