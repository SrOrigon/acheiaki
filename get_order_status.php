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

$orderUpdatedAt = (string) ($found['updated_at'] ?? $found['timestamp'] ?? '');
$etagPed = '"' . sha1($id . '|' . $orderUpdatedAt . '|' . ($found['status'] ?? '') . '|' . json_encode($found['location'] ?? null)) . '"';
header('ETag: ' . $etagPed);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etagPed) {
    http_response_code(304);
    exit;
}
$since = trim((string) ($_GET['since'] ?? ''));
if ($since !== '' && $orderUpdatedAt !== '' && $since === $orderUpdatedAt) {
    echo json_encode([
        'success' => true,
        'changed' => false,
        'updated_at' => $orderUpdatedAt,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'changed' => true,
    'updated_at' => $orderUpdatedAt,
    'order' => [
        'id' => $found['id'],
        'codigo' => $found['codigo'] ?? '',
        'status' => $found['status'] ?? 'novo',
        'etapa_detalhada' => $found['etapa_detalhada'] ?? '',
        'cliente' => $found['cliente'] ?? 'N/A',
        'celular' => $found['celular'] ?? '',
        'endereco' => $found['endereco'] ?? null,
        'subtotal' => $found['subtotal'] ?? null,
        'frete' => $found['frete'] ?? 0,
        'frete_detalhe' => $found['frete_detalhe'] ?? '',
        'total' => $found['total'] ?? 0,
        'pagamento' => $found['pagamento'] ?? 'N/A',
        'troco' => $found['troco'] ?? '',
        'observacao' => $found['observacao'] ?? '',
        'itens' => $found['itens'] ?? [],
        'location' => $found['location'] ?? null,
        'rastreio_externo' => $found['rastreio_externo'] ?? '',
        'created_at' => $found['created_at'] ?? $found['timestamp'] ?? '',
        'aceito_em' => $found['aceito_em'] ?? $found['cozinha_desde'] ?? '',
        'pronto_em' => $found['pronto_em'] ?? '',
        'saiu_entrega_em' => $found['saiu_entrega_em'] ?? $found['saiu_em'] ?? '',
        'entregue_em' => $found['entregue_em'] ?? '',
    ],
], JSON_UNESCAPED_UNICODE);
