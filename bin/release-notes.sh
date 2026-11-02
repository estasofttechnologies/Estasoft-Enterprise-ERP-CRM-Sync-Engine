#!/usr/bin/env bash
# Prints the CHANGELOG.md section for one version (used for GitHub release notes).
#
#   bin/release-notes.sh <x.y.z>
#
# Exit 1 when the section is missing or empty.

set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

VERSION="${1:-}"
if [[ "${VERSION}" == "-h" || "${VERSION}" == "--help" ]]; then
	print_usage "${BASH_SOURCE[0]}"
	exit 0
fi
[[ -n "${VERSION}" ]] || die "Usage: bin/release-notes.sh <x.y.z>"
VERSION="${VERSION#v}"
[[ -f "${ROOT_DIR}/CHANGELOG.md" ]] || die "CHANGELOG.md not found."

NOTES="$(awk -v ver="${VERSION}" '
	/^## \[/ {
		if (found) { exit }
		if (index($0, "## [" ver "]") == 1) { found = 1; next }
	}
	/^\[[^]]+\]:/ { if (found) { exit } }
	found { print }
' "${ROOT_DIR}/CHANGELOG.md" | grep -v -E '^<!--.*-->[[:space:]]*$' || true)"

if [[ -z "$(printf '%s' "${NOTES}" | tr -d '[:space:]')" ]]; then
	die "CHANGELOG.md has no notes for ${VERSION}."
fi
if printf '%s' "${NOTES}" | grep -q "Describe the changes in this release"; then
	die "CHANGELOG.md still has the placeholder text for ${VERSION}."
fi
printf '%s\n' "${NOTES}" | sed '/./,$!d' | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}'
