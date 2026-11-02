<?php
/**
 * Writes the "External services" disclosure that WordPress.org asks for (guideline 6) from the connector documents.
 *
 *   php bin/generate-external-services.php           rewrite readme.txt (External services), docs/release/EXTERNAL-SERVICES.md and the systems table of README.md
 *   php bin/generate-external-services.php --check   exit 1 when either file is out of date or a contacted service has no terms or privacy link
 *   php bin/generate-external-services.php --print   print the readme.txt section and change nothing
 *
 * Inputs: the front matter of docs/connectors/{id}.md (id, name, catalog_row, connection_method, available, unavailable_reason, verified, hosts,
 * terms_url, privacy_url), the section "Data sent and when" of each document, and the `type` of the catalog row (includes/Catalog/catalog.php).
 *
 * readme.txt: everything between the heading `== External services ==` and the next `== ` heading is rewritten, except the hand-written introduction,
 * which is every line from the heading up to the first line of the form `= Name =`. Edit the introduction by hand; run this script to refresh the rest.
 * Per service the readme gets one short paragraph (what it is used for, the data sent, when, the hosts) and the terms and privacy links. Systems that
 * make a file for the merchant to import contact nothing and share one entry; systems with `available: false` are named in one "Not available" note.
 *
 * docs/release/EXTERNAL-SERVICES.md: the full table (system, hosts, data sent, when, terms, privacy, status) with the data taken from each document's
 * own "Data sent and when" section, plus the links that still need a person to confirm them.
 *
 * README.md: the table of supported systems between the lines `<!-- supported-systems:start -->` and `<!-- supported-systems:end -->` is rewritten from the same
 * documents (system, type, how it connects, and whether it has been tried with a live account).
 *
 * Exit codes: 0 fine, 1 out of date or incomplete (--check), 2 usage problem.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

if ( ! defined( 'ESTASOFT_CATALOG_LIB' ) ) {
	define( 'ESTASOFT_CATALOG_LIB', true );
}
require_once __DIR__ . '/generate-catalog-from-docs.php';

const ESTASOFT_EXT_TYPE_ORDER  = array( 'erp', 'accounting', 'crm' );
const ESTASOFT_EXT_TYPE_LABELS = array(
	'erp'        => 'ERP',
	'accounting' => 'Accounting',
	'crm'        => 'CRM',
);
const ESTASOFT_EXT_METHOD_LABELS = array(
	'key'          => 'API key or login',
	'own_app'      => 'Your own app and sign-in page',
	'cloud_app'    => 'Cloud app (no sign-in page)',
	'server_login' => 'Server login',
	'export_file'  => 'File you import',
	'none'         => 'None',
	'unknown'      => 'See the document',
);
const ESTASOFT_EXT_CREDENTIALS = array(
	'key'          => 'your API key or token',
	'own_app'      => 'the client ID and secret of your own app, and its sign-in tokens',
	'cloud_app'    => 'your app\'s client ID and secret (or signed assertion), and its access tokens',
	'server_login' => 'your user name and password',
	'unknown'      => 'your sign-in details',
);
/*
 * Connector ids whose vendor publishes no terms of use for the software itself, because the merchant runs it on their own server (open source). The readme
 * then says so in words instead of linking a page. A terms_url in the front matter always wins over this list.
 */
const ESTASOFT_EXT_NO_TERMS = array(
	'suitecrm' => 'none; SuiteCRM is open source software (AGPL) that runs on your own server, so no vendor processes your data',
);
/*
 * Data and timing for a connector whose document has no "Data sent and when" section yet. The document is the source; this is only a stopgap so the table
 * is complete, and the script reports the missing section.
 */
