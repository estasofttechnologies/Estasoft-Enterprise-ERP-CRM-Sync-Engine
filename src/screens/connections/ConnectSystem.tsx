import { __, sprintf } from '@wordpress/i18n';
import type { SystemEntry } from '../../api/local-types';
import { Callout } from '../../components/Callout';
import { Card } from '../../components/Card';
import { StatusBadge } from '../../components/StatusBadge';
import { isSelectable, shapeOfEntry } from '../../lib/connect';
import { methodExplanation, methodLabel } from '../../lib/catalog';
import { fieldsOf } from '../connect/copy';
import { DirectConnect } from '../connect/DirectConnect';
import type { ConnectProps } from '../connect/DirectConnect';
import { ExportConnect } from '../connect/ExportConnect';
import { OwnAppConnect } from '../connect/OwnAppConnect';

export type { ConnectProps as ConnectSystemProps } from '../connect/DirectConnect';

/**
 * Connect screen for one system. One component set draws the five ways to connect from the data of
 * the catalog: a pasted key, the person's own app and a sign-in, a cloud app, a server sign-in, and
 * an export file. A system that cannot be connected (no way to, or no form) is explained instead.
 *
 * @param props             Chosen system (with its product chosen) and handlers.
 * @param props.entry
 * @param props.onConnected
 * @param props.existing
 * @return The connect step.
 */
export function ConnectSystem( {
	entry,
	onConnected,
	existing,
}: ConnectProps ) {
	const shape = shapeOfEntry( entry );
	if ( ! shape || ! entry.connector || ! isSelectable( entry ) ) {
		return <MethodExplainer entry={ entry } />;
	}
	if ( shape !== 'export_file' && fieldsOf( entry ).length === 0 ) {
		return <MethodExplainer entry={ entry } />;
	}
	switch ( shape ) {
		case 'own_app':
			return (
				<OwnAppConnect
					entry={ entry }
					onConnected={ onConnected }
					existing={ existing }
				/>
			);
		case 'export_file':
			return (
				<ExportConnect
					entry={ entry }
					onConnected={ onConnected }
					existing={ existing }
				/>
			);
		default:
			return (
				<DirectConnect
					entry={ entry }
					shape={ shape }
					onConnected={ onConnected }
					existing={ existing }
					note={
						entry.connection_method === 'unknown'
							? methodExplanation( entry.connection_method )
							: undefined
					}
				/>
			);
	}
}

/**
 * For a system that cannot be connected from this screen: what connecting involves, and the reason
 * it cannot be done today. Nothing here pretends to work.
 *
 * @param props       Chosen system.
 * @param props.entry
 * @return The explanation.
 */
export function MethodExplainer( { entry }: { entry: SystemEntry } ) {
	const blocked = ! isSelectable( entry );
	return (
		<div className="estasoft-sync-stack">
			<Callout tone={ blocked ? 'warning' : 'info' }>
				{ blocked
					? sprintf(
							/* translators: %s: system name */ __(
								'%s cannot be connected yet.',
								'estasoft-enterprise-erpcrm-sync-engine'
							),
							entry.name
						)
					: entry.connector
						? sprintf(
								/* translators: %s: system name */ __(
									'The connector for %s is installed, but this screen has no form for it yet. Open Connections to continue.',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								entry.name
							)
						: sprintf(
								/* translators: %s: system name */ __(
									'The %s connector is not part of this release yet, so it cannot be connected today. This page shows what connecting will involve.',
									'estasoft-enterprise-erpcrm-sync-engine'
								),
								entry.name
							) }
			</Callout>
			<Card
				title={ sprintf(
					/* translators: %s: system name */ __(
						'How you connect to %s',
						'estasoft-enterprise-erpcrm-sync-engine'
					),
					entry.name
				) }
			>
				<p>
					<StatusBadge
						tone={
							entry.connection_method === 'none'
								? 'warning'
								: 'neutral'
						}
						icon="box"
					>
						{ methodLabel( entry.connection_method ) }
					</StatusBadge>
				</p>
				<p>{ methodExplanation( entry.connection_method ) }</p>
				<p className="estasoft-sync-muted">{ entry.needs }</p>
			</Card>
		</div>
	);
}
