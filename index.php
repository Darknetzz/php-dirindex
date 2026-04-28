<?php
/**
 * Simple dark-mode directory index.
 * Place in any folder and open in browser (requires PHP).
 */

header('X-Content-Type-Options: nosniff');

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
if ($realBase === false) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('HTTP/1.1 500 Internal Server Error');
    exit('Base directory is not accessible.');
}

/**
 * Check if an IP matches a single entry (exact address or CIDR, e.g. 192.168.1.0/24).
 */
function ipMatchesEntry($ip, $entry) {
    $entry = trim((string) $entry);
    if ($entry === '') return false;
    $ipBin = @inet_pton($ip);
    if ($ipBin === false) return false;
    $ipLen = strlen($ipBin);
    if (strpos($entry, '/') !== false) {
        $parts = explode('/', $entry, 2);
        $network = trim($parts[0]);
        $prefix = (int) $parts[1];
        $netBin = @inet_pton($network);
        if ($netBin === false || strlen($netBin) !== $ipLen || $prefix < 0 || $prefix > ($ipLen * 8)) return false;
        $fullBytes = (int) ($prefix / 8);
        $bits = $prefix % 8;
        for ($i = 0; $i < $fullBytes; $i++) {
            if ($ipBin[$i] !== $netBin[$i]) return false;
        }
        if ($bits > 0 && $fullBytes < $ipLen) {
            $mask = chr(0xFF << (8 - $bits));
            if ((ord($ipBin[$fullBytes]) & ord($mask)) !== (ord($netBin[$fullBytes]) & ord($mask))) return false;
        }
        return true;
    }
    $entryBin = @inet_pton($entry);
    return $entryBin !== false && $ipBin === $entryBin;
}

/**
 * Check if IP matches any entry in a list (each entry: exact IP or CIDR).
 */
function ipMatchesList($ip, array $list) {
    foreach ($list as $entry) {
        if (ipMatchesEntry($ip, $entry)) return true;
    }
    return false;
}

/**
 * Ensure a resolved path is under the base directory (prevents symlink escape).
 */
function pathUnderBase($resolved, $realBase) {
    if ($resolved === false || $resolved === '') return false;
    $sep = DIRECTORY_SEPARATOR;
    $baseNorm = rtrim($realBase, $sep);
    return $resolved === $baseNorm || strpos($resolved . $sep, $baseNorm . $sep) === 0;
}

function dirindexSqliteAvailable() {
    return extension_loaded('pdo_sqlite') && class_exists('PDO');
}

function dirindexSqlitePath($scriptDir) {
    return $scriptDir . DIRECTORY_SEPARATOR . '.dirindex.sqlite';
}

function dirindexOpenSqliteStore($path, &$error = null) {
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        return $pdo;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return null;
    }
}

function loadDirindexStoredConfig($scriptDir, &$storageInfo) {
    $storageInfo = [
        'type' => dirindexSqliteAvailable() ? 'sqlite' : 'config',
        'path' => dirindexSqliteAvailable() ? dirindexSqlitePath($scriptDir) : $scriptDir . DIRECTORY_SEPARATOR . 'config.php',
        'error' => null,
    ];
    if (!dirindexSqliteAvailable()) {
        return [];
    }

    $sqlitePath = dirindexSqlitePath($scriptDir);
    if (!is_file($sqlitePath)) {
        return [];
    }

    $pdo = dirindexOpenSqliteStore($sqlitePath, $storageInfo['error']);
    if (!$pdo) {
        return [];
    }

    $settings = [];
    try {
        $stmt = $pdo->query('SELECT key, value FROM settings');
        foreach ($stmt as $row) {
            $settings[$row['key']] = $row['value'];
        }
    } catch (Throwable $e) {
        $storageInfo['error'] = $e->getMessage();
    }
    return $settings;
}