const ESTASOFT_EXT_FALLBACK = array(
	'odoo' => array(
		'data' => array(
			'Your Odoo login and API key (to sign in)',
			'The order, customer and product data your mapping selects: order number, date, status, totals, line items with SKU, quantity, price and tax, shipping, fees, coupons, name, email, phone, addresses, company, buyer tax number. Full card numbers are never read',
		),
		'when' => array(
			'Test connection, Sync again, Retry',
			'An order, refund or customer event is delivered from the queue; the scheduled stock, tracking, invoice-status and nightly reconciliation reads (reads only)',
		),
	),
);
const ESTASOFT_EXT_PURPOSE = array(
	'erp'        => 'Creates sales orders, customers and credit notes in %s for your WooCommerce orders, and reads stock and tracking back where offered.',
	'accounting' => 'Creates invoices (or sales orders), credit notes and customers in %s for your WooCommerce orders, and reads stock and invoice status back where offered.',
	'crm'        => 'Creates deals (or opportunities), contacts and refund notes in %s for your WooCommerce orders.',
);

/**
 * Read every connector document and the catalog type of its row.
 *
 * @param string            $root     Plugin root.
 * @param array<int,string> $problems Receives a line for each thing that must be fixed before submission.
 * @return array<int,array<string,mixed>> One entry per document: id, name, type, kind (api, file or unavailable), method, hosts, terms, privacy, flags, section.
 */
function estasoft_ext_services( string $root, array &$problems ): array {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/estasoft-sync-abspath/' );
	}
	$rows  = require $root . '/includes/Catalog/catalog.php';
	$types = array();
	foreach ( $rows as $row ) {
		$types[ (string) $row['name'] ] = (string) $row['type'];
	}

	$services = array();
	$files    = glob( $root . '/docs/connectors/*.md' );
	sort( $files );
	foreach ( (array) $files as $file ) {
		$slug = basename( $file, '.md' );
		if ( in_array( $slug, array( 'README', 'BUILDING' ), true ) ) {
			continue;
		}
		$text  = (string) file_get_contents( $file );
		$front = estasoft_front_matter( $text );
		if ( ! isset( $front['id'], $front['name'], $front['catalog_row'] ) ) {
			$problems[] = "{$slug}: the front matter has no id, name or catalog_row.";
			continue;
		}
		$method    = (string) ( $front['connection_method'] ?? 'unknown' );
		$available = ! array_key_exists( 'available', $front ) || false !== $front['available'];
		$kind      = 'api';
		if ( ! $available ) {
			$kind = 'unavailable';
		} elseif ( 'export_file' === $method ) {
			$kind = 'file';
		}
		$hosts = array();
		foreach ( (array) ( $front['hosts'] ?? array() ) as $host ) {
			$hosts[] = trim( (string) $host );
		}
		$terms   = is_string( $front['terms_url'] ?? null ) ? trim( (string) $front['terms_url'] ) : '';
		$privacy = is_string( $front['privacy_url'] ?? null ) ? trim( (string) $front['privacy_url'] ) : '';
		$flags   = estasoft_ext_link_flags( $text );

		$terms_note = '';
		if ( '' === $terms && isset( ESTASOFT_EXT_NO_TERMS[ (string) $front['id'] ] ) ) {
			$terms_note = ESTASOFT_EXT_NO_TERMS[ (string) $front['id'] ];
		}
		if ( 'api' === $kind ) {
			if ( '' === $terms && '' === $terms_note ) {
				$problems[] = "{$front['id']}: terms_url is empty; WordPress.org needs a link to the terms of every service the plugin contacts.";
			}
			if ( '' === $privacy ) {
				$problems[] = "{$front['id']}: privacy_url is empty; WordPress.org needs a link to the privacy policy of every service the plugin contacts.";
			}
		}
		if ( 'unavailable' !== $kind && '' === estasoft_ext_section( $text, 'Data sent and when' ) ) {
			$problems[] = "note: {$front['id']}: the document has no section \"Data sent and when\"" . ( isset( ESTASOFT_EXT_FALLBACK[ (string) $front['id'] ] ) ? ' (the built-in text of this script is used meanwhile).' : '.' );
		}
		$services[] = array(
			'id'        => (string) $front['id'],
			'slug'      => $slug,
			'name'      => (string) $front['name'],
			'type'      => $types[ (string) $front['catalog_row'] ] ?? 'accounting',
			'kind'      => $kind,
			'method'    => $method,
			'hosts'     => $hosts,
			'terms'     => $terms,
			'terms_note' => $terms_note,
			'privacy'   => $privacy,
			'verified'  => ! empty( $front['verified'] ),
			'reason'    => trim( (string) ( $front['unavailable_reason'] ?? '' ) ),
			'flags'     => $flags,
			'section'   => estasoft_ext_section( $text, 'Data sent and when' ),
		);
	}
	usort(
		$services,
		static fn( array $a, array $b ): int => strcasecmp( (string) $a['name'], (string) $b['name'] )
	);
	return $services;
}

