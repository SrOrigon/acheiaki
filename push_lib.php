<?php
/**
 * Gerenciador de Push Notifications (Web Push VAPID / FCM Nativo Android & iOS + Fallback em Tempo Real).
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/realtime_lib.php';
require_once __DIR__ . '/queue_lib.php';

function pz_push_config()
{
    return [
        'vapid_public' => trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_VAPID_PUBLIC_KEY') : getenv('ACHEIAKI_VAPID_PUBLIC_KEY'))),
        'vapid_private' => trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_VAPID_PRIVATE_KEY') : getenv('ACHEIAKI_VAPID_PRIVATE_KEY'))),
        'fcm_server_key' => trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_FCM_SERVER_KEY') : getenv('ACHEIAKI_FCM_SERVER_KEY'))),
    ];
}

function pz_push_registrar_dispositivo($slug, array $dados)
{
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string) $slug) ?: 'default';
    $platform = strtolower(trim((string) ($dados['platform'] ?? 'web')));
    if (!in_array($platform, ['web', 'android', 'ios'], true)) {
        $platform = 'web';
    }
    $role = strtolower(trim((string) ($dados['role'] ?? 'cliente')));
    if (!in_array($role, ['cliente', 'painel', 'cozinha', 'motoboy'], true)) {
        $role = 'cliente';
    }
    $endpoint = trim((string) ($dados['endpoint'] ?? ''));
    $fcmToken = trim((string) ($dados['fcm_token'] ?? ''));
    $deviceId = trim((string) ($dados['device_id'] ?? ''));
    $telefone = preg_replace('/\D/', '', (string) ($dados['telefone'] ?? ''));
    $pedidoId = trim((string) ($dados['pedido_id'] ?? ''));

    if ($endpoint === '' && $fcmToken === '' && $deviceId === '') {
        return ['success' => false, 'message' => 'Informe endpoint Push, token FCM ou ID do dispositivo.'];
    }

    $idChave = sha1($slug . '|' . $platform . '|' . ($fcmToken ?: ($endpoint ?: $deviceId)));
    $reg = [
        'id' => $idChave,
        'tenant' => $slug,
        'platform' => $platform,
        'role' => $role,
        'endpoint' => $endpoint,
        'keys' => is_array($dados['keys'] ?? null) ? $dados['keys'] : [],
        'fcm_token' => $fcmToken,
        'device_id' => $deviceId,
        'telefone' => $telefone,
        'pedido_id' => $pedidoId,
        'atualizado_em' => date('c'),
    ];

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $ok = Database::mutate('push_subscribers.json', function (&$lista) use ($reg) {
        if (!is_array($lista)) {
            $lista = [];
        }
        $atualizado = false;
        foreach ($lista as $i => $item) {
            if (is_array($item) && ($item['id'] ?? '') === $reg['id']) {
                $lista[$i] = $reg;
                $atualizado = true;
                break;
            }
        }
        if (!$atualizado) {
            array_unshift($lista, $reg);
        }
        if (count($lista) > 500) {
            $lista = array_slice($lista, 0, 500);
        }
        return true;
    });
    Database::setTenant($anterior);

    return ['success' => (bool) $ok, 'id' => $idChave, 'platform' => $platform];
}

function pz_push_listar_inscritos($slug, $role = '', $telefone = '', $pedidoId = '')
{
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $lista = Database::read('push_subscribers.json');
    Database::setTenant($anterior);
    if (!is_array($lista)) {
        return [];
    }
    $telClean = preg_replace('/\D/', '', (string) $telefone);
    return array_values(array_filter($lista, function ($sub) use ($role, $telClean, $pedidoId) {
        if (!is_array($sub)) {
            return false;
        }
        if ($role !== '' && ($sub['role'] ?? '') !== $role) {
            return false;
        }
        if ($telClean !== '' && substr((string) ($sub['telefone'] ?? ''), -8) !== substr($telClean, -8)) {
            return false;
        }
        if ($pedidoId !== '' && (string) ($sub['pedido_id'] ?? '') !== '' && (string) ($sub['pedido_id'] ?? '') !== $pedidoId) {
            return false;
        }
        return true;
    }));
}

function pz_push_enfileirar_campanha($slug, $titulo, $mensagem, $url = '', $role = 'cliente')
{
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string) $slug) ?: 'default';
    $payload = [
        'titulo' => trim(strip_tags((string) $titulo)) ?: 'Novidade na loja!',
        'mensagem' => trim(strip_tags((string) $mensagem)),
        'url' => trim((string) $url) ?: ('index.html?tenant=' . rawurlencode($slug)),
        'role' => $role,
        'criado_em' => date('c'),
    ];
    // Notifica abas/apps abertos imediatamente em tempo real
    pz_realtime_publicar($slug, 'push_notice', $payload);
    // Despacha envio externo (FCM / WebPush) via fila assíncrona
    $jobId = pz_queue_push('push.campaign', $payload, $slug);
    return ['success' => true, 'job_id' => $jobId, 'payload' => $payload];
}

function pz_push_notificar_status_pedido($slug, array $pedido, $novoStatus)
{
    $rotulos = [
        'novo' => 'Pedido recebido! Estamos confirmando seu pedido.',
        'preparo' => 'Na cozinha! Seu pedido começou a ser preparado.',
        'em-rota' => 'Saiu para entrega! Acompanhe o entregador em tempo real.',
        'concluido' => 'Pedido entregue! Bom apetite e avalie sua experiência.',
    ];
    if (!isset($rotulos[$novoStatus])) {
        return;
    }
    $idPed = (string) ($pedido['id'] ?? '');
    $payload = [
        'titulo' => 'Pedido #' . $idPed . ' atualizado',
        'mensagem' => $rotulos[$novoStatus],
        'url' => 'rastreio.html?id=' . rawurlencode($idPed) . '&tenant=' . rawurlencode($slug),
        'pedido_id' => $idPed,
        'telefone' => preg_replace('/\D/', '', (string) ($pedido['celular'] ?? '')),
        'role' => 'cliente',
    ];
    pz_queue_push('push.order_status', $payload, $slug);
}

function pz_push_enviar_fcm($token, $titulo, $mensagem, $url)
{
    $cfg = pz_push_config();
    if ($cfg['fcm_server_key'] === '' || $token === '' || !function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: key=' . $cfg['fcm_server_key'],
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'to' => $token,
            'priority' => 'high',
            'notification' => [
                'title' => $titulo,
                'body' => $mensagem,
                'sound' => 'default',
            ],
            'data' => ['url' => $url],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function pz_push_processar_job($slug, $type, array $payload)
{
    $subs = pz_push_listar_inscritos(
        $slug,
        (string) ($payload['role'] ?? ''),
        (string) ($payload['telefone'] ?? ''),
        (string) ($payload['pedido_id'] ?? '')
    );

    $enviadosFcm = 0;
    foreach ($subs as $sub) {
        if (!empty($sub['fcm_token'])) {
            if (pz_push_enviar_fcm($sub['fcm_token'], $payload['titulo'] ?? '', $payload['mensagem'] ?? '', $payload['url'] ?? '')) {
                $enviadosFcm++;
            }
        }
    }

    // Registra histórico de campanhas enviadas na loja
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    Database::mutate('push_log.json', function (&$log) use ($type, $payload, $subs, $enviadosFcm) {
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, [
            'id' => 'pl_' . date('YmdHis'),
            'tipo' => $type,
            'titulo' => $payload['titulo'] ?? '',
            'mensagem' => $payload['mensagem'] ?? '',
            'inscritos_alvo' => count($subs),
            'fcm_enviados' => $enviadosFcm,
            'em' => date('c'),
        ]);
        if (count($log) > 100) {
            $log = array_slice($log, 0, 100);
        }
        return true;
    });
    Database::setTenant($anterior);

    return ['success' => true, 'inscritos' => count($subs), 'fcm' => $enviadosFcm];
}
