import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { SelectField, TextField } from '../../components/Fields';
import { PageHeader } from '../../components/PageHeader';

/** Where requests and the connector guide live. Opened by the person; nothing is sent from the store. */
const PROJECT_URL =
	'https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine';

/**
 * Other systems: ask for a system that is not listed, how to build a connector, and the add-ons
 * from other developers that are installed. Carries a warning about third-party add-ons.
 *
 * @return The screen.
 */
export function OtherSystems() {
	const [ name, setName ] = useState( '' );
	const [ type, setType ] = useState( 'erp' );
	const query = new URLSearchParams( {
		title: `System request: ${ name.trim() }`,
		labels: 'system-request',
		body: `System: ${ name.trim() }\nType: ${ type }\n`,
	} ).toString();
	return (
		<>
			<PageHeader
				title={ __( 'Other systems', 'estasoft-erp-crm-sync-engine' ) }
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
							'Other systems',
							'estasoft-erp-crm-sync-engine'
						),
					},
				] }
				description={ __(
					'Ask for a system that is not listed, or build the connector yourself.',
					'estasoft-erp-crm-sync-engine'
				) }
			/>
			<Callout tone="warning">
				<p className="estasoft-sync-strong">
					{ __(
						'Add-ons from other developers are not reviewed by Estasoft.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
				<p>
					{ __(
						'A connector add-on receives your order and customer data and the credentials you give it. Estasoft does not review, support or take responsibility for add-ons it did not publish. Install one only if you trust its developer, and read what it does first.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
			</Callout>
			<Card
				title={ __(
					'Ask for a system',
					'estasoft-erp-crm-sync-engine'
				) }
				description={ __(
					'All 34 systems on Choose a system are official connectors. If yours is not one of them, ask for it.',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				<div className="estasoft-sync-inline-fields">
					<TextField
						label={ __(
							'System name',
							'estasoft-erp-crm-sync-engine'
						) }
						value={ name }
						onChange={ setName }
						placeholder={ __(
							'For example Tryton or Zoho Inventory',
							'estasoft-erp-crm-sync-engine'
						) }
					/>
					<SelectField
						label={ __( 'Type', 'estasoft-erp-crm-sync-engine' ) }
						value={ type }
						onChange={ setType }
						options={ [
							{
								value: 'erp',
								label: __(
									'ERP',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: 'accounting',
								label: __(
									'Accounting',
									'estasoft-erp-crm-sync-engine'
								),
							},
							{
								value: 'crm',
								label: __(
									'CRM',
									'estasoft-erp-crm-sync-engine'
								),
							},
						] }
					/>
				</div>
				<p className="estasoft-sync-muted">
					{ __(
						'Opens a request on the project’s GitHub page. Nothing is sent from your store.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
				<Btn
					variant="primary"
					disabled={ name.trim() === '' }
					href={
						name.trim() === ''
							? undefined
							: `${ PROJECT_URL }/issues/new?${ query }`
					}
					target="_blank"
				>
					{ __( 'Open a request', 'estasoft-erp-crm-sync-engine' ) }
				</Btn>
			</Card>
			<Card
				title={ __(
					'How to add a system',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				<ol className="estasoft-sync-ol">
					<li>
						{ __(
							'Write an adapter that follows the Estasoft connector contract: connect, read and write the fields in the capability list, report errors as retryable or not.',
							'estasoft-erp-crm-sync-engine'
						) }
					</li>
					<li>
						{ __(
							'Run the contract test suite. A connector must pass it to appear as a connector in Choose a system.',
							'estasoft-erp-crm-sync-engine'
						) }
					</li>
					<li>
						{ __(
							'Name the plugin estasoft-sync-connector-{system} and publish it on WordPress.org under GPL.',
							'estasoft-erp-crm-sync-engine'
						) }
					</li>
					<li>
						{ __(
							'It then shows up here and in Choose a system with its own name and a Community label.',
							'estasoft-erp-crm-sync-engine'
						) }
					</li>
				</ol>
				<div className="estasoft-sync-actions">
					<Btn
						href={ `${ PROJECT_URL }/blob/main/docs/ARCHITECTURE.md` }
						target="_blank"
					>
						{ __(
							'Read the connector guide',
							'estasoft-erp-crm-sync-engine'
						) }
					</Btn>
					<Btn
						href={ `${ PROJECT_URL }/tree/main/tests/php/Contract` }
						target="_blank"
					>
						{ __(
							'Download the test suite',
							'estasoft-erp-crm-sync-engine'
						) }
					</Btn>
				</div>
			</Card>
			<Card
				title={ __(
					'Installed community connectors',
					'estasoft-erp-crm-sync-engine'
				) }
			>
				<h3 className="estasoft-sync-subtitle">
					{ __( 'None installed', 'estasoft-erp-crm-sync-engine' ) }
				</h3>
				<p className="estasoft-sync-muted">
					{ __(
						'When you install one, it appears here and in Choose a system.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
			</Card>
		</>
	);
}
