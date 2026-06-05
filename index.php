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
 * Parse newline- or comma-separated IP/CIDR list from settings input.
 */
function parseIpAccessListInput($text) {
    $text = str_replace([',', ';'], "\n", (string) $text);
    $entries = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $entry = trim($line);
        if ($entry !== '' && !str_starts_with($entry, '#')) {
            $entries[] = $entry;
        }
    }
    return $entries;
}

function formatIpAccessListForInput(array $list) {
    return implode("\n", array_map('strval', $list));
}

function isValidIpAccessEntry($entry) {
    $entry = trim((string) $entry);
    if ($entry === '') {
        return false;
    }
    if (strpos($entry, '/') !== false) {
        $parts = explode('/', $entry, 2);
        $network = trim($parts[0]);
        if ($network === '' || !isset($parts[1]) || $parts[1] === '' || !ctype_digit((string) $parts[1])) {
            return false;
        }
        $prefix = (int) $parts[1];
        $netBin = @inet_pton($network);
        if ($netBin === false) {
            return false;
        }
        $maxPrefix = strlen($netBin) * 8;
        return $prefix >= 0 && $prefix <= $maxPrefix;
    }
    return @inet_pton($entry) !== false;
}

function validateIpAccessList(array $entries, &$invalidEntry = null) {
    foreach ($entries as $entry) {
        if (!isValidIpAccessEntry($entry)) {
            $invalidEntry = $entry;
            return false;
        }
    }
    return true;
}

function normalizeIpHeaderInput($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (!str_starts_with($value, 'HTTP_')) {
        $value = 'HTTP_' . strtoupper(str_replace('-', '_', $value));
    }
    return preg_match('/^HTTP_[A-Z0-9_]+$/', $value) ? $value : null;
}

function parseForwardedClientIp($headerValue) {
    foreach (explode(',', (string) $headerValue) as $part) {
        $ip = trim($part);
        if ($ip !== '' && @inet_pton($ip) !== false) {
            return $ip;
        }
    }
    return '';
}

function dirindexLoopbackRanges() {
    return ['127.0.0.0/8', '::1/128'];
}

function dirindexPrivateNetworkRanges() {
    return [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        'fc00::/7',
        'fe80::/10',
    ];
}

function dirindexPrivateNetworkWhitelistRanges() {
    return array_merge(dirindexLoopbackRanges(), dirindexPrivateNetworkRanges());
}

function dirindexBuildPrivateNetworkWhitelist($clientIp = '') {
    $whitelist = dirindexPrivateNetworkWhitelistRanges();
    $clientIp = trim((string) $clientIp);
    if ($clientIp !== '' && !ipMatchesList($clientIp, $whitelist)) {
        $whitelist[] = $clientIp;
    }
    return $whitelist;
}

function isLoopbackIp($ip) {
    return $ip !== '' && ipMatchesList($ip, dirindexLoopbackRanges());
}

function isPrivateOrLocalIp($ip) {
    return $ip !== '' && ipMatchesList($ip, dirindexPrivateNetworkWhitelistRanges());
}

function resolveClientIp(array $config) {
    $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    $ipHeader = isset($config['ip_header']) ? trim((string) $config['ip_header']) : '';

    if ($ipHeader !== '' && !empty($_SERVER[$ipHeader])) {
        $forwardedIp = parseForwardedClientIp($_SERVER[$ipHeader]);
        if ($forwardedIp !== '') {
            return [
                'ip' => $forwardedIp,
                'source' => $ipHeader,
                'proxy' => ($remoteAddr !== '' && $forwardedIp !== $remoteAddr) ? $remoteAddr : '',
            ];
        }
    }

    if ($remoteAddr !== '' && isPrivateOrLocalIp($remoteAddr)) {
        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $autoHeader) {
            if (empty($_SERVER[$autoHeader])) {
                continue;
            }
            $forwardedIp = parseForwardedClientIp($_SERVER[$autoHeader]);
            if ($forwardedIp !== '' && $forwardedIp !== $remoteAddr) {
                return [
                    'ip' => $forwardedIp,
                    'source' => $autoHeader,
                    'proxy' => $remoteAddr,
                ];
            }
        }
    }

    return [
        'ip' => $remoteAddr,
        'source' => 'REMOTE_ADDR',
        'proxy' => '',
    ];
}

function clientIpSourceLabel($source) {
    switch ($source) {
        case 'HTTP_X_FORWARDED_FOR':
            return 'X-Forwarded-For';
        case 'HTTP_X_REAL_IP':
            return 'X-Real-IP';
        case 'HTTP_CF_CONNECTING_IP':
            return 'CF-Connecting-IP';
        case 'REMOTE_ADDR':
            return 'REMOTE_ADDR';
        default:
            return $source;
    }
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

function pathLogicalUnderBase($absolutePath, $realBase) {
    $sep = DIRECTORY_SEPARATOR;
    $baseNorm = rtrim($realBase, $sep);
    $pathNorm = rtrim($absolutePath, $sep);
    return $pathNorm === $baseNorm || str_starts_with($pathNorm . $sep, $baseNorm . $sep);
}

/**
 * Validate a listing path can be shared and determine its type.
 */
function resolveShareableEntry($absolutePath, $realBase, $allowOutside) {
    if (!file_exists($absolutePath) && !is_link($absolutePath)) {
        return ['ok' => false, 'type' => null, 'error' => 'share_failed'];
    }
    if (!pathLogicalUnderBase($absolutePath, $realBase)) {
        return ['ok' => false, 'type' => null, 'error' => 'share_failed'];
    }
    if (is_link($absolutePath)) {
        $resolved = realpath($absolutePath);
        if ($resolved === false) {
            return ['ok' => false, 'type' => null, 'error' => 'share_broken_link'];
        }
        if (!$allowOutside && !pathUnderBase($resolved, $realBase)) {
            return ['ok' => false, 'type' => null, 'error' => 'share_link_outside'];
        }
    } elseif (!$allowOutside) {
        $resolved = realpath($absolutePath);
        if ($resolved !== false && !pathUnderBase($resolved, $realBase)) {
            return ['ok' => false, 'type' => null, 'error' => 'share_failed'];
        }
    }
    if (is_dir($absolutePath)) {
        return ['ok' => true, 'type' => 'dir', 'error' => null];
    }
    if (is_file($absolutePath)) {
        return ['ok' => true, 'type' => 'file', 'error' => null];
    }
    return ['ok' => false, 'type' => null, 'error' => 'share_failed'];
}

function dirindexSqliteAvailable() {
    return extension_loaded('pdo_sqlite') && class_exists('PDO');
}

function dirindexSqlitePath($scriptDir) {
    return $scriptDir . DIRECTORY_SEPARATOR . '.dirindex.sqlite';
}

function dirindexJsonPath($scriptDir) {
    return $scriptDir . DIRECTORY_SEPARATOR . '.dirindex.json';
}

function dirindexStoragePath($scriptDir) {
    return dirindexSqliteAvailable() ? dirindexSqlitePath($scriptDir) : dirindexJsonPath($scriptDir);
}

function dirindexStorageWritable($scriptDir, &$detail = null) {
    $storagePath = dirindexStoragePath($scriptDir);
    if (!is_dir($scriptDir)) {
        $detail = 'Storage directory does not exist.';
        return false;
    }
    if (!is_writable($scriptDir)) {
        $detail = 'PHP cannot write to ' . $storagePath . ' (directory not writable).';
        return false;
    }
    if (is_file($storagePath) && !is_writable($storagePath)) {
        $detail = 'PHP cannot write to ' . $storagePath . ' (file not writable).';
        return false;
    }
    return true;
}

function dirindexFlashSet($message) {
    $_SESSION['dirindex_flash_message'] = (string) $message;
}

function dirindexFlashTake() {
    if (!isset($_SESSION['dirindex_flash_message'])) {
        return null;
    }
    $message = (string) $_SESSION['dirindex_flash_message'];
    unset($_SESSION['dirindex_flash_message']);
    return $message;
}

function dirindexStoredConfigKeys() {
    return [
        'show_symlinks',
        'allow_open_symlinks_outside',
        'ip_whitelist',
        'ip_blacklist',
        'ip_header',
        'upload_enabled',
        'auth_username',
        'auth_password_hash',
        'upload_max_bytes',
    ];
}

function dirindexArraySettingKeys() {
    return ['ip_whitelist', 'ip_blacklist'];
}

function dirindexEncodeStoredValue($key, $value) {
    if (in_array($key, dirindexArraySettingKeys(), true)) {
        return json_encode(array_values((array) $value), JSON_UNESCAPED_SLASHES);
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    return (string) $value;
}

function dirindexDecodeStoredValue($key, $value) {
    if (in_array($key, dirindexArraySettingKeys(), true)) {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $value;
}

function dirindexNormalizeStoredConfig(array $settings) {
    $normalized = [];
    foreach ($settings as $key => $value) {
        $normalized[$key] = dirindexDecodeStoredValue($key, $value);
    }
    return $normalized;
}

function dirindexPrepareSettingsForJson(array $settings) {
    $prepared = [];
    foreach ($settings as $key => $value) {
        if (in_array($key, dirindexArraySettingKeys(), true)) {
            $prepared[$key] = array_values((array) $value);
            continue;
        }
        if (in_array($key, ['show_symlinks', 'allow_open_symlinks_outside', 'upload_enabled'], true)) {
            $prepared[$key] = ($value === '1' || $value === 1 || $value === true);
            continue;
        }
        if ($key === 'upload_max_bytes') {
            $prepared[$key] = (int) $value;
            continue;
        }
        $prepared[$key] = $value;
    }
    return $prepared;
}

function dirindexIsHiddenListingEntry($entry) {
    return $entry === '.dirindex.sqlite'
        || str_starts_with($entry, '.dirindex.sqlite-')
        || $entry === '.dirindex.json'
        || $entry === 'config.php';
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
        'type' => dirindexSqliteAvailable() ? 'sqlite' : 'json',
        'path' => dirindexStoragePath($scriptDir),
        'error' => null,
    ];

    if (dirindexSqliteAvailable()) {
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
        return dirindexNormalizeStoredConfig($settings);
    }

    $jsonPath = dirindexJsonPath($scriptDir);
    if (!is_file($jsonPath)) {
        return [];
    }
    $decoded = json_decode((string) @file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
        $storageInfo['error'] = 'Invalid ' . basename($jsonPath) . '.';
        return [];
    }
    return dirindexNormalizeStoredConfig($decoded);
}

function dirindexImportLegacyConfigIfNeeded($scriptDir, array $storedConfig) {
    $legacyFile = $scriptDir . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($legacyFile) || !is_readable($legacyFile)) {
        return $storedConfig;
    }
    $legacy = (array) include $legacyFile;
    $toImport = [];
    foreach (dirindexStoredConfigKeys() as $key) {
        if (array_key_exists($key, $legacy) && !array_key_exists($key, $storedConfig)) {
            $toImport[$key] = $legacy[$key];
        }
    }
    if (!$toImport) {
        return $storedConfig;
    }
    $importError = null;
    if (!saveDirindexStoredConfig($scriptDir, $toImport, $importError)) {
        return $storedConfig;
    }
    return array_merge($storedConfig, dirindexNormalizeStoredConfig($toImport));
}

function saveDirindexStoredConfig($scriptDir, array $settings, &$error = null) {
    if (dirindexSqliteAvailable()) {
        $pdo = dirindexOpenSqliteStore(dirindexSqlitePath($scriptDir), $error);
        if (!$pdo) {
            return false;
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
            foreach ($settings as $key => $value) {
                $stmt->execute([
                    ':key' => (string) $key,
                    ':value' => dirindexEncodeStoredValue($key, $value),
                ]);
            }
            return true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    $jsonPath = dirindexJsonPath($scriptDir);
    $existing = [];
    if (is_file($jsonPath) && is_readable($jsonPath)) {
        $decoded = json_decode((string) @file_get_contents($jsonPath), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    $data = array_merge($existing, dirindexPrepareSettingsForJson($settings));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $error = 'Unable to encode settings.';
        return false;
    }
    if (@file_put_contents($jsonPath, $json . "\n", LOCK_EX) === false) {
        $error = 'Unable to write ' . basename($jsonPath) . '.';
        return false;
    }
    return true;
}

function dirindexEnsureSharesTable($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS shares (
        token TEXT PRIMARY KEY,
        path TEXT NOT NULL,
        type TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        expires_at INTEGER
    )');
}

function dirindexGetSharesPdo($scriptDir, &$error = null) {
    if (!dirindexSqliteAvailable()) {
        $error = 'SQLite is required for share links.';
        return null;
    }
    $pdo = dirindexOpenSqliteStore(dirindexSqlitePath($scriptDir), $error);
    if ($pdo) {
        dirindexEnsureSharesTable($pdo);
    }
    return $pdo;
}

function loadShareByToken($pdo, $token) {
    $token = trim((string) $token);
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT token, path, type, created_at, expires_at FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !hash_equals($row['token'], $token)) {
        return null;
    }
    if ($row['expires_at'] !== null && (int) $row['expires_at'] < time()) {
        return null;
    }
    return [
        'token'      => $row['token'],
        'path'       => $row['path'],
        'type'       => $row['type'],
        'created_at' => (int) $row['created_at'],
        'expires_at' => $row['expires_at'] !== null ? (int) $row['expires_at'] : null,
    ];
}

function createShare($pdo, $relativePath, $type, $expiresAt) {
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO shares (token, path, type, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$token, $relativePath, $type, time(), $expiresAt]);
    return $token;
}

function revokeShare($pdo, $token) {
    $stmt = $pdo->prepare('DELETE FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    return $stmt->rowCount() > 0;
}

function listActiveShares($pdo) {
    $stmt = $pdo->query('SELECT token, path, type, created_at, expires_at FROM shares ORDER BY created_at DESC');
    $shares = [];
    $now = time();
    foreach ($stmt as $row) {
        if ($row['expires_at'] !== null && (int) $row['expires_at'] < $now) {
            continue;
        }
        $shares[] = [
            'token'      => $row['token'],
            'path'       => $row['path'],
            'type'       => $row['type'],
            'created_at' => (int) $row['created_at'],
            'expires_at' => $row['expires_at'] !== null ? (int) $row['expires_at'] : null,
        ];
    }
    return $shares;
}

function pathWithinShareScope($requestedPath, array $share) {
    $sharePath = trim($share['path'], '/');
    $requestedPath = trim((string) $requestedPath, '/');
    if ($share['type'] === 'file') {
        return $requestedPath === '' || $requestedPath === $sharePath;
    }
    if ($requestedPath === '') {
        return true;
    }
    return $requestedPath === $sharePath || str_starts_with($requestedPath, $sharePath . '/');
}

function shareExpiryFromChoice($choice) {
    switch ((string) $choice) {
        case '1d':
            return time() + 86400;
        case '7d':
            return time() + 7 * 86400;
        case '30d':
            return time() + 30 * 86400;
        default:
            return null;
    }
}

function serveSharedFileDownload($absolutePath, $displayName) {
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=UTF-8');
        exit('File not found.');
    }
    $mime = @mime_content_type($absolutePath);
    if (!$mime || $mime === 'application/octet-stream') {
        $mime = 'application/octet-stream';
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $displayName) . '"');
    header('Content-Length: ' . (string) filesize($absolutePath));
    readfile($absolutePath);
    exit;
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

function isShareAjaxRequest() {
    return isset($_POST['ajax']) && (string) $_POST['ajax'] === '1';
}

function shareAjaxResponse($ok, $message) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => (bool) $ok, 'message' => (string) $message], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    exit;
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

function currentListingUrl($indexHref, $relativePath, array $params = [], $shareToken = null) {
    global $shareTokenActive;
    $token = ($shareToken !== null && $shareToken !== '') ? $shareToken : $shareTokenActive;
    if ($token !== null && $token !== '') {
        $params = array_merge(['share' => $token], $params);
    }
    if ($relativePath !== '') {
        $params = array_merge(['path' => $relativePath], $params);
    }
    return $indexHref . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

function requestOrigin() {
    $host = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string) $_SERVER['HTTP_HOST'];
    }
    if ($host === '') {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    return ($https ? 'https' : 'http') . '://' . $host;
}

function shareUrl($indexHref, $token, array $params = [], $absolute = false) {
    $params = array_merge(['share' => $token], $params);
    $url = $indexHref . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    if (!$absolute) {
        return $url;
    }
    $origin = requestOrigin();
    return $origin !== '' ? $origin . $url : $url;
}

function directEntryUrl($relativePath, $trailingSlash = false) {
    $relativePath = trim((string) $relativePath, '/');
    if ($relativePath === '') {
        return '/';
    }
    $segments = array_values(array_filter(explode('/', $relativePath), function ($segment) {
        return $segment !== '';
    }));
    $url = '/' . implode('/', array_map('rawurlencode', $segments));
    return $trailingSlash ? rtrim($url, '/') . '/' : $url;
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

// Defaults; UI-managed settings are stored in .dirindex.sqlite (or .dirindex.json without SQLite).
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
$dirindexStorage = [];
$storedConfig = loadDirindexStoredConfig(__DIR__, $dirindexStorage);
$storedConfig = dirindexImportLegacyConfigIfNeeded(__DIR__, $storedConfig);
if ($storedConfig) {
    $dirindexConfig = array_merge($dirindexConfig, $storedConfig);
}
$allowOutside = !empty($dirindexConfig['allow_open_symlinks_outside']);
$hasUploadCredentials = hasUploadCredentials($dirindexConfig);
$setupNeeded = !$hasUploadCredentials;
$uploadEnabled = isUploadEnabled($dirindexConfig);
$sessionNeeded = $setupNeeded || $hasUploadCredentials || $_SERVER['REQUEST_METHOD'] === 'POST';
if ($sessionNeeded) {
    startDirindexSession((string) $dirindexConfig['session_name']);
}
$authenticated = $sessionNeeded ? isAuthenticated() : false;

// Share link resolution (valid token bypasses IP access check)
$shareContext = null;
$inShareMode = false;
$shareTokenActive = null;
$sharePdo = null;
$sharesAvailable = dirindexSqliteAvailable();
$shareRequestToken = isset($_GET['share']) ? trim((string) $_GET['share']) : '';
if ($shareRequestToken !== '') {
    if (!$sharesAvailable) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Share links are not available (SQLite required).');
    }
    $shareError = null;
    $sharePdo = dirindexGetSharesPdo(__DIR__, $shareError);
    if (!$sharePdo) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Share links are not available.');
    }
    $shareContext = loadShareByToken($sharePdo, $shareRequestToken);
    if (!$shareContext) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Share link not found or expired.');
    }
    $inShareMode = true;
    $shareTokenActive = $shareContext['token'];
}