function saveDirindexStoredConfig($scriptDir, $configFile, array $settings, &$error = null) {
    if (dirindexSqliteAvailable()) {
        $pdo = dirindexOpenSqliteStore(dirindexSqlitePath($scriptDir), $error);
        if (!$pdo) {
            return false;
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
            foreach ($settings as $key => $value) {
                $stmt->execute([':key' => (string) $key, ':value' => (string) $value]);
            }
            return true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    $existing = [];
    if (is_file($configFile) && is_readable($configFile)) {
        $existing = (array) include $configFile;
    }
    $data = array_merge($existing, $settings);
    $php = "<?php\n/**\n * Generated by PHP Directory Index.\n * You can edit this file manually, but changes made in the UI may overwrite matching keys.\n */\nreturn " . var_export($data, true) . ";\n";
    if (@file_put_contents($configFile, $php, LOCK_EX) === false) {
        $error = 'Unable to write ' . basename($configFile) . '.';
        return false;
    }
    return true;
}

function startDirindexSession($name = 'dirindex_upload') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_name($name);
    }
    @session_start();
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function isAuthenticated() {
    return !empty($_SESSION['dirindex_authenticated']);
}

function hasUploadCredentials(array $config) {
    return !empty($config['auth_username']) && !empty($config['auth_password_hash']);
}

function isUploadEnabled(array $config) {
    return !empty($config['upload_enabled']) && hasUploadCredentials($config);
}

function currentListingUrl($indexHref, $relativePath, array $params = []) {
    if ($relativePath !== '') {
        $params = array_merge(['path' => $relativePath], $params);
    }
    return $indexHref . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

function redirectToCurrentListing($indexHref, $relativePath, $messageKey = null) {
    $params = [];
    if ($messageKey !== null && $messageKey !== '') {
        $params['msg'] = $messageKey;
    }
    header('Location: ' . currentListingUrl($indexHref, $relativePath, $params));
    exit;
}

function cleanUploadFilename($name) {
    $name = trim((string) $name);
    if ($name === '' || $name === '.' || $name === '..') return null;
    if (str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')) return null;
    return $name;
}

function uploadErrorMessage($errorCode) {
    switch ((int) $errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'upload_too_large';
        case UPLOAD_ERR_NO_FILE:
            return 'upload_missing';
        case UPLOAD_ERR_PARTIAL:
            return 'upload_partial';
        default:
            return 'upload_failed';
    }
}

// Base URL for links (absolute path so ?path= links work regardless of rewrites)
$indexHref = (isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '') ? $_SERVER['SCRIPT_NAME'] : '/index.php';

// Optional config: create config.php in the same folder as this script, return an array (see README).
$dirindexConfig = [
    'show_symlinks'             => true,
    'allow_open_symlinks_outside' => false,
    'ip_whitelist'              => [],
    'ip_blacklist'              => [],
    'upload_enabled'            => false,
    'auth_username'             => '',
    'auth_password_hash'        => '',
    'upload_max_bytes'          => 0,
    'session_name'              => 'dirindex_upload',
];
$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (is_file($configFile) && is_readable($configFile)) {
    $userConfig = (function () use ($configFile) {
        return (array) include $configFile;
    })();
    $dirindexConfig = array_merge($dirindexConfig, $userConfig);
}
$dirindexStorage = [];
$storedConfig = loadDirindexStoredConfig(__DIR__, $dirindexStorage);
if ($storedConfig) {
    $dirindexConfig = array_merge($dirindexConfig, $storedConfig);
}
$allowOutside = !empty($dirindexConfig['allow_open_symlinks_outside']);

// IP access check (whitelist / blacklist with CIDR support)
$clientIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
if (isset($dirindexConfig['ip_header']) && $dirindexConfig['ip_header'] !== '' && !empty($_SERVER[$dirindexConfig['ip_header']])) {
    $forwarded = $_SERVER[$dirindexConfig['ip_header']];
    $clientIp = trim(strpos($forwarded, ',') !== false ? strstr($forwarded, ',', true) : $forwarded);
}
$ipBlacklist = isset($dirindexConfig['ip_blacklist']) && is_array($dirindexConfig['ip_blacklist']) ? $dirindexConfig['ip_blacklist'] : [];
$ipWhitelist = isset($dirindexConfig['ip_whitelist']) && is_array($dirindexConfig['ip_whitelist']) ? $dirindexConfig['ip_whitelist'] : [];
if ($clientIp !== '' && (ipMatchesList($clientIp, $ipBlacklist) || ($ipWhitelist !== [] && !ipMatchesList($clientIp, $ipWhitelist)))) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Access denied.');
}

// Subdirectory path from query (e.g. index.php?path=foo/bar)
$relativePath = isset($_GET['path']) ? trim((string) $_GET['path'], '/') : '';
// Reject directory traversal and null bytes
if ($relativePath !== '' && (strpos($relativePath, '..') !== false || str_contains($relativePath, "\0"))) {
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

$blockedMessage = null;
if ($relativePath !== '') {
    $requestedPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $requestedReal = realpath($requestedPath);
    $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
    $isMdFullPage = ($ext === 'md' && !isset($_GET['content']));
    if (is_file($requestedPath) && $isMdFullPage && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        $md = @file_get_contents($requestedPath);
        if ($md !== false) {
            header('Content-Type: text/html; charset=UTF-8');
            echo renderMarkdownPage($md, $relativePath, $indexHref);
            exit;
        }
    }
    if (is_file($requestedPath) && isset($_GET['content']) && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        $raw = @file_get_contents($requestedPath);
        if ($raw !== false) {
            $lang = $previewExts[$ext] ?? 'plaintext';
            if (!isset($previewExts[$ext]) && (looksLikeBinary($requestedPath) || filesize($requestedPath) > 512 * 1024)) {
                $raw = false; // refuse to send likely binary or large unknown files
            }
            if ($raw !== false) {
                header('Content-Type: application/json; charset=UTF-8');
                $out = ['content' => $raw, 'lang' => $lang, 'name' => basename($relativePath)];
                if ($ext === 'md' || $ext === 'markdown') {
                    $out['html'] = markdownToHtml($raw);
                }
                echo json_encode($out, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }
    if (!$allowOutside && is_file($requestedPath) && ($isMdFullPage || isset($_GET['content'])) && $requestedReal !== false && !pathUnderBase($requestedReal, $realBase)) {
        $blockedMessage = 'That link points outside the index and cannot be opened.';
    }
    $realCurrent = realpath($requestedPath);
    $dirAllowed = $realCurrent !== false && is_dir($requestedPath) && (pathUnderBase($realCurrent, $realBase) || $allowOutside);
    if (!$dirAllowed) {
        if (!$allowOutside && !$blockedMessage && is_dir($requestedPath) && $realCurrent !== false && !pathUnderBase($realCurrent, $realBase)) {
            $blockedMessage = 'That link points outside the index and cannot be opened.';
        }
        $currentPath = $baseDir;
        $relativePath = '';
    } else {
        $currentPath = $realCurrent;
        $blockedMessage = null;
    }
} else {
    $currentPath = $baseDir;
    $blockedMessage = null;
}

$hasUploadCredentials = hasUploadCredentials($dirindexConfig);
$setupNeeded = !$hasUploadCredentials;
$uploadEnabled = isUploadEnabled($dirindexConfig);
$sessionNeeded = $setupNeeded || $hasUploadCredentials || $_SERVER['REQUEST_METHOD'] === 'POST';
if ($sessionNeeded) {
    startDirindexSession((string) $dirindexConfig['session_name']);
}
$authenticated = $sessionNeeded ? isAuthenticated() : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!validCsrfToken($csrf)) {
        redirectToCurrentListing($indexHref, $relativePath, 'csrf_failed');
    }

    if ($action === 'setup') {
        if (!$setupNeeded) {
            redirectToCurrentListing($indexHref, $relativePath, 'setup_done');
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $maxBytes = trim((string) ($_POST['upload_max_bytes'] ?? ''));
        if ($username === '' || $password === '' || $confirm === '') {
            redirectToCurrentListing($indexHref, $relativePath, 'setup_missing');
        }
        if (!hash_equals($password, $confirm)) {
            redirectToCurrentListing($indexHref, $relativePath, 'setup_mismatch');
        }
        if (strlen($password) < 8) {
            redirectToCurrentListing($indexHref, $relativePath, 'setup_short_password');
        }
        $maxBytesInt = ($maxBytes !== '' && ctype_digit($maxBytes)) ? (int) $maxBytes : 0;
        $saveError = null;
        $saved = saveDirindexStoredConfig(__DIR__, $configFile, [
            'upload_enabled' => '1',
            'auth_username' => $username,
            'auth_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'upload_max_bytes' => (string) $maxBytesInt,
        ], $saveError);
        if (!$saved) {
            redirectToCurrentListing($indexHref, $relativePath, 'setup_write_failed');
        }
        session_regenerate_id(true);
        $_SESSION['dirindex_authenticated'] = true;
        redirectToCurrentListing($indexHref, $relativePath, 'setup_saved');
    }

    if ($action === 'login') {
        if (!$hasUploadCredentials) {
            redirectToCurrentListing($indexHref, $relativePath, 'setup_required');
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $userOk = hash_equals((string) $dirindexConfig['auth_username'], $username);
        $passOk = password_verify($password, (string) $dirindexConfig['auth_password_hash']);
        if (!$userOk || !$passOk) {
            redirectToCurrentListing($indexHref, $relativePath, 'login_failed');
        }
        session_regenerate_id(true);
        $_SESSION['dirindex_authenticated'] = true;
        redirectToCurrentListing($indexHref, $relativePath, 'login_ok');
    }

    if ($action === 'logout') {
        unset($_SESSION['dirindex_authenticated']);
        redirectToCurrentListing($indexHref, $relativePath, 'logout_ok');
    }

    if ($action === 'upload') {
        if (!$uploadEnabled || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, 'auth_required');
        }
        if (!is_dir($currentPath) || !is_writable($currentPath)) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_not_writable');
        }
        if (empty($_FILES['upload_file']) || !isset($_FILES['upload_file']['error'])) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_missing');
        }
        $file = $_FILES['upload_file'];
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            redirectToCurrentListing($indexHref, $relativePath, uploadErrorMessage($file['error']));
        }
        $name = cleanUploadFilename($file['name'] ?? '');
        if ($name === null) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_bad_name');
        }
        $maxBytes = isset($dirindexConfig['upload_max_bytes']) ? (int) $dirindexConfig['upload_max_bytes'] : 0;
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($maxBytes > 0 && $size > $maxBytes) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_too_large');
        }
        $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_failed');
        }
        $destination = $currentPath . DIRECTORY_SEPARATOR . $name;
        $exists = file_exists($destination);
        if ($exists && (is_dir($destination) || is_link($destination))) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_target_blocked');
        }
        if ($exists && ($_POST['overwrite'] ?? '') !== '1') {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_exists');
        }
        if (!@move_uploaded_file($tmpName, $destination)) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_failed');
        }
        redirectToCurrentListing($indexHref, $relativePath, $exists ? 'upload_overwritten' : 'upload_ok');
    }

    redirectToCurrentListing($indexHref, $relativePath, 'bad_action');
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
        if ($entry === '.dirindex.sqlite' || str_starts_with($entry, '.dirindex.sqlite-')) continue;
        $full = $currentPath . DIRECTORY_SEPARATOR . $entry;
        clearstatcache(false, $full);
        $isLink = is_link($full);
        $linkTarget = $isLink ? @readlink($full) : null;
        if ($linkTarget === false) {
            $linkTarget = null;
        }
        $mtime = @filemtime($full);
        if ($mtime === false) {
            $stat = @stat($full);
            $mtime = ($stat !== false && isset($stat['mtime'])) ? (int) $stat['mtime'] : (($stat !== false && isset($stat[9])) ? (int) $stat[9] : null);
        } else {
            $mtime = (int) $mtime;
        }
        if (empty($dirindexConfig['show_symlinks']) && $isLink) {
            continue;
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

$messageMap = [
    'auth_required' => ['error', 'Please sign in before uploading.'],
    'bad_action' => ['error', 'Unknown action.'],
    'csrf_failed' => ['error', 'Security check failed. Please try again.'],
    'login_failed' => ['error', 'Invalid username or password.'],
    'login_ok' => ['success', 'Signed in.'],
    'logout_ok' => ['info', 'Signed out.'],
    'setup_done' => ['info', 'Upload setup is already complete.'],
    'setup_missing' => ['error', 'Enter a username and password to finish setup.'],
    'setup_mismatch' => ['error', 'The setup passwords did not match.'],
    'setup_required' => ['error', 'Set up upload authentication first.'],
    'setup_saved' => ['success', 'Upload authentication is set up and you are signed in.'],
    'setup_short_password' => ['error', 'Use a password with at least 8 characters.'],
    'setup_write_failed' => ['error', 'Could not save upload settings. Check file permissions.'],
    'upload_bad_name' => ['error', 'Upload filename is not allowed.'],
    'upload_exists' => ['error', 'A file with that name already exists. Confirm overwrite and try again.'],
    'upload_failed' => ['error', 'Upload failed.'],
    'upload_missing' => ['error', 'Choose a file to upload.'],
    'upload_not_writable' => ['error', 'This directory is not writable by PHP.'],
    'upload_ok' => ['success', 'Upload complete.'],
    'upload_overwritten' => ['success', 'Existing file overwritten.'],
    'upload_partial' => ['error', 'Upload was interrupted before it completed.'],
    'upload_target_blocked' => ['error', 'Cannot overwrite a directory or symlink.'],
    'upload_too_large' => ['error', 'Uploaded file is too large.'],
];
$statusMessage = null;
if (isset($_GET['msg'], $messageMap[$_GET['msg']])) {
    $statusMessage = $messageMap[$_GET['msg']];
}
$existingNames = [];
foreach ($items as $item) {
    $existingNames[] = $item['name'];
}

// Optional ?open=filename to open file in modal on load (shareable URL)
$openFileForModal = null;
if (isset($_GET['open']) && $_GET['open'] !== '') {
    $openParam = trim((string) $_GET['open'], '/');
    if ($openParam !== '' && strpos($openParam, '..') === false && !str_contains($openParam, "\0")) {
        $openFilePath = $relativePath !== '' ? $relativePath . '/' . $openParam : $openParam;
        $openAbsPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $openFilePath);
        $openReal = realpath($openAbsPath);
        if ($openReal !== false && is_file($openReal) && (pathUnderBase($openReal, $realBase) || $allowOutside)) {
            $openExt = strtolower(pathinfo($openFilePath, PATHINFO_EXTENSION));
            $isText = isset($previewExts[$openExt]) || !looksLikeBinary($openReal);
            if ($isText) {
                $openDir = dirname($openFilePath);
                $openName = basename($openFilePath);
                $openFileForModal = [
                    'content_url' => $indexHref . '?path=' . rawurlencode($openFilePath) . '&content=1',
                    'name'        => $openName,
                    'open_url'    => ($openExt === 'md' || $openExt === 'markdown')
                        ? $indexHref . '?path=' . rawurlencode($openFilePath)
                        : '/' . ($openDir !== '.' ? $openDir . '/' : '') . rawurlencode($openName),
                ];
            }
        } elseif (!$allowOutside && !$blockedMessage && $openReal !== false && is_file($openReal) && !pathUnderBase($openReal, $realBase)) {
            $blockedMessage = 'That link points outside the index and cannot be opened.';
        }
    }
}

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
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) use ($h) {
        $url = $m[2];
        // Block javascript:, data:, vbscript: and other scheme-based XSS
        $safe = !preg_match('/^(javascript|data|vbscript|file):/i', trim($url));
        $href = $safe ? $h($url) : '#';
        return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $s);
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
    <link rel="stylesheet" id="hljs-theme" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
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
        html.theme-light {
            --bg: #fafafa;
            --bg-card: #ffffff;
            --border: #e4e4e7;
            --text: #18181b;
            --text-muted: #71717a;
            --accent: #7c3aed;
            --accent-dim: #5b21b6;
            --dir-color: #0891b2;
            --hover: #f4f4f5;
        }
        body.font-large { font-size: 17px; }
        body.font-large .listing td, body.font-large .listing th { font-size: 0.95rem; }

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
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }
        .header-main { flex: 1; min-width: 0; }
        .btn-settings {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            padding: 0;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-card);
            color: var(--text-muted);
            cursor: pointer;
        }
        .btn-settings:hover { color: var(--text); background: var(--hover); border-color: var(--text-muted); }
        .btn-settings svg { width: 1.15rem; height: 1.15rem; }

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

        .settings-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1001; align-items: center; justify-content: center; padding: 2rem; box-sizing: border-box; }
        .settings-overlay.is-open { display: flex; }
        .settings-modal { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; width: 100%; max-width: 380px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .settings-modal .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
        .settings-modal .modal-title { font-size: 1rem; font-weight: 600; }
        .settings-modal .modal-body { padding: 1.25rem; }
        .settings-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.6rem 0; border-bottom: 1px solid var(--border); }
        .settings-row:last-child { border-bottom: none; }
        .settings-row label { font-size: 0.9rem; color: var(--text); cursor: pointer; }
        .settings-toggle { position: relative; width: 2.5rem; height: 1.35rem; flex-shrink: 0; border-radius: 999px; background: var(--border); cursor: pointer; transition: background 0.2s; }
        .settings-toggle::after { content: ''; position: absolute; top: 2px; left: 2px; width: 1.1rem; height: 1.1rem; border-radius: 50%; background: var(--bg-card); box-shadow: 0 1px 2px rgba(0,0,0,0.2); transition: transform 0.2s; }
        .settings-toggle.is-on { background: var(--accent); }
        .settings-toggle.is-on::after { transform: translateX(1.15rem); }
        input.settings-check { position: absolute; opacity: 0; width: 0; height: 0; }

        .blocked-msg {
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            background: rgba(185, 28, 28, 0.15);
            border: 1px solid rgba(185, 28, 28, 0.4);
            border-radius: 8px;
            color: var(--text);
            font-size: 0.9rem;
        }
        .message-info {
            background: rgba(124, 58, 237, 0.12);
            border-color: rgba(124, 58, 237, 0.35);
        }
        .message-success {
            background: rgba(22, 163, 74, 0.14);
            border-color: rgba(22, 163, 74, 0.38);
        }
        .auth-panel {
            margin-bottom: 1rem;
            padding: 1rem;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
        }
        .auth-panel h2 {
            margin: 0 0 0.75rem;
            font-size: 0.95rem;
            font-weight: 600;
        }
        .auth-panel p {
            margin: 0 0 0.75rem;
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        .auth-form {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 0.65rem;
        }
        .auth-field {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-width: 11rem;
            flex: 1 1 11rem;
        }
        .auth-field label {
            color: var(--text-muted);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .auth-field input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text);
            padding: 0.55rem 0.65rem;
            font: inherit;
        }
        .auth-field input[type="file"] {
            padding: 0.43rem 0.65rem;
        }
        .auth-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            align-items: center;
        }
        .btn-auth {
            border: 1px solid var(--accent-dim);
            border-radius: 8px;
            background: var(--accent-dim);
            color: white;
            padding: 0.55rem 0.8rem;
            font: inherit;
            cursor: pointer;
        }
        .btn-auth:hover {
            filter: brightness(1.08);
        }
        .btn-auth-secondary {
            background: transparent;
            color: var(--text-muted);
            border-color: var(--border);
        }
        .btn-auth-secondary:hover {
            color: var(--text);
            background: var(--hover);
        }
    </style>
