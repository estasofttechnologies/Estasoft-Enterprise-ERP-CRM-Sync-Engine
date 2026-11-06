import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ErrorInfo, ReactNode } from 'react';
import { Btn } from '../components/Btn';
import { Callout } from '../components/Callout';

interface State {
	failed: boolean;
}

/**
 * Catches a render error in one screen so the app bar stays usable.
 */
export class ErrorBoundary extends Component<
	{ children: ReactNode; resetKey?: string },
	State
> {
	public state: State = { failed: false };

	/**
	 * @return The state to show after an error.
	 */
	public static getDerivedStateFromError(): State {
		return { failed: true };
	}

	/**
	 * @param error Error thrown while rendering.
	 * @param info  React's component stack.
	 */
	public componentDidCatch( error: Error, info: ErrorInfo ): void {
		// Nothing is sent anywhere. The browser console is the only place this goes.
		// eslint-disable-next-line no-console
		console.error(
			'ESTASOFT SYNC: a screen failed to render.',
			error,
			info.componentStack
		);
	}

	/**
	 * @param previous          Props before the update.
	 * @param previous.resetKey
	 */
	public componentDidUpdate( previous: { resetKey?: string } ): void {
		if ( this.state.failed && previous.resetKey !== this.props.resetKey ) {
			this.setState( { failed: false } );
		}
	}

	/**
	 * @return The children, or a message when one of them failed.
	 */
	public render(): ReactNode {
		if ( this.state.failed ) {
			return (
				<Callout
					tone="danger"
					role="alert"
					action={
						<Btn small onClick={ () => window.location.reload() }>
							{ __(
								'Reload the page',
								'estasoft-enterprise-erpcrm-sync-engine'
							) }
						</Btn>
					}
				>
					<strong>
						{ __(
							'This screen could not be shown.',
							'estasoft-enterprise-erpcrm-sync-engine'
						) }
					</strong>{ ' ' }
					{ __(
						'Your data is safe. Reload the page; if it happens again, copy a support report from Logs.',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
				</Callout>
			);
		}
		return this.props.children;
	}
}
