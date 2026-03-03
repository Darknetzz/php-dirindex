# PHP Directory Index

A single-file directory index that lists files and folders in a dark-themed, readable layout.

- **Place** `index.php` in any folder (or document root) and open it in a browser.
- **Lists** the current directory with name, size, and modified date. Directories appear first, then files (alphabetically).
- **Navigate** subfolders via `?path=subfolder`; breadcrumbs and a ".." row let you go back. Path traversal is restricted to the base directory.
- **Requires** PHP and a web server. Works when the script is symlinked or in a subdirectory; it uses `DOCUMENT_ROOT` when available so the index can show the server root.

No config or dependencies—just drop the file and run.
