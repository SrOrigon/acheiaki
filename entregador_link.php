<?php
require_once 'tenant_init.php';
require_once 'pedido_acesso.php';

header('Content-Type: application/json; charset=utf-8');

pedidozap_bootstrap_tenant();

if (!pz_admin_ok()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$renovar = ($_GET['acao'] ?? '') === 'renovar';
echo json_encode(['success' => true, 'chave' => pz_chave_entregador($renovar)], JSON_UNESCAPED_UNICODE);