/**
 * Which link lines of the front matter carry a comment saying the page was not read.
 *
 * @param string $text Document text.
 * @return array<string,bool> terms and privacy, true when the link is not confirmed.
 */
function estasoft_ext_link_flags( string $text ): array {
	$flags = array(
		'terms'   => false,
		'privacy' => false,
	);
	if ( 1 !== preg_match( '/\A---\R(.*?)\R---\R/s', $text, $block ) ) {
		return $flags;
	}
	foreach ( (array) preg_split( '/\R/', $block[1] ) as $line ) {
		if ( 1 === preg_match( '/^(terms|privacy)_url:.*#(.*)$/', (string) $line, $match ) && 1 === preg_match( '/not (verified|fetched|reachable|read)|was not read|refuses automated|confirm|check it is/i', $match[2] ) ) {
			$flags[ $match[1] ] = true;
		}
	}
	return $flags;
}

/**
 * The body of a section whose heading contains a phrase, without the heading.
 *
 * @param string $text   Document text.
 * @param string $phrase Words the heading starts with (after an optional number).
 */
function estasoft_ext_section( string $text, string $phrase ): string {
	if ( 1 !== preg_match( '/^##\s+(?:\d+\.?\s+)?' . preg_quote( $phrase, '/' ) . '[^\n]*\R(.*?)(?=^##\s|\z)/ms', $text, $match ) ) {
		return '';
	}
	return trim( $match[1] );
}

/**
 * Split a Markdown table row into cells (an escaped pipe stays inside its cell).
 *
 * @param string $line One table line.
 * @return array<int,string>
 */
function estasoft_ext_cells( string $line ): array {
	$line  = trim( $line );
	$line  = trim( $line, '|' );
	$cells = preg_split( '/(?<!\\\\)\|/', $line );
	return array_map( static fn( string $cell ): string => trim( str_replace( '\\|', '|', $cell ) ), (array) $cells );
}

/**
 * What is sent and when, in two parallel lists, taken from the tables of a "Data sent and when" section.
 *
 * A table may be headed Host / Action / When and Data sent (or Data, or What is sent). Rows with no data column are skipped. A section without a
 * table (a file export, a connector that makes no request) is returned as its own words.
 *
 * @param string $section Section body.
 * @return array{data:array<int,string>,when:array<int,string>,note:string}
 */
