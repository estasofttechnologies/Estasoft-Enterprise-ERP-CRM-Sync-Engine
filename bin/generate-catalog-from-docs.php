<?php
/**
 * Rewrites the connector fields of includes/Catalog/catalog.php and the index docs/connectors/README.md from the connector documents.
 *
 *   php bin/generate-catalog-from-docs.php           write both files
 *   php bin/generate-catalog-from-docs.php --check   exit 1 when a file would change, change nothing
 *   php bin/generate-catalog-from-docs.php --strict  also exit 1 when a document breaks a rule (step count or length)
 *
 * Every docs/connectors/{id}.md starts with a front matter block (see docs/connectors/BUILDING.md). A document belongs to the catalog row whose
 * name equals its `catalog_row`. These catalog fields come from the documents: connector, connection_method, available, unavailable_reason, verified,
 * variants, hosts, terms_url, privacy_url, developer_url and steps (the numbered list under the heading "How the merchant connects": at most 6 steps of at most
 * 140 characters). `hosts` may hold patterns: an entry that starts with `*.` (for example `*.oraclecloud.com`) stands for any subdomain and stays in the
 * list with its `*.` so the screens can tell it from a single host. `unavailable_reason` (front matter) says why a system with `available: false` cannot be
 * chosen yet; it is written to the row as `unavailable_reason`, next to the hand-written `needs`. The hand-written fields stay: name, type, regions, needs, wave. A row without a document keeps the values it has.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

const ESTASOFT_MAX_STEPS       = 6;
const ESTASOFT_MAX_STEP_LENGTH = 140;

/**
 * A host of the front matter in the form the catalog keeps: trimmed and lower case. A pattern keeps its leading `*.`.
 *
 * @param mixed $host Value from the front matter.
 */
function estasoft_host_entry( mixed $host ): string {
	return strtolower( trim( (string) $host ) );
}

/**
 * Whether a host entry is a pattern (`*.example.com`: any subdomain).
 *
 * @param string $host Entry of the catalog `hosts` list.
 */
function estasoft_host_is_pattern( string $host ): bool {
	return str_starts_with( $host, '*.' );
}

/**
 * Why a host entry is not acceptable, or null. Accepted: a host name (`api.example.com`), or a pattern whose `*.` is followed by a domain of at
 * least two labels (`*.oraclecloud.com`, not `*.com`, not `a.*.com`, not `*`). No scheme, path, port or space.
 *
 * @param string $host Entry (already through estasoft_host_entry()).
 */
function estasoft_host_problem( string $host ): ?string {
	$name = estasoft_host_is_pattern( $host ) ? substr( $host, 2 ) : $host;
	if ( 1 !== preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $name ) ) {
		return "'{$host}' is not a host name or a pattern like *.example.com (no scheme, path, port or other wildcard).";
	}
	if ( estasoft_host_is_pattern( $host ) && 1 !== preg_match( '/^[^.]+\.[^.]+/', $name ) ) {
		return "'{$host}' is too wide: a pattern needs a domain of two labels after *.";
	}
	return null;
}

/**
 * Split a flow collection or a list at top-level commas.
 *
 * @param string $text Text without the outer brackets.
 * @return array<int,string>
 */
function estasoft_split_flow( string $text ): array {
	$parts = array();
	$depth = 0;
	$quote = '';
	$start = 0;
	$len   = strlen( $text );
	for ( $i = 0; $i < $len; ++$i ) {
		$char = $text[ $i ];
		if ( '' !== $quote ) {
			if ( '\\' === $char && '"' === $quote ) {
				++$i;
			} elseif ( $char === $quote ) {
				$quote = '';
			}
			continue;
		}
		if ( '"' === $char || "'" === $char ) {
			$quote = $char;
		} elseif ( '[' === $char || '{' === $char ) {
			++$depth;
		} elseif ( ']' === $char || '}' === $char ) {
			--$depth;
		} elseif ( ',' === $char && 0 === $depth ) {
			$parts[] = trim( substr( $text, $start, $i - $start ) );
			$start   = $i + 1;
		}
	}
	$last = trim( substr( $text, $start ) );
	if ( '' !== $last ) {
		$parts[] = $last;
	}
	return $parts;
}

