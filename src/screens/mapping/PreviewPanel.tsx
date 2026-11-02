import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { MappingPreview, MappingType, SampleOrder } from '../../api/types';
import { useSystemName } from '../../app/StatusProvider';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { JsonView } from '../../components/CodeBlock';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { SelectField } from '../../components/Fields';
import { useFormat } from '../../components/FormatContext';
import { KeyValueList } from '../../components/KeyValueList';
import { StatusBadge } from '../../components/StatusBadge';
import { useResource } from '../../hooks/useResource';
import { formatMoney } from '../../lib/format';
import { asText, getPath } from '../../lib/path';
import { catalogMessage } from '../../lib/text';
import { causeValue } from '../../lib/attention';

/**
 * The first lines of an order the person can recognise, from the canonical source data.
 *
 * @param source Canonical order from the preview.
 * @param locale Locale and currency for amounts.
 * @return Label and value pairs.
 */
export function summariseSource(
	source: Record< string, unknown >,
	locale: ReturnType< typeof useFormat >
): { label: string; value: string }[] {
	const currency =
		asText( getPath( source, 'order.currency' ) ) || locale.currency;
	const money = ( path: string ) => {
		const value = getPath( source, path );
		return typeof value === 'number'
			? formatMoney( { minor: value, currency }, locale )
			: '';
	};
	const lines = getPath( source, 'lines' );
	const items = Array.isArray( lines )
		? lines
				.map( ( line ) => {
					const row = line as Record< string, unknown >;
					return `${ asText( row.quantity ) } × ${ asText( row.name ) } (${ asText( row.sku ) }), ${ typeof row.unit_price === 'number' ? formatMoney( { minor: row.unit_price, currency }, locale ) : '' }`.replace(
						/,\s*$/,
						''
					);
				} )
				.join( '\n' )
		: '';
	const rows = [
		{
			label: __( 'Customer', 'estasoft-erp-crm-sync-engine' ),
			value: [
				asText( getPath( source, 'billing.first_name' ) ),
				asText( getPath( source, 'billing.last_name' ) ),
			]
				.filter( Boolean )
				.join( ' ' ),
		},
		{
			label: __( 'Phone', 'estasoft-erp-crm-sync-engine' ),
			value: asText( getPath( source, 'billing.phone' ) ),
		},
		{
			label: __( 'State', 'estasoft-erp-crm-sync-engine' ),
			value: asText( getPath( source, 'billing.state' ) ),
		},
		{ label: __( 'Items', 'estasoft-erp-crm-sync-engine' ), value: items },
		{
			label: __( 'Shipping', 'estasoft-erp-crm-sync-engine' ),
			value: money( 'order.shipping_total' ),
		},
		{
			label: __( 'Tax', 'estasoft-erp-crm-sync-engine' ),
			value: money( 'order.tax_total' ),
		},
		{
			label: __( 'Payment', 'estasoft-erp-crm-sync-engine' ),
			value: asText( getPath( source, 'order.payment.method_title' ) ),
		},
		{
			label: __( 'Total', 'estasoft-erp-crm-sync-engine' ),
			value: money( 'order.total' ),
		},
	];
	return rows.filter( ( row ) => row.value !== '' );
}

export interface PreviewPanelProps {
	type: MappingType;
	/** Preview with the draft rules instead of the live ones. */
	useDraft: boolean;
	orderId: number | null;
	onOrderChange: ( id: number ) => void;
	orders: SampleOrder[];
	/** Called with the SKU when the person wants to map a product that was not found. */
	onMapSku?: ( sku: string ) => void;
}

/**
 * Source and result side by side for one real order. Lines changed by a rule are marked in
 * the text as well as highlighted.
 *
 * @param props               Preview settings.
 * @param props.type
 * @param props.useDraft
 * @param props.orderId
 * @param props.onOrderChange
 * @param props.orders
 * @param props.onMapSku
 * @return The two cards.
 */
