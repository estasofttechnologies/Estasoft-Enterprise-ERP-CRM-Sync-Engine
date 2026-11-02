import type { ReactNode } from 'react';

export interface CardProps {
	title?: ReactNode;
	description?: ReactNode;
	/** Content at the end of the header (a button). */
	actions?: ReactNode;
	children?: ReactNode;
	className?: string;
	/** Remove the padding of the body (for tables and lists that run edge to edge). */
	flush?: boolean;
	id?: string;
	/** Heading element. Defaults to h2. */
	as?: 'h2' | 'h3';
}

/**
 * A surface with an optional heading.
 *
 * @param props             Heading texts and content.
 * @param props.title
 * @param props.description
 * @param props.actions
 * @param props.children
 * @param props.className
 * @param props.flush
 * @param props.id
 * @param props.as
 * @return The card.
 */
export function Card( {
	title,
	description,
	actions,
	children,
	className = '',
	flush = false,
	id,
	as: Heading = 'h2',
}: CardProps ) {
	return (
		<section
			className={ `estasoft-sync-card ${ className }`.trim() }
			id={ id }
			aria-labelledby={ title && id ? `${ id }-title` : undefined }
		>
			{ title || actions ? (
				<div className="estasoft-sync-card__head">
					<div className="estasoft-sync-card__titles">
						{ title ? (
							<Heading
								id={ id ? `${ id }-title` : undefined }
								className="estasoft-sync-card__title"
							>
								{ title }
							</Heading>
						) : null }
						{ description ? (
							<p className="estasoft-sync-muted">
								{ description }
							</p>
						) : null }
					</div>
					{ actions ? (
						<div className="estasoft-sync-card__actions">
							{ actions }
						</div>
					) : null }
				</div>
			) : null }
			{ children !== undefined && children !== null ? (
				<div
					className={ `estasoft-sync-card__body${ flush ? ' estasoft-sync-card__body--flush' : '' }` }
				>
					{ children }
				</div>
			) : null }
		</section>
	);
}
