import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { ApiError } from '../../api/client';
import { api } from '../../api/endpoints';
import type { Connection } from '../../api/types';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { Busy } from '../../components/EmptyState';
import { SelectField } from '../../components/Fields';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useResource } from '../../hooks/useResource';
import { errorText } from '../../lib/text';

export interface OrganisationStepProps {
	connection: Connection;
	system: string;
	/** Called once, with the connection as saved, when the organisation is settled (chosen, the only one, or there is no choice). */
	onDone: ( connection: Connection ) => void;
	/** Ask the question again even when it was settled before (a person who wants to switch). */
	label?: string;
}

/**
 * After sign-in: which organisation or company gets the orders. Nothing to ask when the system has
 * none or one (it is used); a list to pick from when it has several. The answer is saved before
 * the step is done.
 *
 * @param props            The connection and what to do when it is settled.
 * @param props.connection
 * @param props.system
 * @param props.onDone
 * @param props.label
 * @return The picker, or nothing while there is no choice to make.
 */
export function OrganisationStep( {
	connection,
	system,
	onDone,
	label,
}: OrganisationStepProps ) {
	const list = useResource(
		( signal ) =>
			api.connections.oauthOrganisations( connection.id, signal ),
		[ connection.id ]
	);
	const [ chosen, setChosen ] = useState( '' );
	const finished = useRef( false );
	const save = useAsyncAction( async ( id: string ) => {
		const updated = await api.connections.oauthSelectOrganisation(
			connection.id,
			id
		);
		finished.current = true;
		onDone( updated );
	} );
	const organisations = list.data ?? [];
	// A system that does not use sign-in answers SYNC-CFG-004: there is nothing to choose.
	const noChoice =
		list.error instanceof ApiError && list.error.code === 'SYNC-CFG-004';

	useEffect( () => {
		if ( finished.current || list.loading ) {
			return;
		}
		if ( noChoice || ( list.data && list.data.length === 0 ) ) {
			finished.current = true;
			onDone( connection );
			return;
		}
		if ( list.data && list.data.length === 1 ) {
			void save.run( list.data[ 0 ].id );
		}
		// Settle once, when the list arrives.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ list.data, list.loading, noChoice ] );

	if ( list.loading ) {
		return (
			<Busy
				label={ __(
					'Looking for your organisations…',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			/>
		);
	}
	if ( list.error && ! noChoice ) {
		return (
			<Callout
				tone="danger"
				role="alert"
				action={
					<Btn small onClick={ () => void list.reload() }>
						{ __(
							'Try again',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</Btn>
				}
			>
				{ errorText( list.error ) }
			</Callout>
		);
	}
	if ( organisations.length <= 1 ) {
		return save.error ? (
			<Callout tone="danger" role="alert">
				{ errorText( save.error ) }
			</Callout>
		) : null;
	}
	const fieldLabel =
		label ?? __( 'Organisation', 'estasoft-enterprise-erpcrm-sync-engine' );
	return (
		<Card
			title={ sprintf(
				/* translators: %s: system name */
				__(
					'Which %s organisation gets the orders?',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				system
			) }
		>
			<SelectField
				label={ fieldLabel }
				value={ chosen }
				onChange={ setChosen }
				options={ [
					{
						value: '',
						label: __(
							'Choose…',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
					},
					...organisations.map( ( organisation ) => ( {
						value: organisation.id,
						label: organisation.name,
					} ) ),
				] }
				hint={ __(
					'If you have more than one, pick the one that should receive these orders.',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			/>
			<div className="estasoft-sync-actions">
				<Btn
					variant="primary"
					disabled={ ! chosen || save.busy }
					busy={ save.busy }
					onClick={ () => void save.run( chosen ) }
				>
					{ sprintf(
						/* translators: %s: "Organisation" or "Company" */
						__(
							'Use this %s',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						fieldLabel.toLowerCase()
					) }
				</Btn>
			</div>
			{ save.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( save.error ) }
				</Callout>
			) : null }
		</Card>
	);
}
