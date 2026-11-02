import { speak } from '@wordpress/a11y';
import { useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ApiError } from '../../api/client';
import { ERROR_CATALOG } from '../../api/errors';
import { api } from '../../api/endpoints';
import type { ExportFileInfo } from '../../api/local-types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { Link } from '../../app/router';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { DateTime } from '../../components/DateTime';
import { ConfirmDialog } from '../../components/Dialog';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { StatusBadge } from '../../components/StatusBadge';
import { PageHeader } from '../../components/PageHeader';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { StateChip } from '../../components/StateChip';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useKeepFocus } from '../../hooks/useKeepFocus';
import { useResource } from '../../hooks/useResource';
import { entryForConnector } from '../../lib/connect';
import { downloadBlob } from '../../lib/download';
import { formatFileSize } from '../../lib/format';
import { catalogMessage, errorText, ordersPhrase } from '../../lib/text';
import { importGuideFor } from './guides';

/**
 * The code and words of a failed export (SYNC-EXP-001 and the rest), taken from the answer.
 *
 * @param error What was thrown.
 * @return The message and code.
 */
function failureOf( error: unknown ): {
	message: string;
	code: string;
	remedy: string;
} {
	const code = error instanceof ApiError ? error.code : '';
	return {
		message: errorText( error ),
		code,
		remedy: ERROR_CATALOG[ code ]?.remedy ?? '',
	};
}

/**
 * Exports (board D06): for a connection that uses files. Shows what is waiting, makes a file on
 * request, lists the files with Download and Delete, lists the orders a file had to leave out and
 * why, and says how to import the file in the program that reads it. Nothing here is sent anywhere.
 *
 * @param props    The connection id.
 * @param props.id
 * @return The screen.
 */
