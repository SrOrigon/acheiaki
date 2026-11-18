<?php
/**
 * Barramento de Tempo Real (SSE + Pusher / Soketi WebSockets).
 * Permite atualização instantânea de status de pedidos, cozinha e GPS sem esperar polling.
 */
require_once __DIR__ . '/cache_lib.php';
require_once __DIR__ . '/db_pdo.php';

function pz_realtime_ws_config()
{
    $appId = trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('PUSHER_APP_ID') : getenv('PUSHER_APP_ID')));
    $key = trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('PUSHER_KEY') : getenv('PUSHER_KEY')));
    $secret = trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('PUSHER_SECRET') : getenv('PUSHER_SECRET')));
    $cluster = trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('PUSHER_CLUSTER') : getenv('PUSHER_CLUSTER'))) ?: 'mt1';
    $soketiHost = trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('SOKETI_HOST') : getenv('SOKETI_HOST')));
    $soketiPort = (int) ((function_exists('pz_db_env_val') ? pz_db_env_val('SOKETI_PORT') : getenv('SOKETI_PORT')) ?: 6001);
    $soketiTls = strtolower((string) (function_exists('pz_db_env_val') ? pz_db_env_val('SOKETI_TLS') : getenv('SOKETI_TLS'))) === 'true';

    return [
        'ws_habilitado' => ($appId !== '' && $key !== '' && $secret !== ''),
        'app_id' => $appId,
        'key' => $key,
        'secret' => $secret,
        'cluster' => $cluster,
        'soketi_host' => $soketiHost,
        'soketi_port' => $soketiPort,
        'soketi_tls' => $soketiTls,
    ];
}

function pz_realtime_ws_publico()
{
    $cfg = pz_realtime_ws_config();
    return [
        'ws_habilitado' => $cfg['ws_habilitado'],
        'key' => $cfg['key'],
        'cluster' => $cfg['cluster'],
        'ws_host' => $cfg['soketi_host'],
        'ws_port' => $cfg['soketi_port'],
        'ws_tls' => $cfg['soketi_tls'],
        'sse_endpoint' => 'realtime_events.php',
    ];
}

function pz_realtime_feed_path($tenant)
{
    $dir = __DIR__ . '/data/cache/rt';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string) $tenant) ?: 'default';
    return $dir . '/' . $slug . '.json';
}

function pz_realtime_publicar($tenant, $event, array $payload = [])
{
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string) $tenant) ?: 'default';
    $evName = preg_replace('/[^a-z0-9_.-]/i', '', (string) $event) ?: 'update';
    $seq = (int) round(microtime(true) * 1000);

    $item = [
        'seq' => $seq,
        'tenant' => $slug,
        'event' => $evName,
        'payload' => $payload,
        'ts' => date('c'),
    ];

    // Armazena no Redis (se ativo) ou no cache/arquivo compartilhado entre workers
    $targets = [$slug];
    if ($slug !== 'global') {
        $targets[] = 'global';
    }
    foreach ($targets as $alvo) {
        $key = 'rt_feed_' . $alvo;
        $atual = pz_cache_get($key, []);
        if (!is_array($atual)) {
            $atual = [];
        }
        $atual[] = $item;
        if (count($atual) > 80) {
            $atual = array_slice($atual, -80);
        }
        pz_cache_set($key, $atual, 180);

        $path = pz_realtime_feed_path($alvo);
        @file_put_contents($path, json_encode($atual, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    // Dispara WebSocket real via Pusher / Soketi se configurado
    pz_realtime_trigger_pusher($slug, $evName, $item);

    return $item;
}

function pz_realtime_buscar($tenant, $sinceSeq = 0)
{
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string) $tenant) ?: 'default';
    $sinceSeq = (int) $sinceSeq;

    $lista = pz_cache_get('rt_feed_' . $slug, null);
    if (!is_array($lista)) {
        $path = pz_realtime_feed_path($slug);
        if (is_file($path)) {
            $lista = json_decode(@file_get_contents($path) ?: '[]', true);
        }
    }
    if (!is_array($lista)) {
        $lista = [];
    }

    $novos = [];
    $maxSeq = $sinceSeq;
    foreach ($lista as $ev) {
        if (!is_array($ev)) {
            continue;
        }
        $s = (int) ($ev['seq'] ?? 0);
        if ($s > $sinceSeq) {
            $novos[] = $ev;
        }
        if ($s > $maxSeq) {
            $maxSeq = $s;
        }
    }

    return [
        'seq' => $maxSeq ?: (int) round(microtime(true) * 1000),
        'events' => $novos,
    ];
}

function pz_realtime_trigger_pusher($slug, $event, array $data)
{
    $cfg = pz_realtime_ws_config();
    if (!$cfg['ws_habilitado'] || !function_exists('curl_init')) {
        return false;
    }

    $channels = ['loja-' . $slug];
    if ($slug !== 'global') {
        $channels[] = 'acheiaki-global';
    }

    $bodyArr = [
        'name' => $event,
        'channels' => $channels,
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
    ];
    $body = json_encode($bodyArr, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return false;
    }

    $path = '/apps/' . $cfg['app_id'] . '/events';
    $queryParams = [
        'auth_key' => $cfg['key'],
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'body_md5' => md5($body),
    ];
    ksort($queryParams);
    $queryStr = http_build_query($queryParams);
    $toSign = "POST\n{$path}\n{$queryStr}";
    $sig = hash_hmac('sha256', $toSign, $cfg['secret']);

    if ($cfg['soketi_host'] !== '') {
        $scheme = $cfg['soketi_tls'] ? 'https' : 'http';
        $portPart = $cfg['soketi_port'] ? (':' . $cfg['soketi_port']) : '';
        $url = "{$scheme}://{$cfg['soketi_host']}{$portPart}{$path}?{$queryStr}&auth_signature={$sig}";
    } else {
        $url = "https://api-{$cfg['cluster']}.pusher.com{$path}?{$queryStr}&auth_signature={$sig}";
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 1,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    @curl_exec($ch);
    curl_close($ch);
    return true;
}
