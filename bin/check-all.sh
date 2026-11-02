#!/usr/bin/env bash
# Runs every check that can run on this machine and says what it skipped and why.
#
#   bin/check-all.sh [-v|--verbose] [--fast] [--no-integration] [--no-js]
#
#   -v, --verbose      show all output (by default a failing step shows its first 40 lines)
#   --fast             skip the integration tests and the JavaScript build
#   --no-integration   skip the integration tests (they need MySQL or MariaDB and a WordPress copy)
#   --no-js            skip every Node step
#
# Steps, in order: PHP syntax and red flags, version/readme/release file selection (build-zip --dry-run),
# .gitattributes against .distignore,
# documentation links, shell scripts (shellcheck), GitHub workflow files (YAML + actionlint), PHPCS, PHPStan,
# PHPUnit unit tests, PHPUnit integration tests, then the npm scripts the project defines.
#
# Integration tests drop and recreate the database named by WP_DB_NAME (default estasoft_checkall, so it
# cannot hit another database by accident). Set WP_CORE_DIR, WP_DB_* and WP_TEST_CONTENT to point elsewhere.
#
# Exit: 0 when nothing failed (skips are listed, not failures), 1 when a step failed.

# Not `set -e`: a failing step must not stop the run, run_step records it.
# Step functions are called indirectly through run_step.
# shellcheck disable=SC2329
set -uo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

VERBOSE=0
RUN_INTEGRATION=1
RUN_JS=1
for arg in "$@"; do
	case "${arg}" in
		-v | --verbose) VERBOSE=1 ;;
		--fast)
			RUN_INTEGRATION=0
			RUN_JS=0
			;;
		--no-integration) RUN_INTEGRATION=0 ;;
		--no-js) RUN_JS=0 ;;
		-h | --help)
			print_usage "${BASH_SOURCE[0]}"
			exit 0
			;;
		*)
			fail "Unknown option: ${arg} (try --help)"
			exit 2
			;;
	esac
done

cd "${ROOT_DIR}" || exit 2
LOG_DIR="$(mktemp -d "${TMPDIR:-/tmp}/estasoft-check.XXXXXX")"
trap 'rm -rf "${LOG_DIR}"' EXIT

RESULTS=()
FAILED=0
STEP=0

record() { RESULTS+=("$1|$2|$3"); }

# run_step <label> <command...>: runs it, keeps the output, records PASS, FAIL or SKIP (exit 77).
run_step() {
	local label="$1"
	shift
	STEP=$((STEP + 1))
	local log="${LOG_DIR}/step-${STEP}.log" code=0
	info "-> ${label}"
	"$@" > "${log}" 2>&1 || code=$?
	if [[ "${code}" -eq 0 ]]; then
		record "PASS" "${label}" ""
		[[ "${VERBOSE}" -eq 1 ]] && sed 's/^/     /' "${log}"
	elif [[ "${code}" -eq "${EXIT_SKIPPED}" ]]; then
		local why
		why="$(tail -n 1 "${log}")"
		record "SKIP" "${label}" "${why}"
		sed 's/^/     /' "${log}"
	else
		record "FAIL" "${label}" "exit ${code}"
		FAILED=1
		local total
		total="$(wc -l < "${log}" | tr -d '[:space:]')"
		if [[ "${VERBOSE}" -eq 1 || "${total}" -le 40 ]]; then
			sed 's/^/     /' "${log}"
		else
			head -n 40 "${log}" | sed 's/^/     /'
			printf '     ... %s more lines. Run the step by itself for the full output: %s\n' "$((total - 40))" "$*"
		fi
	fi
	return 0
}

skip_step() {
	STEP=$((STEP + 1))
	record "SKIP" "$1" "$2"
	info "-> $1 (skipped: $2)"
}

