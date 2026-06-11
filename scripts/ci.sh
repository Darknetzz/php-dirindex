#!/usr/bin/env bash
# Local CI checks (replacement for .github/workflows/ci.yml).
#
# Usage (from repo root):
#   ./scripts/ci.sh

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> Building index.min.php"
php scripts/build-min.php

echo "==> Syntax check"
php -l index.php
php -l index.min.php

echo "==> Verifying index.min.php is up to date"
php scripts/build-min.php --check

echo "CI checks passed."
