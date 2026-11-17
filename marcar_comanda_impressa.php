<?php
require_once 'tenant_init.php';
require_once 'pedido_acesso.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bruto = file_get_contents('php://input');
$req = json_decode($bruto, true);
pedidozap_bootstrap_tenant(is_array($req) ? $req : null);

$id = (string) ($req['id'] ?? '');
$comandaId = (string) ($req['comanda_id'] ?? '');
$chave = (string) ($req['k'] ?? $_GET['k'] ?? '');

if ($id === '' || $comandaId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Pedido ou comanda inválidos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!pz_cozinha_ok($chave) && !pz_admin_ok()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

Database::garantirComandaCozinha($id);
$pedido = Database::findOrder($id);

if (!$pedido) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Pedido não encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$atual = (string) ($pedido['comanda_id'] ?? '');
if ($comandaId === '' || $atual === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Comanda ainda não foi gerada para este pedido.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($atual !== $comandaId) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Comanda desatualizada — recarregue a tela.', 'comanda_id' => $atual], JSON_UNESCAPED_UNICODE);
    exit;
}

if (Database::patchOrder($id, ['comanda_impressa_id' => $comandaId])) {
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(500);
echo json_encode(['success' => false, 'message' => 'Não foi possível marcar a impressão.'], JSON_UNESCAPED_UNICODE);