# Tools that live in vendor/bin when Composer has been run, or on the PATH.
tool() {
	if [[ -x "${ROOT_DIR}/vendor/bin/$1" ]]; then
		printf '%s' "${ROOT_DIR}/vendor/bin/$1"
	elif command -v "$1" > /dev/null 2>&1; then
		command -v "$1"
	fi
}

# --- Steps ---------------------------------------------------------------------------------------------

step_workflows() {
	local files=(.github/workflows/*.yml .github/dependabot.yml .github/ISSUE_TEMPLATE/*.yml)
	if ! command -v python3 > /dev/null 2>&1 || ! python3 -c 'import yaml' 2> /dev/null; then
		echo "python3 with PyYAML is not installed (pip install pyyaml)"
		return "${EXIT_SKIPPED}"
	fi
	python3 - "${files[@]}" << 'PY' || return 1
import sys, yaml
bad = 0
for path in sys.argv[1:]:
    try:
        with open(path, encoding="utf-8") as handle:
            yaml.safe_load(handle)
        print("ok   YAML parses: " + path)
    except Exception as error:
        print("FAIL " + path + ": " + str(error))
        bad = 1
sys.exit(bad)
PY
	if command -v actionlint > /dev/null 2>&1; then
		actionlint .github/workflows/*.yml && echo "ok   actionlint: no findings"
	else
		echo "actionlint is not installed (pip install actionlint-py); only YAML syntax was checked"
		return "${EXIT_SKIPPED}"
	fi
}

step_shellcheck() {
	if ! command -v shellcheck > /dev/null 2>&1; then
		echo "shellcheck is not installed (apt install shellcheck, or pip install shellcheck-py)"
		return "${EXIT_SKIPPED}"
	fi
	shellcheck -x -P SCRIPTDIR bin/*.sh && echo "ok   shellcheck: bin/*.sh"
}

step_phpcs() {
	local bin
	bin="$(tool phpcs)"
	if [[ -z "${bin}" ]]; then
		echo "phpcs is not installed here (composer install); CI runs it"
		return "${EXIT_SKIPPED}"
	fi
	if ! "${bin}" -i 2> /dev/null | grep -q "WordPress"; then
		echo "phpcs is installed but the WordPress coding standard is not (composer install); CI runs it"
		return "${EXIT_SKIPPED}"
	fi
	"${bin}" -q
}

step_phpstan() {
	local bin
	bin="$(tool phpstan)"
	if [[ -z "${bin}" ]]; then
		echo "phpstan is not installed here (composer install); CI runs it"
		return "${EXIT_SKIPPED}"
	fi
	"${bin}" analyse --memory-limit=1G --no-progress
}

step_unit() {
	local bin
	bin="$(tool phpunit)"
	if [[ -z "${bin}" ]]; then
		echo "phpunit is not installed (composer install, or apt install phpunit)"
		return "${EXIT_SKIPPED}"
	fi
	"${bin}" -c phpunit.xml.dist
}

step_integration() {
	local core="${WP_CORE_DIR:-/opt/devenv/wp-deb/usr/share/wordpress}"
	local bin
	bin="$(tool phpunit)"
	if [[ -z "${bin}" ]]; then
		echo "phpunit is not installed"
		return "${EXIT_SKIPPED}"
	fi
	if [[ ! -f "${core}/wp-settings.php" ]]; then
		echo "no WordPress copy at ${core} (set WP_CORE_DIR, or run bin/install-wp-core.sh latest <dir>)"
		return "${EXIT_SKIPPED}"
	fi
	# shellcheck disable=SC2016
	if ! php -r '
		if ( ! class_exists( "mysqli" ) ) { exit( 1 ); }
		mysqli_report( MYSQLI_REPORT_OFF );
		$l = @new mysqli( getenv( "WP_DB_HOST" ) ?: "localhost", getenv( "WP_DB_USER" ) ?: "root", getenv( "WP_DB_PASSWORD" ) ?: "" );
		exit( $l->connect_errno ? 1 : 0 );
	' 2> /dev/null; then
		echo "cannot connect to MySQL or MariaDB (set WP_DB_HOST, WP_DB_USER, WP_DB_PASSWORD) or the mysqli extension is missing"
		return "${EXIT_SKIPPED}"
	fi
	export WP_DB_NAME="${WP_DB_NAME:-estasoft_checkall}"
	export WP_TEST_CONTENT="${WP_TEST_CONTENT:-${TMPDIR:-/tmp}/estasoft-wpc-checkall}"
	export WP_CORE_DIR="${core}"
	php bin/install-test-wp.php && "${bin}" -c phpunit-integration.xml.dist
}

step_npm_script() {
	local script="$1"
	if ! command -v npm > /dev/null 2>&1; then
		echo "npm is not installed"
		return "${EXIT_SKIPPED}"
	fi
	if [[ ! -d node_modules ]]; then
		echo "node_modules is missing (run npm ci)"
		return "${EXIT_SKIPPED}"
	fi
	if ! npm_script_exists "${script}"; then
		echo "package.json has no '${script}' script"
		return "${EXIT_SKIPPED}"
	fi
	npm run --silent "${script}"
}

# --- Run -----------------------------------------------------------------------------------------------

run_step "PHP syntax and red flags (bin/lint-php.sh)" bin/lint-php.sh
run_step "Versions, readme.txt and release file selection (bin/build-zip.sh --dry-run)" bin/build-zip.sh --dry-run
run_step "git archive and the release zip agree (bin/check-dist-parity.sh)" bin/check-dist-parity.sh
if command -v php > /dev/null 2>&1; then
	run_step "Documentation links and doc index (bin/check-docs.php)" php bin/check-docs.php
else
	skip_step "Documentation links and doc index" "php not found"
fi
run_step "Shell scripts (shellcheck)" step_shellcheck
run_step "GitHub workflow files (YAML, actionlint)" step_workflows
run_step "PHPCS (WordPress Coding Standards)" step_phpcs
run_step "PHPStan" step_phpstan
run_step "PHPUnit unit tests" step_unit
if [[ "${RUN_INTEGRATION}" -eq 1 ]]; then
	run_step "PHPUnit integration tests (real WordPress and database)" step_integration
else
	skip_step "PHPUnit integration tests" "--fast or --no-integration"
fi
if [[ "${RUN_JS}" -eq 1 ]]; then
	for script in lint:js lint:css typecheck test:js build; do
		run_step "npm run ${script}" step_npm_script "${script}"
	done
else
	skip_step "npm scripts (lint:js, lint:css, typecheck, test:js, build)" "--fast or --no-js"
fi

# --- Summary -------------------------------------------------------------------------------------------

info ""
info "================ Summary ================"
pass=0
skipped=0
failed=0
for row in "${RESULTS[@]}"; do
	status="${row%%|*}"
	rest="${row#*|}"
	label="${rest%%|*}"
	detail="${rest#*|}"
	case "${status}" in
		PASS)
			pass=$((pass + 1))
			printf '%sPASS%s  %s\n' "${C_GREEN}" "${C_OFF}" "${label}"
			;;
		SKIP)
			skipped=$((skipped + 1))
			printf '%sSKIP%s  %s\n        %s\n' "${C_YELLOW}" "${C_OFF}" "${label}" "${detail}"
			;;
		FAIL)
			failed=$((failed + 1))
			printf '%sFAIL%s  %s (%s)\n' "${C_RED}" "${C_OFF}" "${label}" "${detail}"
			;;
	esac
done
info "-----------------------------------------"
info "${pass} passed, ${skipped} skipped, ${failed} failed"
if [[ "${skipped}" -gt 0 ]]; then
	info "Skipped steps did not run on this machine. CI runs PHPCS, PHPStan, the matrix and the Plugin Check."
fi
exit "${FAILED}"
