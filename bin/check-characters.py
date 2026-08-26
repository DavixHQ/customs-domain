#!/usr/bin/env python3
"""
Fails the build if typographic Unicode creeps into code or documentation.

Em dashes, en dashes, ellipses and arrows are fine in prose written for
readers who like them. This project does not, and a rule enforced by hand gets
undone every time a file is regenerated from somewhere that does not know
about it. A build gate does not forget.

Deliberately narrow. Three kinds of non-ASCII are legitimate here and are left
alone:

  - Box-drawing characters and check marks, which draw the nomenclature tree
    diagram in the chapter fixture. That diagram is the clearest explanation in
    the codebase of why commodity codes repeat, and redrawing it in ASCII would
    make it worse.

  - Curly quotes in CodeNormaliser and its test, which are functional. They sit
    in the QUOTING constant that strips smart quotes from codes pasted out of a
    spreadsheet, and the test feeds them as input. Replacing them would delete
    the behaviour.

  - Everything under tests/Fixtures/Api/, which is recorded API responses. Those
    must stay byte-identical to what the service sent, non-breaking spaces and
    all - the weight parser depends on finding them.
"""

import glob
import sys

# Characters that must not appear, and what to write instead.
BANNED = {
    "\u2014": "-",     # em dash
    "\u2013": "-",     # en dash
    "\u2026": "...",   # horizontal ellipsis
    "\u2192": "->",    # rightwards arrow
    "\u00ab": '"',     # guillemets
    "\u00bb": '"',
}

# Files where a banned-looking character is doing real work.
EXEMPT = {
    "src/Validation/CodeNormaliser.php",
    "tests/Validation/CodeNormaliserTest.php",
}


def targets() -> list[str]:
    paths = (
        glob.glob("src/**/*.php", recursive=True)
        + glob.glob("tests/**/*.php", recursive=True)
        + ["README.md", "CHANGELOG.md"]
    )

    return sorted(
        p for p in paths
        if "/Fixtures/Api/" not in p and p not in EXEMPT
    )


def main() -> int:
    findings = []

    for path in targets():
        try:
            lines = open(path, encoding="utf-8").read().split("\n")
        except (IsADirectoryError, FileNotFoundError, UnicodeDecodeError):
            continue

        for number, line in enumerate(lines, 1):
            for banned, replacement in BANNED.items():
                if banned in line:
                    findings.append((path, number, banned, replacement, line.strip()))

    if not findings:
        print(f"Character check passed: {len(targets())} files use plain ASCII punctuation.")
        return 0

    for path, number, banned, replacement, line in findings:
        print(f"{path}:{number}  U+{ord(banned):04X} {banned!r} - write {replacement!r} instead")
        print(f"    {line[:100]}")

    print(f"\n{len(findings)} occurrence(s). Run: python3 bin/fix-characters.py")

    return 1


if __name__ == "__main__":
    raise SystemExit(main())