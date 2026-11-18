<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'tenant_init.php';
require_once 'pedido_acesso.php';
require_once 'realtime_lib.php';

$data = json_decode(file_get_contents('php://input'), true);
pedidozap_bootstrap_tenant(is_array($data) ? $data : null);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (string) ($data['id'] ?? '');
$lat = $data['lat'] ?? null;
$lng = $data['lng'] ?? null;

if ($id === '' || !is_numeric($lat) || !is_numeric($lng)) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros ausentes.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pedido = null;
foreach (Database::read('pedidos.json') as $order) {
    if (is_array($order) && (string) ($order['id'] ?? '') === $id) {
        $pedido = $order;
        break;
    }
}

if (!$pedido || !pz_pode_ver_pedido($pedido, $data['c'] ?? ($_GET['c'] ?? ''), $data['k'] ?? ($_GET['k'] ?? ''))) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Pedido não encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (Database::updateOrderLocation($id, (float) $lat, (float) $lng)) {
    pz_realtime_publicar(Database::getTenant(), 'pedido_gps', [
        'id' => $id,
        'lat' => (float) $lat,
        'lng' => (float) $lng,
    ]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Não foi possível atualizar a localização.'], JSON_UNESCAPED_UNICODE);
}
