<?php
/**
 * API Pública da Vitrine (Multi-Tenant) — Validação de Cupom,
 * Rastreamento de Carrinho Abandonado e Consulta Básica de Fidelidade.
 */
require_once 'tenant_init.php';
require_once 'saas_lib.php';
require_once 'gestao_lib.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$req = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$req = is_array($req) ? $req : [];

pedidozap_bootstrap_tenant($req);

if (!pedidozap_limite_requisicao('recuperacao_api', 20, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Muitas requisições em sequência. Aguarde um instante.'], JSON_UNESCAPED_UNICODE);
    exit;
}

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
    $tok = trim((string) ($req['token'] ?? $_GET['token'] ?? ''));
    $telToken = '';
    if ($tok !== '') {
        $rawTok = base64_decode($tok, true);
        if (is_string($rawTok)) {
            $partes = explode('|', $rawTok);
            if (count($partes) === 3 && (int) $partes[1] >= time()) {
                $chaveHmac = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_APP_SECRET') : '';
                if (strlen($chaveHmac) < 32) {
                    $antT = Database::getTenant();
                    Database::setTenant('default');
                    $secDoc = Database::readStrict('comprador_secret.json');
                    Database::setTenant($antT);
                    $chaveHmac = is_array($secDoc) ? (string) ($secDoc['secret'] ?? '') : '';
                }
                if ($chaveHmac !== '' && hash_equals(hash_hmac('sha256', $partes[0] . '|' . $partes[1], $chaveHmac), $partes[2])) {
                    $telToken = preg_replace('/\D/', '', (string) $partes[0]);
                }
            }
        }
    }
    $uLoja = (string) ($_GET['user'] ?? $req['auth']['user'] ?? '');
    $pLoja = (string) ($_GET['pass'] ?? $req['auth']['pass'] ?? '');
    $authLojaOk = ($uLoja !== '' || $pLoja !== '') && (pedidozap_autorizado($uLoja, $pLoja) !== false);

    if (!$authLojaOk && ($telToken === '' || $telToken !== $tel)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'encontrado' => false,
            'requer_autenticacao' => true,
            'message' => 'Entre na sua conta para consultar seus pontos de fidelidade.',
        ], JSON_UNESCAPED_UNICODE);
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