</head>
<body<?php if ($openFileForModal): ?> data-open-content-url="<?= h($openFileForModal['content_url']) ?>" data-open-name="<?= h($openFileForModal['name']) ?>" data-open-url="<?= h($openFileForModal['open_url']) ?>"<?php endif; ?>>
    <div class="page">
        <header>
            <div class="header-main">
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
            </div>
            <button type="button" class="btn-settings" id="btn-settings" aria-label="Settings" title="Settings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </button>
        </header>

        <?php if ($blockedMessage): ?>
        <div class="blocked-msg" role="alert">
            <?= h($blockedMessage) ?>
        </div>
        <?php endif; ?>

        <?php if ($statusMessage): ?>
        <div class="blocked-msg message-<?= h($statusMessage[0]) ?>" role="status">
            <?= h($statusMessage[1]) ?>
        </div>
        <?php endif; ?>

        <?php if ($setupNeeded): ?>
        <section class="auth-panel" aria-labelledby="setup-title">
            <h2 id="setup-title">Set up uploads</h2>
            <p>Create the upload admin account. Settings will be stored in <?= h($dirindexStorage['type'] === 'sqlite' ? basename($dirindexStorage['path']) : 'config.php') ?>.</p>
            <form class="auth-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>">
                <input type="hidden" name="action" value="setup">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <div class="auth-field">
                    <label for="setup-username">Username</label>
                    <input type="text" id="setup-username" name="username" autocomplete="username" required>
                </div>
                <div class="auth-field">
                    <label for="setup-password">Password</label>
                    <input type="password" id="setup-password" name="password" autocomplete="new-password" minlength="8" required>
                </div>
                <div class="auth-field">
                    <label for="setup-password-confirm">Confirm</label>
                    <input type="password" id="setup-password-confirm" name="password_confirm" autocomplete="new-password" minlength="8" required>
                </div>
                <div class="auth-field">
                    <label for="setup-upload-max">Max bytes</label>
                    <input type="number" id="setup-upload-max" name="upload_max_bytes" min="0" inputmode="numeric" placeholder="0 = PHP default">
                </div>
                <div class="auth-actions">
                    <button type="submit" class="btn-auth">Save setup</button>
                </div>
            </form>
        </section>
        <?php elseif ($uploadEnabled && !$authenticated): ?>
        <section class="auth-panel" aria-labelledby="login-title">
            <h2 id="login-title">Upload login</h2>
            <form class="auth-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <div class="auth-field">
                    <label for="login-username">Username</label>
                    <input type="text" id="login-username" name="username" autocomplete="username" required>
                </div>
                <div class="auth-field">
                    <label for="login-password">Password</label>
                    <input type="password" id="login-password" name="password" autocomplete="current-password" required>
                </div>
                <div class="auth-actions">
                    <button type="submit" class="btn-auth">Sign in</button>
                </div>
            </form>
        </section>
        <?php elseif ($uploadEnabled && $authenticated): ?>
        <section class="auth-panel" aria-labelledby="upload-title">
            <h2 id="upload-title">Upload to /<?= h($relativePath ?: '') ?></h2>
            <form class="auth-form" id="upload-form" method="post" enctype="multipart/form-data" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" data-existing-names="<?= h(json_encode($existingNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="overwrite" id="upload-overwrite" value="">
                <div class="auth-field">
                    <label for="upload-file">File</label>
                    <input type="file" id="upload-file" name="upload_file" required>
                </div>
                <div class="auth-actions">
                    <button type="submit" class="btn-auth">Upload</button>
                </div>
            </form>
            <form class="auth-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" style="margin-top: 0.65rem;">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <button type="submit" class="btn-auth btn-auth-secondary">Sign out</button>
            </form>
        </section>
        <?php endif; ?>

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
                            if ($ts !== null && $ts >= 0 && $ts <= 2147483647) {
                                $formatted = @date('Y-m-d H:i', (int) $ts);
                                echo $formatted !== false ? h($formatted) : '&#8212;';
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

    <div id="settings-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <div class="modal-header">
                <span class="modal-title" id="settings-title">Settings</span>
                <button type="button" class="modal-close" id="settings-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="settings-row">
                    <label for="setting-theme">Light mode</label>
                    <input type="checkbox" id="setting-theme" class="settings-check" aria-describedby="setting-theme-desc">
                    <span class="settings-toggle" id="setting-theme-toggle" role="switch" aria-checked="false" tabindex="0" title="Toggle light mode"></span>
                </div>
                <div class="settings-row">
                    <label for="setting-font">Large text</label>
                    <input type="checkbox" id="setting-font" class="settings-check">
                    <span class="settings-toggle" id="setting-font-toggle" role="switch" aria-checked="false" tabindex="0" title="Toggle large text"></span>
                </div>
                <div class="settings-row">
                    <label for="setting-breadcrumb">Slash in breadcrumbs</label>
                    <input type="checkbox" id="setting-breadcrumb" class="settings-check">
                    <span class="settings-toggle" id="setting-breadcrumb-toggle" role="switch" aria-checked="false" tabindex="0" title="Use / instead of › in breadcrumbs"></span>
                </div>
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
        var uploadForm = document.getElementById('upload-form');
        if (!uploadForm) return;
        var fileInput = document.getElementById('upload-file');
        var overwriteInput = document.getElementById('upload-overwrite');
        var existingNames = [];
        try {
            existingNames = JSON.parse(uploadForm.getAttribute('data-existing-names') || '[]');
        } catch (e) {
            existingNames = [];
        }
        uploadForm.addEventListener('submit', function(e) {
            if (!fileInput || !fileInput.files || !fileInput.files[0]) return;
            var name = fileInput.files[0].name;
            if (existingNames.indexOf(name) === -1) return;
            if (window.confirm('A file named "' + name + '" already exists. Overwrite it?')) {
                overwriteInput.value = '1';
                return;
            }
            overwriteInput.value = '';
            e.preventDefault();
        });
    })();

    (function() {
        var overlay = document.getElementById('file-modal');
        var titleEl = document.getElementById('modal-title');
        var openLinkEl = document.getElementById('modal-open-link');
        var codeEl = document.getElementById('modal-code');
        var modalPre = document.getElementById('modal-pre');
        var modalMd = document.getElementById('modal-md');
        var closeBtn = document.getElementById('modal-close');

        function buildListingUrlWithOpen(contentUrl, fileName) {
            var pathMatch = contentUrl && contentUrl.match(/[?&]path=([^&]+)/);
            var fullPath = pathMatch ? decodeURIComponent(pathMatch[1].replace(/\+/g, ' ')) : '';
            var lastSlash = fullPath.lastIndexOf('/');
            var dirPath = lastSlash >= 0 ? fullPath.slice(0, lastSlash) : '';
            var openParam = lastSlash >= 0 ? fullPath.slice(lastSlash + 1) : fullPath;
            if (openParam === '' && fileName) openParam = fileName;
            var base = contentUrl ? contentUrl.split('?')[0] : (window.location.pathname || '/index.php');
            var q = dirPath ? '?path=' + encodeURIComponent(dirPath) + '&open=' + encodeURIComponent(openParam) : '?open=' + encodeURIComponent(openParam);
            return base + q;
        }
        function removeOpenFromUrl() {
            var u = new URL(window.location.href);
            if (u.searchParams.has('open')) {
                u.searchParams.delete('open');
                history.replaceState(null, '', u.pathname + u.search + (u.hash || ''));
            }
        }

        function closeModal() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            modalMd.innerHTML = '';
            modalMd.classList.remove('is-visible');
            modalMd.setAttribute('aria-hidden', 'true');
            modalPre.style.display = '';
            openLinkEl.style.display = 'none';
            openLinkEl.removeAttribute('href');
            removeOpenFromUrl();
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

        function openModalFromContentUrl(contentUrl, name, openUrl, pushStateUrl) {
            fetch(contentUrl).then(function(r) { return r.json(); }).then(function(data) {
                openModal(data.name || name, data.content || '', data.lang || 'plaintext', data.html || null, openUrl);
                if (pushStateUrl !== undefined) history.pushState({ modal: true }, '', pushStateUrl);
            }).catch(function() {
                if (pushStateUrl === undefined) window.location.href = contentUrl.split('&content')[0];
            });
        }

        document.addEventListener('click', function(e) {
            var a = e.target.closest('a.file-preview');
            if (!a) return;
            e.preventDefault();
            var contentUrl = a.getAttribute('data-content-url');
            var name = a.getAttribute('data-name') || '';
            if (!contentUrl) return;
            var openUrl = a.getAttribute('data-open-url') || '';
            var listingUrl = buildListingUrlWithOpen(contentUrl, name);
            openModalFromContentUrl(contentUrl, name, openUrl, listingUrl);
        });

        closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
        });
        window.addEventListener('popstate', function() {
            if (overlay.classList.contains('is-open')) closeModal();
        });

        var body = document.body;
        var initialContentUrl = body.getAttribute('data-open-content-url');
        if (initialContentUrl) {
            var initialName = body.getAttribute('data-open-name') || '';
            var initialOpenUrl = body.getAttribute('data-open-url') || '';
            openModalFromContentUrl(initialContentUrl, initialName, initialOpenUrl);
        }
    })();

    (function() {
        var STORAGE = { theme: 'dirindex_theme', font: 'dirindex_font', breadcrumb: 'dirindex_breadcrumb' };
        var settingsOverlay = document.getElementById('settings-modal');
        var btnSettings = document.getElementById('btn-settings');
        var settingsClose = document.getElementById('settings-close');
        var hljsTheme = document.getElementById('hljs-theme');
        var pairs = [
            { check: document.getElementById('setting-theme'), toggle: document.getElementById('setting-theme-toggle') },
            { check: document.getElementById('setting-font'), toggle: document.getElementById('setting-font-toggle') },
            { check: document.getElementById('setting-breadcrumb'), toggle: document.getElementById('setting-breadcrumb-toggle') }
        ];

        function getSetting(key, def) {
            try { return localStorage.getItem(key) || def; } catch (e) { return def; }
        }
        function setSetting(key, val) {
            try { localStorage.setItem(key, val); } catch (e) {}
        }

        function applyTheme(light) {
            if (light) {
                document.documentElement.classList.add('theme-light');
                if (hljsTheme) hljsTheme.href = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-light.min.css';
            } else {
                document.documentElement.classList.remove('theme-light');
                if (hljsTheme) hljsTheme.href = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css';
            }
        }
        function applyFont(large) {
            document.body.classList.toggle('font-large', large);
        }
        function applyBreadcrumb(slash) {
            var sep = slash ? '/' : '\u203A';
            document.querySelectorAll('.breadcrumb-sep').forEach(function(el) { el.textContent = sep; });
        }

        function loadAndApply() {
            var theme = getSetting(STORAGE.theme, 'dark');
            var font = getSetting(STORAGE.font, 'normal');
            var breadcrumb = getSetting(STORAGE.breadcrumb, 'chevron');
            var light = (theme === 'light');
            var large = (font === 'large');
            var useSlash = (breadcrumb === 'slash');
            applyTheme(light);
            applyFont(large);
            applyBreadcrumb(useSlash);
            if (pairs[0].check) pairs[0].check.checked = light;
            if (pairs[0].toggle) { pairs[0].toggle.classList.toggle('is-on', light); pairs[0].toggle.setAttribute('aria-checked', light); }
            if (pairs[1].check) pairs[1].check.checked = large;
            if (pairs[1].toggle) { pairs[1].toggle.classList.toggle('is-on', large); pairs[1].toggle.setAttribute('aria-checked', large); }
            if (pairs[2].check) pairs[2].check.checked = useSlash;
            if (pairs[2].toggle) { pairs[2].toggle.classList.toggle('is-on', useSlash); pairs[2].toggle.setAttribute('aria-checked', useSlash); }
        }

        function openSettings() {
            settingsOverlay.classList.add('is-open');
            settingsOverlay.setAttribute('aria-hidden', 'false');
        }
        function closeSettings() {
            settingsOverlay.classList.remove('is-open');
            settingsOverlay.setAttribute('aria-hidden', 'true');
        }

        pairs.forEach(function(p, i) {
            if (!p.toggle || !p.check) return;
            function update() {
                var on = p.check.checked;
                p.toggle.classList.toggle('is-on', on);
                p.toggle.setAttribute('aria-checked', on);
                if (i === 0) { setSetting(STORAGE.theme, on ? 'light' : 'dark'); applyTheme(on); }
                if (i === 1) { setSetting(STORAGE.font, on ? 'large' : 'normal'); applyFont(on); }
                if (i === 2) { setSetting(STORAGE.breadcrumb, on ? 'slash' : 'chevron'); applyBreadcrumb(on); }
            }
            function toggle() {
                p.check.checked = !p.check.checked;
                update();
            }
            p.toggle.addEventListener('click', toggle);
            p.toggle.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
            });
            p.check.addEventListener('change', update);
        });

        btnSettings.addEventListener('click', openSettings);
        settingsClose.addEventListener('click', closeSettings);
        settingsOverlay.addEventListener('click', function(e) {
            if (e.target === settingsOverlay) closeSettings();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && settingsOverlay.classList.contains('is-open')) {
                closeSettings();
                e.stopPropagation();
            }
        });

        loadAndApply();
    })();
    </script>
</body>
</html>
