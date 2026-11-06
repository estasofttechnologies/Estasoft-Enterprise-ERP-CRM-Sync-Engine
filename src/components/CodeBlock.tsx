import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

/**
 * Monospace text that wraps. Optional label for the region (screen readers).
 *
 * @param props          Content and label.
 * @param props.children
 * @param props.label
 * @return The block.
 */
export function CodeBlock( {
	children,
	label,
}: {
	children: ReactNode;
	label?: string;
} ) {
	return (
		<pre
			className="estasoft-sync-code"
			tabIndex={ 0 }
			role={ label ? 'region' : undefined }
			aria-label={ label }
		>
			<code>{ children }</code>
		</pre>
	);
}

interface JsonLine {
	path: string;
	text: string;
}

function flatten(
	value: unknown,
	path: string,
	indent: number,
	out: JsonLine[],
	key?: string
): void {
	const pad = '  '.repeat( indent );
	const prefix = key !== undefined ? `${ JSON.stringify( key ) }: ` : '';
	if ( Array.isArray( value ) ) {
		if ( value.length === 0 ) {
			out.push( { path, text: `${ pad }${ prefix }[]` } );
			return;
		}
		out.push( { path, text: `${ pad }${ prefix }[` } );
		value.forEach( ( entry, index ) =>
			flatten( entry, `${ path }[${ index }]`, indent + 1, out )
		);
		out.push( { path, text: `${ pad }]` } );
		return;
	}
	if ( value !== null && typeof value === 'object' ) {
		const entries = Object.entries( value as Record< string, unknown > );
		if ( entries.length === 0 ) {
			out.push( { path, text: `${ pad }${ prefix }{}` } );
			return;
		}
		out.push( { path, text: `${ pad }${ prefix }{` } );
		entries.forEach( ( [ name, child ] ) =>
			flatten(
				child,
				path ? `${ path }.${ name }` : name,
				indent + 1,
				out,
				name
			)
		);
		out.push( { path, text: `${ pad }}` } );
		return;
	}
	out.push( {
		path,
		text: `${ pad }${ prefix }${ JSON.stringify( value ) }`,
	} );
}

/**
 * Pretty-prints data as lines. Lines whose dotted path is in `marked` are highlighted and
 * prefixed with a marker, so the highlight never depends on colour alone.
 *
 * @param props        Data, the changed paths and a label.
 * @param props.data
 * @param props.marked
 * @param props.label
 * @return The block.
 */
export function JsonView( {
	data,
	marked = [],
	label,
}: {
	data: unknown;
	marked?: string[];
	label?: string;
} ) {
	const lines: JsonLine[] = [];
	flatten( data, '', 0, lines );
	const isMarked = ( path: string ) =>
		path !== '' &&
		marked.some(
			( m ) =>
				path === m ||
				path.replace( /\[\d+\]/g, '[]' ) === m ||
				path.startsWith( `${ m }.` )
		);
	return (
		<pre
			className="estasoft-sync-code"
			tabIndex={ 0 }
			role={ label ? 'region' : undefined }
			aria-label={ label }
		>
			<code>
				{ lines.map( ( line, index ) => {
					const hit =
						isMarked( line.path ) &&
						! /^\s*[\]}]/.test( line.text ) &&
						! /[[{]$/.test( line.text );
					return (
						<span
							key={ index }
							className={
								hit
									? 'estasoft-sync-code__line is-changed'
									: 'estasoft-sync-code__line'
							}
						>
							{ hit ? (
								<span className="estasoft-sync-sr-only">
									{ __(
										'Changed by a rule:',
										'estasoft-enterprise-erpcrm-sync-engine'
									) }
								</span>
							) : null }
							{ line.text }
						</span>
					);
				} ) }
			</code>
		</pre>
	);
}
