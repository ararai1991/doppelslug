"""Builds dist/doppelslug-<version>.zip, the file to upload to WordPress.org.

Only the doppelslug/ folder goes into the ZIP, so tests and dev tooling never ship.

Usage: python bin/build-zip.py
"""

import pathlib
import re
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
PLUGIN = ROOT / "doppelslug"
SKIP = {".DS_Store", "Thumbs.db"}

header = (PLUGIN / "doppelslug.php").read_text(encoding="utf-8")
readme = (PLUGIN / "readme.txt").read_text(encoding="utf-8")

version = re.search(r"^\s*\*\s*Version:\s*(\S+)", header, re.M).group(1)
constant = re.search(r"define\(\s*'DOPPELSLUG_VERSION',\s*'([^']+)'", header).group(1)
stable = re.search(r"^Stable tag:\s*(\S+)", readme, re.M).group(1)

if len({version, constant, stable}) != 1:
    sys.exit("Version mismatch: header %s, DOPPELSLUG_VERSION %s, readme Stable tag %s" % (version, constant, stable))

target = ROOT / "dist" / ("doppelslug-%s.zip" % version)
target.parent.mkdir(exist_ok=True)

with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(PLUGIN.rglob("*")):
        if path.is_file() and path.name not in SKIP:
            archive.write(path, "doppelslug/" + path.relative_to(PLUGIN).as_posix())

with zipfile.ZipFile(target) as archive:
    names = archive.namelist()

print("Built %s (%d files, version %s)" % (target.relative_to(ROOT), len(names), version))
for name in names:
    print("  " + name)
