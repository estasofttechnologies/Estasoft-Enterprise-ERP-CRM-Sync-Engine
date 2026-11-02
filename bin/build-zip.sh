#!/usr/bin/env bash
# Builds the production zip that goes to WordPress.org and to GitHub releases.
#
#   bin/build-zip.sh [--dry-run] [--skip-build] [--out DIR] [--expect-version X.Y.Z] [--keep-staging]
#
# What it does
#   1. Checks that the plugin header, ESTASOFT_SYNC_VERSION, readme.txt Stable tag and package.json agree.
#   2. Checks readme.txt (bin/check-readme.php).
#   3. Builds the admin app (npm ci && npm run build) unless --skip-build or --dry-run.
#   4. Copies the runtime files into a staging folder, leaving out everything listed in .distignore.
#   5. Verifies the staging folder (allowed top-level entries only, no hidden or developer files, every
#      PHP file guarded, plugin header and text domain correct, build output present).
#   6. Zips it as estasoft-erp-crm-sync-engine/ and verifies the zip again from the inside.
#   7. Prints the file list, the size and a SHA-256 checksum.
#
# --dry-run does steps 1, 2, 4 and 5 and prints the file list, but does not run npm and writes no zip. Missing
# build output is then a warning, not an error. Use it to check the file selection on any working tree.
#
# `npm ci` deletes node_modules and reinstalls it from package-lock.json, so a real build takes a minute and
# resets your local node_modules; use --skip-build when build/ is already fresh.
#
# Requires: bash, rsync, zip, unzip, php; node and npm for a real build.

set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

DRY_RUN=0
SKIP_BUILD=0
KEEP_STAGING=0
OUT_DIR="${ROOT_DIR}/dist"
EXPECT_VERSION=""

