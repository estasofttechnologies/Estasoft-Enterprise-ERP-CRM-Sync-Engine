import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { safeHttpUrl } from '../lib/url';
import { Icon } from './Icon';

/**
 * A link to another website. It opens in a new tab, says so for screen readers, and never carries a
 * `javascript:` or `data:` address: an address that is not http or https leaves just the text.
 *
 * @param props           Address and text.
 * @param props.href
 * @param props.children
 * @param props.className
 * @return The link.
 */
export function ExternalLink( {
	href,
	children,
	className = '',
}: {
	href: string | null | undefined;
	children: ReactNode;
	className?: string;
} ) {
	const safe = safeHttpUrl( href );
	if ( ! safe ) {
		return <>{ children }</>;
	}
	return (
		<a
			className={ `estasoft-sync-link ${ className }`.trim() }
			href={ safe }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ children }
			<Icon name="external" size={ 14 } />
			<span className="estasoft-sync-sr-only">
				{ ' ' +
					__(
						'(opens in a new tab)',
						'estasoft-enterprise-erpcrm-sync-engine'
					) }
			</span>
		</a>
	);
}
