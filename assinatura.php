<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/saas_lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}
pedidozap_bootstrap_tenant($body);

$user = $body['auth']['user'] ?? '';
$pass = $body['auth']['pass'] ?? '';
if (!pedidozap_autorizado($user, $pass)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Entre novamente no painel para continuar.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = Database::getTenant();
$acao = (string) ($body['acao'] ?? '');

if ($acao === 'criar') {
    $email = trim((string) ($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um e-mail válido (o mesmo da sua conta Mercado Pago, de preferência).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $resultado = pedidozap_mp_criar_assinatura($slug, $email);
    if (!$resultado['success']) {
        http_response_code(502);
    }
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'sincronizar') {
    $registro = pedidozap_ler_registro();
    $id = (string) ($registro[$slug]['assinatura_id'] ?? '');
    $preapprovalRetorno = (string) ($body['preapproval_id'] ?? '');
    $resultado = ['success' => false, 'message' => 'Nenhuma assinatura encontrada para esta loja.'];
    if ($preapprovalRetorno !== '') {
        $resultado = pedidozap_mp_sincronizar_assinatura($preapprovalRetorno, $slug);
    }
    if (!$resultado['success'] && $id !== '') {
        $resultado = pedidozap_mp_sincronizar_assinatura($id, $slug);
    }
    $resultado['assinatura'] = pedidozap_assinatura_publica($slug);
    $resultado['email'] = (string) (pedidozap_ler_registro()[$slug]['assinatura_email'] ?? '');
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação desconhecida.'], JSON_UNESCAPED_UNICODE);
