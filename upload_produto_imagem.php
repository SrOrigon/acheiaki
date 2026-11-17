<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';

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

if (strlen($bytes) > 6 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Imagem muito grande. Tente uma foto menor.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenant = preg_replace('/[^a-z0-9_-]/i', '', (string) Database::getTenant()) ?: 'default';
$dir = __DIR__ . '/assets/lojas/' . $tenant;
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

$nome = 'prod_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$caminho = $dir . '/' . $nome;

$salvouDisco = @file_put_contents($caminho, $bytes) !== false;
$mimeMap = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$mime = $mimeMap[$ext] ?? 'image/jpeg';
$salvouSql = function_exists('pz_db_media_save') ? pz_db_media_save($tenant, $nome, $mime, $bytes) : null;

if (!$salvouDisco && !$salvouSql) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Falha ao gravar a imagem no servidor.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Quando SQL está ativo (ex.: Railway), serve via media.php com fallback garantido pós-redeploy
$path = ($salvouSql === true)
    ? ('media.php?t=' . rawurlencode($tenant) . '&f=' . rawurlencode($nome))
    : ('assets/lojas/' . $tenant . '/' . $nome);
echo json_encode([
    'success' => true,
    'url' => $path,
    'message' => 'Imagem salva.',
], JSON_UNESCAPED_UNICODE);
