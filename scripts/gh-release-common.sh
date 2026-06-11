#!/usr/bin/env bash
# Shared helpers for publishing GitHub releases locally via the gh CLI.

gh_repo_slug() {
    if ! git remote get-url github &>/dev/null; then
        echo "Remote 'github' is not configured." >&2
        return 1
    fi
    git remote get-url github | sed -E 's#^(git@github.com:|https://github.com/)##; s#\.git$##'
}

require_gh() {
    if ! command -v gh >/dev/null 2>&1; then
        echo "The gh CLI is required to publish GitHub releases." >&2
        echo "Install: https://cli.github.com/" >&2
        exit 1
    fi
    if ! gh auth status >/dev/null 2>&1; then
        echo "gh is not authenticated. Run: gh auth login" >&2
        exit 1
    fi
    if ! gh_repo_slug >/dev/null; then
        exit 1
    fi
}

gh_release_repo_flag() {
    local repo
    repo="$(gh_repo_slug)" || return 1
    printf '%s\n%s' --repo "$repo"
}
