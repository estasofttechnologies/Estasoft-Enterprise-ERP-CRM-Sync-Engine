import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import { Btn } from '../../components/Btn';
import { CodeBlock } from '../../components/CodeBlock';
import { CopyButton } from '../../components/CopyButton';
import { Dialog } from '../../components/Dialog';
import { ErrorState, LoadingState } from '../../components/EmptyState';
import { CheckField } from '../../components/Fields';
import { useResource } from '../../hooks/useResource';
import { downloadText } from '../../lib/download';

/**
 * The support report (I03): a plain-text summary with no names, emails or addresses. Nothing is
 * sent anywhere; the person copies it into a support request.
 *
 * @param props         Close handler.
 * @param props.onClose
 * @return The dialog.
 */
export function SupportReportDialog( { onClose }: { onClose: () => void } ) {
	const [ withOrders, setWithOrders ] = useState( false );
	const report = useResource(
		( signal ) => api.supportReport( withOrders, signal ),
		[ withOrders ],
		{ keepPrevious: true }
	);
	return (
		<Dialog
			title={ __(
				'Support report',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
			size="large"
			onClose={ onClose }
			footer={
				<>
					<Btn variant="tertiary" onClick={ onClose }>
						{ __(
							'Close',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</Btn>
					<Btn
						disabled={ ! report.data }
						icon="download"
						onClick={ () =>
							report.data &&
							downloadText(
								'estasoft-sync-support-report.txt',
								report.data.text,
								'text/plain'
							)
						}
					>
						{ __(
							'Download as text',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</Btn>
					<CopyButton
						text={ () => report.data?.text ?? '' }
						label={ __(
							'Copy report',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						copiedLabel={ __(
							'Report copied',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
						small={ false }
						variant="primary"
					/>
				</>
			}
		>
			{ report.loading ? (
				<LoadingState
					rows={ 4 }
					label={ __(
						'Building the report',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				/>
			) : null }
			{ report.error && ! report.data ? (
				<ErrorState
					error={ report.error }
					onRetry={ () => void report.reload() }
				/>
			) : null }
			{ report.data ? (
				<CodeBlock
					label={ __(
						'Support report text',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				>
					{ report.data.text }
				</CodeBlock>
			) : null }
			<CheckField
				label={ __(
					'Include order numbers',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				hint={ __(
					'Off by default. Order numbers help support find a case; they are not names or emails.',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
				checked={ withOrders }
				onChange={ setWithOrders }
			/>
			<p className="estasoft-sync-muted">
				{ __(
					'Nothing is sent anywhere. Copy the report and paste it into your support request.',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			</p>
		</Dialog>
	);
}
