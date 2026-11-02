import type { CatalogEntry, MappingRule, QueueItem } from '../../src/api/types';
import { CATALOG } from '../../src/api/mock/catalog';
import {
	causeValue,
	groupRemedy,
	groupSentence,
	groupTitle,
} from '../../src/lib/attention';
import { filterCatalog, methodLabel, suggestFor } from '../../src/lib/catalog';
import {
	currencyExponent,
	formatDay,
	formatDuration,
	formatMoney,
	formatSmartTime,
	formatSpan,
	formatTime,
	formatTimeLeft,
	timeLeftTone,
	toLocaleTag,
} from '../../src/lib/format';
import { csvCell, toCsv } from '../../src/lib/csv';
import { getPath } from '../../src/lib/path';
import { safeHttpUrl } from '../../src/lib/url';
import { describeRule, diffRules, nextRuleId } from '../../src/lib/rules';
import {
	catalogMessage,
	eventLabel,
	daysPhrase,
	fillPlaceholders,
	groupsPhrase,
	notesPhrase,
	ordersPhrase,
	whatHappened,
} from '../../src/lib/text';

const dubai = { locale: 'en_GB', timezone: 'Asia/Dubai', currency: 'AED' };
const nbsp = ( text: string ) =>
	text.split( String.fromCharCode( 160 ) ).join( ' ' );

