#!/usr/bin/env bash
# Generate release notes for the rolling dev GitHub release.
#
# Usage:
#   scripts/dev-release-notes.sh > dev-release-notes.md
#
# Expects GITHUB_SHA when run in CI; reads version/build ref from index.php.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
INDEX="$ROOT/index.php"
CHANGELOG="$ROOT/CHANGELOG.md"

VERSION="$(grep -m1 '^\$dirindexVersion' "$INDEX" | sed -n "s/.*= '\([^']*\)'.*/\1/p")"
BUILD_REF="$(grep -m1 '^\$dirindexBuildRef' "$INDEX" | sed -n "s/.*= '\([^']*\)'.*/\1/p")"
SHA="${GITHUB_SHA:-unknown}"
SHORT_SHA="${SHA:0:7}"
REF="${BUILD_REF:-$SHORT_SHA}"
DATE="$(date -u +%Y-%m-%d)"

cat <<EOF
Rolling development build from the \`dev\` branch.

**Build:** ${REF}
**Version:** ${VERSION}
**Commit:** ${SHA}
**Date:** ${DATE}

Install via **About → Channel: Dev → Check for updates**, or download \`index.php\` / \`index.min.php\` below.

EOF

if [[ -f "$CHANGELOG" ]]; then
    echo '### Unreleased changes'
    echo
    awk '
        /^## \[Unreleased\]/ { in_unreleased = 1; next }
        in_unreleased && /^## \[/ { exit }
        in_unreleased { print }
    ' "$CHANGELOG"
fi
