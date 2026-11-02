#!/usr/bin/env bash
# Builds the release zip and installs THAT ZIP into a fresh WordPress, then checks it (bin/smoke-install.php does the checks).
#
#   bin/smoke-install.sh [--skip-build] [--zip FILE] [--report FILE] [--keep]
#
#   --skip-build   build the zip from the existing build/ folder (bin/build-zip.sh --skip-build) instead of running npm ci and npm run build
#   --zip FILE     test this zip and build nothing
#   --report FILE  write a Markdown report (docs/release/SMOKE-TEST.md is the one the repository keeps)
#   --keep         keep the temporary folder and the database for a look afterwards
#
# What it does
#   1. Builds the zip with bin/build-zip.sh (unless --zip), into a temporary folder.
#   2. Creates a FRESH MariaDB or MySQL database and a fresh wp-content folder outside the repository, and installs WordPress into it
#      (core from $SMOKE_CORE, default /opt/devenv/wp-deb/usr/share/wordpress, 6.4.3 on the build machine).
#   3. Copies the WooCommerce stand-in (tests/php/stubs/woocommerce) into plugins/ as a real folder, and UNZIPS the release zip into plugins/.
#      Nothing in the repository is symlinked or loaded.
#   4. Runs the phases of bin/smoke-install.php, each in its own PHP process with WP_DEBUG and a debug.log: install, the requirements guard
#      (PHP too old, WordPress and WooCommerce checks), plugin active without WooCommerce, activation, the REST routes of docs/rest-api.md and
#      anonymous refusals, the catalog and every connector class, the admin page and its script, deactivation, uninstall with and without
#      "Delete all data", and a last look at debug.log and at outbound HTTP requests (there must be none).
#
# Environment: SMOKE_CORE, SMOKE_DB_USER (root), SMOKE_DB_PASSWORD (empty), SMOKE_DB_HOST (localhost).
# Needs: php with mysqli, the mysql or mariadb client, zip and unzip, rsync (for the build); node and npm unless --skip-build or --zip.
#
# Exit: 0 every check passed, 1 something failed, 77 the environment cannot run it (no database or no WordPress core).

# cleanup() runs from the EXIT trap; the report text uses backticks on purpose (Markdown), inside single quotes.
# shellcheck disable=SC2329,SC2016
set -uo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

