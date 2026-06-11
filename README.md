# PHP Directory Index

A single-file directory index that lists files and folders in a dark-themed, readable layout.

- **Place** `index.php` (or the smaller `index.min.php` from [releases](https://github.com/Darknetzz/php-dirindex/releases)) in any folder (or document root) and open it in a browser.
- **Lists** the current directory with name, size, modified date, owner, and Unix permissions. Directories appear first, then files (alphabetically). Click a column header to sort; use **Reset sort** to restore the default order. Use the **Columns** menu above the listing to show or hide optional columns (saved in the browser).
- **Display** (gear icon → Settings → Display): theme (follow system, light, or dark), text size (extra small through extra large), and a custom breadcrumb separator — all saved in the browser.
- **Navigate** subfolders via `?path=subfolder`; breadcrumbs and a ".." row let you go back. Path traversal is restricted to the base directory.
- **Upload** files after setting up the built-in upload login. Existing filenames require confirmation before overwrite. Upload and create names must be safe (letters, numbers, spaces, `. _ - ( ) [ ]`; not `* ? " < > | : \\ /`). Invalid upload names can be renamed via a confirmation prompt.
- **Create** empty folders and files in the current directory when signed in as admin (toolbar buttons in the listing). Same naming rules as uploads.
- **Requires** PHP and a web server. By default the index lists the folder where the script lives. Enable **Use document root as listing base** in Settings → Filesystem to list from the web server root instead (legacy behavior for symlinked or nested installs).

**Settings storage:** Drop in a single PHP file (`index.php` or `index.min.php`). On first run, the setup wizard saves upload credentials and other settings locally in the same folder. You can optionally enable **private-network browsing** during setup (RFC1918, link-local, and IPv6 private ranges). If you complete setup from a public IP, your current address is added to the whitelist automatically so you are not locked out.

- **PDO SQLite available (recommended):** `.dirindex.sqlite` next to the script (upload settings, share links, and UI-managed options).
- **No SQLite:** `.dirindex.json` in the same folder (upload and UI-managed options; share links require SQLite).

Shipped `.htaccess` (Apache) denies direct HTTP access to `.dirindex.sqlite` (including `-wal` / `-shm` sidecars), `.dirindex.json`, and legacy `config.php`. PHP still reads them normally; only browser downloads are blocked. Nginx and `php -S` need equivalent rules if you use those instead.

If you still have a legacy `config.php`, missing keys are imported into the active store on first request. You can delete `config.php` afterward.

**Configurable options** (via the admin settings UI, or by editing `.dirindex.json` when SQLite is unavailable):

| Key | Default | Description |
|-----|---------|-------------|
| `show_symlinks` | `true` | Hide symlinks from the listing when set to `false`. |
| `allow_open_symlinks_outside` | `false` | Allow opening and following symlinks outside the index base. When `false`, a message is shown instead. |
| `upload_enabled` | `false` | Show the upload form when auth credentials are configured. |
| `create_enabled` | `true` | When signed in as admin, show **New folder** / **New file** in the listing toolbar. Independent of `upload_enabled`. |
| `delete_enabled` | `true` | When signed in as admin, show a delete button on each listing row. Requires confirmation; folders are removed recursively. |
| `browse_requires_auth` | `false` | When `true`, visitors must sign in to browse listings or open files through the index. Share links are not affected. |
| `auth_username` | — | Admin login username. |
| `auth_password_hash` | — | Password hash from `password_hash()` (not plain text). |
| `upload_max_bytes` | `0` | Per-file upload limit in bytes; `0` uses PHP/web-server limits. |
| `path_whitelist` | `[]` | When non-empty, only matching paths are visible/browsable (see below). |
| `path_blacklist` | `[]` | Matching paths hidden from listings and blocked from index access (see below). |
| `web_root_url` | *(auto)* | Public URL base for **Open in new tab** links to files and folders (absolute URL or path from the site root). Saved during setup from the current request; leave empty in Settings to keep auto-detecting from the index script path. |
| `listing_from_document_root` | `false` | When `true`, the listing root follows `DOCUMENT_ROOT` heuristics (web root, or its parent when the script is in or symlinked from the doc root). When `false`, the listing root is the folder containing `index.php`. |

**Path access** (Settings → Server settings when signed in as admin):

- One relative path per line (from the index root). Lines starting with `#` are ignored.
- **Folder path** (trailing slash or contains `/`, e.g. `public/` or `backups/old`): matches that path and everything beneath it.
- **Name rule** (no slash, e.g. `.git` or `.env`): matches any file or folder with that name anywhere in the tree.
- **Path blacklist** — matching paths are omitted from listings and cannot be opened, previewed, downloaded, uploaded to, or shared through the index (unless opened via a valid share link).
- **Path whitelist** — when non-empty, only whitelisted paths (and parent folders needed to reach them) are visible and browsable; everything else is hidden/blocked. When empty, no whitelist restriction applies (blacklist still applies).
- Legacy `hidden_paths` values are treated as `path_blacklist` until settings are saved again.
- This applies to the directory index only; protect sensitive files at the web-server level if they must not be reachable by direct URL.

**Access control** (Settings → Server settings when signed in as admin):

- **IP whitelist** — one IP or CIDR per line (e.g. `192.168.0.0/16`). If non-empty, only these addresses may browse the index; others get 403. Loopback (`127.0.0.0/8`, `::1`) is always allowed so local access and `php -S` still work.
- **IP blacklist** — one IP or CIDR per line. Matching addresses are denied unless they open a valid share link. Loopback is never blocked.
- **Client IP header** — when behind a reverse proxy, choose the header that carries the real client IP (e.g. X-Forwarded-For). If unset and the connection comes from a private/local address (typical reverse-proxy setup), the app automatically uses `X-Real-IP` or `X-Forwarded-For` instead of the proxy's `REMOTE_ADDR`.

These keys can also be edited in `.dirindex.sqlite` or `.dirindex.json` if needed.

**Reset:** Signed-in admins can use **Settings → Reset** to delete the settings file and return to first-run setup (admin account, access rules, and share links are removed; indexed files are not deleted).

**Share links:** When signed in as admin (and PDO SQLite is available), use the share button on any file or folder to create a public link. Share links use a secret token in the URL (`?share=…`) and **bypass IP and path whitelist/blacklist** so recipients outside your network can view the shared item. Directory shares allow browsing inside that folder only; file shares open a landing page with a download button (text files may also show a preview). Optional expiry: never, 1 day, 7 days, or 30 days. Revoke links from the **Shared links** button (link icon) in the page header.

Example URLs:

- File: `index.php?share=TOKEN` (landing page), `index.php?share=TOKEN&download=1` (download)
- Directory: `index.php?share=TOKEN`, `index.php?share=TOKEN&path=shared/subfolder`

To create a password hash manually:

```sh
php -r "echo password_hash('change-me', PASSWORD_DEFAULT), PHP_EOL;"
```

PHP settings such as `upload_max_filesize` and `post_max_size` still apply.

No dependencies—just drop the file and run.

## `index.php` vs `index.min.php`

Both files are the same application. Use whichever fits your workflow:

| | `index.php` | `index.min.php` |
|--|-------------|-----------------|
| **Purpose** | Development and patching | Smaller production drop-in |
| **Size** | Full source (~200 KB) | Minified (~145 KB; ~10–15% smaller over gzip) |
| **Readable** | Yes | No (minified CSS, JS, HTML; stripped comments) |
| **In git** | Yes | No (built at release time) |

`index.min.php` is generated from `index.php` — like `app.min.js` beside `app.js`. Settings (`.dirindex.sqlite` / `.dirindex.json`) are created next to whichever filename you deploy; the app does not require the file to be named `index.php`.

**Download:** GitHub Releases include both files and a zip. **Build locally:**

```sh
php scripts/build-min.php          # writes index.min.php
php scripts/build-min.php --check  # exit 1 if index.min.php is missing or stale
```

Requires PHP only (no npm). After editing `index.php`, rebuild before shipping `index.min.php`.

## Development and releases

Two remotes: **GitLab** (`origin`) for day-to-day work, **GitHub** (`github`) for public visibility and releases.

**One-time setup:**

```sh
git remote add github git@github.com:Darknetzz/php-dirindex.git
# first push only (GitHub repo must exist and be empty):
git push -u github main
```

**Day-to-day:**

```sh
git push origin main
git push github main
```

Or push both at once:

```sh
git push origin main && git push github main
```

**Publish a release** (script builds locally, tags, pushes to both remotes; GitHub Actions attaches release files):

```sh
./scripts/release.sh                    # prompt; defaults to last tag + 1 patch (v1.0.0 → v1.0.1)
./scripts/release.sh v1.0.0
./scripts/release.sh v1.2.0 "Optional tag message"
./scripts/release.sh --dry-run          # preview only
```

The script finalizes `CHANGELOG.md` (moves `[Unreleased]` notes under the new version), commits that update, runs `scripts/build-min.php`, creates an annotated tag (message defaults to that version's CHANGELOG section), pushes `main` if needed, then pushes the tag to GitLab and GitHub. GitHub Actions publishes `index.php`, `index.min.php`, and a zip to the GitHub Release page, with the release description taken from the same CHANGELOG section.

Release notes live in [CHANGELOG.md](CHANGELOG.md). Add bullets under `## [Unreleased]` as you make changes.
