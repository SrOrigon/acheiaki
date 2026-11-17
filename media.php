<?php
/**
 * Servimento seguro de imagens de produtos (disco local ou pz_media_store no SQL do Railway).
 */
require_once __DIR__ . '/db_pdo.php';

$tenant = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_GET['t'] ?? 'default')) ?: 'default';
$file = basename((string) ($_GET['f'] ?? ''));

if ($file === '' || !preg_match('/^[a-zA-Z0-9._-]+\.(jpg|jpeg|png|webp|gif)$/i', $file)) {
    http_response_code(404);
    exit;
}

$mimeMap = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = $mimeMap[$ext] ?? 'image/jpeg';

$etag = '"' . sha1($tenant . '|' . $file) . '"';
$cacheHeader = strpos($file, 'prod_') === 0
    ? 'public, max-age=2592000, immutable'
    : 'public, max-age=604800';
header('ETag: ' . $etag);
header('Cache-Control: ' . $cacheHeader);
header('X-Content-Type-Options: nosniff');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

$diskPath = __DIR__ . '/assets/lojas/' . $tenant . '/' . $file;
if (is_file($diskPath) && is_readable($diskPath)) {
    header('Content-Type: ' . $mime);
    readfile($diskPath);
    exit;
}

$sqlMedia = function_exists('pz_db_media_read') ? pz_db_media_read($tenant, $file) : null;
if (is_array($sqlMedia) && !empty($sqlMedia['bytes'])) {
    header('Content-Type: ' . ($sqlMedia['mime'] ?: $mime));
    echo $sqlMedia['bytes'];
    exit;
}

http_response_code(404);
