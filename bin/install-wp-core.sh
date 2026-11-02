#!/usr/bin/env bash
# Downloads a WordPress release for the integration tests (CI and local).
#
#   bin/install-wp-core.sh <6.4|7.1|6.4.3|latest> <target-directory>
#
# "6.4" resolves to the newest 6.4.x security release, "latest" to the current stable release, using
# https://api.wordpress.org/core/stable-check/1.0/. The archive is checked against the published SHA-1.
# Afterwards `export WP_CORE_DIR=<target-directory>` and run `php bin/install-test-wp.php`.

set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
	print_usage "${BASH_SOURCE[0]}"
	exit 0
fi

WANT="${1:-}"
TARGET="${2:-}"
[[ -n "${WANT}" && -n "${TARGET}" ]] || die "Usage: bin/install-wp-core.sh <6.4|latest|x.y.z> <target-directory>"
require_cmd curl
require_cmd php
require_cmd tar

# resolve_version <want> < stable-check.json: prints the concrete version.
resolve_version() {
	# shellcheck disable=SC2016
	php -r '
		$want = $argv[1];
		$all  = json_decode( stream_get_contents( STDIN ), true );
		if ( ! is_array( $all ) ) { fwrite( STDERR, "stable-check returned no JSON\n" ); exit( 1 ); }
		$versions = array_keys( $all );
		if ( "latest" === $want ) {
			$latest = array_keys( $all, "latest", true );
			if ( array() === $latest ) { fwrite( STDERR, "no version is marked latest\n" ); exit( 1 ); }
			echo $latest[0]; exit( 0 );
		}
		$match = array_filter( $versions, static fn( $v ) => $v === $want || 0 === strpos( (string) $v, $want . "." ) );
		if ( array() === $match ) { fwrite( STDERR, "no WordPress release matches {$want}\n" ); exit( 1 ); }
		usort( $match, "version_compare" );
		echo end( $match );
	' "$1"
}

if [[ "${ESTASOFT_WP_VERSION_JSON:-}" != "" ]]; then
	JSON="$(cat "${ESTASOFT_WP_VERSION_JSON}")"
else
	JSON="$(curl -fsSL --retry 3 https://api.wordpress.org/core/stable-check/1.0/)"
fi
VERSION="$(resolve_version "${WANT}" <<< "${JSON}")"
info "WordPress ${WANT} resolves to ${VERSION}"

if [[ -n "${ESTASOFT_RESOLVE_ONLY:-}" ]]; then
	exit 0
fi

WORK="$(mktemp -d "${TMPDIR:-/tmp}/estasoft-wp.XXXXXX")"
trap 'rm -rf "${WORK}"' EXIT
ARCHIVE="${WORK}/wordpress.tar.gz"
curl -fsSL --retry 3 -o "${ARCHIVE}" "https://wordpress.org/wordpress-${VERSION}.tar.gz"
EXPECTED="$(curl -fsSL --retry 3 "https://wordpress.org/wordpress-${VERSION}.tar.gz.sha1" | tr -d '[:space:]')"
if command -v sha1sum > /dev/null 2>&1; then
	ACTUAL="$(sha1sum "${ARCHIVE}" | cut -d' ' -f1)"
else
	ACTUAL="$(shasum -a 1 "${ARCHIVE}" | cut -d' ' -f1)"
fi
[[ "${EXPECTED}" == "${ACTUAL}" ]] || die "SHA-1 mismatch for WordPress ${VERSION}: expected ${EXPECTED}, got ${ACTUAL}."

mkdir -p "${TARGET}"
tar -xzf "${ARCHIVE}" -C "${TARGET}" --strip-components=1
[[ -f "${TARGET}/wp-settings.php" ]] || die "${TARGET}/wp-settings.php is missing after extraction."
ok "WordPress ${VERSION} is in ${TARGET}"
