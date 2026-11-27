#!/usr/bin/env bash
# Shared helpers for publishing GitHub releases locally via the gh CLI.

# GitHub is a read-only push mirror of the GitLab origin; there is no github remote.
GITHUB_REPO="${GITHUB_REPO:-Darknetzz/php-dirindex}"

gh_repo_slug() {
    printf '%s\n' "$GITHUB_REPO"
}

# Wait until the GitLab push mirror has copied a tag to GitHub (gh release needs it there).
wait_for_github_tag() {
    local tag="$1" deadline=$((SECONDS + ${2:-600}))
    echo "==> Waiting for the GitLab push mirror to copy $tag to GitHub"
    until gh api "repos/${GITHUB_REPO}/git/ref/tags/${tag}" >/dev/null 2>&1; do
        if [[ $SECONDS -ge $deadline ]]; then
            echo "$tag is not on GitHub yet: check the push mirror (GitLab > Settings > Repository > Mirroring repositories), then rerun." >&2
            return 1
        fi
        sleep 10
    done
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

# Wait until the GitLab push mirror has copied a commit to GitHub.
wait_for_github_commit() {
    local sha="$1" deadline=$((SECONDS + ${2:-600}))
    echo "==> Waiting for the GitLab push mirror to copy ${sha:0:7} to GitHub"
    until gh api "repos/${GITHUB_REPO}/commits/${sha}" >/dev/null 2>&1; do
        if [[ $SECONDS -ge $deadline ]]; then
            echo "${sha:0:7} is not on GitHub yet: check the push mirror (GitLab > Settings > Repository > Mirroring repositories), then rerun." >&2
            return 1
        fi
        sleep 10
    done
}

# Point a (lightweight) tag on GitHub at a commit, creating or moving it.
# Rolling tags move on GitHub first, then on origin, so the mirror (which keeps
# divergent refs) finds both sides equal and has nothing to sync.
move_github_tag() {
    local tag="$1" sha="$2"
    if gh api "repos/${GITHUB_REPO}/git/ref/tags/${tag}" >/dev/null 2>&1; then
        gh api -X PATCH "repos/${GITHUB_REPO}/git/refs/tags/${tag}" -f sha="$sha" -F force=true >/dev/null
    else
        gh api -X POST "repos/${GITHUB_REPO}/git/refs" -f ref="refs/tags/${tag}" -f sha="$sha" >/dev/null
    fi
}