function estasoft_ext_summary( string $section ): array {
	$data  = array();
	$when  = array();
	$notes = array();
	$head  = null;
	foreach ( (array) preg_split( '/\R/', $section ) as $line ) {
		$line = (string) $line;
		if ( 0 !== strpos( ltrim( $line ), '|' ) ) {
			$head = null;
			if ( '' !== trim( $line ) ) {
				$notes[] = trim( $line );
			}
			continue;
		}
		$cells = estasoft_ext_cells( $line );
		if ( null === $head ) {
			$head = array_map( 'strtolower', $cells );
			continue;
		}
		if ( 1 === preg_match( '/^[\s:\-|]+$/', $line ) ) {
			continue;
		}
		$pick = static function ( array $names ) use ( $head, $cells ): string {
			foreach ( $names as $name ) {
				$index = array_search( $name, $head, true );
				if ( false !== $index && isset( $cells[ $index ] ) ) {
					return $cells[ $index ];
				}
			}
			return '';
		};
		$text = $pick( array( 'data sent', 'what is sent', 'data' ) );
		if ( '' === $text ) {
			continue;
		}
		$host   = $pick( array( 'host' ) );
		$action = $pick( array( 'action' ) );
		$moment = $pick( array( 'when' ) );
		$label  = '' !== $action ? $action . ( '' !== $moment ? ' (' . $moment . ')' : '' ) : $moment;
		$data[] = ( '' !== $host ? $host . ': ' : '' ) . $text;
		$when[] = $label;
	}
	$note = implode( ' ', $notes );
	// Words about this repository's own plumbing mean nothing to a reader of the readme.
	$kept = array();
	foreach ( (array) preg_split( '/(?<=\.)\s+/', $note ) as $sentence ) {
		if ( 1 !== preg_match( '/`hosts`|\bV0\b/', (string) $sentence ) ) {
			$kept[] = $sentence;
		}
	}
	return array(
		'data' => $data,
		'when' => $when,
		'note' => trim( implode( ' ', $kept ) ),
	);
}

/**
 * Hosts as readme text.
 *
 * @param array<string,mixed> $service Service.
 */
function estasoft_ext_hosts( array $service ): string {
	if ( array() === $service['hosts'] ) {
		return 'only the address you enter (your own server or your provider\'s)';
	}
	return implode( ', ', array_map( static fn( string $host ): string => '`' . $host . '`', $service['hosts'] ) );
}

/**
 * The readme paragraph of one service that is contacted over the network.
 *
 * @param array<string,mixed> $service Service.
 */
function estasoft_ext_paragraph( array $service ): string {
	$type    = isset( ESTASOFT_EXT_PURPOSE[ $service['type'] ] ) ? (string) $service['type'] : 'accounting';
	$purpose = sprintf( ESTASOFT_EXT_PURPOSE[ $type ], $service['name'] );
	$creds   = ESTASOFT_EXT_CREDENTIALS[ $service['method'] ] ?? ESTASOFT_EXT_CREDENTIALS['unknown'];
	return $purpose . ' Data sent: ' . $creds . ', and the order, customer and product data your mapping selects; nothing else. When: Test connection, each queued order, refund or customer event, and scheduled reads. Contacts: ' . estasoft_ext_hosts( $service ) . '.';
}

/**
 * The generated part of the readme section (everything after the introduction).
 *
 * @param array<int,array<string,mixed>> $services Services.
 */
function estasoft_ext_readme_block( array $services ): string {
	$out   = '';
	$files = array();
	$gone  = array();
	foreach ( ESTASOFT_EXT_TYPE_ORDER as $type ) {
		foreach ( $services as $service ) {
			if ( 'api' !== $service['kind'] || $type !== $service['type'] ) {
				continue;
			}
			$out .= '= ' . $service['name'] . " =\n" . estasoft_ext_paragraph( $service ) . "\n\n";
			$out .= 'Terms: ' . ( '' !== $service['terms'] ? $service['terms'] : $service['terms_note'] ) . ' | Privacy: ' . $service['privacy'] . "\n\n";
		}
	}
	foreach ( $services as $service ) {
		if ( 'file' === $service['kind'] ) {
			$files[] = $service['name'];
		} elseif ( 'unavailable' === $service['kind'] ) {
			$gone[] = $service;
		}
	}
	if ( array() !== $files ) {
		$out .= '= File exports: ' . implode( ', ', $files ) . " =\n";
		$out .= 'These connectors contact no server and send nothing over the network. They write an import file in a protected folder of your uploads directory, which you download and import yourself. Files are deleted after 30 days and with the plugin\'s data.' . "\n\n";
	}
	if ( array() !== $gone ) {
		$names = array();
		foreach ( $gone as $service ) {
			$names[] = $service['name'] . ( '' !== $service['reason'] ? ' (' . rtrim( $service['reason'], '.' ) . ')' : '' );
		}
		$out .= "= Not available in this version =\n";
		$out .= implode( '; ', $names ) . '. The plugin lists ' . ( 1 === count( $gone ) ? 'it' : 'them' ) . ' but cannot connect to ' . ( 1 === count( $gone ) ? 'it' : 'them' ) . ' and contacts nothing for ' . ( 1 === count( $gone ) ? 'it' : 'them' ) . ".\n\n";
	}
	return rtrim( $out ) . "\n";
}

