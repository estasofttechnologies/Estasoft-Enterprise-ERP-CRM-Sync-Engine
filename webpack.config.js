/**
 * Webpack configuration: the @wordpress/scripts default plus three changes.
 *
 * 1. `react/jsx-runtime` is bundled from `src/jsx-runtime.ts` instead of being left to
 *    WordPress. WordPress only registers that script handle from 6.6, and the plugin supports
 *    6.4. The shim builds elements with `createElement` from `@wordpress/element`, so it always
 *    matches the React that WordPress loads.
 * 2. `process.env.ESTASOFT_MOCK` is replaced at build time. The in-memory mock server is only
 *    imported behind that constant, so a production build contains none of it.
 * 3. With `ESTASOFT_MOCK=1` the @wordpress packages are bundled (no externals), so the app can
 *    run from a plain HTML page without WordPress. That build also writes `build/mock/index.html`
 *    (from `src/dev/host.html`) next to the bundle (see docs/admin-app.md).
 */
const fs = require( 'fs' );
const path = require( 'path' );
const webpack = require( 'webpack' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const isMock = process.env.ESTASOFT_MOCK === '1';

const plugins = defaultConfig.plugins.filter(
	( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
);

if ( ! isMock ) {
	plugins.push(
		new DependencyExtractionWebpackPlugin( {
			requestToExternal( request ) {
				if ( request === 'react/jsx-runtime' ) {
					// A falsy, defined value means: bundle it.
					return false;
				}
				return undefined;
			},
		} )
	);
}

if ( isMock ) {
	plugins.push( {
		apply( compiler ) {
			compiler.hooks.thisCompilation.tap( 'EstasoftDevHost', ( compilation ) => {
				compilation.hooks.processAssets.tap(
					{
						name: 'EstasoftDevHost',
						stage: webpack.Compilation.PROCESS_ASSETS_STAGE_ADDITIONAL,
					},
					() => {
						const html = fs.readFileSync(
							path.resolve( __dirname, 'src/dev/host.html' ),
							'utf8'
						);
						compilation.emitAsset(
							'index.html',
							new webpack.sources.RawSource( html )
						);
					}
				);
			} );
		},
	} );
}

plugins.push(
	new webpack.DefinePlugin( {
		'process.env.ESTASOFT_MOCK': JSON.stringify( isMock ? '1' : '' ),
	} )
);

module.exports = {
	...defaultConfig,
	plugins,
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve && defaultConfig.resolve.alias ),
			...( isMock
				? {}
				: {
						'react/jsx-runtime$': path.resolve( __dirname, 'src/jsx-runtime.ts' ),
				  } ),
		},
	},
};
