<?php
/**
 * Documentation checks: every relative link in the Markdown files points at a file that exists, and
 * docs/spec/README.md lists every document under docs/.
 *
 *   php bin/check-docs.php
 *
 * Exit codes: 0 passed, 1 problems found.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

$root     = dirname( __DIR__ );
$problems = array();

$markdown = array();
foreach ( array( '', 'docs', '.github' ) as $base ) {
	$directory = '' === $base ? $root : $root . '/' . $base;
	if ( ! is_dir( $directory ) ) {
		continue;
	}
	$flags    = FilesystemIterator::SKIP_DOTS;
	$iterator = '' === $base
		? new IteratorIterator( new DirectoryIterator( $directory ) )
		: new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, $flags ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'md' === strtolower( $file->getExtension() ) ) {
			$markdown[] = $file->getPathname();
		}
	}
}
sort( $markdown );

foreach ( $markdown as $file ) {
	$text = (string) file_get_contents( $file );
	// Ignore fenced code blocks and inline code, which may contain example links.
	$text = (string) preg_replace( '/```.*?```/s', '', $text );
	$text = (string) preg_replace( '/`[^`\n]*`/', '', $text );
	if ( 1 > preg_match_all( '/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $text, $matches ) ) {
		continue;
	}
	foreach ( $matches[1] as $target ) {
		if ( 1 === preg_match( '#^(https?:|mailto:|\#|tel:)#i', $target ) ) {
			continue;
		}
		$path = (string) preg_replace( '/[#?].*$/', '', $target );
		if ( '' === $path ) {
			continue;
		}
		$resolved = 0 === strpos( $path, '/' ) ? $root . $path : dirname( $file ) . '/' . $path;
		if ( ! file_exists( $resolved ) ) {
			$problems[] = substr( $file, strlen( $root ) + 1 ) . ": broken link to {$target}";
		}
	}
}

// docs/spec/README.md must mention every document in docs/.
$index = $root . '/docs/spec/README.md';
if ( ! is_readable( $index ) ) {
	$problems[] = 'docs/spec/README.md is missing.';
} else {
	$index_text = (string) file_get_contents( $index );
	$docs       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/docs', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $docs as $file ) {
		if ( ! $file->isFile() || 'md' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$relative = substr( $file->getPathname(), strlen( $root . '/docs/' ) );
		if ( 'spec/README.md' === $relative ) {
			continue;
		}
		// One document per connector: docs/connectors/README.md (generated from the documents' front matter) lists them,
		// and docs/spec/README.md lists that index.
		if ( 0 === strpos( $relative, 'connectors/' ) && 'connectors/README.md' !== $relative ) {
			$connectors_index = (string) file_get_contents( $root . '/docs/connectors/README.md' );
			if ( false === strpos( $connectors_index, basename( $relative ) ) ) {
				$problems[] = "docs/{$relative} is not listed in docs/connectors/README.md (run php bin/generate-catalog-from-docs.php).";
			}
			continue;
		}
		if ( false === strpos( $index_text, $relative ) && false === strpos( $index_text, basename( $relative ) ) ) {
			$problems[] = "docs/{$relative} is not listed in docs/spec/README.md.";
		}
	}
}

foreach ( $problems as $problem ) {
	fwrite( STDERR, "FAIL {$problem}\n" );
}
if ( array() !== $problems ) {
	exit( 1 );
}
echo sprintf( "ok   %d Markdown files: relative links resolve, docs/spec/README.md lists every document in docs/.\n", count( $markdown ) );
exit( 0 );
