#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
for file in public/heartshop/api/*.php scripts/heartshop/*.php scripts/dev/*.php tests/heartshop/*.php; do
  php -l "$file"
done
for file in public/heartshop/assets/*.js scripts/dev/*.js; do node --check "$file"; done
python3 tests/heartshop/core.py
python3 tests/heartshop/standalone.py
git diff --check
