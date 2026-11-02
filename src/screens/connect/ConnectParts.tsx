import { speak } from '@wordpress/a11y';
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import type { SystemEntry } from '../../api/local-types';
import { Card } from '../../components/Card';
import { CopyButton } from '../../components/CopyButton';
import { ExternalLink } from '../../components/ExternalLink';
import type { ConnectShape } from '../../lib/connect';
import { stepsAsText } from '../../lib/connect';

/**
 * Turns `code` in a step into a code element, so a value to copy is easy to see. Nothing else in a
 * step is markup.
 *
 * @param text A step as the connector wrote it.
 * @return The step with code elements.
 */
export function renderStep( text: string ): ReactNode[] {
	return text.split( /(`[^`]+`)/g ).map( ( part, index ) =>
		part.startsWith( '`' ) && part.endsWith( '`' ) && part.length > 2 ? (
			<code key={ index } className="estasoft-sync-mono" dir="ltr">
				{ part.slice( 1, -1 ) }
			</code>
		) : (
			<span key={ index }>{ part }</span>
		)
	);
}

/**
 * Removes the code marks, for the plain-text copy of the steps.
 * @param text
 */
export function plainStep( text: string ): string {
	return text.replace( /`([^`]+)`/g, '$1' );
}

export interface ConnectGuideProps {
	title: string;
	system: string;
	steps: string[];
	/** The plan or licence the system needs, said before the steps. */
	planNote?: ReactNode;
	/** Text before the steps. */
	intro?: ReactNode;
	/** Text after the steps. */
	outro?: ReactNode;
	developerUrl?: string;
	/** Who the copied steps are for: "my accountant", "my administrators". */
	copyLabel: string;
	id?: string;
}

/**
 * The numbered steps of a way to connect, the plan the system needs, a link to the vendor's developer
 * area and a button that copies the steps for whoever does the work inside the system.
 *
 * @param props              Texts and steps.
 * @param props.title
 * @param props.system
 * @param props.steps
 * @param props.planNote
 * @param props.intro
 * @param props.outro
 * @param props.developerUrl
 * @param props.copyLabel
 * @param props.id
 * @return The card.
 */
export function ConnectGuide( {
	title,
	system,
	steps,
	planNote,
	intro,
	outro,
	developerUrl,
	copyLabel,
	id,
}: ConnectGuideProps ) {
	return (
		<Card title={ title } id={ id }>
			{ planNote ? <p>{ planNote }</p> : null }
			{ intro }
			{ steps.length > 0 ? (
				<ol className="estasoft-sync-ol">
					{ steps.map( ( step, index ) => (
						<li key={ index }>{ renderStep( step ) }</li>
					) ) }
				</ol>
			) : null }
			{ outro }
			<div className="estasoft-sync-actions">
				{ developerUrl ? (
					<span>
						<ExternalLink href={ developerUrl }>
							{ sprintf(
								/* translators: %s: system name */
								__(
									'Open the %s developer area',
									'estasoft-erp-crm-sync-engine'
								),
								system
							) }
						</ExternalLink>
					</span>
				) : null }
				{ steps.length > 0 ? (
					<CopyButton
						text={ () =>
							stepsAsText( system, steps.map( plainStep ) )
						}
						label={ copyLabel }
					/>
				) : null }
			</div>
		</Card>
	);
}

/**
 * What is sent, and where (WordPress.org guideline 6): the hosts the plugin contacts for this system,
 * and the vendor's terms and privacy policy.
 *
 * @param props       The system.
 * @param props.entry
 * @param props.shape
 * @return The card.
 */
export function DataDisclosure( {
	entry,
	shape,
}: {
	entry: SystemEntry;
	shape: ConnectShape;
} ) {
	const hosts = ( entry.hosts ?? [] ).filter( Boolean );
	const own = ( entry.credential_fields ?? [] ).some(
		( field ) =>
			field.type === 'url' ||
			/^(address|url|host|server|base_url)$/i.test( field.name )
	);
	const links = [
		{
			href: entry.terms_url,
			text: sprintf(
				/* translators: %s: system name */
				__( '%s terms of use', 'estasoft-erp-crm-sync-engine' ),
				entry.name
			),
		},
		{
			href: entry.privacy_url,
			text: sprintf(
				/* translators: %s: system name */
				__( '%s privacy policy', 'estasoft-erp-crm-sync-engine' ),
				entry.name
			),
		},
	].filter( ( link ) => Boolean( link.href ) );
	return (
		<Card
			title={ __(
				'What is sent, and where',
				'estasoft-erp-crm-sync-engine'
			) }
		>
			{ shape === 'export_file' ? (
				<p>
					{ __(
						'Nothing is sent to any website. The file is made on your own website and stays there until you download it.',
						'estasoft-erp-crm-sync-engine'
					) }
				</p>
			) : (
				<>
					<p>
						{ sprintf(
							/* translators: %s: system name */
							__(
								'Once this connection is on and syncing, the plugin sends the orders, customers and products that you choose to sync from this website to %s, at these addresses. Testing the connection does not send orders or customers. Nothing goes to Estasoft.',
								'estasoft-erp-crm-sync-engine'
							),
							entry.name
						) }
					</p>
					{ hosts.length > 0 || own ? (
						<ul className="estasoft-sync-list estasoft-sync-list--plain">
							{ hosts.map( ( host ) => (
								<li key={ host }>
									<code
										className="estasoft-sync-mono"
										dir="ltr"
									>
										{ host }
									</code>
								</li>
							) ) }
							{ own ? (
								<li>
									{ __(
										'The address you type in the form.',
										'estasoft-erp-crm-sync-engine'
									) }
								</li>
							) : null }
						</ul>
					) : null }
				</>
			) }
			{ links.length > 0 ? (
				<p>
					{ links.map( ( link, index ) => (
						<span key={ link.text }>
							{ index > 0 ? ' · ' : '' }
							<ExternalLink href={ link.href }>
								{ link.text }
							</ExternalLink>
						</span>
					) ) }
				</p>
			) : null }
		</Card>
	);
}

/**
 * Moves focus to a heading and says where the person is, whenever a flow reaches its next part.
 * Not on the first render: arriving on the screen is handled by the page.
 *
 * @param part    The part the flow is in now; a change moves focus.
 * @param message What a screen reader hears for this part.
 * @return A ref for the heading that should take focus.
 */
export function useStepFocus< T extends HTMLElement >(
	part: string,
	message: string
) {
	const ref = useRef< T >( null );
	const first = useRef( true );
	useEffect( () => {
		if ( first.current ) {
			first.current = false;
			return;
		}
		ref.current?.focus();
		speak( message, 'polite' );
		// Only a change of part moves focus.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ part ] );
	return ref;
}
