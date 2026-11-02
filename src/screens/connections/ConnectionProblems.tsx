import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { Link } from '../../app/router';
import { Card } from '../../components/Card';
import type { IconName } from '../../components/Icon';
import { KeyValueList } from '../../components/KeyValueList';
import { PageHeader } from '../../components/PageHeader';
import { StatusBadge } from '../../components/StatusBadge';
import type { Tone } from '../../components/StatusBadge';

interface Problem {
	code: string;
	title: string;
	state: string;
	tone: Tone;
	icon: IconName;
	see: string;
	does: string;
	you: string;
	action: { label: string; to: string };
}

/**
 * What each connection problem looks like, what the plugin does on its own, and what the person does
 * (board D04). Written once, so the screen, the log and a support report use the same words.
 *
 * @return The list of problems.
 */
function getProblems(): Problem[] {
	return [
		{
			code: 'SYNC-AUTH-002',
			title: __(
				'The sign-in has expired',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Needs reconnect', 'estasoft-erp-crm-sync-engine' ),
			tone: 'warning',
			icon: 'retrying',
			see: __(
				'A notice on every plugin screen and a Reconnect button on the connection. The system shows as Needs reconnect.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'Stops sending to this connection. Orders wait as Queued and are kept. An email is sent once.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Press Reconnect and approve on the system’s own page.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
		{
			code: 'SYNC-AUTH-001',
			title: __(
				'The system refused the sign-in',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Paused', 'estasoft-erp-crm-sync-engine' ),
			tone: 'danger',
			icon: 'paused',
			see: __(
				'A red notice, and the connection shows Paused. Orders wait as Queued.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'Does not retry. Pauses the connection so orders wait and none are marked Failed.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Check the user and the key, or the secret, then test again.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
		{
			code: 'SYNC-AUTH-003',
			title: __(
				'The sign-in was cancelled',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Not connected', 'estasoft-erp-crm-sync-engine' ),
			tone: 'warning',
			icon: 'warning',
			see: __(
				'When you come back from the system’s page, a message says the sign-in was cancelled and nothing was connected.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'Keeps the details of your app. Nothing is sent, and nothing else changes.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Press Try again, and approve everything the page asks for. If the page did not open, check that your browser is not blocking it.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
		{
			code: 'SYNC-AUTH-004',
			title: __(
				'The sign-in lacks some access',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Partly connected', 'estasoft-erp-crm-sync-engine' ),
			tone: 'warning',
			icon: 'warning',
			see: __(
				'The test lists what is missing, for example that invoices cannot be created.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'Does not save a connection from a test. A saved connection that loses access shows the same list.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Give the user the missing access in the system, or sign in again and approve all the items. Then test again.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
		{
			code: 'SYNC-AUTH-005',
			title: __(
				'The key or secret has run out',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Needs a new key', 'estasoft-erp-crm-sync-engine' ),
			tone: 'danger',
			icon: 'failed',
			see: __(
				'A warning before the end date, and a red notice when it has passed. Connections shows the date.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'Stops sending when it runs out. Orders wait in the queue.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Make a new key or secret in the system and replace it on the connection. Your mappings and queue stay.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
		{
			code: 'SYNC-AUTH-006',
			title: __(
				'The plan does not include outside access',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Needs a person', 'estasoft-erp-crm-sync-engine' ),
			tone: 'danger',
			icon: 'failed',
			see: __(
				'The test fails with a message about the plan. Some systems only let outside programs in on a higher plan, or when the API is switched on.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'Nothing is saved from the test. A saved connection that is refused for this reason is paused.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Change the plan, or switch the API on in the system, then test again.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
		{
			code: 'SYNC-AUTH-007',
			title: __(
				'The system does not know this app yet',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Needs a person', 'estasoft-erp-crm-sync-engine' ),
			tone: 'danger',
			icon: 'failed',
			see: __(
				'For a cloud app, the test fails after the secret was accepted, saying the app is not known inside the system.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'Nothing is saved from the test.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Ask the system’s administrator to finish the app setup inside the system, so the app is added and enabled. Then test again.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
		{
			code: 'SYNC-NET-001',
			title: __(
				'The system does not answer',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Paused', 'estasoft-erp-crm-sync-engine' ),
			tone: 'warning',
			icon: 'paused',
			see: __(
				'The dashboard shows the system as unreachable and Paused. Orders wait as Queued.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'After several failures in a row it pauses, and it checks again by itself. It resumes when the system answers.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Usually nothing. Check the system if it stays paused.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
		{
			code: 'SYNC-RATE-001',
			title: __(
				'The system asks us to slow down',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Retrying', 'estasoft-erp-crm-sync-engine' ),
			tone: 'neutral',
			icon: 'retrying',
			see: __(
				'Items show Retrying with the wait time. No notice.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'Waits the time the system asks, then continues. This does not count towards pausing the connection.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Nothing. Lower the speed limit on the connection if it happens often.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
		{
			code: 'SYNC-CFG-002',
			title: __(
				'The encryption key is missing',
				'estasoft-erp-crm-sync-engine'
			),
			state: __( 'Needs fixing', 'estasoft-erp-crm-sync-engine' ),
			tone: 'danger',
			icon: 'failed',
			see: __(
				'A notice with the line to paste. Saving credentials is refused.',
				'estasoft-erp-crm-sync-engine'
			),
			does: __(
				'Keeps existing connections as they are and refuses new secrets.',
				'estasoft-erp-crm-sync-engine'
			),
			you: __(
				'Add the line to wp-config.php and reload.',
				'estasoft-erp-crm-sync-engine'
			),
			action: {
				label: __( 'Open Connections', 'estasoft-erp-crm-sync-engine' ),
				to: '/connections',
			},
		},
	];
}

/**
 * Connection problems: a reference of what each problem looks like and what to do (board D04).
 *
 * @return The screen.
 */
export function ConnectionProblems() {
	const problems = getProblems();
	return (
		<>
			<PageHeader
				title={ __(
					'Connection problems',
					'estasoft-erp-crm-sync-engine'
				) }
				breadcrumb={ [
					{
						label: __(
							'Connections',
							'estasoft-erp-crm-sync-engine'
						),
						to: '/connections',
					},
					{
						label: __( 'Problems', 'estasoft-erp-crm-sync-engine' ),
					},
				] }
				description={ __(
					'What each problem looks like, what the plugin does on its own, and what you do. Every problem has a code that also appears in the log and in a support report.',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
			<div className="estasoft-sync-grid estasoft-sync-grid--cards estasoft-sync-problems">
				{ problems.map( ( problem ) => (
					<Card
						key={ problem.code }
						title={ problem.title }
						description={
							(
								<span className="estasoft-sync-mono">
									{ problem.code }
								</span>
							 ) as ReactNode
						}
						actions={
							<StatusBadge
								tone={ problem.tone }
								icon={ problem.icon }
							>
								{ problem.state }
							</StatusBadge>
						}
					>
						<KeyValueList
							stacked
							items={ [
								{
									label: __(
										'What you see',
										'estasoft-erp-crm-sync-engine'
									),
									value: problem.see,
								},
								{
									label: __(
										'What the plugin does',
										'estasoft-erp-crm-sync-engine'
									),
									value: problem.does,
								},
								{
									label: __(
										'What you do',
										'estasoft-erp-crm-sync-engine'
									),
									value: problem.you,
								},
							] }
						/>
						<p>
							<Link
								to={ problem.action.to }
								className="estasoft-sync-link"
							>
								{ problem.action.label }
							</Link>
						</p>
					</Card>
				) ) }
			</div>
		</>
	);
}
