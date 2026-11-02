import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type {
	MappingPreview,
	MappingRule,
	MappingSet,
	MappingType,
	MappingVersion,
} from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { useSystemName } from '../../app/StatusProvider';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { ConfirmDialog, Dialog } from '../../components/Dialog';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { CheckField, SelectField, TextField } from '../../components/Fields';
import { useFormat } from '../../components/FormatContext';
import { DateTime } from '../../components/DateTime';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { StatusBadge, Tag } from '../../components/StatusBadge';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useResource } from '../../hooks/useResource';
import { formatMoney } from '../../lib/format';
import { asText, getPath } from '../../lib/path';
import { describeRule, diffRules, sourcePaths } from '../../lib/rules';
import { errorText } from '../../lib/text';
import { PreviewPanel, useSampleOrders } from './PreviewPanel';
import { RuleDialog } from './RuleDialog';

export interface RuleSetEditorProps {
	type: MappingType;
	/** Heading of the page or section. */
	title: string;
	/** One line under the heading. */
	description: string;
	/** Render as a section inside a page that already has an h1. */
	asSection?: boolean;
}

function flattenTarget( value: unknown, prefix = '' ): string[] {
	if ( value === null || typeof value !== 'object' ) {
		return [ `${ prefix }: ${ JSON.stringify( value ) }` ];
	}
	if ( Array.isArray( value ) ) {
		return value.flatMap( ( entry, index ) =>
			flattenTarget( entry, `${ prefix }[${ index }]` )
		);
	}
	return Object.entries( value as Record< string, unknown > ).flatMap(
		( [ key, child ] ) =>
			flattenTarget( child, prefix ? `${ prefix }.${ key }` : key )
	);
}

/**
 * Lines that differ between two results, as removed and added.
 *
 * @param before Result with the live rules.
 * @param after  Result with the draft rules.
 * @return Removed and added lines.
 */
export function diffTargets(
	before: unknown,
	after: unknown
): { removed: string[]; added: string[] } {
	const a = flattenTarget( before );
	const b = flattenTarget( after );
	return {
		removed: a.filter( ( line ) => ! b.includes( line ) ),
		added: b.filter( ( line ) => ! a.includes( line ) ),
	};
}

/**
 * A rule table for one mapping type with versions, a draft, publish, roll back and preview.
 *
 * @param props             Type and headings.
 * @param props.type
 * @param props.title
 * @param props.description
 * @param props.asSection
 * @return The editor.
 */
