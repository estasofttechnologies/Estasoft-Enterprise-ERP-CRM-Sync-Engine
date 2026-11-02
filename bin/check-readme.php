<?php
/**
 * Checks readme.txt against the WordPress.org readme rules and this project's own rules.
 *
 * The official validator (https://wordpress.org/plugins/developers/readme-validator/) is a web page, so this
 * script cannot replace it; it catches the mistakes that page would report, offline and in CI. Run the web
 * validator once before submission as well (docs/release/CHECKLIST.md).
 *
 *   php bin/check-readme.php [path/to/readme.txt] [--strict-assets]
 *
 * Exit codes: 0 passed (warnings allowed), 1 errors found, 2 usage problem.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

const ESTASOFT_README_CONTRIBUTORS = array( 'estasofttechnologies' );
const ESTASOFT_README_REQUIRED     = array(
	'Contributors',
	'Tags',
	'Requires at least',
	'Tested up to',
	'Requires PHP',
	'Requires Plugins',
	'Stable tag',
	'License',
	'License URI',
);
const ESTASOFT_README_SECTIONS     = array(
	'Description',
	'External services',
	'Third-party libraries and services',
	'Installation',
	'Frequently Asked Questions',
	'Screenshots',
	'Changelog',
	'Upgrade Notice',
);

$args          = array_slice( $argv, 1 );
$strict_assets = in_array( '--strict-assets', $args, true );
$args          = array_values( array_diff( $args, array( '--strict-assets' ) ) );
$root          = dirname( __DIR__ );
$path          = $args[0] ?? $root . '/readme.txt';

if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "Cannot read {$path}\n" );
	exit( 2 );
}

$errors   = array();
$warnings = array();
$raw      = (string) file_get_contents( $path );
$lines    = preg_split( '/\R/', $raw );
$lines    = false === $lines ? array() : $lines;

if ( false !== strpos( $raw, "\r" ) ) {
	$errors[] = 'The file has Windows line endings; use LF.';
}
if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
	$errors[] = 'The file is not valid UTF-8.';
}
if ( strlen( $raw ) > 10000 ) {
	$warnings[] = sprintf( 'The file is %d bytes. The readme handbook warns that a readme over 10 KB may cause errors; move detail to the GitHub documentation.', strlen( $raw ) );
}

// Title line.
$name = '';
if ( isset( $lines[0] ) && 1 === preg_match( '/^=== (.+) ===$/', $lines[0], $m ) ) {
	$name = $m[1];
} else {
	$errors[] = 'Line 1 must be "=== Plugin Name ===".';
}

// Header fields: the block of "Key: value" lines after the title.
$headers = array();
$index   = 1;
while ( isset( $lines[ $index ] ) && '' === trim( $lines[ $index ] ) ) {
	++$index;
}
while ( isset( $lines[ $index ] ) && 1 === preg_match( '/^([A-Za-z ]+):\s*(.*)$/', $lines[ $index ], $m ) ) {
	$headers[ $m[1] ] = trim( $m[2] );
	++$index;
}
foreach ( ESTASOFT_README_REQUIRED as $key ) {
	if ( ! isset( $headers[ $key ] ) || '' === $headers[ $key ] ) {
		$errors[] = "Missing header field: {$key}.";
	}
}

// Short description: first paragraph after the headers.
while ( isset( $lines[ $index ] ) && '' === trim( $lines[ $index ] ) ) {
	++$index;
}
$short = '';
while ( isset( $lines[ $index ] ) && '' !== trim( $lines[ $index ] ) && 0 !== strpos( $lines[ $index ], '==' ) ) {
	$short .= ( '' === $short ? '' : ' ' ) . trim( $lines[ $index ] );
	++$index;
}
if ( '' === $short ) {
	$errors[] = 'No short description after the header fields.';
} else {
	$short_length = mb_strlen( $short );
	if ( $short_length > 150 ) {
		$errors[] = "The short description is {$short_length} characters; the limit is 150.";
	}
	if ( 1 === preg_match( '/[<>*`\[\]_]/', $short ) ) {
		$errors[] = 'The short description must not contain markup.';
	}
}

// Header rules.
if ( isset( $headers['Contributors'] ) ) {
	$contributors = array_values( array_filter( array_map( 'trim', explode( ',', $headers['Contributors'] ) ) ) );
	if ( $contributors !== ESTASOFT_README_CONTRIBUTORS ) {
		$errors[] = 'Contributors must be exactly: ' . implode( ', ', ESTASOFT_README_CONTRIBUTORS ) . '.';
	}
}
if ( isset( $headers['Tags'] ) ) {
	$tags = array_values( array_filter( array_map( 'trim', explode( ',', $headers['Tags'] ) ) ) );
	if ( count( $tags ) < 1 || count( $tags ) > 5 ) {
		$errors[] = 'Tags: use between 1 and 5 (found ' . count( $tags ) . ').';
	}
	if ( count( array_unique( array_map( 'strtolower', $tags ) ) ) !== count( $tags ) ) {
		$errors[] = 'Tags: duplicates found.';
	}
}
if ( isset( $headers['Tested up to'] ) && 1 !== preg_match( '/^\d+\.\d+(\.\d+)?$/', $headers['Tested up to'] ) ) {
	$errors[] = 'Tested up to must be a version number such as 7.1 (no "WP" prefix).';
}
if ( isset( $headers['Stable tag'] ) && 1 !== preg_match( '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $headers['Stable tag'] ) ) {
	$errors[] = 'Stable tag must be x.y.z (never "trunk").';
}
if ( isset( $headers['License'] ) && 1 !== preg_match( '/^(GPL ?v?2|GPL-2\.0)( or later|\+|-or-later)?$/i', $headers['License'] ) ) {
	$errors[] = 'License must be GPLv2 or later (or a GPL-compatible licence).';
}
if ( isset( $headers['License URI'] ) && 1 !== preg_match( '#^https://#', $headers['License URI'] ) ) {
	$errors[] = 'License URI must be an https address.';
}
if ( isset( $headers['Requires Plugins'] ) && 'woocommerce' !== $headers['Requires Plugins'] ) {
	$errors[] = 'Requires Plugins must be exactly "woocommerce" (the slug of the required plugin).';
}

// The main plugin file is the source of truth for several fields.
$plugin_file = $root . '/estasoft-erp-crm-sync-engine.php';
if ( is_readable( $plugin_file ) ) {
	$header_block = (string) file_get_contents( $plugin_file, false, null, 0, 4096 );
	$pairs        = array(
		'Stable tag'        => 'Version',
		'Requires at least' => 'Requires at least',
		'Requires PHP'      => 'Requires PHP',
		'Requires Plugins'  => 'Requires Plugins',
	);
	foreach ( $pairs as $readme_key => $plugin_key ) {
		if ( 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $plugin_key, '/' ) . ':[ \t]*(.+)$/mi', $header_block, $pm ) ) {
			$plugin_value = trim( $pm[1] );
			if ( isset( $headers[ $readme_key ] ) && $headers[ $readme_key ] !== $plugin_value ) {
				$errors[] = "{$readme_key} is '{$headers[ $readme_key ]}' in readme.txt but '{$plugin_value}' ({$plugin_key}) in the plugin header.";
			}
		} else {
			$errors[] = "The plugin header has no '{$plugin_key}' line.";
		}
	}
	if ( '' !== $name && 1 === preg_match( '/^[ \t\/*#@]*Plugin Name:[ \t]*(.+)$/mi', $header_block, $pm ) && trim( $pm[1] ) !== $name ) {
		$errors[] = "The readme name '{$name}' differs from the plugin header name '" . trim( $pm[1] ) . "'.";
	}
}

// Sections.
$sections = array();
$current  = '';
foreach ( $lines as $line ) {
	if ( 1 === preg_match( '/^== (.+) ==\s*$/', $line, $m ) ) {
		$current              = $m[1];
		$sections[ $current ] = array();
		continue;
	}
	if ( '' !== $current ) {
		$sections[ $current ][] = $line;
	}
}
foreach ( ESTASOFT_README_SECTIONS as $required ) {
	if ( ! isset( $sections[ $required ] ) ) {
		$errors[] = "Missing section: == {$required} ==.";
	} elseif ( '' === trim( implode( '', $sections[ $required ] ) ) ) {
		$errors[] = "Section is empty: == {$required} ==.";
	}
}
$order   = array_keys( $sections );
$known   = array_values( array_intersect( ESTASOFT_README_SECTIONS, $order ) );
$in_file = array_values( array_intersect( $order, ESTASOFT_README_SECTIONS ) );
if ( $known !== $in_file ) {
	$warnings[] = 'Sections are not in the usual order (' . implode( ', ', ESTASOFT_README_SECTIONS ) . ').';
}

// Changelog must describe the stable tag.
if ( isset( $sections['Changelog'], $headers['Stable tag'] ) ) {
	$changelog = implode( "\n", $sections['Changelog'] );
	if ( false === strpos( $changelog, '= ' . $headers['Stable tag'] . ' =' ) ) {
		$errors[] = "The Changelog has no entry '= {$headers['Stable tag']} ='.";
	}
}

// Upgrade Notice: 300 characters per entry at most, and an entry for the stable tag.
if ( isset( $sections['Upgrade Notice'] ) ) {
	$notice_text = implode( "\n", $sections['Upgrade Notice'] );
	$parts       = preg_split( '/^= (.+) =\s*$/m', $notice_text, -1, PREG_SPLIT_DELIM_CAPTURE );
	$parts       = false === $parts ? array() : $parts;
	$count       = 0;
	for ( $i = 1; $i < count( $parts ); $i += 2 ) {
		++$count;
		$body = trim( $parts[ $i + 1 ] ?? '' );
		if ( mb_strlen( $body ) > 300 ) {
			$errors[] = "Upgrade Notice for {$parts[ $i ]} is " . mb_strlen( $body ) . ' characters; the limit is 300.';
		}
		if ( '' === $body ) {
			$errors[] = "Upgrade Notice for {$parts[ $i ]} is empty.";
		}
	}
	if ( 0 === $count ) {
		$errors[] = 'Upgrade Notice has no "= x.y.z =" entry.';
	}
	if ( isset( $headers['Stable tag'] ) && false === strpos( $notice_text, '= ' . $headers['Stable tag'] . ' =' ) ) {
		$errors[] = "Upgrade Notice has no entry for {$headers['Stable tag']}.";
	}
}

// Screenshots: numbered list; the images live in .wordpress-org/ and may still be pending.
if ( isset( $sections['Screenshots'] ) ) {
	$shots = array();
	foreach ( $sections['Screenshots'] as $line ) {
		if ( 1 === preg_match( '/^(\d+)\.\s+(\S.*)$/', $line, $m ) ) {
			$shots[ (int) $m[1] ] = $m[2];
		}
	}
	$expected = range( 1, max( 1, count( $shots ) ) );
	if ( array_keys( $shots ) !== $expected ) {
		$errors[] = 'Screenshots must be a numbered list 1, 2, 3 ... without gaps.';
	}
	foreach ( array_keys( $shots ) as $number ) {
		$found = glob( $root . '/.wordpress-org/screenshot-' . $number . '.{png,jpg,jpeg,gif}', GLOB_BRACE );
		if ( empty( $found ) ) {
			$message = "Screenshot {$number} has a caption but .wordpress-org/screenshot-{$number}.png does not exist yet (pending, see docs/release/ASSETS.md).";
			if ( $strict_assets ) {
				$errors[] = $message;
			} else {
				$warnings[] = $message;
			}
		}
	}
}

// External services section must say what is sent, when and link to terms and privacy.
if ( isset( $sections['External services'] ) ) {
	$ext = strtolower( implode( "\n", $sections['External services'] ) );
	foreach ( array( 'terms', 'privacy', 'https://' ) as $needle ) {
		if ( false === strpos( $ext, $needle ) ) {
			$errors[] = "External services must mention '{$needle}'.";
		}
	}
	if ( false === strpos( $ext, 'telemetry' ) ) {
		$warnings[] = 'External services should state that the plugin has no telemetry.';
	}
}

// Wording rules from the project brief: no superlatives, no performance numbers, no placeholders.
$flat = $raw;
if ( 1 === preg_match( '/\b(best|fastest|#1|number one|guarantee[sd]?|blazing|lightning)\b/i', $flat, $m ) ) {
	$errors[] = "Marketing wording is not allowed (found '{$m[1]}').";
}
if ( 1 === preg_match( '/\b\d+(\.\d+)?\s?(x|times)\s+(faster|quicker)\b|\b\d+\s?%\s+(faster|quicker|less)\b/i', $flat, $m ) ) {
	$errors[] = "Performance numbers are not allowed (found '{$m[0]}').";
}
if ( 1 === preg_match( '/\b(TODO|TBD|FIXME|lorem ipsum|Describe the changes in this release)\b/i', $flat, $m ) ) {
	$errors[] = "Placeholder text found: '{$m[1]}'.";
}
if ( 1 === preg_match( '/\bhttp:\/\/(?!localhost|127\.0\.0\.1)/i', $flat ) ) {
	$warnings[] = 'A plain http:// link was found; use https:// unless the address has no secure form.';
}

// Report.
foreach ( $warnings as $warning ) {
	fwrite( STDERR, "warn {$warning}\n" );
}
foreach ( $errors as $error ) {
	fwrite( STDERR, "FAIL {$error}\n" );
}
if ( array() !== $errors ) {
	fwrite( STDERR, sprintf( "readme.txt: %d error(s), %d warning(s).\n", count( $errors ), count( $warnings ) ) );
	exit( 1 );
}
echo sprintf( "ok   readme.txt follows the WordPress.org rules (%d warning(s), %d bytes, short description %d characters).\n", count( $warnings ), strlen( $raw ), mb_strlen( $short ) );
exit( 0 );