/**
 * Remove a trailing comment (a # after a space, outside quotes and brackets).
 *
 * @param string $value Raw value.
 */
function estasoft_strip_comment( string $value ): string {
	$quote = '';
	$len   = strlen( $value );
	for ( $i = 0; $i < $len; ++$i ) {
		$char = $value[ $i ];
		if ( '' !== $quote ) {
			if ( '\\' === $char && '"' === $quote ) {
				++$i;
			} elseif ( $char === $quote ) {
				$quote = '';
			}
		} elseif ( '"' === $char || "'" === $char ) {
			$quote = $char;
		} elseif ( '#' === $char && ( 0 === $i || ' ' === $value[ $i - 1 ] || "\t" === $value[ $i - 1 ] ) ) {
			return rtrim( substr( $value, 0, $i ) );
		}
	}
	return rtrim( $value );
}

/**
 * Parse one value of the front matter (scalar, quoted text, flow list or flow map).
 *
 * @param string $raw Text after the colon.
 * @return mixed
 */
function estasoft_parse_value( string $raw ): mixed {
	$value = trim( estasoft_strip_comment( $raw ) );
	if ( '' === $value || 'null' === $value || '~' === $value ) {
		return '' === $value ? '' : null;
	}
	if ( 'true' === $value || 'false' === $value ) {
		return 'true' === $value;
	}
	if ( 1 === preg_match( '/^-?\d+$/', $value ) ) {
		return (int) $value;
	}
	$first = $value[0];
	if ( '"' === $first && str_ends_with( $value, '"' ) && strlen( $value ) >= 2 ) {
		return stripcslashes( substr( $value, 1, -1 ) );
	}
	if ( "'" === $first && str_ends_with( $value, "'" ) && strlen( $value ) >= 2 ) {
		return str_replace( "''", "'", substr( $value, 1, -1 ) );
	}
	if ( '[' === $first && str_ends_with( $value, ']' ) ) {
		return array_map( 'estasoft_parse_value', estasoft_split_flow( substr( $value, 1, -1 ) ) );
	}
	if ( '{' === $first && str_ends_with( $value, '}' ) ) {
		$map = array();
		foreach ( estasoft_split_flow( substr( $value, 1, -1 ) ) as $pair ) {
			$colon = strpos( $pair, ':' );
			if ( false !== $colon ) {
				$map[ trim( substr( $pair, 0, $colon ) ) ] = estasoft_parse_value( substr( $pair, $colon + 1 ) );
			}
		}
		return $map;
	}
	return $value;
}

/**
 * Read the front matter of a document.
 *
 * @param string $text Document text.
 * @return array<string,mixed>
 */
