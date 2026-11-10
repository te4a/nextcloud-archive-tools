"""Collect literal application messages for the native Nextcloud l10n catalogs."""

import ast
import json
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
messages = set()
plurals = set()
for path in (root / "app/src").rglob("*"):
    if path.suffix not in (".ts", ".vue"):
        continue
    text = path.read_text(encoding="utf-8")
    literal = r"('(?:\\.|[^'\\])*')"
    for pattern in (r"\btr\(" + literal, r"\bt\('archive_tools',\s*" + literal):
        for match in re.finditer(pattern, text):
            messages.add(ast.literal_eval(match.group(1)))
    for match in re.finditer(r"\bn\('archive_tools',\s*" + literal + r",\s*" + literal, text):
        plurals.add((ast.literal_eval(match.group(1)), ast.literal_eval(match.group(2))))

destination = root / "app/src/messages.json"
destination.write_text(json.dumps({
    "messages": sorted(messages),
    "plurals": [{"singular": singular, "plural": plural} for singular, plural in sorted(plurals)],
}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(f"Collected {len(messages)} messages and {len(plurals)} plural pairs.")
