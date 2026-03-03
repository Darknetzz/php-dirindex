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
// Text file extensions: open in modal. Value = highlight.js language (or 'plaintext').
$textExts = [
    'md' => 'markdown', 'markdown' => 'markdown',
    'html' => 'markup', 'htm' => 'markup',
    'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript',
    'css' => 'css', 'scss' => 'scss', 'sass' => 'sass', 'less' => 'less',
    'json' => 'json', 'xml' => 'xml', 'yaml' => 'yaml', 'yml' => 'yaml',
    'php' => 'php', 'py' => 'python', 'rb' => 'ruby', 'sh' => 'bash', 'bash' => 'bash', 'zsh' => 'bash',
    'sql' => 'sql', 'csv' => 'plaintext', 'txt' => 'plaintext', 'log' => 'plaintext',
    'env' => 'plaintext', 'ini' => 'ini', 'cfg' => 'plaintext', 'conf' => 'plaintext',
    'ts' => 'typescript', 'tsx' => 'typescript', 'jsx' => 'javascript',
    'vue' => 'xml', 'rst' => 'rest',
];
$previewExts = $textExts; // used for content API and listing "previewable" check

/**
 * Heuristic: file is likely binary if it contains null bytes in the first chunk.
 * Used for unknown/no extension so we can still offer modal for plain text files.
 */
function looksLikeBinary($absolutePath, $maxLen = 8192) {
    if (!is_file($absolutePath) || filesize($absolutePath) === 0) return false;
    $f = @fopen($absolutePath, 'rb');
    if (!$f) return true; // assume binary if unreadable
    $chunk = @fread($f, $maxLen);
    fclose($f);
    return $chunk === false || str_contains($chunk, "\0");
}

