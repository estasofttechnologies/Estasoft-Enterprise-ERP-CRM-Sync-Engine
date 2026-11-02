import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { LogQuery } from '../../api/local-types';
import type { LogEntry, LogLevel } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useDisplaySettings } from '../../app/DisplaySettingsContext';
import { useNotices } from '../../app/NoticeProvider';
import { Link, navigate, routeTo, useRoute } from '../../app/router';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { CodeBlock } from '../../components/CodeBlock';
import { CopyButton } from '../../components/CopyButton';
import { DateTime } from '../../components/DateTime';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { SelectField, TextField } from '../../components/Fields';
import { KeyValueList } from '../../components/KeyValueList';
import { PageHeader } from '../../components/PageHeader';
import { Pager } from '../../components/Pager';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { SegmentedFilter } from '../../components/SegmentedFilter';
import { StatusBadge } from '../../components/StatusBadge';
import { useDebouncedValue } from '../../hooks/useDebouncedValue';
import { useResource } from '../../hooks/useResource';
import { downloadText } from '../../lib/download';
import { formatDuration } from '../../lib/format';
import { errorText } from '../../lib/text';
import { useSystemName } from '../../app/StatusProvider';
import { SupportReportDialog } from './SupportReportDialog';

type LevelFilter = 'all' | LogLevel;
type Range = NonNullable< LogQuery[ 'range' ] >;

/** The ranges the REST route knows. Anything else in the address falls back to the default. */
const RANGES: Range[] = [ '1h', '24h', '7d', '30d', 'all' ];
const DEFAULT_RANGE: Range = 'all';

function LevelBadge( { level }: { level: LogLevel } ) {
	if ( level === 'error' ) {
		return (
			<StatusBadge tone="danger">
				{ __( 'Error', 'estasoft-erp-crm-sync-engine' ) }
			</StatusBadge>
		);
	}
	if ( level === 'warning' ) {
		return (
			<StatusBadge tone="warning">
				{ __( 'Warning', 'estasoft-erp-crm-sync-engine' ) }
			</StatusBadge>
		);
	}
	return (
		<StatusBadge tone="info" icon="info">
			{ __( 'Info', 'estasoft-erp-crm-sync-engine' ) }
		</StatusBadge>
	);
}

function answer( entry: LogEntry ): string {
	if ( entry.http_status === null ) {
		return entry.level === 'error'
			? __( 'Not sent', 'estasoft-erp-crm-sync-engine' )
			: '—';
	}
	return String( entry.http_status );
}

/**
 * The log: filters, search, a page of entries, CSV download and a support report.
 *
 * @return The screen.
 */
