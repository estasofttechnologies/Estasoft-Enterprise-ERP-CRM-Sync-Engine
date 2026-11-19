#!/usr/bin/env bash
# Uploads a built plugin to the WordPress.org Subversion repository in many small commits.
#
#   bin/wporg-upload-in-batches.sh --plugin-dir DIR --version X.Y.Z [--assets-dir DIR]
#                                  [--svn-url URL] [--batch-size N] [--dry-run]
#
# This is the fallback for the Release workflow's deploy job. That job sends every file in one commit, and
# on a plugin's first release the WordPress.org server can take longer than ten minutes to process it, so
# the connection times out (svn: E175012). Here the commits are small, so each one finishes quickly:
#
#   1. one commit that creates the empty folders in trunk;
#   2. one commit per folder of at most --batch-size files (larger folders are split by sub-folder);
#   3. the files that sit directly in a folder, last of all the plugin's top-level files, so readme.txt and
#      the main plugin file arrive after the code they refer to;
#   4. removal of files and folders that are no longer in the plugin, then a check that trunk on the server
#      is identical to --plugin-dir;
#   5. tags/X.Y.Z, a server-side copy of trunk (skipped when the tag already exists);
#   6. --assets-dir (icons, banners, screenshots) into the repository's assets folder.
#
# It is safe to run again after a failure: it starts from a fresh checkout and commits only what differs.
#
# SVN_USERNAME and SVN_PASSWORD must be set in the environment (the password goes to svn on standard input,
# never on the command line). They are not needed for a file:// URL, which is how the script is tested.
# --dry-run prints the plan and exits without touching the network.

set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

PLUGIN_DIR=""
ASSETS_DIR=""
VERSION=""
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
BATCH_SIZE=100
DRY_RUN=0
COMMIT_TIMEOUT=1500

while [[ $# -gt 0 ]]; do
	case "$1" in
		-h | --help)
			print_usage "${BASH_SOURCE[0]}"
			exit 0
			;;
		--plugin-dir)
			PLUGIN_DIR="${2:-}"
			shift 2
			;;
		--assets-dir)
			ASSETS_DIR="${2:-}"
			shift 2
			;;
		--version)
			VERSION="${2:-}"
			shift 2
			;;
		--svn-url)
			SVN_URL="${2:-}"
			shift 2
			;;
		--batch-size)
			BATCH_SIZE="${2:-}"
			shift 2
			;;
		--dry-run)
			DRY_RUN=1
			shift
			;;
		*)
			die "Unknown option: $1 (see --help)"
			;;
	esac
done

