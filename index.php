<?php
/**
 * Simple dark-mode directory index.
 * Place in any folder and open in browser (requires PHP).
 */

// Listing root: prefer document root, but when the script is the index of a subfolder (or symlinked from it),
// use that folder's parent so ?path=dokuwiki etc. list siblings.
$baseDir = __DIR__;
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $docRootReal = realpath($_SERVER['DOCUMENT_ROOT']);
    $scriptDir = realpath($baseDir);
    if ($docRootReal) {
        $scriptInDocRoot = ($scriptDir === $docRootReal || strpos($scriptDir, $docRootReal . DIRECTORY_SEPARATOR) === 0);
        if (!$scriptInDocRoot) {
            // Script is symlinked from doc root (e.g. /var/www/html/php-dirindex/index.php -> project): list doc root's parent
            $parent = dirname($docRootReal);
            if ($parent && $parent !== $docRootReal) {
                $baseDir = $parent;
            }
        } elseif ($scriptDir === $docRootReal) {
            // Script is inside doc root; doc root is a subfolder (e.g. php-dirindex): list its parent
            $parent = dirname($docRootReal);
            if ($parent && $parent !== $docRootReal) {
                $baseDir = $parent;
            }
        } else {
            $baseDir = $docRootReal;
        }
    }
}
$realBase = realpath($baseDir);

// Base URL for links (absolute path so ?path= links work regardless of rewrites)
$indexHref = (isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '') ? $_SERVER['SCRIPT_NAME'] : '/index.php';

// Subdirectory path from query (e.g. index.php?path=foo/bar)
$relativePath = isset($_GET['path']) ? trim((string) $_GET['path'], '/') : '';
// Reject directory traversal; allow symlinked dirs (realpath may resolve outside realBase)
if ($relativePath !== '' && strpos($relativePath, '..') !== false) {
    $relativePath = '';
}
if ($relativePath !== '') {
    $currentPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $realCurrent = realpath($currentPath);
    if ($realCurrent === false || !is_dir($currentPath)) {
        $currentPath = $baseDir;
        $relativePath = '';
    } else {
        $currentPath = $realCurrent;
    }
} else {
    $currentPath = $baseDir;
}

$parentPath = dirname($currentPath);
// Has parent if we have a logical parent in the path (so ".." works even inside symlinked dirs)
$hasParent = $relativePath !== '';

$items = [];
$handle = @opendir($currentPath);
if ($handle) {
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $currentPath . DIRECTORY_SEPARATOR . $entry;
        $isLink = is_link($full);
        $mtime = @filemtime($full);
        if ($mtime === false) {
            $mtime = $isLink && file_exists($full) ? @filemtime(realpath($full)) : null;
        }
        $items[] = [
            'name'   => $entry,
            'path'   => $relativePath ? $relativePath . '/' . $entry : $entry,
            'isDir'  => is_dir($full),
            'isLink' => $isLink,
            'size'   => is_file($full) ? filesize($full) : null,
            'mtime'  => $mtime,
        ];
    }
    closedir($handle);
}

usort($items, function ($a, $b) {
    if ($a['isDir'] !== $b['isDir']) return $a['isDir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});

function formatSize($bytes) {
    if ($bytes === null || $bytes < 0) return '—';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i ? 2 : 0) . ' ' . $units[$i];
}

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$title = $relativePath ? 'Index of /' . h($relativePath) : 'Index of /';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0d0d0f;
            --bg-card: #141417;
            --border: #25252a;
            --text: #e4e4e7;
            --text-muted: #71717a;
            --accent: #a78bfa;
            --accent-dim: #7c3aed;
            --dir-color: #67e8f9;
            --hover: #27272a;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: 'Outfit', system-ui, sans-serif;
            font-size: 15px;
            line-height: 1.5;
        }

        .page {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        h1 {
            font-weight: 600;
            font-size: 1.25rem;
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
            word-break: break-all;
        }
        h1 strong { color: var(--text); }

        .breadcrumb {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        .breadcrumb a {
            color: var(--accent);
            text-decoration: none;
        }
        .breadcrumb a:hover { text-decoration: underline; }

        .listing {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .listing table {
            width: 100%;
            border-collapse: collapse;
        }

        .listing th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            background: rgba(0,0,0,0.2);
            border-bottom: 1px solid var(--border);
        }
        .listing th:last-child { text-align: right; }

        .listing td {
            padding: 0.65rem 1rem;
            border-bottom: 1px solid var(--border);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.9rem;
        }
        .listing tr:last-child td { border-bottom: none; }
        .listing tr:hover td { background: var(--hover); }

        .listing .name a {
            color: var(--text);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .listing .name a:hover { color: var(--accent); }
        .listing .name .dir a { color: var(--dir-color); }
        .listing .name .dir a:hover { color: #22d3ee; }
        .listing .name.symlink a { color: var(--accent-dim); }
        .listing .name.symlink a:hover { color: var(--accent); }

        .listing .size, .listing .date {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .listing .size { text-align: right; }

        .icon {
            width: 1.1em;
            height: 1.1em;
            flex-shrink: 0;
            opacity: 0.9;
        }

        footer {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="page">
        <header>
            <h1>Index of <strong>/<?= h($relativePath ?: '') ?></strong></h1>
            <nav class="breadcrumb">
                <a href="<?= h($indexHref) ?>">/</a>
                <?php
                $segments = $relativePath ? explode('/', $relativePath) : [];
                $acc = '';
                foreach ($segments as $seg):
                    $acc .= ($acc ? '/' : '') . $seg;
                ?>
                    &nbsp;/&nbsp;<a href="<?= h($indexHref) ?>?path=<?= h(rawurlencode($acc)) ?>"><?= h($seg) ?></a>
                <?php endforeach; ?>
            </nav>
        </header>

        <div class="listing">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="size">Size</th>
                        <th>Modified</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($hasParent): $parentRel = dirname($relativePath); $parentRel = ($parentRel === '.' || $parentRel === '') ? '' : $parentRel; ?>
                    <tr>
                        <td class="name dir">
                            <a href="<?= h($indexHref) ?><?= $parentRel !== '' ? '?path=' . h(rawurlencode($parentRel)) : '' ?>">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                ..
                            </a>
                        </td>
                        <td class="size">—</td>
                        <td class="date">—</td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($items as $item):
                        $url = $item['isDir']
                            ? $indexHref . '?path=' . rawurlencode($item['path'])
                            : '/' . ($relativePath ? $relativePath . '/' : '') . rawurlencode($item['name']);
                        $nameClass = ($item['isDir'] ? 'dir ' : '') . ($item['isLink'] ? 'symlink' : '');
                    ?>
                    <tr>
                        <td class="name <?= trim($nameClass) ?>">
                            <a href="<?= h($url) ?>">
                                <?php if ($item['isLink']): ?>
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" title="Symbolic link"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?php elseif ($item['isDir']): ?>
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                <?php else: ?>
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                <?php endif; ?>
                                <?= h($item['name']) ?>
                            </a>
                        </td>
                        <td class="size"><?= $item['isDir'] ? '—' : h(formatSize($item['size'])) ?></td>
                        <td class="date"><?= ($item['mtime'] !== null && $item['mtime'] > 0) ? date('Y-m-d H:i', $item['mtime']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <footer>
            <?= count($items) + ($hasParent ? 1 : 0) ?> item(s) &nbsp;·&nbsp; PHP directory index
        </footer>
    </div>
</body>
</html>