/**
 * Put the generated block into the readme, keeping the hand-written introduction.
 *
 * @param string $readme Current readme.txt.
 * @param string $block  Generated block.
 * @return string New readme.txt, or the same text when the section cannot be found.
 */
function estasoft_ext_rewrite_readme( string $readme, string $block ): string {
	$lines = explode( "\n", $readme );
	$start = null;
	$end   = count( $lines );
	foreach ( $lines as $index => $line ) {
		if ( null === $start && '== External services ==' === rtrim( $line ) ) {
			$start = $index;
			continue;
		}
		if ( null !== $start && 1 === preg_match( '/^== .+ ==\s*$/', $line ) ) {
			$end = $index;
			break;
		}
	}
	if ( null === $start ) {
		return $readme;
	}
	$intro = array();
	for ( $i = $start + 1; $i < $end; $i++ ) {
		if ( 1 === preg_match( '/^= .+ =\s*$/', $lines[ $i ] ) ) {
			break;
		}
		$intro[] = $lines[ $i ];
	}
	$intro_text = trim( implode( "\n", $intro ) );
	$head       = array_slice( $lines, 0, $start + 1 );
	$tail       = array_slice( $lines, $end );
	return implode( "\n", $head ) . "\n\n" . $intro_text . "\n\n" . $block . "\n" . implode( "\n", $tail );
}

/**
 * One cell of the table: no pipes or line breaks that would break the Markdown table.
 *
 * @param string $text Text.
 */
function estasoft_ext_cell( string $text ): string {
	return str_replace( array( '|', "\n" ), array( '\\|', ' ' ), trim( $text ) );
}

/**
 * A numbered list inside a table cell.
 *
 * @param array<int,string> $items Items.
 */
function estasoft_ext_list_cell( array $items ): string {
	if ( array() === $items ) {
		return '';
	}
	if ( 1 === count( $items ) ) {
		return estasoft_ext_cell( $items[0] );
	}
	$parts = array();
	foreach ( array_values( $items ) as $index => $item ) {
		$parts[] = ( $index + 1 ) . '. ' . estasoft_ext_cell( $item );
	}
	return implode( '<br>', $parts );
}

/**
 * A link cell: the address as a link, marked when nobody has read the page yet.
 *
 * @param string $url     Address.
 * @param bool   $flagged Whether the page is not confirmed.
 * @param string $note    Words to print when there is no address because the vendor publishes none.
 */
function estasoft_ext_link( string $url, bool $flagged, string $note = '' ): string {
	if ( '' === $url ) {
		return '' !== $note ? ucfirst( $note ) : 'None listed';
	}
	return '[' . preg_replace( '#^https://(www\.)?#', '', $url ) . '](' . $url . ')' . ( $flagged ? ' (not confirmed)' : '' );
}

/**
 * The status column.
 *
 * @param array<string,mixed> $service Service.
 */
function estasoft_ext_status( array $service ): string {
	if ( 'unavailable' === $service['kind'] ) {
		return 'Not available';
	}
	return ( 'file' === $service['kind'] ? 'Available, contacts no server' : 'Available' ) . ( $service['verified'] ? ', tested with a live account' : ', not yet tested with a live account' );
}