function estasoft_front_matter( string $text ): array {
	if ( 1 !== preg_match( '/\A---\R(.*?)\R---\R/s', $text, $match ) ) {
		return array();
	}
	$data  = array();
	$lines = preg_split( '/\R/', $match[1] );
	$key   = null;
	foreach ( (array) $lines as $line ) {
		if ( 1 === preg_match( '/^([A-Za-z_][A-Za-z0-9_]*):(.*)$/', $line, $pair ) ) {
			$key          = $pair[1];
			$data[ $key ] = estasoft_parse_value( $pair[2] );
			if ( '' === trim( estasoft_strip_comment( $pair[2] ) ) ) {
				$data[ $key ] = array(); // A block list may follow.
			}
		} elseif ( null !== $key && 1 === preg_match( '/^\s+-\s*(.*)$/', $line, $item ) ) {
			$data[ $key ][] = 1 === preg_match( '/^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $item[1], $first ) ? array( $first[1] => estasoft_parse_value( $first[2] ) ) : estasoft_parse_value( $item[1] );
		} elseif ( null !== $key && 1 === preg_match( '/^\s+([A-Za-z_]+):(.*)$/', $line, $sub ) && is_array( $data[ $key ] ) && array() !== $data[ $key ] ) {
			$last                                         = array_key_last( $data[ $key ] );
			$data[ $key ][ $last ]                        = is_array( $data[ $key ][ $last ] ) ? $data[ $key ][ $last ] : array();
			$data[ $key ][ $last ][ trim( $sub[1] ) ] = estasoft_parse_value( $sub[2] );
		}
	}
	return $data;
}

/**
 * The numbered list under the heading "How the merchant connects".
 *
 * @param string            $text     Document text.
 * @param string            $name     Document id for messages.
 * @param array<int,string> $problems Receives messages about broken rules.
 * @return array<int,string>
 */
function estasoft_steps( string $text, string $name, array &$problems ): array {
	if ( 1 !== preg_match( '/^##\s+(?:\d+\.?\s+)?How the merchant connects\s*$(.*?)(?=^##\s|\z)/ms', $text, $section ) ) {
		$problems[] = "{$name}: no section 'How the merchant connects'.";
		return array();
	}
	$steps = array();
	foreach ( (array) preg_split( '/\R/', $section[1] ) as $line ) {
		if ( 1 === preg_match( '/^\s{0,3}\d+[.)]\s+(.*\S)\s*$/', $line, $item ) ) {
			$steps[] = trim( $item[1] );
		} elseif ( array() !== $steps && 1 === preg_match( '/^\s{2,}(\S.*)$/', $line, $more ) && 1 !== preg_match( '/^\s*[-*]\s/', $line ) ) {
			$steps[ count( $steps ) - 1 ] .= ' ' . trim( $more[1] );
		}
	}
	if ( array() === $steps ) {
		$problems[] = "{$name}: 'How the merchant connects' has no numbered list.";
	}
	if ( count( $steps ) > ESTASOFT_MAX_STEPS ) {
		$problems[] = "{$name}: " . count( $steps ) . ' steps; the catalog keeps the first ' . ESTASOFT_MAX_STEPS . '.';
		$steps      = array_slice( $steps, 0, ESTASOFT_MAX_STEPS );
	}
	foreach ( $steps as $index => $step ) {
		if ( mb_strlen( $step ) > ESTASOFT_MAX_STEP_LENGTH ) {
			$problems[] = "{$name}: step " . ( $index + 1 ) . ' has ' . mb_strlen( $step ) . ' characters (the limit is ' . ESTASOFT_MAX_STEP_LENGTH . ').';
		}
	}
	return $steps;
}

/**
 * Write a PHP value in the style of the catalog file.
 *
 * @param mixed $value  Value.
 * @param int   $indent Tabs of the line the value starts on.
 */
function estasoft_export( mixed $value, int $indent ): string {
	if ( null === $value ) {
		return 'null';
	}
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	if ( is_int( $value ) ) {
		return (string) $value;
	}
	if ( is_string( $value ) ) {
		return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";
	}
	$value = (array) $value;
	if ( array() === $value ) {
		return 'array()';
	}
	$pad  = str_repeat( "\t", $indent + 1 );
	$end  = str_repeat( "\t", $indent );
	$list = array_is_list( $value );
	if ( $list && array() === array_filter( $value, 'is_array' ) ) {
		$items = array_map( static fn( $item ): string => estasoft_export( $item, $indent ), $value );
		$one   = 'array( ' . implode( ', ', $items ) . ' )';
		if ( strlen( $one ) <= 90 ) {
			return $one;
		}
	}
	$width = 0;
	if ( ! $list ) {
		foreach ( array_keys( $value ) as $key ) {
			$width = max( $width, strlen( "'" . $key . "'" ) );
		}
	}
	$out = "array(\n";
	foreach ( $value as $key => $item ) {
		$out .= $pad . ( $list ? '' : str_pad( "'" . $key . "'", $width ) . ' => ' ) . estasoft_export( $item, $indent + 1 ) . ",\n";
	}
	return $out . $end . ')';
}

/**
 * Build the catalog and the connector index from the documents of a plugin root.
 *
 * @param string            $root     Plugin root.
 * @param array<int,string> $problems Receives messages about broken rules and unknown rows.
 * @return array{catalog:string,readme:string}
 */
function estasoft_catalog_from_docs( string $root, array &$problems ): array {
	$problems = array();
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/estasoft-sync-abspath/' );
	}
	$rows = require $root . '/includes/Catalog/catalog.php';
	$docs = array();
	$files = glob( $root . '/docs/connectors/*.md' );
	sort( $files );
	foreach ( (array) $files as $file ) {
		$text   = (string) file_get_contents( $file );
		$front  = estasoft_front_matter( $text );
		$name   = basename( $file, '.md' );
		if ( in_array( $name, array( 'README', 'BUILDING' ), true ) || ! isset( $front['id'], $front['catalog_row'] ) ) {
			if ( ! in_array( $name, array( 'README', 'BUILDING' ), true ) ) {
				$problems[] = "{$name}: front matter has no id or catalog_row.";
			}
			continue;
		}
		$front['_steps'] = estasoft_steps( $text, $name, $problems );
		$docs[]          = $front;
	}

	$known = array_column( $rows, 'name' );
	foreach ( $docs as $doc ) {
		if ( ! in_array( $doc['catalog_row'], $known, true ) ) {
			$problems[] = "{$doc['id']}: catalog_row '{$doc['catalog_row']}' is not a row of the catalog.";
		}
		foreach ( (array) ( $doc['hosts'] ?? array() ) as $host ) {
			$why = estasoft_host_problem( estasoft_host_entry( $host ) );
			if ( null !== $why ) {
				$problems[] = "{$doc['id']}: hosts: {$why}";
			}
		}
		if ( array_key_exists( 'available', $doc ) && false === $doc['available'] && '' === trim( (string) ( $doc['unavailable_reason'] ?? '' ) ) ) {
			$problems[] = "{$doc['id']}: available is false but there is no unavailable_reason (say why the system cannot be chosen yet).";
		}
	}

	$out = array();
	foreach ( $rows as $row ) {
		$mine = array_values( array_filter( $docs, static fn( array $doc ): bool => $doc['catalog_row'] === $row['name'] ) );
		if ( array() === $mine ) {
			$out[] = estasoft_row( $row, array() );
			continue;
		}
		$out[] = estasoft_row( $row, $mine );
	}

	$lines = array();
	foreach ( $out as $row ) {
		$lines[] = "\t" . estasoft_export( $row, 1 ) . ',';
	}
	$header = <<<'PHP'
<?php
/**
 * The systems on the Choose a system screen (static data, never fetched from the network).
 *
 * The hand-written fields are name, type, regions, needs and wave. The connector fields (connector, connection_method, available, selectable, unavailable_reason,
 * verified, variants, hosts, terms_url, privacy_url, developer_url, steps) are written by bin/generate-catalog-from-docs.php from docs/connectors/*.md: do
 * not edit them here. A host that starts with `*.` is a pattern (any subdomain). `connector` is the id of the connector (a row that holds several products names the first one and lists them all in `variants`).
 * `connection_method` is one of key, own_app, cloud_app, server_login, export_file, none, unknown (docs/spec/connection-methods-spec.md).
 * Filter: estasoft_sync_catalog.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return array(

PHP;
	$catalog = $header . implode( "\n", $lines ) . "\n);\n";

	return array(
		'catalog' => $catalog,
		'readme'  => estasoft_readme( $docs ),
	);
}

/**
 * One catalog row with the connector fields taken from its documents.
 *
 * @param array<string,mixed>             $row  Existing row.
 * @param array<int,array<string,mixed>> $docs Documents of the row.
 * @return array<string,mixed>
 */
function estasoft_row( array $row, array $docs ): array {
	$base = array(
		'name'              => (string) $row['name'],
		'type'              => (string) $row['type'],
		'regions'           => (string) $row['regions'],
		'connector'         => $row['connector'] ?? null,
		'connection_method' => (string) ( $row['connection_method'] ?? 'unknown' ),
		'needs'             => (string) ( $row['needs'] ?? '' ),
		'available'         => false,
		'selectable'        => true,
		'verified'          => false,
		'variants'          => array(),
		'hosts'             => array(),
		'terms_url'         => '',
		'privacy_url'       => '',
		'developer_url'     => '',
		'steps'             => array(),
		'wave'              => (int) ( $row['wave'] ?? 0 ),
	);
	if ( array() === $docs ) {
		foreach ( array( 'available', 'selectable', 'verified', 'variants', 'hosts', 'terms_url', 'privacy_url', 'developer_url', 'steps' ) as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$base[ $key ] = $row[ $key ];
			}
		}
		return estasoft_with_reason( $base, (string) ( $row['unavailable_reason'] ?? '' ) );
	}
	$primary = $docs[0];
	$labels  = array();
	foreach ( $docs as $doc ) {
		foreach ( (array) ( $doc['variants'] ?? array() ) as $variant ) {
			if ( is_array( $variant ) && isset( $variant['id'] ) ) {
				$labels[ (string) $variant['id'] ] = (string) ( $variant['label'] ?? $variant['id'] );
			}
		}
	}
	$multi = count( $docs ) > 1;
	if ( $multi ) {
		// The primary document is the one that lists the most variants; when equal, the first by id.
		usort(
			$docs,
			static fn( array $a, array $b ): int => count( (array) ( $b['variants'] ?? array() ) ) <=> count( (array) ( $a['variants'] ?? array() ) ) ?: strcmp( (string) $a['id'], (string) $b['id'] )
		);
		$primary = $docs[0];
	}
	$base['connector']         = (string) $primary['id'];
	$base['connection_method'] = (string) ( $primary['connection_method'] ?? $base['connection_method'] );
	$base['available']         = ! array_key_exists( 'available', $primary ) || false !== $primary['available'];
	$base['selectable']        = $base['available'];
	$reason                    = trim( (string) ( $primary['unavailable_reason'] ?? '' ) );
	$base['verified']          = ! empty( $primary['verified'] );
	$base['terms_url']         = (string) ( $primary['terms_url'] ?? '' );
	$base['privacy_url']       = (string) ( $primary['privacy_url'] ?? '' );
	$base['developer_url']     = (string) ( $primary['developer_url'] ?? '' );
	$base['steps']             = $primary['_steps'];
	$hosts                     = array();
	foreach ( $docs as $doc ) {
		foreach ( (array) ( $doc['hosts'] ?? array() ) as $host ) {
			$hosts[] = estasoft_host_entry( $host );
		}
	}
	$base['hosts'] = array_values( array_unique( $hosts ) );
	if ( $multi ) {
		$variants = array();
		foreach ( $docs as $doc ) {
			$variant = array(
				'id'                => (string) $doc['id'],
				'label'             => $labels[ (string) $doc['id'] ] ?? (string) ( $doc['name'] ?? $doc['id'] ),
				'connection_method' => (string) ( $doc['connection_method'] ?? 'unknown' ),
				'hosts'             => array_values( array_map( 'estasoft_host_entry', (array) ( $doc['hosts'] ?? array() ) ) ),
				'terms_url'         => (string) ( $doc['terms_url'] ?? '' ),
				'privacy_url'       => (string) ( $doc['privacy_url'] ?? '' ),
				'developer_url'     => (string) ( $doc['developer_url'] ?? '' ),
				'steps'             => $doc['_steps'],
			);
			$variants[ (string) $doc['id'] ] = estasoft_with_reason( $variant, trim( (string) ( $doc['unavailable_reason'] ?? '' ) ) );
		}
		// Keep the order in which the primary document lists its variants, then the rest by id.
		$ordered = array();
		foreach ( array_keys( $labels ) as $id ) {
			if ( isset( $variants[ $id ] ) ) {
				$ordered[] = $variants[ $id ];
				unset( $variants[ $id ] );
			}
		}
		ksort( $variants );
		$base['variants'] = array_merge( $ordered, array_values( $variants ) );
		$base['connector'] = (string) $base['variants'][0]['id'];
	}
	return estasoft_with_reason( $base, $reason );
}

