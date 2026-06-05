# AGENTS.md

## Project overview

**php-dirindex** is a single-file PHP directory index. Drop `index.php` into any folder (or document root), open it in a browser, and get a dark-themed listing of files and folders with navigation, in-browser text preview, optional authenticated uploads, and optional IP access controls.

There is no build step, no Composer dependencies, and no framework. Almost all application logic lives in `index.php` (~2000 lines): PHP helpers at the top, request handling in the middle, and inline HTML/CSS/JavaScript at the bottom.

## Repository layout

| File | Purpose |
|------|---------|
| `index.php` | The entire application (listing, preview, auth, uploads, UI) |
| `README.md` | User-facing setup and configuration guide |
| `.gitignore` | Ignores runtime files: `.dirindex.sqlite*`, `.dirindex.json`, legacy `config.php` |

Runtime files (not in git): `.dirindex.sqlite` (settings and share links when PDO SQLite is available), or `.dirindex.json` (settings fallback without SQLite). Legacy `config.php` is imported once if present, then ignored.

## How it works

- **Listing root** — Resolves a base directory from `__DIR__` and `DOCUMENT_ROOT`, including symlink/subfolder cases so sibling directories can be listed.
- **Navigation** — Subfolders use `?path=subfolder`. Breadcrumbs and a `..` row go up. Path traversal (`..`, null bytes) is rejected; resolved paths must stay under the base unless `allow_open_symlinks_outside` is enabled.
- **Preview** — Text-like files open in a modal via `?content=1` (JSON API). Markdown (`.md`) can render as a full HTML page. highlight.js provides syntax highlighting.
- **Uploads** — Optional, session-authenticated. First visit can run a setup wizard that stores credentials in `.dirindex.sqlite` (or `.dirindex.json` without SQLite). CSRF tokens protect all POST actions.
- **Access control** — Optional IP whitelist/blacklist with CIDR support; optional `ip_header` for reverse proxies.
- **Share links** — Token-based public links stored in `.dirindex.sqlite` (`shares` table). Valid `?share=TOKEN` requests bypass IP checks. File shares render a download landing page; directory shares scope listing navigation to the shared folder. Create/revoke requires admin session + CSRF; viewing is read-only (POST blocked in share mode).

## Development and testing

```sh
# Run a local PHP built-in server from the repo root
php -S localhost:8080

# Open http://localhost:8080/index.php

# Generate a password hash for manual config
php -r "echo password_hash('change-me', PASSWORD_DEFAULT), PHP_EOL;"
```

No automated test suite exists. Verify changes manually in a browser: listing, `?path=` navigation, file preview modal, upload flow (if enabled), symlink/IP restrictions, and share links (create, copy, browse, download, expiry/revoke, IP bypass).

## Code conventions

- **Monolith** — New behavior belongs in `index.php` unless there is a strong reason to split. Reuse existing helpers (`pathUnderBase`, `h`, `currentListingUrl`, config load/save functions, etc.) rather than duplicating logic.
- **PHP style** — Procedural PHP with named functions (no classes). Use `h()` for HTML escaping. Prefer early `exit` after setting headers for API-style responses.
- **Config** — Defaults live in `$dirindexConfig`; merge order is defaults → stored settings (SQLite or JSON). Legacy `config.php` keys are imported once if missing from storage. Document new keys in `README.md`.
- **POST actions** — Use `action` field values (`setup`, `login`, `logout`, `upload`, `settings`, `account`). Always validate CSRF. Redirect with flash-style message keys via `redirectToCurrentListing`.
- **Frontend** — Vanilla JS in `<script>` blocks at the bottom of `index.php`. UI preferences (theme, font size, breadcrumb style) use `localStorage`. External CDN: highlight.js only.

## Security boundaries

Treat these as non-negotiable when making changes:

1. **Path safety** — Never bypass `pathUnderBase()` or `..` / null-byte checks when resolving user-supplied paths.
2. **Symlinks** — Respect `show_symlinks` and `allow_open_symlinks_outside`; do not silently follow links outside the base when disabled.
3. **Uploads** — Require authentication, CSRF, `is_uploaded_file()`, sanitized filenames (`cleanUploadFilename`), and writable-directory checks. Existing files need explicit overwrite confirmation.
4. **Secrets** — Do not commit `.dirindex.sqlite`, `.dirindex.json`, or legacy `config.php`. Passwords are stored as `password_hash()` output only.
5. **Output** — Escape user-controlled strings in HTML (`h()`). JSON responses use safe encoding flags.

## Common change patterns

- **New config option** — Add default in `$dirindexConfig`, wire through settings save/load, update `README.md`.
- **New previewable file type** — Add extension → highlight.js language mapping in `$textExts` / `$previewExts`.
- **New POST action** — Add handler after existing actions, include CSRF check, redirect with a new `$messageMap` entry. Block POST in share mode (`$inShareMode`).
- **Share links** — Use `dirindexGetSharesPdo()`, `shareUrl()`, `pathWithinShareScope()`. Preserve `share` in URLs via `currentListingUrl()` / `$shareTokenActive`. Binary files in share mode use `?download=1` through `index.php`, not `directEntryUrl()`.
- **UI tweak** — CSS is in the same file; match existing dark/light theme variables and modal patterns.

## What not to do

- Do not add Composer, npm, or a bundler unless explicitly requested.
- Do not extract into multiple PHP files without a clear maintenance benefit.
- Do not weaken upload or path-traversal checks for convenience.
- Do not add unrelated features (databases beyond SQLite settings, user management beyond single admin, etc.) unless asked.

## Documentation

- **README.md** — End-user setup, config keys, and upload instructions.
When adding user-visible behavior, update README.md. When adding agent-relevant architecture or workflow notes, update this file.
