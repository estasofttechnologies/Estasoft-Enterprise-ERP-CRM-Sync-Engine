import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { SystemEntry } from '../../api/local-types';
import { useApp } from '../../app/AppContext';
import { Link } from '../../app/router';
import { Btn } from '../../components/Btn';
import {
	EmptyState,
	ErrorState,
	LoadingState,
} from '../../components/EmptyState';
import { Card } from '../../components/Card';
import { ChoiceGroup, TextField } from '../../components/Fields';
import { HelpTip } from '../../components/HelpTip';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { SegmentedFilter } from '../../components/SegmentedFilter';
import { StatusBadge, Tag } from '../../components/StatusBadge';
import { useResource } from '../../hooks/useResource';
import type { CatalogFilter } from '../../lib/catalog';
import { isSelectable, variantsOf } from '../../lib/connect';
import {
	filterCatalog,
	methodLabel,
	suggestFor,
	tileLetter,
	typeLabel,
} from '../../lib/catalog';

export interface ChooseSystemProps {
	selected: string | null;
	onSelect: ( entry: SystemEntry ) => void;
	/** The product chosen for a row that holds several (a connector id), or null. */
	variant?: string | null;
	onVariant?: ( id: string ) => void;
}

/**
 * The tag that says a connector has not been tried with a live account, and what that means for
 * the person. Calm on purpose: it says what was done, and what they can do about it.
 *
 * @param props      The system.
 * @param props.name
 * @return The tag and its help.
 */
