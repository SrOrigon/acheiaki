<?php
require_once 'tenant_init.php';
require_once 'pedido_acesso.php';

header('Content-Type: application/json; charset=utf-8');

pedidozap_bootstrap_tenant();

if (!pz_entregador_ok($_GET['k'] ?? '') && !pz_admin_ok()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Abra esta página pelo link do entregador enviado pela loja.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ordersVer = Database::getOrdersVersion();
$sinceParam = trim((string) ($_GET['since'] ?? ''));
if ($sinceParam !== '' && $sinceParam === $ordersVer) {
    echo json_encode(['success' => true, 'changed' => false, 'updated_at' => $ordersVer], JSON_UNESCAPED_UNICODE);
    exit;
}

$ativos = array_filter(Database::read('pedidos.json'), function ($p) {
    return is_array($p) && in_array($p['status'] ?? 'novo', ['novo', 'preparo', 'em-rota'], true);
});

usort($ativos, function ($a, $b) {
    $prio = static function ($st) {
        if ($st === 'em-rota') {
            return 0;
        }
        if ($st === 'preparo') {
            return 1;
        }
        return 2;
    };
    $pa = $prio($a['status'] ?? 'novo');
    $pb = $prio($b['status'] ?? 'novo');
    if ($pa !== $pb) {
        return $pa <=> $pb;
    }
    return strcmp((string) ($b['pronto_em'] ?? $b['timestamp'] ?? ''), (string) ($a['pronto_em'] ?? $a['timestamp'] ?? ''));
});

$resultado = [];
foreach ($ativos as $p) {
    $resultado[] = [
        'id' => $p['id'],
        'codigo' => $p['codigo'] ?? '',
        'cliente' => $p['cliente'] ?? 'Sem Nome',
        'celular' => $p['celular'] ?? '',
        'status' => $p['status'] ?? 'novo',
        'endereco' => $p['endereco'] ?? null,
        'total' => round((float) ($p['total'] ?? 0), 2),
        'pagamento' => $p['pagamento'] ?? 'N/A',
        'troco' => $p['troco'] ?? '',
        'observacao' => $p['observacao'] ?? '',
        'itens_qtd' => count((array) ($p['itens'] ?? [])),
        'pronto_em' => $p['pronto_em'] ?? '',
        'saiu_entrega_em' => $p['saiu_entrega_em'] ?? $p['saiu_em'] ?? '',
    ];
}

echo json_encode(['success' => true, 'changed' => true, 'updated_at' => $ordersVer, 'orders' => $resultado], JSON_UNESCAPED_UNICODE);
