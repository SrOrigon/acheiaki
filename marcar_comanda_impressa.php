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

$estadoPrint = '';
$atualComanda = '';
$okPrint = Database::mutate('pedidos.json', function (&$orders) use ($id, $comandaId, &$estadoPrint, &$atualComanda) {
    foreach ($orders as &$order) {
        if ((string) ($order['id'] ?? '') !== $id) {
            continue;
        }
        $atualComanda = (string) ($order['comanda_id'] ?? '');
        if ($atualComanda === '') {
            $estadoPrint = 'sem_comanda';
            return false;
        }
        if ($atualComanda !== $comandaId) {
            $estadoPrint = 'conflito';
            return false;
        }
        if ((string) ($order['comanda_impressa_id'] ?? '') === $comandaId) {
            $estadoPrint = 'ja_impressa';
            return false;
        }
        $order['comanda_impressa_id'] = $comandaId;
        $order['comanda_impressa_em'] = date('c');
        $estadoPrint = 'impressa';
        return true;
    }
    unset($order);
    $estadoPrint = 'nao_encontrado';
    return false;
});

if ($estadoPrint === 'ja_impressa') {
    echo json_encode(['success' => true, 'already_printed' => true, 'comanda_id' => $comandaId], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($estadoPrint === 'impressa' && $okPrint) {
    echo json_encode(['success' => true, 'already_printed' => false, 'comanda_id' => $comandaId], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($estadoPrint === 'nao_encontrado') {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Pedido não encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($estadoPrint === 'sem_comanda') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Comanda ainda não foi gerada para este pedido.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($estadoPrint === 'conflito') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Comanda desatualizada — recarregue a tela.', 'comanda_id' => $atualComanda], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(500);
echo json_encode(['success' => false, 'message' => 'Não foi possível marcar a impressão.'], JSON_UNESCAPED_UNICODE);
