#!/usr/bin/env bash
# Install local git hooks:
#   - git push alias → rolling dev release on successful push to `dev`
#   - core.hooksPath → scripts/githooks (post-merge / post-checkout rebuild index.min.php)
#
# Usage (from repo root):
#   ./scripts/install-hooks.sh
#   ./scripts/install-hooks.sh --status
#   ./scripts/install-hooks.sh --uninstall
#
# Hooks are not cloned with the repo — run this once after each fresh clone.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PUSH_ALIAS='!bash "'"$ROOT"'/scripts/git-push.sh"'
HOOKS_PATH="scripts/githooks"
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

current_hooks_path() {
    git config --local --get core.hooksPath 2>/dev/null || true
}

push_ok() {
    [[ "$(current_alias)" == "$PUSH_ALIAS" ]]
}

hooks_ok() {
    [[ "$(current_hooks_path)" == "$HOOKS_PATH" ]]
}

ensure_hook_scripts_executable() {
    chmod +x "$ROOT/scripts/git-push.sh" "$ROOT/scripts/dev-release.sh" \
        "$ROOT/scripts/rebuild-min-if-needed.sh" \
        "$ROOT/scripts/githooks/post-merge" \
        "$ROOT/scripts/githooks/post-checkout"
}

case "$ACTION" in
    status)
        ok=0
        if push_ok; then
            echo "Dev release push hook: installed"
        else
            alias_value="$(current_alias)"
            if [[ -n "$alias_value" ]]; then
                echo "Dev release push hook: not installed (alias.push is customized)"
                echo "  alias.push = $alias_value"
            else
                echo "Dev release push hook: not installed"
            fi
            ok=1
        fi
        if hooks_ok; then
            echo "Min rebuild hooks (post-merge/post-checkout): installed"
        else
            hooks_value="$(current_hooks_path)"
            if [[ -n "$hooks_value" ]]; then
                echo "Min rebuild hooks: not installed (core.hooksPath is customized)"
                echo "  core.hooksPath = $hooks_value"
            else
                echo "Min rebuild hooks: not installed"
            fi
            ok=1
        fi
        exit "$ok"
        ;;
    uninstall)
        alias_value="$(current_alias)"
        if [[ "$alias_value" == "$PUSH_ALIAS" ]]; then
            git config --local --unset alias.push
            echo "Removed git push alias for rolling dev releases."
        else
            echo "Nothing to remove for push alias."
        fi
        hooks_value="$(current_hooks_path)"
        if [[ "$hooks_value" == "$HOOKS_PATH" ]]; then
            git config --local --unset core.hooksPath
            echo "Removed core.hooksPath (min rebuild hooks)."
        else
            echo "Nothing to remove for core.hooksPath."
        fi
        ;;
    install)
        ensure_hook_scripts_executable

        existing="$(current_alias)"
        if [[ -n "$existing" && "$existing" != "$PUSH_ALIAS" ]]; then
            echo "alias.push is already set to something else:" >&2
            echo "  $existing" >&2
            echo "Unset it first or merge manually." >&2
            exit 1
        fi

        existing_hooks="$(current_hooks_path)"
        if [[ -n "$existing_hooks" && "$existing_hooks" != "$HOOKS_PATH" ]]; then
            echo "core.hooksPath is already set to something else:" >&2
            echo "  $existing_hooks" >&2
            echo "Unset it first or merge manually." >&2
            exit 1
        fi

        git config --local alias.push "$PUSH_ALIAS"
        git config --local core.hooksPath "$HOOKS_PATH"

        echo "Installed:"
        echo "  • Successful git push on branch dev runs scripts/dev-release.sh"
        echo "  • git pull / branch checkout rebuilds index.min.php when sources change"
        echo "Requires gh auth for dev releases. Skip once: SKIP_DEV_RELEASE=1 git push …"
        echo "Skip min rebuild once: SKIP_MIN_BUILD=1 git pull …"
        echo "Use plain push: git -c alias.push= push …"
        echo
        echo "Note: git does not clone hooks. Re-run ./scripts/install-hooks.sh after each fresh clone."
        ;;
esac
