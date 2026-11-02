import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { SignInResult } from '../../api/local-types';
import type { Connection, ConnectionTestResult } from '../../api/types';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { Busy } from '../../components/EmptyState';
import { Link } from '../../app/router';
import { ConnectOutcome } from './ConnectOutcome';
import { useStepFocus } from './ConnectParts';
import { OrganisationStep } from './OrganisationStep';
import { useSignInStart } from './signin';
import { errorText } from '../../lib/text';

export interface SignInReturnProps {
	connection: Connection;
	system: string;
	result: SignInResult;
	/** Called when the person is done with the message (the flag is dropped from the address). */
	onClose: () => void;
	/** Called after the connection was tested and saved, so the screen can load the new state. */
	onTested: () => void;
	/** Shown after a passing test when setup is not finished: the way back into it. */
	continueSetup?: boolean;
}

type Part = 'organisation' | 'testing' | 'tested';

/**
 * What the person sees when the vendor's sign-in page sent the browser back. Cancelled and failed
 * say so and offer another try (SYNC-AUTH-003). A good sign-in goes on to the organisation question
 * and then tests the connection, so a partly approved sign-in shows what is missing (SYNC-AUTH-004).
 *
 * @param props               The connection, the result and handlers.
 * @param props.connection
 * @param props.system
 * @param props.result
 * @param props.onClose
 * @param props.onTested
 * @param props.continueSetup
 * @return The message and the next step.
 */