[[ -n "${PLUGIN_DIR}" ]] || die "--plugin-dir is required."
[[ -d "${PLUGIN_DIR}" ]] || die "Plugin folder not found: ${PLUGIN_DIR}"
[[ -f "${PLUGIN_DIR}/${SLUG}.php" && -f "${PLUGIN_DIR}/readme.txt" ]] || die "${PLUGIN_DIR} does not look like the built plugin (${SLUG}.php or readme.txt is missing)."
[[ "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "--version must look like 1.2.3 (a stable release)."
[[ "${BATCH_SIZE}" =~ ^[1-9][0-9]*$ ]] || die "--batch-size must be a positive whole number."
if [[ -n "${ASSETS_DIR}" && ! -d "${ASSETS_DIR}" ]]; then
	die "Assets folder not found: ${ASSETS_DIR}"
fi
PLUGIN_DIR="$(cd "${PLUGIN_DIR}" && pwd)"
[[ -z "${ASSETS_DIR}" ]] || ASSETS_DIR="$(cd "${ASSETS_DIR}" && pwd)"

# --- Plan ----------------------------------------------------------------------------------------------

# Each entry is "tree|folder" (the folder and everything below it) or "files|folder" (only the files that sit
# directly in the folder; the empty string is the plugin's top level).
BATCHES=()

count_files() { find "$1" -type f | wc -l | tr -d '[:space:]'; }

plan_dir() {
	local rel="$1" abs="${PLUGIN_DIR}${1:+/$1}" name
	if [[ -n "${rel}" && "$(count_files "${abs}")" -le "${BATCH_SIZE}" ]]; then
		BATCHES+=("tree|${rel}")
		return 0
	fi
	while IFS= read -r name; do
		plan_dir "${rel:+${rel}/}${name}"
	done < <(find "${abs}" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | LC_ALL=C sort)
	if [[ -n "$(find "${abs}" -mindepth 1 -maxdepth 1 -type f -print -quit)" ]]; then
		BATCHES+=("files|${rel}")
	fi
}

plan_dir ""

info "Plugin folder: ${PLUGIN_DIR} ($(count_files "${PLUGIN_DIR}") files)"
info "Plan: 1 commit for the folders, ${#BATCHES[@]} commits for the files, then the tag and the assets"
if ((DRY_RUN)); then
	for entry in "${BATCHES[@]}"; do
		mode="${entry%%|*}"
		rel="${entry#*|}"
		abs="${PLUGIN_DIR}${rel:+/$rel}"
		if [[ "${mode}" == "tree" ]]; then
			n="$(count_files "${abs}")"
		else
			n="$(find "${abs}" -mindepth 1 -maxdepth 1 -type f | wc -l | tr -d '[:space:]')"
		fi
		printf '  %-5s %4s files  trunk/%s\n' "${mode}" "${n}" "${rel}"
	done
	info "Dry run: nothing was uploaded."
	exit 0
fi

# --- Subversion helpers --------------------------------------------------------------------------------

require_cmd svn "apt-get install subversion"
require_cmd rsync
require_cmd timeout

if [[ "${SVN_URL}" != file://* ]]; then
	[[ -n "${SVN_USERNAME:-}" && -n "${SVN_PASSWORD:-}" ]] || die "SVN_USERNAME and SVN_PASSWORD must be set."
fi

# svn_w <svn arguments>: runs svn with the login (the password is passed on standard input) and a time limit.
svn_w() {
	if [[ -n "${SVN_USERNAME:-}" ]]; then
		printf '%s' "${SVN_PASSWORD}" | timeout "${COMMIT_TIMEOUT}" svn "$@" --non-interactive --no-auth-cache \
			--username "${SVN_USERNAME}" --password-from-stdin
	else
		timeout "${COMMIT_TIMEOUT}" svn "$@" --non-interactive --no-auth-cache < /dev/null
	fi
}

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
WC="${WORK}/wc"

# register <path> [svn depth]: add what is new under <path> and schedule what disappeared for removal.
register() {
	local path="$1" depth="${2:-infinity}" line
	svn add --force --quiet --depth "${depth}" --no-auto-props "${path}" > /dev/null
	svn status --depth "${depth}" "${path}" | while IFS= read -r line; do
		if [[ "${line:0:1}" == "!" ]]; then
			svn rm --quiet --force "${line:8}" > /dev/null 2>&1 || true
		fi
	done
}

# commit_path <message> <path> [svn depth]: commits <path> when it has changes.
commit_path() {
	local message="$1" path="$2" depth="${3:-infinity}" started=${SECONDS}
	if [[ -z "$(svn status --depth "${depth}" "${path}")" ]]; then
		info "      nothing to commit"
		return 0
	fi
	svn_w commit --depth "${depth}" -m "${message}" "${path}" > /dev/null
	info "      committed in $((SECONDS - started)) s"
}

# --- Upload --------------------------------------------------------------------------------------------

info "Checking out ${SVN_URL}"
svn checkout --quiet --non-interactive "${SVN_URL}" "${WC}" > /dev/null
for top in trunk tags assets; do
	if [[ ! -d "${WC}/${top}" ]]; then
		svn mkdir --quiet "${WC}/${top}" > /dev/null
		svn_w commit -m "Create ${top}" "${WC}/${top}" > /dev/null
	fi
done

info "[folders] creating the empty folders in trunk"
while IFS= read -r dir; do
	mkdir -p "${WC}/trunk/${dir}"
	svn add --force --quiet --depth empty "${WC}/trunk/${dir}" > /dev/null
done < <(cd "${PLUGIN_DIR}" && find . -mindepth 1 -type d | sed 's|^\./||' | LC_ALL=C sort)
commit_path "Version ${VERSION}: create the folders" "${WC}/trunk"

total="${#BATCHES[@]}"
index=0
for entry in "${BATCHES[@]}"; do
	index=$((index + 1))
	mode="${entry%%|*}"
	rel="${entry#*|}"
	src="${PLUGIN_DIR}${rel:+/$rel}"
	dest="${WC}/trunk${rel:+/$rel}"
	mkdir -p "${dest}"
	if [[ "${mode}" == "tree" ]]; then
		info "[${index}/${total}] ${rel} ($(count_files "${src}") files)"
		rsync -a --delete --exclude='.svn' "${src}/" "${dest}/"
		register "${dest}"
		commit_path "Version ${VERSION}: ${rel}" "${dest}"
	else
		info "[${index}/${total}] ${rel:-top level}: files in the folder itself"
		rsync -a --delete --exclude='.svn' --exclude='/*/' "${src}/" "${dest}/"
		register "${dest}" files
		commit_path "Version ${VERSION}: ${rel:-top level} files" "${dest}" files
	fi
done

# Folders that no longer exist in the plugin are not part of any batch above, so remove them here.
info "[cleanup] removing files and folders that are no longer in the plugin"
while IFS= read -r path; do
	[[ -e "${path}" ]] || continue
	rel="${path#"${WC}/trunk/"}"
	[[ -e "${PLUGIN_DIR}/${rel}" ]] && continue
	svn rm --quiet --force "${path}" > /dev/null
done < <(find "${WC}/trunk" -mindepth 1 -name .svn -prune -o -print | LC_ALL=C sort)
commit_path "Version ${VERSION}: remove what is no longer in the plugin" "${WC}/trunk"

info "Checking that trunk on the server equals the plugin folder"
rm -rf "${WORK}/export"
svn export --quiet --non-interactive "${SVN_URL}/trunk" "${WORK}/export" > /dev/null
if ! diff -r -q "${PLUGIN_DIR}" "${WORK}/export"; then
	die "Trunk on the server differs from ${PLUGIN_DIR}. Run the script again; it commits only what differs."
fi
ok "trunk is identical to the plugin folder"

if svn ls --non-interactive "${SVN_URL}/tags/${VERSION}" > /dev/null 2>&1; then
	info "tags/${VERSION} already exists; it is left as it is"
else
	info "Creating tags/${VERSION}"
	svn_w copy -m "Tag version ${VERSION}" "${SVN_URL}/trunk" "${SVN_URL}/tags/${VERSION}" > /dev/null
	ok "tags/${VERSION} created"
fi

if [[ -n "${ASSETS_DIR}" ]]; then
	info "Assets ($(count_files "${ASSETS_DIR}") files)"
	rsync -a --delete --exclude='.svn' "${ASSETS_DIR}/" "${WC}/assets/"
	register "${WC}/assets"
	while IFS= read -r file; do
		case "${file##*.}" in
			png) mime="image/png" ;;
			jpg | jpeg) mime="image/jpeg" ;;
			gif) mime="image/gif" ;;
			svg) mime="image/svg+xml" ;;
			*) continue ;;
		esac
		svn propset --quiet svn:mime-type "${mime}" "${file}" > /dev/null
	done < <(find "${WC}/assets" -type f -not -path '*/.svn/*' | LC_ALL=C sort)
	commit_path "Version ${VERSION}: icons, banners and screenshots" "${WC}/assets"
fi

ok "Done: trunk, tags/${VERSION} and assets are on WordPress.org"
