# AGENTS.md

## Project overview

**php-dirindex** is a single-file PHP directory index. Drop `index.php` into any folder (or document root), open it in a browser, and get a dark-themed listing of files and folders with navigation, in-browser text preview, optional authenticated uploads, and optional IP access controls.

There is no build step, no Composer dependencies, and no framework. Almost all application logic lives in `index.php` (~2000 lines): PHP helpers at the top, request handling in the middle, and inline HTML/CSS/JavaScript at the bottom.

## Repository layout

| File | Purpose |
|------|---------|
| `index.php` | The entire application (listing, preview, auth, uploads, UI) |
| `config.php.example` | Documented template for optional `config.php` settings |
| `README.md` | User-facing setup and configuration guide |
| `.gitignore` | Ignores runtime files: `config.php`, `.dirindex.sqlite*` |

Runtime files (not in git): `config.php` (optional static config), `.dirindex.sqlite` (upload settings when PDO SQLite is available).

## How it works

- **Listing root** — Resolves a base directory from `__DIR__` and `DOCUMENT_ROOT`, including symlink/subfolder cases so sibling directories can be listed.
- **Navigation** — Subfolders use `?path=subfolder`. Breadcrumbs and a `..` row go up. Path traversal (`..`, null bytes) is rejected; resolved paths must stay under the base unless `allow_open_symlinks_outside` is enabled.
- **Preview** — Text-like files open in a modal via `?content=1` (JSON API). Markdown (`.md`) can render as a full HTML page. highlight.js provides syntax highlighting.
- **Uploads** — Optional, session-authenticated. First visit can run a setup wizard that stores credentials in SQLite or `config.php`. CSRF tokens protect all POST actions.
- **Access control** — Optional IP whitelist/blacklist with CIDR support; optional `ip_header` for reverse proxies.

## Development and testing

```sh
# Run a local PHP built-in server from the repo root
php -S localhost:8080

# Open http://localhost:8080/index.php

# Generate a password hash for manual config
php -r "echo password_hash('change-me', PASSWORD_DEFAULT), PHP_EOL;"
```

No automated test suite exists. Verify changes manually in a browser: listing, `?path=` navigation, file preview modal, upload flow (if enabled), and symlink/IP restrictions when relevant.

## Code conventions

- **Monolith** — New behavior belongs in `index.php` unless there is a strong reason to split. Reuse existing helpers (`pathUnderBase`, `h`, `currentListingUrl`, config load/save functions, etc.) rather than duplicating logic.
- **PHP style** — Procedural PHP with named functions (no classes). Use `h()` for HTML escaping. Prefer early `exit` after setting headers for API-style responses.
- **Config** — Defaults live in `$dirindexConfig`; merge order is defaults → `config.php` → `.dirindex.sqlite` settings. Document new keys in both `config.php.example` and `README.md`.
- **POST actions** — Use `action` field values (`setup`, `login`, `logout`, `upload`, `settings`, `account`). Always validate CSRF. Redirect with flash-style message keys via `redirectToCurrentListing`.
- **Frontend** — Vanilla JS in `<script>` blocks at the bottom of `index.php`. UI preferences (theme, font size, breadcrumb style) use `localStorage`. External CDN: highlight.js only.

## Security boundaries

Treat these as non-negotiable when making changes:

1. **Path safety** — Never bypass `pathUnderBase()` or `..` / null-byte checks when resolving user-supplied paths.
2. **Symlinks** — Respect `show_symlinks` and `allow_open_symlinks_outside`; do not silently follow links outside the base when disabled.
3. **Uploads** — Require authentication, CSRF, `is_uploaded_file()`, sanitized filenames (`cleanUploadFilename`), and writable-directory checks. Existing files need explicit overwrite confirmation.
4. **Secrets** — Do not commit `config.php` or `.dirindex.sqlite`. Passwords are stored as `password_hash()` output only.
5. **Output** — Escape user-controlled strings in HTML (`h()`). JSON responses use safe encoding flags.

## Common change patterns

- **New config option** — Add default in `$dirindexConfig`, wire through settings save/load, update `config.php.example` and `README.md`.
- **New previewable file type** — Add extension → highlight.js language mapping in `$textExts` / `$previewExts`.
- **New POST action** — Add handler after existing actions, include CSRF check, redirect with a new `$messageMap` entry.
- **UI tweak** — CSS is in the same file; match existing dark/light theme variables and modal patterns.

## What not to do

- Do not add Composer, npm, or a bundler unless explicitly requested.
- Do not extract into multiple PHP files without a clear maintenance benefit.
- Do not weaken upload or path-traversal checks for convenience.
- Do not add unrelated features (databases beyond SQLite settings, user management beyond single admin, etc.) unless asked.

## Documentation

- **README.md** — End-user setup, config keys, and upload instructions.
- **config.php.example** — Inline comments for each config key.

When adding user-visible behavior, update README.md. When adding agent-relevant architecture or workflow notes, update this file.
