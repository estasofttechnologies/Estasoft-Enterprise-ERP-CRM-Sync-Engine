<?php
/**
 * Scans the plugin's runtime PHP (main file, uninstall.php, includes/) for constructs that WordPress.org plugin review
 * rejects. It reads PHP tokens, so text inside strings and comments never causes a hit.
 *
 *   php bin/red-flags.php [--root=/path/to/plugin]
 *
 * Flags: eval, base64_decode, extract, error_reporting, ini_set, shell execution, the @ operator, die and exit (except the
 * `defined( 'ABSPATH' ) || exit;` guard), create_function, includes of remote files, and outbound HTTP outside the one class
 * that talks to the network (includes/Core/Support/WpHttpClient.php). A legitimate use is listed as `path:label` in
 * bin/red-flags-allowlist.txt, with a comment saying why.
 *
 * Exit status: 0 nothing found, 1 something found.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--root=' ) ) {
		$root = rtrim( substr( $arg, 7 ), '/' );
	}
}

$function_flags = array(
	'base64_decode'    => 'base64_decode',
	'extract'          => 'extract',
	'error_reporting'  => 'error_reporting',
	'ini_set'          => 'ini_set',
	'create_function'  => 'create_function',
	'exec'             => 'shell execution',
	'shell_exec'       => 'shell execution',
	'system'           => 'shell execution',
	'passthru'         => 'shell execution',
	'popen'            => 'shell execution',
	'proc_open'        => 'shell execution',
	'wp_remote_request' => 'outbound HTTP outside WpHttpClient',
	'wp_remote_get'    => 'outbound HTTP outside WpHttpClient',
	'wp_remote_post'   => 'outbound HTTP outside WpHttpClient',
	'wp_remote_head'   => 'outbound HTTP outside WpHttpClient',
	'wp_safe_remote_request' => 'outbound HTTP outside WpHttpClient',
	'wp_safe_remote_get'     => 'outbound HTTP outside WpHttpClient',
	'wp_safe_remote_post'    => 'outbound HTTP outside WpHttpClient',
	'wp_safe_remote_head'    => 'outbound HTTP outside WpHttpClient',
	'curl_init'        => 'outbound HTTP outside WpHttpClient',
	'curl_exec'        => 'outbound HTTP outside WpHttpClient',
	'curl_multi_exec'  => 'outbound HTTP outside WpHttpClient',
	'fsockopen'        => 'outbound HTTP outside WpHttpClient',
	'stream_socket_client' => 'outbound HTTP outside WpHttpClient',
);

$allow = array();
$allow_file = __DIR__ . '/red-flags-allowlist.txt';
if ( is_file( $allow_file ) ) {
	foreach ( (array) file( $allow_file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line && '#' !== $line[0] ) {
			$allow[ $line ] = true;
		}
	}
}

$files = array();
foreach ( array( 'estasoft-erp-crm-sync-engine.php', 'uninstall.php' ) as $name ) {
	if ( is_file( "$root/$name" ) ) {
		$files[] = "$root/$name";
	}
}
if ( is_dir( "$root/includes" ) ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/includes", FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}
sort( $files );

$hits = 0;

$report = static function ( string $label, string $relative, int $line, string $text ) use ( &$hits, $allow ): void {
	if ( isset( $allow[ "$relative:$label" ] ) ) {
		return;
	}
	++$hits;
	fwrite( STDOUT, sprintf( "FAIL %s: %s:%d: %s\n", $label, $relative, $line, trim( $text ) ) );
};

foreach ( $files as $path ) {
	$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
	$source   = (string) file_get_contents( $path );
	$lines    = explode( "\n", $source );
	try {
		$tokens = token_get_all( $source, TOKEN_PARSE );
	} catch ( ParseError $e ) {
		fwrite( STDOUT, sprintf( "FAIL parse error: %s: %s\n", $relative, $e->getMessage() ) );
		++$hits;
		continue;
	}
	$count = count( $tokens );
	// Index of the previous and next significant token (not whitespace or comment).
	$significant = static function ( int $from, int $step ) use ( $tokens, $count ): ?int {
		for ( $i = $from + $step; $i >= 0 && $i < $count; $i += $step ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $i;
		}
		return null;
	};
	for ( $i = 0; $i < $count; ++$i ) {
		$token = $tokens[ $i ];
		$line  = is_array( $token ) ? $token[2] : 0;
		$text  = $lines[ max( 0, $line - 1 ) ] ?? '';
		if ( '@' === $token ) {
			// The line is not known for a single character token; find it from the nearest earlier array token.
			for ( $j = $i; $j >= 0; --$j ) {
				if ( is_array( $tokens[ $j ] ) ) {
					$line = $tokens[ $j ][2];
					break;
				}
			}
			$report( 'error suppression', $relative, $line, $lines[ max( 0, $line - 1 ) ] ?? '' );
			continue;
		}
		if ( ! is_array( $token ) ) {
			continue;
		}
		if ( T_EVAL === $token[0] ) {
			$report( 'eval', $relative, $line, $text );
			continue;
		}
		if ( T_EXIT === $token[0] ) {
			if ( ! preg_match( "/defined\\(\\s*'(ABSPATH|WP_UNINSTALL_PLUGIN)'\\s*\\)\\s*\\|\\|\\s*(exit|die)\\s*;/", $text ) ) {
				$report( 'die or exit', $relative, $line, $text );
			}
			continue;
		}
		if ( in_array( $token[0], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
			$next = $significant( $i, 1 );
			if ( null !== $next && '(' === $tokens[ $next ] ) {
				$next = $significant( $next, 1 );
			}
			if ( null !== $next && is_array( $tokens[ $next ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $next ][0] && preg_match( '#^[\'"]https?://#i', $tokens[ $next ][1] ) ) {
				$report( 'remote include', $relative, $line, $text );
			}
			continue;
		}
		if ( T_NEW === $token[0] ) {
			$next = $significant( $i, 1 );
			if ( null !== $next && is_array( $tokens[ $next ] ) && preg_match( '/^\\\\?(WP_Http|SoapClient)$/', $tokens[ $next ][1] ) ) {
				$report( 'outbound HTTP outside WpHttpClient', $relative, $line, $text );
			}
			continue;
		}
		if ( ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
			continue;
		}
		$name = ltrim( $token[1], '\\' );
		$next = $significant( $i, 1 );
		$prev = $significant( $i, -1 );
		if ( null === $next || '(' !== $tokens[ $next ] ) {
			continue;
		}
		if ( null !== $prev && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
			continue;
		}
		if ( 'file_get_contents' === $name ) {
			$arg = $significant( $next, 1 );
			if ( null !== $arg && is_array( $tokens[ $arg ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $arg ][0] && preg_match( '#^[\'"]https?://#i', $tokens[ $arg ][1] ) ) {
				$report( 'outbound HTTP outside WpHttpClient', $relative, $line, $text );
			}
			continue;
		}
		if ( isset( $function_flags[ $name ] ) ) {
			$report( $function_flags[ $name ], $relative, $line, $text );
		}
	}
}

if ( 0 === $hits ) {
	fwrite( STDOUT, sprintf( "ok   Red-flag scan: %d runtime files, none of eval, base64_decode, extract, error_reporting, ini_set, shell calls, @, stray exit, remote include, outbound HTTP outside WpHttpClient.\n", count( $files ) ) );
	exit( 0 );
}
exit( 1 );
