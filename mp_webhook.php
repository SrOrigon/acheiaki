<?php

/**
 * Notificações do Mercado Pago (assinaturas). O corpo recebido serve só como aviso:
 * o status real é sempre consultado de novo na API com o token do servidor.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/queue_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (!pedidozap_limite_requisicao('mp_webhook', 60, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Limite de requisições excedido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$body = json_decode($rawBody, true);
if (!is_array($body)) {
    $body = [];
}

$tipo = (string) ($body['type'] ?? $body['topic'] ?? $_GET['type'] ?? $_GET['topic'] ?? '');
$id = (string) ($body['data']['id'] ?? $_GET['data_id'] ?? $_GET['id'] ?? '');
$id = preg_replace('/[^A-Za-z0-9_-]/', '', $id);

$webhookSecret = function_exists('pz_db_env_val') ? trim((string) pz_db_env_val('ACHEIAKI_MP_WEBHOOK_SECRET')) : trim((string) (getenv('ACHEIAKI_MP_WEBHOOK_SECRET') ?: ''));
if ($webhookSecret !== '') {
    $sigHeader = trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
    $reqIdHeader = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    $sigOk = false;
    if ($sigHeader !== '') {
        $ts = '';
        $v1 = '';
        foreach (explode(',', $sigHeader) as $parte) {
            $kv = explode('=', trim($parte), 2);
            if (count($kv) === 2) {
                if (strtolower($kv[0]) === 'ts') {
                    $ts = trim($kv[1]);
                } elseif (strtolower($kv[0]) === 'v1') {
                    $v1 = strtolower(trim($kv[1]));
                }
            }
        }
        if ($ts !== '' && $v1 !== '') {
            $manifest = "id:{$id};request-id:{$reqIdHeader};ts:{$ts};";
            $calcMp = hash_hmac('sha256', $manifest, $webhookSecret);
            $sigOk = hash_equals($calcMp, $v1);
        } elseif (preg_match('/^[a-f0-9]{64}$/i', $sigHeader)) {
            $calcRaw = hash_hmac('sha256', $rawBody, $webhookSecret);
            $sigOk = hash_equals($calcRaw, strtolower($sigHeader));
        }
    }
    if (!$sigOk) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Assinatura de webhook inválida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

Database::setTenant('default');
$resultado = ['success' => true, 'ignorado' => true];

if ($id !== '') {
    if ($tipo === 'subscription_preapproval' || $tipo === 'preapproval') {
        $jobId = pz_queue_push('mp.sync_subscription', ['kind' => 'preapproval', 'id' => $id], 'default');
        $resultado = ['success' => true, 'queued' => true, 'job_id' => $jobId];
    } elseif ($tipo === 'subscription_authorized_payment' || $tipo === 'authorized_payment') {
        $jobId = pz_queue_push('mp.sync_subscription', ['kind' => 'authorized_payment', 'id' => $id], 'default');
        $resultado = ['success' => true, 'queued' => true, 'job_id' => $jobId];
    } elseif ($tipo === 'payment' || strpos($tipo, 'payment') !== false) {
        $jobId = pz_queue_push('mp.sync_payment', ['id' => $id], 'default');
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
