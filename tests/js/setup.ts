import '@testing-library/jest-dom';
import { configureAxe, toHaveNoViolations } from 'jest-axe';

expect.extend( toHaveNoViolations );

// jsdom does not implement these browser APIs that WordPress components use.
class ResizeObserverStub {
	observe() {}
	unobserve() {}
	disconnect() {}
}
class IntersectionObserverStub {
	observe() {}
	unobserve() {}
	disconnect() {}
	takeRecords() {
		return [];
	}
}
Object.assign( window, {
	ResizeObserver: window.ResizeObserver ?? ResizeObserverStub,
	IntersectionObserver:
		window.IntersectionObserver ?? IntersectionObserverStub,
} );
Object.defineProperty( window, 'matchMedia', {
	writable: true,
	value: ( query: string ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addEventListener: () => undefined,
		removeEventListener: () => undefined,
		addListener: () => undefined,
		removeListener: () => undefined,
		dispatchEvent: () => false,
	} ),
} );
window.scrollTo = jest.fn();
Element.prototype.scrollIntoView = jest.fn();

// The live regions of @wordpress/a11y are created on DOM ready; make sure they exist in tests.
const regions = [ 'a11y-speak-polite', 'a11y-speak-assertive' ];
regions.forEach( ( id ) => {
	if ( ! document.getElementById( id ) ) {
		const region = document.createElement( 'div' );
		region.id = id;
		region.className = 'a11y-speak-region';
		region.setAttribute(
			'aria-live',
			id.endsWith( 'polite' ) ? 'polite' : 'assertive'
		);
		document.body.appendChild( region );
	}
} );

/** axe with the rules that make no sense for a fragment of a page switched off. */
export const axe = configureAxe( {
	rules: {
		region: { enabled: false },
		'page-has-heading-one': { enabled: false },
		'landmark-one-main': { enabled: false },
	},
} );
