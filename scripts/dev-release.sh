#!/usr/bin/env bash
# Publish or refresh the rolling dev GitHub prerelease locally.
# Replaces .github/workflows/dev-release.yml — run after pushing dev when you
# want About → Dev updates to see new artifacts.
#
# Usage (from repo root):
#   ./scripts/dev-release.sh
#   ./scripts/dev-release.sh --dry-run

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DRY_RUN=0
DEFAULT_BRANCH="dev"
DEV_TAG="dev"

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -h|--help)
            echo "Usage: ./scripts/dev-release.sh [--dry-run]"
            exit 0
            ;;
        *)
            echo "Unknown argument: $arg" >&2
            exit 1
            ;;
    esac
done

# shellcheck source=gh-release-common.sh
source "$ROOT/scripts/gh-release-common.sh"

run() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "dry-run: $*"
    else
        "$@"
    fi
}

BRANCH="$(git branch --show-current)"
if [[ "$BRANCH" != "$DEFAULT_BRANCH" ]]; then
    echo "Warning: not on $DEFAULT_BRANCH (on '$BRANCH'). Continuing with current HEAD." >&2
fi

require_gh

SHA="$(git rev-parse HEAD)"
SHORT_SHA="$(git rev-parse --short=7 HEAD)"
mapfile -t REPO_ARGS < <(gh_release_repo_flag)

restore_index_php() {
    if [[ -f index.php.bak.dev-release ]]; then
        mv -f index.php.bak.dev-release index.php
        if [[ "$DRY_RUN" -ne 1 ]]; then
            php scripts/build-min.php >/dev/null 2>&1 || true
        fi
    fi
}
trap restore_index_php EXIT

echo "==> Embedding build ref ${SHORT_SHA} in index.php (temporary, not committed)"
if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "dry-run: would set \$dirindexBuildRef = '${SHORT_SHA}'"
else
    cp index.php index.php.bak.dev-release
    sed -i "s/^\$dirindexBuildRef = '';/\$dirindexBuildRef = '${SHORT_SHA}';/" index.php
fi

echo "==> Building minified artifact"
run php scripts/build-min.php

echo "==> Syntax check"
run php -l index.php
run php -l index.min.php

echo "==> Packaging release files"
DIST="$ROOT/dist-dev"
rm -rf "$DIST"
run mkdir -p "$DIST"
if [[ "$DRY_RUN" -ne 1 ]]; then
    cp index.php index.min.php README.md CHANGELOG.md "$DIST/"
fi

NOTES_FILE="$ROOT/dev-release-notes.md"
echo "==> Generating dev release notes"
if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "dry-run: GITHUB_SHA=${SHA} scripts/dev-release-notes.sh > dev-release-notes.md"
else
    GITHUB_SHA="$SHA" "$ROOT/scripts/dev-release-notes.sh" > "$NOTES_FILE"
fi

echo "==> Publishing GitHub prerelease ${DEV_TAG}"
if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "dry-run: gh release create/upload ${DEV_TAG} with dist-dev/index.php and index.min.php"
    echo "Dry run complete."
    exit 0
fi

if gh release view "$DEV_TAG" "${REPO_ARGS[@]}" >/dev/null 2>&1; then
    gh release upload "$DEV_TAG" "${REPO_ARGS[@]}" \
        "$DIST/index.php" "$DIST/index.min.php" --clobber
    gh release edit "$DEV_TAG" "${REPO_ARGS[@]}" \
        --prerelease --title "Development (rolling)" --notes-file "$NOTES_FILE"
else
    gh release create "$DEV_TAG" "${REPO_ARGS[@]}" \
        --prerelease --title "Development (rolling)" --notes-file "$NOTES_FILE" \
        "$DIST/index.php" "$DIST/index.min.php"
fi

REPO="$(gh_repo_slug)"
cat <<EOF

Dev release published:
  https://github.com/${REPO}/releases/tag/${DEV_TAG}

Build ref: ${SHORT_SHA}
EOF
