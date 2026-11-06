import { __, sprintf } from '@wordpress/i18n';
import { api } from '../../api/endpoints';
import type { CapacityResult } from '../../api/types';
import { Btn } from '../../components/Btn';
import { Callout } from '../../components/Callout';
import { Dialog } from '../../components/Dialog';
import { Busy } from '../../components/EmptyState';
import { KeyValueList } from '../../components/KeyValueList';
import { useAsyncAction } from '../../hooks/useAsyncAction';
import { useState } from '@wordpress/element';
import { errorText } from '../../lib/text';

/**
 * Capacity test: sends test items through a connection that sends nothing and reports how many
 * this server handles a minute.
 *
 * @param props         Close handler.
 * @param props.onClose
 * @return The dialog.
 */
export function CapacityTestDialog( { onClose }: { onClose: () => void } ) {
	const [ result, setResult ] = useState< CapacityResult | null >( null );
	const run = useAsyncAction( async () => {
		setResult( await api.capacityTest() );
	} );
	return (
		<Dialog
			title={ __(
				'Capacity test',
				'estasoft-enterprise-erpcrm-sync-engine'
			) }
			onClose={ onClose }
			locked={ run.busy }
			footer={
				<>
					<Btn
						variant="tertiary"
						onClick={ onClose }
						disabled={ run.busy }
					>
						{ __(
							'Close',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</Btn>
					{ ! result ? (
						<Btn
							variant="primary"
							busy={ run.busy }
							disabled={ run.busy }
							onClick={ () => void run.run() }
						>
							{ __(
								'Run the test',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</Btn>
					) : null }
				</>
			}
		>
			<p>
				{ __(
					'Sends test items through a connection that sends nothing, then shows how fast this server works through them. It uses some of your server’s capacity while it runs, so run it when the shop is quiet.',
					'estasoft-enterprise-erpcrm-sync-engine'
				) }
			</p>
			{ run.busy ? (
				<Busy
					label={ __(
						'Running the test…',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				/>
			) : null }
			{ run.error ? (
				<Callout tone="danger" role="alert">
					{ errorText( run.error ) }
				</Callout>
			) : null }
			{ result ? (
				<>
					<KeyValueList
						items={ [
							{
								label: __(
									'Items a minute',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								value: result.items_per_minute,
							},
							{
								label: __(
									'Items sent through',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								value: result.items,
							},
							{
								label: __(
									'Time taken',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								value: sprintf(
									/* translators: %s: seconds */ __(
										'%s s',
										'estasoft-enterprise-erpcrm-sync-engine'
									),
									String( result.seconds )
								),
							},
						] }
					/>
					<Callout tone="success" role="status">
						{ result.verdict }
					</Callout>
				</>
			) : null }
		</Dialog>
	);
}
