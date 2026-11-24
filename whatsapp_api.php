<?php
/**
 * API Autenticada de WhatsApp + IA Ao Vivo por Loja (Painel Admin)
 * Ações: status, salvar_config, gerar_qr, listar_conversas, enviar_mensagem,
 * alternar_ia, simular_cliente.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/whatsapp_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$req = json_decode((string) $raw, true);
if (!is_array($req)) {
    $req = [];
}

pedidozap_bootstrap_tenant($req);

$user = trim((string) ($req['auth']['user'] ?? $_GET['user'] ?? ''));
$pass = (string) ($req['auth']['pass'] ?? $_GET['pass'] ?? '');
$sessao = pedidozap_sessao_info($user, $pass);
if (!is_array($sessao) || empty($sessao['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão expirada. Entre novamente no painel.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = Database::getTenant();
$acao = trim((string) ($req['acao'] ?? 'status'));

if ($acao === 'status') {
    $pub = pz_wpp_config_publica($slug);
    $st = !empty($req['verificar_conexao'])
        ? pz_wpp_status_conexao($slug)
        : [
            'ok' => true,
            'estado' => !$pub['gateway_pronto'] ? 'modo_teste' : ($pub['conectado'] ? 'conectado' : 'aguardando_qr'),
            'conectado' => (bool) $pub['conectado'],
            'rotulo' => !$pub['gateway_pronto']
                ? 'Simulador IA Ao Vivo (sem gateway externo ainda)'
                : ($pub['conectado'] ? 'WhatsApp conectado ao vivo' : 'Pronto para parear QR Code / Webhook'),
            'detalhe' => '',
        ];

    echo json_encode([
        'success' => true,
        'config' => $pub,
        'status_conexao' => $st,
        'conversas' => pz_wpp_listar_conversas($slug),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'salvar_config') {
    $patch = is_array($req['config'] ?? null) ? $req['config'] : [];
    $salvo = pz_wpp_salvar_config($slug, $patch);
    if ($salvo === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Não foi possível salvar a configuração de WhatsApp.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = pz_wpp_status_conexao($slug);
    echo json_encode([
        'success' => true,
        'config' => pz_wpp_config_publica($slug),
        'status_conexao' => $st,
        'message' => 'Configuração de WhatsApp e IA salva!',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'gerar_qr') {
    $res = pz_wpp_qr_ou_conectar($slug);
    echo json_encode(array_merge($res, [
        'config' => pz_wpp_config_publica($slug),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'listar_conversas') {
    echo json_encode([
        'success' => true,
        'conversas' => pz_wpp_listar_conversas($slug),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'enviar_mensagem') {
    $tel = trim((string) ($req['telefone'] ?? ''));
    $msg = trim((string) ($req['mensagem'] ?? ''));
    $nome = trim((string) ($req['nome'] ?? ''));
    $pausarIa = !array_key_exists('pausar_ia', $req) || !empty($req['pausar_ia']);

    $res = pz_wpp_enviar_texto($slug, $tel, $msg, [
        'autor' => 'humano',
        'nome' => $nome,
    ]);
    if ($res['success'] && $pausarIa) {
        pz_wpp_alternar_ia_conversa($slug, $tel, false);
    }
    echo json_encode(array_merge($res, [
        'conversas' => pz_wpp_listar_conversas($slug),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'alternar_ia') {
    $tel = trim((string) ($req['telefone'] ?? ''));
    $ativa = !empty($req['ia_ativa']);
    pz_wpp_alternar_ia_conversa($slug, $tel, $ativa);
    echo json_encode([
        'success' => true,
        'conversas' => pz_wpp_listar_conversas($slug),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'simular_cliente') {
    $tel = trim((string) ($req['telefone'] ?? '5521999990001')) ?: '5521999990001';
    $nome = trim((string) ($req['nome'] ?? 'Cliente Teste')) ?: 'Cliente Teste';
    $texto = trim((string) ($req['mensagem'] ?? 'Oi, o que você recomenda de lanche hoje?'));
    if ($texto === '') {
        $texto = 'Oi, o que você recomenda de lanche hoje?';
    }
    $res = pz_wpp_processar_entrada_cliente($slug, $tel, $texto, [
        'nome' => $nome,
        'msg_id' => 'sim_' . substr(md5($texto . microtime(true)), 0, 12),
        'forcar_ia' => true,
    ]);
    echo json_encode(array_merge($res, [
        'conversas' => pz_wpp_listar_conversas($slug),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'enviar_teste') {
    $storeCfg = pedidozap_normalizar_config(Database::readStrict('config.json'), $slug);
    $nomeLoja = trim((string) ($storeCfg['NOME_LOJA'] ?? $storeCfg['EMPRESA']['nome'] ?? 'Minha Loja'));
    $telRaw = trim((string) ($req['telefone'] ?? ($storeCfg['WHATSAPP_NUMBER'] ?? ($storeCfg['REDES']['whatsapp'] ?? ''))));
    $telNorm = pz_wpp_normalizar_telefone($telRaw);
    if (strlen($telNorm) < 12) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um número de WhatsApp com DDD para enviar o teste.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msgTeste = trim((string) ($req['mensagem'] ?? ''));
    if ($msgTeste === '') {
        $msgTeste = "✅ Teste AcheIAki ({$nomeLoja}): seu WhatsApp + IA autônomo está pronto para atender clientes e avisar sobre pedidos sem taxa!";
    }
    $res = pz_wpp_enviar_texto($slug, $telNorm, $msgTeste, [
        'autor' => 'sistema',
        'nome' => 'Teste da Loja',
    ]);
    $waLink = 'https://wa.me/' . rawurlencode($telNorm) . '?text=' . rawurlencode($msgTeste);
    echo json_encode(array_merge($res, [
        'wa_link' => $waLink,
        'telefone' => $telNorm,
        'conversas' => pz_wpp_listar_conversas($slug),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
