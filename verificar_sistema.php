<?php
/**
 * Diagnóstico rápido (CLI ou navegador master). Não exponha em produção pública sem proteção.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/integracoes_lib.php';
require_once __DIR__ . '/perfil_qualificado_lib.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Execute no servidor: php verificar_sistema.php'], JSON_UNESCAPED_UNICODE);
    exit;
}

$checks = [];
$checks['php'] = ['ok' => true, 'version' => PHP_VERSION];
$checks['openssl'] = ['ok' => extension_loaded('openssl')];
$checks['json'] = ['ok' => extension_loaded('json')];
$checks['allow_url_fopen'] = ['ok' => (bool) ini_get('allow_url_fopen'), 'nota' => 'Necessário para CNPJ e Google via file_get_contents'];

$dataDir = __DIR__ . '/data';
$checks['data_gravavel'] = ['ok' => is_dir($dataDir) && is_writable($dataDir)];

$checks['integracoes'] = pedidozap_integracoes_publicas();
$checks['master_config'] = ['ok' => pedidozap_ler_master() !== null, 'nota' => 'Defina ACHEIAKI_MASTER_* no .env ou master_auth.json'];
$checks['app_secret'] = ['ok' => strlen(pedidozap_app_secret()) >= 32];
$checks['brand_icon'] = [
    'ok' => is_file(__DIR__ . '/assets/images/acheiaki-icon.jpg')
        || is_file(__DIR__ . '/assets/images/acheiaki-icon.png'),
    'nota' => 'Inclua assets/images/acheiaki-icon.jpg para PWA e redes sociais',
];
$checks['gd'] = ['ok' => extension_loaded('gd'), 'nota' => 'Recomendado para redimensionar fotos de produtos'];
$checks['curl'] = ['ok' => function_exists('curl_init')];
$checks['mbstring'] = ['ok' => extension_loaded('mbstring')];
$checks['cnpj_validador'] = ['ok' => pedidozap_validar_cnpj('00000000000191')];

$cnpjTeste = pedidozap_consultar_cnpj('00000000000191');
$checks['cnpj_api'] = [
    'ok' => !empty($cnpjTeste['success']),
    'message' => $cnpjTeste['message'] ?? ($cnpjTeste['success'] ? 'OK' : 'falha'),
];

$perfilDemo = pedidozap_montar_perfil([
    'empresa' => [
        'cnpj' => '00000000000191',
        'razao_social' => 'BANCO DO BRASIL SA',
        'nome_fantasia' => 'BANCO DO BRASIL',
        'cnae' => 6422100,
        'cnae_descricao' => 'Bancos múltiplos',
    ],
]);
$checks['motor_perfil'] = [
    'ok' => !empty($perfilDemo['melhorias']),
    'nicho' => $perfilDemo['nicho_sugerido'] ?? '',
];

$todosOk = true;
foreach ($checks as $item) {
    if (is_array($item) && array_key_exists('ok', $item) && !$item['ok']) {
        $todosOk = false;
    }
}

echo json_encode([
    'success' => $todosOk,
    'checks' => $checks,
    'hora' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