/**
 * Build docs/release/EXTERNAL-SERVICES.md.
 *
 * @param array<int,array<string,mixed>> $services Services.
 */
function estasoft_ext_table( array $services ): string {
	$api   = array_values( array_filter( $services, static fn( array $s ): bool => 'api' === $s['kind'] ) );
	$files = array_values( array_filter( $services, static fn( array $s ): bool => 'file' === $s['kind'] ) );
	$gone  = array_values( array_filter( $services, static fn( array $s ): bool => 'unavailable' === $s['kind'] ) );

	$out  = "# External services\n\n";
	$out .= "Generated by `php bin/generate-external-services.php` from `docs/connectors/*.md`; do not edit by hand (CI runs it with `--check`). It backs the `== External services ==` section of `readme.txt`, which WordPress.org requires for every service a plugin contacts (guideline 6).\n\n";
	$out .= "The plugin has no Estasoft server, no telemetry, no analytics and no update check. It contacts only the system the merchant connects, only after the merchant has entered that system's credentials, and sends only the data the merchant's mapping selects. Each row below says which hosts are contacted, what is sent and when. \"Data sent\" and \"When\" are numbered in the same order and come from the section \"Data sent and when\" of the connector's own document. A host written `*.example.com` stands for the address of the merchant's own account at that vendor; \"the address you enter\" means a server the merchant runs or rents.\n\n";
	$out .= sprintf( "%d systems are contacted over the network, %d make a file and contact nothing, %d cannot be selected yet.\n\n", count( $api ), count( $files ), count( $gone ) );

	$out .= "## Systems the plugin contacts\n\n";
	$out .= "| System | Type | How it connects | Hosts | Data sent | When | Terms | Privacy | Status |\n|---|---|---|---|---|---|---|---|---|\n";
	foreach ( ESTASOFT_EXT_TYPE_ORDER as $type ) {
		foreach ( $api as $service ) {
			if ( $type !== $service['type'] ) {
				continue;
			}
			$summary = estasoft_ext_summary( (string) $service['section'] );
			if ( array() === $summary['data'] && isset( ESTASOFT_EXT_FALLBACK[ $service['id'] ] ) ) {
				$summary = ESTASOFT_EXT_FALLBACK[ $service['id'] ] + array( 'note' => '' );
			}
			$hosts   = array() === $service['hosts'] ? 'The address you enter' : implode( '<br>', array_map( static fn( string $h ): string => '`' . $h . '`', $service['hosts'] ) );
			$out    .= '| [' . $service['name'] . '](../connectors/' . $service['slug'] . '.md) | ' . ESTASOFT_EXT_TYPE_LABELS[ $type ] . ' | ' . ( ESTASOFT_EXT_METHOD_LABELS[ $service['method'] ] ?? $service['method'] ) . ' | ' . $hosts . ' | ' . estasoft_ext_list_cell( $summary['data'] ) . ' | ' . estasoft_ext_list_cell( $summary['when'] ) . ' | ' . estasoft_ext_link( (string) $service['terms'], (bool) $service['flags']['terms'], (string) $service['terms_note'] ) . ' | ' . estasoft_ext_link( (string) $service['privacy'], (bool) $service['flags']['privacy'] ) . ' | ' . estasoft_ext_status( $service ) . " |\n";
		}
	}

	$out .= "\n## Systems that contact nothing (a file you import)\n\n";
	$out .= "| System | Type | Data sent | When | Terms | Privacy | Status |\n|---|---|---|---|---|---|---|\n";
	foreach ( $files as $service ) {
		$summary = estasoft_ext_summary( (string) $service['section'] );
		$note    = '' !== $summary['note'] ? $summary['note'] : 'No data is sent to any host.';
		$out    .= '| [' . $service['name'] . '](../connectors/' . $service['slug'] . '.md) | ' . ( ESTASOFT_EXT_TYPE_LABELS[ $service['type'] ] ?? $service['type'] ) . ' | ' . estasoft_ext_cell( $note ) . ' | Never: the plugin writes a file in a protected folder of the uploads directory and the merchant downloads it | ' . estasoft_ext_link( (string) $service['terms'], (bool) $service['flags']['terms'], (string) $service['terms_note'] ) . ' | ' . estasoft_ext_link( (string) $service['privacy'], (bool) $service['flags']['privacy'] ) . ' | ' . estasoft_ext_status( $service ) . " |\n";
	}

	$out .= "\n## Systems that cannot be selected yet\n\n";
	$out .= "| System | Type | Why | Terms | Privacy | Status |\n|---|---|---|---|---|---|\n";
	foreach ( $gone as $service ) {
		$out .= '| [' . $service['name'] . '](../connectors/' . $service['slug'] . '.md) | ' . ( ESTASOFT_EXT_TYPE_LABELS[ $service['type'] ] ?? $service['type'] ) . ' | ' . estasoft_ext_cell( '' !== $service['reason'] ? (string) $service['reason'] : 'No connector can be selected in this version.' ) . ' | ' . estasoft_ext_link( (string) $service['terms'], (bool) $service['flags']['terms'], (string) $service['terms_note'] ) . ' | ' . estasoft_ext_link( (string) $service['privacy'], (bool) $service['flags']['privacy'] ) . ' | ' . estasoft_ext_status( $service ) . " |\n";
	}

	$open = array();
	foreach ( $services as $service ) {
		if ( 'api' !== $service['kind'] ) {
			continue;
		}
		foreach ( array( 'terms', 'privacy' ) as $which ) {
			if ( '' === $service[ $which ] && ! ( 'terms' === $which && '' !== $service['terms_note'] ) ) {
				$open[] = '`' . $service['id'] . '`: no ' . $which . ' link in the front matter';
			} elseif ( $service['flags'][ $which ] ) {
				$open[] = '`' . $service['id'] . '`: the ' . $which . ' link has not been read by a person or a fetch';
			}
		}
	}
	$out .= "\n## Links that need a person to confirm them\n\n";
	if ( array() === $open ) {
		$out .= "None.\n";
	} else {
		$out .= "These are the front matter links of the documents above whose page was not read when the document was written (the comment on the line says why). Open each one before submission, and change the front matter if the page moved. `docs/release/OWNER-ACTIONS.md` lists this as an owner task.\n\n";
		foreach ( $open as $line ) {
			$out .= '- ' . $line . "\n";
		}
	}
	return $out;
}

