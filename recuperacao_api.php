<?php
/**
 * API Pública da Vitrine (Multi-Tenant) — Validação de Cupom,
 * Rastreamento de Carrinho Abandonado e Consulta Básica de Fidelidade.
 */
require_once 'tenant_init.php';
require_once 'gestao_lib.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$req = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$req = is_array($req) ? $req : [];

pedidozap_bootstrap_tenant($req);

$action = trim((string) ($_GET['action'] ?? $req['action'] ?? ''));

if ($action === 'validar_cupom') {
    $codigo = (string) ($req['codigo'] ?? $_GET['codigo'] ?? '');
    $subtotal = (float) ($req['subtotal'] ?? $_GET['subtotal'] ?? 0);
    $frete = (float) ($req['frete'] ?? $_GET['frete'] ?? 0);
    $celular = (string) ($req['celular'] ?? $_GET['celular'] ?? '');
    $res = pz_validar_cupom($codigo, $subtotal, $frete, $celular);
    echo json_encode(array_merge(['success' => !empty($res['valido'])], $res), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'heartbeat_carrinho') {
    $ok = pz_registrar_carrinho_recuperacao($req);
    echo json_encode(['success' => (bool) $ok], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'fidelidade_consulta') {
    $tel = preg_replace('/\D/', '', (string) ($req['celular'] ?? $_GET['celular'] ?? ''));
    if (strlen($tel) < 10) {
        echo json_encode(['success' => false, 'encontrado' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }
    foreach (pz_ler_clientes() as $cli) {
        if (($cli['telefone'] ?? '') === $tel) {
            $primeiroNome = explode(' ', trim((string) ($cli['nome'] ?? 'Cliente')))[0];
            echo json_encode([
                'success' => true,
                'encontrado' => true,
                'primeiro_nome' => $primeiroNome,
                'pontos_fidelidade' => (int) ($cli['pontos_fidelidade'] ?? 0),
                'total_pedidos' => (int) ($cli['total_pedidos'] ?? 0),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['success' => true, 'encontrado' => false, 'pontos_fidelidade' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
