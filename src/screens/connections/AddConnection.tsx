import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { SystemEntry } from '../../api/local-types';
import type { Connection } from '../../api/types';
import { navigate } from '../../app/router';
import { Btn } from '../../components/Btn';
import {
	isConnectedNow,
	isSelectable,
	needsVariant,
	resolveSystem,
} from '../../lib/connect';
import { PageHeader } from '../../components/PageHeader';
import { ChooseSystem } from './ChooseSystem';
import { ConnectSystem } from './ConnectSystem';

/**
 * Add another connection: choose a system, connect it, then open it. The setup wizard is for the
 * first connection; this is for the next ones.
 *
 * @return The screen.
 */
export function AddConnection() {
	const [ row, setRow ] = useState< SystemEntry | null >( null );
	const [ variant, setVariant ] = useState< string | null >( null );
	const entry = row ? resolveSystem( row, variant ) : null;
	const [ connecting, setConnecting ] = useState( false );
	const [ created, setCreated ] = useState< Connection | null >( null );
	return (
		<>
			<PageHeader
				title={
					connecting
						? __(
								'Connect a system',
								'estasoft-erp-crm-sync-engine'
							)
						: __(
								'Choose a system',
								'estasoft-erp-crm-sync-engine'
							)
				}
				breadcrumb={ [
					{
						label: __(
							'Connections',
							'estasoft-erp-crm-sync-engine'
						),
						to: '/connections',
					},
					{
						label: __(
							'Add a connection',
							'estasoft-erp-crm-sync-engine'
						),
					},
				] }
			/>
			{ connecting && entry ? (
				<ConnectSystem
					entry={ entry }
					existing={ created }
					onConnected={ setCreated }
				/>
			) : (
				<ChooseSystem
					selected={ row?.name ?? null }
					variant={ variant }
					onSelect={ ( picked ) => {
						setRow( picked );
						setVariant( null );
						setCreated( null );
					} }
					onVariant={ ( id ) => {
						setVariant( id );
						setCreated( null );
					} }
				/>
			) }
			<div className="estasoft-sync-wizard__foot">
				<span />
				<div className="estasoft-sync-actions">
					<Btn
						onClick={ () =>
							connecting
								? setConnecting( false )
								: navigate( '/connections' )
						}
					>
						{ connecting
							? __( 'Back', 'estasoft-erp-crm-sync-engine' )
							: __( 'Cancel', 'estasoft-erp-crm-sync-engine' ) }
					</Btn>
					{ connecting ? (
						<Btn
							variant="primary"
							disabled={ ! isConnectedNow( created ) }
							onClick={ () =>
								created &&
								navigate( `/connections/${ created.id }` )
							}
						>
							{ __(
								'Open the connection',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					) : (
						<Btn
							variant="primary"
							disabled={
								! row ||
								! isSelectable( row ) ||
								needsVariant( row, variant )
							}
							onClick={ () => setConnecting( true ) }
						>
							{ __( 'Continue', 'estasoft-erp-crm-sync-engine' ) }
						</Btn>
					) }
				</div>
			</div>
		</>
	);
}
