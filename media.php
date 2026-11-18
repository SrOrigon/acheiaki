<?php
/**
 * Servimento seguro + otimização sob demanda (WebP/AVIF + resize + ETag + CDN)
 * para imagens de produtos (assets/lojas ou SQL pz_media_store) e assets/images.
 */
require_once __DIR__ . '/db_pdo.php';
require_once __DIR__ . '/media_cdn_lib.php';

$tenant = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_GET['t'] ?? 'default')) ?: 'default';
$file = basename((string) ($_GET['f'] ?? ''));

if ($file === '' || !preg_match('/^[a-zA-Z0-9._-]+\.(jpg|jpeg|png|webp|gif|svg)$/i', $file)) {
    http_response_code(404);
    exit;
}

$mimeMap = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = $mimeMap[$ext] ?? 'image/jpeg';

$targetFmt = pz_media_client_aceita_webp();
$maxW = isset($_GET['w']) && is_numeric($_GET['w']) ? (int) $_GET['w'] : ($tenant === 'assets' && in_array($ext, ['png', 'jpg', 'jpeg'], true) && $targetFmt !== '' ? 960 : 0);
$quality = isset($_GET['q']) && is_numeric($_GET['q']) ? (int) $_GET['q'] : 80;

$etag = '"' . sha1($tenant . '|' . $file . '|' . $targetFmt . '|' . $maxW . '|' . $quality) . '"';
$cacheHeader = (strpos($file, 'prod_') === 0 || $tenant === 'assets')
    ? 'public, max-age=2592000, immutable'
    : 'public, max-age=604800';
header('ETag: ' . $etag);
header('Cache-Control: ' . $cacheHeader);
header('Vary: Accept');
header('X-Content-Type-Options: nosniff');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

$rawBytes = null;
$rawMime = $mime;

if ($tenant === 'assets') {
    $diskPath = __DIR__ . '/assets/images/' . $file;
    if (is_file($diskPath) && is_readable($diskPath)) {
        $rawBytes = @file_get_contents($diskPath);
    }
} else {
    $diskPath = __DIR__ . '/assets/lojas/' . $tenant . '/' . $file;
    if (is_file($diskPath) && is_readable($diskPath)) {
        $rawBytes = @file_get_contents($diskPath);
    } else {
        $sqlMedia = function_exists('pz_db_media_read') ? pz_db_media_read($tenant, $file) : null;
        if (is_array($sqlMedia) && !empty($sqlMedia['bytes'])) {
            $rawBytes = $sqlMedia['bytes'];
            $rawMime = $sqlMedia['mime'] ?: $mime;
        }
    }
}

if (!is_string($rawBytes) || $rawBytes === '') {
    http_response_code(404);
    exit;
}

if (($targetFmt !== '' || $maxW > 0) && $ext !== 'svg' && $ext !== 'gif') {
    $opt = pz_media_transformar($rawBytes, $ext, $targetFmt ?: $ext, $maxW, $quality);
    header('Content-Type: ' . $opt['mime']);
    header('X-Media-Optimized: ' . $opt['ext']);
    echo $opt['bytes'];
    exit;
}

header('Content-Type: ' . $rawMime);
echo $rawBytes;
