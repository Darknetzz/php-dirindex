#!/usr/bin/env bash
# Rebuild index.min.php when index.php or scripts/build-min.php changed
# between two commits (or always on a fresh checkout / missing min file).
#
# Usage:
#   ./scripts/rebuild-min-if-needed.sh <oldrev> <newrev>
# Bypass: SKIP_MIN_BUILD=1

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ -n "${SKIP_MIN_BUILD:-}" ]]; then
    exit 0
fi

if [[ $# -lt 2 ]]; then
    echo "Usage: $0 <oldrev> <newrev>" >&2
    exit 1
fi

oldrev="$1"
newrev="$2"
zero="0000000000000000000000000000000000000000"
need_build=0

if [[ ! -f "$ROOT/index.min.php" ]]; then
    need_build=1
elif [[ "$oldrev" == "$zero" || -z "$oldrev" ]]; then
    # Fresh clone / first checkout of this commit range.
    need_build=1
else
    changed="$(git diff-tree -r --name-only --no-commit-id "$oldrev" "$newrev" 2>/dev/null || true)"
    if echo "$changed" | grep -qE '^(index\.php|scripts/build-min\.php)$'; then
        need_build=1
    fi
fi

if [[ "$need_build" -ne 1 ]]; then
    exit 0
fi

if ! command -v php >/dev/null 2>&1; then
    echo "Warning: php not found; skipped index.min.php rebuild." >&2
    exit 0
fi

echo "==> Rebuilding index.min.php (scripts/build-min.php)"
if ! php "$ROOT/scripts/build-min.php"; then
    echo "Warning: index.min.php rebuild failed (git operation succeeded)." >&2
    exit 0
fi
