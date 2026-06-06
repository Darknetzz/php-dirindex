#!/usr/bin/env bash
# Create a version tag and push it to GitLab + GitHub.
# GitHub Actions (.github/workflows/release.yml) builds release assets when the tag lands on github.
#
# Usage (from repo root):
#   ./scripts/release.sh                  # prompt; default bumps last version segment
#   ./scripts/release.sh v1.0.0
#   ./scripts/release.sh v1.0.0 "Short release notes for the tag message"
#   ./scripts/release.sh --dry-run

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DRY_RUN=0
TAG=""
MESSAGE=""

usage() {
    cat <<'EOF'
Usage: ./scripts/release.sh [tag] [message] [--dry-run]

  tag       Version tag in vMAJOR.MINOR.PATCH form (e.g. v1.0.0). Prompted when omitted;
            default is the latest tag with the patch segment bumped by 1.
  message   Optional annotated-tag message (defaults to the tag name)
  --dry-run Show what would happen without editing CHANGELOG, committing, tagging, or pushing

Examples:
  ./scripts/release.sh
  ./scripts/release.sh v1.0.0
  ./scripts/release.sh v1.2.0 "Fix upload overwrite prompt"
EOF
}

suggest_next_tag() {
    local latest
    latest="$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname 2>/dev/null | head -1)"

    if [[ -z "$latest" ]]; then
        echo "v0.1.0"
        return
    fi

    local ver="${latest#v}"
    if [[ "$ver" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
        echo "v${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.$((BASH_REMATCH[3] + 1))"
    else
        echo "v0.1.0"
    fi
}

CHANGELOG="$ROOT/CHANGELOG.md"

changelog_has_unreleased_entries() {
    awk '
        /^## \[Unreleased\]/ { in_unreleased = 1; next }
        in_unreleased && /^## \[/ { exit }
        in_unreleased && /^- / { found = 1 }
        END { exit(found ? 0 : 1) }
    ' "$CHANGELOG"
}

finalize_changelog() {
    local tag="$1"
    local version="${tag#v}"
    local date
    date="$(date +%Y-%m-%d)"
    local new_release_header="## [${version}] - ${date}"
    local new_unreleased
    new_unreleased='## [Unreleased]

### Added

### Changed

### Fixed
'

    if [[ ! -f "$CHANGELOG" ]]; then
        echo "Missing CHANGELOG.md" >&2
        exit 1
    fi

    if ! grep -q '^## \[Unreleased\]' "$CHANGELOG"; then
        echo "CHANGELOG.md must contain a ## [Unreleased] section" >&2
        exit 1
    fi

    if ! changelog_has_unreleased_entries; then
        echo "CHANGELOG.md [Unreleased] has no bullet entries. Add notes before releasing." >&2
        exit 1
    fi

    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "dry-run: would rename ## [Unreleased] to ${new_release_header}"
        echo "dry-run: would prepend a fresh ## [Unreleased] section"
        return
    fi

    local tmp
    tmp="$(mktemp)"
    awk -v new="$new_release_header" '
        /^## \[Unreleased\]/ && !done { print new; done = 1; next }
        { print }
    ' "$CHANGELOG" > "$tmp"
    mv "$tmp" "$CHANGELOG"

    tmp="$(mktemp)"
    awk -v block="$new_unreleased" '
        /^## \[[0-9]/ && !inserted { print block; print ""; inserted = 1 }
        { print }
    ' "$CHANGELOG" > "$tmp"
    mv "$tmp" "$CHANGELOG"
}

prompt_for_tag() {
    local latest default
    latest="$(git tag -l 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname 2>/dev/null | head -1)"
    default="$(suggest_next_tag)"

    if [[ -n "$latest" ]]; then
        echo "Latest tag: $latest"
    else
        echo "No existing v* tags found."
    fi

    read -r -p "Release tag [$default]: " TAG
    TAG="${TAG:-$default}"
}

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -h|--help) usage; exit 0 ;;
        *)
            if [[ -z "$TAG" ]]; then
                TAG="$arg"
            elif [[ -z "$MESSAGE" ]]; then
                MESSAGE="$arg"
            else
                echo "Unexpected argument: $arg" >&2
                usage >&2
                exit 1
            fi
            ;;
    esac
done

if [[ -z "$TAG" ]]; then
    prompt_for_tag
fi

if [[ ! "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Tag must match vMAJOR.MINOR.PATCH (e.g. v1.0.0). Got: $TAG" >&2
    exit 1
fi

if [[ -z "$MESSAGE" ]]; then
    MESSAGE="$TAG"
fi

if ! git remote get-url github &>/dev/null; then
    echo "Remote 'github' is not configured. Add it first:" >&2
    echo "  git remote add github git@github.com:YOU/php-dirindex.git" >&2
    exit 1
fi

BRANCH="$(git branch --show-current)"
if [[ "$BRANCH" != "main" ]]; then
    echo "Not on main (on '$BRANCH'). Checkout main before releasing." >&2
    exit 1
fi

DIRTY_OTHER="$(git status --porcelain | grep -v '^.. CHANGELOG\.md$' || true)"
if [[ -n "$DIRTY_OTHER" ]]; then
    echo "Working tree is not clean. Commit or stash changes before releasing." >&2
    echo "$DIRTY_OTHER" >&2
    exit 1
fi

if git rev-parse "$TAG" >/dev/null 2>&1; then
    echo "Tag already exists locally: $TAG" >&2
    exit 1
fi

if git ls-remote --exit-code --tags origin "refs/tags/$TAG" &>/dev/null; then
    echo "Tag already exists on origin: $TAG" >&2
    exit 1
fi

if git ls-remote --exit-code --tags github "refs/tags/$TAG" &>/dev/null; then
    echo "Tag already exists on github: $TAG" >&2
    exit 1
fi

run() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "dry-run: $*"
    else
        "$@"
    fi
}

echo "==> Finalizing CHANGELOG.md"
finalize_changelog "$TAG"
if [[ "$DRY_RUN" -ne 1 ]]; then
    if ! git diff --quiet CHANGELOG.md; then
        run git add CHANGELOG.md
        run git commit -m "Prepare release $TAG"
    fi
fi

echo "==> Verifying minified build"
run php scripts/build-min.php
run php -l index.php
run php -l index.min.php

AHEAD_ORIGIN="$(git rev-list --count origin/main..HEAD 2>/dev/null || echo 0)"
AHEAD_GITHUB="$(git rev-list --count github/main..HEAD 2>/dev/null || echo 0)"

if [[ "$AHEAD_ORIGIN" != "0" || "$AHEAD_GITHUB" != "0" ]]; then
    echo "==> Pushing main (ahead of origin: $AHEAD_ORIGIN, github: $AHEAD_GITHUB)"
    run git push origin main
    run git push github main
fi

echo "==> Creating annotated tag $TAG"
run git tag -a "$TAG" -m "$MESSAGE"

echo "==> Pushing tag to origin and github"
run git push origin "$TAG"
run git push github "$TAG"

if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "Dry run complete. No tag was created."
    exit 0
fi

cat <<EOF

Release tag $TAG pushed.

GitHub Actions will build index.php, index.min.php, and a zip, then publish:
  https://github.com/$(git remote get-url github | sed -E 's#.*github.com[:/](.+)(\.git)?$#\1#')/releases/tag/$TAG

Track the workflow under Actions on GitHub if it does not appear within a minute.
EOF
