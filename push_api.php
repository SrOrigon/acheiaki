<?php
/**
 * API Pública de Inscrição em Push Notifications (Web Push & Nativo Capacitor FCM/APNs).
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/push_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
    $res = pz_push_registrar_dispositivo($tenant, $body);
    if (empty($res['success'])) {
        http_response_code(400);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação desconhecida.'], JSON_UNESCAPED_UNICODE);
