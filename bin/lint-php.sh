#!/usr/bin/env bash
# PHP syntax check for every PHP file in the repository, plus a scan of the plugin's runtime code for
# constructs that WordPress.org reviewers reject.
#
#   bin/lint-php.sh [--no-flags]
#
# Syntax: `php -l` on every .php file outside vendor/, node_modules/ and build output. CI runs this on
# PHP 8.1, 8.2, 8.3 and 8.4, so it also catches syntax that is too new for 8.1.
#
# Red flags (runtime code only: the main file, uninstall.php and includes/; never tests): eval,
# base64_decode, extract, error_reporting, ini_set, shell execution, the @ error-suppression operator,
# die or exit (except `defined( 'ABSPATH' ) || exit;` and the uninstall guard), create_function, includes of
# remote files, and any outbound HTTP call outside includes/Core/Support/WpHttpClient.php (the one class
# that talks to the network, so every request obeys the same timeout and TLS rules). A hit is a failure.
# If one is legitimate, add "path:label" to bin/red-flags-allowlist.txt with a comment explaining why.
#
# Exit: 0 passed, 1 failed.

set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

CHECK_FLAGS=1
for arg in "$@"; do
	case "${arg}" in
		--no-flags) CHECK_FLAGS=0 ;;
		-h | --help)
			print_usage "${BASH_SOURCE[0]}"
			exit 0
			;;
		*) die "Unknown option: ${arg} (try --help)" ;;
	esac
done

require_cmd php
cd "${ROOT_DIR}"

status=0
count=0
while IFS= read -r file; do
	count=$((count + 1))
	if ! output="$(php -l "${file}" 2>&1)"; then
		fail "${file#./}"
		printf '%s\n' "${output}" >&2
		status=1
	fi
done < <(find . \( -path ./vendor -o -path ./node_modules -o -path ./.git -o -path ./build -o -path ./dist -o -path ./plugin-build \) -prune -o -name '*.php' -type f -print | LC_ALL=C sort)

if [[ "${status}" -eq 0 ]]; then
	ok "php -l: ${count} files, PHP $(php -r 'echo PHP_VERSION;'), no syntax errors."
fi

if [[ "${CHECK_FLAGS}" -eq 1 ]]; then
	ALLOWLIST="${ROOT_DIR}/bin/red-flags-allowlist.txt"
	RUNTIME_FILES=()
	while IFS= read -r file; do
		RUNTIME_FILES+=("${file}")
	done < <(find ./includes -name '*.php' -type f 2> /dev/null | LC_ALL=C sort; ls ./estasoft-erp-crm-sync-engine.php ./uninstall.php 2> /dev/null)

	flags=0
	# scan <label> <extended regex> [<regex of lines to ignore>]
	scan() {
		local label="$1" pattern="$2" ignore="${3:-}" hits line file
		if [[ ${#RUNTIME_FILES[@]} -eq 0 ]]; then
			return 0
		fi
		hits="$(grep -n -E "${pattern}" "${RUNTIME_FILES[@]}" 2> /dev/null || true)"
		while IFS= read -r line; do
			[[ -n "${line}" ]] || continue
			file="${line%%:*}"
			# Skip comment lines (docblocks, // and # comments).
			if [[ "${line}" =~ ^[^:]+:[0-9]+:[[:space:]]*(\*|//|#|/\*) ]]; then
				continue
			fi
			if [[ -n "${ignore}" && "${line}" =~ ${ignore} ]]; then
				continue
			fi
			if [[ -f "${ALLOWLIST}" ]] && grep -q -F -x "${file#./}:${label}" "${ALLOWLIST}"; then
				continue
			fi
			fail "${label}: ${line}"
			flags=$((flags + 1))
		done <<< "${hits}"
	}

	NOT_IDENT='(^|[^A-Za-z0-9_>:$])'
	scan "eval" "${NOT_IDENT}eval[[:space:]]*\("
	scan "base64_decode" "${NOT_IDENT}base64_decode[[:space:]]*\("
	scan "extract" "${NOT_IDENT}extract[[:space:]]*\("
	scan "error_reporting" "${NOT_IDENT}error_reporting[[:space:]]*\("
	scan "ini_set" "${NOT_IDENT}ini_set[[:space:]]*\("
	scan "shell execution" "${NOT_IDENT}(exec|shell_exec|system|passthru|popen|proc_open)[[:space:]]*\("
	scan "error suppression" "@[[:space:]]*(\\$|[A-Za-z_\\\\]+[[:space:]]*\\()"
	scan "die or exit" "${NOT_IDENT}(die|exit)[[:space:]]*(\\(|;)" "defined\( '(ABSPATH|WP_UNINSTALL_PLUGIN)' \) \|\| exit;"
	scan "create_function" "${NOT_IDENT}create_function[[:space:]]*\("
	scan "outbound HTTP outside WpHttpClient" "${NOT_IDENT}(wp_remote_(request|get|post|head)|wp_safe_remote_(request|get|post|head)|curl_(init|exec|multi_exec)|fsockopen|stream_socket_client)[[:space:]]*\\(|file_get_contents[[:space:]]*\\([[:space:]]*['\"]https?://|new[[:space:]]+\\\\?(WP_Http|SoapClient)\\b"
	scan "remote include" "(include|require)(_once)?[[:space:]]*\(?[[:space:]]*['\"]https?://"

	if [[ "${flags}" -eq 0 ]]; then
		ok "Red-flag scan: ${#RUNTIME_FILES[@]} runtime files, none of eval, base64_decode, extract, error_reporting, ini_set, shell calls, @, stray exit, remote include."
	else
		status=1
	fi
fi

exit "${status}"
