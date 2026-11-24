<?php
/**
 * API pública — cardápio e configurações por tenant (sem credenciais).
 */
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/tenant_init.php';
    require_once __DIR__ . '/store_schema.php';
    require_once __DIR__ . '/saas_lib.php';
    require_once __DIR__ . '/cache_lib.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno ao iniciar API.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
pz_cors_same_origin('GET, OPTIONS', 'Content-Type');
header('Cache-Control: public, max-age=10, stale-while-revalidate=30');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido. Use GET.']);
    exit;
}

pedidozap_bootstrap_tenant();
$tenant = Database::getTenant();

if ($tenant === 'loja-demonstracao-acheiaki') {
    require_once __DIR__ . '/demo_seed_lib.php';
    pedidozap_garantir_loja_demo();
}

if ($tenant !== 'default' && !Database::tenantDataExists($tenant)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Estabelecimento não encontrado.',
        'tenant' => $tenant,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$internoReq = !empty($_GET['interno']);
$cacheKeyPub = 'pub_store_json_' . $tenant . ($internoReq ? '_int' : '');
$json = pz_cache_get($cacheKeyPub, null);
if (!is_string($json) || $json === '') {
    $config = Database::readStrict('config.json');
    $cardapio = Database::readStrict('cardapio.json');

    if ($tenant === 'default') {
        if (empty($config)) {
            $config = Database::read('config.json');
        }
        if (empty($cardapio)) {
            $cardapio = Database::read('cardapio.json');
        }
    }

    $config = pedidozap_normalizar_config(is_array($config) ? $config : [], $tenant);
    $cardapio = is_array($cardapio) ? $cardapio : [];
    $cardapio = pedidozap_filtrar_cardapio_publico($cardapio, $config);

    $version = isset($config['VERSION']) ? (int) $config['VERSION'] : 0;
    $assinatura = pedidozap_assinatura_publica($tenant);
    $bloqueada = ($assinatura['status'] ?? '') === 'bloqueado';

    if ($bloqueada && !$internoReq) {
        // Shadowban público: oculta cardápio e dados públicos aos compradores sem apagar nada do banco/cadastro.
        $cardapio = [];
        $config = [
            'TENANT_SLUG' => $tenant,
            'NOME_LOJA' => $config['NOME_LOJA'] ?? $tenant,
            'VERSION' => $version,
        ];
    }

    $payload = [
        'success' => true,
        'tenant' => $tenant,
        'version' => $version,
        'config' => $config,
        'cardapio' => $cardapio,
        'assinatura' => $assinatura,
        'loja_bloqueada' => $bloqueada,
        'shadowban' => $bloqueada,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao serializar cardápio.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    pz_cache_set($cacheKeyPub, $json, 30);
}

$etagPub = '"' . sha1($json) . '"';
header('ETag: ' . $etagPub);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etagPub) {
    http_response_code(304);
    exit;
}

echo $json;
