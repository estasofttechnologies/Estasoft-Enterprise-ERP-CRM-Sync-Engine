/**
 * ESLint flat config: the @wordpress/scripts default, plus the project's text domain.
 */
const defaults = require( '@wordpress/scripts/config/eslint.config.cjs' );

// Helpers named expect*() (expectHeading, expectNoA11yViolations) count as assertions in tests.
const base = defaults.map( ( entry ) =>
	entry.rules && entry.rules[ 'vitest/expect-expect' ]
		? {
				...entry,
				rules: {
					...entry.rules,
					'vitest/expect-expect': [ 'error', { assertFunctionNames: [ 'expect', 'expect*' ] } ],
				},
		  }
		: entry
);

module.exports = [
	...base,
	{
		rules: {
			'@wordpress/i18n-text-domain': [ 'error', { allowedTextDomain: 'estasoft-erp-crm-sync-engine' } ],
			// Screens choose between several states (loading, error, empty, data). A chain of
			// conditions reads better there than a pile of helper components.
			'no-nested-ternary': 'off',
		},
	},
	{
		ignores: [ 'build/**', 'coverage/**', 'node_modules/**', 'vendor/**' ],
	},
];
