#!/usr/bin/env python3
"""
Flags redundant nullsafe operators.

PHPStan narrows types through assertions and conditionals, so a `?->` on a
variable already dereferenced earlier in the same method is dead syntax — and
level max fails the build over it. This finds them before the build does, and
explains why each one is redundant rather than just naming a line.

Two cases:
  1. `$x?->prop ?? $fallback` — `??` uses isset semantics and already tolerates
     a null `$x`, so the operator does nothing. (Method calls are different:
     `??` does not rescue `null->method()`, so those are left alone.)
  2. `$x?->` where `$x` was already dereferenced earlier in the same method.
"""

import glob
import re
import sys

METHOD_START = re.compile(r'^\s{4}(?:public|private|protected|static|final|abstract)[\w\s]*function ')
NULLSAFE_BEFORE_COALESCE = re.compile(r'\?->\w+\s*(?:\[[^\]]*\]\s*)?\?\?')
NULLSAFE_CALL = re.compile(r'\?->\w+\s*\(')
DEREF = re.compile(r'(\$\w+)\?->')


def scan(path: str) -> list[tuple[int, str, str]]:
    findings = []
    seen: dict[str, int] = {}

    for number, line in enumerate(open(path, encoding='utf-8'), 1):
        if METHOD_START.match(line):
            seen = {}

        if NULLSAFE_BEFORE_COALESCE.search(line) and not NULLSAFE_CALL.search(line):
            findings.append((number, 'nullsafe on the left of ?? does nothing', line.strip()))
            continue

        for match in DEREF.finditer(line):
            variable = match.group(1)
            if variable in seen:
                findings.append((
                    number,
                    f'{variable} was already dereferenced at line {seen[variable]}',
                    line.strip(),
                ))
            else:
                seen[variable] = number

    return findings


def main() -> int:
    directories = sys.argv[1:] or ['src', 'tests']
    total = 0

    for directory in directories:
        for path in sorted(glob.glob(f'{directory}/**/*.php', recursive=True)):
            for number, why, line in scan(path):
                print(f'{path}:{number}  {why}')
                print(f'    {line}')
                total += 1

    if total:
        print(f'\n{total} redundant nullsafe operator(s). Assert non-null once, then use ->.')
        return 1

    print('No redundant nullsafe operators found.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
