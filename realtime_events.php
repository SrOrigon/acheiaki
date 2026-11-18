<?php
/**
 * Endpoint SSE (Server-Sent Events) e Delta JSON em tempo real.
 * Reduz polling cego de pedidos, cozinha e rastreio e entrega push local instantâneo.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/realtime_lib.php';

$tenant = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_GET['tenant'] ?? 'default')) ?: 'default';
$mode = strtolower((string) ($_GET['mode'] ?? 'sse'));
$since = (int) ($_GET['since'] ?? ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0));

if ($mode === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    $res = pz_realtime_buscar($tenant, $since);
    echo json_encode([
        'success' => true,
        'tenant' => $tenant,
        'seq' => $res['seq'],
        'events' => $res['events'],
        'ws' => pz_realtime_ws_publico(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    @ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

echo "retry: 1200\n\n";
@flush();

$inicio = microtime(true);
$maxJanelaSeg = 9.0; // Ciclos curtos liberam o worker HTTP com segurança sob alta concorrência

while (!connection_aborted() && (microtime(true) - $inicio) < $maxJanelaSeg) {
    pz_cache_l1_clear();
    $res = pz_realtime_buscar($tenant, $since);
    if (!empty($res['events'])) {
        foreach ($res['events'] as $ev) {
            $seq = (int) ($ev['seq'] ?? 0);
            if ($seq > $since) {
                $since = $seq;
            }
            $json = json_encode($ev, JSON_UNESCAPED_UNICODE);
            echo "id: {$seq}\n";
            echo "event: " . ($ev['event'] ?? 'update') . "\n";
            echo "data: {$json}\n\n";
        }
        @flush();
    } else {
        echo ": keepalive\n\n";
        @flush();
    }
    usleep(450000);
}
