# PHP Directory Index

A single-file directory index that lists files and folders in a dark-themed, readable layout.

- **Place** `index.php` in any folder (or document root) and open it in a browser.
- **Lists** the current directory with name, size, and modified date. Directories appear first, then files (alphabetically).
- **Navigate** subfolders via `?path=subfolder`; breadcrumbs and a ".." row let you go back. Path traversal is restricted to the base directory.
- **Upload** files after setting up the built-in upload login. Existing filenames require confirmation before overwrite.
- **Requires** PHP and a web server. Works when the script is symlinked or in a subdirectory; it uses `DOCUMENT_ROOT` when available so the index can show the server root.

**Optional config:** Copy `config.php.example` to `config.php` in the same folder as `index.php` to set:

- `show_symlinks` — set to `false` to hide symlinks from the listing (default: `true`).
- `allow_open_symlinks_outside` — set to `true` to allow opening and following symlinks that point outside the index base (default: `false`; when false, a message is shown instead).
- `ip_whitelist` — array of IPs or CIDR ranges (e.g. `['127.0.0.1', '192.168.0.0/16']`). If non-empty, only these addresses may access the index; others get 403.
- `ip_blacklist` — array of IPs or CIDR ranges. If the client IP matches any entry, access is denied with 403.
- `ip_header` — when behind a reverse proxy, set to the header that holds the client IP (e.g. `'HTTP_X_FORWARDED_FOR'`). Otherwise `REMOTE_ADDR` is used.

**Share links:** When signed in as admin (and PDO SQLite is available), use the share button on any file or folder to create a public link. Share links use a secret token in the URL (`?share=…`) and **bypass IP whitelist/blacklist** so recipients outside your network can view the shared item. Directory shares allow browsing inside that folder only; file shares open a landing page with a download button (text files may also show a preview). Optional expiry: never, 1 day, 7 days, or 30 days. Revoke links from **Settings → Shared links**.

Example URLs:

- File: `index.php?share=TOKEN` (landing page), `index.php?share=TOKEN&download=1` (download)
- Directory: `index.php?share=TOKEN`, `index.php?share=TOKEN&path=shared/subfolder`

**Uploads:** On first run, the page can create an upload admin account. When PDO SQLite is available, upload settings are stored in `.dirindex.sqlite` next to `index.php`; otherwise the page writes the same settings to `config.php`.

Upload settings include:

- `upload_enabled` — enables the upload form when auth credentials are configured.
- `auth_username` — upload login username.
- `auth_password_hash` — password hash created with `password_hash()`.
- `upload_max_bytes` — optional per-file limit; `0` means use PHP/web-server limits.

To create a password hash manually:

```sh
php -r "echo password_hash('change-me', PASSWORD_DEFAULT), PHP_EOL;"
```

PHP settings such as `upload_max_filesize` and `post_max_size` still apply.

No config or dependencies—just drop the file and run.
