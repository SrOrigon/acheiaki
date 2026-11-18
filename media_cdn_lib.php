<?php
/**
 * Otimização de Mídia (WebP/AVIF sob demanda + resize) e Offload CDN (Cloudflare R2 / AWS S3).
 */
require_once __DIR__ . '/db_pdo.php';

function pz_media_cdn_config()
{
    $baseUrl = rtrim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_CDN_BASE_URL') : getenv('ACHEIAKI_CDN_BASE_URL')), '/');
    $s3Endpoint = rtrim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_S3_ENDPOINT') : getenv('ACHEIAKI_S3_ENDPOINT')), '/');
    $s3Bucket = trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_S3_BUCKET') : getenv('ACHEIAKI_S3_BUCKET')));
    $s3Region = trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_S3_REGION') : getenv('ACHEIAKI_S3_REGION'))) ?: 'auto';
    $s3Key = trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_S3_ACCESS_KEY') : getenv('ACHEIAKI_S3_ACCESS_KEY')));
    $s3Secret = trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_S3_SECRET_KEY') : getenv('ACHEIAKI_S3_SECRET_KEY')));

    return [
        'cdn_base_url' => $baseUrl,
        's3_endpoint' => $s3Endpoint,
        's3_bucket' => $s3Bucket,
        's3_region' => $s3Region,
        's3_access_key' => $s3Key,
        's3_secret_key' => $s3Secret,
        's3_ativo' => $s3Endpoint !== '' && $s3Bucket !== '' && $s3Key !== '' && $s3Secret !== '',
        'webp_suportado' => function_exists('imagewebp'),
        'avif_suportado' => function_exists('imageavif'),
    ];
}

function pz_media_cdn_publico()
{
    $cfg = pz_media_cdn_config();
    return [
        'cdn_habilitado' => $cfg['cdn_base_url'] !== '',
        's3_r2_ativo' => !empty($cfg['s3_ativo']),
        'webp_suportado' => !empty($cfg['webp_suportado']),
        'avif_suportado' => !empty($cfg['avif_suportado']),
    ];
}

function pz_media_cdn_url($relPath)
{
    $rel = ltrim((string) $relPath, '/');
    if ($rel === '' || preg_match('#^https?://#i', $rel)) {
        return $relPath;
    }
    $cfg = pz_media_cdn_config();
    if ($cfg['cdn_base_url'] !== '') {
        return $cfg['cdn_base_url'] . '/' . $rel;
    }
    return $relPath;
}

function pz_media_cache_dir()
{
    $dir = __DIR__ . '/data/cache/media';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Converte imagem (PNG/JPG/WebP) para WebP/AVIF e redimensiona opcionalmente, com cache em disco.
 */
function pz_media_transformar($rawBytes, $origExt, $targetFmt = 'webp', $maxWidth = 0, $quality = 80)
{
    $origExt = strtolower((string) $origExt);
    $targetFmt = strtolower((string) $targetFmt);
    $maxWidth = max(0, min(1920, (int) $maxWidth));
    $quality = max(45, min(95, (int) $quality));

    $mimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];

    if ($origExt === 'gif' || $origExt === 'svg' || !function_exists('imagecreatefromstring')) {
        return ['bytes' => $rawBytes, 'ext' => $origExt, 'mime' => $mimeMap[$origExt] ?? 'application/octet-stream'];
    }

    if ($targetFmt === 'avif' && !function_exists('imageavif')) {
        $targetFmt = 'webp';
    }
    if ($targetFmt === 'webp' && !function_exists('imagewebp')) {
        return ['bytes' => $rawBytes, 'ext' => $origExt, 'mime' => $mimeMap[$origExt] ?? 'image/jpeg'];
    }

    $cacheKey = sha1($rawBytes . '|' . $targetFmt . '|' . $maxWidth . '|' . $quality) . '.' . $targetFmt;
    $cachePath = pz_media_cache_dir() . '/' . $cacheKey;
    if (is_file($cachePath) && is_readable($cachePath)) {
        $cached = @file_get_contents($cachePath);
        if (is_string($cached) && strlen($cached) > 16) {
            return ['bytes' => $cached, 'ext' => $targetFmt, 'mime' => $mimeMap[$targetFmt] ?? 'image/webp'];
        }
    }

    $img = @imagecreatefromstring($rawBytes);
    if ($img === false) {
        return ['bytes' => $rawBytes, 'ext' => $origExt, 'mime' => $mimeMap[$origExt] ?? 'image/jpeg'];
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $alvo = $img;
    if ($maxWidth > 0 && $w > $maxWidth) {
        $nw = $maxWidth;
        $nh = max(1, (int) round(($h * $nw) / $w));
        $canvas = @imagecreatetruecolor($nw, $nh);
        if ($canvas !== false) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $trans = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $nw, $nh, $trans);
            imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $alvo = $canvas;
        }
    } else {
        imagepalettetotruecolor($alvo);
        imagealphablending($alvo, true);
        imagesavealpha($alvo, true);
    }

    ob_start();
    $ok = false;
    if ($targetFmt === 'avif' && function_exists('imageavif')) {
        $ok = @imageavif($alvo, null, max(40, $quality - 10));
    } elseif ($targetFmt === 'webp' && function_exists('imagewebp')) {
        $ok = @imagewebp($alvo, null, $quality);
    } elseif ($targetFmt === 'jpg' || $targetFmt === 'jpeg') {
        $ok = @imagejpeg($alvo, null, $quality);
    }
    $out = ob_get_clean();
    imagedestroy($alvo);

    if ($ok && is_string($out) && strlen($out) > 16 && (strlen($out) < strlen($rawBytes) || $maxWidth > 0)) {
        @file_put_contents($cachePath, $out, LOCK_EX);
        return ['bytes' => $out, 'ext' => $targetFmt, 'mime' => $mimeMap[$targetFmt] ?? 'image/webp'];
    }

    return ['bytes' => $rawBytes, 'ext' => $origExt, 'mime' => $mimeMap[$origExt] ?? 'image/jpeg'];
}