export function SignInReturn( {
	connection,
	system,
	result,
	onClose,
	onTested,
	continueSetup,
}: SignInReturnProps ) {
	// A person who reconnects an expired sign-in has chosen an organisation before: the server activates the
	// connection when the browser comes back, so there is nothing to ask again. Go straight to the test.
	const [ part, setPart ] = useState< Part >(
		result === 'ok' && connection.status === 'active'
			? 'testing'
			: 'organisation'
	);
	const [ tested, setTested ] = useState< ConnectionTestResult | null >(
		null
	);
	const [ failure, setFailure ] = useState< Error | null >( null );
	const signIn = useSignInStart();
	const heading = useStepFocus< HTMLHeadingElement >(
		`${ result }-${ part }`,
		result === 'ok'
			? sprintf(
					/* translators: %s: system name */
					__( 'Signed in at %s', 'estasoft-erp-crm-sync-engine' ),
					system
				)
			: sprintf(
					/* translators: %s: system name */
					__(
						'The sign-in at %s did not finish',
						'estasoft-erp-crm-sync-engine'
					),
					system
				)
	);
	const mounted = useRef( true );
	useEffect(
		() => () => {
			mounted.current = false;
		},
		[]
	);
	// The message is new content: take focus once so it is not missed.
	useEffect( () => {
		heading.current?.focus();
		if ( result === 'ok' && connection.status === 'active' ) {
			void runTest();
		}
		// Once, on arrival.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const runTest = async () => {
		setPart( 'testing' );
		setFailure( null );
		setTested( null );
		try {
			const answer = await api.connections.test( connection.id );
			if ( ! mounted.current ) {
				return;
			}
			setTested( answer );
			if ( answer.ok && answer.missing_permissions.length === 0 ) {
				onTested();
			}
		} catch ( error ) {
			if ( ! mounted.current ) {
				return;
			}
			setFailure(
				error instanceof Error ? error : new Error( String( error ) )
			);
		}
		setPart( 'tested' );
	};

	if ( result === 'cancelled' || result === 'failed' ) {
		return (
			<Card
				title={
					<span ref={ heading } tabIndex={ -1 }>
						{ sprintf(
							/* translators: %s: system name */
							__(
								'The sign-in at %s did not finish',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					</span>
				}
			>
				{ result === 'cancelled' ? (
					<Callout tone="warning" role="status">
						<p>
							{ __(
								'Sign-in was cancelled. Nothing was connected.',
								'estasoft-erp-crm-sync-engine'
							) }{ ' ' }
							<span className="estasoft-sync-mono">
								SYNC-AUTH-003
							</span>
						</p>
						<p className="estasoft-sync-muted">
							{ sprintf(
								/* translators: %s: system name */
								__(
									'Press Try again to sign in at %s. If the sign-in page did not open, check that your browser is not blocking it.',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						</p>
					</Callout>
				) : (
					<Callout tone="danger" role="alert">
						<p>
							{ sprintf(
								/* translators: %s: system name */
								__(
									'The sign-in with %s could not be finished. Nothing was connected.',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						</p>
						<p className="estasoft-sync-muted">
							{ __(
								'Check the Client ID and the secret of your app, then try again. The log has the details.',
								'estasoft-erp-crm-sync-engine'
							) }{ ' ' }
							<Link to="/logs" className="estasoft-sync-link">
								{ __(
									'Open the log',
									'estasoft-erp-crm-sync-engine'
								) }
							</Link>
						</p>
					</Callout>
				) }
				{ signIn.error ? (
					<Callout tone="danger" role="alert">
						{ errorText( signIn.error ) }
					</Callout>
				) : null }
				<div className="estasoft-sync-actions">
					<Btn
						variant="primary"
						busy={ signIn.busy }
						onClick={ () => void signIn.run( connection.id ) }
					>
						{ __( 'Try again', 'estasoft-erp-crm-sync-engine' ) }
					</Btn>
					<Btn onClick={ onClose }>
						{ __( 'Close', 'estasoft-erp-crm-sync-engine' ) }
					</Btn>
				</div>
			</Card>
		);
	}

	const passed = Boolean(
		tested && tested.ok && tested.missing_permissions.length === 0
	);
	return (
		<Card
			title={
				<span ref={ heading } tabIndex={ -1 }>
					{ sprintf(
						/* translators: %s: system name */
						__( 'Signed in at %s', 'estasoft-erp-crm-sync-engine' ),
						system
					) }
				</span>
			}
		>
			{ part === 'organisation' ? (
				<OrganisationStep
					connection={ connection }
					system={ system }
					onDone={ () => void runTest() }
				/>
			) : null }
			{ part === 'testing' ? (
				<Busy
					label={ __(
						'Testing the connection… This can take up to 30 seconds.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ part === 'tested' ? (
				<>
					<ConnectOutcome
						shape="own_app"
						system={ system }
						result={ tested }
						failure={ failure }
						nothingSaved={ false }
					/>
					{ passed && tested ? (
						<Callout tone="success" role="status">
							<p className="estasoft-sync-strong">
								{ sprintf(
									/* translators: %s: system name */
									__(
										'Connected to %s.',
										'estasoft-erp-crm-sync-engine'
									),
									system
								) }
							</p>
							<p>
								{ [ tested.version, tested.company ]
									.filter( Boolean )
									.join( ', ' ) }
							</p>
							<p className="estasoft-sync-muted">
								{ __(
									'Waiting orders are sent in the order they arrived.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						</Callout>
					) : null }
					<div className="estasoft-sync-actions">
						{ ! passed ? (
							<Btn
								variant="primary"
								busy={ signIn.busy }
								onClick={ () =>
									void signIn.run( connection.id )
								}
							>
								{ __(
									'Sign in again',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : null }
						{ ! passed ? (
							<Btn onClick={ () => void runTest() }>
								{ __(
									'Test again',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						) : null }
						{ passed && continueSetup ? (
							<Link
								to="/setup"
								className="components-button is-primary estasoft-sync-btn"
							>
								{ __(
									'Continue setup',
									'estasoft-erp-crm-sync-engine'
								) }
							</Link>
						) : null }
						<Btn onClick={ onClose }>
							{ passed
								? __( 'Done', 'estasoft-erp-crm-sync-engine' )
								: __(
										'Close',
										'estasoft-erp-crm-sync-engine'
									) }
						</Btn>
					</div>
					{ signIn.error ? (
						<Callout tone="danger" role="alert">
							{ errorText( signIn.error ) }
						</Callout>
					) : null }
				</>
			) : null }
		</Card>
	);
}
