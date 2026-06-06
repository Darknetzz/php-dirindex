# PHP Directory Index

A single-file directory index that lists files and folders in a dark-themed, readable layout.

- **Place** `index.php` in any folder (or document root) and open it in a browser.
- **Lists** the current directory with name, size, modified date, and Unix permissions. Directories appear first, then files (alphabetically). Click a column header to sort; use **Reset sort** to restore the default order. Use the **Columns** menu above the listing to show or hide optional columns (saved in the browser).
- **Navigate** subfolders via `?path=subfolder`; breadcrumbs and a ".." row let you go back. Path traversal is restricted to the base directory.
- **Upload** files after setting up the built-in upload login. Existing filenames require confirmation before overwrite.
- **Create** empty folders and files in the current directory when signed in as admin (toolbar buttons in the listing).
- **Requires** PHP and a web server. Works when the script is symlinked or in a subdirectory; it uses `DOCUMENT_ROOT` when available so the index can show the server root.

**Settings storage:** Drop in only `index.php`. On first run, the setup wizard saves upload credentials and other settings locally. You can optionally enable **private-network browsing** during setup (RFC1918, link-local, and IPv6 private ranges). If you complete setup from a public IP, your current address is added to the whitelist automatically so you are not locked out.

- **PDO SQLite available (recommended):** `.dirindex.sqlite` next to `index.php` (upload settings, share links, and UI-managed options).
- **No SQLite:** `.dirindex.json` in the same folder (upload and UI-managed options; share links require SQLite).

If you still have a legacy `config.php`, missing keys are imported into the active store on first request. You can delete `config.php` afterward.

**Configurable options** (via the admin settings UI, or by editing `.dirindex.json` when SQLite is unavailable):

| Key | Default | Description |
|-----|---------|-------------|
| `show_symlinks` | `true` | Hide symlinks from the listing when set to `false`. |
| `allow_open_symlinks_outside` | `false` | Allow opening and following symlinks outside the index base. When `false`, a message is shown instead. |
| `upload_enabled` | `false` | Show the upload form when auth credentials are configured. |
| `create_enabled` | `true` | When signed in as admin, show **New folder** / **New file** in the listing toolbar. Independent of `upload_enabled`. |
| `auth_username` | — | Admin login username. |
| `auth_password_hash` | — | Password hash from `password_hash()` (not plain text). |
| `upload_max_bytes` | `0` | Per-file upload limit in bytes; `0` uses PHP/web-server limits. |

**Access control** (Settings → Server settings when signed in as admin):

- **IP whitelist** — one IP or CIDR per line (e.g. `192.168.0.0/16`). If non-empty, only these addresses may browse the index; others get 403. Loopback (`127.0.0.0/8`, `::1`) is always allowed so local access and `php -S` still work.
- **IP blacklist** — one IP or CIDR per line. Matching addresses are denied unless they open a valid share link. Loopback is never blocked.
- **Client IP header** — when behind a reverse proxy, choose the header that carries the real client IP (e.g. X-Forwarded-For). If unset and the connection comes from a private/local address (typical reverse-proxy setup), the app automatically uses `X-Real-IP` or `X-Forwarded-For` instead of the proxy's `REMOTE_ADDR`.

These keys can also be edited in `.dirindex.sqlite` or `.dirindex.json` if needed.

**Share links:** When signed in as admin (and PDO SQLite is available), use the share button on any file or folder to create a public link. Share links use a secret token in the URL (`?share=…`) and **bypass IP whitelist/blacklist** so recipients outside your network can view the shared item. Directory shares allow browsing inside that folder only; file shares open a landing page with a download button (text files may also show a preview). Optional expiry: never, 1 day, 7 days, or 30 days. Revoke links from the **Shared links** button (link icon) in the page header.

Example URLs:

- File: `index.php?share=TOKEN` (landing page), `index.php?share=TOKEN&download=1` (download)
- Directory: `index.php?share=TOKEN`, `index.php?share=TOKEN&path=shared/subfolder`

To create a password hash manually:

```sh
php -r "echo password_hash('change-me', PASSWORD_DEFAULT), PHP_EOL;"
```

PHP settings such as `upload_max_filesize` and `post_max_size` still apply.

No dependencies—just drop the file and run.

**Release build:** To generate a smaller deploy artifact (like `*.min.js`), run `php scripts/build-min.php` from the repo root. It writes `index.min.php` next to `index.php` with minified CSS, JavaScript, and HTML plus stripped PHP comments. Edit `index.php` only; rebuild before shipping the minified copy. Use `php scripts/build-min.php --check` in CI to ensure the artifact is current.

## Development and releases

**Source of truth:** GitLab (`gitlab.kriss.li`) is the primary remote. GitHub is a read-only mirror for visibility and published releases.

**Mirror GitLab → GitHub** (one-time setup on GitLab):

1. Create an empty GitHub repo (no README/license).
2. On GitLab: **Settings → Repository → Mirroring repositories → Add new**.
3. **Git repository URL:** `https://github.com/YOU/php-dirindex.git`
4. **Mirror direction:** Push
5. **Authentication:** GitHub fine-grained or classic PAT with **Contents: Read and write**.
6. Enable mirroring. GitLab pushes `main` and tags after each update.

Do not push commits directly to GitHub; always push to GitLab and let the mirror propagate.

**Publish a release:**

```sh
# on main, after changes are pushed to GitLab
git tag -a v1.0.0 -m "v1.0.0"
git push origin v1.0.0
```

After the mirror runs, GitHub Actions builds `index.min.php` and attaches `index.php`, `index.min.php`, and a zip to the GitHub Release for that tag.
