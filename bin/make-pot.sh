#!/usr/bin/env bash
# Creates languages/estasoft-erp-crm-sync-engine.pot with WP-CLI.
#
#   bin/make-pot.sh
#
# Run `npm run build` first so the strings in the compiled admin app are included (the TypeScript source
# is not scanned). Needs WP-CLI (https://wp-cli.org). Without it the script prints the exact command and
# exits with 77 ("skipped"); it never installs anything.
#
# The plugin is English only for now. WordPress.org builds translation packs from the .pot through
# translate.wordpress.org, so the file is also committed for contributors.

set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

POT="${ROOT_DIR}/languages/${SLUG}.pot"
COMMAND=(wp i18n make-pot "${ROOT_DIR}" "${POT}"
	"--slug=${SLUG}"
	"--domain=${SLUG}"
	"--package-name=Estasoft Enterprise ERP/CRM Sync Engine"
	"--headers={\"Report-Msgid-Bugs-To\":\"https://wordpress.org/support/plugin/${SLUG}/\"}"
	"--exclude=node_modules,vendor,tests,bin,docs,src,dist,plugin-build,.github,.wordpress-org,assets"
	"--skip-audit")

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
	print_usage "${BASH_SOURCE[0]}"
	exit 0
fi

if ! command -v wp > /dev/null 2>&1; then
	warn "WP-CLI (wp) is not installed, so no .pot file was written. Install it, then run:"
	printf '  %q ' "${COMMAND[@]}"
	printf '\n'
	exit "${EXIT_SKIPPED}"
fi
if ! wp i18n --help > /dev/null 2>&1; then
	warn "WP-CLI is installed but the i18n command is missing. Run: wp package install wp-cli/i18n-command"
	exit "${EXIT_SKIPPED}"
fi
if [[ ! -d "${ROOT_DIR}/build" ]]; then
	warn "build/ does not exist: strings from the admin app will be missing. Run npm run build first."
fi

"${COMMAND[@]}"
ok "Wrote ${POT#"${ROOT_DIR}"/}"