export function ExportsScreen( { id }: { id: number } ) {
	const { canConfigure, canManage } = useApp();
	const { notify } = useNotices();
	const connection = useResource(
		( signal ) => api.connections.get( id, signal ),
		[ id ]
	);
	const catalog = useResource( ( signal ) => api.catalog.list( signal ), [] );
	const list = useResource(
		( signal ) => api.exports.list( id, signal ),
		[ id ],
		{ poll: 60000 }
	);
	const [ made, setMade ] = useState< ExportFileInfo | null >( null );
	const [ confirm, setConfirm ] = useState<
		{ kind: 'one'; file: ExportFileInfo } | { kind: 'all' } | null
	>( null );
	const [ downloading, setDownloading ] = useState< string | null >( null );

	const create = useAsyncAction( async () => {
		setMade( null );
		const file = await api.exports.create( id );
		setMade( file );
		notify( {
			kind: 'success',
			text: sprintf(
				/* translators: 1: file name, 2: number of orders */
				_n(
					'The export file %1$s is ready, with %2$d order.',
					'The export file %1$s is ready, with %2$d orders.',
					file.orders,
					'estasoft-erp-crm-sync-engine'
				),
				file.filename,
				file.orders
			),
		} );
		await list.reload();
	} );
	// The button is switched off while the file is made, and again when no order is left to put in one: the keyboard goes to the result.
	const madeBox = useRef< HTMLDivElement >( null );
	useKeepFocus( create.busy, madeBox );
	const download = useAsyncAction(
		async ( file: ExportFileInfo, part?: number ) => {
			const name = part
				? ( file.attachments?.[ part - 1 ]?.filename ?? file.filename )
				: file.filename;
			setDownloading( `${ file.id }:${ part ?? 0 }` );
			try {
				const blob = await api.exports.download( file.id, part );
				downloadBlob( name, blob );
				speak(
					sprintf(
						/* translators: %s: file name */
						__( 'Downloaded %s', 'estasoft-erp-crm-sync-engine' ),
						name
					),
					'polite'
				);
			} finally {
				setDownloading( null );
			}
		}
	);

	const entry = entryForConnector(
		catalog.data,
		connection.data?.connector ?? ''
	);
	const system = entry?.name ?? connection.data?.label ?? '';
	const files = list.data?.files ?? [];
	const batch = list.data?.open_batch ?? null;
	const waiting = batch?.orders ?? 0;
	const crumbs = [
		{
			label: __( 'Connections', 'estasoft-erp-crm-sync-engine' ),
			to: '/connections',
		},
		{
			label: system || __( 'Connection', 'estasoft-erp-crm-sync-engine' ),
			to: `/connections/${ id }`,
		},
		{ label: __( 'Exports', 'estasoft-erp-crm-sync-engine' ) },
	];

	if ( list.loading || connection.loading ) {
		return (
			<>
				<PageHeader
					title={ __( 'Exports', 'estasoft-erp-crm-sync-engine' ) }
					breadcrumb={ crumbs }
				/>
				<LoadingState
					rows={ 4 }
					label={ __(
						'Loading the exports',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}
	if ( ! list.data || ! connection.data ) {
		return (
			<>
				<PageHeader
					title={ __( 'Exports', 'estasoft-erp-crm-sync-engine' ) }
					breadcrumb={ crumbs }
				/>
				<ErrorState
					error={ list.error ?? connection.error }
					onRetry={ () => {
						void list.reload();
						void connection.reload();
					} }
					title={ __(
						'The exports could not be loaded.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}

	const guide = importGuideFor( connection.data.connector, system );
	const withLeftOut = files.find( ( file ) => file.left_out.length > 0 );
	const createFailure = create.error ? failureOf( create.error ) : null;
	const downloadFailure = download.error ? failureOf( download.error ) : null;
	const madeLeftOut = made?.left_out ?? [];

	return (
		<>
			<PageHeader
				title={ __( 'Exports', 'estasoft-erp-crm-sync-engine' ) }
				breadcrumb={ crumbs }
				description={ __(
					'This connection uses files. The plugin writes a file, you import it.',
					'estasoft-erp-crm-sync-engine'
				) }
			/>

			<Card
				title={ __(
					'Create an export',
					'estasoft-erp-crm-sync-engine'
				) }
				description={ [ system, connection.data.company ]
					.filter( Boolean )
					.join( ', ' ) }
			>
				{ waiting > 0 ? (
					<p>
						<strong>
							{ sprintf(
								/* translators: %s: number of orders such as "38 orders" */
								__(
									'Everything since the last export: %s.',
									'estasoft-erp-crm-sync-engine'
								),
								ordersPhrase( waiting )
							) }
						</strong>{ ' ' }
						{ batch?.since ? (
							<span className="estasoft-sync-muted">
								{ __(
									'The oldest has been waiting since',
									'estasoft-erp-crm-sync-engine'
								) }{ ' ' }
								<DateTime value={ batch.since } kind="smart" />.
							</span>
						) : null }
					</p>
				) : (
					<p className="estasoft-sync-muted">
						{ __(
							'No orders are waiting for a file. New orders appear here as they are placed.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				) }
				<p className="estasoft-sync-muted">
					{ sprintf(
						/* translators: %s: system name */
						__(
							'Check %s first. Every ledger, account, tax code and item named in the file must already exist there, or the program refuses it. Making a file never sends it anywhere.',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
				</p>
				<div className="estasoft-sync-actions">
					<Btn
						variant="primary"
						busy={ create.busy }
						disabled={
							! canConfigure || waiting === 0 || create.busy
						}
						onClick={ () => void create.run() }
					>
						{ __(
							'Create export file now',
							'estasoft-erp-crm-sync-engine'
						) }
					</Btn>
					{ ! canConfigure ? (
						<span className="estasoft-sync-muted">
							{ __(
								'Only an administrator can create a file.',
								'estasoft-erp-crm-sync-engine'
							) }
						</span>
					) : null }
				</div>
				{ createFailure ? (
					<Callout
						tone="danger"
						role="alert"
						action={
							<Btn small onClick={ () => void create.run() }>
								{ __(
									'Create again',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						}
					>
						<p>
							{ createFailure.message }{ ' ' }
							{ createFailure.code ? (
								<span className="estasoft-sync-mono">
									{ createFailure.code }
								</span>
							) : null }
						</p>
						<p className="estasoft-sync-muted">
							{ createFailure.code === 'SYNC-EXP-001'
								? __(
										'Nothing is lost: the orders stay Queued.',
										'estasoft-erp-crm-sync-engine'
									)
								: '' }{ ' ' }
							{ createFailure.remedy }
						</p>
					</Callout>
				) : null }
				{ made && ! createFailure ? (
					<div ref={ madeBox } tabIndex={ -1 }>
						<Callout tone="success" role="status">
							<p className="estasoft-sync-strong">
								{ sprintf(
									/* translators: 1: file name, 2: number of orders */
									_n(
										'%1$s is ready, with %2$d order.',
										'%1$s is ready, with %2$d orders.',
										made.orders,
										'estasoft-erp-crm-sync-engine'
									),
									made.filename,
									made.orders
								) }
							</p>
							<p>
								<Btn
									small
									icon="download"
									onClick={ () => void download.run( made ) }
								>
									{ __(
										'Download',
										'estasoft-erp-crm-sync-engine'
									) }
								</Btn>
							</p>
						</Callout>
					</div>
				) : null }
				{ madeLeftOut.length > 0 ? (
					<Callout tone="warning" role="status">
						{ sprintf(
							/* translators: %s: number of orders such as "3 orders" */
							__(
								'%s were left out of this file. See the list below.',
								'estasoft-erp-crm-sync-engine'
							),
							ordersPhrase( madeLeftOut.length )
						) }{ ' ' }
						<span className="estasoft-sync-mono">SYNC-EXP-002</span>
					</Callout>
				) : null }
			</Card>

			{ withLeftOut ? (
				<Card
					title={ __(
						'Orders left out of a file',
						'estasoft-erp-crm-sync-engine'
					) }
					description={ sprintf(
						/* translators: %s: file name */
						__( 'From %s', 'estasoft-erp-crm-sync-engine' ),
						withLeftOut.filename
					) }
					flush
				>
					<div className="estasoft-sync-pad">
						<Callout tone="warning">
							<p>
								{ catalogMessage(
									'SYNC-EXP-002',
									{
										n: String(
											withLeftOut.left_out.length
										),
										reason: __(
											'see the reasons below',
											'estasoft-erp-crm-sync-engine'
										),
									},
									system
								) }{ ' ' }
								<span className="estasoft-sync-mono">
									SYNC-EXP-002
								</span>
							</p>
							<p className="estasoft-sync-muted">
								{ __(
									'Nothing is lost: these orders are listed under Needs attention. Fix the cause, retry them there, and they go into the next file.',
									'estasoft-erp-crm-sync-engine'
								) }{ ' ' }
								<Link
									to="/queue/attention"
									className="estasoft-sync-link"
								>
									{ __(
										'Open Needs attention',
										'estasoft-erp-crm-sync-engine'
									) }
								</Link>
							</p>
						</Callout>
					</div>
					<ResponsiveTable<
						ExportFileInfo[ 'left_out' ][ number ],
						string
					>
						caption={ __(
							'Orders left out of the export',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ withLeftOut.left_out }
						rowKey={ ( row ) => `${ row.order }|${ row.reason }` }
						columns={ [
							{
								id: 'order',
								header: __(
									'Order',
									'estasoft-erp-crm-sync-engine'
								),
								primary: true,
								cell: ( row ) => (
									<span className="estasoft-sync-strong">
										{ row.order }
									</span>
								),
							},
							{
								id: 'reason',
								header: __(
									'Why it was left out',
									'estasoft-erp-crm-sync-engine'
								),
								wide: true,
								cell: ( row ) => row.reason,
							},
						] }
					/>
				</Card>
			) : null }

			<Card
				title={ __(
					'Previous exports',
					'estasoft-erp-crm-sync-engine'
				) }
				description={ __(
					'Files are deleted after 30 days.',
					'estasoft-erp-crm-sync-engine'
				) }
				flush={ files.length > 0 }
			>
				{ downloadFailure ? (
					<div className="estasoft-sync-pad">
						<Callout tone="danger" role="alert">
							{ downloadFailure.message }{ ' ' }
							{ downloadFailure.code ? (
								<span className="estasoft-sync-mono">
									{ downloadFailure.code }
								</span>
							) : null }
						</Callout>
					</div>
				) : null }
				{ files.length === 0 ? (
					<EmptyState
						title={ __(
							'No exports yet',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						{ __(
							'Press Create export file now to make the first file. Nothing is sent anywhere.',
							'estasoft-erp-crm-sync-engine'
						) }
					</EmptyState>
				) : (
					<ResponsiveTable< ExportFileInfo, string | number >
						caption={ __(
							'Previous exports',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ files }
						rowKey={ ( file ) => file.id }
						columns={ [
							{
								id: 'file',
								header: __(
									'File',
									'estasoft-erp-crm-sync-engine'
								),
								primary: true,
								cell: ( file ) => (
									<>
										<span
											className="estasoft-sync-strong estasoft-sync-mono"
											dir="ltr"
										>
											{ file.filename }
										</span>
										<span className="estasoft-sync-muted estasoft-sync-block">
											{ [
												file.format_label,
												formatFileSize(
													file.size_bytes
												),
											]
												.filter( Boolean )
												.join( ' · ' ) }
										</span>
									</>
								),
							},
							{
								id: 'made',
								header: __(
									'Made',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( file ) => (
									<>
										<DateTime
											value={ file.created_at }
											kind="smart"
										/>
										{ file.created_by ? (
											<span className="estasoft-sync-muted estasoft-sync-block">
												{ sprintf(
													/* translators: %s: name of the person */
													__(
														'By %s',
														'estasoft-erp-crm-sync-engine'
													),
													file.created_by
												) }
											</span>
										) : null }
									</>
								),
							},
							{
								id: 'contents',
								header: __(
									'Contents',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( file ) => (
									<>
										{ ordersPhrase( file.orders ) }
										{ file.left_out.length > 0 ? (
											<span className="estasoft-sync-block">
												<StatusBadge tone="warning">
													{ sprintf(
														/* translators: %s: number of orders such as "3 orders" */
														__(
															'%s left out',
															'estasoft-erp-crm-sync-engine'
														),
														ordersPhrase(
															file.left_out.length
														)
													) }
												</StatusBadge>
											</span>
										) : null }
									</>
								),
							},
							{
								id: 'expires',
								header: __(
									'Deleted on',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( file ) => (
									<DateTime
										value={ file.expires_at }
										kind="date"
									/>
								),
							},
							{
								id: 'actions',
								header: __(
									'Actions',
									'estasoft-erp-crm-sync-engine'
								),
								hideHeader: true,
								align: 'end',
								cell: ( file ) => (
									<span className="estasoft-sync-actions">
										{ canManage ? (
											<Btn
												small
												icon="download"
												busy={
													downloading ===
													`${ file.id }:0`
												}
												disabled={ download.busy }
												aria-label={ sprintf(
													/* translators: %s: file name */
													__(
														'Download %s',
														'estasoft-erp-crm-sync-engine'
													),
													file.filename
												) }
												onClick={ () =>
													void download.run( file )
												}
											>
												{ __(
													'Download',
													'estasoft-erp-crm-sync-engine'
												) }
											</Btn>
										) : null }
										{ canManage
											? ( file.attachments ?? [] ).map(
													( attachment, index ) => (
														<Btn
															key={
																attachment.filename
															}
															small
															icon="download"
															busy={
																downloading ===
																`${ file.id }:${ index + 1 }`
															}
															disabled={
																download.busy
															}
															aria-label={ sprintf(
																/* translators: 1: name of the list, 2: file name */
																__(
																	'Download %1$s for %2$s',
																	'estasoft-erp-crm-sync-engine'
																),
																attachment.name,
																file.filename
															) }
															onClick={ () =>
																void download.run(
																	file,
																	index + 1
																)
															}
														>
															{ attachment.name }
														</Btn>
													)
												)
											: null }
										{ canConfigure ? (
											<Btn
												small
												aria-label={ sprintf(
													/* translators: %s: file name */
													__(
														'Delete %s',
														'estasoft-erp-crm-sync-engine'
													),
													file.filename
												) }
												onClick={ () =>
													setConfirm( {
														kind: 'one',
														file,
													} )
												}
											>
												{ __(
													'Delete',
													'estasoft-erp-crm-sync-engine'
												) }
											</Btn>
										) : null }
									</span>
								),
							},
						] }
					/>
				) }
			</Card>

			<Card title={ guide.title }>
				<ol className="estasoft-sync-ol">
					{ guide.steps.map( ( step ) => (
						<li key={ step }>{ step }</li>
					) ) }
				</ol>
				{ guide.warning ? (
					<Callout tone="warning">{ guide.warning }</Callout>
				) : null }
			</Card>

			<Card
				title={ __(
					'How these orders look in the queue',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				<p className="estasoft-sync-actions">
					<StateChip
						state="queued"
						label={ __( 'Queued', 'estasoft-erp-crm-sync-engine' ) }
					/>
					<StateChip
						state="synced"
						label={ __(
							'Exported',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
				</p>
				<p>
					{ sprintf(
						/* translators: %s: system name */
						__(
							'An order is Queued until the next export includes it, then Exported. Exported means the order is in a file. It does not mean %s has it: the plugin cannot see whether the program accepted the file.',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
				</p>
			</Card>

			<Card
				title={ __(
					'Where the files are kept',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				<ul className="estasoft-sync-list">
					<li>
						{ __(
							'In a folder inside your uploads folder, with a closed index file and a rule that blocks direct web access. File names are long and random. Some servers ignore those rules, so the plugin never links to a file: every download goes through this screen.',
							'estasoft-erp-crm-sync-engine'
						) }
					</li>
					<li>
						{ __(
							'Only people with the Manage sync permission can download a file.',
							'estasoft-erp-crm-sync-engine'
						) }
					</li>
					<li>
						{ __(
							'A file holds customer names, addresses and phone numbers. Treat it as personal data. Files are deleted after 30 days.',
							'estasoft-erp-crm-sync-engine'
						) }
					</li>
					<li>
						{ __(
							'Nothing is uploaded to Estasoft or to any other website.',
							'estasoft-erp-crm-sync-engine'
						) }
					</li>
				</ul>
				{ canConfigure && files.length > 0 ? (
					<div className="estasoft-sync-actions">
						<Btn
							danger
							small
							onClick={ () => setConfirm( { kind: 'all' } ) }
						>
							{ __(
								'Delete all export files now',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					</div>
				) : null }
				<p className="estasoft-sync-muted">
					<Link
						to={ `/connections/${ id }` }
						className="estasoft-sync-link"
					>
						{ sprintf(
							/* translators: %s: system name */
							__(
								'Back to the %s connection',
								'estasoft-erp-crm-sync-engine'
							),
							system
						) }
					</Link>
				</p>
			</Card>

			{ confirm ? (
				<ConfirmDialog
					title={
						confirm.kind === 'one'
							? __(
									'Delete this export file?',
									'estasoft-erp-crm-sync-engine'
								)
							: __(
									'Delete all export files?',
									'estasoft-erp-crm-sync-engine'
								)
					}
					tone="danger"
					confirmLabel={ __(
						'Delete',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setConfirm( null ) }
					onConfirm={ async () => {
						const targets =
							confirm.kind === 'one' ? [ confirm.file ] : files;
						for ( const file of targets ) {
							await api.exports.remove( file.id );
						}
						notify( {
							kind: 'success',
							text: sprintf(
								/* translators: %d: number of files */
								_n(
									'%d export file was deleted.',
									'%d export files were deleted.',
									targets.length,
									'estasoft-erp-crm-sync-engine'
								),
								targets.length
							),
						} );
						await list.reload();
					} }
				>
					<p>
						{ confirm.kind === 'one'
							? sprintf(
									/* translators: %s: file name */
									__(
										'%s is removed from your website and cannot be restored. The state of the orders in the queue does not change.',
										'estasoft-erp-crm-sync-engine'
									),
									confirm.file.filename
								)
							: sprintf(
									/* translators: %d: number of files */
									_n(
										'The %d file is removed from your website and cannot be restored. The state of the orders in the queue does not change.',
										'The %d files are removed from your website and cannot be restored. The state of the orders in the queue does not change.',
										files.length,
										'estasoft-erp-crm-sync-engine'
									),
									files.length
								) }
					</p>
				</ConfirmDialog>
			) : null }
		</>
	);
}
