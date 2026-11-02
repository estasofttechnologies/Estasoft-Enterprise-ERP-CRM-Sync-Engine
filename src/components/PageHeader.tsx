import type { ReactNode } from 'react';
import { Link } from '../app/router';

export interface Crumb {
	label: string;
	to?: string;
}

export interface PageHeaderProps {
	title: ReactNode;
	description?: ReactNode;
	actions?: ReactNode;
	/** Parent pages, shown above the title: `Queue / Order #10485`. */
	breadcrumb?: Crumb[];
	badge?: ReactNode;
}

/**
 * The top of a screen: optional breadcrumb, the one h1, a line of text and the page actions.
 *
 * @param props             Texts and actions.
 * @param props.title
 * @param props.description
 * @param props.actions
 * @param props.breadcrumb
 * @param props.badge
 * @return The header.
 */
export function PageHeader( {
	title,
	description,
	actions,
	breadcrumb,
	badge,
}: PageHeaderProps ) {
	return (
		<header className="estasoft-sync-head">
			<div className="estasoft-sync-head__text">
				{ breadcrumb && breadcrumb.length > 0 ? (
					<p className="estasoft-sync-crumb">
						{ breadcrumb.map( ( crumb, index ) => (
							<span key={ crumb.label }>
								{ crumb.to ? (
									<Link to={ crumb.to }>{ crumb.label }</Link>
								) : (
									crumb.label
								) }
								{ index < breadcrumb.length - 1 ? ' / ' : '' }
							</span>
						) ) }
					</p>
				) : null }
				<h1
					className="estasoft-sync-title"
					tabIndex={ -1 }
					data-page-heading=""
				>
					{ title }
					{ badge ? (
						<span className="estasoft-sync-head__badge">
							{ badge }
						</span>
					) : null }
				</h1>
				{ description ? (
					<p className="estasoft-sync-muted">{ description }</p>
				) : null }
			</div>
			{ actions ? (
				<div className="estasoft-sync-head__actions">{ actions }</div>
			) : null }
		</header>
	);
}
