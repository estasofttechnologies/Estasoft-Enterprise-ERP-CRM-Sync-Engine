#!/usr/bin/env bash
# PHP syntax check for every PHP file in the repository, plus a scan of the plugin's runtime code for
# constructs that WordPress.org reviewers reject.
#
#   bin/lint-php.sh [--no-flags]
#
# Syntax: `php -l` on every .php file outside vendor/, node_modules/ and build output. CI runs this on
# PHP 8.1, 8.2, 8.3 and 8.4, so it also catches syntax that is too new for 8.1.
#
# Red flags (runtime code only: the main file, uninstall.php and includes/; never tests; scanned by bin/red-flags.php): eval,
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
	# The scan reads PHP tokens (bin/red-flags.php), so words inside strings and comments never count.
	if ! php "${ROOT_DIR}/bin/red-flags.php" --root="${ROOT_DIR}"; then
		status=1
	fi
	# The plugin declares PHP 8.1 as its minimum; this finds syntax and functions from newer versions on any PHP.
	if ! php "${ROOT_DIR}/bin/check-php-version.php" --root="${ROOT_DIR}"; then
		status=1
	fi
fi

exit "${status}"
