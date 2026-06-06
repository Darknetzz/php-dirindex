# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`hidden_paths`** — hide files and folders from listings and block access through the index (Settings → Server settings); supports path prefixes and basename rules
- File preview modal shows metadata (type, size, modified date, permissions) for text and binary files; metadata appears in a footer bar below the preview
- **`browse_requires_auth`** — optional setting to require admin sign-in before browsing listings or opening files; share links still work without sign-in
- `CHANGELOG.md` and release-time automation in `scripts/release.sh`
- `index.min.php` build (`scripts/build-min.php`) — smaller production drop-in, like `*.min.js`
- `scripts/release.sh` — version prompt, build verification, and dual-remote tag push
- GitHub Actions CI (`.github/workflows/ci.yml`) and release workflow (`.github/workflows/release.yml`)

### Changed

- Admin username/password moved from Settings to a separate Account modal (header button next to Settings)
- Folder and file icons on the New folder / New file listing toolbar buttons
- Breadcrumb path segments styled as badges (matching the share-link badge look)
- Documentation for `index.php` vs `index.min.php`, dual GitLab/GitHub remotes, and the release process

### Fixed

- Minified build preserves whitespace after inline `<?php` blocks (fixes conditional `<body>` attributes rendering as plain text)
