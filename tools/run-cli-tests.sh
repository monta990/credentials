#!/usr/bin/env bash
# Run all PHP CLI syntax and regression checks under the exact CI matrix runtime.
set -euo pipefail

EXPECTED_MINOR="${1:-}"
if [[ ! "$EXPECTED_MINOR" =~ ^8\.(3|4|5|6)$ ]]; then
    echo "Usage: bash tools/run-cli-tests.sh <8.3|8.4|8.5|8.6>" >&2
    exit 2
fi

export CREDENTIALS_EXPECTED_PHP_MINOR="$EXPECTED_MINOR"
php tests/php/cli_runtime_test.php

while IFS= read -r -d '' file; do
    php -l "$file"
done < <(find . -type f -name '*.php' -not -path './vendor/*' -print0)

shopt -s nullglob
php_tests=(tests/php/*_test.php)
if ((${#php_tests[@]} == 0)); then
    echo "No PHP regression tests found." >&2
    exit 1
fi
for test_file in "${php_tests[@]}"; do
    if [[ "$test_file" == 'tests/php/cli_runtime_test.php' ]]; then
        continue
    fi
    php "$test_file"
done

printf 'PHP %s CLI syntax and regression tests: OK\n' "$EXPECTED_MINOR"