function UntestedTag( { name }: { name: string } ) {
	return (
		<span className="estasoft-sync-untested">
			<Tag>
				{ __(
					'Not yet tested with a live account',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			</Tag>
			<HelpTip
				label={ sprintf(
					/* translators: %s: system name */
					__(
						'What “not yet tested” means for %s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					name
				) }
			>
				<p>
					{ sprintf(
						/* translators: %s: system name */
						__(
							'This connector was built from the public documentation of %s and checked with automated tests that use recorded examples. It has not been tried with a live account yet.',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						name
					) }
				</p>
				<p>
					{ __(
						'You can use Shadow mode to check the results before anything is sent. Shadow mode changes nothing in either system.',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</p>
			</HelpTip>
		</span>
	);
}

/**
 * "Which product do you use?" for a row that holds several (Sage).
 *
 * @param props           The row, the chosen product and the handler.
 * @param props.entry
 * @param props.variant
 * @param props.onVariant
 * @return The question.
 */
function WhichProduct( {
	entry,
	variant,
	onVariant,
}: {
	entry: SystemEntry;
	variant: string | null | undefined;
	onVariant?: ( id: string ) => void;
} ) {
	return (
		<Card
			title={ __(
				'Which product do you use?',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
			description={ sprintf(
				/* translators: %s: system name such as "Sage 200, Sage 300 and Sage X3" */
				__(
					'%s is a family of products, and each connects its own way.',
					'estasoft-enterprise-erpcrm-sync-engine'
				),
				entry.name
			) }
		>
			<ChoiceGroup
				legend={ __(
					'Which product do you use?',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				hideLegend
				value={ variant ?? '' }
				onChange={ ( id ) => onVariant?.( id ) }
				choices={ variantsOf( entry ).map( ( item ) => ( {
					value: item.id,
					label: item.label,
					disabled: item.available === false,
					description:
						item.available === false
							? __(
									'Not available yet.',
									'estasoft-enterprise-erpcrm-sync-engine'
								)
							: undefined,
				} ) ) }
			/>
		</Card>
	);
}

function Tile( { name }: { name: string } ) {
	return (
		<span className="estasoft-sync-tile" aria-hidden="true">
			{ tileLetter( name ) }
		</span>
	);
}

/**
 * Choose a system (B02): 34 systems from the catalog, filters, live search, the way each one
 * connects, a suggestion row, and empty states.
 *
 * @param props           Selected system name, chosen product and handlers.
 * @param props.selected
 * @param props.onSelect
 * @param props.variant
 * @param props.onVariant
 * @return The list.
 */
export function ChooseSystem( {
	selected,
	onSelect,
	variant,
	onVariant,
}: ChooseSystemProps ) {
	const { boot } = useApp();
	const catalog = useResource( ( signal ) => api.catalog.list( signal ), [] );
	const [ filter, setFilter ] = useState< CatalogFilter >( 'all' );
	const [ query, setQuery ] = useState( '' );
	const entries = useMemo( () => catalog.data ?? [], [ catalog.data ] );
	const chosenEntry = entries.find( ( entry ) => entry.name === selected );
	const counts = useMemo( () => {
		const total = entries.length;
		return {
			all: total,
			erp: entries.filter( ( entry ) => entry.type === 'erp' ).length,
			accounting: entries.filter(
				( entry ) => entry.type === 'accounting'
			).length,
			crm: entries.filter( ( entry ) => entry.type === 'crm' ).length,
			uae: entries.filter( ( entry ) => /\bUAE\b/i.test( entry.regions ) )
				.length,
		};
	}, [ entries ] );
	const visible = filterCatalog( entries, filter, query );
	const suggestion = suggestFor( entries, boot.currency );

	if ( catalog.loading ) {
		return (
			<LoadingState
				rows={ 5 }
				label={ __(
					'Loading the list of systems',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			/>
		);
	}
	if ( ! catalog.data ) {
		return (
			<ErrorState
				error={ catalog.error }
				onRetry={ () => void catalog.reload() }
				title={ __(
					'The list of systems could not be loaded.',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			/>
		);
	}

	const groups: {
		type: SystemEntry[ 'type' ];
		title: string;
		note: string;
	}[] = [
		{
			type: 'accounting',
			title: __( 'Accounting', 'estasoft-enterprise-erpcrm-sync-engine' ),
			note: __(
				'Accounting systems can sync orders as invoices, customers, and refunds as credit notes. Stock only if the system tracks it.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		},
		{
			type: 'erp',
			title: __(
				'ERP and business suites',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
			note: __(
				'ERP and business suites can sync orders, customers, refunds, stock and tracking numbers.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		},
		{
			type: 'crm',
			title: __( 'CRM', 'estasoft-enterprise-erpcrm-sync-engine' ),
			note: __(
				'CRMs can sync customers and show each order as a deal.',
				'estasoft-enterprise-erpcrm-sync-engine'
			),
		},
	];

	return (
		<div className="estasoft-sync-choose">
			<SegmentedFilter< CatalogFilter >
				label={ __(
					'Filter systems',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				value={ filter }
				onChange={ setFilter }
				options={ [
					{
						value: 'all',
						label: __(
							'All',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						count: counts.all,
					},
					{
						value: 'erp',
						label: __(
							'ERP',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						count: counts.erp,
					},
					{
						value: 'accounting',
						label: __(
							'Accounting',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						count: counts.accounting,
					},
					{
						value: 'crm',
						label: __(
							'CRM',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						count: counts.crm,
					},
					{
						value: 'uae',
						label: __(
							'In the UAE',
							'estasoft-enterprise-erpcrm-sync-engine'
						),
						count: counts.uae,
					},
				] }
			/>
			<TextField
				label={ __(
					'Find a system',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				type="search"
				value={ query }
				onChange={ setQuery }
				placeholder={ __(
					'For example Xero or SAP',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				spellCheck={ false }
			/>
			<p className="estasoft-sync-muted">
				{ sprintf(
					/* translators: %d: number of systems */
					__(
						'All %d systems are official connectors, and every feature is free. Each row says how you connect and what you need first, because that differs: some need only a key, some need an app that you create, and a few use a file. Every connector is built from the vendor’s public documentation and checked with recorded examples, and none has been tried with a live account yet, so use Shadow mode to check the results before anything is sent. A system that cannot be selected yet says why in its row. You can add more than one system later. Is yours missing? See',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					entries.length
				) }{ ' ' }
				<Link to="/connections/other" className="estasoft-sync-link">
					{ __(
						'Other systems',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</Link>
				.
			</p>

			{ suggestion.entries.length > 0 && ! query && filter === 'all' ? (
				<section aria-labelledby="estasoft-sync-suggest">
					<h2
						id="estasoft-sync-suggest"
						className="estasoft-sync-card__title"
					>
						{ sprintf(
							/* translators: %s: region such as "the UAE" */ __(
								'Suggested for a store in %s',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							suggestion.region
						) }
					</h2>
					<p className="estasoft-sync-muted">
						{ __(
							'Based on your store currency. You can change your mind later and add more than one system.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</p>
					<div className="estasoft-sync-grid estasoft-sync-grid--cards">
						{ suggestion.entries.map( ( entry ) => (
							<div
								key={ entry.name }
								className={ `estasoft-sync-sys-card${ selected === entry.name ? ' is-selected' : '' }` }
							>
								<div className="estasoft-sync-sys-card__head">
									<Tile name={ entry.name } />
									<span className="estasoft-sync-strong">
										{ entry.name }
									</span>
								</div>
								<p className="estasoft-sync-muted">
									{ entry.needs }
								</p>
								<Btn
									small
									variant={
										selected === entry.name
											? 'primary'
											: 'secondary'
									}
									aria-label={
										selected === entry.name
											? sprintf(
													/* translators: %s: system name */ __(
														'%s, selected',
														'estasoft-enterprise-erpcrm-sync-engine'
													),
													entry.name
												)
											: sprintf(
													/* translators: %s: system name */ __(
														'Select %s',
														'estasoft-enterprise-erpcrm-sync-engine'
													),
													entry.name
												)
									}
									onClick={ () => onSelect( entry ) }
								>
									{ selected === entry.name
										? __(
												'Selected',
												'estasoft-enterprise-erpcrm-sync-engine'
											)
										: __(
												'Select',
												'estasoft-enterprise-erpcrm-sync-engine'
											) }
								</Btn>
							</div>
						) ) }
					</div>
				</section>
			) : null }

			{ visible.length === 0 ? (
				<EmptyState
					as="h2"
					title={ __(
						'No system matches',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
					action={
						<Link
							to="/connections/other"
							className="estasoft-sync-btn estasoft-sync-btn--sm components-button is-secondary"
						>
							{ __(
								'Other systems',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</Link>
					}
				>
					{ query
						? sprintf(
								/* translators: %s: the text searched for */ __(
									'Nothing matches “%s”. Check the spelling, or ask for it under Other systems.',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								query
							)
						: __(
								'Nothing matches this filter. Try another one.',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
				</EmptyState>
			) : (
				groups.map( ( group ) => {
					const rows = visible.filter(
						( entry ) => entry.type === group.type
					);
					if ( rows.length === 0 ) {
						return null;
					}
					return (
						<section
							key={ group.type }
							aria-labelledby={ `estasoft-sync-group-${ group.type }` }
						>
							<h2
								id={ `estasoft-sync-group-${ group.type }` }
								className="estasoft-sync-card__title"
							>
								{ group.title } ({ rows.length })
							</h2>
							<p className="estasoft-sync-muted">
								{ group.note }
							</p>
							<ResponsiveTable< SystemEntry, string >
								className="estasoft-sync-table-wrap--systems"
								caption={ sprintf(
									/* translators: %s: group name */ __(
										'%s systems',
										'estasoft-enterprise-erpcrm-sync-engine'
									),
									group.title
								) }
								rows={ rows }
								rowKey={ ( entry ) => entry.name }
								rowClassName={ ( entry ) =>
									selected === entry.name
										? 'is-chosen'
										: undefined
								}
								columns={ [
									{
										id: 'system',
										header: __(
											'System',
											'estasoft-enterprise-erpcrm-sync-engine'
										),
										primary: true,
										cell: ( entry ) => (
											<span className="estasoft-sync-sys">
												<Tile name={ entry.name } />
												<span>
													<span className="estasoft-sync-strong">
														{ entry.name }
													</span>
													<span className="estasoft-sync-muted estasoft-sync-block">
														{ entry.regions }
													</span>
												</span>
												{ entry.community ? (
													<Tag>
														{ __(
															'Community',
															'estasoft-enterprise-erpcrm-sync-engine'
														) }
													</Tag>
												) : null }
											</span>
										),
									},
									{
										id: 'how',
										header: __(
											'How you connect, and what you need first',
											'estasoft-enterprise-erpcrm-sync-engine'
										),
										label: __(
											'How you connect, and what you need first',
											'estasoft-enterprise-erpcrm-sync-engine'
										),
										wide: true,
										cell: ( entry ) => (
											<>
												<StatusBadge
													tone={
														entry.connection_method ===
														'none'
															? 'warning'
															: 'neutral'
													}
													icon={
														entry.connection_method ===
														'none'
															? 'warning'
															: 'box'
													}
												>
													{ methodLabel(
														entry.connection_method
													) }
												</StatusBadge>{ ' ' }
												<span className="estasoft-sync-muted">
													{ typeLabel( entry.type ) }
												</span>
												<span className="estasoft-sync-muted estasoft-sync-block">
													{ entry.needs }
												</span>
												{ ! isSelectable( entry ) &&
												entry.unavailable_reason ? (
													<span className="estasoft-sync-muted estasoft-sync-block">
														{ sprintf(
															/* translators: %s: why the system cannot be chosen yet */
															__(
																'Cannot be selected yet: %s',
																'estasoft-enterprise-erpcrm-sync-engine'
															),
															entry.unavailable_reason
														) }
													</span>
												) : null }
												{ ! entry.connector &&
												variantsOf( entry ).length ===
													0 &&
												isSelectable( entry ) ? (
													<span className="estasoft-sync-muted estasoft-sync-block">
														{ __(
															'The connector for this system is not in this release yet.',
															'estasoft-enterprise-erpcrm-sync-engine'
														) }
													</span>
												) : null }
												{ entry.verified === false &&
												isSelectable( entry ) ? (
													<UntestedTag
														name={ entry.name }
													/>
												) : null }
											</>
										),
									},
									{
										id: 'select',
										header: __(
											'Select',
											'estasoft-enterprise-erpcrm-sync-engine'
										),
										hideHeader: true,
										align: 'end',
										cell: ( entry ) =>
											! isSelectable( entry ) ? (
												<Btn
													small
													disabled
													aria-label={ sprintf(
														/* translators: %s: system name */ __(
															'%s is not available yet',
															'estasoft-enterprise-erpcrm-sync-engine'
														),
														entry.name
													) }
												>
													{ __(
														'Not available yet',
														'estasoft-enterprise-erpcrm-sync-engine'
													) }
												</Btn>
											) : (
												<Btn
													small
													variant={
														selected === entry.name
															? 'primary'
															: 'secondary'
													}
													aria-pressed={
														selected === entry.name
													}
													aria-label={
														selected === entry.name
															? sprintf(
																	/* translators: %s: system name */ __(
																		'%s, selected',
																		'estasoft-enterprise-erpcrm-sync-engine'
																	),
																	entry.name
																)
															: sprintf(
																	/* translators: %s: system name */ __(
																		'Select %s',
																		'estasoft-enterprise-erpcrm-sync-engine'
																	),
																	entry.name
																)
													}
													onClick={ () =>
														onSelect( entry )
													}
												>
													{ selected === entry.name
														? __(
																'Selected',
																'estasoft-enterprise-erpcrm-sync-engine'
															)
														: __(
																'Select',
																'estasoft-enterprise-erpcrm-sync-engine'
															) }
												</Btn>
											),
									},
								] }
							/>
							{ chosenEntry &&
							chosenEntry.type === group.type &&
							variantsOf( chosenEntry ).length > 0 ? (
								<WhichProduct
									entry={ chosenEntry }
									variant={ variant }
									onVariant={ onVariant }
								/>
							) : null }
						</section>
					);
				} )
			) }
		</div>
	);
}
