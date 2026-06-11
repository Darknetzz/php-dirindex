#!/usr/bin/env bash
# Install local git hooks so dev pushes publish the rolling GitHub prerelease.
#
# Usage (from repo root):
#   ./scripts/install-hooks.sh
#   ./scripts/install-hooks.sh --status
#   ./scripts/install-hooks.sh --uninstall

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PUSH_ALIAS='!bash "'"$ROOT"'/scripts/git-push.sh"'
ACTION="install"

for arg in "$@"; do
    case "$arg" in
        --status)
            ACTION="status"
            ;;
        --uninstall)
            ACTION="uninstall"
            ;;
        -h|--help)
            echo "Usage: ./scripts/install-hooks.sh [--status|--uninstall]"
            exit 0
            ;;
        *)
            echo "Unknown argument: $arg" >&2
            exit 1
            ;;
    esac
done

current_alias() {
    git config --local --get alias.push 2>/dev/null || true
}

case "$ACTION" in
    status)
        alias_value="$(current_alias)"
        if [[ "$alias_value" == "$PUSH_ALIAS" ]]; then
            echo "Dev release push hook: installed"
            exit 0
        fi
        if [[ -n "$alias_value" ]]; then
            echo "Dev release push hook: not installed (alias.push is customized)"
            echo "  alias.push = $alias_value"
            exit 1
        fi
        echo "Dev release push hook: not installed"
        exit 1
        ;;
    uninstall)
        alias_value="$(current_alias)"
        if [[ "$alias_value" == "$PUSH_ALIAS" ]]; then
            git config --local --unset alias.push
            echo "Removed git push alias for rolling dev releases."
        else
            echo "Nothing to remove (dev release push hook was not installed)."
        fi
        ;;
    install)
        if [[ ! -x "$ROOT/scripts/git-push.sh" ]]; then
            chmod +x "$ROOT/scripts/git-push.sh"
        fi
        if [[ ! -x "$ROOT/scripts/dev-release.sh" ]]; then
            chmod +x "$ROOT/scripts/dev-release.sh"
        fi
        existing="$(current_alias)"
        if [[ -n "$existing" && "$existing" != "$PUSH_ALIAS" ]]; then
            echo "alias.push is already set to something else:" >&2
            echo "  $existing" >&2
            echo "Unset it first or merge manually." >&2
            exit 1
        fi
        git config --local alias.push "$PUSH_ALIAS"
        echo "Installed. A successful git push on branch dev runs scripts/dev-release.sh."
        echo "Requires gh auth. Skip once: SKIP_DEV_RELEASE=1 git push …"
        echo "Use plain push: git -c alias.push= push …"
        ;;
esac
