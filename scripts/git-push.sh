#!/usr/bin/env bash
# Wrapper around git push: after a successful push on dev, publish the rolling
# dev GitHub prerelease locally (no GitHub Actions).
#
# Installed as the local git push alias by scripts/install-hooks.sh.
# Bypass: SKIP_DEV_RELEASE=1 git push …  or  git -c alias.push= push …

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DEFAULT_BRANCH="dev"

# Call real git push, not this alias again.
git -c alias.push= push "$@"
status=$?

if [[ $status -ne 0 ]]; then
    exit $status
fi

if [[ -n "${SKIP_DEV_RELEASE:-}" ]]; then
    exit 0
fi

for arg in "$@"; do
    case "$arg" in
        --dry-run|-n)
            exit 0
            ;;
    esac
done

if [[ "$(git branch --show-current 2>/dev/null)" != "$DEFAULT_BRANCH" ]]; then
    exit 0
fi

echo "==> Publishing rolling dev release (scripts/dev-release.sh)"
if ! "$ROOT/scripts/dev-release.sh"; then
    echo "Warning: dev release publish failed (git push succeeded)." >&2
fi
