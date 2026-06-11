<?php
/**
 * Simple dark-mode directory index.
 * Place in any folder and open in browser (requires PHP).
 */

header('X-Content-Type-Options: nosniff');

/** Semver; updated by scripts/release.sh when tagging a release. */
$dirindexVersion = '1.2.5';
/** Short git ref; embedded in rolling dev release artifacts by scripts/dev-release.sh. */
$dirindexBuildRef = '';
$dirindexRepoUrl = 'https://github.com/Darknetzz/php-dirindex';
$dirindexBuildLabel = (basename(__FILE__) === 'index.min.php') ? 'Minified' : 'Standard';

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

/**
 * Parse newline- or comma-separated path access rules from settings input.
 */
function parsePathAccessListInput($text) {
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

function formatPathAccessListForInput(array $list) {
    return implode("\n", array_map('strval', $list));
}

/**
 * Parse newline- or comma-separated file extensions from preview blocklist settings input.
 */
function parsePreviewBlocklistInput($text) {
    $text = str_replace([',', ';'], "\n", (string) $text);
    $entries = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $entry = trim(strtolower(ltrim(trim($line), '.')));
        if ($entry !== '' && !str_starts_with($entry, '#')) {
            $entries[] = $entry;
        }
    }
    return array_values(array_unique($entries));
}

function formatPreviewBlocklistForInput(array $list) {
    return implode("\n", array_map('strval', $list));
}

function normalizePreviewBlocklist($list) {
    if (!is_array($list)) {
        return [];
    }
    $normalized = [];
    foreach ($list as $entry) {
        $entry = trim(strtolower(ltrim(trim((string) $entry), '.')));
        if ($entry !== '') {
            $normalized[] = $entry;
        }
    }
    return array_values(array_unique($normalized));
}

function validatePreviewBlocklist(array $entries, &$invalidEntry = null) {
    foreach ($entries as $entry) {
        if (!preg_match('/^[a-z0-9]{1,16}$/', $entry)) {
            $invalidEntry = $entry;
            return false;
        }
    }
    return true;
}

/**
 * True when a relative path contains traversal segments (.. or .) or empty segments.
 */
function relativePathHasTraversal($path) {
    if ($path === '' || str_contains((string) $path, "\0")) {
        return false;
    }
    $normalized = trim(str_replace('\\', '/', (string) $path), '/');
    if ($normalized === '') {
        return false;
    }
    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return true;
        }
    }
    return false;
}

/**
 * Whether a single path segment is safe to reference (e.g. ?open=); permissive for existing files.
 */
function isSafeEntryName($name) {
    $name = trim((string) $name);
    if ($name === '' || $name === '.' || $name === '..' || str_contains($name, "\0")) {
        return false;
    }
    if (str_contains($name, '/') || str_contains($name, '\\')) {
        return false;
    }
    return true;
}

function entryNameMaxLength() {
    return 255;
}

function entryNameHasControlChars($name) {
    return (bool) preg_match('/[\x00-\x1F\x7F]/', (string) $name);
}

function entryNameHasForbiddenChars($name) {
    return (bool) preg_match('/[\/\\\\:*?"<>|]/', (string) $name);
}

function entryNameHasInvalidEdges($name) {
    $name = (string) $name;
    return $name !== rtrim($name, ' ') || $name !== rtrim($name, '.');
}

function isWindowsReservedEntryName($name) {
    $base = (string) $name;
    $dot = strrpos($base, '.');
    if ($dot !== false) {
        $base = substr($base, 0, $dot);
    }
    $base = strtoupper(rtrim($base, '. '));
    static $reserved = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];
    return in_array($base, $reserved, true);
}

/**
 * Safe-general rules for new uploads and created files/folders.
 */
function isAllowedEntryName($name) {
    $name = trim((string) $name);
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }
    if (str_contains($name, "\0")) {
        return false;
    }
    if (entryNameHasForbiddenChars($name)) {
        return false;
    }
    if (entryNameHasControlChars($name)) {
        return false;
    }
    if (entryNameHasInvalidEdges($name)) {
        return false;
    }
    if (strlen($name) > entryNameMaxLength()) {
        return false;
    }
    if (isWindowsReservedEntryName($name)) {
        return false;
    }
    return true;
}

function suggestSafeEntryName($name) {
    $original = trim((string) $name);
    $ext = '';
    if (preg_match('/(\.[A-Za-z0-9]{1,16})$/', $original, $matches)) {
        $ext = $matches[1];
    }
    $base = $ext !== '' ? substr($original, 0, -strlen($ext)) : $original;
    $base = preg_replace('/[\x00-\x1F\x7F]/', '', $base);
    $base = preg_replace('/[\/\\\\:*?"<>|]/', '-', $base);
    $base = preg_replace('/-+/', '-', $base);
    $base = preg_replace('/\s+/', ' ', $base);
    $base = trim($base, ". \t-");
    if ($base === '' || $base === '.' || $base === '..') {
        $base = 'upload';
    }
    $candidate = $base . $ext;
    $maxBaseLen = entryNameMaxLength() - strlen($ext);
    if ($maxBaseLen < 1) {
        $candidate = substr($base, 0, entryNameMaxLength());
    } elseif (strlen($base) > $maxBaseLen) {
        $base = rtrim(substr($base, 0, $maxBaseLen), ". \t-");
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'upload';
        }
        $candidate = $base . $ext;
    }
    if (isWindowsReservedEntryName($candidate)) {
        $prefix = 'file-';
        $base = $prefix . $base;
        if (strlen($base . $ext) > entryNameMaxLength()) {
            $base = rtrim(substr($base, 0, max(1, entryNameMaxLength() - strlen($ext) - strlen($prefix))), ". \t-");
        }
        $candidate = $base . $ext;
    }
    if (!isAllowedEntryName($candidate)) {
        $candidate = 'upload' . $ext;
    }
    return $candidate;
}

function normalizeRelativePathForAccess($path) {
    $path = trim(str_replace('\\', '/', (string) $path), '/');
    if ($path === '' || relativePathHasTraversal($path) || str_contains($path, "\0")) {
        return null;
    }
    return $path;
}

function normalizePathAccessRule($entry) {
    $entry = trim(str_replace('\\', '/', (string) $entry));
    $entry = trim($entry, '/');
    if ($entry === '' || str_contains($entry, "\0")) {
        return null;
    }
    if (relativePathHasTraversal($entry)) {
        return null;
    }
    foreach (explode('/', $entry) as $segment) {
        if ($segment === '..' || $segment === '') {
            return null;
        }
    }
    return $entry;
}

function pathAccessRuleHasWildcard($entry) {
    return strpbrk((string) $entry, '*?[') !== false;
}

function pathAccessRuleStaticPrefix($entry) {
    $entry = trim(str_replace('\\', '/', (string) $entry), '/');
    if ($entry === '') {
        return '';
    }
    $pos = strcspn($entry, '*?[');
    return rtrim(substr($entry, 0, $pos), '/');
}

function pathGlobToRegex($pattern) {
    $pattern = str_replace('\\', '/', (string) $pattern);
    $len = strlen($pattern);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $pattern[$i];
        if ($c === '*' && $i + 1 < $len && $pattern[$i + 1] === '*') {
            $out .= '.*';
            $i++;
            if ($i + 1 < $len && $pattern[$i + 1] === '/') {
                $i++;
            }
        } elseif ($c === '*') {
            $out .= '[^/]*';
        } elseif ($c === '?') {
            $out .= '[^/]';
        } elseif ($c === '[') {
            $end = strpos($pattern, ']', $i + 1);
            if ($end === false) {
                $out .= preg_quote($c, '/');
            } else {
                $out .= substr($pattern, $i, $end - $i + 1);
                $i = $end;
            }
        } else {
            $out .= preg_quote($c, '/');
        }
    }
    return $out;
}

function pathGlobMatchString($pattern, $string) {
    $pattern = (string) $pattern;
    $string = (string) $string;
    if ($pattern === '') {
        return false;
    }
    if (!pathAccessRuleHasWildcard($pattern)) {
        return $string === $pattern;
    }
    if (str_contains($pattern, '**')) {
        $regex = '/^' . pathGlobToRegex($pattern) . '$/D';
        if (defined('FNM_CASEFOLD')) {
            $regex = '/^' . pathGlobToRegex($pattern) . '$/Di';
        }
        return (bool) preg_match($regex, $string);
    }
    if (function_exists('fnmatch')) {
        $flags = FNM_PATHNAME;
        if (defined('FNM_CASEFOLD')) {
            $flags |= FNM_CASEFOLD;
        }
        return fnmatch($pattern, $string, $flags);
    }
    $regex = '/^' . pathGlobToRegex($pattern) . '$/D';
    if (defined('FNM_CASEFOLD')) {
        $regex = '/^' . pathGlobToRegex($pattern) . '$/Di';
    }
    return (bool) preg_match($regex, $string);
}

function isValidPathAccessEntry($entry) {
    return normalizePathAccessRule($entry) !== null;
}

function validatePathAccessList(array $entries, &$invalidEntry = null) {
    foreach ($entries as $entry) {
        if (!isValidPathAccessEntry($entry)) {
            $invalidEntry = $entry;
            return false;
        }
    }
    return true;
}

/**
 * Whether a relative path (from index root) matches a path access rule.
 * Rules without a slash match any path segment or basename; rules with a slash
 * match that path and anything beneath it. Wildcards (*, ?, [...], **) are supported.
 */
function pathMatchesAccessRule($relativePath, $entry) {
    $relativePath = normalizeRelativePathForAccess($relativePath);
    $entryRaw = trim(str_replace('\\', '/', (string) $entry));
    if ($relativePath === null || $entryRaw === '') {
        return false;
    }
    $rootPathRule = str_contains($entryRaw, '/') || str_ends_with($entryRaw, '/');
    $rule = normalizePathAccessRule($entry);
    if ($rule === null) {
        return false;
    }
    if (pathAccessRuleHasWildcard($entryRaw)) {
        if ($rootPathRule) {
            return pathGlobMatchString($rule, $relativePath);
        }
        if (pathGlobMatchString($entryRaw, basename($relativePath))) {
            return true;
        }
        foreach (explode('/', $relativePath) as $segment) {
            if (pathGlobMatchString($entryRaw, $segment)) {
                return true;
            }
        }
        return false;
    }
    if ($rootPathRule) {
        return $relativePath === $rule || str_starts_with($relativePath, $rule . '/');
    }
    if (basename($relativePath) === $rule) {
        return true;
    }
    foreach (explode('/', $relativePath) as $segment) {
        if ($segment === $rule) {
            return true;
        }
    }
    return false;
}

function pathMatchesAccessList($relativePath, array $entries) {
    $relativePath = normalizeRelativePathForAccess($relativePath);
    if ($relativePath === null) {
        return false;
    }
    foreach ($entries as $entry) {
        if (pathMatchesAccessRule($relativePath, $entry)) {
            return true;
        }
    }
    return false;
}

function pathAllowedByWhitelist($relativePath, array $pathWhitelist) {
    $trimmed = trim(str_replace('\\', '/', (string) $relativePath), '/');
    if ($trimmed === '' || relativePathHasTraversal($trimmed) || str_contains($trimmed, "\0")) {
        return $trimmed === '' && $pathWhitelist !== [];
    }
    if (pathMatchesAccessList($relativePath, $pathWhitelist)) {
        return true;
    }
    foreach ($pathWhitelist as $entry) {
        $rule = normalizePathAccessRule($entry);
        if ($rule === null) {
            continue;
        }
        $entryRaw = trim(str_replace('\\', '/', (string) $entry));
        $rootPathRule = str_contains($entryRaw, '/') || str_ends_with($entryRaw, '/');
        if (!$rootPathRule) {
            continue;
        }
        if (pathAccessRuleHasWildcard($entryRaw)) {
            $staticPrefix = pathAccessRuleStaticPrefix($entryRaw);
            if ($staticPrefix !== '' && ($trimmed === $staticPrefix || str_starts_with($staticPrefix, $trimmed . '/'))) {
                return true;
            }
            continue;
        }
        if (str_starts_with($rule, $trimmed . '/')) {
            return true;
        }
    }
    return false;
}

function isPathAccessDenied($relativePath, array $pathWhitelist, array $pathBlacklist, $inShareMode = false) {
    if ($inShareMode) {
        return false;
    }
    if (pathMatchesAccessList($relativePath, $pathBlacklist)) {
        return true;
    }
    if ($pathWhitelist !== [] && !pathAllowedByWhitelist($relativePath, $pathWhitelist)) {
        return true;
    }
    return false;
}

function denyPathAccessIfNeeded($relativePath, array $pathWhitelist, array $pathBlacklist, $inShareMode = false) {
    if (!isPathAccessDenied($relativePath, $pathWhitelist, $pathBlacklist, $inShareMode)) {
        return false;
    }
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found.');
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
 * Resolve the directory used as the listing root.
 * Default: folder containing this script. Optional legacy mode uses DOCUMENT_ROOT heuristics.
 */
function resolveListingBaseDir($scriptDir, $fromDocumentRoot) {
    $baseDir = $scriptDir;
    if (!$fromDocumentRoot || empty($_SERVER['DOCUMENT_ROOT'])) {
        return $baseDir;
    }
    $docRootReal = realpath($_SERVER['DOCUMENT_ROOT']);
    $scriptDirReal = realpath($scriptDir);
    if (!$docRootReal || !$scriptDirReal) {
        return $baseDir;
    }
    $scriptInDocRoot = ($scriptDirReal === $docRootReal || strpos($scriptDirReal, $docRootReal . DIRECTORY_SEPARATOR) === 0);
    if (!$scriptInDocRoot) {
        // Script is symlinked from doc root: list doc root's parent
        $parent = dirname($docRootReal);
        if ($parent && $parent !== $docRootReal) {
            return $parent;
        }
        return $baseDir;
    }
    if ($scriptDirReal === $docRootReal) {
        // Script is the doc root index: list doc root's parent
        $parent = dirname($docRootReal);
        if ($parent && $parent !== $docRootReal) {
            return $parent;
        }
        return $baseDir;
    }
    return $docRootReal;
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

function isBrokenSymbolicLink($path) {
    if (!is_link($path)) {
        return false;
    }
    return !is_file($path) && !is_dir($path);
}

function metaRequestAllowed($requestedPath, $requestedReal, $realBase, $allowOutside) {
    if (!is_file($requestedPath) && !is_link($requestedPath)) {
        return false;
    }
    if ($allowOutside) {
        return true;
    }
    if ($requestedReal !== false) {
        return pathUnderBase($requestedReal, $realBase);
    }
    return pathLogicalUnderBase($requestedPath, $realBase);
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

function deleteDirectoryRecursive($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    $items = @scandir($dir);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_link($path)) {
            if (!@unlink($path)) {
                return false;
            }
            continue;
        }
        if (is_dir($path)) {
            if (!deleteDirectoryRecursive($path)) {
                return false;
            }
            continue;
        }
        if (!@unlink($path)) {
            return false;
        }
    }
    return @rmdir($dir);
}

function deleteFilesystemEntry($absolutePath) {
    if (is_link($absolutePath)) {
        return @unlink($absolutePath);
    }
    if (is_dir($absolutePath)) {
        return deleteDirectoryRecursive($absolutePath);
    }
    if (is_file($absolutePath)) {
        return @unlink($absolutePath);
    }
    return false;
}

function resolveDeletableEntry($relativePath, $baseDir, $realBase, $allowOutside, array $pathWhitelist, array $pathBlacklist, $inShareMode = false) {
    $relativePath = trim(str_replace('\\', '/', (string) $relativePath), '/');
    if ($relativePath === '' || relativePathHasTraversal($relativePath) || str_contains($relativePath, "\0")) {
        return ['ok' => false, 'error' => 'delete_invalid'];
    }
    if (isPathAccessDenied($relativePath, $pathWhitelist, $pathBlacklist, $inShareMode)) {
        return ['ok' => false, 'error' => 'path_denied'];
    }
    if (dirindexIsHiddenListingEntry(basename($relativePath))) {
        return ['ok' => false, 'error' => 'delete_blocked'];
    }
    $absolutePath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!file_exists($absolutePath) && !is_link($absolutePath)) {
        return ['ok' => false, 'error' => 'delete_missing'];
    }
    if (!pathLogicalUnderBase($absolutePath, $realBase)) {
        return ['ok' => false, 'error' => 'delete_blocked'];
    }
    if (is_link($absolutePath)) {
        $resolved = realpath($absolutePath);
        if ($resolved === false) {
            return ['ok' => true, 'absolute' => $absolutePath, 'is_dir' => false];
        }
        if (!$allowOutside && !pathUnderBase($resolved, $realBase)) {
            return ['ok' => false, 'error' => 'delete_outside'];
        }
    } elseif (!$allowOutside) {
        $resolved = realpath($absolutePath);
        if ($resolved !== false && !pathUnderBase($resolved, $realBase)) {
            return ['ok' => false, 'error' => 'delete_outside'];
        }
    }
    $parentDir = dirname($absolutePath);
    if (!is_dir($parentDir) || !is_writable($parentDir)) {
        return ['ok' => false, 'error' => 'delete_not_writable'];
    }
    return [
        'ok'       => true,
        'absolute' => $absolutePath,
        'is_dir'   => is_dir($absolutePath) && !is_link($absolutePath),
    ];
}

function revokeSharesForDeletedPath($pdo, $relativePath) {
    $relativePath = trim(str_replace('\\', '/', (string) $relativePath), '/');
    if ($relativePath === '') {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM shares WHERE path = ? OR path LIKE ?');
    $stmt->execute([$relativePath, $relativePath . '/%']);
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
        'create_enabled',
        'delete_enabled',
        'auth_username',
        'auth_password_hash',
        'upload_max_bytes',
        'path_whitelist',
        'path_blacklist',
        'web_root_url',
        'listing_from_document_root',
    ];
}

