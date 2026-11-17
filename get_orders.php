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

$orders = Database::read('pedidos.json');
echo json_encode($orders);