/**
 * The table of supported systems for README.md.
 *
 * @param array<int,array<string,mixed>> $services Services.
 */
function estasoft_ext_systems_table( array $services ): string {
	$out  = "| System | Type | How it connects | Not yet tested with a live account |\n|---|---|---|---|\n";
	$rank = array_flip( ESTASOFT_EXT_TYPE_ORDER );
	usort(
		$services,
		static fn( array $a, array $b ): int => ( $rank[ $a['type'] ] ?? 9 ) <=> ( $rank[ $b['type'] ] ?? 9 ) ?: strcasecmp( (string) $a['name'], (string) $b['name'] )
	);
	foreach ( $services as $service ) {
		$tested = 'Yes';
		if ( 'unavailable' === $service['kind'] ) {
			$tested = 'Not available yet';
		} elseif ( $service['verified'] ) {
			$tested = 'No, tested';
		}
		$method = 'unavailable' === $service['kind'] ? 'Cannot be selected yet' : ( ESTASOFT_EXT_METHOD_LABELS[ $service['method'] ] ?? (string) $service['method'] );
		$out   .= '| [' . $service['name'] . '](docs/connectors/' . $service['slug'] . '.md) | ' . ( ESTASOFT_EXT_TYPE_LABELS[ $service['type'] ] ?? $service['type'] ) . ' | ' . $method . ' | ' . $tested . " |\n";
	}
	return $out;
}

