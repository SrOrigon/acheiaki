<?php
require_once 'tenant_init.php';
require_once 'auth_lib.php';

header('Content-Type: application/json');

pedidozap_bootstrap_tenant();

$user = $_GET['user'] ?? '';
$pass = $_GET['pass'] ?? '';

require_once 'saas_lib.php';

if (!pedidozap_autorizado($user, $pass)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}
pedidozap_recusar_loja_bloqueada($user, $pass);

$ordersVer = Database::getOrdersVersion();
$etagOrders = '"' . sha1(Database::getTenant() . '|' . $ordersVer) . '"';
header('ETag: ' . $etagOrders);
header('Cache-Control: no-cache');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etagOrders) {
    http_response_code(304);
    exit;
}

$since = trim((string) ($_GET['since'] ?? ''));
$delta = ($_GET['delta'] ?? '') === '1';
if ($delta && $since !== '' && $since === $ordersVer) {
    echo json_encode(['success' => true, 'changed' => false, 'updated_at' => $ordersVer]);
    exit;
}

$orders = Database::read('pedidos.json');
if ($delta) {
    echo json_encode([
        'success' => true,
        'changed' => true,
        'updated_at' => $ordersVer,
        'orders' => $orders,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode($orders);
