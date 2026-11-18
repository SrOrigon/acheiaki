<?php
/**
 * Endpoint SSE (Server-Sent Events) e Delta JSON em tempo real — Isolado por Tenant e Permissões.
 * Visitantes anônimos recebem apenas eventos públicos de cardápio/avaliação ou do seu pedido (id+c).
 * Operadores (painel, cozinha, motoboy) autenticados recebem os eventos operacionais da loja.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/pedido_acesso.php';
require_once __DIR__ . '/realtime_lib.php';

pedidozap_bootstrap_tenant();
$tenant = Database::getTenant();
$mode = strtolower((string) ($_GET['mode'] ?? 'sse'));
$since = (int) ($_GET['since'] ?? ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0));

$chaveOp = (string) ($_GET['k'] ?? '');
$isStaff = pz_admin_ok() || pz_cozinha_ok($chaveOp) || pz_entregador_ok($chaveOp);

$orderIdFilter = trim((string) ($_GET['order_id'] ?? ''));
$orderCode = trim((string) ($_GET['c'] ?? ''));
$orderOk = false;
if (!$isStaff && $orderIdFilter !== '' && $orderCode !== '') {
    $pedCheck = Database::findOrder($orderIdFilter);
    if (is_array($pedCheck) && !empty($pedCheck['codigo']) && hash_equals((string) $pedCheck['codigo'], $orderCode)) {
        $orderOk = true;
    }
}

$filtrarEventos = static function (array $events) use ($tenant, $isStaff, $orderOk, $orderIdFilter): array {
    $out = [];
    foreach ($events as $ev) {
        if (!is_array($ev) || ($ev['tenant'] ?? 'default') !== $tenant) {
            continue;
        }
        $nome = (string) ($ev['event'] ?? '');
        $payload = is_array($ev['payload'] ?? null) ? $ev['payload'] : [];

        if (in_array($nome, ['cardapio_atualizado', 'avaliacao_publicada'], true)) {
            $out[] = $ev;
            continue;
        }
        if ($isStaff) {
            $out[] = $ev;
            continue;
        }
        if ($orderOk && in_array($nome, ['pedido_status', 'pedido_gps'], true) && (string) ($payload['id'] ?? '') === $orderIdFilter) {
            $out[] = $ev;
            continue;
        }
        if ($nome === 'push_notice') {
            $pid = (string) ($payload['pedido_id'] ?? '');
            $tel = (string) ($payload['telefone'] ?? '');
            if (($pid === '' && $tel === '') || ($orderOk && $pid === $orderIdFilter)) {
                $out[] = $ev;
            }
        }
    }
    return $out;
};

if ($mode === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    $res = pz_realtime_buscar($tenant, $since);
    $visiveis = $filtrarEventos($res['events']);
    echo json_encode([
        'success' => true,
        'tenant' => $tenant,
        'scope' => $isStaff ? 'staff' : ($orderOk ? 'order' : 'public'),
        'seq' => $res['seq'],
        'events' => $visiveis,
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
$maxJanelaSeg = 9.0;

while (!connection_aborted() && (microtime(true) - $inicio) < $maxJanelaSeg) {
    pz_cache_l1_clear();
    $res = pz_realtime_buscar($tenant, $since);
    if (!empty($res['events'])) {
        $visiveis = $filtrarEventos($res['events']);
        foreach ($res['events'] as $evRaw) {
            $s = (int) ($evRaw['seq'] ?? 0);
            if ($s > $since) {
                $since = $s;
            }
        }
        foreach ($visiveis as $ev) {
            $seq = (int) ($ev['seq'] ?? 0);
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
