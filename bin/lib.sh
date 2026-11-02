#!/usr/bin/env bash
# Shared helpers for the scripts in bin/. Source this file; do not run it.
#
# Exit code convention used by every script here that check-all.sh calls:
#   0  passed
#   1  failed
#   77 skipped (a tool or input is missing; the script says what)
#
# shellcheck shell=bash
# shellcheck disable=SC2034

if [[ -n "${ESTASOFT_LIB_LOADED:-}" ]]; then
	return 0
fi
ESTASOFT_LIB_LOADED=1

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="estasoft-erp-crm-sync-engine"
PLUGIN_FILE="${ROOT_DIR}/${SLUG}.php"
README_FILE="${ROOT_DIR}/readme.txt"
EXIT_SKIPPED=77

# Top-level entries that may appear inside the release zip. Anything else is a build error, so a new
# developer file in the repository root can never leak into a release by accident. If a new runtime
# directory is added (for example templates/), add it here and make sure .distignore does not exclude it.
DIST_ALLOWED_TOP=(
	"${SLUG}.php"
	"uninstall.php"
	"readme.txt"
	"LICENSE"
	"includes"
	"build"
	"assets"
	"languages"
)

if [[ -t 1 ]]; then
	C_RED=$'\033[31m'
	C_GREEN=$'\033[32m'
	C_YELLOW=$'\033[33m'
	C_DIM=$'\033[2m'
	C_OFF=$'\033[0m'
else
	C_RED=""
	C_GREEN=""
	C_YELLOW=""
	C_DIM=""
	C_OFF=""
fi

info() { printf '%s\n' "$*"; }
ok() { printf '%sok%s   %s\n' "${C_GREEN}" "${C_OFF}" "$*"; }
warn() { printf '%swarn%s %s\n' "${C_YELLOW}" "${C_OFF}" "$*" >&2; }
fail() { printf '%sFAIL%s %s\n' "${C_RED}" "${C_OFF}" "$*" >&2; }
die() {
	fail "$*"
	exit 1
}

# print_usage <script>: prints the comment block at the top of a script (from line 2 to the first non-comment).
print_usage() {
	awk 'NR > 1 { if ($0 ~ /^#/) { sub(/^# ?/, ""); print } else { exit } }' "$1"
}

# require_cmd <command> [hint]: stop with a clear message when a tool is missing.
require_cmd() {
	if ! command -v "$1" > /dev/null 2>&1; then
		die "Required command not found: $1${2:+ ($2)}"
	fi
}

# plugin_header <Field>: value of a field in the plugin file header, for example "Version".
plugin_header() {
	local field="$1"
	sed -n -E "1,40s/^[[:space:]]*\*?[[:space:]]*${field}:[[:space:]]*(.*[^[:space:]])[[:space:]]*\$/\1/p" "${PLUGIN_FILE}" | head -n 1
}

# plugin_const_version: the value of ESTASOFT_SYNC_VERSION.
plugin_const_version() {
	sed -n -E "s/^[[:space:]]*define\([[:space:]]*'ESTASOFT_SYNC_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\).*/\1/p" "${PLUGIN_FILE}" | head -n 1
}

# readme_field <Field>: value of a header field in readme.txt, for example "Stable tag".
readme_field() {
	local field="$1"
	sed -n -E "s/^${field}:[[:space:]]*(.*[^[:space:]])[[:space:]]*\$/\1/p" "${README_FILE}" | head -n 1
}

# package_version: version in package.json (empty when the file is missing).
package_version() {
	[[ -f "${ROOT_DIR}/package.json" ]] || return 0
	if command -v node > /dev/null 2>&1; then
		node -e 'process.stdout.write(String(require(process.argv[1]).version || ""))' "${ROOT_DIR}/package.json"
	else
		sed -n -E 's/^  "version":[[:space:]]*"([^"]+)".*/\1/p' "${ROOT_DIR}/package.json" | head -n 1
	fi
}

# is_semver <value>: x.y.z with an optional pre-release suffix.
is_semver() {
	[[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]
}

# verify_versions [expected]: header, constant, readme Stable tag and package.json must agree.
# Prints each mismatch and returns 1 when there is one.
verify_versions() {
	local expected="${1:-}"
	local header const stable pkg status=0
	header="$(plugin_header Version)"
	const="$(plugin_const_version)"
	stable="$(readme_field 'Stable tag')"
	pkg="$(package_version)"

	[[ -n "${header}" ]] || {
		fail "No Version: line in the plugin header."
		status=1
	}
	[[ -n "${const}" ]] || {
		fail "No ESTASOFT_SYNC_VERSION constant in ${SLUG}.php."
		status=1
	}
	[[ -n "${stable}" ]] || {
		fail "No Stable tag: line in readme.txt."
		status=1
	}
	if [[ "${status}" -ne 0 ]]; then
		return 1
	fi
	if ! is_semver "${header}"; then
		fail "Plugin header version '${header}' is not x.y.z."
		status=1
	fi
	if [[ "${header}" != "${const}" ]]; then
		fail "Version mismatch: header '${header}' vs ESTASOFT_SYNC_VERSION '${const}'."
		status=1
	fi
	if [[ "${header}" != "${stable}" ]]; then
		fail "Version mismatch: header '${header}' vs readme Stable tag '${stable}'."
		status=1
	fi
	if [[ -n "${pkg}" && "${header}" != "${pkg}" ]]; then
		fail "Version mismatch: header '${header}' vs package.json '${pkg}'."
		status=1
	fi
	if [[ -n "${expected}" && "${header}" != "${expected}" ]]; then
		fail "Version mismatch: expected '${expected}' (the tag) but the plugin is '${header}'."
		status=1
	fi
	if [[ "${status}" -eq 0 ]]; then
		ok "Version ${header}: header, constant, readme Stable tag${pkg:+, package.json} agree${expected:+, tag matches}."
	fi
	return "${status}"
}

# human_size <bytes>
human_size() {
	local bytes="$1"
	if [[ "${bytes}" -ge 1048576 ]]; then
		printf '%d.%02d MiB' $((bytes / 1048576)) $(((bytes % 1048576) * 100 / 1048576))
	elif [[ "${bytes}" -ge 1024 ]]; then
		printf '%d.%02d KiB' $((bytes / 1024)) $(((bytes % 1024) * 100 / 1024))
	else
		printf '%d B' "${bytes}"
	fi
}

# sha256_file <file>: "<hash>  <file>" on Linux and macOS.
sha256_file() {
	if command -v sha256sum > /dev/null 2>&1; then
		sha256sum "$1"
	else
		shasum -a 256 "$1"
	fi
}

# npm_script_exists <name>: true when package.json defines that script.
npm_script_exists() {
	[[ -f "${ROOT_DIR}/package.json" ]] || return 1
	if command -v node > /dev/null 2>&1; then
		node -e 'const s = require(process.argv[1]).scripts || {}; process.exit(Object.prototype.hasOwnProperty.call(s, process.argv[2]) ? 0 : 1)' "${ROOT_DIR}/package.json" "$1"
	else
		grep -q -E "^    \"$1\"[[:space:]]*:" "${ROOT_DIR}/package.json"
	fi
}