/**
 * Put a block between two marker lines of a file's text.
 *
 * @param string $text  File text.
 * @param string $name  Marker name (the lines are `<!-- name:start -->` and `<!-- name:end -->`).
 * @param string $block New content between the markers.
 * @return string|null The new text, or null when a marker is missing.
 */
function estasoft_ext_replace_marked( string $text, string $name, string $block ): ?string {
	$start = '<!-- ' . $name . ':start -->';
	$end   = '<!-- ' . $name . ':end -->';
	$from  = strpos( $text, $start );
	$to    = strpos( $text, $end );
	if ( false === $from || false === $to || $to < $from ) {
		return null;
	}
	return substr( $text, 0, $from + strlen( $start ) ) . "\n" . $block . substr( $text, $to );
}

/**
 * Run the script.
 *
 * @param array<int,string> $argv Arguments.
 */
function estasoft_ext_main( array $argv ): int {
	$mode = $argv[1] ?? '';
	if ( ! in_array( $mode, array( '', '--check', '--print' ), true ) ) {
		fwrite( STDERR, "Usage: php bin/generate-external-services.php [--check|--print]\n" );
		return 2;
	}
	$root     = dirname( __DIR__ );
	$problems = array();
	$services = estasoft_ext_services( $root, $problems );
	if ( array() === $services ) {
		fwrite( STDERR, "generate-external-services: no connector documents found.\n" );
		return 1;
	}
	$block  = estasoft_ext_readme_block( $services );
	$readme = (string) file_get_contents( $root . '/readme.txt' );
	$new    = estasoft_ext_rewrite_readme( $readme, $block );
	if ( $new === $readme && false === strpos( $readme, '== External services ==' ) ) {
		fwrite( STDERR, "generate-external-services: readme.txt has no == External services == heading.\n" );
		return 1;
	}
	if ( '--print' === $mode ) {
		echo $block;
		return 0;
	}
	$targets = array(
		$root . '/readme.txt'                         => $new,
		$root . '/docs/release/EXTERNAL-SERVICES.md' => estasoft_ext_table( $services ),
	);
	$readme_md = is_file( $root . '/README.md' ) ? (string) file_get_contents( $root . '/README.md' ) : '';
	$new_md    = estasoft_ext_replace_marked( $readme_md, 'supported-systems', estasoft_ext_systems_table( $services ) );
	if ( null === $new_md ) {
		$problems[] = 'README.md has no <!-- supported-systems:start --> and <!-- supported-systems:end --> markers.';
	} else {
		$targets[ $root . '/README.md' ] = $new_md;
	}
	$changed = 0;
	foreach ( $targets as $path => $text ) {
		$current = is_file( $path ) ? (string) file_get_contents( $path ) : '';
		if ( $current === $text ) {
			continue;
		}
		++$changed;
		if ( '--check' === $mode ) {
			fwrite( STDERR, substr( $path, strlen( $root ) + 1 ) . " is out of date. Run: php bin/generate-external-services.php\n" );
		} else {
			file_put_contents( $path, $text );
			echo 'Wrote ' . substr( $path, strlen( $root ) + 1 ) . "\n";
		}
	}
	$fatal = array();
	foreach ( $problems as $problem ) {
		fwrite( STDERR, 'generate-external-services: ' . $problem . "\n" );
		if ( 0 !== strpos( $problem, 'note: ' ) ) {
			$fatal[] = $problem;
		}
	}
	if ( '--check' === $mode && ( $changed > 0 || array() !== $fatal ) ) {
		return 1;
	}
	if ( 0 === $changed && array() === $fatal ) {
		echo "The External services section and docs/release/EXTERNAL-SERVICES.md are up to date.\n";
	}
	return 0;
}

exit( estasoft_ext_main( $argv ) );
