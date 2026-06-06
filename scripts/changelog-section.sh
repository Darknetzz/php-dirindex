#!/usr/bin/env bash
# Print the CHANGELOG.md section for a release version (header through next version).
#
# Usage:
#   scripts/changelog-section.sh 1.0.0
#   scripts/changelog-section.sh v1.0.0

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CHANGELOG="$ROOT/CHANGELOG.md"
VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
    echo "Usage: scripts/changelog-section.sh VERSION" >&2
    echo "  VERSION may be v1.0.0 or 1.0.0" >&2
    exit 1
fi

VERSION="${VERSION#v}"

if [[ ! -f "$CHANGELOG" ]]; then
    echo "Missing CHANGELOG.md" >&2
    exit 1
fi

awk -v ver="$VERSION" '
    $0 ~ "^## \\[" ver "\\]" { found = 1; print; next }
    found && /^## \[/ { exit }
    found { print }
' "$CHANGELOG"