function dirindexArraySettingKeys() {
    return ['ip_whitelist', 'ip_blacklist', 'path_whitelist', 'path_blacklist', 'preview_blocklist'];
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
        if (in_array($key, ['show_symlinks', 'allow_open_symlinks_outside', 'upload_enabled', 'create_enabled', 'delete_enabled', 'listing_from_document_root', 'browse_requires_auth', 'image_preview_enabled', 'markdown_preview_enabled', 'hash_sha256_sha512_enabled'], true)) {
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

function dirindexDeleteStorage($scriptDir, &$error = null) {
    if (!is_dir($scriptDir)) {
        $error = 'Storage directory does not exist.';
        return false;
    }
    if (!is_writable($scriptDir)) {
        $error = 'PHP cannot write to the directory containing settings storage.';
        return false;
    }
    $paths = [
        dirindexSqlitePath($scriptDir),
        dirindexJsonPath($scriptDir),
    ];
    foreach (glob($scriptDir . DIRECTORY_SEPARATOR . '.dirindex.sqlite-*') ?: [] as $sidecar) {
        $paths[] = $sidecar;
    }
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        if (!@unlink($path)) {
            $error = 'Could not delete ' . basename($path) . '.';
            return false;
        }
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

function serveSharedFileBytes($absolutePath, $displayName, $inline = false) {
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
    $safeName = str_replace(['"', "\r", "\n"], '', $displayName);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
    header('Content-Length: ' . (string) filesize($absolutePath));
    readfile($absolutePath);
    exit;
}

function serveSharedFileDownload($absolutePath, $displayName) {
    serveSharedFileBytes($absolutePath, $displayName, false);
}

function serveSharedFileInline($absolutePath, $displayName) {
    serveSharedFileBytes($absolutePath, $displayName, true);
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

function parseSemverTag($tag) {
    $tag = ltrim(trim((string) $tag), 'vV');
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $tag, $m)) {
        return null;
    }
    return [(int) $m[1], (int) $m[2], (int) $m[3]];
}

function compareSemverTags($a, $b) {
    $pa = parseSemverTag($a);
    $pb = parseSemverTag($b);
    if ($pa === null || $pb === null) {
        return 0;
    }
    foreach ([0, 1, 2] as $i) {
        if ($pa[$i] !== $pb[$i]) {
            return $pa[$i] <=> $pb[$i];
        }
    }
    return 0;
}

function dirindexHttpRequest($url, $accept = '*/*', $timeoutSec = 15) {
    $userAgent = 'php-dirindex/' . ($GLOBALS['dirindexVersion'] ?? '0');
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['User-Agent: ' . $userAgent, 'Accept: ' . $accept],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [
            'status' => $code,
            'body' => ($body === false) ? null : $body,
        ];
    }
    if (!ini_get('allow_url_fopen')) {
        return ['status' => 0, 'body' => null];
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeoutSec,
            'header' => "User-Agent: {$userAgent}\r\nAccept: {$accept}\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    return [
        'status' => $status,
        'body' => ($body === false) ? null : $body,
    ];
}

function dirindexHttpGet($url, $accept = '*/*', $timeoutSec = 15) {
    $response = dirindexHttpRequest($url, $accept, $timeoutSec);
    $status = (int) ($response['status'] ?? 0);
    $body = $response['body'] ?? null;
    if ($body === null || $status < 200 || $status >= 300) {
        return null;
    }
    return $body;
}

function dirindexUpdateArtifactName() {
    $name = basename(__FILE__);
    return ($name === 'index.php' || $name === 'index.min.php') ? $name : 'index.php';
}

function dirindexGitHubRepoApiBase($repoUrl) {
    if (!preg_match('#github\.com/([^/]+)/([^/]+)#i', (string) $repoUrl, $m)) {
        return null;
    }
    return 'https://api.github.com/repos/' . $m[1] . '/' . preg_replace('/\.git$/', '', $m[2]);
}

function dirindexNormalizeUpdateChannel($channel) {
    return ($channel === 'dev') ? 'dev' : 'stable';
}

function dirindexParseDevBuildRef($releaseBody) {
    if (preg_match('/\*\*Build:\*\*\s*([a-f0-9]{7,40})/i', (string) $releaseBody, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

function dirindexParseDevVersion($releaseBody) {
    if (preg_match('/\*\*Version:\*\*\s*([0-9]+\.[0-9]+\.[0-9]+)/i', (string) $releaseBody, $m)) {
        return $m[1];
    }
    return null;
}

function dirindexBuildRefFromPhpSource($content) {
    if (preg_match('/\$dirindexBuildRef\s*=\s*[\'"]([a-f0-9]+)[\'"];/i', (string) $content, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

function dirindexFetchRelease($repoUrl, $channel = 'stable') {
    $channel = dirindexNormalizeUpdateChannel($channel);
    $apiBase = dirindexGitHubRepoApiBase($repoUrl);
    if ($apiBase === null) {
        return ['error' => 'Could not determine the GitHub repository.'];
    }
    $endpoint = ($channel === 'dev')
        ? $apiBase . '/releases/tags/dev'
        : $apiBase . '/releases/latest';
    $response = dirindexHttpRequest($endpoint, 'application/vnd.github+json');
    $status = (int) ($response['status'] ?? 0);
    $body = $response['body'] ?? null;
    if ($body === null) {
        if ($channel === 'dev' && $status === 404) {
            return ['error' => 'No dev release found yet. Push to the dev branch on GitHub to publish one.'];
        }
        return ['error' => 'Could not reach GitHub. Check outbound network access and try again.'];
    }
    if ($status < 200 || $status >= 300) {
        if ($channel === 'dev' && $status === 404) {
            return ['error' => 'No dev release found yet. Push to the dev branch on GitHub to publish one.'];
        }
        return ['error' => 'Could not reach GitHub. Check outbound network access and try again.'];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        $message = ($channel === 'dev')
            ? 'Could not read the dev release from GitHub. Push to the dev branch to publish one.'
            : 'Could not read the latest release from GitHub.';
        return ['error' => $message];
    }
    $tag = isset($data['tag_name']) ? (string) $data['tag_name'] : '';
    if ($tag === '') {
        return ['error' => 'Release has no version tag.'];
    }
    $releaseBody = isset($data['body']) ? trim((string) $data['body']) : '';
    $assets = [];
    if (!empty($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (!is_array($asset) || empty($asset['name'])) {
                continue;
            }
            $name = (string) $asset['name'];
            if (!empty($asset['id'])) {
                $assets[$name] = $apiBase . '/releases/assets/' . (int) $asset['id'];
            } elseif (!empty($asset['browser_download_url'])) {
                $assets[$name] = (string) $asset['browser_download_url'];
            }
        }
    }
    $version = ltrim($tag, 'vV');
    $buildRef = null;
    if ($channel === 'dev') {
        $buildRef = dirindexParseDevBuildRef($releaseBody);
        $devVersion = dirindexParseDevVersion($releaseBody);
        if ($devVersion !== null) {
            $version = $devVersion;
        }
    }
    return [
        'channel' => $channel,
        'tag' => $tag,
        'version' => $version,
        'build_ref' => $buildRef,
        'html_url' => isset($data['html_url']) ? (string) $data['html_url'] : '',
        'body' => $releaseBody,
        'assets' => $assets,
    ];
}

function validateDirindexPhpSource($content, $expectedVersion = null, $expectedBuildRef = null) {
    if (!is_string($content)) {
        return 'Downloaded file is invalid.';
    }
    $len = strlen($content);
    $minLen = 50000;
    if ($len < $minLen || $len > 800000) {
        return 'Downloaded file size is unexpected (' . $len . ' bytes).';
    }
    $trimmed = ltrim($content);
    if (preg_match('/^<!DOCTYPE html\b/i', $trimmed) || preg_match('/^<html\b/i', $trimmed)) {
        return 'Could not download the update (GitHub returned HTML instead of the release file).';
    }
    if (!preg_match('/^\xEF\xBB\xBF?<\?php\b/', $trimmed)) {
        return 'Downloaded file is not a PHP script.';
    }
    if (!str_contains($content, '$dirindexVersion')) {
        return 'Downloaded file does not look like php-dirindex.';
    }
    if ($expectedVersion !== null && !preg_match('/\$dirindexVersion\s*=\s*[\'"]' . preg_quote((string) $expectedVersion, '/') . '[\'"];/', $content)) {
        return 'Downloaded file version does not match the release.';
    }
    if ($expectedBuildRef !== null) {
        $buildRef = dirindexBuildRefFromPhpSource($content);
        if ($buildRef === '' || !hash_equals(strtolower((string) $expectedBuildRef), $buildRef)) {
            return 'Downloaded dev build does not match the release.';
        }
    }
    return null;
}

function dirindexScriptDirWritableForUpdate() {
    $dir = dirname(__FILE__);
    return is_writable($dir);
}

function canApplyDirindexUpdate($authenticated, $hasUploadCredentials, $inShareMode) {
    if ($inShareMode || !$hasUploadCredentials || !$authenticated) {
        return false;
    }
    return dirindexScriptDirWritableForUpdate();
}

function dirindexUpdateCheckPayload($currentVersion, $currentBuildRef, $repoUrl, $authenticated, $hasUploadCredentials, $inShareMode, $channel = 'stable') {
    $channel = dirindexNormalizeUpdateChannel($channel);
    $artifact = dirindexUpdateArtifactName();
    $currentBuildRef = strtolower(trim((string) $currentBuildRef));
    $release = dirindexFetchRelease($repoUrl, $channel);
    if (!empty($release['error'])) {
        return [
            'ok' => false,
            'error' => $release['error'],
            'channel' => $channel,
            'current_version' => $currentVersion,
            'current_build_ref' => $currentBuildRef,
            'artifact' => $artifact,
            'can_update' => false,
        ];
    }
    $latestVersion = $release['version'];
    $latestBuildRef = $release['build_ref'] ?? null;
    if ($channel === 'dev') {
        if ($latestBuildRef === null) {
            return [
                'ok' => false,
                'error' => 'Dev release is missing a build ref.',
                'channel' => $channel,
                'current_version' => $currentVersion,
                'current_build_ref' => $currentBuildRef,
                'artifact' => $artifact,
                'can_update' => false,
            ];
        }
        $updateAvailable = $currentBuildRef !== $latestBuildRef;
        $upToDate = !$updateAvailable;
    } else {
        $cmp = compareSemverTags($latestVersion, $currentVersion);
        $updateAvailable = $cmp > 0;
        $upToDate = $cmp <= 0;
    }
    $downloadUrl = $release['assets'][$artifact] ?? null;
    $payload = [
        'ok' => true,
        'channel' => $channel,
        'current_version' => $currentVersion,
        'current_build_ref' => $currentBuildRef,
        'latest_version' => $latestVersion,
        'latest_build_ref' => $latestBuildRef,
        'latest_tag' => $release['tag'],
        'update_available' => $updateAvailable,
        'up_to_date' => $upToDate,
        'release_url' => $release['html_url'],
        'release_notes' => $release['body'],
        'artifact' => $artifact,
        'download_url' => $downloadUrl,
        'can_update' => $updateAvailable && $downloadUrl !== null && canApplyDirindexUpdate($authenticated, $hasUploadCredentials, $inShareMode),
    ];
    if ($updateAvailable && $downloadUrl === null) {
        $payload['error'] = 'Release has no ' . $artifact . ' asset.';
    } elseif ($updateAvailable && !canApplyDirindexUpdate($authenticated, $hasUploadCredentials, $inShareMode)) {
        if (!$hasUploadCredentials) {
            $payload['error'] = 'Complete setup to enable in-place updates.';
        } elseif (!$authenticated) {
            $payload['error'] = 'Sign in as admin to update in place.';
        } elseif (!dirindexScriptDirWritableForUpdate()) {
            $payload['error'] = 'The script directory is not writable by PHP.';
        }
    }
    return $payload;
}

function applyDirindexSelfUpdate($downloadUrl, $expectedVersion, $channel = 'stable', $expectedBuildRef = null) {
    $channel = dirindexNormalizeUpdateChannel($channel);
    $content = dirindexHttpGet($downloadUrl, 'application/octet-stream', 60);
    if ($content === null) {
        return 'Could not download the update.';
    }
    $validationError = validateDirindexPhpSource(
        $content,
        $channel === 'dev' ? null : $expectedVersion,
        $channel === 'dev' ? $expectedBuildRef : null
    );
    if ($validationError !== null) {
        return $validationError;
    }
    $target = __FILE__;
    $dir = dirname($target);
    $tmp = $dir . DIRECTORY_SEPARATOR . '.dirindex-update-' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        return 'Could not write a temporary update file.';
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return 'Could not replace ' . basename($target) . '. Check file permissions.';
    }
    return null;
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

function isCreateEnabled(array $config) {
    return !empty($config['create_enabled']) && hasUploadCredentials($config);
}

function isDeleteEnabled(array $config) {
    return !empty($config['delete_enabled']) && hasUploadCredentials($config);
}

function isBrowseAuthRequired(array $config) {
    return !empty($config['browse_requires_auth']) && hasUploadCredentials($config);
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

function indexDirectoryWebPath($indexHref) {
    $dir = str_replace('\\', '/', dirname((string) $indexHref));
    if ($dir === '/' || $dir === '.') {
        return '/';
    }
    return rtrim($dir, '/') . '/';
}

function detectWebRootUrl($indexHref) {
    $origin = requestOrigin();
    $path = indexDirectoryWebPath($indexHref);
    if ($origin !== '') {
        return $origin . $path;
    }
    return $path;
}

function normalizeWebRootUrlInput($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (strlen($value) > 2048) {
        return null;
    }
    if (preg_match('#^https?://#i', $value)) {
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if ($path !== '/' && !str_ends_with($path, '/')) {
            $path .= '/';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return strtolower((string) $parts['scheme']) . '://' . $parts['host'] . $port . $path;
    }
    if ($value[0] !== '/') {
        return null;
    }
    if (str_contains($value, '..') || str_contains($value, "\0")) {
        return null;
    }
    if ($value !== '/' && !str_ends_with($value, '/')) {
        $value .= '/';
    }
    return $value;
}

function effectiveWebRootUrl(array $config, $indexHref) {
    $configured = trim((string) ($config['web_root_url'] ?? ''));
    if ($configured !== '') {
        $normalized = normalizeWebRootUrlInput($configured);
        if ($normalized !== null && $normalized !== '') {
            return $normalized;
        }
    }
    return detectWebRootUrl($indexHref);
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
    global $effectiveWebRootUrl;
    $base = $effectiveWebRootUrl ?? '/';
    $relativePath = trim((string) $relativePath, '/');
    $pathPart = '/';
    if ($relativePath !== '') {
        $segments = array_values(array_filter(explode('/', $relativePath), function ($segment) {
            return $segment !== '';
        }));
        $pathPart = '/' . implode('/', array_map('rawurlencode', $segments));
        if ($trailingSlash) {
            $pathPart = rtrim($pathPart, '/') . '/';
        }
    } elseif ($trailingSlash) {
        $pathPart = '/';
    }
    if (preg_match('#^https?://#i', $base)) {
        $base = rtrim($base, '/');
        return $base . $pathPart;
    }
    $base = rtrim($base, '/');
    if ($base === '') {
        $base = '/';
    }
    if ($pathPart === '/') {
        return $base === '/' ? '/' : $base . '/';
    }
    if ($base === '/') {
        return $pathPart;
    }
    return $base . $pathPart;
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
    if (!isAllowedEntryName($name)) {
        return null;
    }
    return $name;
}

function uploadDirMaxFiles() {
    return 500;
}

function normalizeUploadRelativePath($path) {
    $path = str_replace('\\', '/', (string) $path);
    $path = trim($path, '/');
    if ($path === '' || str_contains($path, "\0") || relativePathHasTraversal($path)) {
        return null;
    }
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if (!isAllowedEntryName($segment)) {
            return null;
        }
        if (dirindexIsHiddenListingEntry($segment)) {
            return null;
        }
        $segments[] = $segment;
    }
    return implode('/', $segments);
}

function uploadFilesArrayFromRequest($field = 'upload_file') {
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['name'])) {
        return [];
    }
    $file = $_FILES[$field];
    if (!is_array($file['name'])) {
        return [[
            'name'     => (string) $file['name'],
            'type'     => (string) ($file['type'] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'] ?? ''),
            'error'    => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($file['size'] ?? 0),
        ]];
    }
    $out = [];
    foreach ($file['name'] as $i => $name) {
        $out[] = [
            'name'     => (string) $name,
            'type'     => (string) ($file['type'][$i] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'][$i] ?? ''),
            'error'    => (int) ($file['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($file['size'][$i] ?? 0),
        ];
    }
    return $out;
}

function processDirectoryUpload($currentPath, $relativePath, array $files, $maxBytes, $allowOverwrite, array $pathWhitelist, array $pathBlacklist) {
    if ($files === []) {
        return 'upload_missing';
    }
    if (count($files) > uploadDirMaxFiles()) {
        return 'upload_dir_too_many';
    }
    $planned = [];
    foreach ($files as $file) {
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return uploadErrorMessage($file['error']);
        }
        $entryRel = normalizeUploadRelativePath($file['name']);
        if ($entryRel === null) {
            return 'upload_dir_bad_path';
        }
        $fullRel = $relativePath !== '' ? $relativePath . '/' . $entryRel : $entryRel;
        if (isPathAccessDenied($fullRel, $pathWhitelist, $pathBlacklist)) {
            return 'path_denied';
        }
        if ($maxBytes > 0 && (int) $file['size'] > $maxBytes) {
            return 'upload_too_large';
        }
        $planned[] = [
            'entryRel' => $entryRel,
            'dest'     => $currentPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryRel),
            'file'     => $file,
        ];
    }
    $conflicts = 0;
    foreach ($planned as $entry) {
        if (!file_exists($entry['dest'])) {
            continue;
        }
        if (is_dir($entry['dest']) || is_link($entry['dest'])) {
            return 'upload_target_blocked';
        }
        $conflicts++;
    }
    if ($conflicts > 0 && !$allowOverwrite) {
        dirindexFlashSet($conflicts . ' file(s) in this upload already exist.');
        return 'upload_dir_exists';
    }
    $uploaded = 0;
    foreach ($planned as $entry) {
        $dest = $entry['dest'];
        $exists = file_exists($dest);
        if ($exists && (is_dir($dest) || is_link($dest))) {
            return 'upload_target_blocked';
        }
        if ($exists && !$allowOverwrite) {
            continue;
        }
        $parentDir = dirname($dest);
        if (!is_dir($parentDir) && !@mkdir($parentDir, 0755, true)) {
            return 'upload_failed';
        }
        $tmpName = (string) $entry['file']['tmp_name'];
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return 'upload_failed';
        }
        if ($exists && !@unlink($dest)) {
            return 'upload_failed';
        }
        if (!@move_uploaded_file($tmpName, $dest)) {
            return 'upload_failed';
        }
        $uploaded++;
    }
    if ($uploaded === 0) {
        return 'upload_missing';
    }
    dirindexFlashSet($uploaded . ' file(s) uploaded.');
    return ($conflicts > 0) ? 'upload_dir_overwritten' : 'upload_dir_ok';
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
    'create_enabled'            => true,
    'delete_enabled'            => true,
    'browse_requires_auth'      => false,
    'auth_username'             => '',
    'auth_password_hash'        => '',
    'upload_max_bytes'          => 0,
    'path_whitelist'            => [],
    'path_blacklist'            => [],
    'web_root_url'              => '',
    'listing_from_document_root' => false,
    'image_preview_enabled'     => true,
    'markdown_preview_enabled'  => true,
    'preview_blocklist'         => ['php'],
    'hash_sha256_sha512_enabled' => false,
    'session_name'              => 'dirindex_upload',
];
$dirindexStorage = [];
$storedConfig = loadDirindexStoredConfig(__DIR__, $dirindexStorage);
$storedConfig = dirindexImportLegacyConfigIfNeeded(__DIR__, $storedConfig);
if ($storedConfig) {
    $dirindexConfig = array_merge($dirindexConfig, $storedConfig);
}
$baseDir = resolveListingBaseDir(__DIR__, !empty($dirindexConfig['listing_from_document_root']));
$realBase = realpath($baseDir);
if ($realBase === false) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('HTTP/1.1 500 Internal Server Error');
    exit('Base directory is not accessible.');
}
$effectiveWebRootUrl = effectiveWebRootUrl($dirindexConfig, $indexHref);
$webRootUrlConfigured = trim((string) ($dirindexConfig['web_root_url'] ?? ''));
$webRootUrlDetected = detectWebRootUrl($indexHref);
$pathWhitelist = isset($dirindexConfig['path_whitelist']) && is_array($dirindexConfig['path_whitelist']) ? array_values($dirindexConfig['path_whitelist']) : [];
$pathBlacklist = isset($dirindexConfig['path_blacklist']) && is_array($dirindexConfig['path_blacklist']) ? array_values($dirindexConfig['path_blacklist']) : [];
if ($pathBlacklist === [] && isset($dirindexConfig['hidden_paths']) && is_array($dirindexConfig['hidden_paths'])) {
    $legacyHiddenPaths = array_values($dirindexConfig['hidden_paths']);
    if ($legacyHiddenPaths !== []) {
        $pathBlacklist = $legacyHiddenPaths;
    }
}
$allowOutside = !empty($dirindexConfig['allow_open_symlinks_outside']);
$hasUploadCredentials = hasUploadCredentials($dirindexConfig);
$setupNeeded = !$hasUploadCredentials;
$uploadEnabled = isUploadEnabled($dirindexConfig);
$createEnabled = isCreateEnabled($dirindexConfig);
$deleteEnabled = isDeleteEnabled($dirindexConfig);
$browseAuthRequired = isBrowseAuthRequired($dirindexConfig);
$previewBlocklist = normalizePreviewBlocklist(
    isset($dirindexConfig['preview_blocklist']) && is_array($dirindexConfig['preview_blocklist'])
        ? $dirindexConfig['preview_blocklist']
        : ['php']
);
$imagePreviewEnabled = !isset($dirindexConfig['image_preview_enabled']) || !empty($dirindexConfig['image_preview_enabled']);
$markdownPreviewEnabled = !isset($dirindexConfig['markdown_preview_enabled']) || !empty($dirindexConfig['markdown_preview_enabled']);
$hashSha256Sha512Enabled = !empty($dirindexConfig['hash_sha256_sha512_enabled']);
$sessionNeeded = $setupNeeded || $hasUploadCredentials || $browseAuthRequired || $_SERVER['REQUEST_METHOD'] === 'POST';
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
if ($relativePath !== '' && (relativePathHasTraversal($relativePath) || str_contains($relativePath, "\0"))) {
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

if (isset($_GET['update_check']) && $relativePath === '' && !$inShareMode) {
    $updateChannel = dirindexNormalizeUpdateChannel(isset($_GET['channel']) ? (string) $_GET['channel'] : 'stable');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        dirindexUpdateCheckPayload($dirindexVersion, $dirindexBuildRef, $dirindexRepoUrl, $authenticated, $hasUploadCredentials, $inShareMode, $updateChannel),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    );
    exit;
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

function imagePreviewExtensions() {
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tiff', 'tif', 'avif', 'heic'];
}

function isImageExtension($ext) {
    return in_array(strtolower((string) $ext), imagePreviewExtensions(), true);
}

function isPreviewExtensionBlocked($ext, array $blocklist) {
    $ext = strtolower((string) $ext);
    return $ext !== '' && in_array($ext, $blocklist, true);
}

function listingEntryDownloadUrl($relativePath, $ext, $inShareMode, $indexHref, array $previewBlocklist) {
    if (isPreviewExtensionBlocked($ext, $previewBlocklist)) {
        return null;
    }
    if ($inShareMode) {
        return currentListingUrl($indexHref, $relativePath, ['download' => '1']);
    }
    return directEntryUrl($relativePath);
}

/**
 * Whether a file can open in the preview modal: 'text', 'image', or false.
 */
function filePreviewKind($absolutePath, $ext, array $previewExts, array $blocklist, $imagePreviewEnabled) {
    $ext = strtolower((string) $ext);
    if (isPreviewExtensionBlocked($ext, $blocklist)) {
        return false;
    }
    if ($imagePreviewEnabled && isImageExtension($ext)) {
        return 'image';
    }
    if (isset($previewExts[$ext])) {
        return 'text';
    }
    if (!is_file($absolutePath)) {
        return false;
    }
    if (!looksLikeBinary($absolutePath)) {
        return 'text';
    }
    return false;
}

function previewImageUrl($indexHref, $relativePath, $inShareMode) {
    if ($inShareMode) {
        return currentListingUrl($indexHref, $relativePath, ['inline' => '1']);
    }
    return directEntryUrl($relativePath);
}

function isMarkdownExtension($ext) {
    $ext = strtolower((string) $ext);
    return $ext === 'md' || $ext === 'markdown';
}

function markdownSafeUrl($url) {
    $url = trim((string) $url);
    if ($url === '' || preg_match('/^(javascript|data|vbscript|file):/i', $url)) {
        return null;
    }
    return $url;
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

function listingEntryLinkTitle(array $item) {
    if (empty($item['isLink'])) {
        return '';
    }
    $target = trim((string) ($item['linkTarget'] ?? ''));
    if (!empty($item['isBrokenLink'])) {
        return $target !== '' ? 'Broken symbolic link → ' . $target : 'Broken symbolic link';
    }
    return $target;
}

function listingEntryTypeClass(array $item) {
    if (!empty($item['isLink'])) {
        return !empty($item['isBrokenLink']) ? 'ft-type--symlink-broken' : 'ft-type--symlink';
    }
    if (!empty($item['isDir'])) {
        return 'ft-type--dir';
    }
    return 'ft-type--' . fileExtensionCategory($item['ext'] ?? '');
}

function fileTypeIconHtml($ext, $isDir = false, $compact = false) {
    $class = 'ft-icon' . ($compact ? ' ft-icon--listing' : '');
    if ($isDir) {
        return '<span class="' . $class . ' ft-icon--dir" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
            . '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'
            . '</svg></span>';
    }
    $ext = strtolower((string) $ext);
    $label = $ext !== '' ? strtoupper(strlen($ext) <= 4 ? $ext : substr($ext, 0, 4)) : 'FILE';
    $category = fileExtensionCategory($ext);
    return '<span class="' . $class . ' ft-icon--' . h($category) . '" aria-hidden="true">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">'
        . '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
        . '<polyline points="14 2 14 8 20 8"/>'
        . '</svg>'
        . '<span class="ft-icon__label">' . h($label) . '</span>'
        . '</span>';
}

function dirindexHljsClassForLang($lang) {
    $lang = (string) $lang;
    if ($lang === '' || $lang === 'plaintext') {
        return '';
    }
    if ($lang === 'markup') {
        return 'language-html';
    }
    return 'language-' . $lang;
}

function dirindexHljsScriptForLang($lang) {
    static $map = [
        'markdown' => 'markdown',
        'markup' => 'xml',
        'css' => 'css',
        'scss' => 'scss',
        'sass' => 'scss',
        'less' => 'less',
        'json' => 'json',
        'xml' => 'xml',
        'yaml' => 'yaml',
        'php' => 'php',
        'python' => 'python',
        'ruby' => 'ruby',
        'bash' => 'bash',
        'sql' => 'sql',
        'ini' => 'ini',
        'typescript' => 'typescript',
        'javascript' => 'javascript',
    ];
    return $map[(string) $lang] ?? null;
}

function renderShareFileLandingPage($relativePath, $absolutePath, array $share, $indexHref, $previewKind, $size, $mtime, array $previewExts, $ext, array $previewBlocklist, $markdownPreviewEnabled) {
    $name = basename($relativePath);
    $downloadParams = ['download' => '1'];
    if ($share['type'] === 'dir') {
        $downloadParams['path'] = $relativePath;
    }
    $downloadUrl = shareUrl($indexHref, $share['token'], $downloadParams);
    $mtimeFormatted = ($mtime !== null && $mtime >= 0 && $mtime <= 2147483647) ? @date('Y-m-d H:i', (int) $mtime) : '—';
    $previewHtml = '';
    $previewLang = null;
    $isCodePreview = false;
    $isMdPreview = false;
    if ($previewKind === 'text') {
        $raw = @file_get_contents($absolutePath);
        if ($raw !== false) {
            $lang = $previewExts[$ext] ?? 'plaintext';
            if (!isset($previewExts[$ext]) && (looksLikeBinary($absolutePath) || filesize($absolutePath) > 512 * 1024)) {
                $raw = false;
            }
            if ($raw !== false) {
                if (isMarkdownExtension($ext) && $markdownPreviewEnabled) {
                    $previewHtml = '<div class="preview-md">' . markdownToHtml($raw) . '</div>';
                    $isMdPreview = true;
                } else {
                    $previewLang = $lang;
                    $isCodePreview = true;
                    $hlClass = dirindexHljsClassForLang($lang);
                    $codeAttrs = $hlClass !== '' ? ' class="' . h($hlClass) . '"' : '';
                    $escaped = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
                    $previewHtml = '<pre class="preview-code hljs"><code' . $codeAttrs . '>' . $escaped . '</code></pre>';
                }
            }
        }
    } elseif ($previewKind === 'image') {
        $inlineParams = ['inline' => '1'];
        if ($share['type'] === 'dir') {
            $inlineParams['path'] = $relativePath;
        }
        $inlineUrl = shareUrl($indexHref, $share['token'], $inlineParams);
        $previewHtml = '<div class="preview-image"><img src="' . h($inlineUrl) . '" alt="' . h($name) . '"></div>';
    }
    $css = '
    :root { --bg: #0d0d0f; --bg-card: #141417; --border: #25252a; --text: #e4e4e7; --text-muted: #71717a; --accent: #a78bfa; --accent-dim: #7c3aed; --md-pre-bg: #282c34; --md-code-bg: rgba(255,255,255,0.08); --md-quote: #a1a1aa; --md-th-bg: rgba(0,0,0,0.25); }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; font-size: 15px; line-height: 1.6; padding: clamp(1rem, 3vw, 2rem); }
    .page { max-width: 720px; margin: 0 auto; }
    .page--wide { max-width: min(1100px, calc(100vw - 2rem)); }
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: clamp(1.25rem, 3vw, 2rem); }
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
    .preview-md { color: var(--text); }
    .preview-md h1, .preview-md h2, .preview-md h3, .preview-md h4, .preview-md h5, .preview-md h6 { margin-top: 1.25em; margin-bottom: 0.5em; color: var(--text); }
    .preview-md p { margin: 0.5em 0; }
    .preview-md a { color: var(--accent); text-decoration: none; }
    .preview-md a:hover { text-decoration: underline; }
    .preview-md :not(pre) > code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 0.9em; background: var(--md-code-bg); color: var(--text); padding: 0.15em 0.35em; border-radius: 4px; }
    .preview-md pre,
    .preview-md pre.hljs { margin: 0.75em 0; background: var(--md-pre-bg) !important; border: 1px solid var(--border); border-radius: 8px; padding: 1rem 1.1rem; overflow: auto; font-size: 0.85rem; line-height: 1.55; max-height: min(75vh, 960px); }
    .preview-md pre code { display: block; background: none; padding: 0; white-space: pre; }
    .preview-md blockquote { margin: 0.75em 0; padding: 0.25em 0 0.25em 1rem; border-left: 3px solid var(--accent-dim); color: var(--md-quote); }
    .preview-md hr { border: none; border-top: 1px solid var(--border); margin: 1.25em 0; }
    .preview-md img { max-width: 100%; height: auto; border-radius: 8px; }
    .preview-md ul, .preview-md ol { margin: 0.5em 0; padding-left: 1.5rem; }
    .preview-md .task-list-item { list-style: none; margin-left: -1.5rem; }
    .preview-md .task-list-item-checkbox { margin: 0 0.4em 0 0; vertical-align: middle; cursor: default; width: 1.1em; height: 1.1em; border: 1px solid var(--text-muted); background: var(--bg); border-radius: 3px; accent-color: var(--accent); }
    .preview-md .task-list-item-checkbox:checked { background: var(--accent-dim); border-color: var(--accent); }
    .preview-md .md-table-wrap { overflow-x: auto; margin: 0.75em 0; }
    .preview-md table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
    .preview-md th, .preview-md td { border: 1px solid var(--border); padding: 0.4em 0.65em; text-align: left; vertical-align: top; color: var(--text); }
    .preview-md th { background: var(--md-th-bg); font-weight: 600; }
    .preview-code { margin: 0; background: #282c34; border: 1px solid var(--border); border-radius: 8px; padding: 1rem 1.1rem; overflow: auto; font-size: 0.85rem; line-height: 1.55; max-height: min(75vh, 960px); }
    .preview-code code { display: block; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; white-space: pre; }
    .preview-image { text-align: center; }
    .preview-image img { max-width: 100%; max-height: min(75vh, 960px); width: auto; height: auto; object-fit: contain; border-radius: 8px; }
    ';
    $hljsCdn = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/';
    $headExtra = '';
    if ($isCodePreview || $isMdPreview) {
        $headExtra = '<link rel="stylesheet" href="' . h($hljsCdn) . 'styles/atom-one-dark.min.css">';
    }
    $pageClass = ($isCodePreview || $previewKind === 'image' || $isMdPreview) ? 'page page--wide' : 'page';
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($name) . '</title>' . $headExtra . '<style>' . $css . '</style></head><body><div class="' . $pageClass . '"><div class="card">';
    $html .= '<div class="file-header">';
    $html .= fileTypeIconHtml($ext, false);
    $html .= '<div class="file-header-text"><h1>' . h($name) . '</h1>';
    $html .= '<p class="meta">' . h(formatSize($size)) . ' · Modified ' . h($mtimeFormatted) . '</p></div></div>';
    if (!isPreviewExtensionBlocked($ext, $previewBlocklist)) {
        $html .= '<a class="btn-download" href="' . h($downloadUrl) . '">Download</a>';
    }
    if ($previewHtml !== '') {
        $html .= '<div class="preview"><h2>Preview</h2>' . $previewHtml . '</div>';
    }
    $html .= '</div></div>';
    if ($isCodePreview) {
        $html .= '<script src="' . h($hljsCdn) . 'highlight.min.js"></script>';
        if ($previewLang !== null && $previewLang !== 'plaintext') {
            $scriptLang = dirindexHljsScriptForLang($previewLang);
            if ($scriptLang !== null) {
                $html .= '<script src="' . h($hljsCdn) . 'languages/' . h($scriptLang) . '.min.js"></script>';
            }
        }
        $html .= '<script>var el=document.querySelector(".preview-code code");if(el&&window.hljs)hljs.highlightElement(el);</script>';
    } elseif ($isMdPreview) {
        $html .= '<script src="' . h($hljsCdn) . 'highlight.min.js"></script>';
        foreach (['php', 'javascript', 'python', 'bash', 'json', 'xml', 'yaml', 'typescript', 'ini'] as $mdHljsLang) {
            $html .= '<script src="' . h($hljsCdn) . 'languages/' . h($mdHljsLang) . '.min.js"></script>';
        }
        $html .= '<script>document.querySelectorAll(".preview-md pre code").forEach(function(el){if(window.hljs)hljs.highlightElement(el);});</script>';
    }
    $html .= '</body></html>';
    return $html;
}

$canBrowse = $inShareMode || (!$setupNeeded && (!$browseAuthRequired || $authenticated));
$browseAuthBlocked = $browseAuthRequired && !$authenticated && !$inShareMode && !$setupNeeded;
$blockedMessage = null;

if ($browseAuthBlocked) {
    if (isset($_GET['content']) || isset($_GET['meta'])) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Authentication required.'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $relativePath = '';
}

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
    if (isset($_GET['inline'])) {
        serveSharedFileInline($shareFileReal, basename($effectivePath));
    }
    if (!isset($_GET['content']) && !isset($_GET['meta'])) {
        $shareExt = strtolower(pathinfo($effectivePath, PATHINFO_EXTENSION));
        $sharePreviewKind = filePreviewKind($shareFileReal, $shareExt, $previewExts, $previewBlocklist, $imagePreviewEnabled);
        $shareMtime = @filemtime($shareFileReal);
        header('Content-Type: text/html; charset=UTF-8');
        echo renderShareFileLandingPage($effectivePath, $shareFileReal, $shareContext, $indexHref, $sharePreviewKind, filesize($shareFileReal), $shareMtime !== false ? (int) $shareMtime : null, $previewExts, $shareExt, $previewBlocklist, $markdownPreviewEnabled);
        exit;
    }
    $relativePath = $effectivePath;
}

if ($relativePath !== '') {
    denyPathAccessIfNeeded($relativePath, $pathWhitelist, $pathBlacklist, $inShareMode);
    $requestedPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $requestedReal = realpath($requestedPath);
    if ($inShareMode && isset($_GET['download']) && is_file($requestedPath) && $requestedReal !== false && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        serveSharedFileDownload($requestedReal, basename($relativePath));
    }
    if ($inShareMode && isset($_GET['inline']) && is_file($requestedPath) && $requestedReal !== false && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        serveSharedFileInline($requestedReal, basename($relativePath));
    }
    if ($inShareMode && $shareContext && $shareContext['type'] === 'dir' && is_file($requestedPath) && $requestedReal !== false
        && !isset($_GET['content']) && !isset($_GET['meta']) && !isset($_GET['download']) && !isset($_GET['inline']) && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        $fileExt = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $filePreviewKind = filePreviewKind($requestedReal, $fileExt, $previewExts, $previewBlocklist, $imagePreviewEnabled);
        $fileMtime = @filemtime($requestedReal);
        header('Content-Type: text/html; charset=UTF-8');
        echo renderShareFileLandingPage($relativePath, $requestedReal, $shareContext, $indexHref, $filePreviewKind, filesize($requestedReal), $fileMtime !== false ? (int) $fileMtime : null, $previewExts, $fileExt, $previewBlocklist, $markdownPreviewEnabled);
        exit;
    }
    $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
    $isMarkdownDirect = isMarkdownExtension($ext)
        && !isset($_GET['content']) && !isset($_GET['meta']) && !isset($_GET['download']) && !isset($_GET['inline']) && !isset($_GET['open'])
        && !($inShareMode && $shareContext && $shareContext['type'] === 'dir');
    if ($canBrowse && is_file($requestedPath) && $isMarkdownDirect && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        $openName = basename($relativePath);
        $parentPath = dirname($relativePath);
        if ($parentPath === '.' || $parentPath === '') {
            $parentPath = '';
        }
        header('Location: ' . currentListingUrl($indexHref, $parentPath, ['open' => $openName]));
        exit;
    }
    if ($canBrowse && isset($_GET['meta']) && metaRequestAllowed($requestedPath, $requestedReal, $realBase, $allowOutside)) {
        $includeHashes = isset($_GET['hashes']);
        if ($includeHashes) {
            @set_time_limit(0);
        }
        header('Content-Type: application/json; charset=UTF-8');
        $metaPath = ($requestedReal !== false && is_file($requestedReal)) ? $requestedReal : $requestedPath;
        $meta = fileMetadataJson($metaPath, $relativePath, $ext, false, $hashSha256Sha512Enabled);
        if ($includeHashes) {
            $hashError = filePathHashError($requestedPath);
            if ($hashError !== null) {
                $meta['error'] = $hashError;
            } else {
                $meta['hashes'] = filePathHashes($metaPath, $hashSha256Sha512Enabled);
            }
        }
        echo json_encode($meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($canBrowse && is_file($requestedPath) && isset($_GET['content']) && (pathUnderBase($requestedReal, $realBase) || $allowOutside)) {
        if (isPreviewExtensionBlocked($ext, $previewBlocklist)) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Preview is disabled for this file type.'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $raw = @file_get_contents($requestedPath);
        if ($raw !== false) {
            $lang = $previewExts[$ext] ?? 'plaintext';
            if (!isset($previewExts[$ext]) && (looksLikeBinary($requestedPath) || filesize($requestedPath) > 512 * 1024)) {
                $raw = false; // refuse to send likely binary or large unknown files
            }
            if ($raw !== false) {
                header('Content-Type: application/json; charset=UTF-8');
                $out = fileMetadataJson($requestedReal, $relativePath, $ext, false);
                $out['content'] = $raw;
                $out['lang'] = $lang;
                $out['hashes'] = fileContentHashes($raw, $hashSha256Sha512Enabled);
                if (isMarkdownExtension($ext) && $markdownPreviewEnabled) {
                    $out['html'] = markdownToHtml($raw);
                }
                echo json_encode($out, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }
    if (!$allowOutside && is_file($requestedPath) && ($isMarkdownDirect || isset($_GET['content']) || isset($_GET['meta'])) && $requestedReal !== false && !pathUnderBase($requestedReal, $realBase)) {
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
        $webRootInput = trim((string) ($_POST['web_root_url'] ?? ''));
        if ($webRootInput === '') {
            $webRootUrl = detectWebRootUrl($indexHref);
        } else {
            $webRootUrl = normalizeWebRootUrlInput($webRootInput);
            if ($webRootUrl === null) {
                redirectToCurrentListing($indexHref, $relativePath, 'web_root_url_invalid');
            }
        }
        $setupSettings = [
            'upload_enabled' => '1',
            'create_enabled' => '1',
            'delete_enabled' => '1',
            'auth_username' => $username,
            'auth_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'upload_max_bytes' => (string) $maxBytesInt,
            'web_root_url' => $webRootUrl,
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
        $pathWhitelistEntries = parsePathAccessListInput($_POST['path_whitelist'] ?? '');
        $pathBlacklistEntries = parsePathAccessListInput($_POST['path_blacklist'] ?? '');
        $invalidIpEntry = null;
        $invalidPathEntry = null;
        if (!validateIpAccessList($ipWhitelistEntries, $invalidIpEntry) || !validateIpAccessList($ipBlacklistEntries, $invalidIpEntry)) {
            redirectToCurrentListing($indexHref, $relativePath, 'ip_access_invalid');
        }
        if (!validatePathAccessList($pathWhitelistEntries, $invalidPathEntry) || !validatePathAccessList($pathBlacklistEntries, $invalidPathEntry)) {
            redirectToCurrentListing($indexHref, $relativePath, 'path_access_invalid');
        }
        $ipHeader = normalizeIpHeaderInput($_POST['ip_header'] ?? '');
        if ($ipHeader === null) {
            redirectToCurrentListing($indexHref, $relativePath, 'ip_header_invalid');
        }
        $webRootInput = trim((string) ($_POST['web_root_url'] ?? ''));
        $webRootUrl = $webRootInput === '' ? '' : normalizeWebRootUrlInput($webRootInput);
        if ($webRootUrl === null) {
            redirectToCurrentListing($indexHref, $relativePath, 'web_root_url_invalid');
        }
        $previewBlocklistEntries = parsePreviewBlocklistInput($_POST['preview_blocklist'] ?? '');
        $invalidPreviewEntry = null;
        if (!validatePreviewBlocklist($previewBlocklistEntries, $invalidPreviewEntry)) {
            redirectToCurrentListing($indexHref, $relativePath, 'preview_blocklist_invalid');
        }
        $saveError = null;
        $saved = saveDirindexStoredConfig(__DIR__, [
            'show_symlinks' => isset($_POST['show_symlinks']) ? '1' : '0',
            'listing_from_document_root' => isset($_POST['listing_from_document_root']) ? '1' : '0',
            'allow_open_symlinks_outside' => isset($_POST['allow_open_symlinks_outside']) ? '1' : '0',
            'upload_enabled' => isset($_POST['upload_enabled']) ? '1' : '0',
            'create_enabled' => isset($_POST['create_enabled']) ? '1' : '0',
            'delete_enabled' => isset($_POST['delete_enabled']) ? '1' : '0',
            'browse_requires_auth' => isset($_POST['browse_requires_auth']) ? '1' : '0',
            'image_preview_enabled' => isset($_POST['image_preview_enabled']) ? '1' : '0',
            'markdown_preview_enabled' => isset($_POST['markdown_preview_enabled']) ? '1' : '0',
            'hash_sha256_sha512_enabled' => isset($_POST['hash_sha256_sha512_enabled']) ? '1' : '0',
            'upload_max_bytes' => (string) $maxBytesInt,
            'ip_whitelist' => $ipWhitelistEntries,
            'ip_blacklist' => $ipBlacklistEntries,
            'path_whitelist' => $pathWhitelistEntries,
            'path_blacklist' => $pathBlacklistEntries,
            'preview_blocklist' => $previewBlocklistEntries,
            'ip_header' => $ipHeader,
            'web_root_url' => $webRootUrl,
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

    if ($action === 'reset') {
        if (!$hasUploadCredentials || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, 'auth_required');
        }
        $resetError = null;
        if (!dirindexDeleteStorage(__DIR__, $resetError)) {
            dirindexFlashSet($resetError ?: 'Could not delete settings storage.');
            redirectToCurrentListing($indexHref, $relativePath, 'reset_failed');
        }
        unset($_SESSION['dirindex_authenticated']);
        session_regenerate_id(true);
        redirectToCurrentListing($indexHref, '', 'reset_done');
    }

    if ($action === 'upload') {
        if (!$uploadEnabled || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, 'auth_required');
        }
        if (!is_dir($currentPath) || !is_writable($currentPath)) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_not_writable');
        }
        $uploadMode = (string) ($_POST['upload_mode'] ?? 'file');
        $allowOverwrite = ($_POST['overwrite'] ?? '') === '1';
        $maxBytes = isset($dirindexConfig['upload_max_bytes']) ? (int) $dirindexConfig['upload_max_bytes'] : 0;
        if ($uploadMode === 'directory') {
            $dirFiles = uploadFilesArrayFromRequest('upload_file');
            redirectToCurrentListing(
                $indexHref,
                $relativePath,
                processDirectoryUpload($currentPath, $relativePath, $dirFiles, $maxBytes, $allowOverwrite, $pathWhitelist, $pathBlacklist)
            );
        }
        $uploadFiles = uploadFilesArrayFromRequest('upload_file');
        if ($uploadFiles === []) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_missing');
        }
        $file = $uploadFiles[0];
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            redirectToCurrentListing($indexHref, $relativePath, uploadErrorMessage($file['error']));
        }
        $uploadAsRaw = trim((string) ($_POST['upload_as'] ?? ''));
        $name = null;
        if ($uploadAsRaw !== '') {
            $name = cleanUploadFilename($uploadAsRaw);
            if ($name === null) {
                redirectToCurrentListing($indexHref, $relativePath, 'upload_bad_name');
            }
        } else {
            $originalName = (string) ($file['name'] ?? '');
            $name = cleanUploadFilename($originalName);
            if ($name === null) {
                dirindexFlashSet('Suggested name: ' . suggestSafeEntryName($originalName));
                redirectToCurrentListing($indexHref, $relativePath, 'upload_bad_name');
            }
        }
        $uploadRelativePath = $relativePath !== '' ? $relativePath . '/' . $name : $name;
        if (isPathAccessDenied($uploadRelativePath, $pathWhitelist, $pathBlacklist)) {
            redirectToCurrentListing($indexHref, $relativePath, 'path_denied');
        }
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
        if ($exists && !$allowOverwrite) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_exists');
        }
        if (!@move_uploaded_file($tmpName, $destination)) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_failed');
        }
        redirectToCurrentListing($indexHref, $relativePath, $exists ? 'upload_overwritten' : 'upload_ok');
    }

    if ($action === 'create_entry') {
        if (!$createEnabled || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, $createEnabled ? 'auth_required' : 'create_disabled');
        }
        if (!is_dir($currentPath) || !is_writable($currentPath)) {
            redirectToCurrentListing($indexHref, $relativePath, 'upload_not_writable');
        }
        $name = cleanUploadFilename($_POST['entry_name'] ?? '');
        if ($name === null || dirindexIsHiddenListingEntry($name)) {
            $rawName = trim((string) ($_POST['entry_name'] ?? ''));
            if ($name === null && $rawName !== '' && !dirindexIsHiddenListingEntry($rawName)) {
                dirindexFlashSet('Suggested name: ' . suggestSafeEntryName($rawName));
            }
            redirectToCurrentListing($indexHref, $relativePath, 'create_bad_name');
        }
        $createRelativePath = $relativePath !== '' ? $relativePath . '/' . $name : $name;
        if (isPathAccessDenied($createRelativePath, $pathWhitelist, $pathBlacklist)) {
            redirectToCurrentListing($indexHref, $relativePath, 'path_denied');
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

    if ($action === 'delete_entry') {
        if (!$deleteEnabled || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, $deleteEnabled ? 'auth_required' : 'delete_disabled');
        }
        $entryPath = trim(str_replace('\\', '/', (string) ($_POST['entry_path'] ?? '')), '/');
        $resolved = resolveDeletableEntry($entryPath, $baseDir, $realBase, $allowOutside, $pathWhitelist, $pathBlacklist);
        if (!$resolved['ok']) {
            redirectToCurrentListing($indexHref, $relativePath, $resolved['error']);
        }
        if (!deleteFilesystemEntry($resolved['absolute'])) {
            redirectToCurrentListing($indexHref, $relativePath, 'delete_failed');
        }
        if ($sharesAvailable) {
            $shareError = null;
            $pdo = dirindexGetSharesPdo(__DIR__, $shareError);
            if ($pdo) {
                revokeSharesForDeletedPath($pdo, $entryPath);
            }
        }
        $parentPath = dirname($entryPath);
        if ($parentPath === '.' || $parentPath === '') {
            $parentPath = '';
        }
        redirectToCurrentListing($indexHref, $parentPath, 'delete_ok');
    }

    if ($action === 'share_create') {
        if (!$hasUploadCredentials || !$authenticated) {
            redirectToCurrentListing($indexHref, $relativePath, 'auth_required');
        }
        if (!$sharesAvailable) {
            redirectToCurrentListing($indexHref, $relativePath, 'share_unavailable');
        }
        $sharePath = trim((string) ($_POST['share_path'] ?? ''), '/');
        if ($sharePath === '' || relativePathHasTraversal($sharePath) || str_contains($sharePath, "\0")) {
            redirectToCurrentListing($indexHref, $relativePath, 'share_failed');
        }
        if (isPathAccessDenied($sharePath, $pathWhitelist, $pathBlacklist)) {
            redirectToCurrentListing($indexHref, $relativePath, 'path_denied');
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

    if ($action === 'app_update') {
        if (!isShareAjaxRequest()) {
            redirectToCurrentListing($indexHref, $relativePath, 'bad_action');
        }
        if (!$hasUploadCredentials || !$authenticated) {
            shareAjaxResponse(false, 'Please sign in as admin first.');
        }
        if (!canApplyDirindexUpdate($authenticated, $hasUploadCredentials, $inShareMode)) {
            shareAjaxResponse(false, 'The script directory is not writable by PHP.');
        }
        $updateChannel = dirindexNormalizeUpdateChannel(isset($_POST['channel']) ? (string) $_POST['channel'] : 'stable');
        $check = dirindexUpdateCheckPayload($dirindexVersion, $dirindexBuildRef, $dirindexRepoUrl, $authenticated, $hasUploadCredentials, $inShareMode, $updateChannel);
        if (empty($check['ok'])) {
            shareAjaxResponse(false, $check['error'] ?? 'Could not check for updates.');
        }
        if (empty($check['update_available'])) {
            if ($updateChannel === 'dev') {
                $buildRef = $dirindexBuildRef !== '' ? $dirindexBuildRef : 'current';
                shareAjaxResponse(false, 'Already on the latest dev build (' . $buildRef . ').');
            }
            shareAjaxResponse(false, 'Already up to date (v' . $dirindexVersion . ').');
        }
        if (empty($check['download_url'])) {
            shareAjaxResponse(false, $check['error'] ?? 'Update package not found.');
        }
        $applyError = applyDirindexSelfUpdate(
            $check['download_url'],
            $check['latest_version'],
            $updateChannel,
            $check['latest_build_ref'] ?? null
        );
        if ($applyError !== null) {
            shareAjaxResponse(false, $applyError);
        }
        if ($updateChannel === 'dev') {
            $buildRef = $check['latest_build_ref'] ?? 'latest';
            shareAjaxResponse(true, 'Updated to dev build ' . $buildRef . ' (v' . $check['latest_version'] . '). Reloading…');
        }
        shareAjaxResponse(true, 'Updated to v' . $check['latest_version'] . '. Reloading…');
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
        $entryRelativePath = $relativePath !== '' ? $relativePath . '/' . $entry : $entry;
        if (isPathAccessDenied($entryRelativePath, $pathWhitelist, $pathBlacklist, $inShareMode)) continue;
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
        $isBrokenLink = isBrokenSymbolicLink($full);
        $ext = $isFile ? strtolower(pathinfo($entry, PATHINFO_EXTENSION)) : '';
        $previewKind = $isFile ? filePreviewKind($full, $ext, $previewExts, $previewBlocklist, $imagePreviewEnabled) : false;
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
            'ownerLabel' => formatEntryOwner($full),
            'previewKind' => $previewKind,
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
    'reset_done' => ['info', 'All settings were reset. Complete setup to continue.'],
    'reset_failed' => ['error', 'Could not reset settings. Check file permissions.'],
    'ip_access_invalid' => ['error', 'Access list contains an invalid IP address or CIDR range.'],
    'ip_header_invalid' => ['error', 'Client IP header name is not valid.'],
    'web_root_url_invalid' => ['error', 'Web root URL must be an absolute URL or a path starting with /.'],
    'path_access_invalid' => ['error', 'Path access list contains an invalid entry. Use relative paths without ..; wildcards (*, ?, [...], **) are allowed.'],
    'preview_blocklist_invalid' => ['error', 'Preview blocklist contains an invalid extension. Use lowercase names like php or js, one per line.'],
    'path_denied' => ['error', 'That path is not allowed.'],
    'upload_bad_name' => ['error', 'Upload filename is not allowed. Use letters, numbers, spaces, and . _ - ( ) [ ]. Avoid * ? " < > | : \\ / and trailing dots or spaces.'],
    'upload_exists' => ['error', 'A file with that name already exists. Confirm overwrite and try again.'],
    'upload_failed' => ['error', 'Upload failed.'],
    'upload_missing' => ['error', 'Choose a file to upload.'],
    'upload_not_writable' => ['error', 'This directory is not writable by PHP.'],
    'upload_ok' => ['success', 'Upload complete.'],
    'upload_overwritten' => ['success', 'Existing file overwritten.'],
    'upload_partial' => ['error', 'Upload was interrupted before it completed.'],
    'upload_target_blocked' => ['error', 'Cannot overwrite a directory or symlink.'],
    'upload_too_large' => ['error', 'Uploaded file is too large.'],
    'upload_dir_ok' => ['success', 'Folder upload complete.'],
    'upload_dir_overwritten' => ['success', 'Folder upload complete; existing files were overwritten.'],
    'upload_dir_exists' => ['error', 'Some files in this upload already exist. Confirm overwrite and try again.'],
    'upload_dir_bad_path' => ['error', 'A file path in this upload is not allowed.'],
    'upload_dir_too_many' => ['error', 'Too many files in one folder upload (limit is ' . uploadDirMaxFiles() . ').'],
    'create_disabled' => ['error', 'Creating folders and files is disabled in settings.'],
    'create_bad_name' => ['error', 'Name is not allowed. Use letters, numbers, spaces, and . _ - ( ) [ ]. Avoid * ? " < > | : \\ / and trailing dots or spaces.'],
    'create_exists' => ['error', 'An entry with that name already exists.'],
    'create_failed' => ['error', 'Could not create the entry.'],
    'create_folder_ok' => ['success', 'Folder created.'],
    'create_file_ok' => ['success', 'File created.'],
    'delete_disabled' => ['error', 'Deleting files and folders is disabled in settings.'],
    'delete_invalid' => ['error', 'That path cannot be deleted.'],
    'delete_missing' => ['error', 'That file or folder no longer exists.'],
    'delete_blocked' => ['error', 'That entry cannot be deleted.'],
    'delete_outside' => ['error', 'That entry points outside the listing root and cannot be deleted.'],
    'delete_not_writable' => ['error', 'This directory is not writable by PHP.'],
    'delete_failed' => ['error', 'Could not delete the entry.'],
    'delete_ok' => ['success', 'Entry deleted.'],
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
$openLoginModal = $hasUploadCredentials && !$authenticated && !$inShareMode && (
    $browseAuthBlocked
    || (isset($_GET['msg']) && in_array((string) $_GET['msg'], ['auth_required', 'login_failed'], true))
);
$openAccountModal = $authenticated && !$inShareMode && (
    isset($_GET['msg']) && in_array((string) $_GET['msg'], ['account_mismatch', 'account_missing', 'account_saved', 'account_short_password'], true)
);
$settingsPanelFocus = null;
$settingsModalMessageKeys = [
    'settings_saved',
    'settings_write_failed',
    'ip_access_invalid',
    'ip_header_invalid',
    'path_access_invalid',
    'preview_blocklist_invalid',
    'web_root_url_invalid',
];
$settingsModalMessage = null;
$openSettingsModal = $authenticated && !$inShareMode && isset($_GET['msg']) && in_array((string) $_GET['msg'], $settingsModalMessageKeys, true);
if ($openSettingsModal) {
    $settingsMsg = (string) $_GET['msg'];
    if ($statusMessage !== null) {
        $settingsModalMessage = $statusMessage;
    }
    if (in_array($settingsMsg, ['ip_access_invalid', 'ip_header_invalid'], true)) {
        $settingsPanelFocus = 'network';
    } elseif ($settingsMsg === 'path_access_invalid') {
        $settingsPanelFocus = 'path';
    } elseif ($settingsMsg === 'preview_blocklist_invalid') {
        $settingsPanelFocus = 'previews';
    } elseif ($settingsMsg === 'web_root_url_invalid') {
        $settingsPanelFocus = 'filesystem';
    }
}
$existingNames = [];
foreach ($items as $item) {
    $existingNames[] = $item['name'];
}

// Optional ?open=filename to open file in modal on load (shareable URL)
$openFileForModal = null;
if ($canBrowse && isset($_GET['open']) && $_GET['open'] !== '') {
    $openParam = trim((string) $_GET['open'], '/');
    if ($openParam !== '' && isSafeEntryName($openParam)) {
        $openFilePath = $relativePath !== '' ? $relativePath . '/' . $openParam : $openParam;
        if (isPathAccessDenied($openFilePath, $pathWhitelist, $pathBlacklist, $inShareMode)) {
            $blockedMessage = 'This path is not available.';
        } else {
        $openAbsPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $openFilePath);
        $openReal = realpath($openAbsPath);
        if ($openReal !== false && is_file($openReal) && (pathUnderBase($openReal, $realBase) || $allowOutside)) {
            $openExt = strtolower(pathinfo($openFilePath, PATHINFO_EXTENSION));
            $openPreviewKind = filePreviewKind($openReal, $openExt, $previewExts, $previewBlocklist, $imagePreviewEnabled);
            $openName = basename($openFilePath);
            $openMtime = @filemtime($openReal);
            $openMtimeFormatted = ($openMtime !== false && $openMtime >= 0 && $openMtime <= 2147483647) ? (@date('Y-m-d H:i', (int) $openMtime) ?: '—') : '—';
            $openDownloadUrl = listingEntryDownloadUrl($openFilePath, $openExt, $inShareMode, $indexHref, $previewBlocklist);
            $openDirectUrl = $inShareMode ? currentListingUrl($indexHref, $openFilePath, ['download' => '1']) : directEntryUrl($openFilePath);
            $openPerms = formatEntryPermissions($openReal) ?? '';
            $openType = $openExt !== '' ? '.' . $openExt : '';
            $openMetaUrl = currentListingUrl($indexHref, $openFilePath, ['meta' => '1']);
            if ($openPreviewKind === 'text') {
                $openFileForModal = [
                    'content_url' => currentListingUrl($indexHref, $openFilePath, ['content' => '1']),
                    'name'        => $openName,
                    'open_url'    => $openDirectUrl,
                    'download_url' => $openDownloadUrl,
                    'share_path'  => $openFilePath,
                    'size'        => formatSize(filesize($openReal)),
                    'mtime'       => $openMtimeFormatted,
                    'perms'       => $openPerms,
                    'type'        => $openType,
                ];
            } elseif ($openPreviewKind === 'image') {
                $openFileForModal = [
                    'image'       => true,
                    'image_url'   => previewImageUrl($indexHref, $openFilePath, $inShareMode),
                    'meta_url'    => $openMetaUrl,
                    'name'        => $openName,
                    'open_url'    => $openDirectUrl,
                    'download_url' => $openDownloadUrl,
                    'share_path'  => $openFilePath,
                    'size'        => formatSize(filesize($openReal)),
                    'mtime'       => $openMtimeFormatted,
                    'perms'       => $openPerms,
                    'type'        => $openType,
                ];
            } else {
                $openFileForModal = [
                    'binary'       => true,
                    'meta_url'     => $openMetaUrl,
                    'name'         => $openName,
                    'download_url' => $openDownloadUrl,
                    'size'         => formatSize(filesize($openReal)),
                    'mtime'        => $openMtimeFormatted,
                    'perms'        => $openPerms,
                    'type'         => $openType,
                    'icon_html'    => fileTypeIconHtml($openExt, false),
                    'share_path'   => $openFilePath,
                ];
            }
        } elseif (is_link($openAbsPath) && isBrokenSymbolicLink($openAbsPath) && metaRequestAllowed($openAbsPath, $openReal, $realBase, $allowOutside)) {
            $openName = basename($openFilePath);
            $openLinkTarget = @readlink($openAbsPath);
            $openMtime = @filemtime($openAbsPath);
            $openMtimeFormatted = ($openMtime !== false && $openMtime >= 0 && $openMtime <= 2147483647) ? (@date('Y-m-d H:i', (int) $openMtime) ?: '—') : '—';
            $openExt = strtolower(pathinfo($openFilePath, PATHINFO_EXTENSION));
            $openFileForModal = [
                'binary'       => true,
                'broken_link'  => true,
                'link_target'  => ($openLinkTarget !== false && $openLinkTarget !== '') ? $openLinkTarget : null,
                'meta_url'     => currentListingUrl($indexHref, $openFilePath, ['meta' => '1']),
                'name'         => $openName,
                'size'         => '—',
                'mtime'        => $openMtimeFormatted,
                'perms'        => formatEntryPermissions($openAbsPath) ?? '',
                'type'         => $openExt !== '' ? '.' . $openExt : '',
                'icon_html'    => fileTypeIconHtml($openExt, false),
                'share_path'   => $openFilePath,
            ];
        } elseif (!$allowOutside && !$blockedMessage && $openReal !== false && is_file($openReal) && !pathUnderBase($openReal, $realBase)) {
            $blockedMessage = 'That link points outside the index and cannot be opened.';
        }
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

function fileMtimeFormatted($mtime) {
    if ($mtime === false || $mtime === null || $mtime < 0 || $mtime > 2147483647) {
        return '—';
    }
    $formatted = @date('Y-m-d H:i', (int) $mtime);
    return ($formatted !== false) ? $formatted : '—';
}

function fileHashNullSet() {
    return ['crc32' => null, 'md5' => null, 'sha1' => null, 'sha256' => null, 'sha512' => null];
}

function fileHashAlgorithmMap($includeSha256Sha512 = false) {
    $algos = ['crc32b' => 'crc32', 'md5' => 'md5', 'sha1' => 'sha1'];
    if ($includeSha256Sha512) {
        $algos['sha256'] = 'sha256';
        $algos['sha512'] = 'sha512';
    }
    return $algos;
}

function fileHashInitContexts($includeSha256Sha512 = false) {
    $contexts = [];
    foreach (fileHashAlgorithmMap($includeSha256Sha512) as $algo => $key) {
        $ctx = @hash_init($algo);
        if ($ctx === false) {
            return null;
        }
        $contexts[$key] = $ctx;
    }
    return $contexts;
}

function fileHashFinalizeContexts(array $contexts) {
    $hashes = [];
    foreach ($contexts as $key => $ctx) {
        $hashes[$key] = hash_final($ctx);
    }
    return $hashes;
}

function fileContentHashes($data, $includeSha256Sha512 = false) {
    $contexts = fileHashInitContexts($includeSha256Sha512);
    if ($contexts === null) {
        return fileHashNullSet();
    }
    foreach ($contexts as $ctx) {
        hash_update($ctx, $data);
    }
    return fileHashFinalizeContexts($contexts);
}

function filePathHashError($absolutePath) {
    if (isBrokenSymbolicLink($absolutePath)) {
        return 'File does not exist (broken symbolic link).';
    }
    if (!is_file($absolutePath)) {
        return 'File does not exist.';
    }
    $fh = @fopen($absolutePath, 'rb');
    if ($fh === false) {
        return 'File is not readable.';
    }
    fclose($fh);
    return null;
}

function filePathHashes($absolutePath, $includeSha256Sha512 = false) {
    $contexts = fileHashInitContexts($includeSha256Sha512);
    if ($contexts === null) {
        return fileHashNullSet();
    }
    $fh = @fopen($absolutePath, 'rb');
    if ($fh === false) {
        return fileHashNullSet();
    }
    while (($chunk = fread($fh, 1048576)) !== false && $chunk !== '') {
        foreach ($contexts as $ctx) {
            hash_update($ctx, $chunk);
        }
    }
    fclose($fh);
    return fileHashFinalizeContexts($contexts);
}

function fileMetadataJson($absolutePath, $relativePath, $ext, $includeHashes = true, $includeSha256Sha512 = false) {
    $fileSize = @filesize($absolutePath);
    $fileSizeValue = ($fileSize !== false && $fileSize >= 0) ? (int) $fileSize : null;
    $fileMtime = @filemtime($absolutePath);
    $meta = [
        'name'            => basename($relativePath),
        'size'            => $fileSizeValue,
        'size_formatted'  => formatSize($fileSizeValue),
        'mtime'           => $fileMtime !== false ? (int) $fileMtime : null,
        'mtime_formatted' => fileMtimeFormatted($fileMtime !== false ? (int) $fileMtime : null),
        'perms'           => formatEntryPermissions($absolutePath) ?? '',
        'ext'             => $ext,
    ];
    if (isBrokenSymbolicLink($absolutePath)) {
        $meta['broken_link'] = true;
        $linkTarget = @readlink($absolutePath);
        if ($linkTarget !== false && $linkTarget !== '') {
            $meta['link_target'] = $linkTarget;
        }
    }
    if ($includeHashes) {
        $hashError = filePathHashError($absolutePath);
        if ($hashError !== null) {
            $meta['error'] = $hashError;
        } else {
            $meta['hashes'] = filePathHashes($absolutePath, $includeSha256Sha512);
        }
    }
    return $meta;
}

function formatEntryOwner($path) {
    $uid = @fileowner($path);
    if ($uid === false) {
        return null;
    }
    if (function_exists('posix_getpwuid')) {
        $account = @posix_getpwuid($uid);
        if (is_array($account) && !empty($account['name'])) {
            return (string) $account['name'];
        }
    }
    return (string) (int) $uid;
}

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function markdownIsTableRowLine($line) {
    $trimmed = trim((string) $line);
    return $trimmed !== '' && $trimmed[0] === '|' && strpos($trimmed, '|', 1) !== false;
}

function markdownIsTableSeparatorLine($line) {
    return (bool) preg_match('/^\|(?:\s*:?-+:?\s*\|)+\s*$/', trim((string) $line));
}

function markdownParseTableCells($line) {
    $trimmed = trim((string) $line);
    if ($trimmed !== '' && $trimmed[0] === '|') {
        $trimmed = substr($trimmed, 1);
    }
    if ($trimmed !== '' && substr($trimmed, -1) === '|') {
        $trimmed = substr($trimmed, 0, -1);
    }
    return array_map('trim', explode('|', $trimmed));
}

function markdownTableAlignments($separatorLine) {
    $aligns = [];
    foreach (markdownParseTableCells($separatorLine) as $cell) {
        if (preg_match('/^:-+:$/', $cell)) {
            $aligns[] = 'center';
        } elseif (preg_match('/^-+:$/', $cell)) {
            $aligns[] = 'right';
        } elseif (preg_match('/^:-+$/', $cell)) {
            $aligns[] = 'left';
        } else {
            $aligns[] = null;
        }
    }
    return $aligns;
}

function markdownRenderTableHtml(array $rowLines, $h) {
    if ($rowLines === []) {
        return '';
    }
    $hasHeader = count($rowLines) >= 2 && markdownIsTableSeparatorLine($rowLines[1]);
    $aligns = $hasHeader ? markdownTableAlignments($rowLines[1]) : [];
    $cellAlign = function ($index) use ($aligns) {
        if (!isset($aligns[$index]) || $aligns[$index] === null) {
            return '';
        }
        return ' style="text-align:' . $aligns[$index] . ';"';
    };
    $html = '<div class="md-table-wrap"><table>' . "\n";
    $bodyStart = 0;
    if ($hasHeader) {
        $html .= "<thead><tr>\n";
        foreach (markdownParseTableCells($rowLines[0]) as $i => $cell) {
            $html .= '<th' . $cellAlign($i) . '>' . markdownInline($cell, $h) . "</th>\n";
        }
        $html .= "</tr></thead>\n<tbody>\n";
        $bodyStart = 2;
    } else {
        $html .= "<tbody>\n";
    }
    for ($r = $bodyStart; $r < count($rowLines); $r++) {
        if (markdownIsTableSeparatorLine($rowLines[$r])) {
            continue;
        }
        $html .= "<tr>\n";
        foreach (markdownParseTableCells($rowLines[$r]) as $i => $cell) {
            $html .= '<td' . $cellAlign($i) . '>' . markdownInline($cell, $h) . "</td>\n";
        }
        $html .= "</tr>\n";
    }
    $html .= "</tbody></table></div>\n";
    return $html;
}

/**
 * Simple markdown to HTML (headers, emphasis, links, images, code, lists, blockquotes, rules, tables).
 */
function markdownToHtml($text) {
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $lines = preg_split('/\R/', (string) $text);
    $out = '';
    $inBlock = false;
    $listType = null;
    $inBlockquote = false;
    $tableBuffer = [];
    $flushTable = function () use (&$tableBuffer, &$out, $h) {
        if ($tableBuffer !== []) {
            $out .= markdownRenderTableHtml($tableBuffer, $h);
            $tableBuffer = [];
        }
    };
    $closeList = function () use (&$listType, &$out) {
        if ($listType !== null) {
            $out .= "</{$listType}>\n";
            $listType = null;
        }
    };
    $closeBlockquote = function () use (&$inBlockquote, &$out) {
        if ($inBlockquote) {
            $out .= "</blockquote>\n";
            $inBlockquote = false;
        }
    };
    foreach ($lines as $line) {
        if (preg_match('/^```(\w*)\s*$/', $line, $m)) {
            $flushTable();
            $closeList();
            $closeBlockquote();
            if ($inBlock) {
                $out .= "</code></pre>\n";
                $inBlock = false;
            } else {
                $lang = $m[1] !== '' ? $m[1] : 'plaintext';
                $out .= '<pre class="hljs"><code class="language-' . $h($lang) . '">';
                $inBlock = true;
            }
            continue;
        }
        if ($inBlock) {
            $out .= $h($line) . "\n";
            continue;
        }
        if (markdownIsTableRowLine($line)) {
            $closeList();
            $closeBlockquote();
            $tableBuffer[] = $line;
            continue;
        }
        $flushTable();
        if (preg_match('/^(\*{3,}|-{3,}|_{3,})\s*$/', $line)) {
            $closeList();
            $closeBlockquote();
            $out .= "<hr>\n";
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
            $closeList();
            $closeBlockquote();
            $level = strlen($m[1]);
            $out .= "<h{$level}>" . markdownInline($m[2], $h) . "</h{$level}>\n";
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $closeList();
            if (!$inBlockquote) {
                $out .= "<blockquote>\n";
                $inBlockquote = true;
            }
            if (trim($m[1]) !== '') {
                $out .= '<p>' . markdownInline($m[1], $h) . "</p>\n";
            }
            continue;
        }
        $closeBlockquote();
        if (preg_match('/^[\-\*]\s+\[([ xX])\]\s+(.+)$/', $line, $m)) {
            if ($listType !== 'ul') {
                $closeList();
                $out .= "<ul>\n";
                $listType = 'ul';
            }
            $checked = (strtolower($m[1]) === 'x');
            $out .= '<li class="task-list-item">'
                . '<input type="checkbox" class="task-list-item-checkbox" disabled' . ($checked ? ' checked' : '') . '> '
                . markdownInline($m[2], $h) . "</li>\n";
            continue;
        }
        if (preg_match('/^[\-\*]\s+(.+)$/', $line, $m)) {
            if ($listType !== 'ul') {
                $closeList();
                $out .= "<ul>\n";
                $listType = 'ul';
            }
            $out .= '<li>' . markdownInline($m[1], $h) . "</li>\n";
            continue;
        }
        if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
            if ($listType !== 'ol') {
                $closeList();
                $out .= "<ol>\n";
                $listType = 'ol';
            }
            $out .= '<li>' . markdownInline($m[1], $h) . "</li>\n";
            continue;
        }
        if (trim($line) === '') {
            $closeList();
            $out .= "\n";
            continue;
        }
        $closeList();
        $out .= '<p>' . markdownInline($line, $h) . "</p>\n";
    }
    $flushTable();
    $closeList();
    $closeBlockquote();
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
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function ($m) use ($h) {
        $url = markdownSafeUrl($m[2]);
        if ($url === null) {
            return $h('![' . $m[1] . '](' . $m[2] . ')');
        }
        return '<img src="' . $h($url) . '" alt="' . $m[1] . '" loading="lazy">';
    }, $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) use ($h) {
        $url = markdownSafeUrl($m[2]);
        $href = $url !== null ? $h($url) : '#';
        return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $s);
    return $s;
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
            --ft-dir: #22d3ee;
            --ft-archive: #fbbf24;
            --ft-image: #34d399;
            --ft-video: #c084fc;
            --ft-audio: #f472b6;
            --ft-pdf: #f87171;
            --ft-spreadsheet: #4ade80;
            --ft-document: #60a5fa;
            --ft-presentation: #fb923c;
            --ft-code: #818cf8;
            --ft-executable: #f87171;
            --ft-file: #a1a1aa;
            --ft-symlink: #a78bfa;
            --ft-symlink-broken: #f87171;
            --hover: #27272a;
            --md-pre-bg: #282c34;
            --md-code-bg: color-mix(in srgb, var(--text) 8%, var(--bg-card));
            --md-quote: color-mix(in srgb, var(--text) 70%, var(--text-muted));
            --md-th-bg: color-mix(in srgb, var(--bg) 40%, var(--bg-card));
        }
        html.theme-light {
            --bg: #fafafa;
            --bg-card: #ffffff;
            --border: #e4e4e7;
            --text: #18181b;
            --text-muted: #71717a;
            --md-pre-bg: #f6f8fa;
            --md-code-bg: color-mix(in srgb, var(--text) 7%, var(--bg-card));
            --md-quote: color-mix(in srgb, var(--text) 78%, var(--text-muted));
            --md-th-bg: color-mix(in srgb, var(--text) 6%, var(--bg-card));
            --accent: #7c3aed;
            --accent-dim: #5b21b6;
            --dir-color: #0891b2;
            --ft-dir: #0891b2;
            --ft-archive: #d97706;
            --ft-image: #059669;
            --ft-video: #9333ea;
            --ft-audio: #db2777;
            --ft-pdf: #dc2626;
            --ft-spreadsheet: #16a34a;
            --ft-document: #2563eb;
            --ft-presentation: #ea580c;
            --ft-code: #4f46e5;
            --ft-executable: #dc2626;
            --ft-file: #71717a;
            --ft-symlink: #7c3aed;
            --ft-symlink-broken: #dc2626;
            --hover: #f4f4f5;
        }
        html {
            font-size: 15px;
        }
        html.font-xs { font-size: 13px; }
        html.font-sm { font-size: 14px; }
        html.font-lg { font-size: 17px; }
        html.font-xl { font-size: 19px; }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: 'Outfit', system-ui, sans-serif;
            font-size: 1rem;
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
        .entry-delete {
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
        .listing tr:hover .entry-delete,
        .entry-share:focus-visible,
        .entry-delete:focus-visible { opacity: 1; }
        .entry-share:hover { color: var(--accent); border-color: var(--accent-dim); background: var(--bg); }
        .entry-delete:hover { color: #f87171; border-color: color-mix(in srgb, #f87171 45%, var(--border)); background: color-mix(in srgb, #f87171 10%, var(--bg)); }
        .entry-share svg,
        .entry-delete svg { width: 0.95rem; height: 0.95rem; }
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
        .btn-share-sm.danger-outline {
            color: #f87171;
            border-color: color-mix(in srgb, #f87171 45%, var(--border));
            background: transparent;
        }
        .btn-share-sm.danger-outline:hover {
            color: #f87171;
            border-color: color-mix(in srgb, #f87171 45%, var(--border));
            background: color-mix(in srgb, #f87171 10%, var(--bg));
        }
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
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.8125rem;
        }
        .breadcrumb a {
            display: inline-block;
            color: var(--accent);
            background: color-mix(in srgb, var(--accent) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--accent) 22%, transparent);
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            text-decoration: none;
            line-height: 1.35;
            transition: background 0.15s, border-color 0.15s;
        }
        .breadcrumb a:hover {
            text-decoration: none;
            background: color-mix(in srgb, var(--accent) 20%, transparent);
            border-color: color-mix(in srgb, var(--accent) 35%, transparent);
        }
        .breadcrumb-sep {
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 500;
            opacity: 0.55;
            user-select: none;
        }

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
            content: 'Drop files to upload';
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
        .listing-toolbar-start {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-right: auto;
        }
        .listing-col-picker {
            position: relative;
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
        .listing-col-picker-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .listing-col-picker-btn:disabled:hover {
            color: var(--text-muted);
            border-color: var(--border);
            background: transparent;
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
        .btn-listing-tool .icon {
            width: 0.9rem;
            height: 0.9rem;
            opacity: 0.85;
            flex-shrink: 0;
        }
        .btn-listing-tool:hover {
            color: var(--text);
            border-color: var(--text-muted);
            background: var(--hover);
        }
        a.btn-listing-tool {
            text-decoration: none;
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
        .listing th.owner { text-align: right; }
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
        .listing th.perms .listing-sort-btn,
        .listing th.owner .listing-sort-btn {
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
        .listing table.listing-hide-owner th.owner,
        .listing table.listing-hide-owner td.owner,
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
            min-width: 0;
            flex-wrap: wrap;
        }
        .listing .name a:hover { filter: brightness(1.12); }
        .listing .name.ft-type--dir a { color: var(--ft-dir); }
        .listing .name.ft-type--archive a { color: var(--ft-archive); }
        .listing .name.ft-type--image a { color: var(--ft-image); }
        .listing .name.ft-type--video a { color: var(--ft-video); }
        .listing .name.ft-type--audio a { color: var(--ft-audio); }
        .listing .name.ft-type--pdf a { color: var(--ft-pdf); }
        .listing .name.ft-type--spreadsheet a { color: var(--ft-spreadsheet); }
        .listing .name.ft-type--document a { color: var(--ft-document); }
        .listing .name.ft-type--presentation a { color: var(--ft-presentation); }
        .listing .name.ft-type--code a { color: var(--ft-code); }
        .listing .name.ft-type--executable a { color: var(--ft-executable); }
        .listing .name.ft-type--file a { color: var(--ft-file); }
        .listing .name.ft-type--symlink a { color: var(--ft-symlink); }
        .listing .name.ft-type--symlink-broken a { color: var(--ft-symlink-broken); }
        .listing .name.ft-type--symlink-broken .entry-name { text-decoration: line-through; text-decoration-color: color-mix(in srgb, var(--ft-symlink-broken) 55%, transparent); }
        .entry-broken-badge {
            display: inline-block;
            flex-shrink: 0;
            margin-left: 0.15rem;
            padding: 0.05rem 0.35rem;
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: var(--ft-symlink-broken);
            border: 1px solid color-mix(in srgb, var(--ft-symlink-broken) 45%, transparent);
            border-radius: 4px;
            vertical-align: middle;
            line-height: 1.3;
            white-space: nowrap;
        }
        .listing .name a.file-preview,
        .listing .name a.file-binary { cursor: pointer; }
        .entry-name {
            min-width: 0;
            word-break: break-word;
        }
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
            display: inline-flex;
            align-items: center;
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
        .modal-title-wrap { display: flex; align-items: center; gap: 0.45rem; flex: 1; min-width: 0; flex-wrap: wrap; }
        .modal-title { font-family: 'JetBrains Mono', monospace; font-size: 0.9rem; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .modal-header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        .modal-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.65rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: transparent;
            color: var(--text-muted);
            font-size: 0.8rem;
            font-family: inherit;
            line-height: 1.2;
            white-space: nowrap;
            cursor: pointer;
            text-decoration: none;
            transition: color 0.15s, border-color 0.15s, background 0.15s;
        }
        .modal-action-btn:hover,
        .modal-action-btn:focus-visible {
            color: var(--accent);
            border-color: var(--accent-dim);
            background: var(--hover);
            outline: none;
        }
        .modal-action-btn[hidden] { display: none !important; }
        .modal-action-btn svg { width: 0.9rem; height: 0.9rem; flex-shrink: 0; }
        .modal-close { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0.25rem; line-height: 1; border-radius: 4px; }
        .modal-close:hover { color: var(--text); background: var(--hover); }
        .modal-body { overflow: auto; padding: 1rem; flex: 1; min-height: 0; }
        .modal-body pre { margin: 0; font-size: 0.85rem; }
        .modal-body code { font-family: 'JetBrains Mono', monospace; }
        .modal-body .modal-md { display: none; color: var(--text); }
        .modal-body .modal-md.is-visible { display: block; }
        .modal-body .modal-md h1, .modal-body .modal-md h2, .modal-body .modal-md h3,
        .modal-body .modal-md h4, .modal-body .modal-md h5, .modal-body .modal-md h6 { margin-top: 1em; margin-bottom: 0.5em; line-height: 1.3; color: var(--text); }
        .modal-body .modal-md p { margin: 0.5em 0; line-height: 1.6; }
        .modal-body .modal-md a { color: var(--accent); text-decoration: none; }
        .modal-body .modal-md a:hover { text-decoration: underline; }
        .modal-body .modal-md :not(pre) > code { font-family: 'JetBrains Mono', monospace; font-size: 0.9em; background: var(--md-code-bg); color: var(--text); padding: 0.15em 0.35em; border-radius: 4px; }
        .modal-body .modal-md pre,
        .modal-body .modal-md pre.hljs { background: var(--md-pre-bg) !important; border: 1px solid var(--border); border-radius: 8px; padding: 0.75rem 1rem; margin: 0.75em 0; overflow: auto; font-size: 0.85rem; line-height: 1.55; max-height: min(60vh, 720px); }
        .modal-body .modal-md pre code { display: block; background: none; padding: 0; white-space: pre; }
        .modal-body .modal-md blockquote { margin: 0.75em 0; padding: 0.25em 0 0.25em 1rem; border-left: 3px solid var(--accent-dim); color: var(--md-quote); }
        .modal-body .modal-md hr { border: none; border-top: 1px solid var(--border); margin: 1.25em 0; }
        .modal-body .modal-md img { max-width: 100%; height: auto; border-radius: 8px; }
        .modal-body .modal-md ul, .modal-body .modal-md ol { margin: 0.5em 0; padding-left: 1.5rem; }
        .modal-body .modal-md .task-list-item { list-style: none; margin-left: -1.5rem; }
        .modal-body .modal-md .task-list-item-checkbox { margin: 0 0.4em 0 0; vertical-align: middle; cursor: default; width: 1.1em; height: 1.1em; border: 1px solid var(--text-muted); background: var(--bg); border-radius: 3px; accent-color: var(--accent); }
        .modal-body .modal-md .task-list-item-checkbox:checked { background: var(--accent-dim); border-color: var(--accent); }
        .modal-body .modal-md .md-table-wrap { overflow-x: auto; margin: 0.75em 0; }
        .modal-body .modal-md table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
        .modal-body .modal-md th, .modal-body .modal-md td { border: 1px solid var(--border); padding: 0.4em 0.65em; text-align: left; vertical-align: top; color: var(--text); }
        .modal-body .modal-md th { background: var(--md-th-bg); font-weight: 600; }
        .modal-file-meta {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border);
            background: color-mix(in srgb, var(--bg) 50%, transparent);
            flex-shrink: 0;
        }
        .modal-file-meta[hidden] { display: none !important; }
        .modal-file-meta-primary {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem 1.75rem;
        }
        .modal-file-meta-item { display: flex; flex-direction: column; gap: 0.2rem; min-width: 4.5rem; }
        .modal-file-meta-label {
            font-size: 0.68rem;
            font-weight: 500;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .modal-file-meta-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            color: var(--text);
            word-break: break-word;
        }
        .modal-file-meta-hashes { width: 100%; min-width: 0; }
        .modal-file-meta-hashes-summary {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            cursor: pointer;
            list-style: none;
            user-select: none;
            font-size: 0.68rem;
            font-weight: 500;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .modal-file-meta-hashes-summary::-webkit-details-marker { display: none; }
        .modal-file-meta-hashes-summary::before {
            content: '';
            width: 0.4rem;
            height: 0.4rem;
            border-right: 2px solid var(--text-muted);
            border-bottom: 2px solid var(--text-muted);
            transform: rotate(-45deg);
            transition: transform 0.15s ease;
            flex-shrink: 0;
        }
        .modal-file-meta-hashes[open] > .modal-file-meta-hashes-summary::before {
            transform: rotate(45deg);
        }
        .modal-file-meta-hashes-body {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            margin-top: 0.65rem;
            padding-top: 0.65rem;
            border-top: 1px solid var(--border);
        }
        .modal-file-meta-item--wide { width: 100%; min-width: 0; }
        .modal-file-meta-item--wide .modal-file-meta-value { font-size: 0.75rem; word-break: break-all; }
        .modal-file-meta-hashes-hint {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.82rem;
        }
        .modal-file-meta-hashes-error { color: var(--msg-error, #f87171); }
        .modal.is-binary { width: min(520px, 95vw); }
        .modal.is-image { width: min(900px, 95vw); }
        .modal.is-markdown { width: min(800px, 95vw); }
        .modal-binary[hidden] { display: none !important; }
        #modal-binary-download[hidden] { display: none !important; }
        .modal-binary-header { display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; }
        .modal-binary-text { min-width: 0; }
        .modal-binary-name { margin: 0; font-size: 1.25rem; font-weight: 600; word-break: break-word; }
        #modal-broken-badge[hidden],
        #modal-broken-notice[hidden] {
            display: none !important;
        }
        .modal-broken-notice {
            margin: 0 0 1rem;
            padding: 0.65rem 0.85rem;
            border: 1px solid color-mix(in srgb, var(--ft-symlink-broken) 35%, transparent);
            border-radius: 8px;
            background: color-mix(in srgb, var(--ft-symlink-broken) 10%, transparent);
            color: var(--ft-symlink-broken);
            font-size: 0.9rem;
            word-break: break-word;
        }
        .modal-image[hidden] { display: none !important; }
        .modal-image { text-align: center; }
        .modal-image img { max-width: 100%; max-height: min(75vh, 960px); width: auto; height: auto; object-fit: contain; border-radius: 8px; }
        .ft-icon {
            position: relative;
            flex-shrink: 0;
            width: 3.5rem;
            height: 3.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: color-mix(in srgb, var(--ft-file) 14%, transparent);
            color: var(--ft-file);
        }
        .ft-icon--listing {
            width: 1.65rem;
            height: 1.65rem;
            border-radius: 6px;
        }
        .ft-icon svg { width: 2.5rem; height: 2.5rem; }
        .ft-icon--listing svg { width: 1.05rem; height: 1.05rem; }
        .ft-icon__label { position: absolute; left: 50%; bottom: 0.55rem; transform: translateX(-50%); font-size: 0.55rem; font-weight: 700; letter-spacing: 0.02em; line-height: 1; }
        .ft-icon--listing .ft-icon__label { font-size: 0.36rem; bottom: 0.18rem; letter-spacing: 0; }
        .ft-icon--dir { background: color-mix(in srgb, var(--ft-dir) 14%, transparent); color: var(--ft-dir); }
        .ft-icon--archive { background: color-mix(in srgb, var(--ft-archive) 14%, transparent); color: var(--ft-archive); }
        .ft-icon--image { background: color-mix(in srgb, var(--ft-image) 14%, transparent); color: var(--ft-image); }
        .ft-icon--video { background: color-mix(in srgb, var(--ft-video) 14%, transparent); color: var(--ft-video); }
        .ft-icon--audio { background: color-mix(in srgb, var(--ft-audio) 14%, transparent); color: var(--ft-audio); }
        .ft-icon--pdf { background: color-mix(in srgb, var(--ft-pdf) 14%, transparent); color: var(--ft-pdf); }
        .ft-icon--spreadsheet { background: color-mix(in srgb, var(--ft-spreadsheet) 14%, transparent); color: var(--ft-spreadsheet); }
        .ft-icon--document { background: color-mix(in srgb, var(--ft-document) 14%, transparent); color: var(--ft-document); }
        .ft-icon--presentation { background: color-mix(in srgb, var(--ft-presentation) 14%, transparent); color: var(--ft-presentation); }
        .ft-icon--code { background: color-mix(in srgb, var(--ft-code) 14%, transparent); color: var(--ft-code); }
        .ft-icon--executable { background: color-mix(in srgb, var(--ft-executable) 14%, transparent); color: var(--ft-executable); }
        .ft-icon--file { background: color-mix(in srgb, var(--ft-file) 14%, transparent); color: var(--ft-file); }
        .btn-download { display: inline-block; background: var(--accent-dim); color: #fff; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; }
        .btn-download:hover { background: var(--accent); color: #fff; }

        .listing .size, .listing .modified, .listing .owner, .listing .perms {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .listing .size { text-align: right; }
        .listing .col-size { min-width: 5.5rem; }
        .listing td.size, .listing th.size { white-space: nowrap; }
        .listing .col-modified { min-width: 10rem; }
        .listing td.modified, .listing th.modified { white-space: nowrap; }
        .listing .owner { text-align: right; }
        .listing .col-owner { min-width: 5.5rem; }
        .listing td.owner, .listing th.owner { white-space: nowrap; }
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
        .footer-about-link {
            background: none;
            border: none;
            padding: 0;
            font: inherit;
            font-size: inherit;
            color: inherit;
            cursor: pointer;
            text-decoration: underline;
            text-decoration-color: transparent;
            transition: color 0.15s ease, text-decoration-color 0.15s ease;
        }
        .footer-about-link:hover,
        .footer-about-link:focus-visible {
            color: var(--accent);
            text-decoration-color: currentColor;
        }
        .about-intro {
            margin: 0 0 1rem;
            line-height: 1.55;
            color: var(--text);
        }
        .about-meta {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.35rem 1rem;
            margin: 0 0 1.25rem;
            font-size: 0.9rem;
        }
        .about-meta dt {
            margin: 0;
            color: var(--text-muted);
        }
        .about-meta dd {
            margin: 0;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.85rem;
            word-break: break-word;
        }
        .about-links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 0.75rem;
        }
        .about-links a {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: var(--accent);
            text-decoration: none;
            font-size: 0.9rem;
        }
        .about-links a svg {
            width: 0.95rem;
            height: 0.95rem;
            flex-shrink: 0;
        }
        .about-links a:hover { text-decoration: underline; }
        .about-update {
            margin: 0 0 1.25rem;
            padding: 0.85rem 0.95rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
        }
        .about-update-status {
            margin: 0 0 0.75rem;
            font-size: 0.875rem;
            line-height: 1.5;
            color: var(--text);
        }
        .about-update-status.is-muted { color: var(--text-muted); }
        .about-update-status.is-success { color: #4ade80; }
        .about-update-status.is-warning { color: #fbbf24; }
        .about-update-status.is-error { color: #f87171; }
        .about-update-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .about-update-actions .btn-auth { font-size: 0.85rem; padding: 0.45rem 0.7rem; }
        .about-update-actions .btn-auth[hidden] { display: none; }
        .about-update-channel {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 0 0.75rem;
            font-size: 0.85rem;
        }
        .about-update-channel label {
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .about-update-channel select {
            flex: 1;
            min-width: 0;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-card);
            color: var(--text);
            padding: 0.4rem 0.55rem;
            font: inherit;
        }

        .settings-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1001; align-items: center; justify-content: center; padding: 2rem; box-sizing: border-box; }
        .settings-overlay.is-open { display: flex; }
        .settings-modal { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; width: 100%; max-width: 680px; max-height: 88vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .settings-modal-message {
            margin: 0;
            font-size: 0.875rem;
            line-height: 1.45;
        }
        .settings-modal-message--alert {
            margin: 0 0 0.85rem;
        }
        .settings-modal-message--toast {
            position: fixed;
            bottom: 1.75rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1002;
            width: min(420px, calc(100vw - 2rem));
            margin: 0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.28);
            pointer-events: none;
            animation: settingsToastIn 0.22s ease;
        }
        .settings-modal-message--toast.is-hiding {
            animation: settingsToastOut 0.25s ease forwards;
        }
        @keyframes settingsToastIn {
            from { opacity: 0; transform: translateX(-50%) translateY(0.5rem); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        @keyframes settingsToastOut {
            from { opacity: 1; transform: translateX(-50%) translateY(0); }
            to { opacity: 0; transform: translateX(-50%) translateY(0.5rem); }
        }
        .settings-modal .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
        .settings-modal .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            flex-shrink: 0;
        }
        .settings-modal .modal-title { font-size: 1rem; font-weight: 600; }
        .settings-modal .modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; min-height: 0; }
        .settings-main-panel { max-width: 880px; }
        .login-modal-panel,
        .account-modal-panel,
        .share-modal-panel,
        .about-modal-panel { max-width: 440px; }
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
        .login-modal-panel .auth-form,
        .account-modal-panel .settings-form {
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
        .btn-auth svg {
            width: 1rem;
            height: 1rem;
            flex-shrink: 0;
        }
        .btn-auth.btn-auth-icon {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
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
        .btn-auth-danger {
            border-color: color-mix(in srgb, #f87171 55%, var(--accent-dim));
            background: color-mix(in srgb, #f87171 88%, var(--accent-dim));
        }
        .btn-auth-danger:hover {
            filter: brightness(1.08);
        }
        .confirm-modal-panel { max-width: 420px; }
        .confirm-modal-message {
            margin: 0 0 0.75rem;
            color: var(--text);
            line-height: 1.5;
        }
        .confirm-modal-target {
            margin: 0 0 0.75rem;
            padding: 0.55rem 0.65rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text);
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.85rem;
            word-break: break-word;
            line-height: 1.4;
        }
        .confirm-modal-target[hidden] { display: none !important; }
        .confirm-modal-detail {
            margin: 0 0 1rem;
        }
        .confirm-modal-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 0.65rem;
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
        .upload-form {
            display: grid;
            gap: 1rem;
        }
        .upload-dropzone {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            min-height: 8.5rem;
            padding: 1.5rem 1.25rem;
            border: 2px dashed var(--border);
            border-radius: 12px;
            background: color-mix(in srgb, var(--bg) 55%, transparent);
            text-align: center;
            transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
        }
        .upload-dropzone:hover,
        .upload-dropzone.is-dragover {
            border-color: var(--accent-dim);
            background: color-mix(in srgb, var(--accent) 10%, var(--bg-card));
            box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 18%, transparent);
        }
        .upload-dropzone.has-file {
            border-style: solid;
            border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
            background: color-mix(in srgb, var(--accent) 6%, var(--bg-card));
        }
        .upload-file-input {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .upload-dropzone-icon {
            width: 2.25rem;
            height: 2.25rem;
            color: var(--accent);
            opacity: 0.9;
        }
        .upload-dropzone-title {
            margin: 0;
            color: var(--text);
            font-size: 0.95rem;
            font-weight: 600;
        }
        .upload-dropzone-hint {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.82rem;
        }
        .upload-browse-btn {
            margin-top: 0.35rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.4rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-card);
            color: var(--text);
            font: inherit;
            font-size: 0.85rem;
            cursor: pointer;
            transition: color 0.15s, border-color 0.15s, background 0.15s;
        }
        .upload-browse-btn:hover,
        .upload-browse-btn:focus-visible {
            color: var(--accent);
            border-color: var(--accent-dim);
            background: var(--hover);
            outline: none;
        }
        .upload-browse-btn-secondary {
            background: transparent;
        }
        .upload-browse-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
        }
        .upload-file-name {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            max-width: 100%;
            margin-top: 0.35rem;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            background: color-mix(in srgb, var(--accent) 14%, transparent);
            color: var(--text);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            line-height: 1.3;
        }
        .upload-file-name:empty {
            display: none;
        }
        .upload-file-name svg {
            flex-shrink: 0;
            width: 0.95rem;
            height: 0.95rem;
            color: var(--accent);
        }
        .upload-file-name-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .upload-form-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            align-items: center;
            gap: 0.65rem;
        }
        .upload-dir-overwrite {
            margin: 0;
            margin-right: auto;
        }
        .settings-panel {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: color-mix(in srgb, var(--bg) 88%, var(--bg-card));
        }
        .settings-panel + .settings-panel,
        .settings-server-form + .settings-panel,
        .settings-panel + .settings-server-form {
            margin-top: 1rem;
        }
        .settings-panel-summary {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.75rem 1rem;
            cursor: pointer;
            list-style: none;
            user-select: none;
        }
        .settings-panel-summary::-webkit-details-marker { display: none; }
        .settings-panel-summary::before {
            content: '';
            width: 0.45rem;
            height: 0.45rem;
            border-right: 2px solid var(--text-muted);
            border-bottom: 2px solid var(--text-muted);
            transform: rotate(-45deg);
            transition: transform 0.15s ease;
            flex-shrink: 0;
        }
        .settings-panel[open] > .settings-panel-summary::before {
            transform: rotate(45deg);
        }
        .settings-panel-summary-main {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            min-width: 0;
            flex: 1;
        }
        .settings-panel-title {
            color: var(--text);
            font-size: 0.95rem;
            font-weight: 600;
        }
        .settings-panel-hint {
            color: var(--text-muted);
            font-size: 0.78rem;
            font-weight: 400;
            line-height: 1.35;
        }
        .settings-panel-body {
            display: grid;
            gap: 1rem;
            padding: 1rem 1rem 1.25rem;
            border-top: 1px solid var(--border);
        }
        .settings-panel-body--display {
            gap: 1rem;
        }
        .settings-display-field {
            gap: 0.45rem;
        }
        .settings-display-label {
            color: var(--text);
            font-size: 0.9rem;
            font-weight: 500;
        }
        .display-segmented {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
        }
        .display-segmented--joined {
            flex-wrap: nowrap;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            background: var(--bg);
            gap: 0;
        }
        .display-segment {
            flex: 1 1 auto;
            min-width: 0;
            padding: 0.5rem 0.7rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text-muted);
            font: inherit;
            font-size: 0.82rem;
            line-height: 1.25;
            cursor: pointer;
            white-space: nowrap;
            text-align: center;
        }
        .display-segmented--joined .display-segment {
            border: none;
            border-radius: 0;
            border-right: 1px solid var(--border);
        }
        .display-segmented--joined .display-segment:last-child {
            border-right: none;
        }
        .display-segment:hover {
            color: var(--text);
        }
        .display-segment.is-active {
            background: color-mix(in srgb, var(--accent) 18%, var(--bg-card));
            border-color: var(--accent-dim);
            color: var(--text);
        }
        .display-segmented--joined .display-segment.is-active {
            border-color: transparent;
            box-shadow: inset 0 0 0 1px var(--accent-dim);
        }
        .display-breadcrumb-input {
            max-width: 8rem;
        }
        .settings-panel--danger {
            border-color: color-mix(in srgb, #f87171 35%, var(--border));
        }
        .settings-panel--danger .settings-panel-title {
            color: #f87171;
        }
        .settings-server-form {
            display: grid;
            gap: 0.65rem;
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
    <script>
    (function() {
        try {
            var theme = localStorage.getItem('dirindex_theme') || 'dark';
            var light = theme === 'light' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: light)').matches);
            if (light) document.documentElement.classList.add('theme-light');
            var font = localStorage.getItem('dirindex_font') || 'md';
            if (font === 'normal') font = 'md';
            if (font === 'large') font = 'lg';
            if (font === 'xs' || font === 'sm' || font === 'lg' || font === 'xl') {
                document.documentElement.classList.add('font-' + font);
            }
        } catch (e) {}
    })();
    </script>
</head>
<body class="<?= $setupNeeded ? 'setup-mode' : '' ?>"<?php if ($openFileForModal): ?><?php if (!empty($openFileForModal['binary'])): ?> data-open-binary="1" data-open-name="<?= h($openFileForModal['name']) ?>"<?php if (!empty($openFileForModal['download_url'])): ?> data-open-download-url="<?= h($openFileForModal['download_url']) ?>"<?php endif; ?><?php if (!empty($openFileForModal['broken_link'])): ?> data-open-broken-link="1"<?php endif; ?><?php if (!empty($openFileForModal['link_target'])): ?> data-open-link-target="<?= h($openFileForModal['link_target']) ?>"<?php endif; ?> data-open-size="<?= h($openFileForModal['size']) ?>" data-open-mtime="<?= h($openFileForModal['mtime']) ?>" data-open-perms="<?= h($openFileForModal['perms'] ?? '') ?>" data-open-type="<?= h($openFileForModal['type'] ?? '') ?>" data-open-icon-html="<?= h($openFileForModal['icon_html']) ?>" data-open-meta-url="<?= h($openFileForModal['meta_url'] ?? '') ?>" data-open-share-path="<?= h($openFileForModal['share_path']) ?>"<?php elseif (!empty($openFileForModal['image'])): ?> data-open-image="1" data-open-image-url="<?= h($openFileForModal['image_url']) ?>" data-open-name="<?= h($openFileForModal['name']) ?>" data-open-url="<?= h($openFileForModal['open_url']) ?>"<?php if (!empty($openFileForModal['download_url'])): ?> data-open-download-url="<?= h($openFileForModal['download_url']) ?>"<?php endif; ?> data-open-size="<?= h($openFileForModal['size'] ?? '') ?>" data-open-mtime="<?= h($openFileForModal['mtime'] ?? '') ?>" data-open-perms="<?= h($openFileForModal['perms'] ?? '') ?>" data-open-type="<?= h($openFileForModal['type'] ?? '') ?>" data-open-meta-url="<?= h($openFileForModal['meta_url'] ?? '') ?>" data-open-share-path="<?= h($openFileForModal['share_path']) ?>"<?php else: ?> data-open-content-url="<?= h($openFileForModal['content_url']) ?>" data-open-name="<?= h($openFileForModal['name']) ?>" data-open-url="<?= h($openFileForModal['open_url']) ?>"<?php if (!empty($openFileForModal['download_url'])): ?> data-open-download-url="<?= h($openFileForModal['download_url']) ?>"<?php endif; ?> data-open-size="<?= h($openFileForModal['size'] ?? '') ?>" data-open-mtime="<?= h($openFileForModal['mtime'] ?? '') ?>" data-open-perms="<?= h($openFileForModal['perms'] ?? '') ?>" data-open-type="<?= h($openFileForModal['type'] ?? '') ?>" data-open-share-path="<?= h($openFileForModal['share_path']) ?>"<?php endif; ?><?php endif; ?><?php if ($openLoginModal): ?> data-open-login="1"<?php endif; ?><?php if ($openAccountModal): ?> data-open-account="1"<?php endif; ?><?php if ($openSettingsModal): ?> data-open-settings="1"<?php if ($settingsPanelFocus !== null): ?> data-settings-panel="<?= h($settingsPanelFocus) ?>"<?php endif; ?><?php endif; ?>>
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
                    <label for="setup-web-root">Web root URL</label>
                    <input type="text" id="setup-web-root" name="web_root_url" value="<?= h($webRootUrlDetected) ?>" spellcheck="false" autocomplete="off" placeholder="https://example.com/files/">
                    <span class="field-help">Base URL for <strong>Open in new tab</strong> links. Pre-filled from this request; change it if files are published under a different public URL (e.g. the site root while this index lives in a subdirectory).</span>
                </div>
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
                <?php if ($authenticated && !$inShareMode): ?>
                <button type="button" class="btn-settings" id="btn-account" aria-label="Account" title="Account" aria-haspopup="dialog" aria-controls="account-modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </button>
                <?php endif; ?>
                <button type="button" class="btn-settings" id="btn-about" aria-label="About" title="About" aria-haspopup="dialog" aria-controls="about-modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                </button>
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

        <?php if ($statusMessage && $settingsModalMessage === null): ?>
        <div class="blocked-msg message-<?= h($statusMessage[0]) ?>" role="status">
            <?= h($statusMessage[1]) ?>
        </div>
        <?php endif; ?>

        <?php if ($browseAuthBlocked): ?>
        <div class="blocked-msg" role="status">
            Sign in to browse this directory. Share links are not affected.
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
                    <p>Signed in as <?= h($dirindexConfig['auth_username']) ?>. Uploads are <?= $uploadEnabled ? 'enabled' : 'disabled' ?>. Creating folders/files is <?= $createEnabled ? 'enabled' : 'disabled' ?>. Deleting is <?= $deleteEnabled ? 'enabled' : 'disabled' ?>.</p>
                </div>
                <div class="admin-bar-actions">
                    <?php if ($uploadEnabled): ?>
                    <button type="button" class="btn-auth" id="btn-upload-toggle" aria-expanded="false" aria-controls="upload-panel">Upload</button>
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
                <form class="upload-form" id="upload-form" method="post" enctype="multipart/form-data" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" data-existing-names="<?= h(json_encode($existingNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>" data-dir-max-files="<?= (int) uploadDirMaxFiles() ?>" data-upload-path="/<?= h($relativePath ?: '') ?>">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="upload_mode" id="upload-mode" value="file">
                    <input type="hidden" name="overwrite" id="upload-overwrite" value="">
                    <input type="hidden" name="upload_as" id="upload-as" value="">
                    <div class="upload-dropzone" id="upload-dropzone">
                        <input type="file" id="upload-file" class="upload-file-input" name="upload_file">
                        <input type="file" id="upload-file-dir" class="upload-file-input" name="upload_file[]" multiple webkitdirectory directory disabled>
                        <svg class="upload-dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M12 16V4"/><path d="m8 8 4-4 4 4"/><path d="M4 17v1a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1"/></svg>
                        <p class="upload-dropzone-title" id="upload-dropzone-title">Drop a file here</p>
                        <p class="upload-dropzone-hint" id="upload-dropzone-hint">or choose one from your device</p>
                        <div class="upload-browse-actions">
                            <button type="button" class="upload-browse-btn" id="upload-browse-btn">Browse files</button>
                            <button type="button" class="upload-browse-btn upload-browse-btn-secondary" id="upload-browse-dir-btn">Browse folder</button>
                        </div>
                        <span class="upload-file-name" id="upload-file-name" aria-live="polite"></span>
                    </div>
                    <label class="settings-check-row upload-dir-overwrite" id="upload-dir-overwrite-row" hidden>
                        <input type="checkbox" id="upload-dir-overwrite" value="1">
                        <span>Overwrite existing files in this folder upload</span>
                    </label>
                    <div class="upload-form-actions">
                        <button type="submit" class="btn-auth" id="upload-submit-btn">Upload to /<?= h($relativePath ?: '') ?></button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($authenticated && !$inShareMode && $deleteEnabled): ?>
        <form id="delete-entry-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" hidden>
            <input type="hidden" name="action" value="delete_entry">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="entry_path" id="delete-entry-path" value="">
        </form>
        <?php endif; ?>

        <div class="listing">
            <?php $currentDirNewTabUrl = $inShareMode ? currentListingUrl($indexHref, $relativePath) : directEntryUrl($relativePath, true); ?>
            <div class="listing-toolbar">
                <div class="listing-toolbar-start">
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
                                <input type="checkbox" id="setting-col-owner" checked>
                                Owner
                            </label>
                            <label class="listing-col-picker-option" role="menuitemcheckbox">
                                <input type="checkbox" id="setting-col-perms" checked>
                                Permissions
                            </label>
                        </div>
                    </div>
                    <button type="button" class="listing-col-picker-btn" id="listing-sort-reset" disabled>
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                        Reset sort
                    </button>
                </div>
                <a class="btn-listing-tool" href="<?= h($currentDirNewTabUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open this folder at the web root URL in a new tab" aria-label="Open this folder at the web root URL in a new tab">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                    Open in new tab
                </a>
                <?php if ($createEnabled && $authenticated && !$inShareMode): ?>
                <button type="button" class="btn-listing-tool" id="btn-create-folder">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    New folder
                </button>
                <button type="button" class="btn-listing-tool" id="btn-create-file">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    New file
                </button>
                <?php endif; ?>
            </div>
            <table id="listing-table">
                <colgroup>
                    <col class="col-name">
                    <col class="col-size">
                    <col class="col-modified">
                    <col class="col-owner">
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
                        <th scope="col" class="owner" data-sort-col="owner">
                            <button type="button" class="listing-sort-btn" data-sort-col="owner">
                                Owner <span class="listing-sort-indicator" aria-hidden="true"></span>
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
                        <td class="name dir ft-type--dir">
                            <?php
                            $parentUrl = currentListingUrl($indexHref, $parentRel);
                            $parentNewTabUrl = $inShareMode ? $parentUrl : directEntryUrl($parentRel, true);
                            ?>
                            <div class="name-content">
                                <a href="<?= h($parentUrl) ?>">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                    ..
                                </a>
                                <div class="name-actions">
                                <a class="entry-open-new" href="<?= h($parentNewTabUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open parent directory in new tab" title="Open in new tab">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                                </a>
                                </div>
                            </div>
                        </td>
                        <td class="size">&#8212;</td>
                        <td class="modified">&#8212;</td>
                        <td class="owner">&#8212;</td>
                        <td class="perms">&#8212;</td>
                    </tr>
                    <?php endif; ?>

                    <?php
                    foreach ($items as $item):
                        $brokenLinkAttr = !empty($item['isBrokenLink']) ? ' data-broken-link="1"' : '';
                        $symlinkAttr = !empty($item['isLink']) ? ' data-is-symlink="1"' : '';
                        $linkTargetAttr = ($item['isLink'] && !empty($item['linkTarget'])) ? ' data-link-target="' . h($item['linkTarget']) . '"' : '';
                        $linkTitle = listingEntryLinkTitle($item);
                        if ($linkTitle === '' && !$item['isDir'] && empty($item['previewKind']) && empty($item['isLink'])) {
                            $linkTitle = 'View file info';
                        }
                        $linkTitleAttr = $linkTitle !== '' ? ' title="' . h($linkTitle) . '"' : '';
                        if ($item['isDir']) {
                            $url = currentListingUrl($indexHref, $item['path']);
                            $newTabUrl = $inShareMode ? $url : directEntryUrl($item['path'], true);
                            $linkAttrs = $symlinkAttr . $linkTargetAttr . $brokenLinkAttr;
                        } else {
                            if ($inShareMode) {
                                $directUrl = currentListingUrl($indexHref, $item['path'], ['download' => '1']);
                            } else {
                                $directUrl = directEntryUrl($item['path']);
                            }
                            $newTabUrl = $directUrl;
                            $ts = isset($item['mtime']) ? $item['mtime'] : null;
                            $mtimeFormatted = '—';
                            if ($ts !== null && $ts >= 0 && $ts <= 2147483647) {
                                $formatted = @date('Y-m-d H:i', (int) $ts);
                                $mtimeFormatted = $formatted !== false ? $formatted : '—';
                            }
                            $sizeFormatted = formatSize($item['size']);
                            $permsLabel = !empty($item['permsLabel']) ? $item['permsLabel'] : '';
                            $typeLabel = $item['ext'] !== '' ? '.' . $item['ext'] : '';
                            $entryDownloadUrl = !empty($item['isBrokenLink']) ? null : listingEntryDownloadUrl($item['path'], $item['ext'], $inShareMode, $indexHref, $previewBlocklist);
                            $downloadAttr = ($entryDownloadUrl !== null) ? (' data-download-url="' . h($entryDownloadUrl) . '"') : '';
                            if ($item['previewKind'] === 'text') {
                                $url = '#';
                                $contentUrl = currentListingUrl($indexHref, $item['path'], ['content' => '1']);
                                $openUrl = $directUrl;
                                $previewClass = 'file-preview' . (isMarkdownExtension($item['ext']) && $markdownPreviewEnabled ? ' file-preview-md' : '');
                                $linkAttrs = ' class="' . $previewClass . '" data-content-url="' . h($contentUrl) . '" data-name="' . h($item['name']) . '" data-open-url="' . h($openUrl) . '" data-share-path="' . h($item['path']) . '"'
                                    . $downloadAttr
                                    . ' data-size="' . h($sizeFormatted) . '"'
                                    . ' data-mtime="' . h($mtimeFormatted) . '"'
                                    . ' data-perms="' . h($permsLabel) . '"'
                                    . ' data-type="' . h($typeLabel) . '"'
                                    . $brokenLinkAttr
                                    . $symlinkAttr
                                    . $linkTargetAttr;
                            } elseif ($item['previewKind'] === 'image') {
                                $url = '#';
                                $imageUrl = previewImageUrl($indexHref, $item['path'], $inShareMode);
                                $metaUrl = currentListingUrl($indexHref, $item['path'], ['meta' => '1']);
                                $openUrl = $directUrl;
                                $linkAttrs = ' class="file-preview file-preview-image" data-image-url="' . h($imageUrl) . '" data-meta-url="' . h($metaUrl) . '" data-name="' . h($item['name']) . '" data-open-url="' . h($openUrl) . '" data-share-path="' . h($item['path']) . '"'
                                    . $downloadAttr
                                    . ' data-size="' . h($sizeFormatted) . '"'
                                    . ' data-mtime="' . h($mtimeFormatted) . '"'
                                    . ' data-perms="' . h($permsLabel) . '"'
                                    . ' data-type="' . h($typeLabel) . '"'
                                    . $brokenLinkAttr
                                    . $symlinkAttr
                                    . $linkTargetAttr;
                            } else {
                                $url = '#';
                                $metaUrl = currentListingUrl($indexHref, $item['path'], ['meta' => '1']);
                                $linkAttrs = ' class="file-binary"'
                                    . ' data-name="' . h($item['name']) . '"'
                                    . ' data-meta-url="' . h($metaUrl) . '"'
                                    . $downloadAttr
                                    . ' data-size="' . h($sizeFormatted) . '"'
                                    . ' data-mtime="' . h($mtimeFormatted) . '"'
                                    . ' data-perms="' . h($permsLabel) . '"'
                                    . ' data-type="' . h($typeLabel) . '"'
                                    . ' data-icon-html="' . h(fileTypeIconHtml($item['ext'], false)) . '"'
                                    . ' data-share-path="' . h($item['path']) . '"'
                                    . $brokenLinkAttr
                                    . $symlinkAttr
                                    . $linkTargetAttr;
                            }
                        }
                        $nameClass = ($item['isDir'] ? 'dir ' : '') . ($item['isLink'] ? 'symlink ' : '') . (!empty($item['isBrokenLink']) ? 'broken-link ' : '') . ((!$item['isDir'] && !$item['previewKind']) ? 'binary' : '');
                        $entryTypeClass = listingEntryTypeClass($item);
                    ?>
                    <tr data-is-dir="<?= $item['isDir'] ? '1' : '0' ?>" data-sort-name="<?= h($item['name']) ?>" data-sort-size="<?= $item['isDir'] ? '-1' : (int) $item['size'] ?>" data-sort-mtime="<?= isset($item['mtime']) && $item['mtime'] !== null ? (int) $item['mtime'] : '0' ?>" data-sort-owner="<?= h(strtolower($item['ownerLabel'] ?? '')) ?>" data-sort-perms="<?= isset($item['perms']) && $item['perms'] !== null ? (int) $item['perms'] : '0' ?>">
                        <td class="name <?= trim($nameClass) ?> <?= h($entryTypeClass) ?>">
                            <div class="name-content">
                                <a href="<?= h($url) ?>"<?= $linkAttrs ?><?= $linkTitleAttr ?>>
                                    <?php if ($item['isLink']): ?>
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                    <?php else: ?>
                                    <?= fileTypeIconHtml($item['ext'], $item['isDir'], true) ?>
                                    <?php endif; ?>
                                    <span class="entry-name"><?= h($item['name']) ?></span><?php if (!empty($item['isBrokenLink'])): ?><span class="entry-broken-badge">broken</span><?php endif; ?>
                                </a>
                                <div class="name-actions">
                                <?php if ($authenticated && !$inShareMode && $deleteEnabled): ?>
                                <button type="button" class="entry-delete" data-delete-path="<?= h($item['path']) ?>" data-delete-name="<?= h($item['name']) ?>" data-is-dir="<?= $item['isDir'] ? '1' : '0' ?>" aria-label="Delete <?= h($item['name']) ?>" title="Delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                </button>
                                <?php endif; ?>
                                <?php if ($authenticated && !$inShareMode && $sharesAvailable && empty($item['isBrokenLink'])): ?>
                                <button type="button" class="entry-share" data-share-path="<?= h($item['path']) ?>" data-share-type="<?= h($item['isDir'] ? 'dir' : 'file') ?>" aria-label="Share <?= h($item['name']) ?>" title="Create share link">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                                </button>
                                <?php endif; ?>
                                <?php if ((!$inShareMode || $item['isDir']) && empty($item['isBrokenLink'])): ?>
                                <a class="entry-open-new" href="<?= h($newTabUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open <?= h($item['name']) ?> in new tab" title="Open in new tab">
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
                        ?>                        </td>
                        <td class="owner"><?= !empty($item['ownerLabel']) ? h($item['ownerLabel']) : '&#8212;' ?></td>
                        <td class="perms"><?= !empty($item['permsLabel']) ? h($item['permsLabel']) : '&#8212;' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <footer>
            <?= count($items) + ($hasParent ? 1 : 0) ?> item(s) &nbsp;·&nbsp;
            <button type="button" class="footer-about-link" id="footer-about">php-dirindex v<?= h($dirindexVersion) ?></button>
        </footer>
    </div>

    <div id="file-modal" class="modal-overlay" aria-hidden="true">
        <div class="modal" id="file-modal-panel" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <span class="modal-title" id="modal-title"></span>
                    <span id="modal-broken-badge" class="entry-broken-badge" hidden>broken</span>
                </div>
                <div class="modal-header-actions">
                    <a id="modal-download-link" class="modal-action-btn" href="#" download hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download
                    </a>
                    <a id="modal-open-link" class="modal-action-btn" href="#" target="_blank" rel="noopener noreferrer" hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                        Open in new tab
                    </a>
                    <?php if ($authenticated && !$inShareMode && $sharesAvailable): ?>
                    <button type="button" class="modal-action-btn" id="modal-share-btn" hidden aria-label="Share file" title="Create share link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                        Share
                    </button>
                    <?php endif; ?>
                </div>
                <button type="button" class="modal-close" id="modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p id="modal-broken-notice" class="modal-broken-notice" hidden></p>
                <div id="modal-binary" class="modal-binary" hidden aria-hidden="true">
                    <div class="modal-binary-header">
                        <span id="modal-binary-icon"></span>
                        <div class="modal-binary-text">
                            <h2 id="modal-binary-name" class="modal-binary-name"></h2>
                        </div>
                    </div>
                    <a id="modal-binary-download" class="btn-download" href="#">Download</a>
                </div>
                <div id="modal-md" class="modal-md" aria-hidden="true"></div>
                <div id="modal-image" class="modal-image" hidden aria-hidden="true">
                    <img id="modal-image-el" alt="">
                </div>
                <pre id="modal-pre"><code id="modal-code"></code></pre>
            </div>
            <div id="modal-file-meta" class="modal-file-meta" hidden></div>
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

    <div id="about-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal about-modal-panel" role="dialog" aria-modal="true" aria-labelledby="about-title">
            <div class="modal-header">
                <span class="modal-title" id="about-title">About</span>
                <button type="button" class="modal-close" id="about-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p class="about-intro">A single-file PHP directory index with navigation, in-browser previews, optional uploads, and share links. Drop <code>index.php</code> into any folder and open it in a browser.</p>
                <dl class="about-meta">
                    <dt>Project</dt>
                    <dd>php-dirindex</dd>
                    <dt>Version</dt>
                    <dd><?= h($dirindexVersion) ?></dd>
                    <dt>Build</dt>
                    <dd><?= h($dirindexBuildLabel) ?><?php if ($dirindexBuildRef !== ''): ?> <span class="about-build-ref">(<?= h($dirindexBuildRef) ?>)</span><?php endif; ?></dd>
                    <dt>PHP</dt>
                    <dd><?= h(PHP_VERSION) ?></dd>
                </dl>
                <?php if (!$inShareMode): ?>
                <div class="about-update" id="about-update"
                    data-check-url="<?= h(currentListingUrl($indexHref, '', ['update_check' => '1'])) ?>"
                    data-post-url="<?= h($indexHref) ?>"
                    data-current-version="<?= h($dirindexVersion) ?>"
                    data-current-build-ref="<?= h($dirindexBuildRef) ?>">
                    <div class="about-update-channel">
                        <label for="about-update-channel">Channel</label>
                        <select id="about-update-channel" aria-label="Update channel">
                            <option value="stable">Stable (tagged releases)</option>
                            <option value="dev">Dev (rolling)</option>
                        </select>
                    </div>
                    <p class="about-update-status is-muted" id="about-update-status" role="status">Check GitHub for a newer release.</p>
                    <div class="about-update-actions">
                        <button type="button" class="btn-auth btn-auth-secondary" id="about-check-updates">Check for updates</button>
                        <button type="button" class="btn-auth" id="about-apply-update" hidden>Update now</button>
                    </div>
                </div>
                <?php endif; ?>
                <div class="about-links">
                    <a href="<?= h($dirindexRepoUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 3v12"/><circle cx="6" cy="18" r="3"/><path d="M18 3v6"/><circle cx="18" cy="9" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>
                        <span>Repository</span>
                    </a>
                    <a href="<?= h($dirindexRepoUrl . '/releases') ?>" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                        <span>Releases</span>
                    </a>
                    <a href="<?= h($dirindexRepoUrl . '/blob/dev/CHANGELOG.md') ?>" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
                        <span>Changelog</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div id="settings-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal settings-main-panel" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <div class="modal-header">
                <span class="modal-title" id="settings-title">Settings</span>
                <button type="button" class="modal-close" id="settings-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <?php if ($settingsModalMessage && $settingsModalMessage[0] !== 'success'): ?>
                <div id="settings-modal-message" class="settings-modal-message blocked-msg message-<?= h($settingsModalMessage[0]) ?> settings-modal-message--alert" role="alert">
                    <?= h($settingsModalMessage[1]) ?>
                </div>
                <?php endif; ?>
                <details class="settings-panel" id="settings-panel-display" data-settings-panel="display" open>
                    <summary class="settings-panel-summary">
                        <span class="settings-panel-summary-main">
                            <span class="settings-panel-title" id="display-settings-title">Display</span>
                            <span class="settings-panel-hint">Theme, text size, and breadcrumb separator</span>
                        </span>
                    </summary>
                    <div class="settings-panel-body settings-panel-body--display">
                        <div class="settings-field settings-display-field">
                            <span class="settings-display-label" id="setting-theme-label">Theme</span>
                            <div class="display-segmented display-segmented--joined" role="group" aria-labelledby="setting-theme-label">
                                <button type="button" class="display-segment" data-theme-option="system">Follow system</button>
                                <button type="button" class="display-segment" data-theme-option="light">Light</button>
                                <button type="button" class="display-segment" data-theme-option="dark">Dark</button>
                            </div>
                        </div>
                        <div class="settings-field settings-display-field">
                            <span class="settings-display-label" id="setting-font-label">Text size</span>
                            <div class="display-segmented" role="group" aria-labelledby="setting-font-label">
                                <button type="button" class="display-segment" data-font-option="xs">Extra small</button>
                                <button type="button" class="display-segment" data-font-option="sm">Small</button>
                                <button type="button" class="display-segment" data-font-option="md">Medium</button>
                                <button type="button" class="display-segment" data-font-option="lg">Large</button>
                                <button type="button" class="display-segment" data-font-option="xl">Extra large</button>
                            </div>
                        </div>
                        <div class="settings-field settings-display-field">
                            <label for="setting-breadcrumb-sep">Breadcrumb separator</label>
                            <input type="text" id="setting-breadcrumb-sep" class="display-breadcrumb-input" maxlength="6" spellcheck="false" autocomplete="off" placeholder="›">
                            <span class="settings-help">Shown between breadcrumb segments. Examples: ›, /, →, ::</span>
                        </div>
                    </div>
                </details>

                <?php if ($authenticated && !$inShareMode): ?>
                <form class="settings-form settings-server-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" id="settings-server-form">
                    <input type="hidden" name="action" value="settings">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

                    <details class="settings-panel" id="settings-panel-permissions" data-settings-panel="permissions" open>
                        <summary class="settings-panel-summary">
                            <span class="settings-panel-summary-main">
                                <span class="settings-panel-title">Permissions &amp; uploads</span>
                                <span class="settings-panel-hint">Upload, create, delete, and browse sign-in</span>
                            </span>
                        </summary>
                        <div class="settings-panel-body">
                            <label class="settings-check-row">
                                <input type="checkbox" name="upload_enabled" value="1" <?= $uploadEnabled ? 'checked' : '' ?>>
                                <span>Enable uploads</span>
                            </label>
                            <label class="settings-check-row">
                                <input type="checkbox" name="create_enabled" value="1" <?= $createEnabled ? 'checked' : '' ?>>
                                <span>Allow creating folders and files</span>
                            </label>
                            <label class="settings-check-row">
                                <input type="checkbox" name="delete_enabled" value="1" <?= $deleteEnabled ? 'checked' : '' ?>>
                                <span>Allow deleting folders and files</span>
                            </label>
                            <label class="settings-check-row">
                                <input type="checkbox" name="browse_requires_auth" value="1" <?= !empty($dirindexConfig['browse_requires_auth']) ? 'checked' : '' ?>>
                                <span>Require sign-in to browse files</span>
                            </label>
                            <p class="settings-help">When enabled, visitors must sign in to view listings or open files. Valid share links still work without signing in.</p>
                            <div class="settings-field">
                                <label for="admin-upload-max">Upload limit in bytes</label>
                                <input type="number" id="admin-upload-max" name="upload_max_bytes" min="0" inputmode="numeric" value="<?= h((string) ((int) ($dirindexConfig['upload_max_bytes'] ?? 0))) ?>">
                                <span class="settings-help">Use 0 to rely on PHP's configured upload limit.</span>
                            </div>
                        </div>
                    </details>

                    <details class="settings-panel" id="settings-panel-filesystem" data-settings-panel="filesystem">
                        <summary class="settings-panel-summary">
                            <span class="settings-panel-summary-main">
                                <span class="settings-panel-title">Filesystem</span>
                                <span class="settings-panel-hint">Symlinks, follow rules, and public file URLs</span>
                            </span>
                        </summary>
                        <div class="settings-panel-body">
                            <label class="settings-check-row">
                                <input type="checkbox" name="listing_from_document_root" value="1" <?= !empty($dirindexConfig['listing_from_document_root']) ? 'checked' : '' ?>>
                                <span>Use document root as listing base</span>
                            </label>
                            <p class="settings-help">When enabled, listings start at the web server document root (or its parent when the script sits in or above the doc root). When disabled, listings start in the folder that contains this script.</p>
                            <div class="settings-field">
                                <label for="admin-web-root-url">Web root URL</label>
                                <input type="text" id="admin-web-root-url" name="web_root_url" value="<?= h($webRootUrlConfigured) ?>" spellcheck="false" autocomplete="off" placeholder="<?= h($webRootUrlDetected) ?>">
                                <span class="settings-help">Used for <strong>Open in new tab</strong> and file preview download links. Absolute URL (<code>https://files.example.com/</code>) or site path (<code>/public/</code>). Leave empty to auto-detect from this index (<code><?= h($webRootUrlDetected) ?></code>).</span>
                            </div>
                            <label class="settings-check-row">
                                <input type="checkbox" name="show_symlinks" value="1" <?= !empty($dirindexConfig['show_symlinks']) ? 'checked' : '' ?>>
                                <span>Show symlinks in listings</span>
                            </label>
                            <label class="settings-check-row">
                                <input type="checkbox" name="allow_open_symlinks_outside" value="1" <?= !empty($dirindexConfig['allow_open_symlinks_outside']) ? 'checked' : '' ?>>
                                <span>Allow opening symlinks outside the listing root</span>
                            </label>
                        </div>
                    </details>

                    <details class="settings-panel" id="settings-panel-previews" data-settings-panel="previews">
                        <summary class="settings-panel-summary">
                            <span class="settings-panel-summary-main">
                                <span class="settings-panel-title">Previews</span>
                                <span class="settings-panel-hint">Images, Markdown, and blocked file types</span>
                            </span>
                        </summary>
                        <div class="settings-panel-body">
                            <label class="settings-check-row">
                                <input type="checkbox" name="image_preview_enabled" value="1" <?= $imagePreviewEnabled ? 'checked' : '' ?>>
                                <span>Enable image preview in modal</span>
                            </label>
                            <p class="settings-help">When enabled, common image files (jpg, png, gif, webp, svg, and similar) open in the preview modal instead of the binary download dialog.</p>
                            <label class="settings-check-row">
                                <input type="checkbox" name="markdown_preview_enabled" value="1" <?= $markdownPreviewEnabled ? 'checked' : '' ?>>
                                <span>Render Markdown in preview</span>
                            </label>
                            <p class="settings-help">When enabled, <code>.md</code> and <code>.markdown</code> files are shown as formatted HTML in the preview modal and on share landing pages. When disabled, they open as syntax-highlighted source instead.</p>
                            <div class="settings-field">
                                <label for="admin-preview-blocklist">Preview blocklist</label>
                                <textarea id="admin-preview-blocklist" name="preview_blocklist" rows="4" spellcheck="false" placeholder="php&#10;env"><?= h(formatPreviewBlocklistForInput($previewBlocklist)) ?></textarea>
                                <span class="settings-help">One file extension per line, without a dot (e.g. <code>php</code>, <code>env</code>). Matching types are not previewed in the modal or on share landing pages; they open as binary files instead. Default: <code>php</code>.</span>
                            </div>
                            <label class="settings-check-row">
                                <input type="checkbox" name="hash_sha256_sha512_enabled" value="1" <?= $hashSha256Sha512Enabled ? 'checked' : '' ?>>
                                <span>Include SHA-256 and SHA-512 checksums</span>
                            </label>
                            <p class="settings-help">When enabled, the file info modal can compute SHA-256 and SHA-512 in addition to CRC32, MD5, and SHA-1. Disabled by default because the stronger digests are much slower on large files.</p>
                        </div>
                    </details>

                    <details class="settings-panel" id="settings-panel-path" data-settings-panel="path">
                        <summary class="settings-panel-summary">
                            <span class="settings-panel-summary-main">
                                <span class="settings-panel-title">Path access</span>
                                <span class="settings-panel-hint">Whitelist and blacklist paths in this index</span>
                            </span>
                        </summary>
                        <div class="settings-panel-body">
                            <div class="settings-field">
                                <label for="admin-path-whitelist">Path whitelist</label>
                                <textarea id="admin-path-whitelist" name="path_whitelist" rows="4" spellcheck="false" placeholder="public/&#10;docs/*.md"><?= h(formatPathAccessListForInput($pathWhitelist)) ?></textarea>
                                <span class="settings-help">One path per line, relative to the index root. When non-empty, only these paths (and parent folders needed to reach them) are visible. Use a trailing slash or a slash in the path (e.g. <code>public/</code>) for a folder tree; a name without a slash (e.g. <code>README.md</code>) matches that basename anywhere. Wildcards are supported: <code>*.log</code>, <code>backups/*.sql</code>, <code>logs/**</code>. Share links bypass path rules.</span>
                            </div>
                            <div class="settings-field">
                                <label for="admin-path-blacklist">Path blacklist</label>
                                <textarea id="admin-path-blacklist" name="path_blacklist" rows="4" spellcheck="false" placeholder="private/&#10;*.tmp&#10;.git&#10;node_modules"><?= h(formatPathAccessListForInput($pathBlacklist)) ?></textarea>
                                <span class="settings-help">One path per line. Same rule syntax as the whitelist, including wildcards. Matching paths are omitted from listings and blocked unless opened via a valid share link.</span>
                            </div>
                        </div>
                    </details>

                    <details class="settings-panel" id="settings-panel-network" data-settings-panel="network">
                        <summary class="settings-panel-summary">
                            <span class="settings-panel-summary-main">
                                <span class="settings-panel-title">Network access</span>
                                <span class="settings-panel-hint">IP whitelist, blacklist, and reverse proxy header</span>
                            </span>
                        </summary>
                        <div class="settings-panel-body">
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
                        </div>
                    </details>
                </form>

                <details class="settings-panel settings-panel--danger" id="settings-panel-reset" data-settings-panel="reset">
                    <summary class="settings-panel-summary">
                        <span class="settings-panel-summary-main">
                            <span class="settings-panel-title">Reset</span>
                            <span class="settings-panel-hint">Delete settings storage and return to setup</span>
                        </span>
                    </summary>
                    <div class="settings-panel-body">
                        <p class="settings-help">Delete <?= h(basename(dirindexStoragePath(__DIR__))) ?> and return to first-run setup. This removes the admin account, server settings, and share links. Files in the directory are not deleted.</p>
                        <form class="settings-form" method="post" action="<?= h(currentListingUrl($indexHref, $relativePath)) ?>" id="reset-settings-form">
                            <input type="hidden" name="action" value="reset">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <button type="button" class="btn-auth btn-auth-danger" id="btn-reset-settings">Reset all settings</button>
                        </form>
                    </div>
                </details>
                <?php endif; ?>
            </div>
            <?php if ($authenticated && !$inShareMode): ?>
            <div class="modal-footer">
                <button type="submit" form="settings-server-form" class="btn-auth btn-auth-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span>Save server settings</span>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($settingsModalMessage && $settingsModalMessage[0] === 'success'): ?>
        <div id="settings-modal-message" class="settings-modal-message blocked-msg message-success settings-modal-message--toast" role="status" aria-live="polite">
            <?= h($settingsModalMessage[1]) ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($authenticated && !$inShareMode): ?>
    <div id="account-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal account-modal-panel" role="dialog" aria-modal="true" aria-labelledby="account-title">
            <div class="modal-header">
                <span class="modal-title" id="account-title">Account</span>
                <button type="button" class="modal-close" id="account-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
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
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($createEnabled && $authenticated && !$inShareMode): ?>
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
                        <input type="text" id="create-entry-name" name="entry_name" required autocomplete="off" spellcheck="false" placeholder="notes.txt" maxlength="255">
                        <span class="settings-help" id="create-entry-help">Creates an empty folder in the current directory. Names may use letters, numbers, spaces, and . _ - ( ) [ ].</span>
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
                                        <button type="submit" class="btn-share-sm danger-outline">
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

    <div id="confirm-modal" class="settings-overlay" aria-hidden="true">
        <div class="settings-modal share-modal-panel confirm-modal-panel" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title">
            <div class="modal-header">
                <span class="modal-title" id="confirm-modal-title">Confirm</span>
                <button type="button" class="modal-close" id="confirm-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirm-modal-message" class="confirm-modal-message"></p>
                <div id="confirm-modal-target" class="confirm-modal-target" hidden></div>
                <p id="confirm-modal-detail" class="confirm-modal-detail settings-help"></p>
                <div class="confirm-modal-actions">
                    <button type="button" class="btn-auth btn-auth-secondary" id="confirm-modal-cancel">Cancel</button>
                    <button type="button" class="btn-auth" id="confirm-modal-ok">OK</button>
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
    function entryNameHasControlChars(name) {
        for (var i = 0; i < name.length; i++) {
            var code = name.charCodeAt(i);
            if (code <= 31 || code === 127) return true;
        }
        return false;
    }
    function entryNameHasForbiddenChars(name) {
        var forbidden = '/\\:*?"<>|';
        for (var i = 0; i < name.length; i++) {
            if (forbidden.indexOf(name.charAt(i)) !== -1) return true;
        }
        return false;
    }
    function entryNameTrimEdges(name, chars) {
        var start = 0;
        var end = name.length;
        while (start < end && chars.indexOf(name.charAt(start)) !== -1) start++;
        while (end > start && chars.indexOf(name.charAt(end - 1)) !== -1) end--;
        return name.slice(start, end);
    }
    function entryNameTrimEnd(name, chars) {
        var end = name.length;
        while (end > 0 && chars.indexOf(name.charAt(end - 1)) !== -1) end--;
        return name.slice(0, end);
    }
    function entryNameHasInvalidEdges(name) {
        if (!name) return true;
        var last = name.charAt(name.length - 1);
        return last === ' ' || last === '.';
    }
    function entryNameCollapseChar(name, ch) {
        var out = '';
        var prev = false;
        for (var i = 0; i < name.length; i++) {
            var c = name.charAt(i);
            if (c === ch) {
                if (!prev) out += c;
                prev = true;
            } else {
                out += c;
                prev = false;
            }
        }
        return out;
    }
    function entryNameCollapseWhitespace(name) {
        var out = '';
        var prevSpace = false;
        for (var i = 0; i < name.length; i++) {
            var c = name.charAt(i);
            if (c === ' ' || c === '\t' || c === '\n' || c === '\r') {
                if (!prevSpace) out += ' ';
                prevSpace = true;
            } else {
                out += c;
                prevSpace = false;
            }
        }
        return out;
    }
    function entryNameReplaceForbidden(name) {
        var forbidden = '/\\:*?"<>|';
        var out = '';
        for (var i = 0; i < name.length; i++) {
            var c = name.charAt(i);
            out += forbidden.indexOf(c) !== -1 ? '-' : c;
        }
        return out;
    }
    function entryNameStripControlChars(name) {
        var out = '';
        for (var i = 0; i < name.length; i++) {
            var code = name.charCodeAt(i);
            if (code > 31 && code !== 127) out += name.charAt(i);
        }
        return out;
    }
    function entryNameExtractExtension(name) {
        var dot = name.lastIndexOf('.');
        if (dot <= 0) return '';
        var ext = name.slice(dot);
        if (ext.length < 2 || ext.length > 17) return '';
        for (var i = 1; i < ext.length; i++) {
            var c = ext.charAt(i);
            if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9'))) return '';
        }
        return ext;
    }
    function isWindowsReservedEntryName(name) {
        var base = name;
        var dot = base.lastIndexOf('.');
        if (dot > 0) base = base.slice(0, dot);
        base = entryNameTrimEnd(base, '. ').toUpperCase();
        return ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'].indexOf(base) !== -1;
    }
    function isAllowedEntryName(name) {
        name = (name || '').trim();
        if (!name || name === '.' || name === '..') return false;
        if (entryNameHasForbiddenChars(name)) return false;
        if (entryNameHasControlChars(name)) return false;
        if (entryNameHasInvalidEdges(name)) return false;
        if (name.length > 255) return false;
        if (isWindowsReservedEntryName(name)) return false;
        return true;
    }
    function suggestSafeEntryName(name) {
        var original = (name || '').trim();
        var ext = entryNameExtractExtension(original);
        var base = ext ? original.slice(0, -ext.length) : original;
        base = entryNameStripControlChars(base);
        base = entryNameReplaceForbidden(base);
        base = entryNameCollapseChar(base, '-');
        base = entryNameCollapseWhitespace(base);
        base = entryNameTrimEdges(base, '. \t-');
        if (!base || base === '.' || base === '..') base = 'upload';
        var candidate = base + ext;
        var maxBaseLen = 255 - ext.length;
        if (maxBaseLen < 1) {
            candidate = base.slice(0, 255);
        } else if (base.length > maxBaseLen) {
            base = entryNameTrimEnd(base.slice(0, maxBaseLen), '. \t-');
            if (!base || base === '.' || base === '..') base = 'upload';
            candidate = base + ext;
        }
        if (isWindowsReservedEntryName(candidate)) {
            base = 'file-' + base;
            if ((base + ext).length > 255) {
                base = entryNameTrimEnd(base.slice(0, Math.max(1, 255 - ext.length - 5)), '. \t-');
            }
            candidate = base + ext;
        }
        if (!isAllowedEntryName(candidate)) candidate = 'upload' + ext;
        return candidate;
    }

    var confirmModalState = { resolve: null };

    function showConfirmModal(options) {
        options = options || {};
        var overlay = document.getElementById('confirm-modal');
        var titleEl = document.getElementById('confirm-modal-title');
        var messageEl = document.getElementById('confirm-modal-message');
        var targetEl = document.getElementById('confirm-modal-target');
        var detailEl = document.getElementById('confirm-modal-detail');
        var okBtn = document.getElementById('confirm-modal-ok');
        var cancelBtn = document.getElementById('confirm-modal-cancel');
        var closeBtn = document.getElementById('confirm-modal-close');
        if (!overlay || !messageEl || !okBtn || !cancelBtn) {
            return Promise.resolve(false);
        }
        if (confirmModalState.resolve) {
            confirmModalState.resolve(false);
            confirmModalState.resolve = null;
        }
        if (titleEl) titleEl.textContent = options.title || 'Confirm';
        messageEl.textContent = options.message || 'Are you sure?';
        if (targetEl) {
            if (options.target) {
                targetEl.textContent = options.target;
                targetEl.hidden = false;
            } else {
                targetEl.textContent = '';
                targetEl.hidden = true;
            }
        }
        if (detailEl) {
            detailEl.textContent = options.detail || '';
            detailEl.hidden = !options.detail;
        }
        okBtn.textContent = options.confirmLabel || 'OK';
        cancelBtn.textContent = options.cancelLabel || 'Cancel';
        okBtn.classList.toggle('btn-auth-danger', !!options.danger);
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        okBtn.focus();
        return new Promise(function(resolve) {
            confirmModalState.resolve = resolve;
        });
    }

    function closeConfirmModal(confirmed) {
        var overlay = document.getElementById('confirm-modal');
        if (overlay) {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
        }
        if (confirmModalState.resolve) {
            confirmModalState.resolve(!!confirmed);
            confirmModalState.resolve = null;
        }
    }

    (function() {
        var overlay = document.getElementById('confirm-modal');
        var okBtn = document.getElementById('confirm-modal-ok');
        var cancelBtn = document.getElementById('confirm-modal-cancel');
        var closeBtn = document.getElementById('confirm-modal-close');
        if (!overlay || !okBtn || !cancelBtn) return;
        okBtn.addEventListener('click', function() { closeConfirmModal(true); });
        cancelBtn.addEventListener('click', function() { closeConfirmModal(false); });
        if (closeBtn) closeBtn.addEventListener('click', function() { closeConfirmModal(false); });
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeConfirmModal(false);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
                closeConfirmModal(false);
                e.stopPropagation();
            }
        });
    })();

    (function() {
        var toggle = document.getElementById('btn-upload-toggle');
        var panel = document.getElementById('upload-panel');
        if (!toggle || !panel) return;
        toggle.addEventListener('click', function() {
            var isOpen = panel.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.textContent = isOpen ? 'Hide upload' : 'Upload';
        });
    })();

    (function() {
        var deleteForm = document.getElementById('delete-entry-form');
        var deletePathInput = document.getElementById('delete-entry-path');
        if (!deleteForm || !deletePathInput) return;

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-delete');
            if (!btn) return;
            e.preventDefault();
            var path = btn.getAttribute('data-delete-path') || '';
            var name = btn.getAttribute('data-delete-name') || path;
            var isDir = btn.getAttribute('data-is-dir') === '1';
            if (!path) return;
            showConfirmModal({
                title: isDir ? 'Delete folder?' : 'Delete file?',
                message: isDir ? 'Delete this folder and everything inside it?' : 'Delete this file?',
                target: name,
                detail: 'This cannot be undone.',
                confirmLabel: 'Delete',
                danger: true
            }).then(function(confirmed) {
                if (!confirmed) return;
                deletePathInput.value = path;
                deleteForm.submit();
            });
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
        var createForm = document.getElementById('create-entry-form');
        if (!overlay || !typeInput || !nameInput) return;

        var createNameHint = ' Names may use letters, numbers, spaces, and . _ - ( ) [ ].';

        function setCreateType(type) {
            var isFolder = type === 'folder';
            typeInput.value = isFolder ? 'folder' : 'file';
            if (title) title.textContent = isFolder ? 'Create folder' : 'Create file';
            if (helpText) helpText.textContent = (isFolder
                ? 'Creates an empty folder in the current directory.'
                : 'Creates an empty file in the current directory.') + createNameHint;
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
        if (createForm) {
            createForm.addEventListener('submit', function(e) {
                var value = nameInput.value || '';
                if (isAllowedEntryName(value)) return;
                e.preventDefault();
                var suggested = suggestSafeEntryName(value);
                window.alert('That name is not allowed.\n\nSuggested name: ' + suggested);
                nameInput.value = suggested;
                nameInput.focus();
                nameInput.select();
            });
        }
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
        var dirInput = document.getElementById('upload-file-dir');
        var uploadModeInput = document.getElementById('upload-mode');
        var overwriteInput = document.getElementById('upload-overwrite');
        var uploadAsInput = document.getElementById('upload-as');
        var dropzone = document.getElementById('upload-dropzone');
        var dropzoneTitle = document.getElementById('upload-dropzone-title');
        var dropzoneHint = document.getElementById('upload-dropzone-hint');
        var browseBtn = document.getElementById('upload-browse-btn');
        var browseDirBtn = document.getElementById('upload-browse-dir-btn');
        var submitBtn = document.getElementById('upload-submit-btn');
        var fileNameEl = document.getElementById('upload-file-name');
        var dirOverwriteRow = document.getElementById('upload-dir-overwrite-row');
        var dirOverwriteCheckbox = document.getElementById('upload-dir-overwrite');
        var dirMaxFiles = parseInt(uploadForm.getAttribute('data-dir-max-files') || '500', 10);
        if (!dirMaxFiles || dirMaxFiles < 1) dirMaxFiles = 500;
        var dirUploadConfirmed = false;
        var existingNames = [];
        try {
            existingNames = JSON.parse(uploadForm.getAttribute('data-existing-names') || '[]');
        } catch (e) {
            existingNames = [];
        }

        function isDirectoryUploadMode() {
            return uploadModeInput && uploadModeInput.value === 'directory';
        }

        function updateSubmitLabel() {
            if (!submitBtn) return;
            var path = uploadForm.getAttribute('data-upload-path') || '/';
            submitBtn.textContent = (isDirectoryUploadMode() ? 'Upload folder to ' : 'Upload to ') + path;
        }

        function setUploadMode(mode) {
            var isDirectory = mode === 'directory';
            if (uploadModeInput) uploadModeInput.value = isDirectory ? 'directory' : 'file';
            if (fileInput) {
                fileInput.disabled = isDirectory;
                if (isDirectory) fileInput.value = '';
            }
            if (dirInput) {
                dirInput.disabled = !isDirectory;
                if (!isDirectory) dirInput.value = '';
            }
            if (dropzoneTitle) dropzoneTitle.textContent = isDirectory ? 'Drop a folder here' : 'Drop a file here';
            if (dropzoneHint) dropzoneHint.textContent = isDirectory ? 'or choose a folder from your device' : 'or choose one from your device';
            if (dirOverwriteRow) dirOverwriteRow.hidden = !isDirectory;
            if (!isDirectory && dirOverwriteCheckbox) dirOverwriteCheckbox.checked = false;
            updateSubmitLabel();
            clearSelection();
        }

        function hasFileDrag(e) {
            var types = e.dataTransfer && e.dataTransfer.types;
            if (!types) return false;
            return Array.prototype.indexOf.call(types, 'Files') !== -1;
        }

        function isDirectoryFileList(files) {
            if (!files || files.length === 0) return false;
            if (files.length > 1) return true;
            var first = files[0];
            return !!(first && first.webkitRelativePath);
        }

        function directoryLabelFromFiles(files) {
            if (!files || !files.length) return '';
            var firstPath = files[0].webkitRelativePath || files[0].name || '';
            var parts = firstPath.split('/');
            if (parts.length > 1) return parts[0] + ' (' + files.length + ' files)';
            return files.length + ' file' + (files.length === 1 ? '' : 's');
        }

        function setSelectionLabel(name, isDirectory) {
            if (!dropzone || !fileNameEl) return;
            if (!name) {
                dropzone.classList.remove('has-file');
                fileNameEl.textContent = '';
                fileNameEl.innerHTML = '';
                return;
            }
            dropzone.classList.add('has-file');
            var icon = isDirectory
                ? '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'
                : '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/>';
            fileNameEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' + icon + '</svg><span class="upload-file-name-text"></span>';
            var textEl = fileNameEl.querySelector('.upload-file-name-text');
            if (textEl) textEl.textContent = name;
        }

        function clearSelection() {
            dirUploadConfirmed = false;
            setSelectionLabel('');
            if (overwriteInput) overwriteInput.value = '';
            clearUploadRename();
        }

        function clearUploadRename() {
            if (uploadAsInput) uploadAsInput.value = '';
        }

        function uploadTargetName() {
            if (uploadAsInput && uploadAsInput.value) return uploadAsInput.value;
            if (!fileInput || !fileInput.files || !fileInput.files[0]) return '';
            return fileInput.files[0].name;
        }

        function assignFileList(files, directoryMode) {
            if (!files || !files.length) return;
            var dt = new DataTransfer();
            Array.prototype.forEach.call(files, function(file) {
                dt.items.add(file);
            });
            if (directoryMode) {
                setUploadMode('directory');
                if (!dirInput) return;
                dirInput.files = dt.files;
                setSelectionLabel(directoryLabelFromFiles(dirInput.files), true);
            } else {
                setUploadMode('file');
                if (!fileInput) return;
                fileInput.files = dt.files;
                setSelectionLabel(fileInput.files[0] ? fileInput.files[0].name : '', false);
            }
            if (overwriteInput) overwriteInput.value = '';
            clearUploadRename();
        }

        function openUploadPanel() {
            var panel = document.getElementById('upload-panel');
            var toggle = document.getElementById('btn-upload-toggle');
            if (panel && !panel.classList.contains('is-open')) {
                panel.classList.add('is-open');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'true');
                    toggle.textContent = 'Hide upload';
                }
            }
        }

        if (browseBtn && fileInput) {
            browseBtn.addEventListener('click', function() {
                setUploadMode('file');
                fileInput.click();
            });
        }
        if (browseDirBtn && dirInput) {
            browseDirBtn.addEventListener('click', function() {
                setUploadMode('directory');
                dirInput.click();
            });
        }
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                setUploadMode('file');
                var file = fileInput.files && fileInput.files[0];
                setSelectionLabel(file ? file.name : '', false);
                if (overwriteInput) overwriteInput.value = '';
                clearUploadRename();
            });
        }
        if (dirInput) {
            dirInput.addEventListener('change', function() {
                setUploadMode('directory');
                if (!dirInput.files || !dirInput.files.length) {
                    clearSelection();
                    return;
                }
                setSelectionLabel(directoryLabelFromFiles(dirInput.files), true);
                if (overwriteInput) overwriteInput.value = '';
                clearUploadRename();
            });
        }
        if (dropzone) {
            var dropCounter = 0;
            dropzone.addEventListener('dragenter', function(e) {
                if (!hasFileDrag(e)) return;
                e.preventDefault();
                dropCounter++;
                dropzone.classList.add('is-dragover');
            });
            dropzone.addEventListener('dragover', function(e) {
                if (!hasFileDrag(e)) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
            });
            dropzone.addEventListener('dragleave', function(e) {
                if (!hasFileDrag(e)) return;
                dropCounter--;
                if (dropCounter <= 0) {
                    dropCounter = 0;
                    dropzone.classList.remove('is-dragover');
                }
            });
            dropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropCounter = 0;
                dropzone.classList.remove('is-dragover');
                if (!e.dataTransfer || !e.dataTransfer.files.length) return;
                assignFileList(e.dataTransfer.files, isDirectoryFileList(e.dataTransfer.files));
            });
        }

        uploadForm.addEventListener('submit', function(e) {
            if (isDirectoryUploadMode()) {
                if (!dirInput || !dirInput.files || !dirInput.files.length) {
                    e.preventDefault();
                    window.alert('Choose a folder to upload.');
                    return;
                }
                if (dirInput.files.length > dirMaxFiles) {
                    e.preventDefault();
                    window.alert('Too many files in this folder (limit is ' + dirMaxFiles + ').');
                    return;
                }
                if (dirOverwriteCheckbox && dirOverwriteCheckbox.checked) {
                    if (overwriteInput) overwriteInput.value = '1';
                } else if (overwriteInput) {
                    overwriteInput.value = '';
                }
                if (!dirUploadConfirmed && !(dirOverwriteCheckbox && dirOverwriteCheckbox.checked)) {
                    e.preventDefault();
                    showConfirmModal({
                        title: 'Upload folder?',
                        message: 'Upload ' + dirInput.files.length + ' file(s) from this folder?',
                        detail: 'If any target files already exist, enable overwrite and upload again.',
                        confirmLabel: 'Upload folder'
                    }).then(function(confirmed) {
                        if (!confirmed) return;
                        dirUploadConfirmed = true;
                        uploadForm.requestSubmit();
                    });
                    return;
                }
                dirUploadConfirmed = false;
                return;
            }
            if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                e.preventDefault();
                window.alert('Choose a file to upload.');
                return;
            }
            var originalName = fileInput.files[0].name;
            if (!uploadAsInput || !uploadAsInput.value) {
                if (!isAllowedEntryName(originalName)) {
                    e.preventDefault();
                    var suggested = suggestSafeEntryName(originalName);
                    if (window.confirm('"' + originalName + '" is not allowed.\n\nUpload as "' + suggested + '" instead?')) {
                        uploadAsInput.value = suggested;
                        uploadForm.requestSubmit();
                    }
                    return;
                }
            } else if (!isAllowedEntryName(uploadAsInput.value)) {
                e.preventDefault();
                clearUploadRename();
                return;
            }
            var name = uploadTargetName();
            if (existingNames.indexOf(name) !== -1 && overwriteInput && overwriteInput.value !== '1') {
                e.preventDefault();
                if (window.confirm('A file named "' + name + '" already exists. Overwrite it?')) {
                    overwriteInput.value = '1';
                    uploadForm.requestSubmit();
                } else if (overwriteInput) {
                    overwriteInput.value = '';
                }
            }
        });

        var listing = document.querySelector('.listing');
        if (listing) {
            var listingDragCounter = 0;
            listing.addEventListener('dragenter', function(e) {
                if (!hasFileDrag(e)) return;
                e.preventDefault();
                listingDragCounter++;
                listing.classList.add('is-dragover');
            });
            listing.addEventListener('dragover', function(e) {
                if (!hasFileDrag(e)) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
            });
            listing.addEventListener('dragleave', function(e) {
                if (!hasFileDrag(e)) return;
                listingDragCounter--;
                if (listingDragCounter <= 0) {
                    listingDragCounter = 0;
                    listing.classList.remove('is-dragover');
                }
            });
            listing.addEventListener('drop', function(e) {
                e.preventDefault();
                listingDragCounter = 0;
                listing.classList.remove('is-dragover');
                if (!e.dataTransfer || !e.dataTransfer.files.length) return;
                assignFileList(e.dataTransfer.files, isDirectoryFileList(e.dataTransfer.files));
                openUploadPanel();
                uploadForm.requestSubmit();
            });
        }
    })();

    (function() {
        var aboutOverlay = document.getElementById('about-modal');
        var btnAbout = document.getElementById('btn-about');
        var footerAbout = document.getElementById('footer-about');
        var aboutClose = document.getElementById('about-close');
        if (!aboutOverlay || !aboutClose) return;

        var aboutTrigger = btnAbout || footerAbout;
        var aboutUpdate = document.getElementById('about-update');
        var aboutUpdateStatus = document.getElementById('about-update-status');
        var aboutCheckBtn = document.getElementById('about-check-updates');
        var aboutApplyBtn = document.getElementById('about-apply-update');
        var aboutChannelSelect = document.getElementById('about-update-channel');
        var latestCheckData = null;
        var updateChannelStorageKey = 'dirindexUpdateChannel';

        function getAboutUpdateChannel() {
            if (!aboutChannelSelect) return 'stable';
            return aboutChannelSelect.value === 'dev' ? 'dev' : 'stable';
        }

        function buildAboutCheckUrl() {
            var base = aboutUpdate ? aboutUpdate.getAttribute('data-check-url') : '';
            if (!base) return '';
            var channel = getAboutUpdateChannel();
            return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'channel=' + encodeURIComponent(channel);
        }

        function setAboutUpdateStatus(text, tone) {
            if (!aboutUpdateStatus) return;
            aboutUpdateStatus.textContent = text;
            aboutUpdateStatus.className = 'about-update-status' + (tone ? ' is-' + tone : '');
        }

        function csrfTokenValue() {
            var input = document.querySelector('input[name="csrf_token"]');
            return input ? input.value : '';
        }

        function renderAboutUpdateState(data) {
            latestCheckData = data;
            if (aboutApplyBtn) aboutApplyBtn.hidden = true;
            if (!data || !data.ok) {
                setAboutUpdateStatus((data && data.error) ? data.error : 'Could not check for updates.', 'error');
                return;
            }
            if (data.up_to_date) {
                if (data.channel === 'dev') {
                    var currentRef = data.current_build_ref || 'latest';
                    setAboutUpdateStatus('You are on the latest dev build (' + currentRef + ', v' + data.current_version + ').', 'success');
                } else {
                    setAboutUpdateStatus('You are on the latest release (v' + data.current_version + ').', 'success');
                }
                return;
            }
            if (data.update_available) {
                var msg;
                if (data.channel === 'dev') {
                    msg = 'Dev build ' + (data.latest_build_ref || '?') + ' is available (v' + data.latest_version;
                    if (data.current_build_ref) msg += ', you have ' + data.current_build_ref;
                    else msg += ', stable install';
                    msg += ').';
                } else {
                    msg = 'v' + data.latest_version + ' is available (you have v' + data.current_version + ').';
                }
                if (data.error) {
                    msg += ' ' + data.error;
                    setAboutUpdateStatus(msg, 'warning');
                } else {
                    setAboutUpdateStatus(msg, 'warning');
                    if (aboutApplyBtn && data.can_update) {
                        aboutApplyBtn.textContent = data.channel === 'dev'
                            ? ('Update to dev ' + (data.latest_build_ref || ''))
                            : ('Update to v' + data.latest_version);
                        aboutApplyBtn.hidden = false;
                    }
                }
                return;
            }
            setAboutUpdateStatus('Could not compare versions.', 'error');
        }

        function checkAboutUpdates() {
            if (!aboutUpdate || !aboutCheckBtn) return;
            var checkUrl = buildAboutCheckUrl();
            if (!checkUrl) return;
            aboutCheckBtn.disabled = true;
            if (aboutApplyBtn) aboutApplyBtn.disabled = true;
            setAboutUpdateStatus('Checking GitHub for updates…', 'muted');
            fetch(checkUrl, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(renderAboutUpdateState)
                .catch(function() {
                    renderAboutUpdateState({ ok: false, error: 'Could not check for updates.' });
                })
                .finally(function() {
                    aboutCheckBtn.disabled = false;
                    if (aboutApplyBtn) aboutApplyBtn.disabled = false;
                });
        }

        function applyAboutUpdate() {
            if (!aboutUpdate || !aboutApplyBtn || !latestCheckData || !latestCheckData.can_update) return;
            var postUrl = aboutUpdate.getAttribute('data-post-url');
            if (!postUrl) return;
            var channel = latestCheckData.channel || getAboutUpdateChannel();
            var label = channel === 'dev'
                ? ('dev build ' + (latestCheckData.latest_build_ref || latestCheckData.latest_version))
                : ('v' + (latestCheckData.latest_version || 'latest'));
            if (!window.confirm('Replace ' + (latestCheckData.artifact || 'index.php') + ' with ' + label + ' from GitHub?')) {
                return;
            }
            var body = new FormData();
            body.append('action', 'app_update');
            body.append('ajax', '1');
            body.append('channel', channel);
            var csrf = csrfTokenValue();
            if (csrf) body.append('csrf_token', csrf);
            aboutApplyBtn.disabled = true;
            aboutCheckBtn.disabled = true;
            setAboutUpdateStatus('Downloading and applying ' + label + '…', 'muted');
            fetch(postUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        setAboutUpdateStatus(data.message || 'Update applied.', 'success');
                        window.setTimeout(function() { window.location.reload(); }, 900);
                    } else {
                        setAboutUpdateStatus(data.message || 'Could not apply the update.', 'error');
                    }
                })
                .catch(function() {
                    setAboutUpdateStatus('Could not apply the update.', 'error');
                })
                .finally(function() {
                    aboutApplyBtn.disabled = false;
                    aboutCheckBtn.disabled = false;
                });
        }

        function openAbout() {
            aboutOverlay.classList.add('is-open');
            aboutOverlay.setAttribute('aria-hidden', 'false');
            window.setTimeout(function() { aboutClose.focus(); }, 0);
        }
        function closeAbout() {
            aboutOverlay.classList.remove('is-open');
            aboutOverlay.setAttribute('aria-hidden', 'true');
            if (aboutTrigger) aboutTrigger.focus();
        }

        if (aboutChannelSelect) {
            try {
                var savedChannel = localStorage.getItem(updateChannelStorageKey);
                if (savedChannel === 'dev' || savedChannel === 'stable') {
                    aboutChannelSelect.value = savedChannel;
                }
            } catch (e) {}
            aboutChannelSelect.addEventListener('change', function() {
                try {
                    localStorage.setItem(updateChannelStorageKey, getAboutUpdateChannel());
                } catch (e) {}
                if (aboutApplyBtn) aboutApplyBtn.hidden = true;
                latestCheckData = null;
                setAboutUpdateStatus(getAboutUpdateChannel() === 'dev'
                    ? 'Check GitHub for a newer dev build.'
                    : 'Check GitHub for a newer release.', 'muted');
            });
        }

        if (btnAbout) btnAbout.addEventListener('click', openAbout);
        if (footerAbout) footerAbout.addEventListener('click', openAbout);
        aboutClose.addEventListener('click', closeAbout);
        if (aboutCheckBtn) aboutCheckBtn.addEventListener('click', checkAboutUpdates);
        if (aboutApplyBtn) aboutApplyBtn.addEventListener('click', applyAboutUpdate);
        aboutOverlay.addEventListener('click', function(e) {
            if (e.target === aboutOverlay) closeAbout();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && aboutOverlay.classList.contains('is-open')) {
                closeAbout();
                e.stopPropagation();
            }
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
        var accountOverlay = document.getElementById('account-modal');
        var btnAccount = document.getElementById('btn-account');
        var accountClose = document.getElementById('account-close');
        var adminUsernameInput = document.getElementById('admin-username');
        if (!accountOverlay || !btnAccount || !accountClose) return;

        function openAccount() {
            accountOverlay.classList.add('is-open');
            accountOverlay.setAttribute('aria-hidden', 'false');
            window.setTimeout(function() {
                if (adminUsernameInput) adminUsernameInput.focus();
            }, 0);
        }
        function closeAccount() {
            accountOverlay.classList.remove('is-open');
            accountOverlay.setAttribute('aria-hidden', 'true');
            btnAccount.focus();
        }

        btnAccount.addEventListener('click', openAccount);
        accountClose.addEventListener('click', closeAccount);
        accountOverlay.addEventListener('click', function(e) {
            if (e.target === accountOverlay) closeAccount();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && accountOverlay.classList.contains('is-open')) {
                closeAccount();
                e.stopPropagation();
            }
        });
        if (document.body.getAttribute('data-open-account') === '1') {
            openAccount();
        }
    })();

    (function() {
        var overlay = document.getElementById('file-modal');
        var modalPanel = document.getElementById('file-modal-panel');
        var titleEl = document.getElementById('modal-title');
        var downloadLinkEl = document.getElementById('modal-download-link');
        var openLinkEl = document.getElementById('modal-open-link');
        var codeEl = document.getElementById('modal-code');
        var modalPre = document.getElementById('modal-pre');
        var modalMd = document.getElementById('modal-md');
        var modalBinary = document.getElementById('modal-binary');
        var modalBinaryIcon = document.getElementById('modal-binary-icon');
        var modalBinaryName = document.getElementById('modal-binary-name');
        var modalBinaryDownload = document.getElementById('modal-binary-download');
        var modalImage = document.getElementById('modal-image');
        var modalImageEl = document.getElementById('modal-image-el');
        var modalFileMeta = document.getElementById('modal-file-meta');
        var closeBtn = document.getElementById('modal-close');
        var shareBtn = document.getElementById('modal-share-btn');
        var currentSharePath = '';
        var modalHashesOpen = false;
        var modalMetaUrl = '';
        var modalMetaFallback = {};
        var modalHashesLoaded = false;
        var modalHashesLoading = false;
        var modalHashesError = '';
        var modalBrokenLink = false;
        var modalBrokenLinkTarget = '';
        var modalMetaRequestId = 0;
        var modalBrokenBadge = document.getElementById('modal-broken-badge');
        var modalBrokenNotice = document.getElementById('modal-broken-notice');

        function isEmptyMetaValue(value) {
            var text = (value || '').trim();
            return text === '' || text === '\u2014' || text === '-' || text === '\u002d';
        }
        function listingLinkIsBroken(el) {
            if (!el) return false;
            if (el.getAttribute('data-broken-link') === '1') return true;
            if (el.getAttribute('data-is-symlink') !== '1') return false;
            if (!el.getAttribute('data-link-target')) return false;
            return isEmptyMetaValue(el.getAttribute('data-size'));
        }
        function sharePathFromContentUrl(contentUrl, fileName) {
            if (!contentUrl) return fileName || '';
            var pathMatch = contentUrl.match(/[?&]path=([^&]+)/);
            if (!pathMatch) return fileName || '';
            return decodeURIComponent(pathMatch[1].replace(/\+/g, ' '));
        }
        function resetModalActionLinks() {
            setModalDownloadLink('');
            setModalOpenLink('');
            if (modalBinaryDownload) {
                modalBinaryDownload.hidden = true;
                modalBinaryDownload.removeAttribute('href');
            }
            if (shareBtn) shareBtn.hidden = true;
        }
        function setModalSharePath(path) {
            currentSharePath = path || '';
            if (!shareBtn) return;
            shareBtn.hidden = modalBrokenLink || !currentSharePath;
            if (currentSharePath) shareBtn.setAttribute('data-share-path', currentSharePath);
            else shareBtn.removeAttribute('data-share-path');
        }

        function metaFromElement(el) {
            if (!el) return {};
            return {
                type: el.getAttribute('data-type') || el.getAttribute('data-open-type') || '',
                size: el.getAttribute('data-size') || el.getAttribute('data-open-size') || '',
                mtime: el.getAttribute('data-mtime') || el.getAttribute('data-open-mtime') || '',
                perms: el.getAttribute('data-perms') || el.getAttribute('data-open-perms') || '',
                linkTarget: el.getAttribute('data-link-target') || el.getAttribute('data-open-link-target') || ''
            };
        }
        function brokenLinkNoticeText(linkTarget) {
            var target = (linkTarget || '').trim();
            return target !== '' ? 'Broken symbolic link → ' + target : 'Broken symbolic link';
        }
        function setModalBrokenLinkState(brokenLink, linkTarget) {
            modalBrokenLink = !!brokenLink;
            modalBrokenLinkTarget = linkTarget || '';
            if (modalBrokenBadge) modalBrokenBadge.hidden = !modalBrokenLink;
            if (modalBrokenNotice) {
                if (modalBrokenLink) {
                    modalBrokenNotice.textContent = brokenLinkNoticeText(modalBrokenLinkTarget);
                    modalBrokenNotice.hidden = false;
                } else {
                    modalBrokenNotice.hidden = true;
                    modalBrokenNotice.textContent = '';
                }
            }
            if (modalBrokenLink) {
                setModalDownloadLink('');
                setModalOpenLink('');
                if (modalBinaryDownload) {
                    modalBinaryDownload.hidden = true;
                    modalBinaryDownload.removeAttribute('href');
                }
                if (shareBtn) shareBtn.hidden = true;
            }
        }
        function metaHashesFromApi(data) {
            var hashes = (data && data.hashes && typeof data.hashes === 'object') ? data.hashes : {};
            return {
                crc32: hashes.crc32 || '',
                md5: hashes.md5 || '',
                sha1: hashes.sha1 || '',
                sha256: hashes.sha256 || '',
                sha512: hashes.sha512 || ''
            };
        }
        function metaFromApi(data) {
            if (!data) return {};
            var meta = Object.assign({
                type: data.ext ? ('.' + data.ext) : '',
                size: data.size_formatted || '',
                mtime: data.mtime_formatted || '',
                perms: data.perms || ''
            }, metaHashesFromApi(data));
            if (data.error) meta.hashesError = data.error;
            if (data.broken_link) {
                meta.brokenLink = true;
                meta.linkTarget = data.link_target || meta.linkTarget || '';
            }
            return meta;
        }
        function mergeModalMeta(primary, fallback) {
            return {
                type: primary.type || fallback.type || '',
                size: primary.size || fallback.size || '',
                mtime: primary.mtime || fallback.mtime || '',
                perms: primary.perms || fallback.perms || '',
                crc32: primary.crc32 || fallback.crc32 || '',
                md5: primary.md5 || fallback.md5 || '',
                sha1: primary.sha1 || fallback.sha1 || '',
                sha256: primary.sha256 || fallback.sha256 || '',
                sha512: primary.sha512 || fallback.sha512 || '',
                hashesError: primary.hashesError || fallback.hashesError || '',
                linkTarget: primary.linkTarget || fallback.linkTarget || '',
                brokenLink: !!(primary.brokenLink || fallback.brokenLink)
            };
        }
        function appendModalMetaItem(parent, label, value, wide) {
            var item = document.createElement('div');
            item.className = 'modal-file-meta-item' + (wide ? ' modal-file-meta-item--wide' : '');
            var labelEl = document.createElement('span');
            labelEl.className = 'modal-file-meta-label';
            labelEl.textContent = label;
            var valueEl = document.createElement('span');
            valueEl.className = 'modal-file-meta-value';
            valueEl.textContent = value;
            item.appendChild(labelEl);
            item.appendChild(valueEl);
            parent.appendChild(item);
        }
        function modalMetaHasHashValues(meta) {
            return !!(meta && (meta.crc32 || meta.md5 || meta.sha1 || meta.sha256 || meta.sha512));
        }
        function setModalMeta(meta) {
            if (!modalFileMeta) return;
            var existingHashes = modalFileMeta.querySelector('.modal-file-meta-hashes');
            if (existingHashes) modalHashesOpen = existingHashes.open;
            var basicFields = [
                { label: 'Type', value: meta.type || '' },
                { label: 'Size', value: meta.size || '' },
                { label: 'Modified', value: meta.mtime || '' },
                { label: 'Permissions', value: meta.perms || '' }
            ];
            var hashFields = [
                { label: 'CRC32', value: meta.crc32 || '' },
                { label: 'MD5', value: meta.md5 || '' },
                { label: 'SHA-1', value: meta.sha1 || '' },
                { label: 'SHA-256', value: meta.sha256 || '' },
                { label: 'SHA-512', value: meta.sha512 || '' }
            ];
            modalFileMeta.innerHTML = '';
            var hasValue = false;
            var primary = document.createElement('div');
            primary.className = 'modal-file-meta-primary';
            basicFields.forEach(function(field) {
                if (!field.value) return;
                hasValue = true;
                appendModalMetaItem(primary, field.label, field.value, false);
            });
            if (primary.childNodes.length) modalFileMeta.appendChild(primary);
            var hashCount = hashFields.filter(function(field) { return !!field.value; }).length;
            var hashesError = meta.hashesError || modalHashesError || '';
            var showLazyChecksums = !!modalMetaUrl && !modalHashesLoaded && !hashesError;
            if (hashCount || showLazyChecksums || hashesError) {
                hasValue = true;
                var hashesPanel = document.createElement('details');
                hashesPanel.className = 'modal-file-meta-hashes';
                if (modalHashesOpen) hashesPanel.open = true;
                var summary = document.createElement('summary');
                summary.className = 'modal-file-meta-hashes-summary';
                if (hashesError) {
                    summary.textContent = 'Checksums (unavailable)';
                } else if (modalHashesLoading) {
                    summary.textContent = 'Checksums (computing…)';
                } else if (hashCount) {
                    summary.textContent = 'Checksums (' + hashCount + ')';
                } else {
                    summary.textContent = 'Checksums';
                }
                hashesPanel.appendChild(summary);
                var hashesBody = document.createElement('div');
                hashesBody.className = 'modal-file-meta-hashes-body';
                hashFields.forEach(function(field) {
                    if (!field.value) return;
                    appendModalMetaItem(hashesBody, field.label, field.value, true);
                });
                if (hashesError) {
                    var err = document.createElement('p');
                    err.className = 'modal-file-meta-hashes-hint modal-file-meta-hashes-error';
                    err.textContent = hashesError;
                    hashesBody.appendChild(err);
                } else if (showLazyChecksums && !hashCount) {
                    var hint = document.createElement('p');
                    hint.className = 'modal-file-meta-hashes-hint';
                    hint.textContent = modalHashesLoading ? 'Reading file and computing checksums…' : 'Expand to compute checksums.';
                    hashesBody.appendChild(hint);
                }
                hashesPanel.appendChild(hashesBody);
                modalFileMeta.appendChild(hashesPanel);
            }
            modalFileMeta.hidden = !hasValue;
        }
        function modalMetaUrlWithHashes(metaUrl) {
            if (!metaUrl) return '';
            if (metaUrl.indexOf('hashes=') >= 0) return metaUrl;
            return metaUrl + (metaUrl.indexOf('?') >= 0 ? '&' : '?') + 'hashes=1';
        }
        function setModalHashesError(message) {
            modalHashesError = message || '';
            modalHashesLoaded = true;
            modalHashesLoading = false;
            modalMetaFallback = Object.assign({}, modalMetaFallback, { hashesError: modalHashesError });
            setModalMeta(modalMetaFallback);
        }
        function loadModalHashes() {
            if (!modalMetaUrl || modalHashesLoaded || modalHashesLoading) return;
            if (modalBrokenLink) {
                setModalHashesError('File does not exist (broken symbolic link).');
                return;
            }
            modalHashesLoading = true;
            modalHashesError = '';
            setModalMeta(modalMetaFallback);
            fetch(modalMetaUrlWithHashes(modalMetaUrl)).then(function(r) {
                return r.json().then(function(data) {
                    if (data && data.error) {
                        setModalHashesError(data.error);
                        return;
                    }
                    modalHashesLoaded = modalMetaHasHashValues(metaFromApi(data));
                    modalHashesLoading = false;
                    modalHashesError = '';
                    modalMetaFallback = mergeModalMeta(metaFromApi(data), modalMetaFallback);
                    setModalMeta(modalMetaFallback);
                });
            }).catch(function() {
                setModalHashesError('Could not compute checksums.');
            });
        }
        function loadModalMeta(metaUrl, fallbackMeta, brokenLink) {
            modalMetaUrl = metaUrl || '';
            modalMetaFallback = fallbackMeta || {};
            modalBrokenLink = !!brokenLink;
            modalHashesLoaded = modalMetaHasHashValues(modalMetaFallback);
            modalHashesLoading = false;
            modalHashesError = modalMetaFallback.hashesError || '';
            setModalMeta(modalMetaFallback);
            if (!metaUrl) return;
            var requestId = ++modalMetaRequestId;
            fetch(metaUrl).then(function(r) {
                return r.json().then(function(data) {
                    if (requestId !== modalMetaRequestId) return;
                    modalMetaFallback = mergeModalMeta(metaFromApi(data), fallbackMeta || {});
                    if (data && data.error) {
                        modalHashesError = data.error;
                        modalMetaFallback.hashesError = data.error;
                    }
                    var metaBroken = !!(data && data.broken_link);
                    if (metaBroken) {
                        setModalBrokenLinkState(true, (data && data.link_target) || modalMetaFallback.linkTarget || '');
                    } else if (!brokenLink) {
                        setModalBrokenLinkState(false, '');
                    }
                    if (modalMetaHasHashValues(modalMetaFallback)) {
                        modalHashesLoaded = true;
                    }
                    setModalMeta(modalMetaFallback);
                });
            }).catch(function() {});
        }
        function clearModalMeta() {
            modalHashesOpen = false;
            modalMetaUrl = '';
            modalMetaFallback = {};
            modalHashesLoaded = false;
            modalHashesLoading = false;
            modalHashesError = '';
            modalBrokenLink = false;
            setModalMeta({});
        }
        if (modalFileMeta) {
            modalFileMeta.addEventListener('toggle', function(e) {
                var panel = e.target;
                if (!panel.classList || !panel.classList.contains('modal-file-meta-hashes') || !panel.open) return;
                loadModalHashes();
            }, true);
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
            if (modalImage) {
                modalImage.hidden = true;
                modalImage.setAttribute('aria-hidden', 'true');
            }
            if (modalImageEl) modalImageEl.removeAttribute('src');
            if (modalPanel) {
                modalPanel.classList.remove('is-binary');
                modalPanel.classList.remove('is-image');
                modalPanel.classList.remove('is-markdown');
            }
            resetModalActionLinks();
            setModalBrokenLinkState(false, '');
            clearModalMeta();
        }

        function closeModal() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            hidePreviewPanels();
            modalPre.style.display = '';
            setModalDownloadLink('');
            openLinkEl.hidden = true;
            openLinkEl.removeAttribute('href');
            setModalSharePath('');
            removeOpenFromUrl();
        }
        function setModalDownloadLink(downloadUrl, fileName) {
            if (!downloadLinkEl) return;
            if (downloadUrl) {
                downloadLinkEl.href = downloadUrl;
                if (fileName) downloadLinkEl.setAttribute('download', fileName);
                else downloadLinkEl.setAttribute('download', '');
                downloadLinkEl.hidden = false;
            } else {
                downloadLinkEl.hidden = true;
                downloadLinkEl.removeAttribute('href');
                downloadLinkEl.removeAttribute('download');
            }
        }
        function setModalOpenLink(openUrl) {
            if (openUrl) {
                openLinkEl.href = openUrl;
                openLinkEl.hidden = false;
            } else {
                openLinkEl.hidden = true;
                openLinkEl.removeAttribute('href');
            }
        }
        function highlightModalMarkdown() {
            if (!modalMd || !window.hljs) return;
            modalMd.querySelectorAll('pre code').forEach(function(el) {
                hljs.highlightElement(el);
            });
        }
        function openModal(name, content, lang, html, openUrl, sharePath, meta, downloadUrl, brokenLink, linkTarget) {
            hidePreviewPanels();
            modalMetaUrl = '';
            modalMetaFallback = meta || {};
            modalHashesLoaded = modalMetaHasHashValues(modalMetaFallback);
            modalHashesLoading = false;
            titleEl.textContent = name;
            var isBroken = !!brokenLink || !!modalMetaFallback.brokenLink;
            var target = linkTarget || modalMetaFallback.linkTarget || '';
            setModalSharePath(sharePath || '');
            setModalBrokenLinkState(isBroken, target);
            setModalMeta(modalMetaFallback);
            if (!isBroken) {
                setModalDownloadLink(downloadUrl || '', name);
                setModalOpenLink(openUrl || '');
            }
            if (html != null) {
                modalMd.innerHTML = html;
                modalMd.classList.add('is-visible');
                modalMd.setAttribute('aria-hidden', 'false');
                highlightModalMarkdown();
                if (modalPanel) modalPanel.classList.add('is-markdown');
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
        function openBinaryModal(name, size, mtime, iconHtml, downloadUrl, sharePath, pushStateUrl, meta, metaUrl, brokenLink, linkTarget) {
            hidePreviewPanels();
            if (modalPanel) modalPanel.classList.add('is-binary');
            titleEl.textContent = name;
            var fileMeta = meta || {};
            if (!fileMeta.size && size) fileMeta.size = size;
            if (!fileMeta.mtime && mtime) fileMeta.mtime = mtime;
            var isBroken = !!brokenLink;
            var target = linkTarget || fileMeta.linkTarget || '';
            setModalSharePath(sharePath || '');
            setModalBrokenLinkState(isBroken, target);
            loadModalMeta(metaUrl || '', fileMeta, isBroken);
            modalBinaryIcon.innerHTML = iconHtml || '';
            modalBinaryName.textContent = name;
            if (!isBroken && downloadUrl) {
                modalBinaryDownload.href = downloadUrl;
                modalBinaryDownload.hidden = false;
            } else {
                modalBinaryDownload.hidden = true;
                modalBinaryDownload.removeAttribute('href');
            }
            setModalDownloadLink('');
            if (!isBroken) setModalOpenLink(downloadUrl || '');
            modalBinary.hidden = false;
            modalBinary.setAttribute('aria-hidden', 'false');
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            if (pushStateUrl !== undefined) history.pushState({ modal: true }, '', pushStateUrl);
        }
        function openImageModal(name, imageUrl, openUrl, sharePath, pushStateUrl, meta, metaUrl, downloadUrl, brokenLink, linkTarget) {
            hidePreviewPanels();
            if (modalPanel) modalPanel.classList.add('is-image');
            titleEl.textContent = name;
            var fileMeta = meta || {};
            var isBroken = !!brokenLink;
            var target = linkTarget || fileMeta.linkTarget || '';
            setModalSharePath(sharePath || '');
            setModalBrokenLinkState(isBroken, target);
            loadModalMeta(metaUrl || '', fileMeta, isBroken);
            if (!isBroken) {
                setModalDownloadLink(downloadUrl || '', name);
                setModalOpenLink(openUrl || '');
            }
            if (modalImageEl) {
                modalImageEl.src = imageUrl;
                modalImageEl.alt = name;
            }
            if (modalImage) {
                modalImage.hidden = false;
                modalImage.setAttribute('aria-hidden', 'false');
            }
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            if (pushStateUrl !== undefined) history.pushState({ modal: true }, '', pushStateUrl);
        }

        function openModalFromContentUrl(contentUrl, name, openUrl, sharePath, pushStateUrl, meta, downloadUrl, brokenLink) {
            var fallbackMeta = meta || {};
            var isBrokenLink = !!brokenLink;
            fetch(contentUrl).then(function(r) { return r.json(); }).then(function(data) {
                var path = sharePath || sharePathFromContentUrl(contentUrl, data.name || name);
                var mergedMeta = mergeModalMeta(metaFromApi(data), fallbackMeta);
                var renderHtml = Object.prototype.hasOwnProperty.call(data, 'html') ? data.html : null;
                var isBroken = isBrokenLink || !!mergedMeta.brokenLink;
                openModal(data.name || name, data.content || '', data.lang || 'plaintext', renderHtml, openUrl, path, mergedMeta, isBroken ? '' : downloadUrl, isBroken, mergedMeta.linkTarget || fallbackMeta.linkTarget || '');
                if (pushStateUrl !== undefined) history.pushState({ modal: true }, '', pushStateUrl);
            }).catch(function() {
                if (pushStateUrl === undefined) window.location.href = contentUrl.split('&content')[0];
            });
        }

        document.addEventListener('click', function(e) {
            var previewLink = e.target.closest('a.file-preview');
            if (previewLink) {
                e.preventDefault();
                var name = previewLink.getAttribute('data-name') || '';
                var imageUrl = previewLink.getAttribute('data-image-url');
                var openUrl = previewLink.getAttribute('data-open-url') || '';
                var broken = listingLinkIsBroken(previewLink);
                var downloadUrl = broken ? '' : (previewLink.getAttribute('data-download-url') || '');
                var sharePath = previewLink.getAttribute('data-share-path') || '';
                var linkTarget = previewLink.getAttribute('data-link-target') || '';
                if (imageUrl) {
                    var imageListingUrl = buildListingUrlWithOpen('', name, sharePath);
                    openImageModal(name, imageUrl, openUrl, sharePath, imageListingUrl, metaFromElement(previewLink), previewLink.getAttribute('data-meta-url') || '', downloadUrl, broken, linkTarget);
                    return;
                }
                var contentUrl = previewLink.getAttribute('data-content-url');
                if (!contentUrl) return;
                sharePath = sharePath || sharePathFromContentUrl(contentUrl, name);
                var listingUrl = buildListingUrlWithOpen(contentUrl, name, sharePath);
                openModalFromContentUrl(contentUrl, name, openUrl, sharePath, listingUrl, metaFromElement(previewLink), downloadUrl, broken);
                return;
            }
            var binaryLink = e.target.closest('a.file-binary');
            if (!binaryLink) return;
            e.preventDefault();
            var binaryName = binaryLink.getAttribute('data-name') || '';
            if (!binaryName) return;
            var broken = listingLinkIsBroken(binaryLink);
            var downloadUrl = broken ? '' : (binaryLink.getAttribute('data-download-url') || '');
            var binarySharePath = binaryLink.getAttribute('data-share-path') || '';
            var listingUrl = buildListingUrlWithOpen('', binaryName, binarySharePath);
            openBinaryModal(
                binaryName,
                binaryLink.getAttribute('data-size') || '',
                binaryLink.getAttribute('data-mtime') || '',
                binaryLink.getAttribute('data-icon-html') || '',
                downloadUrl,
                binarySharePath,
                listingUrl,
                metaFromElement(binaryLink),
                binaryLink.getAttribute('data-meta-url') || '',
                broken,
                binaryLink.getAttribute('data-link-target') || ''
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
                body.getAttribute('data-open-share-path') || '',
                undefined,
                metaFromElement(body),
                body.getAttribute('data-open-meta-url') || '',
                body.getAttribute('data-open-broken-link') === '1',
                body.getAttribute('data-open-link-target') || ''
            );
        } else if (body.getAttribute('data-open-image') === '1') {
            openImageModal(
                body.getAttribute('data-open-name') || '',
                body.getAttribute('data-open-image-url') || '',
                body.getAttribute('data-open-url') || '',
                body.getAttribute('data-open-share-path') || '',
                undefined,
                metaFromElement(body),
                body.getAttribute('data-open-meta-url') || '',
                body.getAttribute('data-open-download-url') || '',
                body.getAttribute('data-open-broken-link') === '1',
                body.getAttribute('data-open-link-target') || ''
            );
        } else {
            var initialContentUrl = body.getAttribute('data-open-content-url');
            if (initialContentUrl) {
                var initialName = body.getAttribute('data-open-name') || '';
                var initialOpenUrl = body.getAttribute('data-open-url') || '';
                var initialDownloadUrl = body.getAttribute('data-open-download-url') || '';
                var initialSharePath = body.getAttribute('data-open-share-path') || sharePathFromContentUrl(initialContentUrl, initialName);
                openModalFromContentUrl(initialContentUrl, initialName, initialOpenUrl, initialSharePath, undefined, metaFromElement(body), initialDownloadUrl);
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
        var STORAGE_COL_OWNER = 'dirindex_col_owner';
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
                if (['name', 'size', 'modified', 'owner', 'perms'].indexOf(parsed.col) === -1) return null;
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
            } else if (col === 'owner') {
                va = (a.getAttribute('data-sort-owner') || '').toLowerCase();
                vb = (b.getAttribute('data-sort-owner') || '').toLowerCase();
                if (va < vb) return -1 * mul;
                if (va > vb) return 1 * mul;
                var ownerTieA = (a.getAttribute('data-sort-name') || '').toLowerCase();
                var ownerTieB = (b.getAttribute('data-sort-name') || '').toLowerCase();
                if (ownerTieA < ownerTieB) return -1;
                if (ownerTieA > ownerTieB) return 1;
                return 0;
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
            var canReset = !isDefaultSort(sort);
            if (sortResetBtn) sortResetBtn.disabled = !canReset;
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
            var showOwner = getSetting(STORAGE_COL_OWNER, '1') !== '0';
            var showPerms = getSetting(STORAGE_COL_PERMS, '1') !== '0';
            table.classList.toggle('listing-hide-size', !showSize);
            table.classList.toggle('listing-hide-modified', !showModified);
            table.classList.toggle('listing-hide-owner', !showOwner);
            table.classList.toggle('listing-hide-perms', !showPerms);
            var sizeCheck = document.getElementById('setting-col-size');
            var modCheck = document.getElementById('setting-col-modified');
            var ownerCheck = document.getElementById('setting-col-owner');
            var permsCheck = document.getElementById('setting-col-perms');
            if (sizeCheck) {
                sizeCheck.checked = showSize;
                sizeCheck.setAttribute('aria-checked', showSize ? 'true' : 'false');
            }
            if (modCheck) {
                modCheck.checked = showModified;
                modCheck.setAttribute('aria-checked', showModified ? 'true' : 'false');
            }
            if (ownerCheck) {
                ownerCheck.checked = showOwner;
                ownerCheck.setAttribute('aria-checked', showOwner ? 'true' : 'false');
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
        wireColumnCheckbox(document.getElementById('setting-col-owner'), STORAGE_COL_OWNER);
        wireColumnCheckbox(document.getElementById('setting-col-perms'), STORAGE_COL_PERMS);
        wireColumnPicker();

        applyColumns();
        var initialSort = parseSort();
        if (initialSort) applySort(initialSort);
        else updateSortUi(null);
    })();

    (function() {
        var STORAGE = { theme: 'dirindex_theme', font: 'dirindex_font', breadcrumb: 'dirindex_breadcrumb' };
        var FONT_CLASSES = ['font-xs', 'font-sm', 'font-lg', 'font-xl'];
        var FONT_OPTIONS = ['xs', 'sm', 'md', 'lg', 'xl'];
        var THEME_OPTIONS = ['system', 'light', 'dark'];
        var DEFAULT_BREADCRUMB = '\u203A';
        var settingsOverlay = document.getElementById('settings-modal');
        var btnSettings = document.getElementById('btn-settings');
        var settingsClose = document.getElementById('settings-close');
        var hljsTheme = document.getElementById('hljs-theme');
        var themeButtons = Array.prototype.slice.call(document.querySelectorAll('[data-theme-option]'));
        var fontButtons = Array.prototype.slice.call(document.querySelectorAll('[data-font-option]'));
        var breadcrumbInput = document.getElementById('setting-breadcrumb-sep');
        var systemThemeQuery = window.matchMedia('(prefers-color-scheme: light)');

        function getSetting(key, def) {
            try { return localStorage.getItem(key) || def; } catch (e) { return def; }
        }
        function setSetting(key, val) {
            try { localStorage.setItem(key, val); } catch (e) {}
        }

        function normalizeTheme(value) {
            return THEME_OPTIONS.indexOf(value) !== -1 ? value : 'dark';
        }
        function normalizeFont(value) {
            if (value === 'normal') return 'md';
            if (value === 'large') return 'lg';
            return FONT_OPTIONS.indexOf(value) !== -1 ? value : 'md';
        }
        function normalizeBreadcrumb(value) {
            if (value === 'chevron') return DEFAULT_BREADCRUMB;
            if (value === 'slash') return '/';
            if (typeof value !== 'string') return DEFAULT_BREADCRUMB;
            var trimmed = value.replace(/[\r\n\t]/g, '').trim();
            return trimmed === '' ? DEFAULT_BREADCRUMB : trimmed.slice(0, 6);
        }

        function resolveTheme(mode) {
            mode = normalizeTheme(mode);
            if (mode === 'system') {
                return systemThemeQuery.matches ? 'light' : 'dark';
            }
            return mode;
        }

        function applyTheme(mode) {
            var light = resolveTheme(mode) === 'light';
            if (light) {
                document.documentElement.classList.add('theme-light');
                if (hljsTheme) hljsTheme.href = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-light.min.css';
            } else {
                document.documentElement.classList.remove('theme-light');
                if (hljsTheme) hljsTheme.href = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css';
            }
        }
        function applyFont(size) {
            size = normalizeFont(size);
            FONT_CLASSES.forEach(function(cls) { document.documentElement.classList.remove(cls); });
            if (size === 'xs') document.documentElement.classList.add('font-xs');
            else if (size === 'sm') document.documentElement.classList.add('font-sm');
            else if (size === 'lg') document.documentElement.classList.add('font-lg');
            else if (size === 'xl') document.documentElement.classList.add('font-xl');
        }
        function applyBreadcrumb(sep) {
            sep = normalizeBreadcrumb(sep);
            document.querySelectorAll('.breadcrumb-sep').forEach(function(el) { el.textContent = sep; });
        }

        function syncThemeUi(mode) {
            mode = normalizeTheme(mode);
            themeButtons.forEach(function(btn) {
                var active = btn.getAttribute('data-theme-option') === mode;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }
        function syncFontUi(size) {
            size = normalizeFont(size);
            fontButtons.forEach(function(btn) {
                var active = btn.getAttribute('data-font-option') === size;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }
        function syncBreadcrumbUi(sep) {
            if (!breadcrumbInput) return;
            sep = normalizeBreadcrumb(sep);
            breadcrumbInput.value = sep === DEFAULT_BREADCRUMB ? '' : sep;
            breadcrumbInput.placeholder = DEFAULT_BREADCRUMB;
        }

        function loadAndApply() {
            var theme = normalizeTheme(getSetting(STORAGE.theme, 'dark'));
            var font = normalizeFont(getSetting(STORAGE.font, 'md'));
            var breadcrumb = normalizeBreadcrumb(getSetting(STORAGE.breadcrumb, DEFAULT_BREADCRUMB));
            applyTheme(theme);
            applyFont(font);
            applyBreadcrumb(breadcrumb);
            syncThemeUi(theme);
            syncFontUi(font);
            syncBreadcrumbUi(breadcrumb);
        }

        themeButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var mode = normalizeTheme(btn.getAttribute('data-theme-option'));
                setSetting(STORAGE.theme, mode);
                applyTheme(mode);
                syncThemeUi(mode);
            });
        });
        fontButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var size = normalizeFont(btn.getAttribute('data-font-option'));
                setSetting(STORAGE.font, size);
                applyFont(size);
                syncFontUi(size);
            });
        });
        if (breadcrumbInput) {
            breadcrumbInput.addEventListener('input', function() {
                var raw = breadcrumbInput.value.replace(/[\r\n\t]/g, '').slice(0, 6);
                if (raw !== breadcrumbInput.value) breadcrumbInput.value = raw;
                setSetting(STORAGE.breadcrumb, raw);
                applyBreadcrumb(raw);
            });
        }
        if (typeof systemThemeQuery.addEventListener === 'function') {
            systemThemeQuery.addEventListener('change', function() {
                if (normalizeTheme(getSetting(STORAGE.theme, 'dark')) === 'system') {
                    applyTheme('system');
                }
            });
        } else if (typeof systemThemeQuery.addListener === 'function') {
            systemThemeQuery.addListener(function() {
                if (normalizeTheme(getSetting(STORAGE.theme, 'dark')) === 'system') {
                    applyTheme('system');
                }
            });
        }

        function openSettings() {
            if (!settingsOverlay) return;
            settingsOverlay.classList.add('is-open');
            settingsOverlay.setAttribute('aria-hidden', 'false');
        }
        function closeSettings() {
            if (!settingsOverlay) return;
            settingsOverlay.classList.remove('is-open');
            settingsOverlay.setAttribute('aria-hidden', 'true');
        }

        var PANEL_STORAGE = 'dirindex_settings_panels';
        function readPanelStates() {
            try { return JSON.parse(localStorage.getItem(PANEL_STORAGE) || '{}'); } catch (e) { return {}; }
        }
        function writePanelState(panelId, isOpen) {
            if (!panelId) return;
            var saved = readPanelStates();
            saved[panelId] = !!isOpen;
            try { localStorage.setItem(PANEL_STORAGE, JSON.stringify(saved)); } catch (e) {}
        }
        function initSettingsPanels() {
            if (!settingsOverlay) return;
            var saved = readPanelStates();
            settingsOverlay.querySelectorAll('.settings-panel[data-settings-panel]').forEach(function(panel) {
                var id = panel.getAttribute('data-settings-panel');
                if (id && Object.prototype.hasOwnProperty.call(saved, id)) {
                    panel.open = !!saved[id];
                }
                panel.addEventListener('toggle', function() {
                    writePanelState(id, panel.open);
                });
            });
            var focusId = document.body.getAttribute('data-settings-panel');
            if (focusId) {
                var focusPanel = settingsOverlay.querySelector('[data-settings-panel="' + focusId + '"]');
                if (focusPanel) focusPanel.open = true;
            }
        }
        initSettingsPanels();
        if (document.body.getAttribute('data-open-settings') === '1') {
            openSettings();
            var settingsMessage = document.getElementById('settings-modal-message');
            if (settingsMessage && settingsMessage.classList.contains('settings-modal-message--toast')) {
                window.setTimeout(function() {
                    settingsMessage.classList.add('is-hiding');
                    window.setTimeout(function() {
                        settingsMessage.hidden = true;
                    }, 260);
                }, 4000);
            }
            try {
                var cleanParams = new URLSearchParams(window.location.search);
                if (cleanParams.has('msg')) {
                    cleanParams.delete('msg');
                    var cleanQuery = cleanParams.toString();
                    var cleanUrl = window.location.pathname + (cleanQuery ? '?' + cleanQuery : '') + window.location.hash;
                    history.replaceState(null, '', cleanUrl);
                }
            } catch (e) {}
        }

        if (btnSettings) btnSettings.addEventListener('click', openSettings);
        if (settingsClose) settingsClose.addEventListener('click', closeSettings);
        if (settingsOverlay) {
            settingsOverlay.addEventListener('click', function(e) {
                if (e.target === settingsOverlay) closeSettings();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && settingsOverlay && settingsOverlay.classList.contains('is-open')) {
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

        var resetForm = document.getElementById('reset-settings-form');
        var btnResetSettings = document.getElementById('btn-reset-settings');
        if (resetForm && btnResetSettings) {
            btnResetSettings.addEventListener('click', function() {
                showConfirmModal({
                    title: 'Reset all settings?',
                    message: 'Delete stored settings and return to first-run setup?',
                    detail: 'This removes the admin account, access rules, and share links. It cannot be undone.',
                    confirmLabel: 'Reset',
                    danger: true
                }).then(function(confirmed) {
                    if (confirmed) resetForm.submit();
                });
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
