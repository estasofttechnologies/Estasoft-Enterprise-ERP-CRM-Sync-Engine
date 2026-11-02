import { __, _n, sprintf } from '@wordpress/i18n';
import type { Readiness, ReadinessCheck } from '../../api/types';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { StatusBadge } from '../../components/StatusBadge';
import type { Tone } from '../../components/StatusBadge';
import { navigate } from '../../app/router';
import { safeHttpUrl } from '../../lib/url';

const APP_ROUTE =
	/^#?\/(?:dashboard|queue|invoices|connections|mapping|stock|reconciliation|logs|settings|setup|shadow)(?:[/?]|$)/;

/**
 * Props that make a button open either an app route or an admin URL.
 *
 * @param link Admin URL or app route from the check.
 * @return Button props.
 */
function linkProps(
	link: string
): { href: string } | { onClick: () => void } | null {
	if ( APP_ROUTE.test( link ) ) {
		return { onClick: () => navigate( link.replace( /^#/, '' ) ) };
	}
	const href = safeHttpUrl( link );
	return href ? { href } : null;
}

const TONE: Record< ReadinessCheck[ 'status' ], Tone > = {
	passed: 'success',
	worth_fixing: 'warning',
	needs_fixing: 'danger',
};

/**
 * Word for a check result.
 *
 * @param status Check status.
 * @return The word shown in the badge.
 */
export function checkWord( status: ReadinessCheck[ 'status' ] ): string {
	switch ( status ) {
		case 'passed':
			return __( 'Passed', 'estasoft-erp-crm-sync-engine' );
		case 'worth_fixing':
			return __( 'Worth fixing', 'estasoft-erp-crm-sync-engine' );
		case 'needs_fixing':
			return __( 'Needs fixing', 'estasoft-erp-crm-sync-engine' );
	}
}

/**
 * Counts by result, in words.
 *
 * @param readiness Result of the checks.
 * @return Sentence such as "7 passed, 3 worth fixing, 0 need fixing".
 */
export function checkSummary( readiness: Readiness ): string {
	const count = ( status: ReadinessCheck[ 'status' ] ) =>
		readiness.checks.filter( ( check ) => check.status === status ).length;
	const needs = count( 'needs_fixing' );
	return sprintf(
		/* translators: 1: number passed, 2: number worth fixing, 3: sentence about those that need fixing */
		__(
			'%1$d passed, %2$d worth fixing, %3$s',
			'estasoft-erp-crm-sync-engine'
		),
		count( 'passed' ),
		count( 'worth_fixing' ),
		sprintf(
			/* translators: %d: number */ _n(
				'%d needs fixing',
				'%d need fixing',
				needs,
				'estasoft-erp-crm-sync-engine'
			),
			needs
		)
	);
}

/**
 * Whether the server has never run the checks (it answers with an empty list and no time).
 *
 * @param readiness Result of the checks, when loaded.
 * @return True when there is nothing to show yet and the checks should be run.
 */
export function neverRan( readiness: Readiness | undefined ): boolean {
	return (
		readiness !== undefined &&
		readiness.ran_at === null &&
		readiness.checks.length === 0
	);
}

/**
 * The list of checks. Needs fixing stops setup; worth fixing never does.
 *
 * @param props           Readiness result.
 * @param props.readiness
 * @return The list.
 */
export function ReadinessList( { readiness }: { readiness: Readiness } ) {
	const order: ReadinessCheck[ 'status' ][] = [
		'needs_fixing',
		'worth_fixing',
		'passed',
	];
	const sorted = [ ...readiness.checks ].sort(
		( a, b ) => order.indexOf( a.status ) - order.indexOf( b.status )
	);
	return (
		<ul className="estasoft-sync-attlist">
			{ sorted.map( ( check ) => (
				<li key={ check.id } className="estasoft-sync-att">
					<span className="estasoft-sync-att__badge">
						<StatusBadge tone={ TONE[ check.status ] }>
							{ checkWord( check.status ) }
						</StatusBadge>
					</span>
					<div className="estasoft-sync-att__text">
						<p className="estasoft-sync-strong">{ check.title }</p>
						<p className="estasoft-sync-muted">{ check.detail }</p>
					</div>
					{ check.link &&
					check.link_label &&
					linkProps( check.link ) ? (
						<Btn small { ...linkProps( check.link ) }>
							{ check.link_label }
						</Btn>
					) : null }
				</li>
			) ) }
		</ul>
	);
}

/**
 * A sentence under the checks that says whether they block setup.
 *
 * @param props           Readiness result.
 * @param props.readiness
 * @return The message.
 */
export function ReadinessVerdict( { readiness }: { readiness: Readiness } ) {
	if ( readiness.ran_at === null && readiness.checks.length === 0 ) {
		return (
			<Callout tone="info" role="status">
				{ __(
					'The checks have not run yet. A check that did not run is not a pass.',
					'estasoft-erp-crm-sync-engine'
				) }
			</Callout>
		);
	}
	const needs = readiness.checks.filter(
		( check ) => check.status === 'needs_fixing'
	).length;
	const worth = readiness.checks.filter(
		( check ) => check.status === 'worth_fixing'
	).length;
	if ( needs > 0 ) {
		return (
			<Callout tone="danger" role="status">
				{ sprintf(
					/* translators: %d: number of checks */ _n(
						'%d thing needs fixing. Continue stays off until it is fixed.',
						'%d things need fixing. Continue stays off until they are fixed.',
						needs,
						'estasoft-erp-crm-sync-engine'
					),
					needs
				) }
			</Callout>
		);
	}
	if ( worth > 0 ) {
		return (
			<Callout tone="warning" role="status">
				{ sprintf(
					/* translators: %d: number of checks */ _n(
						'%d thing is worth fixing. None of them stops you from continuing, but each can cause failed orders later.',
						'%d things are worth fixing. None of them stops you from continuing, but each can cause failed orders later.',
						worth,
						'estasoft-erp-crm-sync-engine'
					),
					worth
				) }
			</Callout>
		);
	}
	return (
		<Callout tone="success" role="status">
			{ __( 'Everything passed.', 'estasoft-erp-crm-sync-engine' ) }
		</Callout>
	);
}