describe( 'format', () => {
	it( 'formats money from integer minor units with the currency exponent', () => {
		expect(
			nbsp( formatMoney( { minor: 43800, currency: 'AED' }, dubai ) )
		).toBe( 'AED 438.00' );
		expect(
			nbsp( formatMoney( { minor: 1234567, currency: 'KWD' }, dubai ) )
		).toBe( 'KWD 1,234.567' );
		expect(
			nbsp( formatMoney( { minor: 5000, currency: 'JPY' }, dubai ) )
		).toBe( 'JPY 5,000' );
		expect( formatMoney( null, dubai ) ).toBe( '—' );
		expect( currencyExponent( 'KWD' ) ).toBe( 3 );
	} );

	it.each( [
		[ 'KWD', 3, 1234567, 'KWD 1,234.567' ],
		[ 'BHD', 3, 1234567, 'BHD 1,234.567' ],
		[ 'OMR', 3, 1234567, 'OMR 1,234.567' ],
		[ 'JOD', 3, 1234567, 'JOD 1,234.567' ],
		[ 'TND', 3, 1234567, 'TND 1,234.567' ],
		[ 'IQD', 3, 1234567, 'IQD 1,234.567' ],
		[ 'JPY', 0, 5000, 'JPY 5,000' ],
		[ 'KRW', 0, 5000, 'KRW 5,000' ],
		[ 'AED', 2, 43800, 'AED 438.00' ],
	] )(
		'uses the server exponent for %s (%d places), not Intl',
		( currency, places, minor, text ) => {
			expect( currencyExponent( currency ) ).toBe( places );
			expect( nbsp( formatMoney( { minor, currency }, dubai ) ) ).toBe(
				text
			);
		}
	);

	it( 'takes the exponent of the store currency from the boot data', () => {
		const store = { ...dubai, currency: 'IQD', currencyDecimals: 3 };
		expect( currencyExponent( 'IQD', store ) ).toBe( 3 );
		expect(
			nbsp( formatMoney( { minor: 1500, currency: 'IQD' }, store ) )
		).toBe( 'IQD 1.500' );
		// Another currency on the same page still follows the table.
		expect( currencyExponent( 'JPY', store ) ).toBe( 0 );
		// An unknown currency has two places, like the server.
		expect( currencyExponent( 'xyz' ) ).toBe( 2 );
	} );

	it( 'shows times in the store time zone and in UTC on request', () => {
		expect( formatTime( '2026-11-02T07:40:00Z', dubai ) ).toBe( '11:40' );
		expect(
			formatTime( '2026-11-02T07:40:00Z', { ...dubai, timezone: 'UTC' } )
		).toBe( '07:40' );
		expect( formatTime( '2026-11-01T20:00:00Z', dubai ) ).toBe( '00:00' );
		expect( formatTime( null, dubai ) ).toBe( '—' );
		expect(
			formatTime( '2026-11-02T07:40:00Z', {
				...dubai,
				timezone: 'Not/AZone',
			} )
		).toBe( '07:40' );
	} );

	it( 'says Today and Yesterday by the store clock, not the browser clock', () => {
		const now = '2026-11-02T07:40:00Z';
		expect( formatSmartTime( '2026-11-02T07:00:00Z', dubai, now ) ).toBe(
			'Today 11:00'
		);
		expect( formatSmartTime( '2026-11-01T14:00:00Z', dubai, now ) ).toBe(
			'Yesterday 18:00'
		);
		expect( formatSmartTime( '2026-10-31T14:00:00Z', dubai, now ) ).toBe(
			'31 Oct 18:00'
		);
		expect( formatDay( '2026-11-14T00:00:00Z', dubai ) ).toBe(
			'14 Nov 2026'
		);
	} );

	it( 'writes spans and durations in words', () => {
		expect( formatSpan( 600 ) ).toBe( '10 min' );
		expect( formatSpan( 20400 ) ).toBe( '5 h 40 min' );
		expect( formatSpan( 7200 ) ).toBe( '2 h' );
		expect( formatSpan( 45 ) ).toBe( '45 seconds' );
		expect( formatSpan( 60 * 60 * 72 ) ).toBe( '3 days' );
		expect( formatDuration( 840 ) ).toBe( '840 ms' );
		expect( formatDuration( 1100 ) ).toBe( '1.1 s' );
		expect( formatDuration( 30000 ) ).toBe( '30 s' );
		expect( formatTimeLeft( 20400 ) ).toBe( '5 h 40 min left' );
		expect( formatTimeLeft( -7200 ) ).toBe( 'Late by 2 h' );
	} );

	it( 'colours the invoice countdown: neutral above 12 h, warning 3 to 12 h, danger under 3 h', () => {
		expect( timeLeftTone( 13 * 3600 ) ).toBe( 'neutral' );
		expect( timeLeftTone( 12 * 3600 ) ).toBe( 'warning' );
		expect( timeLeftTone( 3 * 3600 ) ).toBe( 'warning' );
		expect( timeLeftTone( 3 * 3600 - 1 ) ).toBe( 'danger' );
		expect( timeLeftTone( -60 ) ).toBe( 'danger' );
	} );

	it( 'turns WordPress locales into language tags', () => {
		expect( toLocaleTag( 'de_DE_formal' ) ).toBe( 'de-DE' );
		expect( toLocaleTag( 'ar' ) ).toBe( 'ar' );
		expect( toLocaleTag( '' ) ).toBe( 'en-US' );
	} );
} );

