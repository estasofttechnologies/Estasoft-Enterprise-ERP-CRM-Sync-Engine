#!/usr/bin/env bash
# Checks that .gitattributes (export-ignore, used by `git archive` and GitHub's source downloads) and
# .distignore (used by bin/build-zip.sh and the 10up deploy action) leave the same files in.
#
#   bin/check-dist-parity.sh
#
# It copies the working tree (without .git, node_modules and build output) into a scratch folder, makes a
# throwaway git repository there, runs `git archive`, and compares the file list with the one
# `bin/build-zip.sh --dry-run` prints. Your own repository is not touched. The generated build/ folder is left
# out of the comparison because it is not tracked.
#
# Exit: 0 same files, 1 different, 77 git or rsync missing.

set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
	print_usage "${BASH_SOURCE[0]}"
	exit 0
fi

for tool in git rsync; do
	if ! command -v "${tool}" > /dev/null 2>&1; then
		warn "${tool} is not installed; cannot compare."
		exit "${EXIT_SKIPPED}"
	fi
done

SCRATCH="$(mktemp -d "${TMPDIR:-/tmp}/estasoft-parity.XXXXXX")"
trap 'rm -rf "${SCRATCH}"' EXIT

rsync -a --exclude .git --exclude node_modules --exclude build --exclude dist --exclude plugin-build "${ROOT_DIR}/" "${SCRATCH}/tree/"
(
	cd "${SCRATCH}/tree"
	git init -q .
	git -c user.name=scratch -c user.email=scratch@example.invalid add -A > /dev/null 2>&1
	git -c user.name=scratch -c user.email=scratch@example.invalid commit -q -m scratch
	git archive HEAD | tar -t | grep -v '/$' | LC_ALL=C sort > "${SCRATCH}/archive.txt"
)

"${ROOT_DIR}/bin/build-zip.sh" --dry-run 2> /dev/null \
	| sed -n -E "s#^  ${SLUG}/(.+)\$#\\1#p" \
	| grep -v '^build/' \
	| LC_ALL=C sort > "${SCRATCH}/zip.txt" || true

if [[ ! -s "${SCRATCH}/zip.txt" ]]; then
	die "bin/build-zip.sh --dry-run produced no file list; run it by itself to see why."
fi

only_archive="$(comm -23 "${SCRATCH}/archive.txt" "${SCRATCH}/zip.txt")"
only_zip="$(comm -13 "${SCRATCH}/archive.txt" "${SCRATCH}/zip.txt")"
status=0
if [[ -n "${only_archive}" ]]; then
	fail ".gitattributes keeps these in git archive but .distignore leaves them out of the zip (add export-ignore):"
	printf '%s\n' "${only_archive}" | sed 's/^/  /' >&2
	status=1
fi
if [[ -n "${only_zip}" ]]; then
	fail ".distignore keeps these in the zip but .gitattributes leaves them out of git archive (remove export-ignore):"
	printf '%s\n' "${only_zip}" | sed 's/^/  /' >&2
	status=1
fi
if [[ "${status}" -eq 0 ]]; then
	ok "git archive and the release zip contain the same $(wc -l < "${SCRATCH}/zip.txt" | tr -d '[:space:]') files."
fi
exit "${status}"
