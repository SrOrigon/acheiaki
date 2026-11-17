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

$ativos = array_filter(Database::read('pedidos.json'), function ($p) {
    return is_array($p) && in_array($p['status'] ?? 'novo', ['novo', 'preparo', 'em-rota'], true);
});

usort($ativos, function ($a, $b) {
    return strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''));
});

$resultado = [];
foreach ($ativos as $p) {
    $resultado[] = [
        'id' => $p['id'],
        'cliente' => $p['cliente'] ?? 'Sem Nome',
        'status' => $p['status'] ?? 'novo',
    ];
}

echo json_encode(['success' => true, 'orders' => $resultado], JSON_UNESCAPED_UNICODE);
