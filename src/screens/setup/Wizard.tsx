import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { CatalogEntry, Connection, Onboarding } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { navigate } from '../../app/router';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { ConfirmDialog } from '../../components/Dialog';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { PageHeader } from '../../components/PageHeader';
import { getStepLabels, Steps } from '../../components/Steps';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useResource } from '../../hooks/useResource';
import { errorText } from '../../lib/text';
import { ChooseSystem } from '../connections/ChooseSystem';
import { ConnectSystem } from '../connections/ConnectSystem';
import { CheckStep } from './CheckStep';
import { GoLiveStep } from './GoLiveStep';
import { PreviewStep } from './PreviewStep';
import { readWhat, WhatStep } from './WhatStep';
import type { WhatAnswers } from './WhatStep';

/**
 * The setup wizard: check the store, connect a system, choose what to sync, preview an order,
 * go live. Resumable: every step is saved to the server, and nothing is sent before the last step.
 *
 * @return The screen.
 */
export function Wizard() {
	const { canConfigure } = useApp();
	const { reload: reloadStatus } = useStatus();
	const { notify } = useNotices();
	const onboarding = useResource(
		( signal ) => api.onboarding.get( signal ),
		[]
	);
	const readiness = useResource(
		( signal ) => api.readiness.get( signal ),
		[]
	);
	const catalog = useResource( ( signal ) => api.catalog.list( signal ), [] );
	const connections = useResource(
		( signal ) => api.connections.list( signal ),
		[]
	);
	const [ step, setStep ] = useState( 1 );
	const [ sub, setSub ] = useState< 'choose' | 'connect' >( 'choose' );
	const [ systemNameChosen, setSystemNameChosen ] = useState< string | null >(
		null
	);
	const [ connection, setConnection ] = useState< Connection | null >( null );
	const [ what, setWhat ] = useState< WhatAnswers >( readWhat( undefined ) );
	const [ previewOrder, setPreviewOrder ] = useState< number | null >( null );
	const [ mode, setMode ] = useState< 'shadow' | 'live' >( 'shadow' );
	const [ confirmLive, setConfirmLive ] = useState( false );
	const [ seeded, setSeeded ] = useState( false );

	// Resume: take the saved step and answers once, when they arrive.
	useEffect( () => {
		const saved = onboarding.data;
		if ( ! saved || seeded ) {
			return;
		}
		setSeeded( true );
		setStep( saved.step );
		const answers = saved.answers ?? {};
		const chosen =
			typeof answers.system === 'string' ? answers.system : null;
		setSystemNameChosen( chosen );
		setSub( chosen ? 'connect' : 'choose' );
		setWhat( readWhat( answers.what ) );
		if ( typeof answers.preview_order === 'number' ) {
			setPreviewOrder( answers.preview_order );
		}
		if ( saved.completed ) {
			navigate( '/', true );
		}
	}, [ onboarding.data, seeded ] );

	const entry: CatalogEntry | undefined = useMemo(
		() =>
			catalog.data?.find(
				( candidate ) => candidate.name === systemNameChosen
			),
		[ catalog.data, systemNameChosen ]
	);

	// Resume: find the connection that belongs to the chosen system.
	useEffect( () => {
		if ( connection || ! entry?.connector || ! connections.data ) {
			return;
		}
		const match = connections.data.find(
			( candidate ) => candidate.connector === entry.connector
		);
		if ( match ) {
			void api.connections
				.get( match.id )
				.then( setConnection )
				.catch( () => undefined );
		}
	}, [ connection, entry, connections.data ] );

	const persist = async (
		changes: Partial< Pick< Onboarding, 'step' | 'connector' | 'answers' > >
	) => {
		const answers = {
			...( onboarding.data?.answers ?? {} ),
			system: systemNameChosen ?? undefined,
			what,
			preview_order: previewOrder ?? undefined,
			...( changes.answers ?? {} ),
		};
		const saved = await api.onboarding.save( { ...changes, answers } );
		onboarding.setData( saved );
	};

	const move = useAsyncAction(
		async (
			next: number,
			extra?: Partial< Pick< Onboarding, 'connector' | 'answers' > >
		) => {
			await persist( {
				step: Math.min(
					5,
					Math.max( 1, next )
				) as Onboarding[ 'step' ],
				...extra,
			} );
			setStep( next );
			window.scrollTo?.( 0, 0 );
		}
	);

	// Throws when the server refuses. The "Go live now" dialog relies on that to stay open and show
	// the reason; the shadow button runs it through `useAsyncAction`, which keeps the error for the page.
	const doStart = async () => {
		const result = await api.onboarding.goLive( mode );
		onboarding.setData( result );
		await reloadStatus();
		notify( {
			kind: 'success',
			text:
				mode === 'shadow'
					? __(
							'Shadow mode started. Nothing is sent. Each order is compared with your system and the differences are listed.',
							'estasoft-erp-crm-sync-engine'
						)
					: __(
							'You are live. New orders are sent as they arrive.',
							'estasoft-erp-crm-sync-engine'
						),
		} );
		navigate( '/' );
	};
	const start = useAsyncAction( doStart );

	// On every step change, move focus to the step heading and say where we are.
	const stepHeading = useRef< HTMLHeadingElement >( null );
	const firstStep = useRef( true );
	useEffect( () => {
		if ( firstStep.current ) {
			firstStep.current = false;
			return;
		}
		stepHeading.current?.focus();
		speak(
			sprintf(
				/* translators: 1: current step, 2: number of steps, 3: name of the step */
				__( 'Step %1$d of %2$d: %3$s', 'estasoft-erp-crm-sync-engine' ),
				step,
				5,
				getStepLabels()[ step - 1 ] ?? ''
			),
			'polite'
		);
	}, [ step, sub ] );

	if ( onboarding.loading || ( onboarding.data && ! seeded ) ) {
		return (
			<>
				<PageHeader
					title={ __(
						'Set up ESTASOFT SYNC',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<LoadingState
					rows={ 4 }
					label={ __(
						'Loading the setup guide',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}
	if ( ! onboarding.data ) {
		return (
			<>
				<PageHeader
					title={ __(
						'Set up ESTASOFT SYNC',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<ErrorState
					error={ onboarding.error }
					onRetry={ () => void onboarding.reload() }
					title={ __(
						'The setup guide could not be loaded.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}
	if ( ! canConfigure ) {
		return (
			<>
				<PageHeader
					title={ __(
						'Set up ESTASOFT SYNC',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
				<Callout tone="info">
					{ __(
						'Only an administrator can set up the connection. Ask one to open this page.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			</>
		);
	}

	// A check that failed to run is not a pass: Continue stays off and only "Continue anyway" moves on.
	const blocked = readiness.data?.blocked ?? false;
	const checkFailed = Boolean( readiness.error ) && ! readiness.data;
	const system = connection ? connection.connector : ( entry?.name ?? '' );
	const sampleLabel = previewOrder ? String( previewOrder ) : null;

	let body;
	let canContinue = true;
	let continueLabel: string = __(
		'Continue',
		'estasoft-erp-crm-sync-engine'
	);
	let onContinue: () => void = () => void move.run( step + 1 );
	let onBack: ( () => void ) | null =
		step > 1 ? () => void move.run( step - 1 ) : null;

	switch ( step ) {
		case 1:
			body = (
				<CheckStep
					readiness={ readiness }
					onContinueAnyway={ () => void move.run( 2 ) }
				/>
			);
			canContinue = ! blocked && ! readiness.loading && ! checkFailed;
			break;
		case 2:
			if ( sub === 'choose' ) {
				body = (
					<ChooseSystem
						selected={ systemNameChosen }
						onSelect={ ( picked ) => {
							setSystemNameChosen( picked.name );
							if (
								connection &&
								connection.connector !== picked.connector
							) {
								setConnection( null );
							}
						} }
					/>
				);
				canContinue = Boolean( systemNameChosen );
				onContinue = () => {
					setSub( 'connect' );
					void persist( {
						step: 2,
						connector: entry?.connector ?? null,
						answers: { system: systemNameChosen ?? undefined },
					} ).catch( () => undefined );
				};
				onBack = () => void move.run( 1 );
			} else {
				body = entry ? (
					<ConnectSystem
						entry={ entry }
						existing={ connection }
						onConnected={ ( created ) => {
							setConnection( created );
							void persist( {
								step: 2,
								connector: created.connector,
							} ).catch( () => undefined );
						} }
					/>
				) : (
					<LoadingState
						rows={ 3 }
						label={ __(
							'Loading',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
				);
				canContinue = Boolean( connection );
				onContinue = () =>
					void move.run( 3, {
						connector: connection?.connector ?? null,
					} );
				onBack = () => setSub( 'choose' );
			}
			break;
		case 3:
			body = connection ? (
				<WhatStep
					connection={ connection }
					value={ what }
					onChange={ setWhat }
				/>
			) : (
				<Callout
					tone="warning"
					action={
						<Btn small onClick={ () => void move.run( 2 ) }>
							{ __(
								'Connect a system',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					}
				>
					{ __(
						'Connect a system first. What to sync depends on what the system can do.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			);
			canContinue = Boolean( connection );
			onContinue = () => void move.run( 4, { answers: { what } } );
			break;
		case 4:
			body = (
				<PreviewStep
					orderId={ previewOrder }
					onOrderId={ setPreviewOrder }
				/>
			);
			continueLabel = __(
				'Looks right, continue',
				'estasoft-erp-crm-sync-engine'
			);
			onContinue = () =>
				void move.run( 5, {
					answers: { preview_order: previewOrder ?? undefined },
				} );
			break;
		default:
			body = connection ? (
				<GoLiveStep
					connection={ connection }
					what={ what }
					readiness={ readiness.data }
					previewLabel={ sampleLabel ? `#${ sampleLabel }` : null }
					mode={ mode }
					onMode={ setMode }
					onChange={ ( target ) => void move.run( target ) }
				/>
			) : (
				<Callout tone="warning">
					{ __(
						'Connect a system first.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			);
			canContinue = Boolean( connection );
			continueLabel =
				mode === 'shadow'
					? __( 'Start shadow mode', 'estasoft-erp-crm-sync-engine' )
					: __( 'Go live now', 'estasoft-erp-crm-sync-engine' );
			onContinue = () =>
				mode === 'live' ? setConfirmLive( true ) : void start.run();
	}

	return (
		<div className="estasoft-sync-wizard">
			<PageHeader
				title={ __(
					'Set up ESTASOFT SYNC',
					'estasoft-erp-crm-sync-engine'
				) }
				description={ __(
					'You can leave and come back. Nothing is sent to your system until the last step.',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
			<Steps current={ step } />
			<h2
				ref={ stepHeading }
				tabIndex={ -1 }
				className="estasoft-sync-wizard__heading"
			>
				{ getStepLabels()[ step - 1 ] }
			</h2>
			{ body }
			{ move.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( move.error ) }
				</Callout>
			) : null }
			{ start.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( start.error ) }
				</Callout>
			) : null }
			<div className="estasoft-sync-wizard__foot">
				<span className="estasoft-sync-muted">
					{ sprintf(
						/* translators: 1: current step, 2: number of steps */ __(
							'Step %1$d of %2$d',
							'estasoft-erp-crm-sync-engine'
						),
						step,
						5
					) }
				</span>
				<div className="estasoft-sync-actions">
					{ step === 4 ? (
						<Btn onClick={ () => navigate( '/mapping/orders' ) }>
							{ __(
								'Edit the mapping',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					) : null }
					{ onBack ? (
						<Btn onClick={ onBack } disabled={ move.busy }>
							{ __( 'Back', 'estasoft-erp-crm-sync-engine' ) }
						</Btn>
					) : null }
					<Btn
						variant="primary"
						onClick={ onContinue }
						disabled={ ! canContinue || move.busy || start.busy }
						busy={ move.busy || start.busy }
					>
						{ continueLabel }
					</Btn>
				</div>
			</div>
			{ confirmLive ? (
				<ConfirmDialog
					title={ __(
						'Go live now?',
						'estasoft-erp-crm-sync-engine'
					) }
					confirmLabel={ __(
						'Go live now',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setConfirmLive( false ) }
					onConfirm={ doStart }
				>
					<p>
						{ sprintf(
							/* translators: %s: system name */ __(
								'New orders will be sent to %s as they arrive. Orders placed before now are not sent.',
								'estasoft-erp-crm-sync-engine'
							),
							system ||
								__(
									'your system',
									'estasoft-erp-crm-sync-engine'
								)
						) }
					</p>
					<p className="estasoft-sync-muted">
						{ __(
							'Shadow mode compares first and sends nothing. Going live skips that check.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</ConfirmDialog>
			) : null }
		</div>
	);
}
