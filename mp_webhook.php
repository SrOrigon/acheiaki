<?php
/**
 * Notificações do Mercado Pago (assinaturas). O corpo recebido serve só como aviso:
 * o status real é sempre consultado de novo na API com o token do servidor.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/queue_lib.php';

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}

$tipo = (string) ($body['type'] ?? $body['topic'] ?? $_GET['type'] ?? $_GET['topic'] ?? '');
$id = (string) ($body['data']['id'] ?? $_GET['data_id'] ?? $_GET['id'] ?? '');
$id = preg_replace('/[^A-Za-z0-9_-]/', '', $id);

Database::setTenant('default');
$resultado = ['success' => true, 'ignorado' => true];

if ($id !== '') {
    if ($tipo === 'subscription_preapproval' || $tipo === 'preapproval') {
        $jobId = pz_queue_push('mp.sync_subscription', ['kind' => 'preapproval', 'id' => $id], 'default');
        $resultado = ['success' => true, 'queued' => true, 'job_id' => $jobId];
    } elseif ($tipo === 'subscription_authorized_payment' || $tipo === 'authorized_payment') {
        $jobId = pz_queue_push('mp.sync_subscription', ['kind' => 'authorized_payment', 'id' => $id], 'default');
        $resultado = ['success' => true, 'queued' => true, 'job_id' => $jobId];
    }
}

$logDir = __DIR__ . '/data/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@file_put_contents(
    $logDir . '/mp_webhook.log',
    date('c') . ' ' . $tipo . ' ' . $id . ' ' . json_encode($resultado, JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);

http_response_code(200);
echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