/**
 * Add `unavailable_reason` right after the keys it belongs with, only when there is one.
 *
 * @param array<string,mixed> $row    Row or variant.
 * @param string              $reason Text, or an empty string.
 * @return array<string,mixed>
 */
function estasoft_with_reason( array $row, string $reason ): array {
	unset( $row['unavailable_reason'] );
	if ( '' === $reason ) {
		return $row;
	}
	$out = array();
	foreach ( $row as $key => $value ) {
		$out[ $key ] = $value;
		if ( 'selectable' === $key || ( 'label' === $key && ! array_key_exists( 'selectable', $row ) ) ) {
			$out['unavailable_reason'] = $reason;
		}
	}
	if ( ! array_key_exists( 'unavailable_reason', $out ) ) {
		$out['unavailable_reason'] = $reason;
	}
	return $out;
}

/**
 * The index of the connector documents.
 *
 * @param array<int,array<string,mixed>> $docs Documents.
 */
function estasoft_readme( array $docs ): string {
	usort( $docs, static fn( array $a, array $b ): int => strcmp( (string) $a['name'], (string) $b['name'] ) );
	$text  = "# Connector documents\n\n";
	$text .= "One document per connector id. Each starts with a front matter block that the catalog is built from (`bin/generate-catalog-from-docs.php`); this index is generated by the same script. Building a new connector: [BUILDING.md](BUILDING.md).\n\n";
	$text .= "| Connector | Id | How it connects | Hosts the plugin contacts | Verified on a live account |\n|---|---|---|---|---|\n";
	$methods = array(
		'key'          => 'Key or login',
		'own_app'      => 'Your own app, sign in',
		'cloud_app'    => 'Cloud app, no sign-in page',
		'server_login' => 'Server login',
		'export_file'  => 'File you import',
		'none'         => 'None',
		'unknown'      => 'See the document',
	);
	foreach ( $docs as $doc ) {
		$hosts = array() === (array) ( $doc['hosts'] ?? array() ) ? 'The address you enter, or none' : implode( ', ', array_map( static fn( $h ): string => '`' . estasoft_host_entry( $h ) . '`', (array) $doc['hosts'] ) );
		$text .= '| [' . $doc['name'] . '](' . $doc['id'] . '.md) | `' . $doc['id'] . '` | ' . ( $methods[ (string) ( $doc['connection_method'] ?? 'unknown' ) ] ?? (string) $doc['connection_method'] ) . ' | ' . $hosts . ' | ' . ( ! empty( $doc['verified'] ) ? 'Yes' : 'No' ) . " |\n";
	}
	$text .= "\nA host that starts with `*.` is a pattern: any subdomain of it (a system that gives each customer a host of its own). A system with `available: false` cannot be chosen yet; its document says why in `unavailable_reason`.\n";
	return $text . "\nNo connector has been tried on a live vendor account yet: each document ends with the manual smoke test to run on a real account.\n";
}

