#!/usr/bin/env bash
# Create a version tag, push to GitLab + GitHub, and publish release assets via gh CLI.
#
# Usage (from repo root):
#   ./scripts/release.sh                  # prompt; default bumps last version segment
#   ./scripts/release.sh v1.0.0
#   ./scripts/release.sh v1.0.0 "Optional short annotated-tag message (GitHub release body uses CHANGELOG)"
#   ./scripts/release.sh --dry-run

set -euo pipefail

DEFAULT_BRANCH="dev"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DRY_RUN=0
TAG=""
MESSAGE=""
MESSAGE_EXPLICIT=0

usage() {
    cat <<'EOF'
Usage: ./scripts/release.sh [tag] [message] [--dry-run]

  tag       Version tag in vMAJOR.MINOR.PATCH form (e.g. v1.0.0). Prompted when omitted;
            default is the latest tag with the patch segment bumped by 1.
  message   Optional annotated-tag message (defaults to this version's CHANGELOG section)
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

# Files written via mktemp + mv do not inherit directory default ACLs; restore
# www-data read for PHP-FPM deployments (best-effort when setfacl/www-data exist).
ensure_web_server_readable() {
    local file
    for file in "$@"; do
        [[ -f "$file" ]] || continue
        if [[ "$DRY_RUN" -eq 1 ]]; then
            echo "dry-run: would ensure web-server read access on ${file#"$ROOT"/}"
            continue
        fi
        chmod go+r "$file" 2>/dev/null || true
        if command -v setfacl >/dev/null 2>&1 && getent passwd www-data >/dev/null 2>&1; then
            setfacl -m "u:www-data:r" "$file" 2>/dev/null || true
        fi
    done
}

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

update_index_version() {
    local tag="$1"
    local version="${tag#v}"
    local file="$ROOT/index.php"

    if [[ ! -f "$file" ]]; then
        echo "Missing index.php" >&2
        exit 1
    fi

    if ! grep -q "^\$dirindexVersion = '" "$file"; then
        echo "index.php must define \$dirindexVersion for release tagging" >&2
        exit 1
    fi

    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "dry-run: would set \$dirindexVersion to ${version} in index.php"
        return
    fi

    local tmp
    tmp="$(mktemp)"
    sed "s/^\(\$dirindexVersion = '\)[^']*';/\1${version}';/" "$file" > "$tmp"
    mv "$tmp" "$file"
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
                MESSAGE_EXPLICIT=1
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

if ! git remote get-url github &>/dev/null; then
    echo "Remote 'github' is not configured. Add it first:" >&2
    echo "  git remote add github git@github.com:YOU/php-dirindex.git" >&2
    exit 1
fi

BRANCH="$(git branch --show-current)"
if [[ "$BRANCH" != "$DEFAULT_BRANCH" ]]; then
    echo "Not on $DEFAULT_BRANCH (on '$BRANCH'). Checkout $DEFAULT_BRANCH before releasing." >&2
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

echo "==> Updating index.php version"
update_index_version "$TAG"

ensure_web_server_readable "$CHANGELOG" "$ROOT/index.php"

if [[ "$MESSAGE_EXPLICIT" -eq 0 ]]; then
    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "dry-run: annotated tag message and GitHub release body would use the CHANGELOG section for $TAG"
        MESSAGE="(CHANGELOG section for $TAG)"
    else
        MESSAGE="$("$ROOT/scripts/changelog-section.sh" "$TAG")"
        if [[ -z "$MESSAGE" ]]; then
            echo "No CHANGELOG section found for $TAG after finalizing CHANGELOG.md" >&2
            exit 1
        fi
    fi
fi

if [[ "$DRY_RUN" -ne 1 ]]; then
    if ! git diff --quiet CHANGELOG.md index.php; then
        run git add CHANGELOG.md index.php
        run git commit -m "Prepare release $TAG"
    fi
fi

echo "==> Verifying minified build"
run php scripts/build-min.php
ensure_web_server_readable "$ROOT/index.min.php"
run php -l index.php
run php -l index.min.php

AHEAD_ORIGIN="$(git rev-list --count "origin/${DEFAULT_BRANCH}"..HEAD 2>/dev/null || echo 0)"
AHEAD_GITHUB="$(git rev-list --count "github/${DEFAULT_BRANCH}"..HEAD 2>/dev/null || echo 0)"

if [[ "$AHEAD_ORIGIN" != "0" || "$AHEAD_GITHUB" != "0" ]]; then
    echo "==> Pushing $DEFAULT_BRANCH (ahead of origin: $AHEAD_ORIGIN, github: $AHEAD_GITHUB)"
    run git push origin "$DEFAULT_BRANCH"
    run git push github "$DEFAULT_BRANCH"
fi

echo "==> Creating annotated tag $TAG"
run git tag -a "$TAG" -m "$MESSAGE"

echo "==> Pushing tag to origin and github"
run git push origin "$TAG"
run git push github "$TAG"

echo "==> Packaging release assets"
DIST="$ROOT/dist"
ZIP_NAME="php-dirindex-${TAG}.zip"
NOTES_FILE="$ROOT/release-notes.md"
if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "dry-run: package dist/ and ${ZIP_NAME}"
    echo "dry-run: scripts/changelog-section.sh ${TAG} > release-notes.md"
    echo "dry-run: gh release create ${TAG} with assets"
else
    # shellcheck source=gh-release-common.sh
    source "$ROOT/scripts/gh-release-common.sh"
    require_gh

    rm -rf "$DIST"
    mkdir -p "$DIST"
    cp index.php index.min.php README.md CHANGELOG.md "$DIST/"
    (cd "$DIST" && zip -r "../${ZIP_NAME}" .)

    "$ROOT/scripts/changelog-section.sh" "$TAG" > "$NOTES_FILE"
    if [[ ! -s "$NOTES_FILE" ]]; then
        echo "No CHANGELOG section for $TAG" >&2
        exit 1
    fi

    mapfile -t REPO_ARGS < <(gh_release_repo_flag)
    if gh release view "$TAG" "${REPO_ARGS[@]}" >/dev/null 2>&1; then
        gh release upload "$TAG" "${REPO_ARGS[@]}" \
            "$DIST/index.php" "$DIST/index.min.php" "$ROOT/$ZIP_NAME" --clobber
        gh release edit "$TAG" "${REPO_ARGS[@]}" --title "$TAG" --notes-file "$NOTES_FILE"
    else
        gh release create "$TAG" "${REPO_ARGS[@]}" \
            --title "$TAG" --notes-file "$NOTES_FILE" \
            "$DIST/index.php" "$DIST/index.min.php" "$ROOT/$ZIP_NAME"
    fi
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "Dry run complete. No tag was created."
    exit 0
fi

REPO="$(git remote get-url github | sed -E 's#.*github.com[:/](.+)(\.git)?$#\1#')"
cat <<EOF

Release $TAG published:
  https://github.com/${REPO}/releases/tag/${TAG}
EOF