export function Logs() {
	const route = useRoute();
	const { notify } = useNotices();
	const { canManage } = useApp();
	const { settings } = useDisplaySettings();
	const level = (
		[ 'info', 'warning', 'error' ].includes(
			route.params.get( 'level' ) ?? ''
		)
			? route.params.get( 'level' )
			: 'all'
	) as LevelFilter;
	const rangeParam = route.params.get( 'range' ) ?? '';
	const range: Range = RANGES.includes( rangeParam as Range )
		? ( rangeParam as Range )
		: DEFAULT_RANGE;
	const connection = route.params.get( 'connection' ) ?? '';
	const urlSearch = route.params.get( 'search' ) ?? '';
	const page = Math.max( 1, Number( route.params.get( 'page' ) ?? 1 ) || 1 );
	const [ search, setSearch ] = useState( urlSearch );
	const settled = useDebouncedValue( search, 300 );
	const [ report, setReport ] = useState( false );
	const connections = useResource(
		( signal ) => api.connections.list( signal ),
		[]
	);

	const go = ( changes: Record< string, string | number | undefined > ) =>
		navigate(
			routeTo( '/logs', {
				level: level === 'all' ? undefined : level,
				range: range === DEFAULT_RANGE ? undefined : range,
				connection: connection || undefined,
				search: urlSearch || undefined,
				page: page > 1 ? page : undefined,
				...changes,
			} ),
			true
		);
	useEffect( () => {
		if ( settled !== urlSearch ) {
			navigate(
				routeTo( '/logs', {
					level: level === 'all' ? undefined : level,
					range: range === DEFAULT_RANGE ? undefined : range,
					connection: connection || undefined,
					search: settled || undefined,
				} ),
				true
			);
		}
		// Only the settled text should update the address.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ settled ] );

	const query: LogQuery = {
		level: level === 'all' ? undefined : level,
		search: urlSearch || undefined,
		connection_id: connection ? Number( connection ) : undefined,
		range,
		page,
		per_page: 25,
	};
	const list = useResource(
		( signal ) => api.logs.list( query, signal ),
		[ level, range, connection, urlSearch, page ],
		{ keepPrevious: true }
	);
	const items = list.data?.items ?? [];
	const retentionDays = settings?.log_retention_days ?? null;

	const download = async () => {
		try {
			const csv = await api.logs.csv( query );
			downloadText( 'estasoft-sync-log.csv', csv, 'text/csv' );
		} catch ( error ) {
			notify( { kind: 'error', text: errorText( error ) } );
		}
	};

	return (
		<>
			<PageHeader
				title={ __( 'Logs', 'estasoft-erp-crm-sync-engine' ) }
				description={
					retentionDays !== null
						? sprintf(
								/* translators: %d: days logs are kept */ __(
									'Kept for %d days. Names, emails and addresses are removed where the plugin finds them. Check before you share a log.',
									'estasoft-erp-crm-sync-engine'
								),
								retentionDays
							)
						: __(
								'Names, emails and addresses are removed where the plugin finds them. Check before you share a log.',
								'estasoft-erp-crm-sync-engine'
							)
				}
				actions={
					canManage ? (
						<>
							<Btn onClick={ () => setReport( true ) }>
								{ __(
									'Copy a support report',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
							<Btn
								icon="download"
								onClick={ () => void download() }
							>
								{ __(
									'Download as CSV',
									'estasoft-erp-crm-sync-engine'
								) }
							</Btn>
						</>
					) : null
				}
			/>
			<SegmentedFilter< LevelFilter >
				label={ __(
					'Filter by level',
					'estasoft-erp-crm-sync-engine'
				) }
				value={ level }
				onChange={ ( value ) =>
					go( {
						level: value === 'all' ? undefined : value,
						page: undefined,
					} )
				}
				options={ [
					{
						value: 'all',
						label: __( 'All', 'estasoft-erp-crm-sync-engine' ),
						count: level === 'all' ? list.data?.total : undefined,
					},
					{
						value: 'error',
						label: __( 'Errors', 'estasoft-erp-crm-sync-engine' ),
						count: level === 'error' ? list.data?.total : undefined,
					},
					{
						value: 'warning',
						label: __( 'Warnings', 'estasoft-erp-crm-sync-engine' ),
						count:
							level === 'warning' ? list.data?.total : undefined,
					},
					{
						value: 'info',
						label: __( 'Info', 'estasoft-erp-crm-sync-engine' ),
						count: level === 'info' ? list.data?.total : undefined,
					},
				] }
			/>
			<div className="estasoft-sync-filters" role="search">
				<TextField
					label={ __( 'Find', 'estasoft-erp-crm-sync-engine' ) }
					type="search"
					value={ search }
					onChange={ setSearch }
					placeholder={ __(
						'Order, SKU or words in the message',
						'estasoft-erp-crm-sync-engine'
					) }
					spellCheck={ false }
				/>
				<SelectField
					label={ __( 'Connection', 'estasoft-erp-crm-sync-engine' ) }
					value={ connection }
					onChange={ ( value ) =>
						go( {
							connection: value || undefined,
							page: undefined,
						} )
					}
					options={ [
						{
							value: '',
							label: __( 'All', 'estasoft-erp-crm-sync-engine' ),
						},
						...( connections.data ?? [] ).map( ( entry ) => ( {
							value: String( entry.id ),
							label: entry.label,
						} ) ),
					] }
				/>
				<SelectField
					label={ __( 'Range', 'estasoft-erp-crm-sync-engine' ) }
					value={ range }
					onChange={ ( value ) =>
						go( {
							range: value === DEFAULT_RANGE ? undefined : value,
							page: undefined,
						} )
					}
					options={ [
						{
							value: '1h',
							label: __(
								'Last hour',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							value: '24h',
							label: __(
								'Last 24 hours',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							value: '7d',
							label: __(
								'Last 7 days',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							value: '30d',
							label: __(
								'Last 30 days',
								'estasoft-erp-crm-sync-engine'
							),
						},
						{
							value: 'all',
							label: __(
								'Everything kept',
								'estasoft-erp-crm-sync-engine'
							),
						},
					] }
				/>
			</div>
			{ list.loading ? (
				<LoadingState
					rows={ 6 }
					label={ __(
						'Loading the log',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ list.error && ! list.data ? (
				<ErrorState
					error={ list.error }
					onRetry={ () => void list.reload() }
				/>
			) : null }
			{ list.data && items.length === 0 ? (
				<EmptyState
					as="h2"
					title={ __(
						'No log entries',
						'estasoft-erp-crm-sync-engine'
					) }
					action={
						<Btn
							small
							onClick={ () => {
								setSearch( '' );
								navigate( '/logs', true );
							} }
						>
							{ __(
								'Clear filters',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					}
				>
					{ __(
						'Nothing has been logged in this range, or the filters hide it.',
						'estasoft-erp-crm-sync-engine'
					) }
				</EmptyState>
			) : null }
			{ items.length > 0 ? (
				<>
					<ResponsiveTable< LogEntry, number >
						caption={ __(
							'Log entries',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ items }
						rowKey={ ( entry ) => entry.id }
						columns={ [
							{
								id: 'time',
								header: __(
									'Time',
									'estasoft-erp-crm-sync-engine'
								),
								primary: true,
								cell: ( entry ) => (
									<Link
										to={ `/logs/${ entry.id }` }
										className="estasoft-sync-strong estasoft-sync-link"
									>
										<DateTime
											value={ entry.ts }
											kind="seconds"
										/>
									</Link>
								),
							},
							{
								id: 'level',
								header: __(
									'Level',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( entry ) => (
									<LevelBadge level={ entry.level } />
								),
							},
							{
								id: 'what',
								header: __(
									'What happened',
									'estasoft-erp-crm-sync-engine'
								),
								wide: true,
								cell: ( entry ) => (
									<>
										{ entry.message }
										{ entry.code ? (
											<>
												{ ' ' }
												<span className="estasoft-sync-mono">
													{ entry.code }
												</span>
											</>
										) : null }
									</>
								),
							},
							{
								id: 'item',
								header: __(
									'Item',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( entry ) => entry.entity_ref ?? '—',
							},
							{
								id: 'took',
								header: __(
									'Took',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( entry ) =>
									formatDuration( entry.duration_ms ),
							},
							{
								id: 'answer',
								header: __(
									'Answer',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( entry ) => answer( entry ),
							},
						] }
					/>
					<Pager
						page={ list.data?.page ?? 1 }
						totalPages={ list.data?.total_pages ?? 1 }
						total={ list.data?.total ?? items.length }
						perPage={ list.data?.per_page ?? 25 }
						shown={ items.length }
						onPage={ ( next ) =>
							go( { page: next > 1 ? next : undefined } )
						}
					/>
				</>
			) : null }
			{ report ? (
				<SupportReportDialog onClose={ () => setReport( false ) } />
			) : null }
		</>
	);
}

/**
 * One log entry (I07), with the redacted request and answer.
 *
 * @param props    Entry id.
 * @param props.id
 * @return The screen.
 */
export function LogDetail( { id }: { id: number } ) {
	const detail = useResource(
		( signal ) => api.logs.get( id, signal ),
		[ id ]
	);
	const system = useSystemName();
	const entry = detail.data;
	const crumbs = [
		{ label: __( 'Logs', 'estasoft-erp-crm-sync-engine' ), to: '/logs' },
		{ label: __( 'Log entry', 'estasoft-erp-crm-sync-engine' ) },
	];
	if ( detail.loading ) {
		return (
			<>
				<PageHeader
					title={ __( 'Log entry', 'estasoft-erp-crm-sync-engine' ) }
					breadcrumb={ crumbs }
				/>
				<LoadingState
					rows={ 4 }
					label={ __(
						'Loading the log entry',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			</>
		);
	}
	if ( ! entry ) {
		return (
			<>
				<PageHeader
					title={ __( 'Log entry', 'estasoft-erp-crm-sync-engine' ) }
					breadcrumb={ crumbs }
				/>
				<ErrorState
					error={ detail.error }
					onRetry={ () => void detail.reload() }
				/>
			</>
		);
	}
	const text = [
		entry.ts,
		entry.level,
		entry.code,
		entry.message,
		entry.http_status ? `HTTP ${ entry.http_status }` : '',
	]
		.filter( Boolean )
		.join( ' | ' );
	return (
		<>
			<PageHeader
				title={ entry.message }
				breadcrumb={ crumbs }
				badge={ <LevelBadge level={ entry.level } /> }
				actions={
					<CopyButton
						text={ text }
						label={ __(
							'Copy this entry',
							'estasoft-erp-crm-sync-engine'
						) }
						small={ false }
					/>
				}
			/>
			<Card>
				<KeyValueList
					items={ [
						{
							label: __( 'Time', 'estasoft-erp-crm-sync-engine' ),
							value: (
								<DateTime value={ entry.ts } kind="seconds" />
							),
						},
						{
							label: __( 'Code', 'estasoft-erp-crm-sync-engine' ),
							value: entry.code ? (
								<span className="estasoft-sync-mono">
									{ entry.code }
								</span>
							) : (
								'—'
							),
						},
						{
							label: __(
								'Connection',
								'estasoft-erp-crm-sync-engine'
							),
							value: system,
						},
						{
							label: __( 'Item', 'estasoft-erp-crm-sync-engine' ),
							value: entry.queue_id ? (
								<Link
									to={ `/queue/${ entry.queue_id }` }
									className="estasoft-sync-link"
								>
									{ entry.entity_ref ??
										`#${ entry.queue_id }` }
								</Link>
							) : (
								( entry.entity_ref ?? '—' )
							),
						},
						{
							label: __( 'Took', 'estasoft-erp-crm-sync-engine' ),
							value: formatDuration( entry.duration_ms ),
						},
						{
							label: __(
								'Answer',
								'estasoft-erp-crm-sync-engine'
							),
							value: answer( entry ),
						},
					] }
				/>
			</Card>
			<Card
				title={ __(
					'What was sent (redacted)',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				{ entry.request_excerpt ? (
					<CodeBlock
						label={ __(
							'Request excerpt',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						{ entry.request_excerpt }
					</CodeBlock>
				) : (
					<p className="estasoft-sync-muted">
						{ __(
							'Nothing was kept.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				) }
			</Card>
			<Card
				title={ __(
					'What came back (redacted)',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				{ entry.response_excerpt ? (
					<CodeBlock
						label={ __(
							'Answer excerpt',
							'estasoft-erp-crm-sync-engine'
						) }
					>
						{ entry.response_excerpt }
					</CodeBlock>
				) : (
					<p className="estasoft-sync-muted">
						{ __(
							'Nothing was kept.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				) }
			</Card>
			<Callout tone="info">
				{ __(
					'Names, emails, phones and addresses are removed. Secrets are never logged. Excerpts are cut at the size set under Settings.',
					'estasoft-erp-crm-sync-engine'
				) }
			</Callout>
			{ entry.queue_id ? (
				<p>
					<Btn
						variant="primary"
						onClick={ () =>
							navigate( `/queue/${ entry.queue_id }` )
						}
					>
						{ __(
							'Open the queue item',
							'estasoft-erp-crm-sync-engine'
						) }
					</Btn>
				</p>
			) : null }
		</>
	);
}
