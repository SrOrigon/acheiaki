<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/integracoes_lib.php';
require_once __DIR__ . '/perfil_qualificado_lib.php';
require_once __DIR__ . '/store_schema.php';
require_once __DIR__ . '/saas_lib.php';

header('Content-Type: application/json; charset=utf-8');
pz_cors_same_origin('POST, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}

$acao = (string) ($body['acao'] ?? 'status');
$integracoes = pedidozap_integracoes_publicas();

if ($acao === 'status') {
    echo json_encode([
        'success' => true,
        'integracoes' => $integracoes,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao !== 'status' && function_exists('pedidozap_limite_requisicao') && !pedidozap_limite_requisicao('api_perfil_cliente', 20, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Muitas consultas em sequência. Aguarde um instante.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'cnpj') {
    $res = pedidozap_consultar_cnpj($body['cnpj'] ?? '');
    if (!$res['success']) {
        http_response_code(400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $perfil = pedidozap_montar_perfil(['empresa' => $res['empresa']]);
    echo json_encode([
        'success' => true,
        'empresa' => $res['empresa'],
        'perfil' => $perfil,
        'integracoes' => $integracoes,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'buscar_comercio') {
    $res = pedidozap_buscar_comercios_google(
        $body['q'] ?? $body['query'] ?? '',
        $body['lat'] ?? null,
        $body['lng'] ?? null
    );
    echo json_encode(array_merge(['integracoes' => $integracoes], $res), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'detalhar_comercio') {
    $res = pedidozap_detalhar_comercio_google($body['place_id'] ?? '');
    if (!$res['success']) {
        http_response_code(400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $empresa = null;
    if (!empty($body['cnpj'])) {
        $cnpjRes = pedidozap_consultar_cnpj($body['cnpj']);
        if ($cnpjRes['success']) {
            $empresa = $cnpjRes['empresa'];
        }
    }
    $perfil = pedidozap_montar_perfil([
        'comercio' => $res['comercio'],
        'empresa' => $empresa,
    ]);
    echo json_encode([
        'success' => true,
        'comercio' => $res['comercio'],
        'perfil' => $perfil,
        'integracoes' => $integracoes,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'perfil_completo') {
    $empresa = null;
    $comercio = null;
    if (!empty($body['cnpj'])) {
        $cnpjRes = pedidozap_consultar_cnpj($body['cnpj']);
        if (!$cnpjRes['success']) {
            http_response_code(400);
            echo json_encode($cnpjRes, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $empresa = $cnpjRes['empresa'];
    }
    if (!empty($body['place_id'])) {
        $det = pedidozap_detalhar_comercio_google($body['place_id']);
        if ($det['success']) {
            $comercio = $det['comercio'];
        }
    }
    if (!$empresa && !$comercio) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe CNPJ ou comércio do Google.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $perfil = pedidozap_montar_perfil(['empresa' => $empresa, 'comercio' => $comercio]);
    echo json_encode([
        'success' => true,
        'perfil' => $perfil,
        'integracoes' => $integracoes,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'aplicar') {
    pedidozap_bootstrap_tenant($body);
    $user = $body['auth']['user'] ?? '';
    $pass = $body['auth']['pass'] ?? '';
    $auth = pedidozap_autorizado($user, $pass);
    if (!$auth) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Não autorizado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    pedidozap_recusar_loja_bloqueada($user, $pass);
    $perfil = $body['perfil'] ?? null;
    if (!is_array($perfil)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Perfil inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ids = isset($body['melhorias']) && is_array($body['melhorias']) ? $body['melhorias'] : null;
    $somenteAuto = !empty($body['somente_auto']);
    $config = Database::read('config.json');
    if (!is_array($config)) {
        $config = [];
    }
    $config = pedidozap_normalizar_config($config, Database::getTenant());
    if ($ids === null && $somenteAuto) {
        $config = pedidozap_aplicar_perfil_em_config($config, $perfil, true);
    } elseif ($ids === null) {
        $config = pedidozap_aplicar_perfil_em_config($config, $perfil, false);
    } else {
        $config = pedidozap_aplicar_melhorias_config($config, $perfil, $ids);
    }
    pedidozap_tentar_geocode_loja($config);
    $config['VERSION'] = time();
    $ok = Database::write('config.json', $config);
    $slug = Database::getTenant();
    if ($slug !== 'default' && !empty($perfil['cnpj'])) {
        pedidozap_salvar_registro_loja($slug, [
            'razao_social' => $perfil['razao_social'] ?? '',
            'cnpj' => $perfil['cnpj'] ?? '',
            'nicho' => $perfil['nicho_sugerido'] ?? '',
        ]);
    }
    echo json_encode([
        'success' => (bool) $ok,
        'perfil' => $config['PERFIL_QUALIFICADO'] ?? $perfil,
        'nicho' => $config['NICHO'] ?? '',
        'message' => $ok ? 'Perfil aplicado na loja.' : 'Não foi possível gravar.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação desconhecida.'], JSON_UNESCAPED_UNICODE);
