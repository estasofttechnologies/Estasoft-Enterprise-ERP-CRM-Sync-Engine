#!/usr/bin/env bash
# Sets the release version everywhere it has to appear.
#
#   bin/bump-version.sh <x.y.z> [--date YYYY-MM-DD]
#
# Updates: the Version line in the plugin header, the ESTASOFT_SYNC_VERSION constant, Stable tag in readme.txt,
# the version in package.json and package-lock.json, and adds a CHANGELOG.md section (the content of
# [Unreleased] moves under the new heading). It also adds a "= x.y.z =" stub to the Changelog and Upgrade
# Notice sections of readme.txt. The stubs contain a placeholder sentence that bin/check-readme.php and
# bin/build-zip.sh reject, so a release cannot ship before a person has written the notes.
#
# It does not touch git. Review the diff, commit, then tag v<x.y.z>.

set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

NEW=""
DATE="$(date -u +%Y-%m-%d)"
while [[ $# -gt 0 ]]; do
	case "$1" in
		--date)
			[[ $# -ge 2 ]] || die "--date needs a value."
			DATE="$2"
			shift
			;;
		-h | --help)
			print_usage "${BASH_SOURCE[0]}"
			exit 0
			;;
		-*) die "Unknown option: $1 (try --help)" ;;
		*)
			[[ -z "${NEW}" ]] || die "Only one version may be given."
			NEW="$1"
			;;
	esac
	shift
done

[[ -n "${NEW}" ]] || die "Usage: bin/bump-version.sh <x.y.z> [--date YYYY-MM-DD]"
is_semver "${NEW}" || die "'${NEW}' is not a version like 1.2.3 (a suffix such as -rc.1 is allowed)."
[[ "${DATE}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || die "'${DATE}' is not a date like 2026-11-02."

OLD="$(plugin_header Version)"
[[ -n "${OLD}" ]] || die "Cannot read the current version from the plugin header."
if [[ "${OLD}" == "${NEW}" ]]; then
	die "The version is already ${NEW}."
fi
if [[ "$(printf '%s\n%s\n' "${OLD}" "${NEW}" | sort -V | tail -n 1)" != "${NEW}" ]]; then
	die "${NEW} is lower than the current version ${OLD}."
fi
if [[ "$(printf '%s\n' "${NEW}" | grep -c .)" -ne 1 ]]; then
	die "Invalid version."
fi

cd "${ROOT_DIR}"
REPO_URL="https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine"

# replace_in_file <file> <sed-expression>: portable in-place edit that fails when nothing changed.
replace_in_file() {
	local file="$1" expr="$2" tmp
	tmp="$(mktemp)"
	sed -E "${expr}" "${file}" > "${tmp}"
	if cmp -s "${file}" "${tmp}"; then
		rm -f "${tmp}"
		die "Nothing changed in ${file#"${ROOT_DIR}"/} for: ${expr}"
	fi
	cat "${tmp}" > "${file}"
	rm -f "${tmp}"
}

info "Bumping ${OLD} -> ${NEW}"

replace_in_file "${PLUGIN_FILE}" "1,40s/^([[:space:]]*\\*?[[:space:]]*Version:[[:space:]]*)${OLD//./\\.}[[:space:]]*\$/\\1${NEW}/"
replace_in_file "${PLUGIN_FILE}" "s/^([[:space:]]*define\\([[:space:]]*'ESTASOFT_SYNC_VERSION'[[:space:]]*,[[:space:]]*')${OLD//./\\.}('.*)\$/\\1${NEW}\\2/"
replace_in_file "${README_FILE}" "s/^(Stable tag:[[:space:]]*)${OLD//./\\.}[[:space:]]*\$/\\1${NEW}/"

if [[ -f package.json ]]; then
	require_cmd node "needed to edit package.json"
	node - "${NEW}" << 'JS'
const fs = require('fs');
const version = process.argv[2];
for (const file of ['package.json', 'package-lock.json']) {
	if (!fs.existsSync(file)) { continue; }
	const data = JSON.parse(fs.readFileSync(file, 'utf8'));
	data.version = version;
	if (data.packages && data.packages['']) { data.packages[''].version = version; }
	fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n');
}
JS
fi

# CHANGELOG.md: new section under [Unreleased]; its existing content becomes the new section's content.
if [[ -f CHANGELOG.md ]]; then
	tmp="$(mktemp)"
	awk -v ver="${NEW}" -v date="${DATE}" -v old="${OLD}" -v repo="${REPO_URL}" '
		{ lines[NR] = $0 }
		END {
			for (i = 1; i <= NR; i++) {
				line = lines[i]
				if (line ~ /^\[Unreleased\]:/) {
					print "[Unreleased]: " repo "/compare/v" ver "...HEAD"
					print "[" ver "]: " repo "/compare/v" old "...v" ver
					continue
				}
				print line
				if (line ~ /^## \[Unreleased\]/) {
					print ""
					print "## [" ver "] - " date
					# If nothing sits between here and the next heading or link reference, add the stub.
					j = i + 1
					while (j <= NR && lines[j] ~ /^[[:space:]]*$/) { j++ }
					if (j > NR || lines[j] ~ /^## \[/ || lines[j] ~ /^\[[^]]+\]:/) {
						print ""
						print "Describe the changes in this release."
					}
				}
			}
		}
	' CHANGELOG.md > "${tmp}"
	cat "${tmp}" > CHANGELOG.md
	rm -f "${tmp}"
else
	warn "CHANGELOG.md not found; skipped."
fi

# readme.txt: stubs at the top of Changelog and Upgrade Notice.
tmp="$(mktemp)"
awk -v ver="${NEW}" '
	{ print }
	/^== Changelog ==[[:space:]]*$/ {
		print ""
		print "= " ver " ="
		print "* Describe the changes in this release."
	}
	/^== Upgrade Notice ==[[:space:]]*$/ {
		print ""
		print "= " ver " ="
		print "Describe the changes in this release."
	}
' "${README_FILE}" > "${tmp}"
cat "${tmp}" > "${README_FILE}"
rm -f "${tmp}"

verify_versions "${NEW}" || die "The files do not agree after the bump. Check the diff."
info ""
info "Done. Next: write the real notes in CHANGELOG.md and readme.txt (the placeholder text blocks bin/build-zip.sh),"
info "run bin/check-all.sh, commit, then tag v${NEW}."
