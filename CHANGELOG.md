# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Settings → Reset** — signed-in admins can delete `.dirindex.sqlite` / `.dirindex.json` and return to first-run setup (confirmation required)
- **`.htaccess`** — blocks direct HTTP access to `.dirindex.sqlite`, `.dirindex.json`, and legacy `config.php` on Apache
- **Share file previews** — syntax highlighting (highlight.js) on shared text/code landing pages; wider layout and scrollable preview area for long or wide files
- **Settings modal** — collapsible sections (Permissions, Filesystem, Path access, Network access, Reset); panel open state remembered in the browser; auto-opens on save errors
- **About** — info button in the header and version link in the footer open a modal with project details, version, and repository links

### Changed

- **Settings modal** — wider layout (880px) and more spacing between collapsible sections
- **Shared links** — Revoke button uses a danger-outline style instead of a filled red button
- **`path_whitelist` / `path_blacklist`** — replace `hidden_paths` with separate path whitelist and blacklist (same combined logic as IP access). Legacy `hidden_paths` values migrate to `path_blacklist` on load. Share links bypass path rules.
- GitHub releases and annotated tags use the version's `CHANGELOG.md` section as the release description (via `scripts/changelog-section.sh`)

### Fixed


## [1.0.0] - 2026-06-06

### Added

- **`delete_enabled`** — when signed in as admin, show a delete button on listing rows (confirmation required); folders are removed recursively
- **`hidden_paths`** — hide files and folders from listings and block access through the index (Settings → Server settings); supports path prefixes and basename rules
- File preview modal shows metadata (type, size, modified date, permissions) for text and binary files; metadata appears in a footer bar below the preview
- **`browse_requires_auth`** — optional setting to require admin sign-in before browsing listings or opening files; share links still work without sign-in
- `CHANGELOG.md` and release-time automation in `scripts/release.sh`
- `index.min.php` build (`scripts/build-min.php`) — smaller production drop-in, like `*.min.js`
- `scripts/release.sh` — version prompt, build verification, and dual-remote tag push
- GitHub Actions CI (`.github/workflows/ci.yml`) and release workflow (`.github/workflows/release.yml`)

### Changed

- Delete confirmation uses an in-app modal instead of the browser `confirm()` dialog
- Upload and create-entry names use safe-general validation (blocks `/ \\ : * ? " < > |`, control chars, trailing dots/spaces, Windows reserved names); invalid uploads can be renamed via confirmation prompt
- Upload form uses a styled drop zone with browse button and selected-file badge instead of the default file input
- Admin username/password moved from Settings to a separate Account modal (header button next to Settings)
- Folder and file icons on the New folder / New file listing toolbar buttons
- Breadcrumb path segments styled as badges (matching the share-link badge look)
- Documentation for `index.php` vs `index.min.php`, dual GitLab/GitHub remotes, and the release process

### Fixed

- JS minifier no longer corrupts regex literals (fixes `Invalid regular expression: /+$/` in `index.min.php`)
- Path checks no longer treat names like `...` as traversal; folders and files with multiple dots in the name can be opened and shared
- Minified build preserves whitespace after inline `<?php` blocks (fixes conditional `<body>` attributes rendering as plain text)
