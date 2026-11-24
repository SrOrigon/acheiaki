<?php
/**
 * API Pública de Inscrição em Push Notifications (Web Push & Nativo Capacitor FCM/APNs).
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/pedido_acesso.php';
require_once __DIR__ . '/push_lib.php';

header('Content-Type: application/json; charset=utf-8');
pz_cors_same_origin('GET, POST, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$acao = strtolower(trim((string) ($_GET['action'] ?? 'config')));
$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}

pedidozap_bootstrap_tenant($body ?: null);
$tenant = Database::getTenant();

if ($acao === 'config' || $acao === 'vapid_public') {
    $cfg = pz_push_config();
    echo json_encode([
        'success' => true,
        'tenant' => $tenant,
        'vapid_public_key' => $cfg['vapid_public'],
        'fcm_habilitado' => $cfg['fcm_server_key'] !== '',
        'realtime' => pz_realtime_ws_publico(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'subscribe') {
    $roleReq = strtolower(trim((string) ($body['role'] ?? 'cliente')));
    if (in_array($roleReq, ['painel', 'cozinha', 'motoboy'], true)) {
        $u = (string) ($body['auth']['user'] ?? $_GET['user'] ?? '');
        $p = (string) ($body['auth']['pass'] ?? $_GET['pass'] ?? '');
        $k = (string) ($body['k'] ?? $_GET['k'] ?? '');
        $okStaff = ($p !== '' && pedidozap_autorizado($u, $p) !== false) || pz_cozinha_ok($k) || pz_entregador_ok($k);
        if (!$okStaff) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Autorização da loja obrigatória para alertas operacionais.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif (!empty($body['pedido_id'])) {
        $pedSub = Database::findOrder((string) $body['pedido_id']);
        $codSub = (string) ($body['c'] ?? $_GET['c'] ?? '');
        if ($pedSub && !pz_pode_ver_pedido($pedSub, $codSub, '')) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Código de pedido inválido para inscrição Push.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $res = pz_push_registrar_dispositivo($tenant, $body);
    if (empty($res['success'])) {
        http_response_code(400);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação desconhecida.'], JSON_UNESCAPED_UNICODE);
