import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { CatalogEntry, Connection } from '../../api/types';
import { navigate } from '../../app/router';
import { Btn } from '../../components/Btn';
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
	const [ entry, setEntry ] = useState< CatalogEntry | null >( null );
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
					selected={ entry?.name ?? null }
					onSelect={ setEntry }
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
							disabled={ ! created }
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
							disabled={ ! entry }
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