if ($relativePath !== '') {
    $requestedPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
    $isMdFullPage = ($ext === 'md' && !isset($_GET['content']));
    if (is_file($requestedPath) && $isMdFullPage) {
        $md = @file_get_contents($requestedPath);
        if ($md !== false) {
            header('Content-Type: text/html; charset=UTF-8');
            echo renderMarkdownPage($md, $relativePath, $indexHref);
            exit;
        }
    }
    if (is_file($requestedPath) && isset($_GET['content'])) {
        $raw = @file_get_contents($requestedPath);
        if ($raw !== false) {
            $lang = isset($previewExts[$ext]) ? $previewExts[$ext] : 'plaintext';
            if (!isset($previewExts[$ext]) && (looksLikeBinary($requestedPath) || filesize($requestedPath) > 512 * 1024)) {
                $raw = false; // refuse to send likely binary or large unknown files
            }
            if ($raw !== false) {
                header('Content-Type: application/json; charset=UTF-8');
                $out = ['content' => $raw, 'lang' => $lang, 'name' => basename($relativePath)];
                if ($ext === 'md' || $ext === 'markdown') {
                    $out['html'] = markdownToHtml($raw);
                }
                echo json_encode($out);
                exit;
            }
        }
    }
    $realCurrent = realpath($requestedPath);
    if ($realCurrent === false || !is_dir($requestedPath)) {
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
clearstatcache(true);
$handle = @opendir($currentPath);
if ($handle) {
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $currentPath . DIRECTORY_SEPARATOR . $entry;
        $isLink = is_link($full);
        $linkTarget = $isLink ? @readlink($full) : null;
        if ($linkTarget === false) {
            $linkTarget = null;
        }
        $stat = @stat($full);
        $mtime = ($stat !== false && isset($stat['mtime'])) ? (int) $stat['mtime'] : null;
        if ($mtime === null && $isLink && file_exists($full)) {
            $stat = @stat(realpath($full));
            $mtime = ($stat !== false && isset($stat['mtime'])) ? (int) $stat['mtime'] : null;
        }
        $isFile = is_file($full);
        $ext = $isFile ? strtolower(pathinfo($entry, PATHINFO_EXTENSION)) : '';
        $isText = $isFile && (isset($previewExts[$ext]) || !looksLikeBinary($full));
        $items[] = [
            'name'       => $entry,
            'path'       => $relativePath ? $relativePath . '/' . $entry : $entry,
            'isDir'      => is_dir($full),
            'isLink'     => $isLink,
            'linkTarget' => $linkTarget,
            'size'       => $isFile ? filesize($full) : null,
            'mtime'      => $mtime,
            'isText'     => $isText,
            'ext'        => $ext,
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

/**
 * Simple markdown to HTML (headers, bold, italic, code, links, code blocks, lists).
 */
function markdownToHtml($text) {
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $lines = explode("\n", $text);
    $out = '';
    $inBlock = false;
    $inList = false;
    foreach ($lines as $i => $line) {
        $raw = $line;
        if (preg_match('/^```(\w*)\s*$/', $line, $m)) {
            if ($inBlock) {
                $out .= "</code></pre>\n";
                $inBlock = false;
            } else {
                $out .= '<pre><code class="' . $h($m[1]) . '">';
                $inBlock = true;
            }
            continue;
        }
        if ($inBlock) {
            $out .= $h($line) . "\n";
            continue;
        }
        if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
            $l = strlen(strtok($line, ' '));
            $out .= "<h{$l}>" . markdownInline($m[1], $h) . "</h{$l}>\n";
            $inList = false;
            continue;
        }
        if (preg_match('/^[\-\*]\s+\[([ xX])\]\s+(.+)$/', $line, $m)) {
            if (!$inList) {
                $out .= "<ul>\n";
                $inList = true;
            }
            $checked = (strtolower($m[1]) === 'x');
            $out .= '<li class="task-list-item">'
                . '<input type="checkbox" class="task-list-item-checkbox" disabled' . ($checked ? ' checked' : '') . '> '
                . markdownInline($m[2], $h) . "</li>\n";
            continue;
        }
        if (preg_match('/^[\-\*]\s+(.+)$/', $line, $m)) {
            if (!$inList) {
                $out .= "<ul>\n";
                $inList = true;
            }
            $out .= '<li>' . markdownInline($m[1], $h) . "</li>\n";
            continue;
        }
        if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
            if (!$inList) {
                $out .= "<ol>\n";
                $inList = true;
            }
            $out .= '<li>' . markdownInline($m[1], $h) . "</li>\n";
            continue;
        }
        if ($inList) {
            $out .= "</ul>\n";
            $inList = false;
        }
        if (trim($line) === '') {
            $out .= "\n";
            continue;
        }
        $out .= '<p>' . markdownInline($line, $h) . "</p>\n";
    }
    if ($inList) {
        $out .= "</ul>\n";
    }
    if ($inBlock) {
        $out .= "</code></pre>\n";
    }
    return $out;
}

function markdownInline($s, $h) {
    $s = $h($s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    $s = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $s);
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $s);
    return $s;
}

function renderMarkdownPage($md, $relativePath, $indexHref) {
    $title = basename($relativePath);
    $parentPath = dirname($relativePath);
    $parentPath = ($parentPath === '.' || $parentPath === '') ? '' : $parentPath;
    $backUrl = $indexHref . ($parentPath !== '' ? '?path=' . rawurlencode($parentPath) : '');
    $body = markdownToHtml($md);
    $css = '
    :root { --bg: #0d0d0f; --bg-card: #141417; --border: #25252a; --text: #e4e4e7; --text-muted: #71717a; --accent: #a78bfa; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; font-size: 15px; line-height: 1.6; padding: 2rem; }
    .page { max-width: 800px; margin: 0 auto; }
    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }
    .back { margin-bottom: 1.5rem; font-size: 0.9rem; color: var(--text-muted); }
    h1,h2,h3,h4,h5,h6 { margin-top: 1.5em; margin-bottom: 0.5em; }
    pre { background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; overflow-x: auto; }
    code { font-family: ui-monospace, monospace; font-size: 0.9em; }
    p code { background: var(--bg-card); padding: 0.2em 0.4em; border-radius: 4px; }
    ul, ol { margin: 0.5em 0; padding-left: 1.5rem; }
    .task-list-item { list-style: none; margin-left: -1.5rem; }
    .task-list-item-checkbox { margin: 0 0.4em 0 0; vertical-align: middle; cursor: default; width: 1.1em; height: 1.1em; border: 1px solid var(--text-muted); background: var(--bg); border-radius: 3px; accent-color: var(--accent); }
    .task-list-item-checkbox:checked { background: #2e1065; border-color: var(--accent); }
    ';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . '</title><style>' . $css . '</style></head><body><div class="page"><div class="back"><a href="' . h($backUrl) . '">← Back to listing</a></div><div class="md">' . $body . '</div></div></body></html>';
}

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
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
        .breadcrumb-sep { color: var(--text-muted); margin: 0 0.35em; font-weight: 400; user-select: none; }

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
        .listing .name a.file-preview { cursor: pointer; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center; padding: 2rem; box-sizing: border-box; }
        .modal-overlay.is-open { display: flex; }
        .modal { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; max-width: 95vw; max-height: 85vh; width: 1200px; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .modal-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); flex-shrink: 0; }
        .modal-title-wrap { display: flex; align-items: center; gap: 0.75rem; flex: 1; min-width: 0; }
        .modal-title { font-family: 'JetBrains Mono', monospace; font-size: 0.9rem; color: var(--text); word-break: break-all; }
        .modal-open-link { font-size: 0.8rem; color: var(--accent); text-decoration: none; white-space: nowrap; flex-shrink: 0; }
        .modal-open-link:hover { text-decoration: underline; }
        .modal-close { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0.25rem; line-height: 1; border-radius: 4px; }
        .modal-close:hover { color: var(--text); background: var(--hover); }
        .modal-body { overflow: auto; padding: 1rem; flex: 1; min-height: 0; }
        .modal-body pre { margin: 0; font-size: 0.85rem; }
        .modal-body code { font-family: 'JetBrains Mono', monospace; }
        .modal-body .modal-md { display: none; }
        .modal-body .modal-md.is-visible { display: block; }
        .modal-body .modal-md h1,.modal-body .modal-md h2,.modal-body .modal-md h3 { margin-top: 1em; margin-bottom: 0.5em; }
        .modal-body .modal-md pre { background: rgba(0,0,0,0.2); border-radius: 6px; padding: 0.75rem; margin: 0.5em 0; }
        .modal-body .modal-md p { margin: 0.5em 0; }
        .modal-body .modal-md ul, .modal-body .modal-md ol { margin: 0.5em 0; padding-left: 1.5rem; }
        .modal-body .modal-md .task-list-item { list-style: none; margin-left: -1.5rem; }
        .modal-body .modal-md .task-list-item-checkbox { margin: 0 0.4em 0 0; vertical-align: middle; cursor: default; width: 1.1em; height: 1.1em; border: 1px solid var(--text-muted); background: var(--bg); border-radius: 3px; accent-color: var(--accent); }
        .modal-body .modal-md .task-list-item-checkbox:checked { background: var(--accent-dim); border-color: var(--accent); }
        .listing .name.binary a { color: var(--text-muted); }
        .listing .name.binary a:hover { color: var(--accent); }

        .listing .size, .listing .modified {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .listing .size { text-align: right; }
        .listing .col-modified { min-width: 10rem; }
        .listing td.modified, .listing th.modified { white-space: nowrap; }

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
                    <span class="breadcrumb-sep" aria-hidden="true">›</span><a href="<?= h($indexHref) ?>?path=<?= h(rawurlencode($acc)) ?>"><?= h($seg) ?></a>
                <?php endforeach; ?>
            </nav>
        </header>

        <div class="listing">
            <table>
                <colgroup>
                    <col class="col-name">
                    <col class="col-size">
                    <col class="col-modified">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col" class="size">Size</th>
                        <th scope="col" class="modified">Modified</th>
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
                        <td class="size">&#8212;</td>
                        <td class="modified">&#8212;</td>
                    </tr>
                    <?php endif; ?>

                    <?php
                    foreach ($items as $item):
                        if ($item['isDir']) {
                            $url = $indexHref . '?path=' . rawurlencode($item['path']);
                            $linkAttrs = '';
                        } else {
                            if (!empty($item['isText'])) {
                                $url = $indexHref . '?path=' . rawurlencode($item['path']);
                                $openUrl = ($item['ext'] === 'md' || $item['ext'] === 'markdown')
                                    ? $indexHref . '?path=' . rawurlencode($item['path'])
                                    : '/' . ($relativePath ? $relativePath . '/' : '') . rawurlencode($item['name']);
                                $linkAttrs = ' class="file-preview" data-content-url="' . h($indexHref . '?path=' . rawurlencode($item['path']) . '&content=1') . '" data-name="' . h($item['name']) . '" data-open-url="' . h($openUrl) . '"';
                            } else {
                                $url = '/' . ($relativePath ? $relativePath . '/' : '') . rawurlencode($item['name']);
                                $linkAttrs = ' class="file-binary" title="Binary file (opens in new tab)" target="_blank" rel="noopener noreferrer"';
                            }
                        }
                        $nameClass = ($item['isDir'] ? 'dir ' : '') . ($item['isLink'] ? 'symlink ' : '') . ((!$item['isDir'] && empty($item['isText'])) ? 'binary' : '');
                    ?>
                    <tr>
                        <td class="name <?= trim($nameClass) ?>">
                            <a href="<?= h($url) ?>"<?= $linkAttrs ?><?= ($item['isLink'] && !empty($item['linkTarget'])) ? ' title="' . h($item['linkTarget']) . '"' : '' ?>>
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
                        <td class="size"><?= $item['isDir'] ? '&#8212;' : h(formatSize($item['size'])) ?></td>
                        <td class="modified"><?php
                            $ts = isset($item['mtime']) ? $item['mtime'] : null;
                            if ($ts !== null && $ts > 0 && $ts <= 2147483647) {
                                echo h(date('Y-m-d H:i', $ts));
                            } else {
                                echo '&#8212;';
                            }
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <footer>
            <?= count($items) + ($hasParent ? 1 : 0) ?> item(s) &nbsp;·&nbsp; PHP directory index
        </footer>
    </div>

    <div id="file-modal" class="modal-overlay" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <span class="modal-title" id="modal-title"></span>
                    <a id="modal-open-link" class="modal-open-link" href="#" target="_blank" rel="noopener noreferrer" style="display: none;">Open in new tab</a>
                </div>
                <button type="button" class="modal-close" id="modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modal-md" class="modal-md" aria-hidden="true"></div>
                <pre id="modal-pre"><code id="modal-code"></code></pre>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/markdown.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/json.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/yaml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/bash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/typescript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/scss.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/ini.min.js"></script>
    <script>
    (function() {
        var overlay = document.getElementById('file-modal');
        var titleEl = document.getElementById('modal-title');
        var openLinkEl = document.getElementById('modal-open-link');
        var codeEl = document.getElementById('modal-code');
        var modalPre = document.getElementById('modal-pre');
        var modalMd = document.getElementById('modal-md');
        var closeBtn = document.getElementById('modal-close');

        function closeModal() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            modalMd.innerHTML = '';
            modalMd.classList.remove('is-visible');
            modalMd.setAttribute('aria-hidden', 'true');
            modalPre.style.display = '';
            openLinkEl.style.display = 'none';
            openLinkEl.removeAttribute('href');
        }
        function openModal(name, content, lang, html, openUrl) {
            titleEl.textContent = name;
            if (openUrl) {
                openLinkEl.href = openUrl;
                openLinkEl.style.display = '';
            } else {
                openLinkEl.style.display = 'none';
                openLinkEl.removeAttribute('href');
            }
            if (html) {
                modalMd.innerHTML = html;
                modalMd.classList.add('is-visible');
                modalMd.setAttribute('aria-hidden', 'false');
                modalPre.style.display = 'none';
            } else {
                modalMd.classList.remove('is-visible');
                modalMd.setAttribute('aria-hidden', 'true');
                modalPre.style.display = '';
                codeEl.textContent = content;
                codeEl.className = 'language-' + (lang === 'markup' ? 'html' : lang);
                codeEl.parentElement.classList.add('hljs');
                hljs.highlightElement(codeEl);
            }
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
        }

        document.addEventListener('click', function(e) {
            var a = e.target.closest('a.file-preview');
            if (!a) return;
            e.preventDefault();
            var url = a.getAttribute('data-content-url');
            var name = a.getAttribute('data-name') || '';
            if (!url) return;
            var openUrl = a.getAttribute('data-open-url') || '';
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                openModal(data.name || name, data.content || '', data.lang || 'plaintext', data.html || null, openUrl);
            }).catch(function() {
                window.location.href = a.href;
            });
        });

        closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
        });
    })();
    </script>
</body>
</html>