if ( ! defined( 'ESTASOFT_CATALOG_LIB' ) ) {
	$root    = dirname( __DIR__ );
	$mode    = $argv[1] ?? '';
	$problem = array();
	try {
		$built = estasoft_catalog_from_docs( $root, $problem );
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'generate-catalog-from-docs: ' . $error->getMessage() . "\n" );
		exit( 1 );
	}
	foreach ( $problem as $line ) {
		fwrite( STDERR, 'generate-catalog-from-docs: warning. ' . $line . "\n" );
	}
	$targets = array(
		$root . '/includes/Catalog/catalog.php' => $built['catalog'],
		$root . '/docs/connectors/README.md'    => $built['readme'],
	);
	$changed = 0;
	foreach ( $targets as $path => $text ) {
		$current = is_file( $path ) ? (string) file_get_contents( $path ) : '';
		if ( $current === $text ) {
			continue;
		}
		++$changed;
		if ( '--check' !== $mode ) {
			file_put_contents( $path, $text );
			echo 'Wrote ' . substr( $path, strlen( $root ) + 1 ) . "\n";
		} else {
			fwrite( STDERR, substr( $path, strlen( $root ) + 1 ) . " is out of date. Run: php bin/generate-catalog-from-docs.php\n" );
		}
	}
	if ( '--check' === $mode && $changed > 0 ) {
		exit( 1 );
	}
	if ( '--strict' === $mode && array() !== $problem ) {
		exit( 1 );
	}
	if ( 0 === $changed ) {
		echo "The catalog and the connector index are up to date.\n";
	}
}
