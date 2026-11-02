/**
 * Jest configuration. Run with `npm run test:js` (wp-scripts test-unit-jest).
 */
module.exports = {
	testEnvironment: 'jsdom',
	roots: [ '<rootDir>/tests/js' ],
	testMatch: [ '**/*.test.ts', '**/*.test.tsx' ],
	setupFilesAfterEnv: [ '<rootDir>/tests/js/setup.ts' ],
	moduleNameMapper: {
		'\\.(css|scss)$': '<rootDir>/tests/js/style-mock.js',
	},
	transform: {
		'^.+\\.[cm]?[jt]sx?$': 'babel-jest',
	},
	// A few @wordpress packages (and their dependencies) ship ES modules only; transform them.
	transformIgnorePatterns: [ '/node_modules/(?!(@wordpress/(theme|ui)|@base-ui|@base-ui-components|uuid)/)' ],
	collectCoverageFrom: [ 'src/**/*.{ts,tsx}', '!src/api/mock/**', '!src/index.tsx' ],
	testTimeout: 15000,
};
