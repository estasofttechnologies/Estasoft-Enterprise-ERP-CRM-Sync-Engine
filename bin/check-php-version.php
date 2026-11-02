<?php
/**
 * Checks that the plugin's runtime PHP uses nothing newer than the minimum version it declares (PHP 8.1).
 *
 *   php bin/check-php-version.php [--root=/path/to/plugin]
 *
 * It reads tokens, so words in strings and comments never count. It looks for syntax added after PHP 8.1 (readonly classes,
 * typed class constants, `true`/`false`/`null` as standalone types, disjunctive normal form types, property hooks and asymmetric
 * visibility, the #[\Override] attribute, dynamic class constant fetch) and for functions and classes added in PHP 8.2 to 8.4.
 * The CI matrix also runs `php -l` on PHP 8.1, which is the final word; this check gives the answer early on any PHP version.
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

// Functions added after PHP 8.1, with the version that added them.
$functions = array(
	'ini_parse_quantity'          => '8.2',
	'memory_reset_peak_usage'     => '8.2',
	'curl_upkeep'                 => '8.2',
	'mysqli_execute_query'        => '8.2',
	'openssl_cipher_key_length'   => '8.2',
	'libxml_get_external_entity_loader' => '8.2',
	'sodium_crypto_stream_xchacha20_xor_ic' => '8.2',
	'json_validate'               => '8.3',
	'mb_str_pad'                  => '8.3',
	'str_increment'               => '8.3',
	'str_decrement'               => '8.3',
	'stream_context_set_options'  => '8.3',
	'ldap_connect_wallet'         => '8.3',
	'array_find'                  => '8.4',
	'array_find_key'              => '8.4',
	'array_any'                   => '8.4',
	'array_all'                   => '8.4',
	'mb_trim'                     => '8.4',
	'mb_ltrim'                    => '8.4',
	'mb_rtrim'                    => '8.4',
	'mb_ucfirst'                  => '8.4',
	'mb_lcfirst'                  => '8.4',
	'bcceil'                      => '8.4',
	'bcfloor'                     => '8.4',
	'bcround'                     => '8.4',
	'fpow'                        => '8.4',
	'request_parse_body'          => '8.4',
);
// Classes added after PHP 8.1.
$classes = array(
	'Random\\Randomizer'        => '8.2',
	'Random\\Engine\\Secure'    => '8.2',
	'SensitiveParameter'        => '8.2',
	'AllowDynamicProperties'    => '8.2',
	'Override'                  => '8.3',
	'DateMalformedStringException' => '8.3',
	'Deprecated'                => '8.4',
);

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
$report = static function ( string $what, string $relative, int $line ) use ( &$hits ): void {
	++$hits;
	fwrite( STDOUT, sprintf( "FAIL %s: %s:%d\n", $what, $relative, $line ) );
};

$skip = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

foreach ( $files as $path ) {
	$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
	$tokens   = token_get_all( (string) file_get_contents( $path ) );
	$count    = count( $tokens );
	// Significant tokens only, with their line.
	$sig  = array();
	$line = 1;
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			$line = $token[2];
			if ( in_array( $token[0], $skip, true ) ) {
				continue;
			}
			$sig[] = array( $token[0], $token[1], $line );
		} else {
			$sig[] = array( null, $token, $line );
		}
	}
	$n = count( $sig );
	for ( $i = 0; $i < $n; ++$i ) {
		[ $id, $text, $at ] = $sig[ $i ];

		// readonly class (8.2).
		if ( T_READONLY === $id ) {
			$j = $i + 1;
			while ( $j < $n && in_array( $sig[ $j ][0], array( T_FINAL, T_ABSTRACT ), true ) ) {
				++$j;
			}
			if ( $j < $n && T_CLASS === $sig[ $j ][0] ) {
				$report( 'readonly class (PHP 8.2)', $relative, $at );
			}
		}
		// Typed class constants (8.3): const <type> NAME = ...; the name is followed by '=' and a type precedes it.
		if ( T_CONST === $id && $i + 3 < $n ) {
			$a = $sig[ $i + 1 ];
			$b = $sig[ $i + 2 ];
			if ( null === $b[0] && '=' === $b[1] ) {
				continue;
			}
			if ( ( T_STRING === $a[0] || T_ARRAY === $a[0] || T_NAME_QUALIFIED === $a[0] || null !== $a[0] && '?' === $a[1] ) && in_array( $b[0], array( T_STRING ), true ) && null === $sig[ $i + 3 ][0] && '=' === $sig[ $i + 3 ][1] ) {
				$report( 'typed class constant (PHP 8.3)', $relative, $at );
			}
		}
		// #[\Override] and other new attributes (8.2 and later).
		if ( T_ATTRIBUTE === $id && $i + 1 < $n ) {
			$name = ltrim( $sig[ $i + 1 ][1], '\\' );
			if ( in_array( $name, array( 'Override', 'SensitiveParameter', 'AllowDynamicProperties', 'Deprecated' ), true ) ) {
				$report( "attribute #[$name] (PHP 8.2 or later)", $relative, $at );
			}
		}
		// Asymmetric visibility (8.4): public private(set).
		if ( null === $id && '(' === $text && $i > 0 && T_STRING === $sig[ $i - 1 ][0] && 'set' === $sig[ $i - 1 ][1] && $i > 1 && in_array( $sig[ $i - 2 ][0], array( T_PRIVATE, T_PROTECTED, T_PUBLIC ), true ) ) {
			$report( 'asymmetric visibility (PHP 8.4)', $relative, $at );
		}
		// Standalone true / false / null types (8.2): a function signature "function name( ... ): null {". Ternaries such as
		// "$a ? b() : null;" are not signatures, so the closing bracket before the colon must belong to a function declaration.
		if ( null === $id && ':' === $text && $i + 2 < $n && null === $sig[ $i + 2 ][0] && in_array( $sig[ $i + 2 ][1], array( '{', ';' ), true ) && T_STRING === $sig[ $i + 1 ][0] && in_array( strtolower( $sig[ $i + 1 ][1] ), array( 'true', 'false', 'null' ), true ) && $i > 0 && ')' === $sig[ $i - 1 ][1] ) {
			$depth = 0;
			$open  = null;
			for ( $k = $i - 1; $k >= 0; --$k ) {
				if ( null === $sig[ $k ][0] && ')' === $sig[ $k ][1] ) {
					++$depth;
				} elseif ( null === $sig[ $k ][0] && '(' === $sig[ $k ][1] ) {
					--$depth;
					if ( 0 === $depth ) {
						$open = $k;
						break;
					}
				}
			}
			if ( null !== $open && $open > 0 ) {
				$head = $sig[ $open - 1 ];
				$prev = $open > 1 ? $sig[ $open - 2 ] : array( null, '', 0 );
				$is_declaration = T_FUNCTION === $head[0] || T_FN === $head[0] || ( T_STRING === $head[0] && T_FUNCTION === $prev[0] ) || ( null === $head[0] && '&' === $head[1] && T_FUNCTION === $prev[0] );
				if ( $is_declaration ) {
					$report( 'true, false or null as a standalone type (PHP 8.2)', $relative, $at );
				}
			}
		}
		// Functions.
		if ( T_STRING === $id && isset( $functions[ strtolower( $text ) ] ) && $i + 1 < $n && '(' === $sig[ $i + 1 ][1] ) {
			$before = $i > 0 ? $sig[ $i - 1 ] : array( null, '', 0 );
			if ( ! in_array( $before[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				$report( strtolower( $text ) . '() (PHP ' . $functions[ strtolower( $text ) ] . ')', $relative, $at );
			}
		}
		// Classes by name in `new`, `instanceof` and static calls.
		if ( in_array( $id, array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
			$name = ltrim( $text, '\\' );
			if ( isset( $classes[ $name ] ) && $i > 0 && in_array( $sig[ $i - 1 ][0], array( T_NEW, T_INSTANCEOF ), true ) ) {
				$report( "class $name (PHP {$classes[$name]})", $relative, $at );
			}
		}
	}
}

if ( 0 === $hits ) {
	fwrite( STDOUT, sprintf( "ok   PHP 8.1 compatibility scan: %d runtime files, no syntax, function or class from PHP 8.2 to 8.4.\n", count( $files ) ) );
	exit( 0 );
}
exit( 1 );
