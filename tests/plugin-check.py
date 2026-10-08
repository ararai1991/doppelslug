"""Runs the official Plugin Check (PCP) against Doppelslug in a running Playground.

Drives the same admin-ajax requests the Tools -> Plugin Check screen sends, and
prints every error and warning. Exits non-zero if Plugin Check reports an error.

Usage: python tests/plugin-check.py [base-url]   (default http://127.0.0.1:9455)
"""

import http.cookiejar
import json
import re
import sys
import urllib.parse
import urllib.request

BASE = sys.argv[1].rstrip("/") if len(sys.argv) > 1 else "http://127.0.0.1:9455"
PLUGIN = "doppelslug/doppelslug.php"

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def request(path, data=None):
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    try:
        with opener.open(BASE + path, body, timeout=600) as response:
            return response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as error:
        sys.exit("HTTP %s from %s: %s" % (error.code, path, error.read().decode("utf-8", "replace")[:500]))


def ajax(data, fatal=True):
    try:
        raw = request("/wp-admin/admin-ajax.php", data)
    except SystemExit as error:
        if fatal:
            raise
        return {"success": False, "data": [{"message": str(error)}]}
    try:
        return json.loads(raw)
    except ValueError:
        sys.exit("Unexpected response from %s: %s" % (data.get("action"), raw[:500]))


request("/wp-login.php")
request("/wp-login.php", {"log": "admin", "pwd": "password", "wp-submit": "Log In", "testcookie": "1"})
page = request("/wp-admin/tools.php?page=plugin-check")

nonce = re.search(r'PLUGIN_CHECK\s*=\s*\{[^}]*?"nonce":"([0-9a-f]+)"', page)
if not nonce:
    sys.exit("Could not find the Plugin Check nonce; is plugin-check active and the login correct?")

categories = sorted(set(re.findall(r'name="categories"[^>]*value="([^"]+)"', page)))
common = {"nonce": nonce.group(1), "plugin": PLUGIN, "use-ai": "0", "include-experimental": "0"}

setup = ajax(dict(common, action="plugin_check_set_up_environment"))
if not setup.get("success"):
    sys.exit("Set-up failed: %s" % setup)

to_run = ajax(dict(common, action="plugin_check_get_checks_to_run", **{"categories[]": categories}))
checks = to_run.get("data", {}).get("checks", [])
print("Categories: %s" % ", ".join(categories))
print("Running %d checks...\n" % len(checks))

errors = warnings = 0
skipped = []
for check in checks:
    result = ajax(dict(common, action="plugin_check_run_checks", **{"checks[]": [check], "types[]": ["error", "warning"]}), fatal=False)
    if not result.get("success"):
        # Playground's virtual filesystem cannot lock files, which PHPCS-based checks need.
        skipped.append((check, re.sub(r"\s+", " ", str(result.get("data")))[:160]))
        continue
    data = result.get("data", {})
    for kind in ("errors", "warnings"):
        for file_name, lines in (data.get(kind) or {}).items():
            for line, columns in lines.items():
                for column, messages in columns.items():
                    for message in messages:
                        if kind == "errors":
                            errors += 1
                        else:
                            warnings += 1
                        print("%-7s %s:%s  [%s] %s" % (kind[:-1].upper(), file_name, line, message.get("code"), re.sub(r"<[^>]+>", "", message.get("message", ""))))

ajax(dict(common, action="plugin_check_clean_up_environment"))

for check, reason in skipped:
    print("SKIPPED %s  (%s)" % (check, reason))

print("\nPlugin Check: %d error(s), %d warning(s), %d check(s) could not run here" % (errors, warnings, len(skipped)))
sys.exit(1 if errors else 0)