export function PreviewPanel( {
	type,
	useDraft,
	orderId,
	onOrderChange,
	orders,
	onMapSku,
}: PreviewPanelProps ) {
	const system = useSystemName();
	const cfg = useFormat();
	const preview = useResource< MappingPreview >(
		() =>
			api.mappings.preview( {
				type,
				order_id: orderId ?? 0,
				use_draft: useDraft,
				lookups: true,
			} ),
		[ type, orderId, useDraft ],
		{ enabled: orderId !== null }
	);
	const data = preview.data;
	const missingSku = data?.errors.find(
		( error ) => error.code === 'SYNC-MAP-001'
	);
	const skuText = missingSku
		? /(?:Product|SKU)\s+([A-Za-z0-9._\-/]+)/i.exec(
				missingSku.message
			)?.[ 1 ]
		: undefined;

	return (
		<div className="estasoft-sync-stack">
			<Card
				title={ __(
					'The WooCommerce order',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				<SelectField
					label={ __(
						'Try it on a real order',
						'estasoft-erp-crm-sync-engine'
					) }
					value={ orderId === null ? '' : String( orderId ) }
					onChange={ ( value ) => onOrderChange( Number( value ) ) }
					options={ orders.map( ( order ) => ( {
						value: String( order.id ),
						label: `${ order.label } ${ order.customer }, ${ formatMoney( order.total, cfg ) }`,
					} ) ) }
				/>
				{ preview.loading ? (
					<LoadingState
						rows={ 3 }
						label={ __(
							'Building the preview',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
				) : null }
				{ data ? (
					<KeyValueList
						items={ summariseSource( data.source, cfg ).map(
							( row ) => ( {
								label: row.label,
								value: (
									<span
										className="estasoft-sync-pre-line"
										dir="auto"
									>
										{ row.value }
									</span>
								),
							} )
						) }
						stacked
					/>
				) : null }
			</Card>
			{ preview.error && ! data ? (
				<ErrorState
					error={ preview.error }
					onRetry={ () => void preview.reload() }
					title={ __(
						'The preview could not be built.',
						'estasoft-erp-crm-sync-engine'
					) }
				/>
			) : null }
			{ data ? (
				<Card
					title={ sprintf(
						/* translators: %s: system name */ __(
							'What %s will receive',
							'estasoft-erp-crm-sync-engine'
						),
						system
					) }
					description={ __(
						'Preview only. Nothing is sent.',
						'estasoft-erp-crm-sync-engine'
					) }
				>
					<JsonView
						data={ data.target }
						marked={ data.changed_paths }
						label={ __(
							'The request that would be sent',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
					<p className="estasoft-sync-muted">
						{ __(
							'Marked lines were changed by a rule. The raw request is in the log once an order is sent.',
							'estasoft-erp-crm-sync-engine'
						) }
					</p>
					{ data.rule_notes.length > 0 ? (
						<ul className="estasoft-sync-list">
							{ data.rule_notes.map( ( note ) => (
								<li key={ note.path }>
									<span className="estasoft-sync-mono">
										{ note.path }
									</span>{ ' ' }
									{ '←' } { note.note }
								</li>
							) ) }
						</ul>
					) : null }
					{ data.lookups.length > 0 ? (
						<ul className="estasoft-sync-list estasoft-sync-list--plain">
							{ data.lookups.map( ( lookup ) => (
								<li key={ lookup.label }>
									<StatusBadge
										tone={
											lookup.found ? 'success' : 'warning'
										}
									>
										{ lookup.found
											? __(
													'Found',
													'estasoft-erp-crm-sync-engine'
												)
											: __(
													'Not found',
													'estasoft-erp-crm-sync-engine'
												) }
									</StatusBadge>{ ' ' }
									{ lookup.label }: { lookup.detail }
								</li>
							) ) }
						</ul>
					) : null }
					{ data.errors.map( ( error ) => (
						<Callout
							key={ error.code + error.message }
							tone="warning"
							role="status"
						>
							<p>
								{ error.message ||
									catalogMessage(
										error.code,
										error.data?.context,
										system
									) }{ ' ' }
								<span className="estasoft-sync-mono">
									{ error.code }
								</span>
							</p>
						</Callout>
					) ) }
					{ missingSku && onMapSku && skuText ? (
						<button
							type="button"
							className="estasoft-sync-linkbutton"
							onClick={ () => onMapSku( causeValue( skuText ) ) }
						>
							{ sprintf(
								/* translators: %s: SKU */ __(
									'Map %s',
									'estasoft-erp-crm-sync-engine'
								),
								causeValue( skuText )
							) }
						</button>
					) : null }
				</Card>
			) : null }
		</div>
	);
}

/**
 * Loads the sample orders for the preview and keeps the chosen one.
 *
 * @return Orders, the chosen id and a setter.
 */
export function useSampleOrders(): {
	orders: SampleOrder[];
	orderId: number | null;
	setOrderId: ( id: number ) => void;
	loading: boolean;
	error: unknown;
	reload: () => Promise< void >;
} {
	const resource = useResource(
		( signal ) => api.sampleOrders( signal ),
		[]
	);
	const [ orderId, setOrderId ] = useState< number | null >( null );
	useEffect( () => {
		if ( orderId === null && resource.data && resource.data.length > 0 ) {
			setOrderId( resource.data[ 0 ].id );
		}
	}, [ resource.data, orderId ] );
	return {
		orders: resource.data ?? [],
		orderId,
		setOrderId,
		loading: resource.loading,
		error: resource.error,
		reload: resource.reload,
	};
}
