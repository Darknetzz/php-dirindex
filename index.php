<?php
/**
 * Simple dark-mode directory index.
 * Place in any folder and open in browser (requires PHP).
 */

// Use document root so the index always lists the web server root (works when script is symlinked or in a subdir)
$baseDir = __DIR__;
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($docRoot) {
        $baseDir = $docRoot;
    }
}
$realBase = realpath($baseDir);

// Subdirectory path from query (e.g. index.php?path=foo/bar)
$relativePath = isset($_GET['path']) ? trim((string) $_GET['path'], '/') : '';
if ($relativePath !== '') {
    $currentPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $realCurrent = realpath($currentPath);
    if ($realCurrent === false || !is_dir($realCurrent) || strpos($realCurrent, $realBase) !== 0) {
        $currentPath = $baseDir;
        $relativePath = '';
    } else {
        $currentPath = $realCurrent;
    }
} else {
    $currentPath = $baseDir;
}

$parentPath = dirname($currentPath);
$hasParent = $relativePath !== '' && (strpos(realpath($parentPath), $realBase) === 0);

$items = [];
$handle = @opendir($currentPath);
if ($handle) {
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $currentPath . DIRECTORY_SEPARATOR . $entry;
        $items[] = [
            'name'   => $entry,
            'path'   => $relativePath ? $relativePath . '/' . $entry : $entry,
            'isDir'  => is_dir($full),
            'size'   => is_file($full) ? filesize($full) : null,
            'mtime'  => filemtime($full),
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
                <a href="?">/</a>
                <?php
                $segments = $relativePath ? explode('/', $relativePath) : [];
                $acc = '';
                foreach ($segments as $seg):
                    $acc .= ($acc ? '/' : '') . $seg;
                ?>
                    &nbsp;/&nbsp;<a href="?path=<?= h(rawurlencode($acc)) ?>"><?= h($seg) ?></a>
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
                            <a href="?<?= $parentRel !== '' ? 'path=' . h(rawurlencode($parentRel)) : '' ?>">
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
                            ? '?path=' . rawurlencode($item['path'])
                            : ($relativePath ? $relativePath . '/' : '') . rawurlencode($item['name']);
                        $nameClass = $item['isDir'] ? 'dir' : '';
                    ?>
                    <tr>
                        <td class="name <?= $nameClass ?>">
                            <a href="<?= h($url) ?>">
                                <?php if ($item['isDir']): ?>
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                <?php else: ?>
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                <?php endif; ?>
                                <?= h($item['name']) ?>
                            </a>
                        </td>
                        <td class="size"><?= $item['isDir'] ? '—' : h(formatSize($item['size'])) ?></td>
                        <td class="date"><?= date('Y-m-d H:i', $item['mtime']) ?></td>
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
