/**
 * Minimal automatic-runtime JSX shim on top of `createElement` from `@wordpress/element`.
 *
 * WordPress registers the `react-jsx-runtime` script handle only from 6.6, but this plugin
 * supports 6.4. webpack.config.js maps `react/jsx-runtime` to this file for production builds, so the
 * bundle always builds elements with the React that WordPress itself loads.
 */
import { createElement, Fragment } from '@wordpress/element';

type Props = Record< string, unknown > & { children?: unknown };

/**
 * Creates an element with at most one child (the "jsx" entry point of the automatic runtime).
 *
 * @param type  Element type or component.
 * @param props Props, including `children`.
 * @param key   Optional key.
 * @return The element.
 */
export function jsx( type: never, props: Props, key?: string ) {
	const { children, ...rest } = props;
	if ( key !== undefined ) {
		rest.key = key;
	}
	return children === undefined
		? createElement( type, rest )
		: createElement( type, rest, children as never );
}

/**
 * Creates an element whose children are a static list (the "jsxs" entry point).
 *
 * @param type  Element type or component.
 * @param props Props, including `children` as an array.
 * @param key   Optional key.
 * @return The element.
 */
export function jsxs( type: never, props: Props, key?: string ) {
	const { children, ...rest } = props;
	if ( key !== undefined ) {
		rest.key = key;
	}
	return createElement( type, rest, ...( children as never[] ) );
}

export { Fragment };