describe( 'text', () => {
	it( 'fills placeholders from the error catalog and leaves no stray braces', () => {
		expect(
			catalogMessage( 'SYNC-MAP-001', { sku: 'CS-BOWL-04' }, 'Odoo' )
		).toBe( 'Product CS-BOWL-04 was not found in Odoo.' );
		expect( catalogMessage( 'SYNC-MAP-001', {}, 'Odoo' ) ).toBe(
			'Product was not found in Odoo.'
		);
		expect( catalogMessage( 'SYNC-NOPE-999' ) ).toBe( '' );
		expect( fillPlaceholders( 'A {x} B', {} ) ).toBe( 'A B' );
	} );

	it( 'names events and counts orders', () => {
		expect( eventLabel( 'created', 'order' ) ).toBe( 'Order created' );
		expect( eventLabel( 'order.paid', 'order' ) ).toBe( 'Order paid' );
		expect( eventLabel( 'refund', 'refund' ) ).toBe( 'Refund' );
		expect( ordersPhrase( 1 ) ).toBe( '1 order' );
		expect( ordersPhrase( 3 ) ).toBe( '3 orders' );
	} );

	it( 'counts each noun with its own number', () => {
		expect( groupsPhrase( 1 ) ).toBe( '1 group' );
		expect( groupsPhrase( 3 ) ).toBe( '3 groups' );
		expect( daysPhrase( 1 ) ).toBe( '1 day' );
		expect( daysPhrase( 7 ) ).toBe( '7 days' );
		expect( notesPhrase( 1 ) ).toBe( '1 note' );
		expect( notesPhrase( 2 ) ).toBe( '2 notes' );
	} );

	it( 'fills placeholders without touching the punctuation of the text around them', () => {
		// A space before a colon is right in French; only the markers are replaced.
		expect( fillPlaceholders( 'Erreur : {x} ; fin', { x: 'abc' } ) ).toBe(
			'Erreur : abc ; fin'
		);
	} );

	const base: QueueItem = {
		id: 1,
		connection_id: 12,
		entity_type: 'order',
		entity_id: '1',
		entity_label: '10489',
		event: 'created',
		state: 'retrying',
		mode: 'live',
		attempt: 1,
		max_attempts: 5,
		next_attempt_at: '2026-11-02T07:41:00Z',
		error_code: 'SYNC-NET-002',
		error_message: 'Odoo answered 503',
		external_id: null,
		external_ref: null,
		external_url: null,
		skip_reason: null,
		customer_name: null,
		total: null,
		waiting_for_earlier: false,
		created_at: '2026-11-02T07:39:00Z',
		updated_at: '2026-11-02T07:39:00Z',
		finished_at: null,
		order_edit_url: null,
	};

	it( 'describes each state in the "what happened" column', () => {
		expect( whatHappened( base, 'Odoo', dubai ) ).toBe(
			'Odoo answered 503. Retry 1 of 4, next try 11:41'
		);
		// No next try yet: its own sentence, not the same text with a phrase cut off.
		expect(
			whatHappened( { ...base, next_attempt_at: null }, 'Odoo', dubai )
		).toBe( 'Odoo answered 503. Retry 1 of 4' );
		expect(
			whatHappened( { ...base, state: 'queued' }, 'Odoo', dubai )
		).toBe( 'Waiting to be sent' );
		expect(
			whatHappened(
				{ ...base, state: 'queued', waiting_for_earlier: true },
				'Odoo',
				dubai
			)
		).toMatch( /earlier step/ );
		expect(
			whatHappened( { ...base, state: 'syncing' }, 'Odoo', dubai )
		).toBe( 'Sending to Odoo' );
		expect(
			whatHappened(
				{ ...base, state: 'synced', external_ref: 'S00490' },
				'Odoo',
				dubai
			)
		).toBe( 'Odoo S00490' );
		expect(
			whatHappened(
				{
					...base,
					state: 'synced',
					entity_type: 'refund',
					external_ref: 'RINV/2026/0031',
				},
				'Odoo',
				dubai
			)
		).toBe( 'Credit note RINV/2026/0031' );
		expect(
			whatHappened(
				{
					...base,
					state: 'skipped',
					skip_reason: 'Test order',
					error_message: null,
				},
				'Odoo',
				dubai
			)
		).toBe( 'Test order. Skipped at 11:39' );
		expect(
			whatHappened(
				{
					...base,
					state: 'failed',
					error_message: null,
					error_code: 'SYNC-MAP-003',
				},
				'Odoo',
				dubai
			)
		).toMatch( /email is missing/ );
		expect(
			whatHappened(
				{ ...base, state: 'synced', mode: 'shadow' },
				'Odoo',
				dubai
			)
		).toMatch( /Nothing was sent/ );
	} );
} );

