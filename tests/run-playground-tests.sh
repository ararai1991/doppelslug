#!/usr/bin/env bash
# Runs Doppelslug's integration tests against a running WordPress Playground.
#
# Start Playground first (from the repo root):
#   MSYS_NO_PATHCONV=1 npx @wp-playground/cli@3.1.54 server --port=9455 \
#     --mount-dir "$PWD/doppelslug" /wordpress/wp-content/plugins/doppelslug \
#     --mount-dir "$PWD/tests/playground" /wordpress/doppelslug-tests \
#     --blueprint=tests/playground/blueprint.json
#
# Then: bash tests/run-playground-tests.sh [base-url]

set -u

BASE="${1:-http://127.0.0.1:9455}"
TESTS="$BASE/doppelslug-tests/doppelslug-tests.php"
FAILED=0

run() { curl -s "$TESTS?action=$1${2:-}"; }

# expect <label> <path> <expected status> [expected Location suffix]
expect() {
	local label="$1" path="$2" want_code="$3" want_location="${4:-}"
	local out code location
	out="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE$path")"
	code="${out%% *}"
	location="${out#* }"

	if [ "$code" = "$want_code" ] && { [ -z "$want_location" ] || [ "${location%"$want_location"}" != "$location" ]; }; then
		echo "PASS  $label ($code $location)"
	else
		echo "FAIL  $label: got $code $location, want $want_code $want_location"
		FAILED=$((FAILED + 1))
	fi
}

echo "== Setup"
run setup >/dev/null && echo "fixtures created"

echo "== Server-side tests"
unit="$(run unit)"
echo "$unit"
case "$unit" in *"ALL PASSED"*) ;; *) FAILED=$((FAILED + 1)) ;; esac

echo "== Front end: WordPress default"
run mode '&value=default' >/dev/null
expect "partial address with one match redirects" "/how-write-b/" 301 "/how-write-book/"

run publish '&key=blog' >/dev/null
guess="$(run core_guess '&address=how-write-b')"
expect "ambiguous address goes where core's query says ($guess)" "/how-write-b/" 301 "${guess#"$BASE"}"

echo "== Front end: redirect only when one post matches"
run mode '&value=unique' >/dev/null
expect "ambiguous address shows Not Found" "/how-write-b/" 404
expect "unique partial redirects to the book" "/how-write-boo/" 301 "/how-write-book/"
expect "unique partial redirects to the blog" "/how-write-bl/" 301 "/how-write-blog/"
expect "exact slug under a wrong path still redirects" "/old-section/how-write-book/" 301 "/how-write-book/"
expect "paged partial keeps the page number" "/how-write-boo/2/" 301 "/how-write-book/2/"

echo "== Front end: exact matches only"
run mode '&value=exact' >/dev/null
expect "partial address shows Not Found" "/how-write-boo/" 404
expect "exact slug under a wrong path still redirects" "/old-section/how-write-book/" 301 "/how-write-book/"

echo "== Front end: never redirect"
run mode '&value=off' >/dev/null
expect "partial address shows Not Found" "/how-write-boo/" 404
expect "exact slug under a wrong path shows Not Found" "/old-section/how-write-book/" 404

run mode '&value=default' >/dev/null

echo "== Languages"
i18n="$(run i18n)"
echo "$i18n"
case "$i18n" in *"ALL PASSED"*) ;; *) FAILED=$((FAILED + 1)) ;; esac

echo "== Uninstall"
uninstall="$(run uninstall)"
echo "$uninstall"
case "$uninstall" in *FAIL*) FAILED=$((FAILED + 1)) ;; esac

echo "== PHP notices"
log="$(run debuglog)"
if printf '%s' "$log" | grep -qi "plugins/doppelslug/"; then
	echo "FAIL  debug.log mentions doppelslug:"
	printf '%s\n' "$log" | grep -i "plugins/doppelslug/"
	FAILED=$((FAILED + 1))
else
	echo "PASS  no notices from doppelslug in debug.log"
fi

echo
if [ "$FAILED" -eq 0 ]; then echo "ALL CHECKS PASSED"; else echo "$FAILED CHECK GROUP(S) FAILED"; exit 1; fi
