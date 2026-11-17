<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'tenant_init.php';
require_once 'pedido_acesso.php';

pedidozap_bootstrap_tenant();

$id = (string) ($_GET['id'] ?? '');
$codigo = (string) ($_GET['c'] ?? '');
$chave = (string) ($_GET['k'] ?? '');

if ($id === '') {
    echo json_encode(['success' => false, 'message' => 'Pedido não informado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$found = null;
foreach (Database::read('pedidos.json') as $order) {
    if (is_array($order) && (string) ($order['id'] ?? '') === $id) {
        $found = $order;
        break;
    }
}

if (!$found || !pz_pode_ver_pedido($found, $codigo, $chave)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Pedido não encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'order' => [
        'id' => $found['id'],
        'status' => $found['status'] ?? 'novo',
        'cliente' => $found['cliente'] ?? 'N/A',
        'endereco' => $found['endereco'] ?? null,
        'subtotal' => $found['subtotal'] ?? null,
        'frete' => $found['frete'] ?? 0,
        'frete_detalhe' => $found['frete_detalhe'] ?? '',
        'total' => $found['total'] ?? 0,
        'pagamento' => $found['pagamento'] ?? 'N/A',
        'itens' => $found['itens'] ?? [],
        'location' => $found['location'] ?? null,
        'rastreio_externo' => $found['rastreio_externo'] ?? '',
    ],
], JSON_UNESCAPED_UNICODE);
