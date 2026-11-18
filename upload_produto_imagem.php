<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/media_cdn_lib.php';

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

pedidozap_bootstrap_tenant($data);

$user = $data['auth']['user'] ?? '';
$pass = $data['auth']['pass'] ?? '';
if (!pedidozap_autorizado($user, $pass)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = trim((string) ($data['imagem'] ?? ''));
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nenhuma imagem enviada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bytes = null;
$ext = 'jpg';
if (preg_match('#^data:image/(png|jpeg|jpg|webp|gif);base64,#i', $raw, $m)) {
    $ext = strtolower($m[1] === 'jpeg' ? 'jpg' : $m[1]);
    $b64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $raw);
    $bytes = base64_decode($b64, true);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Formato de imagem não reconhecido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($bytes === false || strlen($bytes) < 32) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Arquivo de imagem inválido ou muito pequeno.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($bytes) > 4 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Imagem muito grande. Use uma foto menor (máx. 4 MB).'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ext !== 'gif' && function_exists('imagecreatefromstring') && function_exists('imagesx')) {
    $img = @imagecreatefromstring($bytes);
    if ($img !== false) {
        $w = imagesx($img);
        $h = imagesy($img);
        $maxDim = 720;
        $alvo = $img;
        if ($w > $maxDim || $h > $maxDim) {
            $escala = min($maxDim / $w, $maxDim / $h);
            $nw = max(1, (int) round($w * $escala));
            $nh = max(1, (int) round($h * $escala));
            $canvas = imagecreatetruecolor($nw, $nh);
            if ($canvas !== false) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $alvo = $canvas;
            }
        }
        ob_start();
        $okComp = false;
        $novoExt = $ext;
        if (function_exists('imagewebp')) {
            imagealphablending($alvo, true);
            imagesavealpha($alvo, true);
            $okComp = @imagewebp($alvo, null, 78);
            $novoExt = 'webp';
        } elseif (function_exists('imagejpeg')) {
            $okComp = @imagejpeg($alvo, null, 80);
            $novoExt = 'jpg';
        }
        $compBytes = ob_get_clean();
        imagedestroy($alvo);
        if ($okComp && is_string($compBytes) && strlen($compBytes) > 32 && strlen($compBytes) < strlen($bytes)) {
            $bytes = $compBytes;
            $ext = $novoExt;
        }
    }
}

$tenant = preg_replace('/[^a-z0-9_-]/i', '', (string) Database::getTenant()) ?: 'default';
$imagemAnterior = trim((string) ($data['imagem_anterior'] ?? ''));

$reusarNome = null;
if ($imagemAnterior !== '' && function_exists('pz_media_nome_de_ref')) {
    $cand = pz_media_nome_de_ref($imagemAnterior, $tenant);
    if ($cand !== null && strpos($cand, 'prod_') === 0) {
        $extAntigo = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
        if ($extAntigo === 'jpeg') {
            $extAntigo = 'jpg';
        }
        if ($ext === $extAntigo) {
            $reusarNome = $cand;
        } elseif (function_exists('pz_db_media_delete_ref')) {
            pz_db_media_delete_ref($imagemAnterior, $tenant);
        }
    } elseif (function_exists('pz_db_media_delete_ref')) {
        pz_db_media_delete_ref($imagemAnterior, $tenant);
    }
}

$nome = $reusarNome ?: ('prod_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext);

$mimeMap = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$mime = $mimeMap[$ext] ?? 'image/jpeg';
$salvouSql = function_exists('pz_db_media_save') ? pz_db_media_save($tenant, $nome, $mime, $bytes) : null;

$salvouDisco = false;
$espelharDisco = !(function_exists('pz_runtime_railway') && pz_runtime_railway()) || $salvouSql !== true;
if ($espelharDisco) {
    $dir = __DIR__ . '/assets/lojas/' . $tenant;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $caminho = $dir . '/' . $nome;
    $salvouDisco = @file_put_contents($caminho, $bytes) !== false;
}

if ($salvouSql !== true && !$salvouDisco) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Falha ao gravar a imagem no servidor.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$s3Res = pz_media_s3_put('assets/lojas/' . $tenant . '/' . $nome, $bytes, $mime);

$path = !empty($s3Res['success']) && !empty($s3Res['url'])
    ? $s3Res['url']
    : (($salvouSql === true)
        ? ('media.php?t=' . rawurlencode($tenant) . '&f=' . rawurlencode($nome))
        : ('assets/lojas/' . $tenant . '/' . $nome));

echo json_encode([
    'success' => true,
    'url' => $path,
    'cdn' => !empty($s3Res['success']),
    'ext' => $ext,
    'message' => 'Foto do produto atualizada (uma foto por item).',
], JSON_UNESCAPED_UNICODE);