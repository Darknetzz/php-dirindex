# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

### Changed

- **Release script** — after updating `CHANGELOG.md` and `index.php`, restores web-server read access (`chmod go+r` and `setfacl u:www-data:r` when available) so `mv`-via-`/tmp` does not bypass directory default ACLs

### Fixed


## [1.2.1] - 2026-06-11

### Added

- **File hashes** — the file info modal shows CRC32, MD5, SHA-1, SHA-256, and SHA-512 checksums (text previews via `?content=1`; binary and image files via `?meta=1`)

### Changed

- **File hashes** — checksums in the file info modal are grouped in a collapsible **Checksums** section (collapsed by default)

### Fixed

- **Markdown tables** — GFM pipe tables render as HTML in the preview modal and on share landing pages

## [1.2.0] - 2026-06-11

### Added

- **Image preview modal** — common image types (jpg, png, gif, webp, svg, and similar) open in the file preview modal; share landing pages show inline image previews when enabled
- **`image_preview_enabled`** — Settings → Previews toggle to disable image modal preview
- **`preview_blocklist`** — Settings → Previews list of extensions (e.g. `php`) that are never previewed in the modal or on share pages; default includes `php`
- **Path access wildcards** — path whitelist and blacklist rules support `*`, `?`, `[…]`, and `**` glob patterns (e.g. `*.log`, `public/*.html`, `backups/**`)

### Changed

- **Markdown preview** — `.md` / `.markdown` files render as HTML in the preview modal (headings, lists, links, images, blockquotes, fenced code with highlight.js) instead of a separate full-page view; direct `?path=…/file.md` links redirect to the listing with the modal open

### Fixed


## [1.1.0] - 2026-06-07

### Added

- **Display settings** — theme (follow system, light, or dark), five text sizes (scales all UI via root `rem`), and a custom breadcrumb separator; stored in the browser
- **`web_root_url`** — configurable public URL base for “Open in new tab” file and folder links; auto-detected and saved during setup
- **Listing** — toolbar and folder rows include “Open in new tab” at the configured web root URL; optional **Owner** column (POSIX username, or numeric UID when name lookup is unavailable)
- **Settings → Reset** — signed-in admins can delete `.dirindex.sqlite` / `.dirindex.json` and return to first-run setup (confirmation required)
- **`.htaccess`** — blocks direct HTTP access to `.dirindex.sqlite`, `.dirindex.json`, and legacy `config.php` on Apache
- **Share file previews** — syntax highlighting (highlight.js) on shared text/code landing pages; wider layout and scrollable preview area for long or wide files
- **Settings modal** — collapsible sections (Display, Permissions, Filesystem, Path access, Network access, Reset); panel open state remembered in the browser; auto-opens on save errors
- **About** — info button in the header and version link in the footer open a modal with project details, version, and repository links

### Changed

- **Settings modal** — Display uses the same collapsible sections as server settings; wider layout (880px), more spacing between sections, and more padding inside each section body; display toggles replaced with richer controls; save/error feedback shown inside the modal (toast on success)
- **Shared links** — Revoke button uses a danger-outline style instead of a filled red button
- **`path_whitelist` / `path_blacklist`** — replace `hidden_paths` with separate path whitelist and blacklist (same combined logic as IP access). Legacy `hidden_paths` values migrate to `path_blacklist` on load. Share links bypass path rules.
- GitHub releases and annotated tags use the version's `CHANGELOG.md` section as the release description (via `scripts/changelog-section.sh`)

### Fixed

- **Open in new tab** — folder and current-directory links use the configured web root URL (`web_root_url`) instead of php-dirindex `?path=` URLs; share links still open the share-scoped listing

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
