<?php
/**
 * Checklist automatizado de lançamento (CLI no servidor).
 * Uso: php go_live_smoke.php
 *      php go_live_smoke.php https://seudominio.com.br
 */
declare(strict_types=1);

require_once __DIR__ . '/db_pdo.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/integracoes_lib.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Execute no servidor: php go_live_smoke.php [url_base_opcional]',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseUrl = isset($argv[1]) ? rtrim(trim((string) $argv[1]), '/') : '';

$itens = [];

$registrar = static function (string $id, string $titulo, bool $ok, string $detalhe = '', string $acaoUsuario = '') use (&$itens) {
    $itens[] = [
        'id' => $id,
        'titulo' => $titulo,
        'ok' => $ok,
        'detalhe' => $detalhe,
        'acao_usuario' => $acaoUsuario,
    ];
};

$dataDir = __DIR__ . '/data';
$registrar('data_writable', 'Pasta data gravável', is_dir($dataDir) && is_writable($dataDir), $dataDir, 'No servidor: chmod 775 data e subpastas tenants/logs.');

$extObrigatorias = ['json', 'openssl', 'mbstring', 'pdo'];
foreach ($extObrigatorias as $ext) {
    $registrar('ext_' . $ext, 'Extensão PHP: ' . $ext, extension_loaded($ext), PHP_VERSION);
}
$registrar('ext_gd', 'Extensão PHP: gd (fotos)', extension_loaded('gd'), '', 'Instale php-gd no host (recomendado para upload de produtos).');
$registrar('ext_curl', 'Extensão PHP: curl', function_exists('curl_init'), '', 'Instale php-curl (Mercado Pago / Gemini / CNPJ).');

$master = pedidozap_ler_master();
$registrar(
    'master_auth',
    'Acesso SuperAdmin (master)',
    $master !== null,
    $master ? 'Usuário master definido.' : 'Nenhuma credencial master.',
    'No painel do host, defina ACHEIAKI_MASTER_USER e ACHEIAKI_MASTER_PASS (senha forte) ou cadastre em master_auth.json.'
);

$secret = pedidozap_app_secret();
$registrar(
    'app_secret',
    'Chave APP (tokens de sessão)',
    strlen($secret) >= 32,
    'Comprimento: ' . strlen($secret),
    'Defina ACHEIAKI_APP_SECRET com 32+ caracteres aleatórios no .env de produção.'
);

$iconJpg = is_file(__DIR__ . '/assets/images/acheiaki-icon.jpg');
$iconSvg = is_file(__DIR__ . '/assets/images/placeholder-logo.svg');
$registrar(
    'brand_icon',
    'Ícone da marca',
    $iconJpg || $iconSvg,
    $iconJpg ? 'acheiaki-icon.jpg presente.' : 'Usando fallback SVG até você enviar o JPG oficial.',
    'Envie assets/images/acheiaki-icon.jpg (512×512) e acheiaki-logo.jpg para redes sociais e PWA.'
);

$dbCfg = pz_db_ler_config();
$dbEnabled = !empty($dbCfg['enabled']);
$dbOk = false;
if ($dbEnabled) {
    try {
        $pdo = pz_pdo(false);
        $dbOk = $pdo instanceof PDO;
    } catch (Throwable $e) {
        $dbOk = false;
    }
}
if ($dbEnabled) {
    $registrar(
        'sql',
        'Banco SQL conectado',
        $dbOk,
        $dbOk ? ($dbCfg['driver'] ?? 'mysql') . ' / ' . ($dbCfg['database'] ?? '') : 'Falha na conexão.',
        'No Railway: use PostgreSQL (DATABASE_URL) — já é o padrão do projeto acheiaki.'
    );
} else {
    $registrar(
        'sql',
        'Banco SQL (produção)',
        false,
        'SQL desligado — modo JSON em disco.',
        'Para comercializar com muitas lojas, ative MySQL/PostgreSQL (disco efêmero sem volume perde dados).'
    );
}

$saas = pedidozap_saas_config();
$mpOk = trim((string) ($saas['mp_access_token'] ?? '')) !== '';
$registrar(
    'mercado_pago',
    'Mercado Pago (assinaturas)',
    $mpOk,
    $mpOk ? 'Token configurado no servidor.' : 'Sem token.',
    'Crie app no Mercado Pago, cole ACHEIAKI_MP_ACCESS_TOKEN no .env e configure webhook: {seu_dominio}/mp_webhook.php'
);

