<?php
/**
 * Pré-compressão de imagens pesadas em assets/images/ para WebP (alvo <= 100 KB).
 * Executado em build-time no Dockerfile ou via CLI para eliminar egress e CPU no horário de pico.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
    echo "GD WebP indisponível.\n";
    exit(0);
}

$dir = dirname(__DIR__) . '/assets/images';
$files = @glob($dir . '/*.{png,PNG,jpg,JPG,jpeg,JPEG}', GLOB_BRACE) ?: [];
$maxBytes = 100 * 1024; // 100 KB

foreach ($files as $src) {
    $base = basename($src);
    if (stripos($base, 'acheiaki-icon') === 0) {
        continue; // mantém ícone PWA nítido
    }
    $raw = @file_get_contents($src);
    if (!is_string($raw) || strlen($raw) < 1024) {
        continue;
    }
    $img = @imagecreatefromstring($raw);
    if ($img === false) {
        continue;
    }

    $wOrig = imagesx($img);
    $hOrig = imagesy($img);
    $destWebp = preg_replace('/\.(png|jpe?g)$/i', '.webp', $src);

    $bestBytes = null;
    foreach ([720, 600, 480] as $maxDim) {
        $alvo = $img;
        $criouCanvas = false;
        if ($wOrig > $maxDim || $hOrig > $maxDim) {
            $escala = min($maxDim / $wOrig, $maxDim / $hOrig);
            $nw = max(1, (int) round($wOrig * $escala));
            $nh = max(1, (int) round($hOrig * $escala));
            $canvas = @imagecreatetruecolor($nw, $nh);
            if ($canvas !== false) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $trans = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, 0, 0, $nw, $nh, $trans);
                imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $wOrig, $hOrig);
                $alvo = $canvas;
                $criouCanvas = true;
            }
        } else {
            imagepalettetotruecolor($alvo);
            imagealphablending($alvo, true);
            imagesavealpha($alvo, true);
        }

        foreach ([78, 68, 58, 48] as $q) {
            ob_start();
            $ok = @imagewebp($alvo, null, $q);
            $out = ob_get_clean();
            if ($ok && is_string($out) && strlen($out) > 32) {
                $bestBytes = $out;
                if (strlen($out) <= $maxBytes) {
                    if ($criouCanvas) {
                        imagedestroy($alvo);
                    }
                    break 2;
                }
            }
        }
        if ($criouCanvas) {
            imagedestroy($alvo);
        }
    }

    imagedestroy($img);
    if (is_string($bestBytes) && strlen($bestBytes) > 32) {
        @file_put_contents($destWebp, $bestBytes, LOCK_EX);
        echo sprintf("%s: %.1f KB -> %.1f KB WebP\n", $base, strlen($raw) / 1024, strlen($bestBytes) / 1024);
    }
}
