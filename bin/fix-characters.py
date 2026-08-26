#!/usr/bin/env python3
"""
Rewrites typographic Unicode as plain ASCII, so a file arriving from elsewhere
can be normalised rather than argued with.

Honours exactly the same exemptions as check-characters.py: the fixture tree
diagram, the functional curly quotes in CodeNormaliser, and everything under
tests/Fixtures/Api/, which must stay byte-identical to what the API sent.
"""

import glob
import sys


BANNED = {
    "\u2014": "-",
    "\u2013": "-",
    "\u2026": "...",
    "\u2192": "->",
    "\u00ab": '"',
    "\u00bb": '"',
}

EXEMPT = {
    "src/Validation/CodeNormaliser.php",
    "tests/Validation/CodeNormaliserTest.php",
}


def main() -> int:
    paths = (
        glob.glob("src/**/*.php", recursive=True)
        + glob.glob("tests/**/*.php", recursive=True)
        + ["README.md", "CHANGELOG.md"]
    )

    changed = []

    for path in sorted(paths):
        if "/Fixtures/Api/" in path or path in EXEMPT:
            continue

        try:
            text = open(path, encoding="utf-8").read()
        except (IsADirectoryError, FileNotFoundError, UnicodeDecodeError):
            continue

        original = text

        for banned, replacement in BANNED.items():
            text = text.replace(banned, replacement)

        if text != original:
            open(path, "w", encoding="utf-8").write(text)
            changed.append(path)

    if changed:
        for path in changed:
            print(f"  rewritten {path}")
        print(f"\n{len(changed)} file(s) normalised.")
    else:
        print("Nothing to rewrite.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())