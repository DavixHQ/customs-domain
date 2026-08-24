#!/usr/bin/env bash
#
# Fails the build if platform-specific code leaks into the domain package.
#
# This package must depend on nothing but PHP and PSR interfaces. The moment it
# references a framework, it stops being portable to WordPress, Shopify, a CLI
# tool or a hosted service — which is the entire reason it exists as a separate
# package rather than a directory inside the Magento module.

set -euo pipefail

SRC_DIR="${1:-src}"

# A missing directory must fail, not pass. grep finds no violations in a path
# that does not exist, so without this check a typo or a rename reads as a
# clean result and the boundary stops being enforced silently.
if [ ! -d "$SRC_DIR" ]; then
    echo "BOUNDARY CHECK ERROR: '$SRC_DIR' is not a directory."
    exit 2
fi

PHP_FILE_COUNT=$(find "$SRC_DIR" -name '*.php' -type f | wc -l | tr -d ' ')

if [ "$PHP_FILE_COUNT" -eq 0 ]; then
    echo "BOUNDARY CHECK ERROR: no PHP files found under '$SRC_DIR'."
    exit 2
fi

# Namespaces and symbols that must never appear in src/.
BANNED=(
    'Magento\\'
    'Zend\\'
    'Laminas\\'
    'Symfony\\'
    'Illuminate\\'
    'GuzzleHttp\\'
    'Shopify\\'
    'WP_'
    'wp_'
    'ObjectManager'
    'add_action'
    'add_filter'
)

FAILED=0

for token in "${BANNED[@]}"; do
    if matches=$(grep -rn --include='*.php' -- "$token" "$SRC_DIR" 2>/dev/null); then
        echo "BOUNDARY VIOLATION: '$token' found in $SRC_DIR"
        echo "$matches" | sed 's/^/    /'
        echo
        FAILED=1
    fi
done

# PSR interfaces are the only permitted third-party dependency. Root-namespace
# classes from PHP itself (RuntimeException, DateTimeImmutable) are fine and
# have no backslash; anything namespaced that is neither ours nor PSR is not.
if matches=$(grep -rnP --include='*.php' '^use (?!Davix\\Customs\\|Psr\\)[^;]*\\' "$SRC_DIR" 2>/dev/null); then
    echo "BOUNDARY WARNING: non-PSR, non-domain imports found in $SRC_DIR"
    echo "$matches" | sed 's/^/    /'
    echo "Only Davix\\Customs\\* and Psr\\* imports are permitted."
    echo
    FAILED=1
fi

if [ "$FAILED" -eq 0 ]; then
    echo "Boundary check passed: $PHP_FILE_COUNT files in $SRC_DIR are free of platform-specific code."
fi

exit "$FAILED"