function pz_media_client_aceita_webp()
{
    $fmt = strtolower((string) ($_GET['fmt'] ?? ''));
    if ($fmt === 'avif' && function_exists('imageavif')) {
        return 'avif';
    }
    if ($fmt === 'webp' && function_exists('imagewebp')) {
        return 'webp';
    }
    if ($fmt === 'orig') {
        return '';
    }
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'image/avif') !== false && function_exists('imageavif')) {
        return 'avif';
    }
    if (strpos($accept, 'image/webp') !== false && function_exists('imagewebp')) {
        return 'webp';
    }
    return '';
}

/**
 * Envia objeto diretamente para Cloudflare R2 / AWS S3 com AWS Signature v4 (zero SDK externo).
 */
function pz_media_s3_put($objectKey, $bytes, $contentType = 'image/webp')
{
    $cfg = pz_media_cdn_config();
    if (!$cfg['s3_ativo'] || !function_exists('curl_init')) {
        return ['success' => false, 'skipped' => true];
    }

    $objectKey = ltrim((string) $objectKey, '/');
    $endpoint = $cfg['s3_endpoint'];
    $parsed = @parse_url($endpoint);
    if (!is_array($parsed) || empty($parsed['host'])) {
        return ['success' => false, 'message' => 'S3 endpoint inválido.'];
    }

    $host = $parsed['host'];
    $scheme = $parsed['scheme'] ?? 'https';
    $uriPath = '/' . $cfg['s3_bucket'] . '/' . str_replace('%2F', '/', rawurlencode($objectKey));
    $url = $scheme . '://' . $host . $uriPath;

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $bytes);

    $canonicalHeaders = "content-type:{$contentType}\nhost:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
    $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "PUT\n{$uriPath}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

    $scope = "{$dateStamp}/{$cfg['s3_region']}/s3/aws4_request";
    $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['s3_secret_key'], true);
    $kRegion = hash_hmac('sha256', $cfg['s3_region'], $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "AWS4-HMAC-SHA256 Credential={$cfg['s3_access_key']}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $contentType,
            'x-amz-date: ' . $amzDate,
            'x-amz-content-sha256: ' . $payloadHash,
            'Authorization: ' . $authorization,
            'Cache-Control: public, max-age=31536000, immutable',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        $publicUrl = $cfg['cdn_base_url'] !== '' ? ($cfg['cdn_base_url'] . '/' . $objectKey) : $url;
        return ['success' => true, 'url' => $publicUrl, 'code' => $code];
    }

    return ['success' => false, 'code' => $code, 'message' => $err ?: substr((string) $resp, 0, 200)];
}
