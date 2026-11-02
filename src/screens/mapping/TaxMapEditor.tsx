import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { TaxMap, TaxMapRow } from '../../api/types';
import { useApp } from '../../app/AppContext';
import { useNotices } from '../../app/NoticeProvider';
import { useSystemName } from '../../app/StatusProvider';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { SelectField } from '../../components/Fields';
import { ResponsiveTable } from '../../components/ResponsiveTable';
import { SaveBar } from '../../components/SaveBar';
import { StatusBadge } from '../../components/StatusBadge';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useResource } from '../../hooks/useResource';
import { errorText } from '../../lib/text';

const rowKey = ( row: TaxMapRow ) =>
	`${ row.country }|${ row.tax_name }|${ row.rate_percent }`;

/**
 * Tax mapping: each WooCommerce tax rate gets the code the system expects. Applies when saved;
 * there are no drafts or versions for tax codes.
 *
 * @return The editor.
 */
export function TaxMapEditor() {
	const { canConfigure } = useApp();
	const { notify } = useNotices();
	const system = useSystemName();
	const resource = useResource< TaxMap >(
		( signal ) => api.taxMap.get( signal ),
		[]
	);
	const [ rows, setRows ] = useState< TaxMapRow[] >( [] );
	const original = resource.data?.rows;
	useEffect( () => {
		if ( original ) {
			setRows( original );
		}
	}, [ original ] );
	const changes = original
		? rows.filter(
				( row, index ) =>
					original[ index ] &&
					original[ index ].system_code !== row.system_code
			).length
		: 0;
	const save = useAsyncAction( async () => {
		const next = await api.taxMap.save(
			rows.map( ( row ) => ( {
				country: row.country,
				tax_name: row.tax_name,
				rate_percent: row.rate_percent,
				system_code: row.system_code,
			} ) )
		);
		resource.setData( next );
		notify( {
			kind: 'success',
			text: __(
				'Tax mapping saved. It applies to items sent from now on, and the change is written to the log.',
				'estasoft-erp-crm-sync-engine'
			),
		} );
	} );

	if ( resource.loading ) {
		return (
			<LoadingState
				rows={ 4 }
				label={ __(
					'Loading the tax mapping',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
		);
	}
	if ( ! resource.data ) {
		return (
			<ErrorState
				error={ resource.error }
				onRetry={ () => void resource.reload() }
			/>
		);
	}
	const data = resource.data;
	const unmapped = rows.filter( ( row ) => ! row.system_code );
	const buyerText = ( row: TaxMapRow ): string => {
		const rule = data.buyer_tax_rules.find(
			( entry ) => entry.country === row.country
		);
		if ( ! rule ) {
			return __( 'Optional', 'estasoft-erp-crm-sync-engine' );
		}
		if ( rule.required_for_business ) {
			return rule.digits
				? sprintf(
						/* translators: %d: number of digits */ __(
							'Required for business buyers, %d digits',
							'estasoft-erp-crm-sync-engine'
						),
						rule.digits
					)
				: __(
						'Required for business buyers',
						'estasoft-erp-crm-sync-engine'
					);
		}
		return __( 'Optional', 'estasoft-erp-crm-sync-engine' );
	};

	return (
		<div className="estasoft-sync-stack">
			<Callout tone="info">
				{ __(
					'Changes on this tab apply as soon as you save, and are written to the log. Unlike order and customer rules, tax codes have no drafts or versions.',
					'estasoft-erp-crm-sync-engine'
				) }
			</Callout>
			<p className="estasoft-sync-muted">
				{ sprintf(
					/* translators: %s: system name */ __(
						'Your %s or an accredited provider signs and sends tax invoices. This screen only makes sure each order carries the right tax code and the buyer’s tax number.',
						'estasoft-erp-crm-sync-engine'
					),
					system
				) }
			</p>
			{ unmapped.length > 0 ? (
				<Callout tone="warning" role="status">
					{ sprintf(
						/* translators: 1: number of rates, 2: first rate without a code */
						_n(
							'%1$d WooCommerce tax rate has no code. Orders that use %2$s will fail with “Tax code not mapped” until you choose one.',
							'%1$d WooCommerce tax rates have no code. Orders that use %2$s will fail with “Tax code not mapped” until you choose one.',
							unmapped.length,
							'estasoft-erp-crm-sync-engine'
						),
						unmapped.length,
						`${ unmapped[ 0 ].country } ${ unmapped[ 0 ].tax_name } ${ unmapped[ 0 ].rate_percent }%`
					) }{ ' ' }
					<span className="estasoft-sync-mono">SYNC-MAP-002</span>
				</Callout>
			) : null }
			<Card flush>
				<fieldset
					className="estasoft-sync-fieldset estasoft-sync-fieldset--flush"
					disabled={ ! canConfigure }
				>
					<legend className="estasoft-sync-sr-only">
						{ __( 'Tax codes', 'estasoft-erp-crm-sync-engine' ) }
					</legend>
					<ResponsiveTable< TaxMapRow, string >
						caption={ __(
							'Tax codes by country and rate',
							'estasoft-erp-crm-sync-engine'
						) }
						rows={ rows }
						rowKey={ rowKey }
						columns={ [
							{
								id: 'country',
								header: __(
									'Country',
									'estasoft-erp-crm-sync-engine'
								),
								primary: true,
								cell: ( row ) => (
									<span className="estasoft-sync-strong">
										{ row.country }
									</span>
								),
							},
							{
								id: 'tax',
								header: __(
									'WooCommerce tax',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( row ) => row.tax_name,
							},
							{
								id: 'rate',
								header: __(
									'Rate',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( row ) => `${ row.rate_percent }%`,
							},
							{
								id: 'code',
								header: sprintf(
									/* translators: %s: system name */ __(
										'Tax code in %s',
										'estasoft-erp-crm-sync-engine'
									),
									system
								),
								wide: true,
								cell: ( row ) => (
									<span className="estasoft-sync-codecell">
										{ ! row.system_code ? (
											<StatusBadge tone="warning">
												{ __(
													'No code yet',
													'estasoft-erp-crm-sync-engine'
												) }
											</StatusBadge>
										) : null }
										<SelectField
											hideLabel
											label={ sprintf(
												/* translators: %s: country, tax name and rate */ __(
													'Tax code for %s',
													'estasoft-erp-crm-sync-engine'
												),
												`${ row.country } ${ row.tax_name } ${ row.rate_percent }%`
											) }
											value={ row.system_code ?? '' }
											onChange={ ( value ) =>
												setRows(
													rows.map( ( entry ) =>
														rowKey( entry ) ===
														rowKey( row )
															? {
																	...entry,
																	system_code:
																		value ||
																		null,
																}
															: entry
													)
												)
											}
											options={ [
												{
													value: '',
													label: __(
														'Choose a code',
														'estasoft-erp-crm-sync-engine'
													),
												},
												...data.system_codes.map(
													( code ) => ( {
														value: code.code,
														label: `${ code.label } (${ code.rate_percent }%)`,
													} )
												),
											] }
										/>
									</span>
								),
							},
							{
								id: 'buyer',
								header: __(
									'Buyer tax number',
									'estasoft-erp-crm-sync-engine'
								),
								cell: ( row ) => buyerText( row ),
							},
							{
								id: 'orders',
								header: __(
									'Orders, 30 days',
									'estasoft-erp-crm-sync-engine'
								),
								align: 'end',
								cell: ( row ) => row.orders_30d,
							},
						] }
					/>
				</fieldset>
			</Card>
			{ canConfigure ? (
				<SaveBar
					changes={ changes }
					saving={ save.busy }
					onSave={ () => void save.run() }
					onDiscard={ () => setRows( original ?? [] ) }
				/>
			) : null }
			{ save.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( save.error ) }
				</Callout>
			) : null }
		</div>
	);
}