export function RuleSetEditor( {
	type,
	title,
	description,
	asSection = false,
}: RuleSetEditorProps ) {
	const { canConfigure } = useApp();
	const { notify } = useNotices();
	const system = useSystemName();
	const cfg = useFormat();
	const set = useResource< MappingSet >(
		( signal ) => api.mappings.get( type, signal ),
		[ type ]
	);
	const sample = useSampleOrders();
	const data = set.data;
	const [ rules, setRules ] = useState< MappingRule[] >( [] );
	const [ seeded, setSeeded ] = useState< string >( '' );
	const [ dialog, setDialog ] = useState<
		| { kind: 'edit'; rule: MappingRule }
		| { kind: 'add' }
		| { kind: 'publish' }
		| { kind: 'rollback'; version: number }
		| { kind: 'discard' }
		| { kind: 'view'; version: Omit< MappingVersion, 'rules' > }
		| { kind: 'preview'; orderId: number | null }
		| null
	>( null );

	const baseKey = data
		? `${ data.draft?.version ?? 'live' }-${ data.live?.version ?? 0 }-${ data.versions.length }-${ JSON.stringify( ( data.draft ?? data.live )?.rules ?? [] ).length }`
		: '';
	useEffect( () => {
		if ( data && baseKey !== seeded ) {
			setRules( ( data.draft ?? data.live )?.rules ?? [] );
			setSeeded( baseKey );
		}
	}, [ data, baseKey, seeded ] );

	const baseline = ( data?.draft ?? data?.live )?.rules ?? [];
	const liveRules = useMemo(
		() => data?.live?.rules ?? [],
		[ data?.live?.rules ]
	);
	const unsaved = JSON.stringify( rules ) !== JSON.stringify( baseline );
	const draftDiff = useMemo(
		() => diffRules( liveRules, rules ),
		[ liveRules, rules ]
	);
	const changeCount =
		draftDiff.added.length +
		draftDiff.changed.length +
		draftDiff.removed.length;

	// Sample values come from a preview of the first order with the rules as saved.
	const sampleOrder = sample.orders[ 0 ]?.id ?? null;
	const sampleValues = useResource< MappingPreview >(
		() =>
			api.mappings.preview( {
				type,
				order_id: sampleOrder ?? 0,
				use_draft: Boolean( data?.draft ),
				lookups: false,
			} ),
		[ type, sampleOrder, data?.draft?.version, data?.live?.version ],
		{ enabled: sampleOrder !== null && Boolean( data ) }
	);

	const save = useAsyncAction( async () => {
		const next = await api.mappings.saveDraft( type, { rules } );
		set.setData( next );
		setSeeded( '' );
		notify( {
			kind: 'success',
			text: __(
				'Draft saved. It is not live until you publish it.',
				'estasoft-erp-crm-sync-engine'
			),
		} );
	} );

	if ( set.loading ) {
		return (
			<LoadingState
				rows={ 5 }
				label={ __(
					'Loading the mapping',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
		);
	}
	if ( ! data ) {
		return (
			<ErrorState
				error={ set.error }
				onRetry={ () => void set.reload() }
				title={ __(
					'The mapping could not be loaded.',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
		);
	}

	const nextVersion =
		data.draft?.version ??
		Math.max( 0, ...data.versions.map( ( version ) => version.version ) ) +
			1;
	const sourceLabel = ( rule: MappingRule ): string => {
		if (
			typeof rule.source === 'object' &&
			! Array.isArray( rule.source )
		) {
			return __( 'Fixed value', 'estasoft-erp-crm-sync-engine' );
		}
		return sourcePaths( rule )
			.map(
				( path ) =>
					data.source_fields.find( ( field ) => field.path === path )
						?.label ?? path
			)
			.join( ' + ' );
	};
	const sampleFor = ( rule: MappingRule ): string => {
		if ( ! sampleValues.data ) {
			return '—';
		}
		const value = getPath(
			sampleValues.data.target,
			rule.target.replace( /\[\]/g, '[0]' )
		);
		if (
			typeof value === 'number' &&
			rule.target.match( /total|price|amount/i )
		) {
			return formatMoney( { minor: value, currency: cfg.currency }, cfg );
		}
		return asText( value ) || '—';
	};
	const flag = ( rule: MappingRule ) => {
		if ( draftDiff.added.includes( rule.id ) ) {
			return <Tag>{ __( 'New', 'estasoft-erp-crm-sync-engine' ) }</Tag>;
		}
		if ( draftDiff.changed.includes( rule.id ) ) {
			return (
				<Tag>{ __( 'Changed', 'estasoft-erp-crm-sync-engine' ) }</Tag>
			);
		}
		return null;
	};

	const upsert = ( rule: MappingRule ) => {
		setRules(
			rules.some( ( entry ) => entry.id === rule.id )
				? rules.map( ( entry ) =>
						entry.id === rule.id ? rule : entry
					)
				: [ ...rules, rule ]
		);
		setDialog( null );
	};

	const liveLabel = data.live
		? sprintf(
				/* translators: %d: version number */ __(
					'Version %d is live.',
					'estasoft-erp-crm-sync-engine'
				),
				data.live.version
			)
		: __( 'No version is live yet.', 'estasoft-erp-crm-sync-engine' );
	const draftLabel =
		changeCount > 0 || data.draft
			? sprintf(
					/* translators: %d: number of changes */ _n(
						'Draft: %d change.',
						'Draft: %d changes.',
						changeCount,
						'estasoft-erp-crm-sync-engine'
					),
					changeCount
				)
			: __( 'No draft.', 'estasoft-erp-crm-sync-engine' );

	const heading = asSection ? (
		<div className="estasoft-sync-section-head">
			<div>
				<h2 className="estasoft-sync-card__title">{ title }</h2>
				<p className="estasoft-sync-muted">{ `${ description } ${ liveLabel } ${ draftLabel }` }</p>
			</div>
		</div>
	) : (
		<p className="estasoft-sync-muted">{ `${ description } ${ liveLabel } ${ draftLabel }` }</p>
	);

	const toolbar = canConfigure ? (
		<div className="estasoft-sync-actions">
			<Btn
				disabled={ ! unsaved || save.busy }
				busy={ save.busy }
				onClick={ () => void save.run() }
			>
				{ __( 'Save draft', 'estasoft-erp-crm-sync-engine' ) }
			</Btn>
			<Btn
				variant="primary"
				disabled={ changeCount === 0 && ! unsaved }
				onClick={ () => setDialog( { kind: 'publish' } ) }
			>
				{ sprintf(
					/* translators: %d: version number */ __(
						'Publish as version %d',
						'estasoft-erp-crm-sync-engine'
					),
					nextVersion
				) }
			</Btn>
		</div>
	) : null;

	return (
		<div className="estasoft-sync-stack">
			{ heading }
			{ toolbar }
			{ ! data.live ? (
				<Callout tone="warning">
					{ __(
						'There is no live mapping version for this type. Orders fail until you publish one.',
						'estasoft-erp-crm-sync-engine'
					) }{ ' ' }
					<span className="estasoft-sync-mono">SYNC-CFG-001</span>
				</Callout>
			) : null }
			{ data.draft && ! unsaved ? (
				<Callout tone="info">
					{ __(
						'This is a saved draft. It is not live until you publish it. Live orders still use the live version.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			) : null }
			{ save.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( save.error ) }
				</Callout>
			) : null }
			{ unsaved ? (
				<Callout tone="info" role="status">
					{ __(
						'You have unsaved edits. Save the draft to keep them.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			) : null }

			<Card
				title={
					asSection
						? undefined
						: __( 'Rules', 'estasoft-erp-crm-sync-engine' )
				}
				flush
			>
				{ rules.length === 0 ? (
					<p className="estasoft-sync-pad estasoft-sync-muted">
						{ __(
							'There are no rules yet.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				) : (
					<ResponsiveTable< MappingRule, string >
						caption={ sprintf(
							/* translators: %s: mapping title */ __(
								'Rules: %s',
								'estasoft-erp-crm-sync-engine'
							),
							title
						) }
						rows={ rules }
						rowKey={ ( rule ) => rule.id }
						columns={ [
							{
								id: 'source',
								header: __(
									'WooCommerce field',
									'estasoft-erp-crm-sync-engine'
								),
								primary: true,
								cell: ( rule ) => (
									<>
										<span className="estasoft-sync-strong">
											{ sourceLabel( rule ) }
										</span>{ ' ' }
										{ flag( rule ) }
									</>
								),
							},
							{
								id: 'rule',
								header: __(
									'Rule',
									'estasoft-erp-crm-sync-engine'
								),
								wide: true,
								cell: ( rule ) => describeRule( rule ),
							},
							{
								id: 'target',
								header: sprintf(
									/* translators: %s: system name */ __(
										'%s field',
										'estasoft-erp-crm-sync-engine'
									),
									system
								),
								label: __(
									'Target field',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( rule ) => (
									<span
										className="estasoft-sync-mono"
										dir="ltr"
									>
										{ rule.target }
									</span>
								),
							},
							{
								id: 'sample',
								header: sprintf(
									/* translators: %s: order label such as #10490 */ __(
										'Sample, order %s',
										'estasoft-erp-crm-sync-engine'
									),
									sample.orders[ 0 ]?.label ?? ''
								).trim(),
								label: __(
									'Sample',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( rule ) => (
									<span dir="auto">
										{ sampleFor( rule ) }
									</span>
								),
							},
							{
								id: 'edit',
								header: __(
									'Edit',
									'estasoft-erp-crm-sync-engine'
								),
								hideHeader: true,
								align: 'end',
								cell: ( rule ) =>
									canConfigure ? (
										<Btn
											small
											aria-label={ sprintf(
												/* translators: %s: field name */ __(
													'Edit the %s rule',
													'estasoft-erp-crm-sync-engine'
												),
												sourceLabel( rule )
											) }
											onClick={ () =>
												setDialog( {
													kind: 'edit',
													rule,
												} )
											}
										>
											{ __(
												'Edit',
												'estasoft-erp-crm-sync-engine'
											) }
										</Btn>
									) : null,
							},
						] }
					/>
				) }
				<div className="estasoft-sync-card__foot">
					<span className="estasoft-sync-muted">
						{ sprintf(
							/* translators: %d: number of fields */ _n(
								'%d field',
								'%d fields',
								rules.length,
								'estasoft-erp-crm-sync-engine'
							),
							rules.length
						) }
					</span>
					{ canConfigure ? (
						<Btn
							small
							icon="plus"
							onClick={ () => setDialog( { kind: 'add' } ) }
						>
							{ __(
								'Add a field',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					) : null }
				</div>
			</Card>

			<Card
				title={ __( 'Versions', 'estasoft-erp-crm-sync-engine' ) }
				description={
					data.draft || changeCount > 0
						? sprintf(
								/* translators: %d: number of changes */ _n(
									'Draft: %d change',
									'Draft: %d changes',
									changeCount,
									'estasoft-erp-crm-sync-engine'
								),
								changeCount
							)
						: __(
								'No draft. Change a rule to start one.',
								'estasoft-erp-crm-sync-engine'
							)
				}
				actions={
					canConfigure && data.draft ? (
						<Btn
							small
							onClick={ () => setDialog( { kind: 'discard' } ) }
						>
							{ __(
								'Discard draft',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					) : undefined
				}
				flush
			>
				<ResponsiveTable< Omit< MappingVersion, 'rules' >, number >
					caption={ __(
						'Mapping versions',
						'estasoft-erp-crm-sync-engine'
					) }
					rows={ data.versions.filter(
						( version ) => version.status !== 'draft'
					) }
					rowKey={ ( version ) => version.version }
					columns={ [
						{
							id: 'version',
							header: __(
								'Version',
								'estasoft-erp-crm-sync-engine'
							),
							primary: true,
							cell: ( version ) => (
								<>
									<span className="estasoft-sync-strong">
										{ sprintf(
											/* translators: %d: version number */ __(
												'Version %d',
												'estasoft-erp-crm-sync-engine'
											),
											version.version
										) }
									</span>
									<span className="estasoft-sync-muted estasoft-sync-block">
										<DateTime
											value={
												version.published_at ??
												version.created_at
											}
											kind="smart"
										/>
										, { version.created_by }
										{ version.note
											? ` · ${ version.note }`
											: '' }
									</span>
								</>
							),
						},
						{
							id: 'status',
							header: __(
								'Status',
								'estasoft-erp-crm-sync-engine'
							),
							cell: ( version ) =>
								version.status === 'live' ? (
									<StatusBadge tone="success">
										{ __(
											'Live',
											'estasoft-erp-crm-sync-engine'
										) }
									</StatusBadge>
								) : (
									<span className="estasoft-sync-rowactions">
										<Btn
											small
											onClick={ () =>
												setDialog( {
													kind: 'view',
													version,
												} )
											}
											aria-label={ sprintf(
												/* translators: %d: version number */ __(
													'View version %d',
													'estasoft-erp-crm-sync-engine'
												),
												version.version
											) }
										>
											{ __(
												'View',
												'estasoft-erp-crm-sync-engine'
											) }
										</Btn>
										{ canConfigure ? (
											<Btn
												small
												onClick={ () =>
													setDialog( {
														kind: 'rollback',
														version:
															version.version,
													} )
												}
												aria-label={ sprintf(
													/* translators: %d: version number */ __(
														'Roll back to version %d',
														'estasoft-erp-crm-sync-engine'
													),
													version.version
												) }
											>
												{ __(
													'Roll back',
													'estasoft-erp-crm-sync-engine'
												) }
											</Btn>
										) : null }
									</span>
								),
						},
					] }
				/>
			</Card>

			<Card
				title={ __(
					'Check before you publish',
					'estasoft-erp-crm-sync-engine'
				) }
				description={ sprintf(
					/* translators: %s: system name */ __(
						'See exactly what %s would receive for a real order, with your draft rules applied.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			>
				<Btn
					disabled={ sample.orders.length === 0 }
					onClick={ () =>
						setDialog( {
							kind: 'preview',
							orderId: sample.orderId,
						} )
					}
				>
					{ __(
						'Preview with a real order',
						'estasoft-erp-crm-sync-engine'
					) }
				</Btn>
				{ sample.orders.length === 0 && ! sample.loading ? (
					<p className="estasoft-sync-muted">
						{ __(
							'No orders yet to preview. Place a test order in WooCommerce first.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				) : null }
			</Card>

			{ dialog?.kind === 'edit' ? (
				<RuleDialog
					rule={ dialog.rule }
					set={ data }
					existing={ rules }
					onClose={ () => setDialog( null ) }
					onSave={ upsert }
					onRemove={ () => {
						setRules(
							rules.filter(
								( rule ) => rule.id !== dialog.rule.id
							)
						);
						setDialog( null );
					} }
					onTest={ ( edited ) => {
						const next = rules.map( ( rule ) =>
							rule.id === edited.id ? edited : rule
						);
						setRules( next );
						void api.mappings
							.saveDraft( type, { rules: next } )
							.then( ( saved ) => {
								set.setData( saved );
								setDialog( {
									kind: 'preview',
									orderId: sample.orderId,
								} );
							} )
							.catch( ( error ) =>
								notify( {
									kind: 'error',
									text: errorText( error ),
								} )
							);
					} }
				/>
			) : null }
			{ dialog?.kind === 'add' ? (
				<RuleDialog
					rule={ null }
					set={ data }
					existing={ rules }
					onClose={ () => setDialog( null ) }
					onSave={ upsert }
				/>
			) : null }
			{ dialog?.kind === 'publish' ? (
				<PublishDialog
					type={ type }
					version={ nextVersion }
					live={ liveRules }
					rules={ rules }
					unsaved={ unsaved }
					orders={ sample.orders }
					set={ data }
					onClose={ () => setDialog( null ) }
					onPublished={ ( next ) => {
						set.setData( next );
						setSeeded( '' );
					} }
					onDraftSaved={ ( next ) => set.setData( next ) }
				/>
			) : null }
			{ dialog?.kind === 'rollback' ? (
				<ConfirmDialog
					title={ sprintf(
						/* translators: %d: version number */ __(
							'Roll back to version %d?',
							'estasoft-erp-crm-sync-engine'
						),
						dialog.version
					) }
					confirmLabel={ __(
						'Roll back',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setDialog( null ) }
					onConfirm={ async () => {
						const next = await api.mappings.rollback(
							type,
							dialog.version
						);
						set.setData( next );
						setSeeded( '' );
						notify( {
							kind: 'success',
							text: sprintf(
								/* translators: %d: version number */ __(
									'Version %d is the live rules again, as a new version.',
									'estasoft-erp-crm-sync-engine'
								),
								dialog.version
							),
						} );
					} }
				>
					<p>
						{ sprintf(
							/* translators: 1: version rolled back to, 2: new version number */ __(
								'Version %1$d becomes the live rules again, as a new version %2$d. Nothing is deleted.',
								'estasoft-erp-crm-sync-engine'
							),
							dialog.version,
							nextVersion
						) }
					</p>
					<ul className="estasoft-sync-list">
						<li>
							{ __(
								'Items already sent stay as they are.',
								'estasoft-erp-crm-sync-engine'
							) }
						</li>
						<li>
							{ __(
								'Items waiting in the queue are built with the new version when they are sent.',
								'estasoft-erp-crm-sync-engine'
							) }
						</li>
						{ data.draft ? (
							<li>
								{ __(
									'Your unpublished draft is kept. It stays a draft.',
									'estasoft-erp-crm-sync-engine'
								) }
							</li>
						) : null }
					</ul>
				</ConfirmDialog>
			) : null }
			{ dialog?.kind === 'discard' ? (
				<ConfirmDialog
					title={ __(
						'Discard the draft?',
						'estasoft-erp-crm-sync-engine'
					) }
					tone="danger"
					confirmLabel={ __(
						'Discard draft',
						'estasoft-erp-crm-sync-engine'
					) }
					onClose={ () => setDialog( null ) }
					onConfirm={ async () => {
						const next = await api.mappings.discard( type );
						set.setData( next );
						setSeeded( '' );
						notify( {
							kind: 'success',
							text: __(
								'The draft was discarded. The live version is unchanged.',
								'estasoft-erp-crm-sync-engine'
							),
						} );
					} }
				>
					<p>
						{ __(
							'Your unpublished changes are removed. The live version is not touched.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
				</ConfirmDialog>
			) : null }
			{ dialog?.kind === 'view' ? (
				<VersionViewDialog
					type={ type }
					version={ dialog.version }
					sourceLabel={ sourceLabel }
					onClose={ () => setDialog( null ) }
				/>
			) : null }
			{ dialog?.kind === 'preview' ? (
				<PreviewDialog
					type={ type }
					hasDraft={ Boolean( data.draft ) || unsaved }
					unsaved={ unsaved }
					onSaveDraft={ () => save.run() }
					draftKey={ `${ data.draft?.version ?? 'none' }-${ baseKey }` }
					orders={ sample.orders }
					initial={ dialog.orderId }
					liveVersion={ data.live?.version }
					onClose={ () => setDialog( null ) }
				/>
			) : null }
		</div>
	);
}

function VersionViewDialog( {
	type,
	version,
	sourceLabel,
	onClose,
}: {
	type: MappingType;
	version: Omit< MappingVersion, 'rules' >;
	sourceLabel: ( rule: MappingRule ) => string;
	onClose: () => void;
} ) {
	const detail = useResource< MappingVersion >(
		( signal ) => api.mappings.version( type, version.version, signal ),
		[ type, version.version ]
	);
	return (
		<Dialog
			title={ sprintf(
				/* translators: %d: version number */ __(
					'Version %d',
					'estasoft-erp-crm-sync-engine'
				),
				version.version
			) }
			onClose={ onClose }
			footer={
				<Btn onClick={ onClose }>
					{ __( 'Close', 'estasoft-erp-crm-sync-engine' ) }
				</Btn>
			}
		>
			<p>
				<DateTime
					value={ version.published_at ?? version.created_at }
					kind="smart"
				/>
				, { version.created_by }
				{ version.note ? ` \u00b7 ${ version.note }` : '' }
			</p>
			{ detail.loading ? (
				<LoadingState
					rows={ 3 }
					label={ __(
						'Loading the rules of this version',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ detail.error && ! detail.data ? (
				<ErrorState
					error={ detail.error }
					onRetry={ () => void detail.reload() }
				/>
			) : null }
			{ detail.data && detail.data.rules.length === 0 ? (
				<p className="estasoft-sync-muted">
					{ __(
						'This version has no rules.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
			) : null }
			{ detail.data && detail.data.rules.length > 0 ? (
				<ul className="estasoft-sync-list">
					{ detail.data.rules.map( ( rule ) => (
						<li key={ rule.id }>
							{ sourceLabel( rule ) }: { describeRule( rule ) }{ ' ' }
							{ '→' }{ ' ' }
							<span className="estasoft-sync-mono">
								{ rule.target }
							</span>
						</li>
					) ) }
				</ul>
			) : null }
		</Dialog>
	);
}

interface PublishDialogProps {
	type: MappingType;
	version: number;
	live: MappingRule[];
	rules: MappingRule[];
	unsaved: boolean;
	orders: { id: number; label: string }[];
	set: MappingSet;
	onClose: () => void;
	onPublished: ( next: MappingSet ) => void;
	/** Called when the draft was saved on the way, so the editor shows the saved draft. */
	onDraftSaved: ( next: MappingSet ) => void;
}

function PublishDialog( {
	type,
	version,
	live,
	rules,
	unsaved,
	orders,
	set,
	onClose,
	onPublished,
	onDraftSaved,
}: PublishDialogProps ) {
	const { notify } = useNotices();
	const system = useSystemName();
	const [ note, setNote ] = useState( '' );
	const [ previewFirst, setPreviewFirst ] = useState( orders.length > 0 );
	const diff = diffRules( live, rules );
	const label = ( id: string ) => {
		const rule =
			rules.find( ( entry ) => entry.id === id ) ??
			live.find( ( entry ) => entry.id === id );
		return rule
			? sourcePaths( rule )
					.map(
						( path ) =>
							set.source_fields.find(
								( field ) => field.path === path
							)?.label ?? path
					)
					.join( ' + ' ) || rule.target
			: id;
	};
	return (
		<ConfirmDialog
			title={ sprintf(
				/* translators: %d: version number */ __(
					'Publish version %d?',
					'estasoft-erp-crm-sync-engine'
				),
				version
			) }
			confirmLabel={ sprintf(
				/* translators: %d: version number */ __(
					'Publish version %d',
					'estasoft-erp-crm-sync-engine'
				),
				version
			) }
			onClose={ onClose }
			ruleLabel={ label }
			onConfirm={ async () => {
				// The server previews and publishes the saved draft, never the edits on screen, so the
				// edits are saved first.
				if ( unsaved ) {
					onDraftSaved(
						await api.mappings.saveDraft( type, {
							rules,
							note: note || undefined,
						} )
					);
				}
				if ( previewFirst ) {
					const failures: string[] = [];
					for ( const order of orders.slice( 0, 10 ) ) {
						const result = await api.mappings.preview( {
							type,
							order_id: order.id,
							use_draft: true,
							lookups: false,
						} );
						if ( result.errors.length > 0 ) {
							failures.push(
								`${ order.label }: ${ result.errors[ 0 ].message }`
							);
						}
					}
					if ( failures.length > 0 ) {
						throw new Error(
							sprintf(
								/* translators: 1: number of orders, 2: first problem */ _n(
									'The preview found a problem on %1$d order, so nothing was published. %2$s',
									'The preview found problems on %1$d orders, so nothing was published. %2$s',
									failures.length,
									'estasoft-erp-crm-sync-engine'
								),
								failures.length,
								failures[ 0 ]
							)
						);
					}
				}
				const next = await api.mappings.publish(
					type,
					note || undefined
				);
				notify( {
					kind: 'success',
					text: sprintf(
						/* translators: %d: version number */ __(
							'Version %d is live. Items sent from now use it.',
							'estasoft-erp-crm-sync-engine'
						),
						version
					),
				} );
				onPublished( next );
			} }
		>
			<p>
				{ sprintf(
					/* translators: %d: version number */ __(
						'These changes will go live. Items already sent are not touched; items sent from now use version %d.',
						'estasoft-erp-crm-sync-engine'
					),
					version
				) }
			</p>
			<ul className="estasoft-sync-list">
				{ diff.changed.map( ( id ) => (
					<li key={ `c${ id }` }>
						{ sprintf(
							/* translators: %s: field name */ __(
								'%s: rule changed',
								'estasoft-erp-crm-sync-engine'
							),
							label( id )
						) }
					</li>
				) ) }
				{ diff.added.map( ( id ) => (
					<li key={ `a${ id }` }>
						{ sprintf(
							/* translators: 1: field name, 2: system name */ __(
								'%1$s: added, sent to %2$s',
								'estasoft-erp-crm-sync-engine'
							),
							label( id ),
							system
						) }
					</li>
				) ) }
				{ diff.removed.map( ( id ) => (
					<li key={ `r${ id }` }>
						{ sprintf(
							/* translators: %s: field name */ __(
								'%s: removed',
								'estasoft-erp-crm-sync-engine'
							),
							label( id )
						) }
					</li>
				) ) }
				{ diff.changed.length +
					diff.added.length +
					diff.removed.length ===
				0 ? (
					<li>
						{ __(
							'No rule differs from the live version.',
							'estasoft-erp-crm-sync-engine'
						) }
					</li>
				) : null }
			</ul>
			<TextField
				label={ __(
					'Note for your team (optional)',
					'estasoft-erp-crm-sync-engine'
				) }
				value={ note }
				onChange={ setNote }
				placeholder={ __(
					'For example: phone format for the UAE',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
			<CheckField
				label={ __(
					'Preview on the last 10 orders first',
					'estasoft-erp-crm-sync-engine'
				) }
				hint={ __(
					'Nothing is sent. Takes a few seconds.',
					'estasoft-erp-crm-sync-engine'
				) }
				checked={ previewFirst }
				onChange={ setPreviewFirst }
				disabled={ orders.length === 0 }
			/>
		</ConfirmDialog>
	);
}

function PreviewDialog( {
	type,
	hasDraft,
	orders,
	initial,
	liveVersion,
	onClose,
	unsaved,
	onSaveDraft,
	draftKey,
}: {
	type: MappingType;
	hasDraft: boolean;
	/** Edits on screen that the server has not seen: the preview cannot include them. */
	unsaved: boolean;
	onSaveDraft: () => Promise< unknown >;
	/** Changes when the saved draft changes, so the preview is built again. */
	draftKey: string;
	orders: {
		id: number;
		label: string;
		customer: string;
		total: { minor: number; currency: string };
		created_at: string;
	}[];
	initial: number | null;
	liveVersion: number | undefined;
	onClose: () => void;
} ) {
	const [ orderId, setOrderId ] = useState< number | null >(
		initial ?? orders[ 0 ]?.id ?? null
	);
	const [ full, setFull ] = useState( false );
	const cfg = useFormat();
	const both = useResource(
		async () => {
			const [ before, after ] = await Promise.all( [
				api.mappings.preview( {
					type,
					order_id: orderId ?? 0,
					use_draft: false,
					lookups: false,
				} ),
				api.mappings.preview( {
					type,
					order_id: orderId ?? 0,
					use_draft: true,
					lookups: false,
				} ),
			] );
			return { before, after };
		},
		[ type, orderId, draftKey ],
		{ enabled: orderId !== null }
	);
	const saveFirst = useAsyncAction( async () => {
		await onSaveDraft();
	} );
	const diff = both.data
		? diffTargets( both.data.before.target, both.data.after.target )
		: null;
	return (
		<Dialog
			title={ __(
				'Preview with a real order',
				'estasoft-erp-crm-sync-engine'
			) }
			size="large"
			onClose={ onClose }
			footer={
				<>
					<Btn onClick={ () => setFull( ! full ) }>
						{ full
							? __(
									'Hide the full preview',
									'estasoft-erp-crm-sync-engine'
								)
							: __(
									'Open the full preview',
									'estasoft-erp-crm-sync-engine'
								) }
					</Btn>
					<Btn variant="primary" onClick={ onClose }>
						{ __( 'Close', 'estasoft-erp-crm-sync-engine' ) }
					</Btn>
				</>
			}
		>
			<SelectField
				label={ __( 'Order', 'estasoft-erp-crm-sync-engine' ) }
				value={ orderId === null ? '' : String( orderId ) }
				onChange={ ( value ) => setOrderId( Number( value ) ) }
				options={ orders.map( ( order ) => ( {
					value: String( order.id ),
					label: `${ order.label } ${ order.customer }, ${ formatMoney( order.total, cfg ) }`,
				} ) ) }
			/>
			{ both.loading ? (
				<LoadingState
					rows={ 2 }
					label={ __(
						'Building the preview',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ unsaved ? (
				<Callout
					tone="warning"
					role="status"
					action={
						<Btn
							small
							busy={ saveFirst.busy }
							onClick={ () => void saveFirst.run() }
						>
							{ __(
								'Save the draft',
								'estasoft-erp-crm-sync-engine'
							) }
						</Btn>
					}
				>
					{ __(
						'The edits you have not saved are not in this preview. The preview uses the saved draft. Save the draft to see them here.',
						'estasoft-erp-crm-sync-engine'
					) }
				</Callout>
			) : null }
			{ saveFirst.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( saveFirst.error ) }
				</Callout>
			) : null }
			{ both.error ? (
				<ErrorState
					error={ both.error }
					onRetry={ () => void both.reload() }
				/>
			) : null }
			{ diff ? (
				<>
					<p className="estasoft-sync-strong">
						{ __( 'What changes', 'estasoft-erp-crm-sync-engine' ) }{ ' ' }
						<span className="estasoft-sync-muted">
							{ liveVersion !== undefined
								? sprintf(
										/* translators: %d: live version number */ __(
											'− version %d (live) + your draft',
											'estasoft-erp-crm-sync-engine'
										),
										liveVersion
									)
								: __(
										'− live + your draft',
										'estasoft-erp-crm-sync-engine'
									) }
						</span>
					</p>
					{ ! hasDraft ||
					( diff.removed.length === 0 && diff.added.length === 0 ) ? (
						<p className="estasoft-sync-muted">
							{ __(
								'The draft produces the same result as the live version for this order.',
								'estasoft-erp-crm-sync-engine'
							) }
						</p>
					) : (
						<pre
							className="estasoft-sync-code estasoft-sync-diff"
							tabIndex={ 0 }
							role="region"
							aria-label={ __(
								'Lines that differ from the live version',
								'estasoft-erp-crm-sync-engine'
							) }
						>
							<code>
								{ diff.removed.map( ( line ) => (
									<span
										key={ `-${ line }` }
										className="estasoft-sync-code__line is-removed"
									>{ `− ${ line }` }</span>
								) ) }
								{ diff.added.map( ( line ) => (
									<span
										key={ `+${ line }` }
										className="estasoft-sync-code__line is-added"
									>{ `+ ${ line }` }</span>
								) ) }
							</code>
						</pre>
					) }
					<p className="estasoft-sync-muted">
						{ __(
							'Nothing is sent. Lines marked + or − differ from the live version.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					{ both.data && both.data.after.errors.length > 0
						? both.data.after.errors.map( ( error ) => (
								<Callout
									key={ error.code + error.message }
									tone="warning"
								>
									{ error.message }{ ' ' }
									<span className="estasoft-sync-mono">
										{ error.code }
									</span>
								</Callout>
							) )
						: null }
				</>
			) : null }
			{ full ? (
				<PreviewPanel
					type={ type }
					useDraft
					orderId={ orderId }
					onOrderChange={ setOrderId }
					orders={ orders }
				/>
			) : null }
		</Dialog>
	);
}