describe( 'attention groups', () => {
	const group = {
		error_code: 'SYNC-MAP-001',
		cause: 'SKU CS-BOWL-04',
		count: 3,
		oldest_at: '2026-11-02T06:41:00Z',
		item_ids: [ 1, 2, 3 ],
	};
	it( 'builds titles, sentences and remedies', () => {
		expect( causeValue( group.cause ) ).toBe( 'CS-BOWL-04' );
		expect( groupTitle( group, 'Odoo' ) ).toBe(
			'Product not found in Odoo'
		);
		expect( groupSentence( group, 'Odoo' ) ).toBe(
			'3 orders failed: product CS-BOWL-04 not found in Odoo'
		);
		expect( groupRemedy( group, 'Odoo' ) ).toMatch( /map the SKU/ );
		expect( groupSentence( { ...group, count: 1 }, 'Odoo' ) ).toBe(
			'1 order failed: product CS-BOWL-04 not found in Odoo'
		);
		expect(
			groupSentence(
				{ ...group, error_code: 'SYNC-MAP-002', cause: '', count: 2 },
				'Odoo'
			)
		).toBe( '2 orders failed: tax code is not mapped' );
		expect(
			groupSentence(
				{
					...group,
					error_code: 'SYNC-VAL-001',
					cause: 'Saudi Arabia',
					count: 1,
				},
				'Odoo'
			)
		).toBe(
			'1 order failed: buyer tax number is not valid for Saudi Arabia'
		);
	} );
} );

describe( 'mapping rules', () => {
	const rule = (
		id: string,
		over: Partial< MappingRule > = {}
	): MappingRule => ( {
		id,
		target: 't',
		source: 's',
		transforms: [],
		required: false,
		...over,
	} );

	it( 'describes rules in words', () => {
		expect(
			describeRule(
				rule( 'a', {
					transforms: [ { type: 'prefix', args: { value: 'WC-' } } ],
				} )
			)
		).toBe( 'Add the prefix WC-' );
		expect(
			describeRule( rule( 'a', { transforms: [ { type: 'trim' } ] } ) )
		).toBe( 'Keep as typed' );
		expect( describeRule( rule( 'a', { transforms: [] } ) ) ).toBe(
			'Keep as typed'
		);
		expect(
			describeRule( rule( 'a', { source: { const: 'Web' } } ) )
		).toBe( 'Always send Web' );
		expect(
			describeRule(
				rule( 'a', {
					transforms: [
						{ type: 'join', args: { separator: ' ' } },
						{ type: 'trim' },
					],
				} )
			)
		).toBe( 'Join the fields, keep as typed' );
		expect(
			describeRule( rule( 'a', { transforms: [ { type: 'mystery' } ] } ) )
		).toBe( 'mystery' );
	} );

	it( 'finds added, changed and removed rules by id', () => {
		const live = [
			rule( 'r1' ),
			rule( 'r2', { target: 'x' } ),
			rule( 'r3' ),
		];
		const working = [
			rule( 'r1' ),
			rule( 'r2', { target: 'y' } ),
			rule( 'r4' ),
		];
		expect( diffRules( live, working ) ).toEqual( {
			added: [ 'r4' ],
			changed: [ 'r2' ],
			removed: [ 'r3' ],
		} );
		expect( diffRules( live, live ) ).toEqual( {
			added: [],
			changed: [],
			removed: [],
		} );
		expect( nextRuleId( live ) ).toBe( 'r4' );
	} );

	it( 'does not call a step changed because the server added an empty list of arguments', () => {
		const started = rule( 'r1', {
			transforms: [ { type: 'trim' } ],
		} );
		const cleaned = rule( 'r1', {
			transforms: [
				// PHP sends an empty array of arguments as `[]`.
				{
					type: 'trim',
					args: [] as unknown as Record< string, string >,
				},
			],
		} );
		expect( diffRules( [ started ], [ cleaned ] ).changed ).toEqual( [] );
		expect(
			diffRules(
				[ started ],
				[
					rule( 'r1', {
						transforms: [ { type: 'trim', args: { mode: 'x' } } ],
					} ),
				]
			).changed
		).toEqual( [ 'r1' ] );
		expect( nextRuleId( [ rule( 'r2' ), rule( 'r1' ) ] ) ).toBe( 'r3' );
	} );

	it( 'reads dotted paths', () => {
		const data = { a: { b: [ { c: 5 } ] } };
		expect( getPath( data, 'a.b[0].c' ) ).toBe( 5 );
		expect( getPath( data, 'a.x.y' ) ).toBeUndefined();
	} );
} );