SKIP_BUILD=0
ZIP_ARG=""
REPORT=""
KEEP=0
while [[ $# -gt 0 ]]; do
	case "$1" in
		--skip-build) SKIP_BUILD=1 ;;
		--keep) KEEP=1 ;;
		--zip)
			[[ $# -ge 2 ]] || die "--zip needs a file."
			ZIP_ARG="$2"
			shift
			;;
		--report)
			[[ $# -ge 2 ]] || die "--report needs a file."
			REPORT="$2"
			shift
			;;
		-h | --help)
			print_usage "${BASH_SOURCE[0]}"
			exit 0
			;;
		*) die "Unknown option: $1 (try --help)" ;;
	esac
	shift
done

require_cmd php
require_cmd unzip "apt install unzip"

CORE="${SMOKE_CORE:-/opt/devenv/wp-deb/usr/share/wordpress}"
DB_USER="${SMOKE_DB_USER:-root}"
DB_PASSWORD="${SMOKE_DB_PASSWORD:-}"
DB_HOST="${SMOKE_DB_HOST:-localhost}"

if [[ ! -f "${CORE}/wp-settings.php" ]]; then
	warn "No WordPress core at ${CORE} (set SMOKE_CORE, or run bin/install-wp-core.sh latest <dir>)."
	exit "${EXIT_SKIPPED}"
fi

DB_CLIENT="$(command -v mysql || command -v mariadb || true)"
if [[ -z "${DB_CLIENT}" ]]; then
	warn "Neither mysql nor mariadb client is installed."
	exit "${EXIT_SKIPPED}"
fi
db() {
	local args=(-u"${DB_USER}" -h"${DB_HOST}")
	[[ -n "${DB_PASSWORD}" ]] && args+=(-p"${DB_PASSWORD}")
	"${DB_CLIENT}" "${args[@]}" "$@"
}
if ! db -e 'SELECT 1' > /dev/null 2>&1; then
	warn "Cannot connect to the database as ${DB_USER}@${DB_HOST} (set SMOKE_DB_USER, SMOKE_DB_PASSWORD, SMOKE_DB_HOST)."
	exit "${EXIT_SKIPPED}"
fi

WORK="$(mktemp -d "${TMPDIR:-/tmp}/estasoft-smoke.XXXXXX")"
DB_NAME="estasoft_smoke_$$"
cleanup() {
	if [[ "${KEEP}" -eq 0 ]]; then
		db -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`" > /dev/null 2>&1 || true
		rm -rf "${WORK}"
	else
		info "Kept: ${WORK} and database ${DB_NAME}"
	fi
}
trap cleanup EXIT

# ---- 1. The zip ------------------------------------------------------------------------------------------------------------
if [[ -n "${ZIP_ARG}" ]]; then
	[[ -f "${ZIP_ARG}" ]] || die "No such zip: ${ZIP_ARG}"
	ZIP="$(cd "$(dirname "${ZIP_ARG}")" && pwd)/$(basename "${ZIP_ARG}")"
	info "== 1. Using the zip ${ZIP}"
else
	info "== 1. Building the release zip"
	BUILD_ARGS=(--out "${WORK}/zip")
	[[ "${SKIP_BUILD}" -eq 1 ]] && BUILD_ARGS+=(--skip-build)
	if ! bash "${ROOT_DIR}/bin/build-zip.sh" "${BUILD_ARGS[@]}" > "${WORK}/build.log" 2>&1; then
		tail -n 30 "${WORK}/build.log" >&2
		die "bin/build-zip.sh failed."
	fi
	tail -n 4 "${WORK}/build.log"
	ZIP="$(find "${WORK}/zip" -maxdepth 1 -name "${SLUG}-*.zip" | head -n 1)"
	[[ -f "${ZIP}" ]] || die "The build produced no zip."
fi
ZIP_BYTES="$(wc -c < "${ZIP}" | tr -d '[:space:]')"
ZIP_SHA="$(sha256_file "${ZIP}" | cut -d' ' -f1)"
FILE_COUNT="$(unzip -Z1 "${ZIP}" | grep -vc '/$')"
UNCOMPRESSED="$(unzip -Zt "${ZIP}" | sed -n -E 's/^[0-9]+ files?, ([0-9]+) bytes uncompressed.*/\1/p')"
# Nothing a developer needs may be inside: no VCS, dependency, test, documentation, script or note file, and no hidden file.
BAD_ENTRIES="$(unzip -Z1 "${ZIP}" | grep -E '(\.md$|/READY$|\.map$|\.log$|\.sh$|\.zip$|\.neon|\.xml(\.dist)?$|composer\.(json|lock)$|package(-lock)?\.json$|(^|/)\.|/(tests?|docs?|src|bin|node_modules|vendor)/)' || true)"
if [[ -n "${BAD_ENTRIES}" ]]; then
	printf '%s\n' "${BAD_ENTRIES}" | head -n 20 >&2
	die "The zip contains developer files (listed above)."
fi
ok "$(basename "${ZIP}"): ${FILE_COUNT} files, $(human_size "${ZIP_BYTES}"), SHA-256 ${ZIP_SHA}"

# ---- 2 and 3. A fresh database and content folder, the stand-in and the unzipped plugin --------------------------------------
info "== 2. A fresh WordPress database and wp-content"
db -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" || die "Cannot create the database."
CONTENT="${WORK}/wp-content"
mkdir -p "${CONTENT}/plugins" "${CONTENT}/mu-plugins" "${CONTENT}/themes" "${CONTENT}/uploads" "${CONTENT}/languages"
cp -R "${ROOT_DIR}/tests/php/stubs/woocommerce" "${CONTENT}/plugins/woocommerce"
unzip -q "${ZIP}" -d "${CONTENT}/plugins/"
[[ -f "${CONTENT}/plugins/${SLUG}/${SLUG}.php" ]] || die "The zip does not unpack to ${SLUG}/${SLUG}.php."
if [[ -n "$(find "${CONTENT}" -type l)" ]]; then
	die "There is a symlink in the test content folder; the test must run on the unzipped files."
fi
# A guard that records every outbound request and refuses it. The plugin must make none: nothing is connected.
cat > "${CONTENT}/mu-plugins/smoke-http-guard.php" << 'PHP'
<?php
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		file_put_contents( WP_CONTENT_DIR . '/http-requests.log', $url . "\n", FILE_APPEND );
		return new WP_Error( 'smoke_blocked', 'Outbound requests are blocked in the smoke test.' );
	},
	1,
	3
);
PHP
ok "database ${DB_NAME} and ${CONTENT} created; the plugin is the unzipped release, WooCommerce is the stand-in (real folders, no symlinks)"

# ---- 4. The phases ---------------------------------------------------------------------------------------------------------
export SMOKE_CORE="${CORE}" SMOKE_CONTENT="${CONTENT}" SMOKE_DB="${DB_NAME}" SMOKE_DB_USER="${DB_USER}" SMOKE_DB_PASSWORD="${DB_PASSWORD}" SMOKE_DB_HOST="${DB_HOST}"
PHASE_LOG="${WORK}/phases.log"
: > "${PHASE_LOG}"
FAILED=0
run_phase() {
	local title="$1"
	shift
	info "== ${title}"
	{
		printf '### %s\n' "${title}"
		php "${ROOT_DIR}/bin/smoke-install.php" "$@" 2>&1
		printf 'exit=%s\n' "$?"
	} | tee -a "${PHASE_LOG}" | grep -E '^(ok |FAIL|warn|info|[a-z-]+: [0-9]+ checks)' | grep -vE '^info ' | sed 's/^/   /'
	if tail -n 1 "${PHASE_LOG}" | grep -qv '^exit=0$'; then
		FAILED=1
	fi
}

run_phase "Install WordPress" install
run_phase "Requirements guard: PHP too old" guard old-php
run_phase "Requirements guard: WordPress and WooCommerce" guard deps
run_phase "Plugin active, WooCommerce not" set-plugins estasoft
run_phase "No WooCommerce: notice, no fatal error" no-woo
run_phase "WooCommerce active" set-plugins woocommerce
run_phase "Activate the plugin" activate
run_phase "REST routes, catalog, connectors, admin page" runtime
run_phase "Seed data for the uninstall tests" seed
run_phase "Deactivate" deactivate
run_phase "Uninstall, Delete all data off" uninstall-keep
run_phase "Uninstall, Delete all data on" uninstall-delete
run_phase "debug.log and outbound requests" log

TOTAL_OK="$(grep -c '^ok ' "${PHASE_LOG}" || true)"
TOTAL_FAIL="$(grep -c '^FAIL' "${PHASE_LOG}" || true)"
WP_VERSION="$(sed -n 's/^info wp_version=//p' "${PHASE_LOG}" | head -n 1)"
PHP_VERSION="$(sed -n 's/^info php_version=//p' "${PHASE_LOG}" | head -n 1)"
DB_VERSION="$(db -N -e 'SELECT VERSION()' 2> /dev/null || true)"

# ---- Report ----------------------------------------------------------------------------------------------------------------
if [[ -n "${REPORT}" ]]; then
	{
		printf '# Smoke test of the release zip\n\n'
		printf 'Written by `bin/smoke-install.sh --report`; do not edit by hand. Run it again after any change to the plugin and commit the new file with the release.\n\n'
		printf '| | |\n|---|---|\n'
		printf '| Date (UTC) | %s |\n' "$(date -u '+%Y-%m-%d %H:%M')"
		printf '| Result | %s |\n' "$([[ "${FAILED}" -eq 0 && "${TOTAL_FAIL}" -eq 0 ]] && printf 'passed: %s checks, 0 failed' "${TOTAL_OK}" || printf 'FAILED: %s passed, %s failed' "${TOTAL_OK}" "${TOTAL_FAIL}")"
		printf '| Plugin version | %s |\n' "$(plugin_header Version)"
		printf '| Zip | `%s` |\n' "$(basename "${ZIP}")"
		printf '| Files in the zip | %s |\n' "${FILE_COUNT}"
		printf '| Size | %s zipped, %s unpacked |\n' "$(human_size "${ZIP_BYTES}")" "$(human_size "${UNCOMPRESSED:-0}")"
		printf '| SHA-256 | `%s` |\n' "${ZIP_SHA}"
		printf '| WordPress | %s (fresh install, WP_DEBUG on, debug.log) |\n' "${WP_VERSION}"
		printf '| PHP | %s |\n' "${PHP_VERSION}"
		printf '| Database | %s (fresh database, utf8mb4) |\n' "${DB_VERSION}"
		printf '| WooCommerce | the stand-in `tests/php/stubs/woocommerce` (a copy, version 9.4.0): the real WooCommerce is not available on the build machine |\n'
		printf '\n## What was tested\n\nThe plugin under test is the unpacked release zip in `wp-content/plugins/`, never the repository. Each step ran in its own PHP process.\n\n'
		grep -E '^(### |ok |FAIL|warn)' "${PHASE_LOG}" | sed -E 's/^### (.*)/\n### \1\n/; s/^ok   /- /; s/^FAIL /- **FAILED** /; s/^warn /- Note: /'
		printf '\n\n## What this does not prove\n\n'
		printf -- '- The real WooCommerce (orders, HPOS, block checkout, Action Scheduler) is not installed here. Activation and the admin page are proven against the stand-in; the order hooks need a test on a real shop.\n'
		printf -- '- No connector was run against a vendor: nothing is connected, and the test proves no outbound request is made.\n'
		printf -- '- The admin app ran no JavaScript here: the test proves that the script is enqueued with the right handles on WordPress %s, not that it renders in a browser.\n' "${WP_VERSION}"
	} > "${REPORT}"
	info "Report written to ${REPORT}"
fi

info "== Result"
info "${TOTAL_OK} checks passed, ${TOTAL_FAIL} failed. WordPress ${WP_VERSION}, PHP ${PHP_VERSION}, ${FILE_COUNT} files, zip $(human_size "${ZIP_BYTES}"), SHA-256 ${ZIP_SHA}"
if [[ "${FAILED}" -ne 0 || "${TOTAL_FAIL}" -ne 0 ]]; then
	fail "The smoke test failed. The full log is ${PHASE_LOG} (use --keep to keep it)."
	cp "${PHASE_LOG}" "${TMPDIR:-/tmp}/estasoft-smoke-last.log" 2> /dev/null || true
	exit 1
fi
ok "The release zip installs, runs, deactivates and uninstalls as documented."
exit 0