// IP access check (whitelist / blacklist with CIDR support)
$clientIpContext = resolveClientIp($dirindexConfig);
$clientIp = $clientIpContext['ip'];
$clientIpSource = $clientIpContext['source'];
$clientIpProxy = $clientIpContext['proxy'];
$ipBlacklist = isset($dirindexConfig['ip_blacklist']) && is_array($dirindexConfig['ip_blacklist']) ? $dirindexConfig['ip_blacklist'] : [];
$ipWhitelist = isset($dirindexConfig['ip_whitelist']) && is_array($dirindexConfig['ip_whitelist']) ? $dirindexConfig['ip_whitelist'] : [];
if (!$inShareMode && $clientIp !== '' && !isLoopbackIp($clientIp) && (ipMatchesList($clientIp, $ipBlacklist) || ($ipWhitelist !== [] && !ipMatchesList($clientIp, $ipWhitelist)))) {
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
if ($inShareMode && $shareContext) {
    if ($shareContext['type'] === 'dir') {
        if ($relativePath === '') {
            $relativePath = trim($shareContext['path'], '/');
        } elseif (!pathWithinShareScope($relativePath, $shareContext)) {
            header('HTTP/1.1 404 Not Found');
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Path is outside the shared scope.');
        }
    } elseif ($relativePath !== '' && $relativePath !== trim($shareContext['path'], '/')) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Path is outside the shared scope.');
    }
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

function fileExtensionCategory($ext) {
    $ext = strtolower((string) $ext);
    static $categories = [
        'archive'      => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz', 'tbz2', 'zst', 'lz', 'lzma', 'cab', 'iso'],
        'image'        => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tiff', 'tif', 'avif', 'heic'],
        'video'        => ['mp4', 'mkv', 'avi', 'mov', 'webm', 'wmv', 'm4v', 'mpeg', 'mpg'],
        'audio'        => ['mp3', 'wav', 'flac', 'ogg', 'm4a', 'aac', 'wma', 'opus'],
        'pdf'          => ['pdf'],
        'spreadsheet'  => ['xls', 'xlsx', 'ods', 'csv'],
        'document'     => ['doc', 'docx', 'odt', 'rtf', 'txt', 'md', 'markdown'],
        'presentation' => ['ppt', 'pptx', 'odp'],
        'code'         => ['php', 'js', 'ts', 'py', 'json', 'xml', 'html', 'htm', 'css', 'sql', 'sh', 'bash', 'yaml', 'yml', 'ini', 'env', 'log', 'conf', 'cfg'],
        'executable'   => ['exe', 'msi', 'deb', 'rpm', 'dmg', 'app', 'dll', 'so', 'bin'],
    ];
    foreach ($categories as $category => $exts) {
        if (in_array($ext, $exts, true)) {
            return $category;
        }
    }
    return 'file';
}

function fileTypeIconHtml($ext, $isDir = false) {
    if ($isDir) {
        return '<span class="ft-icon ft-icon--dir" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
            . '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'
            . '</svg></span>';
    }
    $ext = strtolower((string) $ext);
    $label = $ext !== '' ? strtoupper(strlen($ext) <= 4 ? $ext : substr($ext, 0, 4)) : 'FILE';
    $category = fileExtensionCategory($ext);
    return '<span class="ft-icon ft-icon--' . h($category) . '" aria-hidden="true">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">'
        . '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
        . '<polyline points="14 2 14 8 20 8"/>'
        . '</svg>'
        . '<span class="ft-icon__label">' . h($label) . '</span>'
        . '</span>';
}

function renderShareFileLandingPage($relativePath, $absolutePath, array $share, $indexHref, $isText, $size, $mtime, array $previewExts, $ext) {
    $name = basename($relativePath);
    $downloadParams = ['download' => '1'];
    if ($share['type'] === 'dir') {
        $downloadParams['path'] = $relativePath;
    }
    $downloadUrl = shareUrl($indexHref, $share['token'], $downloadParams);
    $mtimeFormatted = ($mtime !== null && $mtime >= 0 && $mtime <= 2147483647) ? @date('Y-m-d H:i', (int) $mtime) : '—';
    $previewHtml = '';
    if ($isText) {
        $raw = @file_get_contents($absolutePath);
        if ($raw !== false) {
            $lang = $previewExts[$ext] ?? 'plaintext';
            if (!isset($previewExts[$ext]) && (looksLikeBinary($absolutePath) || filesize($absolutePath) > 512 * 1024)) {
                $raw = false;
            }
            if ($raw !== false) {
                if ($ext === 'md' || $ext === 'markdown') {
                    $previewHtml = '<div class="preview-md">' . markdownToHtml($raw) . '</div>';
                } else {
                    $escaped = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
                    $previewHtml = '<pre class="preview-code"><code>' . $escaped . '</code></pre>';
                }
            }
        }
    }
    $css = '
    :root { --bg: #0d0d0f; --bg-card: #141417; --border: #25252a; --text: #e4e4e7; --text-muted: #71717a; --accent: #a78bfa; --accent-dim: #7c3aed; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; font-size: 15px; line-height: 1.6; padding: 2rem; }
    .page { max-width: 720px; margin: 0 auto; }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 2rem; }
    .file-header { display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
    .file-header-text { min-width: 0; }
    h1 { margin: 0 0 0.5rem; font-size: 1.5rem; word-break: break-word; }
    .meta { color: var(--text-muted); font-size: 0.9rem; margin: 0; }
    .ft-icon { position: relative; flex-shrink: 0; width: 3.5rem; height: 3.5rem; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; background: rgba(167, 139, 250, 0.12); color: var(--accent); }
    .ft-icon svg { width: 2.5rem; height: 2.5rem; }
    .ft-icon__label { position: absolute; left: 50%; bottom: 0.55rem; transform: translateX(-50%); font-size: 0.55rem; font-weight: 700; letter-spacing: 0.02em; line-height: 1; }
    .ft-icon--dir { background: rgba(34, 211, 238, 0.12); color: #22d3ee; }
    .ft-icon--archive { background: rgba(251, 191, 36, 0.14); color: #fbbf24; }
    .ft-icon--image { background: rgba(52, 211, 153, 0.14); color: #34d399; }
    .ft-icon--video { background: rgba(192, 132, 252, 0.14); color: #c084fc; }
    .ft-icon--audio { background: rgba(244, 114, 182, 0.14); color: #f472b6; }
    .ft-icon--pdf { background: rgba(248, 113, 113, 0.14); color: #f87171; }
    .ft-icon--spreadsheet { background: rgba(74, 222, 128, 0.14); color: #4ade80; }
    .ft-icon--document { background: rgba(96, 165, 250, 0.14); color: #60a5fa; }
    .ft-icon--presentation { background: rgba(251, 146, 60, 0.14); color: #fb923c; }
    .ft-icon--code { background: rgba(129, 140, 248, 0.14); color: #818cf8; }
    .ft-icon--executable { background: rgba(248, 113, 113, 0.14); color: #f87171; }
    .ft-icon--file { background: rgba(161, 161, 170, 0.14); color: #a1a1aa; }
    .btn-download { display: inline-block; background: var(--accent-dim); color: #fff; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; }
    .btn-download:hover { background: var(--accent); }
    .preview { margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--border); }
    .preview h2 { font-size: 1rem; color: var(--text-muted); margin: 0 0 1rem; font-weight: 500; }
    .preview-md h1, .preview-md h2, .preview-md h3 { margin-top: 1.25em; }
    .preview-code { background: #0a0a0c; border: 1px solid var(--border); border-radius: 8px; padding: 1rem; overflow-x: auto; font-size: 0.85rem; }
    ';
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($name) . '</title><style>' . $css . '</style></head><body><div class="page"><div class="card">';
    $html .= '<div class="file-header">';
    $html .= fileTypeIconHtml($ext, false);
    $html .= '<div class="file-header-text"><h1>' . h($name) . '</h1>';
    $html .= '<p class="meta">' . h(formatSize($size)) . ' · Modified ' . h($mtimeFormatted) . '</p></div></div>';
    $html .= '<a class="btn-download" href="' . h($downloadUrl) . '">Download</a>';
    if ($previewHtml !== '') {
        $html .= '<div class="preview"><h2>Preview</h2>' . $previewHtml . '</div>';
    }
    $html .= '</div></div></body></html>';
    return $html;
}

$canBrowse = !$setupNeeded || $inShareMode;
$blockedMessage = null;

if ($inShareMode && $shareContext && $shareContext['type'] === 'file') {
    $shareFilePath = trim($shareContext['path'], '/');
    $effectivePath = $relativePath !== '' ? $relativePath : $shareFilePath;
    $shareFileAbs = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $effectivePath);
    $shareFileReal = realpath($shareFileAbs);
    if ($shareFileReal === false || !is_file($shareFileReal) || (!$allowOutside && !pathUnderBase($shareFileReal, $realBase))) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Shared file not found.');
    }
    if (isset($_GET['download'])) {
        serveSharedFileDownload($shareFileReal, basename($effectivePath));
    }
    if (!isset($_GET['content'])) {
        $shareExt = strtolower(pathinfo($effectivePath, PATHINFO_EXTENSION));
        $shareIsText = isset($previewExts[$shareExt]) || !looksLikeBinary($shareFileReal);
        $shareMtime = @filemtime($shareFileReal);
        header('Content-Type: text/html; charset=UTF-8');
        echo renderShareFileLandingPage($effectivePath, $shareFileReal, $shareContext, $indexHref, $shareIsText, filesize($shareFileReal), $shareMtime !== false ? (int) $shareMtime : null, $previewExts, $shareExt);
        exit;
    }
    $relativePath = $effectivePath;
}

if ($relativePath !== '') {
    $requestedPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $requestedReal = realpath($requestedPath);
    if ($inShareMode && isset($_GET['download']) && is_file($requestedPath) && $requestedReal !== false && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        serveSharedFileDownload($requestedReal, basename($relativePath));
    }
    if ($inShareMode && $shareContext && $shareContext['type'] === 'dir' && is_file($requestedPath) && $requestedReal !== false
        && !isset($_GET['content']) && !isset($_GET['download']) && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        $fileExt = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $fileIsText = isset($previewExts[$fileExt]) || !looksLikeBinary($requestedReal);
        $fileMtime = @filemtime($requestedReal);
        header('Content-Type: text/html; charset=UTF-8');
        echo renderShareFileLandingPage($relativePath, $requestedReal, $shareContext, $indexHref, $fileIsText, filesize($requestedReal), $fileMtime !== false ? (int) $fileMtime : null, $previewExts, $fileExt);
        exit;
    }
    $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
    $isMdFullPage = ($ext === 'md' && !isset($_GET['content']) && !($inShareMode && $shareContext && $shareContext['type'] === 'dir'));
    if ($canBrowse && is_file($requestedPath) && $isMdFullPage && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        $md = @file_get_contents($requestedPath);
        if ($md !== false) {
            header('Content-Type: text/html; charset=UTF-8');
            echo renderMarkdownPage($md, $relativePath, $indexHref);
            exit;
        }
    }
    if ($canBrowse && is_file($requestedPath) && isset($_GET['content']) && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($inShareMode) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Not allowed.');
    }
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!validCsrfToken($csrf)) {
        if (isShareAjaxRequest() && $action === 'share_revoke') {
            shareAjaxResponse(false, 'Security check failed. Please try again.');
        }
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
        $restrictPrivateNetworks = !empty($_POST['restrict_private_networks']);
        $setupSettings = [
            'upload_enabled' => '1',
            'auth_username' => $username,
            'auth_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'upload_max_bytes' => (string) $maxBytesInt,
        ];
        if ($restrictPrivateNetworks) {
            $setupSettings['ip_whitelist'] = dirindexBuildPrivateNetworkWhitelist($clientIp);
        }
        $saveError = null;
        $saved = saveDirindexStoredConfig(__DIR__, $setupSettings, $saveError);
        if (!$saved) {
            dirindexFlashSet($saveError ?: ('Could not write ' . basename(dirindexStoragePath(__DIR__)) . ' in ' . __DIR__ . '.'));
            redirectToCurrentListing($indexHref, $relativePath, 'setup_write_failed');
        }
        session_regenerate_id(true);
        $_SESSION['dirindex_authenticated'] = true;
        if ($restrictPrivateNetworks && $clientIp !== '' && !isPrivateOrLocalIp($clientIp)) {
            dirindexFlashSet('Private-network access is enabled. Your current IP (' . $clientIp . ') was added to the whitelist so you stay signed in.');
        }
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

    if ($action === 'settings') {
        if (!$hasUploadCredentials || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, 'auth_required');
        }
        $maxBytes = trim((string) ($_POST['upload_max_bytes'] ?? ''));
        $maxBytesInt = ($maxBytes !== '' && ctype_digit($maxBytes)) ? (int) $maxBytes : 0;
        $ipWhitelistEntries = parseIpAccessListInput($_POST['ip_whitelist'] ?? '');
        $ipBlacklistEntries = parseIpAccessListInput($_POST['ip_blacklist'] ?? '');
        $invalidIpEntry = null;
        if (!validateIpAccessList($ipWhitelistEntries, $invalidIpEntry) || !validateIpAccessList($ipBlacklistEntries, $invalidIpEntry)) {
            redirectToCurrentListing($indexHref, $relativePath, 'ip_access_invalid');
        }
        $ipHeader = normalizeIpHeaderInput($_POST['ip_header'] ?? '');
        if ($ipHeader === null) {
            redirectToCurrentListing($indexHref, $relativePath, 'ip_header_invalid');
        }
        $saveError = null;
        $saved = saveDirindexStoredConfig(__DIR__, [
            'show_symlinks' => isset($_POST['show_symlinks']) ? '1' : '0',
            'allow_open_symlinks_outside' => isset($_POST['allow_open_symlinks_outside']) ? '1' : '0',
            'upload_enabled' => isset($_POST['upload_enabled']) ? '1' : '0',
            'upload_max_bytes' => (string) $maxBytesInt,
            'ip_whitelist' => $ipWhitelistEntries,
            'ip_blacklist' => $ipBlacklistEntries,
            'ip_header' => $ipHeader,
        ], $saveError);
        redirectToCurrentListing($indexHref, $relativePath, $saved ? 'settings_saved' : 'settings_write_failed');
    }

    if ($action === 'account') {
        if (!$hasUploadCredentials || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, 'auth_required');
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if ($username === '') {
            redirectToCurrentListing($indexHref, $relativePath, 'account_missing');
        }
        if ($password !== '' || $confirm !== '') {
            if (!hash_equals($password, $confirm)) {
                redirectToCurrentListing($indexHref, $relativePath, 'account_mismatch');
            }
            if (strlen($password) < 8) {
                redirectToCurrentListing($indexHref, $relativePath, 'account_short_password');
            }
        }
        $settings = ['auth_username' => $username];
        if ($password !== '') {
            $settings['auth_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $saveError = null;
        $saved = saveDirindexStoredConfig(__DIR__, $settings, $saveError);
        redirectToCurrentListing($indexHref, $relativePath, $saved ? 'account_saved' : 'settings_write_failed');
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

    if ($action === 'create_entry') {
        if (!$hasUploadCredentials || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, 'auth_required');
        }
        if (!is_dir($currentPath) || !is_writable($currentPath)) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_not_writable');
        }
        $name = cleanUploadFilename($_POST['entry_name'] ?? '');
        if ($name === null || dirindexIsHiddenListingEntry($name)) {
            redirectToCurrentListing($indexHref, $relativePath, 'create_bad_name');
        }
        $entryType = (string) ($_POST['entry_type'] ?? '');
        $destination = $currentPath . DIRECTORY_SEPARATOR . $name;
        if ($entryType === 'folder') {
            if (file_exists($destination)) {
                redirectToCurrentListing($indexHref, $relativePath, 'create_exists');
            }
            if (!@mkdir($destination, 0755)) {
                redirectToCurrentListing($indexHref, $relativePath, 'create_failed');
            }
            redirectToCurrentListing($indexHref, $relativePath, 'create_folder_ok');
        }
        if ($entryType === 'file') {
            if (file_exists($destination)) {
                if (is_dir($destination) || is_link($destination)) {
                    redirectToCurrentListing($indexHref, $relativePath, 'upload_target_blocked');
                }
                redirectToCurrentListing($indexHref, $relativePath, 'create_exists');
            }
            if (@file_put_contents($destination, '') === false) {
                redirectToCurrentListing($indexHref, $relativePath, 'create_failed');
            }
            redirectToCurrentListing($indexHref, $relativePath, 'create_file_ok');
        }
        redirectToCurrentListing($indexHref, $relativePath, 'bad_action');
    }

    if ($action === 'share_create') {
        if (!$hasUploadCredentials || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, 'auth_required');
        }
        if (!$sharesAvailable) {
            redirectToCurrentListing($indexHref, $relativePath, 'share_unavailable');
        }
        $sharePath = trim((string) ($_POST['share_path'] ?? ''), '/');
        if ($sharePath === '' || strpos($sharePath, '..') !== false || str_contains($sharePath, "\0")) {
            redirectToCurrentListing($indexHref, $relativePath, 'share_failed');
        }
        $shareAbs = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sharePath);
        $shareResolved = resolveShareableEntry($shareAbs, $realBase, $allowOutside);
        if (!$shareResolved['ok']) {
            redirectToCurrentListing($indexHref, $relativePath, $shareResolved['error']);
        }
        $shareType = $shareResolved['type'];
        $shareError = null;
        $pdo = dirindexGetSharesPdo(__DIR__, $shareError);
        if (!$pdo) {
            redirectToCurrentListing($indexHref, $relativePath, 'share_unavailable');
        }
        $expiresAt = shareExpiryFromChoice($_POST['expires'] ?? 'never');
        $token = createShare($pdo, $sharePath, $shareType, $expiresAt);
        $_SESSION['share_created_token'] = $token;
        redirectToCurrentListing($indexHref, $relativePath, 'share_created');
    }

    if ($action === 'share_revoke') {
        if (!$hasUploadCredentials || !$authenticated) {
            if (isShareAjaxRequest()) {
                shareAjaxResponse(false, 'Please sign in first.');
            }
            redirectToCurrentListing($indexHref, $relativePath, 'auth_required');
        }
        if (!$sharesAvailable) {
            if (isShareAjaxRequest()) {
                shareAjaxResponse(false, 'Share links require PDO SQLite.');
            }
            redirectToCurrentListing($indexHref, $relativePath, 'share_unavailable');
        }
        $revokeToken = trim((string) ($_POST['share_token'] ?? ''));
        $shareError = null;
        $pdo = dirindexGetSharesPdo(__DIR__, $shareError);
        if (!$pdo || $revokeToken === '' || !revokeShare($pdo, $revokeToken)) {
            if (isShareAjaxRequest()) {
                shareAjaxResponse(false, 'Could not create or revoke the share link.');
            }
            redirectToCurrentListing($indexHref, $relativePath, 'share_failed');
        }
        if (isShareAjaxRequest()) {
            shareAjaxResponse(true, 'Share link revoked.');
        }
        redirectToCurrentListing($indexHref, $relativePath, 'share_revoked');
    }

    redirectToCurrentListing($indexHref, $relativePath, 'bad_action');
}

$parentPath = dirname($currentPath);
// Has parent if we have a logical parent in the path (so ".." works even inside symlinked dirs)
$showListing = $canBrowse;
$shareRootPath = ($inShareMode && $shareContext && $shareContext['type'] === 'dir') ? trim($shareContext['path'], '/') : '';
$hasParent = $showListing && $relativePath !== '' && (!$inShareMode || ($shareRootPath !== '' && $relativePath !== $shareRootPath));
$activeShares = [];
if ($authenticated && $sharesAvailable && !$inShareMode) {
    $shareListError = null;
    $shareListPdo = dirindexGetSharesPdo(__DIR__, $shareListError);
    if ($shareListPdo) {
        $activeShares = listActiveShares($shareListPdo);
    }
}
$shareCreatedUrl = null;
if (isset($_SESSION['share_created_token'])) {
    $shareCreatedUrl = shareUrl($indexHref, (string) $_SESSION['share_created_token'], [], true);
    unset($_SESSION['share_created_token']);
}

$items = [];
clearstatcache(true);
$handle = $showListing ? @opendir($currentPath) : false;
if ($handle) {
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        if (dirindexIsHiddenListingEntry($entry)) continue;
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
        $isBrokenLink = $isLink && realpath($full) === false;
        $ext = $isFile ? strtolower(pathinfo($entry, PATHINFO_EXTENSION)) : '';
        $isText = $isFile && (isset($previewExts[$ext]) || !looksLikeBinary($full));
        $entryPerms = @fileperms($full);
        $items[] = [
            'name'       => $entry,
            'path'       => $relativePath ? $relativePath . '/' . $entry : $entry,
            'isDir'      => is_dir($full),
            'isLink'     => $isLink,
            'isBrokenLink' => $isBrokenLink,
            'linkTarget' => $linkTarget,
            'size'       => $isFile ? filesize($full) : null,
            'mtime'      => $mtime,
            'perms'      => $entryPerms !== false ? (int) $entryPerms : null,
            'permsLabel' => formatEntryPermissions($full),
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
    'account_mismatch' => ['error', 'The account passwords did not match.'],
    'account_missing' => ['error', 'Enter an admin username.'],
    'account_saved' => ['success', 'Admin account updated.'],
    'account_short_password' => ['error', 'Use a password with at least 8 characters.'],
    'auth_required' => ['error', 'Please sign in first.'],
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
    'settings_saved' => ['success', 'Settings saved.'],
    'settings_write_failed' => ['error', 'Could not save settings. Check file permissions.'],
    'ip_access_invalid' => ['error', 'Access list contains an invalid IP address or CIDR range.'],
    'ip_header_invalid' => ['error', 'Client IP header name is not valid.'],
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
    'create_bad_name' => ['error', 'Name is not allowed.'],
    'create_exists' => ['error', 'An entry with that name already exists.'],
    'create_failed' => ['error', 'Could not create the entry.'],
    'create_folder_ok' => ['success', 'Folder created.'],
    'create_file_ok' => ['success', 'File created.'],
    'share_created' => ['success', 'Share link created. Copy the link below.'],
    'share_revoked' => ['success', 'Share link revoked.'],
    'share_failed' => ['error', 'Could not create or revoke the share link.'],
    'share_broken_link' => ['error', 'Cannot share a broken symbolic link.'],
    'share_link_outside' => ['error', 'That symlink points outside the listing root. Enable "Allow opening symlinks outside the listing root" in Settings to share it.'],
    'share_unavailable' => ['error', 'Share links require PDO SQLite.'],
];
$statusMessage = null;
if (isset($_GET['msg'], $messageMap[$_GET['msg']])) {
    $statusMessage = $messageMap[$_GET['msg']];
    $flashDetail = $sessionNeeded ? dirindexFlashTake() : null;
    if ($flashDetail !== null && $flashDetail !== '') {
        $statusMessage = [$statusMessage[0], $statusMessage[1] . ' ' . $flashDetail];
    }
}
$storageWritable = dirindexStorageWritable(__DIR__, $storageWritableDetail);
$openLoginModal = $hasUploadCredentials && !$authenticated && isset($_GET['msg']) && in_array((string) $_GET['msg'], ['auth_required', 'login_failed'], true);
$existingNames = [];
foreach ($items as $item) {
    $existingNames[] = $item['name'];
}

// Optional ?open=filename to open file in modal on load (shareable URL)
$openFileForModal = null;
if ($canBrowse && isset($_GET['open']) && $_GET['open'] !== '') {
    $openParam = trim((string) $_GET['open'], '/');
    if ($openParam !== '' && strpos($openParam, '..') === false && !str_contains($openParam, "\0")) {
        $openFilePath = $relativePath !== '' ? $relativePath . '/' . $openParam : $openParam;
        $openAbsPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $openFilePath);
        $openReal = realpath($openAbsPath);
        if ($openReal !== false && is_file($openReal) && (pathUnderBase($openReal, $realBase) || $allowOutside)) {
            $openExt = strtolower(pathinfo($openFilePath, PATHINFO_EXTENSION));
            $isText = isset($previewExts[$openExt]) || !looksLikeBinary($openReal);
            $openName = basename($openFilePath);
            $openMtime = @filemtime($openReal);
            $openMtimeFormatted = ($openMtime !== false && $openMtime >= 0 && $openMtime <= 2147483647) ? (@date('Y-m-d H:i', (int) $openMtime) ?: '—') : '—';
            $openDownloadUrl = $inShareMode ? currentListingUrl($indexHref, $openFilePath, ['download' => '1']) : directEntryUrl($openFilePath);
            if ($isText) {
                $openFileForModal = [
                    'content_url' => currentListingUrl($indexHref, $openFilePath, ['content' => '1']),
                    'name'        => $openName,
                    'open_url'    => $openDownloadUrl,
                    'share_path'  => $openFilePath,
                ];
            } else {
                $openFileForModal = [
                    'binary'       => true,
                    'name'         => $openName,
                    'download_url' => $openDownloadUrl,
                    'size'         => formatSize(filesize($openReal)),
                    'mtime'        => $openMtimeFormatted,
                    'icon_html'    => fileTypeIconHtml($openExt, false),
                    'share_path'   => $openFilePath,
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

function formatEntryPermissions($path) {
    $perms = @fileperms($path);
    if ($perms === false) {
        return null;
    }
    if (($perms & 0xC000) === 0xC000) {
        $type = 's';
    } elseif (($perms & 0xA000) === 0xA000) {
        $type = 'l';
    } elseif (($perms & 0x8000) === 0x8000) {
        $type = '-';
    } elseif (($perms & 0x6000) === 0x6000) {
        $type = 'b';
    } elseif (($perms & 0x4000) === 0x4000) {
        $type = 'd';
    } elseif (($perms & 0x2000) === 0x2000) {
        $type = 'c';
    } elseif (($perms & 0x1000) === 0x1000) {
        $type = 'p';
    } else {
        $type = '?';
    }
    $info = $type;
    $info .= ($perms & 0x0100) ? 'r' : '-';
    $info .= ($perms & 0x0080) ? 'w' : '-';
    $info .= ($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x') : (($perms & 0x0800) ? 'S' : '-');
    $info .= ($perms & 0x0020) ? 'r' : '-';
    $info .= ($perms & 0x0010) ? 'w' : '-';
    $info .= ($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x') : (($perms & 0x0400) ? 'S' : '-');
    $info .= ($perms & 0x0004) ? 'r' : '-';
    $info .= ($perms & 0x0002) ? 'w' : '-';
    $info .= ($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x') : (($perms & 0x0200) ? 'T' : '-');
    return $info;
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

$title = $setupNeeded ? 'Set up PHP Directory Index' : ($inShareMode ? 'Shared: /' . h($relativePath ?: '') : ($relativePath ? 'Index of /' . h($relativePath) : 'Index of /'));
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
        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-shrink: 0;
        }
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
        .entry-share {
            width: 1.75rem;
            height: 1.75rem;
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 7px;
            color: var(--text-muted);
            background: transparent;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.15s, color 0.15s, border-color 0.15s, background 0.15s;
        }
        .listing tr:hover .entry-share,
        .entry-share:focus-visible { opacity: 1; }
        .entry-share:hover { color: var(--accent); border-color: var(--accent-dim); background: var(--bg); }
        .entry-share svg { width: 0.95rem; height: 0.95rem; }
        .name-actions { display: flex; align-items: center; gap: 0.35rem; flex-shrink: 0; }
        .share-url-box {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        .share-url-box input {
            flex: 1;
            min-width: 0;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--bg);
            color: var(--text);
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.8rem;
        }
        .shares-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 0.85rem; margin-top: 0.75rem; }
        .shares-table th, .shares-table td { text-align: left; padding: 0.5rem 0.4rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .shares-table th { color: var(--text-muted); font-weight: 500; }
        .shares-table .shares-col-path { width: auto; }
        .shares-table .shares-col-type { width: 3.5rem; }
        .shares-table .shares-col-expires { width: 7.5rem; }
        .shares-table .shares-col-actions { width: 15rem; }
        .shares-table .shares-cell-clip {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }
        .shares-table code { font-size: 0.75rem; }
        .shares-list-message[hidden] { display: none !important; }
        .shares-list-message { margin-top: 0; margin-bottom: 0.75rem; }
        .share-actions { display: flex; gap: 0.35rem; flex-wrap: nowrap; align-items: center; }
        .share-actions .share-revoke-form { display: inline-flex; margin: 0; flex-shrink: 0; }
        .share-actions .btn-share-sm { flex-shrink: 0; white-space: nowrap; }
        .btn-share-sm {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: var(--bg);
            color: var(--text);
            cursor: pointer;
        }
        .btn-share-sm svg { width: 0.85rem; height: 0.85rem; flex-shrink: 0; }
        .btn-share-sm:hover { background: var(--hover); }
        .share-badge {
            display: inline-block;
            font-size: 0.75rem;
            color: var(--accent);
            background: color-mix(in srgb, var(--accent) 12%, transparent);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            margin-left: 0.5rem;
            vertical-align: middle;
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
            position: relative;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }
        .listing.is-dragover {
            border-color: var(--accent);
        }
        .listing.is-dragover::after {
            content: 'Drop file to upload';
            position: absolute;
            inset: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            background: color-mix(in srgb, var(--accent) 15%, var(--bg-card));
            border: 2px dashed var(--accent);
            border-radius: 10px;
            color: var(--accent);
            font-size: 1rem;
            font-weight: 600;
            pointer-events: none;
        }

        .listing table {
            width: 100%;
            border-collapse: collapse;
        }

        .listing-toolbar {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
            padding: 0.45rem 0.75rem;
            border-bottom: 1px solid var(--border);
            background: rgba(0,0,0,0.12);
            min-height: 2.25rem;
        }
        .listing-col-picker {
            position: relative;
            margin-right: auto;
        }
        .listing-col-picker-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.65rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: transparent;
            color: var(--text-muted);
            font-size: 0.75rem;
            cursor: pointer;
            transition: color 0.15s, border-color 0.15s, background 0.15s;
        }
        .listing-col-picker-btn:hover,
        .listing-col-picker-btn[aria-expanded="true"] {
            color: var(--text);
            border-color: var(--text-muted);
            background: var(--hover);
        }
        .listing-col-picker-btn .icon {
            width: 0.9rem;
            height: 0.9rem;
            opacity: 0.85;
        }
        .listing-col-picker-menu {
            position: absolute;
            top: calc(100% + 0.35rem);
            left: 0;
            z-index: 20;
            min-width: 9rem;
            padding: 0.35rem 0;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-card);
            box-shadow: 0 10px 24px rgba(0,0,0,0.28);
        }
        .listing-col-picker-menu[hidden] {
            display: none;
        }
        .listing-col-picker-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.75rem;
            font-size: 0.8rem;
            color: var(--text);
            cursor: pointer;
            transition: background 0.15s;
        }
        .listing-col-picker-option:hover {
            background: var(--hover);
        }
        .listing-col-picker-option input {
            accent-color: var(--accent);
            cursor: pointer;
        }
        .btn-listing-tool {
            padding: 0.3rem 0.65rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: transparent;
            color: var(--text-muted);
            font-size: 0.75rem;
            cursor: pointer;
            transition: color 0.15s, border-color 0.15s, background 0.15s;
        }
        .btn-listing-tool:hover {
            color: var(--text);
            border-color: var(--text-muted);
            background: var(--hover);
        }

        .listing th {
            text-align: left;
            padding: 0;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            background: rgba(0,0,0,0.2);
            border-bottom: 1px solid var(--border);
        }
        .listing th.size { text-align: right; }
        .listing th.modified { text-align: right; }
        .listing th.perms { text-align: right; }
        .listing-sort-btn {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            width: 100%;
            padding: 0.75rem 1rem;
            border: none;
            background: transparent;
            color: inherit;
            font: inherit;
            text-transform: inherit;
            letter-spacing: inherit;
            cursor: pointer;
            transition: color 0.15s, background 0.15s;
        }
        .listing th.size .listing-sort-btn,
        .listing th.modified .listing-sort-btn,
        .listing th.perms .listing-sort-btn {
            justify-content: flex-end;
        }
        .listing-sort-btn:hover,
        .listing-sort-btn:focus-visible {
            color: var(--text);
            background: var(--hover);
            outline: none;
        }
        .listing-sort-indicator {
            font-size: 0.65rem;
            opacity: 0.9;
            min-width: 0.65rem;
        }
        .listing table.listing-hide-size th.size,
        .listing table.listing-hide-size td.size,
        .listing table.listing-hide-modified th.modified,
        .listing table.listing-hide-modified td.modified,
        .listing table.listing-hide-perms th.perms,
        .listing table.listing-hide-perms td.perms {
            display: none;
        }

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
        .listing .name a.file-preview,
        .listing .name a.file-binary { cursor: pointer; }
        .name-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .entry-open-new {
            width: 1.75rem;
            height: 1.75rem;
            flex: 0 0 auto;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 7px;
            color: var(--text-muted) !important;
            background: transparent;
            opacity: 0;
            transition: opacity 0.15s, color 0.15s, border-color 0.15s, background 0.15s;
        }
        .listing tr:hover .entry-open-new,
        .entry-open-new:focus-visible {
            opacity: 1;
        }
        .entry-open-new:hover {
            color: var(--accent) !important;
            border-color: var(--accent-dim);
            background: var(--bg);
        }
        .entry-open-new .icon {
            width: 0.95rem;
            height: 0.95rem;
        }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center; padding: 2rem; box-sizing: border-box; }
        .modal-overlay.is-open { display: flex; }
        .modal { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; max-width: 95vw; max-height: 85vh; width: 1200px; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        .modal-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); flex-shrink: 0; }
        .modal-title-wrap { display: flex; align-items: center; gap: 0.75rem; flex: 1; min-width: 0; }
        .modal-title { font-family: 'JetBrains Mono', monospace; font-size: 0.9rem; color: var(--text); word-break: break-all; }
        .modal-header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        .modal-open-link { font-size: 0.8rem; color: var(--accent); text-decoration: none; white-space: nowrap; }
        .modal-open-link:hover { text-decoration: underline; }
        .modal-share-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.55rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: transparent;
            color: var(--text-muted);
            font-size: 0.8rem;
            white-space: nowrap;
            cursor: pointer;
            transition: color 0.15s, border-color 0.15s, background 0.15s;
        }
        .modal-share-btn:hover,
        .modal-share-btn:focus-visible {
            color: var(--accent);
            border-color: var(--accent-dim);
            background: var(--hover);
            outline: none;
        }
        .modal-share-btn svg { width: 0.9rem; height: 0.9rem; }
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
        .modal.is-binary { width: min(520px, 95vw); }
        .modal-binary[hidden] { display: none !important; }
        .modal-binary-header { display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; }
        .modal-binary-text { min-width: 0; }
        .modal-binary-name { margin: 0 0 0.5rem; font-size: 1.25rem; font-weight: 600; word-break: break-word; }
        .modal-binary-meta { margin: 0; color: var(--text-muted); font-size: 0.9rem; }
        .ft-icon { position: relative; flex-shrink: 0; width: 3.5rem; height: 3.5rem; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; background: rgba(167, 139, 250, 0.12); color: var(--accent); }
        .ft-icon svg { width: 2.5rem; height: 2.5rem; }
        .ft-icon__label { position: absolute; left: 50%; bottom: 0.55rem; transform: translateX(-50%); font-size: 0.55rem; font-weight: 700; letter-spacing: 0.02em; line-height: 1; }
        .ft-icon--dir { background: rgba(34, 211, 238, 0.12); color: #22d3ee; }
        .ft-icon--archive { background: rgba(251, 191, 36, 0.14); color: #fbbf24; }
        .ft-icon--image { background: rgba(52, 211, 153, 0.14); color: #34d399; }
        .ft-icon--video { background: rgba(192, 132, 252, 0.14); color: #c084fc; }
        .ft-icon--audio { background: rgba(244, 114, 182, 0.14); color: #f472b6; }
        .ft-icon--pdf { background: rgba(248, 113, 113, 0.14); color: #f87171; }
        .ft-icon--spreadsheet { background: rgba(74, 222, 128, 0.14); color: #4ade80; }
        .ft-icon--document { background: rgba(96, 165, 250, 0.14); color: #60a5fa; }
        .ft-icon--presentation { background: rgba(251, 146, 60, 0.14); color: #fb923c; }
        .ft-icon--code { background: rgba(129, 140, 248, 0.14); color: #818cf8; }
        .ft-icon--executable { background: rgba(248, 113, 113, 0.14); color: #f87171; }
        .ft-icon--file { background: rgba(161, 161, 170, 0.14); color: #a1a1aa; }
        .btn-download { display: inline-block; background: var(--accent-dim); color: #fff; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; }
        .btn-download:hover { background: var(--accent); color: #fff; }
        .listing .name.binary a { color: var(--text-muted); }
        .listing .name.binary a:hover { color: var(--accent); }

        .listing .size, .listing .modified, .listing .perms {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .listing .size { text-align: right; }
        .listing .col-size { min-width: 5.5rem; }
        .listing td.size, .listing th.size { white-space: nowrap; }
        .listing .col-modified { min-width: 10rem; }
        .listing td.modified, .listing th.modified { white-space: nowrap; }
        .listing .col-perms { min-width: 6.5rem; }
        .listing td.perms, .listing th.perms { white-space: nowrap; }

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
        .settings-modal { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; width: 100%; max-width: 680px; max-height: 88vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .settings-modal .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
        .settings-modal .modal-title { font-size: 1rem; font-weight: 600; }
        .settings-modal .modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; min-height: 0; }
        .login-modal-panel,
        .share-modal-panel { max-width: 440px; }
        .shares-list-panel { max-width: 1100px; }
        .share-item-display {
            padding: 0.55rem 0.65rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text);
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.85rem;
            word-break: break-all;
            line-height: 1.4;
        }
        .share-item-type {
            color: var(--text-muted);
            font-family: inherit;
            font-size: 0.8rem;
        }
        .share-form-note {
            margin: 0;
            line-height: 1.45;
        }
        .share-form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            padding-top: 0.35rem;
        }
        .login-modal-panel .auth-form {
            display: grid;
            gap: 0.8rem;
        }
        .login-modal-panel .auth-actions {
            margin-top: 0.2rem;
        }
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
        a.btn-auth, a.btn-share-sm {
            text-decoration: none;
            display: inline-flex;
            align-items: center;
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
        .admin-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .admin-bar h2 {
            margin-bottom: 0.25rem;
        }
        .admin-bar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            align-items: center;
            justify-content: flex-end;
        }
        .upload-panel {
            display: none;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        .upload-panel.is-open {
            display: block;
        }
        .settings-section {
            padding: 1rem 0;
            border-top: 1px solid var(--border);
        }
        .settings-section:first-child {
            padding-top: 0;
            border-top: none;
        }
        .settings-section h3 {
            margin: 0 0 0.75rem;
            color: var(--text);
            font-size: 0.95rem;
        }
        .settings-form {
            display: grid;
            gap: 0.8rem;
        }
        .settings-field {
            display: grid;
            gap: 0.3rem;
        }
        .settings-field label,
        .settings-check-row span {
            color: var(--text);
            font-size: 0.9rem;
        }
        .settings-field input,
        .settings-field select,
        .settings-field textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text);
            padding: 0.55rem 0.65rem;
            font: inherit;
        }
        .settings-field textarea {
            min-height: 5.5rem;
            resize: vertical;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            line-height: 1.45;
        }
        .settings-field select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M3 4.5 6 7.5 9 4.5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.65rem center;
            padding-right: 2rem;
            cursor: pointer;
        }
        .settings-help {
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        .settings-check-row {
            display: flex;
            align-items: flex-start;
            gap: 0.55rem;
        }
        .settings-check-row input {
            margin-top: 0.2rem;
            accent-color: var(--accent-dim);
        }
        .settings-form .btn-auth {
            justify-self: start;
        }
        .settings-inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .settings-detected-ip {
            margin: 0;
            font-size: 0.85rem;
        }
        .settings-detected-ip code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.82rem;
        }
        @media (max-width: 640px) {
            .admin-bar {
                align-items: stretch;
                flex-direction: column;
            }
            .admin-bar-actions {
                justify-content: flex-start;
            }
        }
        body.setup-mode {
            background:
                radial-gradient(circle at top left, rgba(124, 58, 237, 0.22), transparent 28rem),
                radial-gradient(circle at bottom right, rgba(103, 232, 249, 0.12), transparent 26rem),
                var(--bg);
        }
        .setup-page {
            width: min(1120px, 100%);
            min-height: 100vh;
            margin: 0 auto;
            padding: clamp(1.5rem, 4vw, 4rem);
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(320px, 440px);
            gap: clamp(1.5rem, 5vw, 4rem);
            align-items: center;
        }
        .setup-hero {
            max-width: 600px;
        }
        .setup-kicker {
            margin: 0 0 0.85rem;
            color: var(--accent);
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }
        .setup-hero h1 {
            margin: 0;
            color: var(--text);
            font-family: 'Outfit', system-ui, sans-serif;
            font-size: clamp(2.15rem, 5vw, 4.25rem);
            line-height: 1;
            letter-spacing: -0.045em;
            word-break: normal;
        }
        .setup-lede {
            margin: 1.25rem 0 0;
            max-width: 38rem;
            color: var(--text-muted);
            font-size: clamp(1rem, 2vw, 1.2rem);
        }
        .setup-benefits {
            display: grid;
            gap: 0.9rem;
            margin-top: 2rem;
        }
        .setup-benefit {
            display: flex;
            gap: 0.85rem;
            align-items: flex-start;
            padding: 1rem;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: color-mix(in srgb, var(--bg-card) 78%, transparent);
        }
        .setup-benefit-icon {
            width: 2rem;
            height: 2rem;
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(124, 58, 237, 0.18);
            color: var(--accent);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.9rem;
        }
        .setup-benefit strong {
            display: block;
            margin-bottom: 0.15rem;
            color: var(--text);
        }
        .setup-benefit span {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        .setup-card {
            padding: clamp(1.25rem, 3vw, 2rem);
            background: color-mix(in srgb, var(--bg-card) 94%, transparent);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.28);
        }
        .setup-card h2 {
            margin: 0;
            color: var(--text);
            font-size: 1.35rem;
        }
        .setup-card p {
            margin: 0.5rem 0 0;
            color: var(--text-muted);
            font-size: 0.95rem;
        }
        .setup-card .blocked-msg {
            margin: 1rem 0 0;
        }
        .setup-form {
            display: grid;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .setup-form .auth-field {
            min-width: 0;
            flex: none;
        }
        .setup-form .auth-field input {
            padding: 0.72rem 0.8rem;
        }
        .setup-form .settings-check-row span code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.82rem;
        }
        .field-help {
            margin-top: 0.1rem;
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        .setup-actions {
            display: grid;
            gap: 0.75rem;
            margin-top: 0.35rem;
        }
        .setup-actions .btn-auth {
            width: 100%;
            padding: 0.75rem 1rem;
            font-weight: 600;
        }
        .setup-storage-note {
            color: var(--text-muted);
            font-size: 0.8rem;
            text-align: center;
        }
        @supports not (background: color-mix(in srgb, black, white)) {
            .setup-benefit,
            .setup-card {
                background: var(--bg-card);
            }
        }
        @media (max-width: 820px) {
            .setup-page {
                grid-template-columns: 1fr;
                align-items: start;
            }
            .setup-hero {
                max-width: none;
            }
        }
    </style>
</head>
<body class="<?= $setupNeeded ? 'setup-mode' : '' ?>"<?php if ($openFileForModal): ?><?php if (!empty($openFileForModal['binary'])): ?> data-open-binary="1" data-open-name="<?= h($openFileForModal['name']) ?>" data-open-download-url="<?= h($openFileForModal['download_url']) ?>" data-open-size="<?= h($openFileForModal['size']) ?>" data-open-mtime="<?= h($openFileForModal['mtime']) ?>" data-open-icon-html="<?= h($openFileForModal['icon_html']) ?>" data-open-share-path="<?= h($openFileForModal['share_path']) ?>"<?php else: ?> data-open-content-url="<?= h($openFileForModal['content_url']) ?>" data-open-name="<?= h($openFileForModal['name']) ?>" data-open-url="<?= h($openFileForModal['open_url']) ?>" data-open-share-path="<?= h($openFileForModal['share_path']) ?>"<?php endif; ?><?php endif; ?><?php if ($openLoginModal): ?> data-open-login="1"<?php endif; ?>>
    <?php if ($setupNeeded): ?>
    <main class="setup-page">
        <section class="setup-hero" aria-labelledby="setup-title">
            <p class="setup-kicker">First run setup</p>
            <h1 id="setup-title">Finish securing this directory index.</h1>
            <p class="setup-lede">Create the admin account used for uploads before the file browser is exposed. Until setup is complete, directory contents stay hidden.</p>
            <div class="setup-benefits" aria-label="Setup details">
                <div class="setup-benefit">
                    <span class="setup-benefit-icon">1</span>
                    <div>
                        <strong>Create upload credentials</strong>
                        <span>Use a dedicated username and a password with at least 8 characters.</span>
                    </div>
                </div>
                <div class="setup-benefit">
                    <span class="setup-benefit-icon">2</span>
                    <div>
                        <strong>Optionally restrict access</strong>
                        <span>Enable private-network browsing to keep the listing off the public internet. Share links still work for anyone.</span>
                    </div>
                </div>
                <div class="setup-benefit">
                    <span class="setup-benefit-icon">3</span>
                    <div>
                        <strong>Choose an upload limit</strong>
                        <span>Leave the limit at 0 to use your PHP server defaults, or set a byte limit for this app.</span>
                    </div>
                </div>
                <div class="setup-benefit">
                    <span class="setup-benefit-icon">4</span>
                    <div>
                        <strong>Start browsing</strong>
                        <span>After saving, you will be signed in and returned to the directory listing.</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="setup-card" aria-label="Setup form">
            <h2>Set up uploads</h2>
            <p>These settings will be saved locally in <?= h(basename($dirindexStorage['path'])) ?>.</p>
            <?php if (!$storageWritable): ?>
            <div class="blocked-msg message-error" role="status">
                <?= h($storageWritableDetail ?: 'PHP cannot write settings to this directory.') ?>
                Make the folder containing <code>index.php</code> writable by the web server user, then try again.
            </div>
            <?php endif; ?>
            <?php if ($statusMessage): ?>
            <div class="blocked-msg message-<?= h($statusMessage[0]) ?>" role="status">
                <?= h($statusMessage[1]) ?>
            </div>
            <?php endif; ?>
            <form class="setup-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>">
                <input type="hidden" name="action" value="setup">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <div class="auth-field">
                    <label for="setup-username">Admin username</label>
                    <input type="text" id="setup-username" name="username" autocomplete="username" placeholder="admin" required>
                    <span class="field-help">This account is only for upload access in this directory index.</span>
                </div>
                <div class="auth-field">
                    <label for="setup-password">Password</label>
                    <input type="password" id="setup-password" name="password" autocomplete="new-password" minlength="8" required>
                    <span class="field-help">Use at least 8 characters. Longer passphrases are better.</span>
                </div>
                <div class="auth-field">
                    <label for="setup-password-confirm">Confirm password</label>
                    <input type="password" id="setup-password-confirm" name="password_confirm" autocomplete="new-password" minlength="8" required>
                    <span class="field-help">Re-enter the password to catch typos before saving.</span>
                </div>
                <label class="settings-check-row">
                    <input type="checkbox" name="restrict_private_networks" value="1">
                    <span>
                        Restrict browsing to private networks
                        <span class="field-help">Allows RFC1918, link-local, and IPv6 private ranges. Loopback always works. Share links bypass IP rules.<?php if ($clientIp !== '' && !isPrivateOrLocalIp($clientIp)): ?> Your current IP (<code><?= h($clientIp) ?></code>) is public and will be added to the whitelist automatically.<?php endif; ?></span>
                    </span>
                </label>
                <div class="auth-field">
                    <label for="setup-upload-max">Upload limit in bytes</label>
                    <input type="number" id="setup-upload-max" name="upload_max_bytes" min="0" inputmode="numeric" placeholder="0">
                    <span class="field-help">Optional. Use 0 for your PHP configuration default.</span>
                </div>
                <div class="setup-actions">
                    <button type="submit" class="btn-auth">Save setup and continue</button>
                    <span class="setup-storage-note">Passwords are stored as hashes, not plain text.</span>
                </div>
            </form>
        </section>
    </main>
    <?php else: ?>
    <div class="page">
        <header>
            <div class="header-main">
                <h1><?= $inShareMode ? 'Shared' : 'Index of' ?> <strong>/<?= h($relativePath ?: '') ?></strong><?php if ($inShareMode): ?><span class="share-badge">Public link</span><?php endif; ?></h1>
                <nav class="breadcrumb">
                    <?php if ($inShareMode && $shareRootPath !== ''): ?>
                    <a href="<?= h(shareUrl($indexHref, $shareTokenActive)) ?>">[root]</a>
                    <?php
                    $segments = $relativePath ? explode('/', $relativePath) : [];
                    $acc = '';
                    foreach ($segments as $seg):
                        $acc .= ($acc ? '/' : '') . $seg;
                        if (!str_starts_with($acc, $shareRootPath) && $acc !== $shareRootPath) {
                            continue;
                        }
                        $crumbPath = $acc;
                    ?>
                        <span class="breadcrumb-sep" aria-hidden="true">›</span><a href="<?= h(currentListingUrl($indexHref, $crumbPath)) ?>"><?= h($seg) ?></a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <a href="<?= h($indexHref) ?>">[root]</a>
                    <?php
                    $segments = $relativePath ? explode('/', $relativePath) : [];
                    $acc = '';
                    foreach ($segments as $seg):
                        $acc .= ($acc ? '/' : '') . $seg;
                    ?>
                        <span class="breadcrumb-sep" aria-hidden="true">›</span><a href="<?= h(currentListingUrl($indexHref, $acc)) ?>"><?= h($seg) ?></a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </nav>
            </div>
            <div class="header-actions">
                <?php if ($hasUploadCredentials && !$authenticated && !$inShareMode): ?>
                <button type="button" class="btn-settings" id="btn-login" aria-label="Admin login" title="Admin login" aria-haspopup="dialog" aria-controls="login-modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </button>
                <?php endif; ?>
                <?php if ($authenticated && !$inShareMode && $sharesAvailable): ?>
                <button type="button" class="btn-settings" id="btn-shares" aria-label="Shared links" title="Shared links" aria-haspopup="dialog" aria-controls="shares-list-modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                </button>
                <?php endif; ?>
                <button type="button" class="btn-settings" id="btn-settings" aria-label="Settings" title="Settings" aria-haspopup="dialog" aria-controls="settings-modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </button>
            </div>
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

        <?php if ($shareCreatedUrl): ?>
        <div class="blocked-msg message-success" role="status">
            <p style="margin:0 0 0.5rem">Share link created:</p>
            <div class="share-url-box">
                <input type="text" id="share-created-url" readonly value="<?= h($shareCreatedUrl) ?>">
                <a href="<?= h($shareCreatedUrl) ?>" class="btn-auth btn-auth-secondary" target="_blank" rel="noopener noreferrer">Open</a>
                <button type="button" class="btn-auth" id="btn-copy-share-created">Copy</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($hasUploadCredentials && $authenticated && !$inShareMode): ?>
        <section class="auth-panel" aria-labelledby="upload-title">
            <div class="admin-bar">
                <div>
                    <h2 id="upload-title">Admin tools</h2>
                    <p>Signed in as <?= h($dirindexConfig['auth_username']) ?>. Uploads are <?= $uploadEnabled ? 'enabled' : 'disabled' ?>.</p>
                </div>
                <div class="admin-bar-actions">
                    <?php if ($uploadEnabled): ?>
                    <button type="button" class="btn-auth" id="btn-upload-toggle" aria-expanded="false" aria-controls="upload-panel">Upload file</button>
                    <?php endif; ?>
                    <form method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <button type="submit" class="btn-auth btn-auth-secondary">Sign out</button>
                    </form>
                </div>
            </div>
            <?php if ($uploadEnabled): ?>
            <div class="upload-panel" id="upload-panel">
                <form class="auth-form" id="upload-form" method="post" enctype="multipart/form-data" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" data-existing-names="<?= h(json_encode($existingNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="overwrite" id="upload-overwrite" value="">
                    <div class="auth-field">
                        <label for="upload-file">File</label>
                        <input type="file" id="upload-file" name="upload_file" required>
                    </div>
                    <div class="auth-actions">
                        <button type="submit" class="btn-auth">Upload to /<?= h($relativePath ?: '') ?></button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <div class="listing">
            <div class="listing-toolbar">
                <div class="listing-col-picker">
                    <button type="button" class="listing-col-picker-btn" id="listing-col-picker-btn" aria-expanded="false" aria-haspopup="true" aria-controls="listing-col-picker-menu">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                        Columns
                    </button>
                    <div class="listing-col-picker-menu" id="listing-col-picker-menu" role="menu" hidden>
                        <label class="listing-col-picker-option" role="menuitemcheckbox">
                            <input type="checkbox" id="setting-col-size" checked>
                            Size
                        </label>
                        <label class="listing-col-picker-option" role="menuitemcheckbox">
                            <input type="checkbox" id="setting-col-modified" checked>
                            Modified
                        </label>
                        <label class="listing-col-picker-option" role="menuitemcheckbox">
                            <input type="checkbox" id="setting-col-perms" checked>
                            Permissions
                        </label>
                    </div>
                </div>
                <?php if ($hasUploadCredentials && $authenticated && !$inShareMode): ?>
                <button type="button" class="btn-listing-tool" id="btn-create-folder">New folder</button>
                <button type="button" class="btn-listing-tool" id="btn-create-file">New file</button>
                <?php endif; ?>
                <button type="button" class="btn-listing-tool" id="listing-sort-reset" hidden>Reset sort</button>
            </div>
            <table id="listing-table">
                <colgroup>
                    <col class="col-name">
                    <col class="col-size">
                    <col class="col-modified">
                    <col class="col-perms">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="name" data-sort-col="name">
                            <button type="button" class="listing-sort-btn" data-sort-col="name">
                                Name <span class="listing-sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col" class="size" data-sort-col="size">
                            <button type="button" class="listing-sort-btn" data-sort-col="size">
                                Size <span class="listing-sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col" class="modified" data-sort-col="modified">
                            <button type="button" class="listing-sort-btn" data-sort-col="modified">
                                Modified <span class="listing-sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col" class="perms" data-sort-col="perms">
                            <button type="button" class="listing-sort-btn" data-sort-col="perms">
                                Permissions <span class="listing-sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($hasParent): $parentRel = dirname($relativePath); $parentRel = ($parentRel === '.' || $parentRel === '') ? '' : $parentRel; ?>
                    <tr data-sort-parent="1">
                        <td class="name dir">
                            <?php
                            $parentUrl = currentListingUrl($indexHref, $parentRel);
                            $parentDirectUrl = $inShareMode ? currentListingUrl($indexHref, $parentRel) : directEntryUrl($parentRel, true);
                            ?>
                            <div class="name-content">
                                <a href="<?= h($parentUrl) ?>">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                    ..
                                </a>
                                <div class="name-actions">
                                <?php if (!$inShareMode): ?>
                                <a class="entry-open-new" href="<?= h($parentDirectUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open parent directory in new tab" title="Open in new tab">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                                </a>
                                <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="size">&#8212;</td>
                        <td class="modified">&#8212;</td>
                        <td class="perms">&#8212;</td>
                    </tr>
                    <?php endif; ?>

                    <?php
                    foreach ($items as $item):
                        if ($item['isDir']) {
                            $url = currentListingUrl($indexHref, $item['path']);
                            $directUrl = $inShareMode ? $url : directEntryUrl($item['path'], true);
                            $linkAttrs = '';
                        } else {
                            if ($inShareMode) {
                                $directUrl = currentListingUrl($indexHref, $item['path'], ['download' => '1']);
                            } else {
                                $directUrl = directEntryUrl($item['path']);
                            }
                            if (!empty($item['isText'])) {
                                $url = currentListingUrl($indexHref, $item['path']);
                                $contentUrl = currentListingUrl($indexHref, $item['path'], ['content' => '1']);
                                $openUrl = $directUrl;
                                $linkAttrs = ' class="file-preview" data-content-url="' . h($contentUrl) . '" data-name="' . h($item['name']) . '" data-open-url="' . h($openUrl) . '" data-share-path="' . h($item['path']) . '"';
                            } else {
                                $url = '#';
                                $ts = isset($item['mtime']) ? $item['mtime'] : null;
                                $mtimeFormatted = '—';
                                if ($ts !== null && $ts >= 0 && $ts <= 2147483647) {
                                    $formatted = @date('Y-m-d H:i', (int) $ts);
                                    $mtimeFormatted = $formatted !== false ? $formatted : '—';
                                }
                                $linkAttrs = ' class="file-binary" title="View file info"'
                                    . ' data-name="' . h($item['name']) . '"'
                                    . ' data-download-url="' . h($directUrl) . '"'
                                    . ' data-size="' . h(formatSize($item['size'])) . '"'
                                    . ' data-mtime="' . h($mtimeFormatted) . '"'
                                    . ' data-icon-html="' . h(fileTypeIconHtml($item['ext'], false)) . '"'
                                    . ' data-share-path="' . h($item['path']) . '"';
                            }
                        }
                        $nameClass = ($item['isDir'] ? 'dir ' : '') . ($item['isLink'] ? 'symlink ' : '') . ((!$item['isDir'] && empty($item['isText'])) ? 'binary' : '');
                    ?>
                    <tr data-is-dir="<?= $item['isDir'] ? '1' : '0' ?>" data-sort-name="<?= h($item['name']) ?>" data-sort-size="<?= $item['isDir'] ? '-1' : (int) $item['size'] ?>" data-sort-mtime="<?= isset($item['mtime']) && $item['mtime'] !== null ? (int) $item['mtime'] : '0' ?>" data-sort-perms="<?= isset($item['perms']) && $item['perms'] !== null ? (int) $item['perms'] : '0' ?>">
                        <td class="name <?= trim($nameClass) ?>">
                            <div class="name-content">
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
                                <div class="name-actions">
                                <?php if ($authenticated && !$inShareMode && $sharesAvailable && empty($item['isBrokenLink'])): ?>
                                <button type="button" class="entry-share" data-share-path="<?= h($item['path']) ?>" data-share-type="<?= h($item['isDir'] ? 'dir' : 'file') ?>" aria-label="Share <?= h($item['name']) ?>" title="Create share link">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                                </button>
                                <?php endif; ?>
                                <?php if (!$inShareMode): ?>
                                <a class="entry-open-new" href="<?= h($directUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open <?= h($item['name']) ?> in new tab" title="Open in new tab">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                                </a>
                                <?php endif; ?>
                                </div>
                            </div>
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
                        <td class="perms"><?= !empty($item['permsLabel']) ? h($item['permsLabel']) : '&#8212;' ?></td>
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
        <div class="modal" id="file-modal-panel" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <span class="modal-title" id="modal-title"></span>
                </div>
                <div class="modal-header-actions">
                    <a id="modal-open-link" class="modal-open-link" href="#" target="_blank" rel="noopener noreferrer" style="display: none;">Open in new tab</a>
                    <?php if ($authenticated && !$inShareMode && $sharesAvailable): ?>
                    <button type="button" class="modal-share-btn" id="modal-share-btn" hidden aria-label="Share file" title="Create share link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                        Share
                    </button>
                    <?php endif; ?>
                </div>
                <button type="button" class="modal-close" id="modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modal-binary" class="modal-binary" hidden aria-hidden="true">
                    <div class="modal-binary-header">
                        <span id="modal-binary-icon"></span>
                        <div class="modal-binary-text">
                            <h2 id="modal-binary-name" class="modal-binary-name"></h2>
                            <p id="modal-binary-meta" class="modal-binary-meta"></p>
                        </div>
                    </div>
                    <a id="modal-binary-download" class="btn-download" href="#">Download</a>
                </div>
                <div id="modal-md" class="modal-md" aria-hidden="true"></div>
                <pre id="modal-pre"><code id="modal-code"></code></pre>
            </div>
        </div>
    </div>

    <?php if ($hasUploadCredentials && !$authenticated): ?>
    <div id="login-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal login-modal-panel" role="dialog" aria-modal="true" aria-labelledby="login-title">
            <div class="modal-header">
                <span class="modal-title" id="login-title">Admin login</span>
                <button type="button" class="modal-close" id="login-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
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
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="settings-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <div class="modal-header">
                <span class="modal-title" id="settings-title">Settings</span>
                <button type="button" class="modal-close" id="settings-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <section class="settings-section" aria-labelledby="display-settings-title">
                    <h3 id="display-settings-title">Display</h3>
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
                </section>

                <?php if ($authenticated && !$inShareMode): ?>
                <section class="settings-section" aria-labelledby="server-settings-title">
                    <h3 id="server-settings-title">Server settings</h3>
                    <form class="settings-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>">
                        <input type="hidden" name="action" value="settings">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <label class="settings-check-row">
                            <input type="checkbox" name="upload_enabled" value="1" <?= $uploadEnabled ? 'checked' : '' ?>>
                            <span>Enable uploads</span>
                        </label>
                        <label class="settings-check-row">
                            <input type="checkbox" name="show_symlinks" value="1" <?= !empty($dirindexConfig['show_symlinks']) ? 'checked' : '' ?>>
                            <span>Show symlinks in listings</span>
                        </label>
                        <label class="settings-check-row">
                            <input type="checkbox" name="allow_open_symlinks_outside" value="1" <?= !empty($dirindexConfig['allow_open_symlinks_outside']) ? 'checked' : '' ?>>
                            <span>Allow opening symlinks outside the listing root</span>
                        </label>
                        <div class="settings-field">
                            <label for="admin-upload-max">Upload limit in bytes</label>
                            <input type="number" id="admin-upload-max" name="upload_max_bytes" min="0" inputmode="numeric" value="<?= h((string) ((int) ($dirindexConfig['upload_max_bytes'] ?? 0))) ?>">
                            <span class="settings-help">Use 0 to rely on PHP's configured upload limit.</span>
                        </div>
                        <div class="settings-field">
                            <label for="admin-ip-whitelist">IP whitelist</label>
                            <textarea id="admin-ip-whitelist" name="ip_whitelist" rows="4" spellcheck="false" placeholder="192.168.1.0/24&#10;10.0.0.0/8"><?= h(formatIpAccessListForInput($ipWhitelist)) ?></textarea>
                            <span class="settings-help">One IP or CIDR per line. When non-empty, only these addresses can browse the index (loopback and share links are always allowed).</span>
                        </div>
                        <div class="settings-field">
                            <label for="admin-ip-blacklist">IP blacklist</label>
                            <textarea id="admin-ip-blacklist" name="ip_blacklist" rows="4" spellcheck="false" placeholder="203.0.113.50"><?= h(formatIpAccessListForInput($ipBlacklist)) ?></textarea>
                            <span class="settings-help">One IP or CIDR per line. Matching addresses are always denied unless they have a valid share link.</span>
                        </div>
                        <div class="settings-field">
                            <label for="admin-ip-header">Client IP header (reverse proxy)</label>
                            <?php
                            $ipHeaderCurrent = (string) ($dirindexConfig['ip_header'] ?? '');
                            $ipHeaderPresets = [
                                '' => 'REMOTE_ADDR (direct connection)',
                                'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For',
                                'HTTP_X_REAL_IP' => 'X-Real-IP',
                                'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)',
                            ];
                            ?>
                            <select id="admin-ip-header" name="ip_header">
                                <?php foreach ($ipHeaderPresets as $value => $label): ?>
                                <option value="<?= h($value) ?>"<?= $ipHeaderCurrent === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                                <?php if ($ipHeaderCurrent !== '' && !isset($ipHeaderPresets[$ipHeaderCurrent])): ?>
                                <option value="<?= h($ipHeaderCurrent) ?>" selected><?= h($ipHeaderCurrent) ?> (custom)</option>
                                <?php endif; ?>
                            </select>
                            <span class="settings-help">Use when behind a reverse proxy so whitelist/blacklist see the real client IP. If left on REMOTE_ADDR but requests come from a private proxy address, X-Real-IP / X-Forwarded-For are used automatically.</span>
                        </div>
                        <?php if ($clientIp !== ''): ?>
                        <p class="settings-detected-ip settings-help">Your detected IP: <code id="detected-client-ip"><?= h($clientIp) ?></code> (from <?= h(clientIpSourceLabel($clientIpSource)) ?><?php if ($clientIpProxy !== ''): ?>, via proxy <?= h($clientIpProxy) ?><?php endif; ?>)</p>
                        <?php if ($clientIpProxy !== '' && trim((string) ($dirindexConfig['ip_header'] ?? '')) === ''): ?>
                        <p class="settings-help">Requests reach PHP through a reverse proxy. Consider setting Client IP header to X-Forwarded-For so detection stays explicit.</p>
                        <?php endif; ?>
                        <div class="settings-inline-actions">
                            <button type="button" class="btn-auth btn-auth-secondary" id="btn-add-current-ip">Add my IP to whitelist</button>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn-auth">Save server settings</button>
                    </form>
                </section>

                <section class="settings-section" aria-labelledby="account-settings-title">
                    <h3 id="account-settings-title">Admin account</h3>
                    <form class="settings-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>">
                        <input type="hidden" name="action" value="account">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <div class="settings-field">
                            <label for="admin-username">Username</label>
                            <input type="text" id="admin-username" name="username" autocomplete="username" value="<?= h((string) $dirindexConfig['auth_username']) ?>" required>
                        </div>
                        <div class="settings-field">
                            <label for="admin-password">New password</label>
                            <input type="password" id="admin-password" name="password" autocomplete="new-password" minlength="8">
                            <span class="settings-help">Leave blank to keep the current password.</span>
                        </div>
                        <div class="settings-field">
                            <label for="admin-password-confirm">Confirm new password</label>
                            <input type="password" id="admin-password-confirm" name="password_confirm" autocomplete="new-password" minlength="8">
                        </div>
                        <button type="submit" class="btn-auth">Save account</button>
                    </form>
                </section>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($hasUploadCredentials && $authenticated && !$inShareMode): ?>
    <div id="create-entry-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal share-modal-panel" role="dialog" aria-modal="true" aria-labelledby="create-entry-title">
            <div class="modal-header">
                <span class="modal-title" id="create-entry-title">Create new entry</span>
                <button type="button" class="modal-close" id="create-entry-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <form class="settings-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" id="create-entry-form">
                    <input type="hidden" name="action" value="create_entry">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="entry_type" id="create-entry-type" value="folder">
                    <div class="settings-field">
                        <label>Location</label>
                        <div class="share-item-display" aria-live="polite">/<?= h($relativePath ?: '') ?></div>
                    </div>
                    <div class="settings-field">
                        <label for="create-entry-name">Name</label>
                        <input type="text" id="create-entry-name" name="entry_name" required autocomplete="off" spellcheck="false" placeholder="notes.txt">
                        <span class="settings-help" id="create-entry-help">Creates an empty folder in the current directory.</span>
                    </div>
                    <div class="share-form-footer">
                        <button type="button" class="btn-auth btn-auth-secondary" id="create-entry-cancel">Cancel</button>
                        <button type="submit" class="btn-auth" id="create-entry-submit">Create folder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($authenticated && !$inShareMode && $sharesAvailable): ?>
    <div id="shares-list-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal shares-list-panel" role="dialog" aria-modal="true" aria-labelledby="shares-list-title">
            <div class="modal-header">
                <span class="modal-title" id="shares-list-title">Shared links</span>
                <button type="button" class="modal-close" id="shares-list-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p class="settings-help">Public share links bypass IP access restrictions. Revoke links you no longer need.</p>
                <div id="shares-list-message" class="blocked-msg shares-list-message" hidden role="status"></div>
                <table id="shares-table" class="shares-table"<?php if (!$activeShares): ?> hidden<?php endif; ?>>
                    <thead>
                        <tr>
                            <th class="shares-col-path">Path</th>
                            <th class="shares-col-type">Type</th>
                            <th class="shares-col-expires">Expires</th>
                            <th class="shares-col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="shares-table-body">
                        <?php foreach ($activeShares as $shareRow): ?>
                        <?php
                                $shareRowUrl = shareUrl($indexHref, $shareRow['token'], [], true);
                            $shareExpires = $shareRow['expires_at'] === null ? 'Never' : (@date('Y-m-d H:i', $shareRow['expires_at']) ?: '—');
                            $sharePathDisplay = '/' . $shareRow['path'];
                        ?>
                        <tr>
                            <td class="shares-col-path" title="<?= h($sharePathDisplay) ?>"><span class="shares-cell-clip"><code><?= h($sharePathDisplay) ?></code></span></td>
                            <td class="shares-col-type"><span class="shares-cell-clip"><?= h($shareRow['type']) ?></span></td>
                            <td class="shares-col-expires"><span class="shares-cell-clip"><?= h($shareExpires) ?></span></td>
                            <td class="shares-col-actions">
                                <div class="share-actions">
                                    <a href="<?= h($shareRowUrl) ?>" class="btn-share-sm" target="_blank" rel="noopener noreferrer">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                                        <span>Open</span>
                                    </a>
                                    <button type="button" class="btn-share-sm btn-copy-share" data-share-url="<?= h($shareRowUrl) ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        <span class="btn-share-sm-label">Copy</span>
                                    </button>
                                    <form method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" class="share-revoke-form">
                                        <input type="hidden" name="action" value="share_revoke">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="share_token" value="<?= h($shareRow['token']) ?>">
                                        <button type="submit" class="btn-share-sm">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            <span>Revoke</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p id="shares-list-empty" class="settings-help"<?php if ($activeShares): ?> hidden<?php endif; ?>>No active share links. Use the share button on a file or folder in the listing.</p>
            </div>
        </div>
    </div>

    <div id="share-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal share-modal-panel" role="dialog" aria-modal="true" aria-labelledby="share-modal-title">
            <div class="modal-header">
                <span class="modal-title" id="share-modal-title">Create share link</span>
                <button type="button" class="modal-close" id="share-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <form class="settings-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" id="share-create-form">
                    <input type="hidden" name="action" value="share_create">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="share_path" id="share-path-input" value="">
                    <div class="settings-field">
                        <label>Sharing</label>
                        <div id="share-item-label" class="share-item-display" aria-live="polite"></div>
                    </div>
                    <div class="settings-field">
                        <label for="share-expires">Expires</label>
                        <select id="share-expires" name="expires">
                            <option value="never">Never</option>
                            <option value="1d">1 day</option>
                            <option value="7d">7 days</option>
                            <option value="30d">30 days</option>
                        </select>
                    </div>
                    <p class="settings-help share-form-note">Public read-only access; bypasses IP whitelist and blacklist.</p>
                    <div class="share-form-footer">
                        <button type="button" class="btn-auth btn-auth-secondary" id="share-modal-cancel">Cancel</button>
                        <button type="submit" class="btn-auth">Create link</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
        var toggle = document.getElementById('btn-upload-toggle');
        var panel = document.getElementById('upload-panel');
        if (!toggle || !panel) return;
        toggle.addEventListener('click', function() {
            var isOpen = panel.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.textContent = isOpen ? 'Hide upload' : 'Upload file';
        });
    })();

    (function() {
        var overlay = document.getElementById('create-entry-modal');
        var closeBtn = document.getElementById('create-entry-close');
        var cancelBtn = document.getElementById('create-entry-cancel');
        var folderBtn = document.getElementById('btn-create-folder');
        var fileBtn = document.getElementById('btn-create-file');
        var typeInput = document.getElementById('create-entry-type');
        var nameInput = document.getElementById('create-entry-name');
        var helpText = document.getElementById('create-entry-help');
        var submitBtn = document.getElementById('create-entry-submit');
        var title = document.getElementById('create-entry-title');
        if (!overlay || !typeInput || !nameInput) return;

        function setCreateType(type) {
            var isFolder = type === 'folder';
            typeInput.value = isFolder ? 'folder' : 'file';
            if (title) title.textContent = isFolder ? 'Create folder' : 'Create file';
            if (helpText) helpText.textContent = isFolder
                ? 'Creates an empty folder in the current directory.'
                : 'Creates an empty file in the current directory.';
            if (submitBtn) submitBtn.textContent = isFolder ? 'Create folder' : 'Create file';
            if (nameInput) nameInput.placeholder = isFolder ? 'newfolder' : 'notes.txt';
        }

        function openCreateModal(type) {
            setCreateType(type === 'file' ? 'file' : 'folder');
            nameInput.value = '';
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            nameInput.focus();
        }

        function closeCreateModal() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
        }

        if (folderBtn) folderBtn.addEventListener('click', function() { openCreateModal('folder'); });
        if (fileBtn) fileBtn.addEventListener('click', function() { openCreateModal('file'); });
        if (closeBtn) closeBtn.addEventListener('click', closeCreateModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeCreateModal);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeCreateModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
                closeCreateModal();
                e.stopPropagation();
            }
        });
    })();

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

        var listing = document.querySelector('.listing');
        if (!listing) return;
        var dragCounter = 0;

        function hasFileDrag(e) {
            var types = e.dataTransfer && e.dataTransfer.types;
            if (!types) return false;
            return Array.prototype.indexOf.call(types, 'Files') !== -1;
        }

        listing.addEventListener('dragenter', function(e) {
            if (!hasFileDrag(e)) return;
            e.preventDefault();
            dragCounter++;
            listing.classList.add('is-dragover');
        });
        listing.addEventListener('dragover', function(e) {
            if (!hasFileDrag(e)) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });
        listing.addEventListener('dragleave', function(e) {
            if (!hasFileDrag(e)) return;
            dragCounter--;
            if (dragCounter <= 0) {
                dragCounter = 0;
                listing.classList.remove('is-dragover');
            }
        });
        listing.addEventListener('drop', function(e) {
            e.preventDefault();
            dragCounter = 0;
            listing.classList.remove('is-dragover');
            if (!e.dataTransfer || !e.dataTransfer.files.length) return;
            var file = e.dataTransfer.files[0];
            if (!file || !fileInput) return;
            var dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
            overwriteInput.value = '';
            uploadForm.requestSubmit();
        });
    })();

    (function() {
        var loginOverlay = document.getElementById('login-modal');
        var btnLogin = document.getElementById('btn-login');
        var loginClose = document.getElementById('login-close');
        var usernameInput = document.getElementById('login-username');
        if (!loginOverlay || !btnLogin || !loginClose) return;

        function openLogin() {
            loginOverlay.classList.add('is-open');
            loginOverlay.setAttribute('aria-hidden', 'false');
            window.setTimeout(function() {
                if (usernameInput) usernameInput.focus();
            }, 0);
        }
        function closeLogin() {
            loginOverlay.classList.remove('is-open');
            loginOverlay.setAttribute('aria-hidden', 'true');
            btnLogin.focus();
        }

        btnLogin.addEventListener('click', openLogin);
        loginClose.addEventListener('click', closeLogin);
        loginOverlay.addEventListener('click', function(e) {
            if (e.target === loginOverlay) closeLogin();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && loginOverlay.classList.contains('is-open')) {
                closeLogin();
                e.stopPropagation();
            }
        });
        if (document.body.getAttribute('data-open-login') === '1') {
            openLogin();
        }
    })();

    (function() {
        var overlay = document.getElementById('file-modal');
        var modalPanel = document.getElementById('file-modal-panel');
        var titleEl = document.getElementById('modal-title');
        var openLinkEl = document.getElementById('modal-open-link');
        var codeEl = document.getElementById('modal-code');
        var modalPre = document.getElementById('modal-pre');
        var modalMd = document.getElementById('modal-md');
        var modalBinary = document.getElementById('modal-binary');
        var modalBinaryIcon = document.getElementById('modal-binary-icon');
        var modalBinaryName = document.getElementById('modal-binary-name');
        var modalBinaryMeta = document.getElementById('modal-binary-meta');
        var modalBinaryDownload = document.getElementById('modal-binary-download');
        var closeBtn = document.getElementById('modal-close');
        var shareBtn = document.getElementById('modal-share-btn');
        var currentSharePath = '';

        function sharePathFromContentUrl(contentUrl, fileName) {
            if (!contentUrl) return fileName || '';
            var pathMatch = contentUrl.match(/[?&]path=([^&]+)/);
            if (!pathMatch) return fileName || '';
            return decodeURIComponent(pathMatch[1].replace(/\+/g, ' '));
        }
        function setModalSharePath(path) {
            currentSharePath = path || '';
            if (!shareBtn) return;
            shareBtn.hidden = !currentSharePath;
            if (currentSharePath) shareBtn.setAttribute('data-share-path', currentSharePath);
            else shareBtn.removeAttribute('data-share-path');
        }

        function buildListingUrlWithOpen(contentUrl, fileName, sharePath) {
            var fullPath = sharePath || '';
            if (!fullPath && contentUrl) {
                var pathMatch = contentUrl.match(/[?&]path=([^&]+)/);
                fullPath = pathMatch ? decodeURIComponent(pathMatch[1].replace(/\+/g, ' ')) : '';
            }
            var lastSlash = fullPath.lastIndexOf('/');
            var dirPath = lastSlash >= 0 ? fullPath.slice(0, lastSlash) : '';
            var openParam = lastSlash >= 0 ? fullPath.slice(lastSlash + 1) : fullPath;
            if (openParam === '' && fileName) openParam = fileName;
            var base = contentUrl ? contentUrl.split('?')[0] : (window.location.pathname || '/index.php');
            var params = new URLSearchParams(window.location.search);
            var share = params.get('share');
            var q = new URLSearchParams();
            if (share) q.set('share', share);
            if (dirPath) q.set('path', dirPath);
            q.set('open', openParam);
            return base + '?' + q.toString();
        }
        function removeOpenFromUrl() {
            var u = new URL(window.location.href);
            if (u.searchParams.has('open')) {
                u.searchParams.delete('open');
                history.replaceState(null, '', u.pathname + u.search + (u.hash || ''));
            }
        }
        function hidePreviewPanels() {
            modalMd.innerHTML = '';
            modalMd.classList.remove('is-visible');
            modalMd.setAttribute('aria-hidden', 'true');
            modalPre.style.display = 'none';
            modalBinary.hidden = true;
            modalBinary.setAttribute('aria-hidden', 'true');
            if (modalPanel) modalPanel.classList.remove('is-binary');
        }

        function closeModal() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            hidePreviewPanels();
            modalPre.style.display = '';
            openLinkEl.style.display = 'none';
            openLinkEl.removeAttribute('href');
            setModalSharePath('');
            removeOpenFromUrl();
        }
        function openModal(name, content, lang, html, openUrl, sharePath) {
            hidePreviewPanels();
            titleEl.textContent = name;
            setModalSharePath(sharePath || '');
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
            } else {
                modalPre.style.display = '';
                codeEl.textContent = content;
                codeEl.className = 'language-' + (lang === 'markup' ? 'html' : lang);
                codeEl.parentElement.classList.add('hljs');
                hljs.highlightElement(codeEl);
            }
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
        }
        function openBinaryModal(name, size, mtime, iconHtml, downloadUrl, sharePath, pushStateUrl) {
            hidePreviewPanels();
            if (modalPanel) modalPanel.classList.add('is-binary');
            titleEl.textContent = name;
            setModalSharePath(sharePath || '');
            modalBinaryIcon.innerHTML = iconHtml || '';
            modalBinaryName.textContent = name;
            modalBinaryMeta.textContent = (size || '—') + ' · Modified ' + (mtime || '—');
            modalBinaryDownload.href = downloadUrl || '#';
            if (downloadUrl) {
                openLinkEl.href = downloadUrl;
                openLinkEl.style.display = '';
            } else {
                openLinkEl.style.display = 'none';
                openLinkEl.removeAttribute('href');
            }
            modalBinary.hidden = false;
            modalBinary.setAttribute('aria-hidden', 'false');
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            if (pushStateUrl !== undefined) history.pushState({ modal: true }, '', pushStateUrl);
        }

        function openModalFromContentUrl(contentUrl, name, openUrl, sharePath, pushStateUrl) {
            fetch(contentUrl).then(function(r) { return r.json(); }).then(function(data) {
                var path = sharePath || sharePathFromContentUrl(contentUrl, data.name || name);
                openModal(data.name || name, data.content || '', data.lang || 'plaintext', data.html || null, openUrl, path);
                if (pushStateUrl !== undefined) history.pushState({ modal: true }, '', pushStateUrl);
            }).catch(function() {
                if (pushStateUrl === undefined) window.location.href = contentUrl.split('&content')[0];
            });
        }

        document.addEventListener('click', function(e) {
            var previewLink = e.target.closest('a.file-preview');
            if (previewLink) {
                e.preventDefault();
                var contentUrl = previewLink.getAttribute('data-content-url');
                var name = previewLink.getAttribute('data-name') || '';
                if (!contentUrl) return;
                var openUrl = previewLink.getAttribute('data-open-url') || '';
                var sharePath = previewLink.getAttribute('data-share-path') || sharePathFromContentUrl(contentUrl, name);
                var listingUrl = buildListingUrlWithOpen(contentUrl, name, sharePath);
                openModalFromContentUrl(contentUrl, name, openUrl, sharePath, listingUrl);
                return;
            }
            var binaryLink = e.target.closest('a.file-binary');
            if (!binaryLink) return;
            e.preventDefault();
            var binaryName = binaryLink.getAttribute('data-name') || '';
            var downloadUrl = binaryLink.getAttribute('data-download-url') || '';
            if (!binaryName || !downloadUrl) return;
            var binarySharePath = binaryLink.getAttribute('data-share-path') || '';
            var listingUrl = buildListingUrlWithOpen('', binaryName, binarySharePath);
            openBinaryModal(
                binaryName,
                binaryLink.getAttribute('data-size') || '',
                binaryLink.getAttribute('data-mtime') || '',
                binaryLink.getAttribute('data-icon-html') || '',
                downloadUrl,
                binarySharePath,
                listingUrl
            );
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
        if (body.getAttribute('data-open-binary') === '1') {
            openBinaryModal(
                body.getAttribute('data-open-name') || '',
                body.getAttribute('data-open-size') || '',
                body.getAttribute('data-open-mtime') || '',
                body.getAttribute('data-open-icon-html') || '',
                body.getAttribute('data-open-download-url') || '',
                body.getAttribute('data-open-share-path') || ''
            );
        } else {
            var initialContentUrl = body.getAttribute('data-open-content-url');
            if (initialContentUrl) {
                var initialName = body.getAttribute('data-open-name') || '';
                var initialOpenUrl = body.getAttribute('data-open-url') || '';
                var initialSharePath = body.getAttribute('data-open-share-path') || sharePathFromContentUrl(initialContentUrl, initialName);
                openModalFromContentUrl(initialContentUrl, initialName, initialOpenUrl, initialSharePath);
            }
        }
    })();

    (function() {
        var table = document.getElementById('listing-table');
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var sortResetBtn = document.getElementById('listing-sort-reset');
        var STORAGE_SORT = 'dirindex_list_sort';
        var STORAGE_COL_SIZE = 'dirindex_col_size';
        var STORAGE_COL_MODIFIED = 'dirindex_col_modified';
        var STORAGE_COL_PERMS = 'dirindex_col_perms';

        function getSetting(key, def) {
            try { return localStorage.getItem(key) || def; } catch (e) { return def; }
        }
        function setSetting(key, val) {
            try { localStorage.setItem(key, val); } catch (e) {}
        }
        function isDefaultSort(sort) {
            return !sort || (sort.col === 'name' && sort.dir === 'asc');
        }
        function parseSort() {
            try {
                var raw = localStorage.getItem(STORAGE_SORT);
                if (!raw) return null;
                var parsed = JSON.parse(raw);
                if (!parsed || !parsed.col) return null;
                if (['name', 'size', 'modified', 'perms'].indexOf(parsed.col) === -1) return null;
                return { col: parsed.col, dir: parsed.dir === 'desc' ? 'desc' : 'asc' };
            } catch (e) {
                return null;
            }
        }
        function saveSort(sort) {
            if (isDefaultSort(sort)) {
                try { localStorage.removeItem(STORAGE_SORT); } catch (e) {}
                return;
            }
            setSetting(STORAGE_SORT, JSON.stringify(sort));
        }
        function getSortRows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('tr:not([data-sort-parent])'));
        }
        function getParentRow() {
            return tbody.querySelector('tr[data-sort-parent]');
        }
        function compareRows(a, b, col, dir) {
            var mul = dir === 'desc' ? -1 : 1;
            var aDir = a.getAttribute('data-is-dir') === '1';
            var bDir = b.getAttribute('data-is-dir') === '1';
            if (aDir !== bDir) return aDir ? -1 : 1;
            var va;
            var vb;
            if (col === 'name') {
                va = (a.getAttribute('data-sort-name') || '').toLowerCase();
                vb = (b.getAttribute('data-sort-name') || '').toLowerCase();
            } else if (col === 'size') {
                va = parseInt(a.getAttribute('data-sort-size') || '-1', 10);
                vb = parseInt(b.getAttribute('data-sort-size') || '-1', 10);
            } else if (col === 'modified') {
                va = parseInt(a.getAttribute('data-sort-mtime') || '0', 10);
                vb = parseInt(b.getAttribute('data-sort-mtime') || '0', 10);
            } else {
                va = parseInt(a.getAttribute('data-sort-perms') || '0', 10);
                vb = parseInt(b.getAttribute('data-sort-perms') || '0', 10);
            }
            if (va < vb) return -1 * mul;
            if (va > vb) return 1 * mul;
            var na = (a.getAttribute('data-sort-name') || '').toLowerCase();
            var nb = (b.getAttribute('data-sort-name') || '').toLowerCase();
            if (na < nb) return -1;
            if (na > nb) return 1;
            return 0;
        }
        function updateSortUi(sort) {
            var showReset = !isDefaultSort(sort);
            if (sortResetBtn) sortResetBtn.hidden = !showReset;
            table.querySelectorAll('th[data-sort-col]').forEach(function(th) {
                var col = th.getAttribute('data-sort-col');
                var active = sort && sort.col === col;
                th.setAttribute('aria-sort', active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none');
                var ind = th.querySelector('.listing-sort-indicator');
                if (ind) ind.textContent = active ? (sort.dir === 'asc' ? '\u25B2' : '\u25BC') : '';
            });
        }
        function applySort(sort) {
            var col = sort ? sort.col : 'name';
            var dir = sort ? sort.dir : 'asc';
            var rows = getSortRows();
            var parent = getParentRow();
            rows.sort(function(a, b) { return compareRows(a, b, col, dir); });
            rows.forEach(function(row) { tbody.appendChild(row); });
            if (parent) tbody.insertBefore(parent, tbody.firstChild);
            updateSortUi(isDefaultSort(sort) ? null : sort);
        }
        function applyColumns() {
            var showSize = getSetting(STORAGE_COL_SIZE, '1') !== '0';
            var showModified = getSetting(STORAGE_COL_MODIFIED, '1') !== '0';
            var showPerms = getSetting(STORAGE_COL_PERMS, '1') !== '0';
            table.classList.toggle('listing-hide-size', !showSize);
            table.classList.toggle('listing-hide-modified', !showModified);
            table.classList.toggle('listing-hide-perms', !showPerms);
            var sizeCheck = document.getElementById('setting-col-size');
            var modCheck = document.getElementById('setting-col-modified');
            var permsCheck = document.getElementById('setting-col-perms');
            if (sizeCheck) {
                sizeCheck.checked = showSize;
                sizeCheck.setAttribute('aria-checked', showSize ? 'true' : 'false');
            }
            if (modCheck) {
                modCheck.checked = showModified;
                modCheck.setAttribute('aria-checked', showModified ? 'true' : 'false');
            }
            if (permsCheck) {
                permsCheck.checked = showPerms;
                permsCheck.setAttribute('aria-checked', showPerms ? 'true' : 'false');
            }
        }
        function wireColumnCheckbox(check, storageKey) {
            if (!check) return;
            check.addEventListener('change', function() {
                setSetting(storageKey, check.checked ? '1' : '0');
                applyColumns();
            });
        }
        function wireColumnPicker() {
            var pickerBtn = document.getElementById('listing-col-picker-btn');
            var pickerMenu = document.getElementById('listing-col-picker-menu');
            if (!pickerBtn || !pickerMenu) return;
            function closePicker() {
                pickerMenu.hidden = true;
                pickerBtn.setAttribute('aria-expanded', 'false');
            }
            function openPicker() {
                pickerMenu.hidden = false;
                pickerBtn.setAttribute('aria-expanded', 'true');
            }
            pickerBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (pickerMenu.hidden) openPicker();
                else closePicker();
            });
            pickerMenu.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            document.addEventListener('click', closePicker);
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closePicker();
            });
        }

        table.querySelectorAll('.listing-sort-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var col = btn.getAttribute('data-sort-col');
                var current = parseSort();
                var dir = 'asc';
                if (current && current.col === col) {
                    dir = current.dir === 'asc' ? 'desc' : 'asc';
                }
                var next = { col: col, dir: dir };
                saveSort(next);
                applySort(next);
            });
        });
        if (sortResetBtn) {
            sortResetBtn.addEventListener('click', function() {
                saveSort(null);
                applySort(null);
            });
        }
        wireColumnCheckbox(document.getElementById('setting-col-size'), STORAGE_COL_SIZE);
        wireColumnCheckbox(document.getElementById('setting-col-modified'), STORAGE_COL_MODIFIED);
        wireColumnCheckbox(document.getElementById('setting-col-perms'), STORAGE_COL_PERMS);
        wireColumnPicker();

        applyColumns();
        var initialSort = parseSort();
        if (initialSort) applySort(initialSort);
        else updateSortUi(null);
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

        var btnAddCurrentIp = document.getElementById('btn-add-current-ip');
        var ipWhitelistField = document.getElementById('admin-ip-whitelist');
        var detectedClientIp = document.getElementById('detected-client-ip');
        if (btnAddCurrentIp && ipWhitelistField && detectedClientIp) {
            btnAddCurrentIp.addEventListener('click', function() {
                var ip = detectedClientIp.textContent.trim();
                if (!ip) return;
                var lines = ipWhitelistField.value.split(/\r?\n/).map(function(line) { return line.trim(); }).filter(Boolean);
                if (lines.indexOf(ip) === -1) {
                    lines.push(ip);
                }
                ipWhitelistField.value = lines.join('\n');
                ipWhitelistField.focus();
            });
        }

        loadAndApply();
    })();

    (function() {
        function copyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            return Promise.resolve();
        }

        var copyCreated = document.getElementById('btn-copy-share-created');
        var createdInput = document.getElementById('share-created-url');
        if (copyCreated && createdInput) {
            copyCreated.addEventListener('click', function() {
                copyText(createdInput.value).then(function() {
                    copyCreated.textContent = 'Copied';
                    setTimeout(function() { copyCreated.textContent = 'Copy'; }, 2000);
                });
            });
        }

        document.querySelectorAll('.btn-copy-share').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var url = btn.getAttribute('data-share-url');
                if (!url) return;
                var label = btn.querySelector('.btn-share-sm-label');
                copyText(url).then(function() {
                    if (!label) return;
                    var prev = label.textContent;
                    label.textContent = 'Copied';
                    setTimeout(function() { label.textContent = prev; }, 2000);
                });
            });
        });

        var sharesListOverlay = document.getElementById('shares-list-modal');
        var btnShares = document.getElementById('btn-shares');
        var sharesListClose = document.getElementById('shares-list-close');
        var sharesListMessage = document.getElementById('shares-list-message');
        var sharesTable = document.getElementById('shares-table');
        var sharesTableBody = document.getElementById('shares-table-body');
        var sharesListEmpty = document.getElementById('shares-list-empty');

        function showSharesListMessage(text, type) {
            if (!sharesListMessage) return;
            sharesListMessage.textContent = text;
            sharesListMessage.className = 'blocked-msg shares-list-message' + (type === 'success' ? ' message-success' : '');
            sharesListMessage.hidden = false;
        }
        function clearSharesListMessage() {
            if (!sharesListMessage) return;
            sharesListMessage.hidden = true;
            sharesListMessage.textContent = '';
        }
        function updateSharesListEmptyState() {
            if (!sharesTableBody) return;
            var hasRows = sharesTableBody.querySelector('tr');
            if (sharesTable) sharesTable.hidden = !hasRows;
            if (sharesListEmpty) sharesListEmpty.hidden = !!hasRows;
        }

        function openSharesList() {
            if (!sharesListOverlay) return;
            clearSharesListMessage();
            sharesListOverlay.classList.add('is-open');
            sharesListOverlay.setAttribute('aria-hidden', 'false');
        }
        function closeSharesList() {
            if (!sharesListOverlay) return;
            sharesListOverlay.classList.remove('is-open');
            sharesListOverlay.setAttribute('aria-hidden', 'true');
        }

        if (btnShares) btnShares.addEventListener('click', openSharesList);
        if (sharesListClose) sharesListClose.addEventListener('click', closeSharesList);
        if (sharesListOverlay) {
            sharesListOverlay.addEventListener('click', function(e) {
                if (e.target === sharesListOverlay) closeSharesList();
            });
            sharesListOverlay.addEventListener('submit', function(e) {
                var form = e.target.closest('.share-revoke-form');
                if (!form) return;
                e.preventDefault();
                var submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;
                var body = new FormData(form);
                body.append('ajax', '1');
                fetch(form.action, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.ok) {
                            var row = form.closest('tr');
                            if (row) row.remove();
                            updateSharesListEmptyState();
                            showSharesListMessage(data.message || 'Share link revoked.', 'success');
                        } else {
                            showSharesListMessage(data.message || 'Could not revoke the share link.');
                        }
                    })
                    .catch(function() {
                        showSharesListMessage('Could not revoke the share link.');
                    })
                    .finally(function() {
                        if (submitBtn) submitBtn.disabled = false;
                    });
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sharesListOverlay && sharesListOverlay.classList.contains('is-open')) {
                closeSharesList();
                e.stopPropagation();
            }
        });

        var shareOverlay = document.getElementById('share-modal');
        var shareClose = document.getElementById('share-modal-close');
        var shareCancel = document.getElementById('share-modal-cancel');
        var sharePathInput = document.getElementById('share-path-input');
        var shareItemLabel = document.getElementById('share-item-label');

        function openShareModal(path, type) {
            if (!shareOverlay || !sharePathInput || !shareItemLabel) return;
            sharePathInput.value = path;
            var typeLabel = type === 'dir' ? 'folder' : 'file';
            shareItemLabel.textContent = '';
            shareItemLabel.appendChild(document.createTextNode('/' + path + ' '));
            var typeSpan = document.createElement('span');
            typeSpan.className = 'share-item-type';
            typeSpan.textContent = '(' + typeLabel + ')';
            shareItemLabel.appendChild(typeSpan);
            shareOverlay.classList.add('is-open');
            shareOverlay.setAttribute('aria-hidden', 'false');
        }
        function closeShareModal() {
            if (!shareOverlay) return;
            shareOverlay.classList.remove('is-open');
            shareOverlay.setAttribute('aria-hidden', 'true');
        }

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-share, #modal-share-btn');
            if (!btn) return;
            e.preventDefault();
            openShareModal(btn.getAttribute('data-share-path') || '', btn.getAttribute('data-share-type') || 'file');
        });

        if (shareClose) shareClose.addEventListener('click', closeShareModal);
        if (shareCancel) shareCancel.addEventListener('click', closeShareModal);
        if (shareOverlay) {
            shareOverlay.addEventListener('click', function(e) {
                if (e.target === shareOverlay) closeShareModal();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && shareOverlay && shareOverlay.classList.contains('is-open')) {
                closeShareModal();
            }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