usage() {
	print_usage "${BASH_SOURCE[0]}"
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--dry-run) DRY_RUN=1 ;;
		--skip-build) SKIP_BUILD=1 ;;
		--keep-staging) KEEP_STAGING=1 ;;
		--out)
			[[ $# -ge 2 ]] || die "--out needs a directory."
			OUT_DIR="$2"
			shift
			;;
		--expect-version)
			[[ $# -ge 2 ]] || die "--expect-version needs a version."
			EXPECT_VERSION="$2"
			shift
			;;
		-h | --help)
			usage
			exit 0
			;;
		*) die "Unknown option: $1 (try --help)" ;;
	esac
	shift
done

require_cmd rsync "apt install rsync"
require_cmd php
if [[ "${DRY_RUN}" -eq 0 ]]; then
	require_cmd zip "apt install zip"
	require_cmd unzip "apt install unzip"
fi

cd "${ROOT_DIR}"

info "== 1. Versions"
verify_versions "${EXPECT_VERSION}" || exit 1
VERSION="$(plugin_header Version)"

info "== 2. readme.txt"
php "${ROOT_DIR}/bin/check-readme.php" "${README_FILE}" || exit 1

info "== 3. Admin app build"
if [[ "${DRY_RUN}" -eq 1 ]]; then
	info "skip (dry run): npm is not run."
elif [[ "${SKIP_BUILD}" -eq 1 ]]; then
	info "skip (--skip-build): using the existing build/ folder."
else
	require_cmd node "install Node.js 20 or later"
	require_cmd npm
	if [[ -f "${ROOT_DIR}/package.json" ]]; then
		npm ci --no-audit --no-fund
		npm run build
	else
		die "package.json is missing, so the admin app cannot be built."
	fi
fi

info "== 4. Staging"
WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/estasoft-zip.XXXXXX")"
cleanup() {
	if [[ "${KEEP_STAGING}" -eq 0 && -n "${WORK_DIR:-}" && -d "${WORK_DIR}" ]]; then
		rm -rf "${WORK_DIR}"
	fi
}
trap cleanup EXIT
STAGE="${WORK_DIR}/${SLUG}"
mkdir -p "${STAGE}"

[[ -f "${ROOT_DIR}/.distignore" ]] || die ".distignore is missing."

# The exclude file is .distignore (the same file the 10up deploy action reads) plus a safety net that is
# always applied, so a mistake in .distignore cannot put these into a release.
SAFETY_EXCLUDES="${WORK_DIR}/safety-excludes"
cat > "${SAFETY_EXCLUDES}" << 'EOF'
.git
.git*
.svn
node_modules
vendor
*.map
*.log
*.zip
*.sh
.DS_Store
Thumbs.db
EOF

# A zip folder inside the repository (the default is dist/; a relative --out is relative to the repository
# root) must not be copied into itself.
case "${OUT_DIR}" in
	/*) OUT_ABS="${OUT_DIR}" ;;
	*) OUT_ABS="${ROOT_DIR}/${OUT_DIR}" ;;
esac
if [[ "${OUT_ABS}" == "${ROOT_DIR}/"* ]]; then
	printf '/%s\n' "${OUT_ABS#"${ROOT_DIR}/"}" >> "${SAFETY_EXCLUDES}"
fi

rsync -a --prune-empty-dirs \
	--exclude-from="${ROOT_DIR}/.distignore" \
	--exclude-from="${SAFETY_EXCLUDES}" \
	"${ROOT_DIR}/" "${STAGE}/"

info "== 5. Verifying the staged files"
VERIFY_FAILED=0
verify_fail() {
	fail "$*"
	VERIFY_FAILED=1
}

# 5a. Only expected top-level entries.
while IFS= read -r entry; do
	allowed=0
	for candidate in "${DIST_ALLOWED_TOP[@]}"; do
		if [[ "${entry}" == "${candidate}" ]]; then
			allowed=1
			break
		fi
	done
	if [[ "${allowed}" -eq 0 ]]; then
		verify_fail "Unexpected top-level entry in the release: ${entry}. Add it to .distignore, or to DIST_ALLOWED_TOP in bin/lib.sh if it is a runtime file."
	fi
done < <(find "${STAGE}" -mindepth 1 -maxdepth 1 -exec basename {} \; | LC_ALL=C sort)

# 5b. Required runtime files.
for required in "${SLUG}.php" "uninstall.php" "readme.txt" "LICENSE" "includes/Plugin.php"; do
	[[ -f "${STAGE}/${required}" ]] || verify_fail "Missing from the release: ${required}"
done

# 5c. Hidden files and developer files.
while IFS= read -r hidden; do
	verify_fail "Hidden file in the release: ${hidden#"${STAGE}"/}"
done < <(find "${STAGE}" -name '.*' -not -path "${STAGE}" | sort)
while IFS= read -r dev; do
	verify_fail "Developer file in the release: ${dev#"${STAGE}"/}"
done < <(find "${STAGE}" \( -name 'package.json' -o -name 'package-lock.json' -o -name 'composer.json' -o -name 'composer.lock' -o -name 'phpunit*.xml*' -o -name 'phpcs.xml*' -o -name 'phpstan*.neon*' -o -name '*.sh' -o -name '*.zip' -o -name '*.map' -o -name 'node_modules' -o -name 'vendor' -o -name 'tests' -o -name 'docs' -o -name 'src' \) | sort)

# 5d. Every PHP file is guarded against direct access.
while IFS= read -r php_file; do
	relative="${php_file#"${STAGE}"/}"
	# build/*.asset.php is generated by @wordpress/scripts: one line, `<?php return array( ... );`, no code to guard.
	if [[ "${relative}" == build/*.asset.php ]]; then
		if ! grep -q -E '^<\?php return array\(.*\);[[:space:]]*$' "${php_file}" || [[ "$(wc -l < "${php_file}" | tr -d '[:space:]')" -gt 1 ]]; then
			verify_fail "Unexpected content in generated file ${relative} (expected a single 'return array( ... );' line)."
		fi
		continue
	fi
	if ! grep -q -E "ABSPATH|WP_UNINSTALL_PLUGIN" "${php_file}"; then
		# An index.php that only holds a comment is allowed ("Silence is golden").
		if [[ "$(basename "${php_file}")" == "index.php" ]] && ! grep -q -E '^[^/*#]*[A-Za-z_]+[[:space:]]*\(' "${php_file}"; then
			continue
		fi
		verify_fail "PHP file without an ABSPATH guard: ${relative}"
	fi
	if ! php -l "${php_file}" > /dev/null 2>&1; then
		verify_fail "PHP syntax error: ${relative}"
	fi
done < <(find "${STAGE}" -name '*.php' | sort)

# 5e. Plugin header fields inside the staged copy.
STAGED_MAIN="${STAGE}/${SLUG}.php"
if [[ -f "${STAGED_MAIN}" ]]; then
	staged_field() {
		sed -n -E "1,40s/^[[:space:]]*\*?[[:space:]]*$1:[[:space:]]*(.*[^[:space:]])[[:space:]]*\$/\1/p" "${STAGED_MAIN}" | head -n 1
	}
	[[ "$(staged_field 'Plugin Name')" == "Estasoft Enterprise ERP/CRM Sync Engine" ]] || verify_fail "Plugin Name header must be 'Estasoft Enterprise ERP/CRM Sync Engine'."
	[[ "$(staged_field 'Text Domain')" == "${SLUG}" ]] || verify_fail "Text Domain header must be '${SLUG}'."
	[[ "$(staged_field 'Domain Path')" == "/languages" ]] || verify_fail "Domain Path header must be '/languages'."
	[[ "$(staged_field 'Requires Plugins')" == "woocommerce" ]] || verify_fail "Requires Plugins header must be 'woocommerce'."
	[[ "$(staged_field 'License')" =~ ^GPL(-|v)?2 ]] || verify_fail "License header must be GPL-2.0-or-later."
	[[ -n "$(staged_field 'Requires at least')" && -n "$(staged_field 'Requires PHP')" ]] || verify_fail "Requires at least and Requires PHP headers are required."
fi

# 5f. Build output.
if [[ -d "${STAGE}/build" ]]; then
	for asset in build/index.js build/index.asset.php; do
		[[ -f "${STAGE}/${asset}" ]] || verify_fail "Build output incomplete: ${asset} is missing."
	done
	if grep -R -l -E "ESTASOFT_MOCK|sourceMappingURL=.*\.map" "${STAGE}/build" > /dev/null 2>&1; then
		verify_fail "The bundle references the mock layer or a source map; rebuild with a production build."
	fi
elif [[ "${DRY_RUN}" -eq 1 ]]; then
	warn "build/ does not exist yet (dry run). A real build needs it."
else
	verify_fail "build/ is missing: the admin app has not been built."
fi

# 5g. No placeholder text in the changelog entry.
if grep -q "Describe the changes in this release" "${STAGE}/readme.txt" 2> /dev/null; then
	verify_fail "readme.txt still contains the changelog stub from bump-version.sh."
fi

if [[ "${VERIFY_FAILED}" -ne 0 ]]; then
	die "The staged files did not pass verification."
fi
ok "Staged files pass: allowed entries only, no hidden or developer files, PHP guarded and valid, header correct."

STAGE_FILES="$(cd "${WORK_DIR}" && find "${SLUG}" -type f | LC_ALL=C sort)"
FILE_COUNT="$(printf '%s\n' "${STAGE_FILES}" | grep -c . || true)"
STAGE_BYTES="$(find "${STAGE}" -type f -exec cat {} + | wc -c | tr -d '[:space:]')"

if [[ "${DRY_RUN}" -eq 1 ]]; then
	info "== 6. Dry run: no zip written"
	info "Files that would be in ${SLUG}-${VERSION}.zip:"
	printf '%s\n' "${STAGE_FILES}" | sed 's/^/  /'
	info "${FILE_COUNT} files, $(human_size "${STAGE_BYTES}") uncompressed."
	exit 0
fi

info "== 6. Zip"
mkdir -p "${OUT_DIR}"
OUT_DIR="$(cd "${OUT_DIR}" && pwd)"
ZIP_PATH="${OUT_DIR}/${SLUG}-${VERSION}.zip"
rm -f "${ZIP_PATH}" "${ZIP_PATH}.sha256"
(
	cd "${WORK_DIR}"
	printf '%s\n' "${STAGE_FILES}" | zip -X -q -9 "${ZIP_PATH}" -@
)

info "== 7. Verifying the zip itself"
ZIP_FAILED=0
zip_fail() {
	fail "$*"
	ZIP_FAILED=1
}
unzip -tq "${ZIP_PATH}" > /dev/null || zip_fail "The zip failed its integrity test."
TOP_ENTRIES="$(unzip -Z1 "${ZIP_PATH}" | cut -d/ -f1 | sort -u)"
[[ "${TOP_ENTRIES}" == "${SLUG}" ]] || zip_fail "The zip must contain exactly one top-level folder named ${SLUG}; found: ${TOP_ENTRIES//$'\n'/, }"
if unzip -Z1 "${ZIP_PATH}" | grep -E -q '(^|/)(\.|node_modules/|vendor/|tests/|docs/|src/|bin/)'; then
	zip_fail "The zip contains hidden or developer entries."
fi
ZIP_HEADER="$(unzip -p "${ZIP_PATH}" "${SLUG}/${SLUG}.php" | head -n 40)"
ZIP_VERSION="$(printf '%s\n' "${ZIP_HEADER}" | sed -n -E 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' | head -n 1)"
ZIP_CONST="$(unzip -p "${ZIP_PATH}" "${SLUG}/${SLUG}.php" | sed -n -E "s/^[[:space:]]*define\([[:space:]]*'ESTASOFT_SYNC_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'.*/\1/p" | head -n 1)"
ZIP_STABLE="$(unzip -p "${ZIP_PATH}" "${SLUG}/readme.txt" | sed -n -E 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' | head -n 1)"
ZIP_DOMAIN="$(printf '%s\n' "${ZIP_HEADER}" | sed -n -E 's/^[[:space:]]*\*?[[:space:]]*Text Domain:[[:space:]]*([^[:space:]]+).*/\1/p' | head -n 1)"
if [[ "${ZIP_VERSION}" != "${VERSION}" || "${ZIP_CONST}" != "${VERSION}" || "${ZIP_STABLE}" != "${VERSION}" ]]; then
	zip_fail "Inside the zip: header '${ZIP_VERSION}', constant '${ZIP_CONST}', Stable tag '${ZIP_STABLE}'; expected '${VERSION}' in all three."
fi
[[ "${ZIP_DOMAIN}" == "${SLUG}" ]] || zip_fail "Inside the zip: Text Domain is '${ZIP_DOMAIN}', expected '${SLUG}'."
if [[ "${ZIP_FAILED}" -ne 0 ]]; then
	rm -f "${ZIP_PATH}"
	die "The zip did not pass verification and was removed."
fi
ok "Zip passes: one folder '${SLUG}', header, constant and Stable tag are ${VERSION}, text domain is ${SLUG}."

(cd "${OUT_DIR}" && sha256_file "$(basename "${ZIP_PATH}")" > "$(basename "${ZIP_PATH}").sha256")

info "== Result"
unzip -Z1 "${ZIP_PATH}" | LC_ALL=C sort | sed 's/^/  /'
ZIP_BYTES="$(wc -c < "${ZIP_PATH}" | tr -d '[:space:]')"
info "${FILE_COUNT} files, $(human_size "${STAGE_BYTES}") uncompressed, zip $(human_size "${ZIP_BYTES}")"
info "Zip:      ${ZIP_PATH}"
info "Checksum: $(cut -d' ' -f1 "${ZIP_PATH}.sha256")"
