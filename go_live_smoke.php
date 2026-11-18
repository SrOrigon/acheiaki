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
require_once __DIR__ . '/cache_lib.php';
require_once __DIR__ . '/queue_lib.php';
require_once __DIR__ . '/realtime_lib.php';
require_once __DIR__ . '/fiscal_lib.php';
require_once __DIR__ . '/territorio_lib.php';

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
    'update_order_status.php',
    'marcar_comanda_impressa.php',
    'get_cozinha_pedidos.php',
    'get_order_status.php',
    'get_active_orders.php',
    'pdv_api.php',
    'fiscal_api.php',
    'push_api.php',
    'realtime_events.php',
    'worker.php',
    'scripts/compress_assets.php',
    'marketplace_api.php',
    'agente_api.php',
    'login.php',
    'Database.php',
];
foreach ($arquivosCriticos as $arq) {
    $registrar('arquivo_' . $arq, 'Arquivo ' . $arq, is_file(__DIR__ . '/' . $arq));
}

// Simulação E2E transacional completa em tenant temporário isolado (__smoke_e2e)
$tenantTeste = '__smoke_e2e_' . substr(bin2hex(random_bytes(3)), 0, 6);
$tenantAnt = Database::getTenant();
try {
    Database::setTenant($tenantTeste);
    Database::write('config.json', ['NOME_LOJA' => 'Smoke Test Kitchen', 'CNPJ' => '11222333000181']);
    Database::write('cardapio.json', ['hamburgueres' => [['id' => 'smoke_1', 'nome' => 'Smoke Burger', 'preco' => 30.00, 'ativo' => true]]]);

    $tok = 'chk_smoke_' . bin2hex(random_bytes(4));
    $ordId = (string) random_int(800000, 899999);
    $ord = [
        'id' => $ordId,
        'codigo' => bin2hex(random_bytes(8)),
        'tenant' => $tenantTeste,
        'cliente' => 'Cliente Smoke',
        'celular' => '11999999999',
        'itens' => [['idProduto' => 'smoke_1', 'nome' => 'Smoke Burger', 'quantidade' => 1, 'precoTotal' => 30.00]],
        'subtotal' => 30.00,
        'total' => 30.00,
        'status' => 'novo',
        'created_at' => date('c'),
    ];
    $r1 = Database::saveOrderAtomic($ord, $tok);
    $r2 = Database::saveOrderAtomic($ord, $tok);
    $registrar('e2e_idempotencia', 'E2E Pedido atômico + idempotência checkout_token', !empty($r1['success']) && empty($r1['deduplicated']) && !empty($r2['deduplicated']));

    $t1 = Database::transitionOrderStatus($ordId, 'preparo', ['role' => 'painel']);
    $comandaId = (string) ($t1['order']['comanda_id'] ?? '');
    $t2 = Database::transitionOrderStatus($ordId, 'em-rota', ['role' => 'cozinha']);
    $t3 = Database::transitionOrderStatus($ordId, 'concluido', ['role' => 'motoboy']);
    $tRetro = Database::transitionOrderStatus($ordId, 'preparo', ['role' => 'cozinha', 'forcar_rollback' => true]);
    $oFinal = Database::findOrder($ordId);
    $stOk = !empty($t1['ok']) && !empty($t2['ok']) && !empty($t3['ok']) && empty($tRetro['ok'])
        && !empty($oFinal['aceito_em']) && !empty($oFinal['pronto_em']) && !empty($oFinal['saiu_entrega_em']) && !empty($oFinal['entregue_em']);
    $registrar('e2e_maquina_estados', 'E2E Máquina de estados + trava retrocesso + timestamps', $stOk);

    // Isolamento cross-tenant + emissão NFC-e + avaliação
    Database::setTenant($tenantTeste . '_outro');
    $vazouOrder = Database::findOrder($ordId) !== null;
    Database::setTenant($tenantTeste);
    $nf = pz_fiscal_emitir_pedido($tenantTeste, $ordId, true);
    $av = pz_territorio_registrar_avaliacao($tenantTeste, ['pedido_id' => $ordId, 'nota' => 5, 'cliente_nome' => 'Cliente Smoke', 'comentario' => 'Teste E2E perfeito']);
    $registrar('e2e_fiscal_avaliacao_isolamento', 'E2E NFC-e + Avaliação + Isolamento cross-tenant', !$vazouOrder && !empty($nf['success']) && !empty($av['success']) && $comandaId !== '');
} catch (Throwable $eE2e) {
    $registrar('e2e_excecao', 'E2E Ciclo completo', false, $eE2e->getMessage());
} finally {
    // Limpa vestígios do tenant de teste
    $dirSmoke = __DIR__ . '/data/tenants/' . $tenantTeste;
    if (is_dir($dirSmoke)) {
        foreach (@glob($dirSmoke . '/*') ?: [] as $fDel) {
            @unlink($fDel);
        }
        @rmdir($dirSmoke);
    }
    $pdoClean = function_exists('pz_pdo') ? pz_pdo(false) : null;
    if ($pdoClean instanceof PDO) {
        try {
            $pdoClean->prepare('DELETE FROM pz_kv_store WHERE tenant_slug = ?')->execute([$tenantTeste]);
            $pdoClean->prepare('DELETE FROM pz_orders_tx WHERE tenant_slug = ?')->execute([$tenantTeste]);
            $pdoClean->prepare('DELETE FROM pz_avaliacoes WHERE tenant_slug = ?')->execute([$tenantTeste]);
        } catch (Throwable $e) {
            // ignora se tabela não existir
        }
    }
    Database::setTenant($tenantAnt);
}

if ($baseUrl !== '') {
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $httpCodeOf = static function (string $url, string $method = 'GET', ?string $body = null) use ($ctx): int {
        $opts = ['http' => ['method' => $method, 'timeout' => 12, 'ignore_errors' => true]];
        if ($body !== null) {
            $opts['http']['header'] = "Content-Type: application/json\r\n";
            $opts['http']['content'] = $body;
        }
        @file_get_contents($url, false, stream_context_create($opts));
        $statusLine = $http_response_header[0] ?? '';
        return preg_match('/\s(\d{3})\s/', $statusLine, $m) ? (int) $m[1] : 0;
    };

    $healthUrl = $baseUrl . '/health.php';
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

    $cFiscal = $httpCodeOf($baseUrl . '/fiscal_api.php?tenant=default');
    $cPdv = $httpCodeOf($baseUrl . '/pdv_api.php?action=orders_polling&tenant=default');
    $cPush = $httpCodeOf($baseUrl . '/push_api.php?action=subscribe&tenant=default', 'POST', '{"role":"painel","fcm_token":"fake"}');
    $rtRaw = @file_get_contents($baseUrl . '/realtime_events.php?tenant=default&mode=json', false, stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]));
    $rtJson = is_string($rtRaw) ? json_decode($rtRaw, true) : null;
    $rtSafe = is_array($rtJson) && ($rtJson['scope'] ?? '') === 'public';

    $registrar('sec_fiscal_401', 'Segurança fiscal_api bloqueia sem auth', $cFiscal === 401, 'HTTP ' . $cFiscal);
    $registrar('sec_pdv_401', 'Segurança pdv_api bloqueia sem API Key', $cPdv === 401, 'HTTP ' . $cPdv);
    $registrar('sec_push_401', 'Segurança push_api bloqueia role operacional sem auth', $cPush === 401, 'HTTP ' . $cPush);
    $registrar('sec_realtime_scope', 'Segurança realtime_events isola PII sem auth', $rtSafe, is_array($rtJson) ? ('scope=' . ($rtJson['scope'] ?? '')) : 'sem json');
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