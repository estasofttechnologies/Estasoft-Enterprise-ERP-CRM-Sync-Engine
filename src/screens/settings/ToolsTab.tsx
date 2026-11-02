import { useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { useStatus } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { ConfirmDialog } from '../../components/Dialog';
import { TextField } from '../../components/Fields';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { downloadText } from '../../lib/download';
import { errorText } from '../../lib/text';
import { CapacityTestDialog } from './CapacityTestDialog';

/** The word that must be typed to delete everything. */
export const DELETE_WORD = 'DELETE';

/**
 * Tools: export and import settings, capacity test, clear logs, delete all plugin data.
 *
 * @return The tab.
 */
export function ToolsTab() {
	const { canConfigure } = useApp();
	const { notify } = useNotices();
	const { reload } = useStatus();
	const [ dialog, setDialog ] = useState<
		| 'capacity'
		| 'clear'
		| 'delete'
		| { kind: 'import'; json: string; preview: string[] }
		| null
	>( null );
	const file = useRef< HTMLInputElement >( null );
	const exportSettings = useAsyncAction( async () => {
		const result = await api.tools.export();
		downloadText( result.filename, result.json, 'application/json' );
		notify( {
			kind: 'success',
			text: sprintf(
				/* translators: %s: file name */ __(
					'Settings exported to %s. Credentials, orders and logs are not in the file.',
					'estasoft-erp-crm-sync-engine'
				),
				result.filename
			),
		} );
	} );
	const chooseFile = useAsyncAction( async ( chosen: File ) => {
		const json = await chosen.text();
		const result = await api.tools.import( json, false );
		if ( 'preview' in result ) {
			setDialog( { kind: 'import', json, preview: result.preview } );
		}
	} );
	const error = exportSettings.error ?? chooseFile.error;

	return (
		<div className="estasoft-sync-stack">
			{ ! canConfigure ? (
				<Callout tone="info">
					{ __(
						'Only administrators can use the tools.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			) : null }
			{ error ? (
				<Callout tone="danger" role="alert">
					{ errorText( error ) }
				</Callout>
			) : null }
			<Card title={ __( 'Tools', 'estasoft-erp-crm-sync-engine' ) } flush>
				<ul className="estasoft-sync-attlist">
					<li className="estasoft-sync-att">
						<div className="estasoft-sync-att__text">
							<p className="estasoft-sync-strong">
								{ __(
									'Export settings',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
							<p className="estasoft-sync-muted">
								{ __(
									'Mapping, rules, limits and notification settings as a file. Credentials, orders and logs are not included.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						</div>
						<Btn
							small
							icon="download"
							busy={ exportSettings.busy }
							disabled={ ! canConfigure }
							onClick={ () => void exportSettings.run() }
						>
							{ __( 'Export', 'estasoft-erp-crm-sync-engine' ) }
						</Btn>
					</li>
					<li className="estasoft-sync-att">
						<div className="estasoft-sync-att__text">
							<p className="estasoft-sync-strong">
								{ __(
									'Import settings',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
							<p className="estasoft-sync-muted">
								{ __(
									'Shows what will change before anything is applied. Mapping arrives as drafts, never live.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						</div>
						<input
							ref={ file }
							type="file"
							accept="application/json,.json"
							className="estasoft-sync-sr-only"
							tabIndex={ -1 }
							aria-hidden="true"
							onChange={ ( event ) => {
								const chosen = event.target.files?.[ 0 ];
								if ( chosen ) {
									void chooseFile.run( chosen );
								}
								event.target.value = '';
							} }
						/>
						<Btn
							small
							icon="upload"
							busy={ chooseFile.busy }
							disabled={ ! canConfigure }
							onClick={ () => file.current?.click() }
						>
							{ __(
								'Choose a file',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					</li>
					<li className="estasoft-sync-att">
						<div className="estasoft-sync-att__text">
							<p className="estasoft-sync-strong">
								{ __(
									'Run a capacity test',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
							<p className="estasoft-sync-muted">
								{ __(
									'Sends test items through a connection that sends nothing, and reports how many your server handles a minute.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						</div>
						<Btn
							small
							icon="play"
							disabled={ ! canConfigure }
							onClick={ () => setDialog( 'capacity' ) }
						>
							{ __( 'Run test', 'estasoft-erp-crm-sync-engine' ) }
						</Btn>
					</li>
					<li className="estasoft-sync-att">
						<div className="estasoft-sync-att__text">
							<p className="estasoft-sync-strong">
								{ __(
									'Clear the logs',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
							<p className="estasoft-sync-muted">
								{ __(
									'Removes log entries. Orders and the queue are kept.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						</div>
						<Btn
							small
							danger
							variant="primary"
							disabled={ ! canConfigure }
							onClick={ () => setDialog( 'clear' ) }
						>
							{ __(
								'Clear logs',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					</li>
					<li className="estasoft-sync-att">
						<div className="estasoft-sync-att__text">
							<p className="estasoft-sync-strong">
								{ __(
									'Delete all plugin data',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
							<p className="estasoft-sync-muted">
								{ __(
									'Removes the queue, logs, mappings and saved credentials. Nothing in WooCommerce or your ERP is deleted.',
									'estasoft-erp-crm-sync-engine'
								) }
							</p>
						</div>
						<Btn
							small
							danger
							variant="primary"
							disabled={ ! canConfigure }
							onClick={ () => setDialog( 'delete' ) }
						>
							{ __(
								'Delete data',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					</li>
				</ul>
			</Card>

			{ dialog === 'capacity' ? (
				<CapacityTestDialog onClose={ () => setDialog( null ) } />
			) : null }
			{ dialog === 'clear' ? (
				<ConfirmDialog
					title={ __(
						'Clear the logs?',
						'estasoft-erp-crm-sync-engine'
					) }
					tone="danger"
					confirmLabel={ __(
						'Clear logs',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setDialog( null ) }
					onConfirm={ async () => {
						const result = await api.tools.clearLogs();
						notify( {
							kind: 'success',
							text: sprintf(
								/* translators: %d: number of entries */
								_n(
									'Logs cleared: %d entry removed. The queue, orders and settings are kept.',
									'Logs cleared: %d entries removed. The queue, orders and settings are kept.',
									result.cleared,
									'estasoft-erp-crm-sync-engine'
								),
								result.cleared
							),
						} );
					} }
				>
					<p>
						{ __(
							'This removes all log entries. The queue, orders and settings are kept.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					<p className="estasoft-sync-muted">
						{ __(
							'Support reports will have nothing to show until new events happen.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</ConfirmDialog>
			) : null }
			{ dialog === 'delete' ? (
				<DeleteDialog
					onClose={ () => setDialog( null ) }
					onExport={ () => void exportSettings.run() }
					onDeleted={ () => void reload() }
				/>
			) : null }
			{ typeof dialog === 'object' &&
			dialog &&
			dialog.kind === 'import' ? (
				<ConfirmDialog
					title={ __(
						'Import settings?',
						'estasoft-erp-crm-sync-engine'
					) }
					confirmLabel={ __(
						'Import',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setDialog( null ) }
					onConfirm={ async () => {
						await api.tools.import( dialog.json, true );
						notify( {
							kind: 'success',
							text: __(
								'Settings imported. Mapping arrived as drafts; review and publish them when you are ready.',
								'estasoft-erp-crm-sync-engine'
							),
						} );
					} }
				>
					<p>
						{ __(
							'This is what will change:',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					<ul className="estasoft-sync-list">
						{ dialog.preview.length === 0 ? (
							<li>
								{ __(
									'Nothing differs from your current settings.',
									'estasoft-erp-crm-sync-engine'
								) }
							</li>
						) : (
							dialog.preview.map( ( line ) => (
								<li key={ line }>{ line }</li>
							) )
						) }
					</ul>
					<p className="estasoft-sync-muted">
						{ __(
							'Mapping arrives as drafts that you review and publish yourself. Tax mapping, limits and notification settings apply as soon as you import. No credentials are in the file.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</ConfirmDialog>
			) : null }
		</div>
	);
}

function DeleteDialog( {
	onClose,
	onExport,
	onDeleted,
}: {
	onClose: () => void;
	onExport: () => void;
	onDeleted: () => void;
} ) {
	const [ typed, setTyped ] = useState( '' );
	const { notify } = useNotices();
	return (
		<ConfirmDialog
			title={ __(
				'Delete all plugin data?',
				'estasoft-erp-crm-sync-engine'
			) }
			tone="danger"
			confirmLabel={ __(
				'Delete everything',
				'estasoft-erp-crm-sync-engine'
			) }
			confirmDisabled={ typed !== DELETE_WORD }
			onClose={ onClose }
			extraAction={
				<Btn variant="tertiary" onClick={ onExport }>
					{ __(
						'Export settings first',
						'estasoft-erp-crm-sync-engine'
					) }
				</Btn>
			}
			onConfirm={ async () => {
				await api.tools.deleteData( typed );
				notify( {
					kind: 'success',
					text: __(
						'All plugin data was deleted. Syncing has stopped.',
						'estasoft-erp-crm-sync-engine'
					),
				} );
				onDeleted();
			} }
		>
			<p>
				{ __(
					'This removes the queue, logs, mapping versions, saved credentials and settings from this site. It cannot be undone.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
			<p className="estasoft-sync-muted">
				{ __(
					'WooCommerce orders, customers and products stay as they are. Nothing in your system is deleted. Syncing stops.',
					'estasoft-erp-crm-sync-engine'
				) }
			</p>
			<Callout tone="warning">
				{ __(
					'The plugin also forgets which orders it already sent. If you connect again afterwards, orders that were already sent can be sent a second time.',
					'estasoft-erp-crm-sync-engine'
				) }
			</Callout>
			<div className="estasoft-sync-typed">
				<TextField
					label={ sprintf(
						/* translators: %s: the word to type */ __(
							'Type %s to confirm',
							'estasoft-erp-crm-sync-engine'
						),
						DELETE_WORD
					) }
					value={ typed }
					onChange={ setTyped }
					autoComplete="off"
					spellCheck={ false }
				/>
			</div>
		</ConfirmDialog>
	);
}