describe( 'catalog', () => {
	it( 'has 34 systems and filters by type, UAE and search', () => {
		expect( CATALOG ).toHaveLength( 34 );
		expect( filterCatalog( CATALOG, 'erp', '' ) ).toHaveLength( 11 );
		expect( filterCatalog( CATALOG, 'accounting', '' ) ).toHaveLength( 17 );
		expect( filterCatalog( CATALOG, 'crm', '' ) ).toHaveLength( 6 );
		expect(
			filterCatalog( CATALOG, 'uae', '' ).every(
				( entry: CatalogEntry ) => /UAE/.test( entry.regions )
			)
		).toBe( true );
		expect(
			filterCatalog( CATALOG, 'all', 'xero' ).map(
				( entry ) => entry.name
			)
		).toContain( 'Xero' );
		expect( filterCatalog( CATALOG, 'all', 'zzzz-nothing' ) ).toEqual( [] );
	} );

	it( 'suggests up to three systems for the store currency and none for an unknown one', () => {
		const uae = suggestFor( CATALOG, 'AED' );
		expect( uae.entries.length ).toBeGreaterThan( 0 );
		expect( uae.entries.length ).toBeLessThanOrEqual( 3 );
		expect( uae.entries[ 0 ].name ).toBe( 'Odoo' );
		expect( suggestFor( CATALOG, 'XYZ' ).entries ).toEqual( [] );
	} );

	it( 'names the ways to connect', () => {
		expect( methodLabel( 'key' ) ).toBe( 'Paste a key' );
		expect( methodLabel( 'export_file' ) ).toBe( 'Export file' );
		expect( methodLabel( 'whatever' ) ).toBe( 'To be confirmed' );
	} );
} );

describe( 'csv', () => {
	it( 'quotes cells and doubles quotes', () => {
		expect( csvCell( 'plain' ) ).toBe( 'plain' );
		expect( csvCell( 'a,b' ) ).toBe( '"a,b"' );
		expect( csvCell( 'say "hi"' ) ).toBe( '"say ""hi"""' );
		expect( csvCell( 'two\nlines' ) ).toBe( '"two\nlines"' );
		expect( csvCell( null ) ).toBe( '' );
		expect( csvCell( 12.5 ) ).toBe( '12.5' );
		expect( csvCell( true ) ).toBe( 'true' );
	} );

	it( 'defuses cells a spreadsheet could run as a formula', () => {
		[ '=1+1', '+1', '-1', '@SUM(A1)', '\tcmd', '\rcmd' ].forEach(
			( value ) => {
				expect( csvCell( value ).replace( /^"/, '' ) ).toMatch( /^'/ );
			}
		);
		expect( csvCell( '=HYPERLINK("x")' ) ).toBe( '"\'=HYPERLINK(""x"")"' );
		// A real number stays a number.
		expect( csvCell( -5 ) ).toBe( '-5' );
	} );

	it( 'writes rows with CRLF line ends', () => {
		expect(
			toCsv( [
				[ 'order', 'fields' ],
				[ '#10490', 'a; b' ],
				[ '=cmd', 'x,y' ],
			] )
		).toBe( 'order,fields\r\n#10490,a; b\r\n\'=cmd,"x,y"' );
	} );
} );

describe( 'safeHttpUrl', () => {
	it( 'allows http and https only', () => {
		expect( safeHttpUrl( 'https://odoo.example/web#id=5' ) ).toBe(
			'https://odoo.example/web#id=5'
		);
		expect( safeHttpUrl( 'http://odoo.example/x' ) ).toBe(
			'http://odoo.example/x'
		);
		expect( safeHttpUrl( 'javascript:alert(1)' ) ).toBeNull();
		expect( safeHttpUrl( ' JavaScript:alert(1)' ) ).toBeNull();
		expect( safeHttpUrl( 'data:text/html,<b>x</b>' ) ).toBeNull();
		expect( safeHttpUrl( 'vbscript:x' ) ).toBeNull();
		expect( safeHttpUrl( '' ) ).toBeNull();
		expect( safeHttpUrl( null ) ).toBeNull();
	} );
} );