$int = pedidozap_integracoes_publicas();
$registrar(
    'google_maps',
    'Google Maps (frete / localização)',
    !empty($int['google_maps_configurado']),
    !empty($int['google_maps_configurado']) ? 'OK' : 'Opcional, mas melhora cálculo de entrega.',
    'Opcional: ACHEIAKI_GOOGLE_MAPS_KEY no .env ou chave no SuperAdmin.'
);

$arquivosCriticos = [
    'health.php',
    'router.php',
    'save_order.php',
    'marketplace_api.php',
    'agente_api.php',
    'login.php',
    'Database.php',
];
foreach ($arquivosCriticos as $arq) {
    $registrar('arquivo_' . $arq, 'Arquivo ' . $arq, is_file(__DIR__ . '/' . $arq));
}

if ($baseUrl !== '') {
    $healthUrl = $baseUrl . '/health.php';
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $raw = @file_get_contents($healthUrl, false, $ctx);
    $health = is_string($raw) ? json_decode($raw, true) : null;
    $registrar(
        'http_health',
        'HTTP health.php público',
        is_array($health) && (($health['status'] ?? '') === 'ok' || ($health['status'] ?? '') === 'degraded'),
        is_array($health) ? json_encode($health, JSON_UNESCAPED_UNICODE) : 'Sem resposta de ' . $healthUrl,
        'Confira DNS, HTTPS e se o host aponta para router.php / Apache com .htaccess.'
    );

    $mktUrl = $baseUrl . '/marketplace_api.php?action=listar_lojas';
    $rawMkt = @file_get_contents($mktUrl, false, $ctx);
    $mkt = is_string($rawMkt) ? json_decode($rawMkt, true) : null;
    $registrar(
        'http_marketplace',
        'API marketplace listar_lojas',
        is_array($mkt) && !empty($mkt['success']),
        is_array($mkt) ? ('Lojas: ' . (int) ($mkt['total'] ?? count($mkt['lojas'] ?? []))) : 'Falha na API pública.',
        'Teste no navegador: ' . $mktUrl
    );
}

$somenteUsuario = [
    'Contratar domínio e ativar HTTPS (Let\'s Encrypt no host).',
    'Abrir superadmin.html, trocar senha master e configurar mensalidade / planos.',
    'Cadastrar 1 loja piloto real (CNPJ, cardápio, WhatsApp) e fazer 1 pedido teste.',
    'Treinar o cliente: painel (laranja), cozinha e motoboy em 15 minutos.',
    'Definir canal de suporte (WhatsApp da plataforma em saas_config).',
    'Revisar termos.html e privacidade.html com seu advogado ou DPO.',
];

$avisos = ['google_maps', 'ext_gd', 'brand_icon', 'mercado_pago', 'sql', 'ext_curl', 'http_health', 'http_marketplace'];
$todosOk = true;
foreach ($itens as $item) {
    if ($item['ok']) {
        continue;
    }
    if (strpos((string) $item['id'], 'arquivo_') === 0) {
        $todosOk = false;
        continue;
    }
    if (in_array($item['id'], $avisos, true)) {
        continue;
    }
    $todosOk = false;
}

$resultado = [
    'success' => $todosOk,
    'pronto_para_trafego' => $todosOk && $master !== null && strlen($secret) >= 32 && is_dir($dataDir) && is_writable($dataDir),
    'hora' => date('c'),
    'checks' => $itens,
    'passos_somente_voce' => $somenteUsuario,
];

echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

if (!$resultado['pronto_para_trafego']) {
    fwrite(STDERR, "\n--- Pendências para você resolver no servidor/contas ---\n");
    foreach ($itens as $item) {
        if (!$item['ok'] && $item['acao_usuario'] !== '') {
            fwrite(STDERR, '• ' . $item['titulo'] . ': ' . $item['acao_usuario'] . "\n");
        }
    }
    fwrite(STDERR, "\n--- Só você pode fazer (operacional/comercial) ---\n");
    foreach ($somenteUsuario as $passo) {
        fwrite(STDERR, '• ' . $passo . "\n");
    }
    exit(1);
}

exit(0);