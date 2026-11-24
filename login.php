<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/saas_lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true) ?: [];

if (!pedidozap_limite_login()) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Muitas tentativas erradas. Aguarde 15 minutos e tente de novo.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = trim((string) ($data['user'] ?? $_POST['user'] ?? ''));
$pass = (string) ($data['pass'] ?? $_POST['pass'] ?? '');
$tenantInformado = trim((string) ($data['tenant'] ?? $_GET['tenant'] ?? ''));
$globalLogin = !empty($data['global_login']) || $tenantInformado === '';

if ($tenantInformado === 'loja-demonstracao-acheiaki' || strcasecmp($user, 'demo') === 0) {
    require_once __DIR__ . '/demo_seed_lib.php';
    pedidozap_garantir_loja_demo();
}

if ($user !== '' && pedidozap_verificar_master($user, $pass)) {
    pedidozap_limite_login(false, true);
    echo json_encode([
        'success' => true,
        'user' => $user,
        'nome' => $user,
        'role' => 'master',
        'tenant' => 'default',
        'url_admin' => 'superadmin.html',
        'permissoes' => pedidozap_perfis_permissoes_padrao('administrador'),
        'token' => pedidozap_emitir_token($user, 'master', 43200),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function pz_login_norm_nome($txt)
{
    $s = mb_strtolower(trim((string) $txt), 'UTF-8');
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($conv !== false) {
            $s = $conv;
        }
    }
    return preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/i', '', $s));
}

function pz_login_tentar_tenant($slug, $identificador, $pass)
{
    Database::setTenant($slug);
    $auth = pedidozap_ler_auth();
    $cfg = Database::readStrict('config.json');
    $nomeEmpresa = trim((string) ($cfg['NOME_LOJA'] ?? ''));
    $cnpjDigits = preg_replace('/\D/', '', (string) ($cfg['CNPJ'] ?? ''));
    $identDigits = preg_replace('/\D/', '', (string) $identificador);

    if ($auth) {
        $authUser = (string) ($auth['user'] ?? '');
        $bateIdent = strcasecmp($authUser, $identificador) === 0
            || strcasecmp($slug, $identificador) === 0
            || ($nomeEmpresa !== '' && pz_login_norm_nome($nomeEmpresa) === pz_login_norm_nome($identificador))
            || ($cnpjDigits !== '' && strlen($identDigits) === 14 && $cnpjDigits === $identDigits);

        if ($bateIdent && pedidozap_verificar_credenciais($authUser, $pass)) {
            $usuario = pedidozap_usuario_atual();
            return [
                'success' => true,
                'user' => $usuario,
                'nome' => $nomeEmpresa !== '' ? $nomeEmpresa : $usuario,
                'nome_empresa' => $nomeEmpresa !== '' ? $nomeEmpresa : $slug,
                'role' => 'lojista',
                'tenant' => $slug,
                'url_admin' => 'admin.html?tenant=' . rawurlencode($slug),
                'permissoes' => pedidozap_perfis_permissoes_padrao('administrador'),
                'token' => pedidozap_emitir_token($usuario, 'lojista'),
            ];
        }
    }

    if (($func = pedidozap_verificar_funcionario($identificador, $pass))) {
        $perms = is_array($func['permissoes'] ?? null)
            ? $func['permissoes']
            : pedidozap_perfis_permissoes_padrao($func['funcao'] ?? 'atendente');
        return [
            'success' => true,
            'user' => (string) $func['login'],
            'nome' => (string) ($func['nome'] ?? $func['login']),
            'nome_empresa' => $nomeEmpresa !== '' ? $nomeEmpresa : $slug,
            'funcao' => (string) ($func['funcao'] ?? 'atendente'),
            'role' => 'funcionario',
            'tenant' => $slug,
            'url_admin' => 'admin.html?tenant=' . rawurlencode($slug),
            'permissoes' => $perms,
            'token' => pedidozap_emitir_token((string) $func['login'], 'funcionario'),
        ];
    }

    return null;
}

if ($user !== '' && !$globalLogin) {
    pedidozap_bootstrap_tenant($data);
    $slugAtual = Database::getTenant();
    $res = pz_login_tentar_tenant($slugAtual, $user, $pass);
    if ($res) {
        pedidozap_limite_login(false, true);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($user !== '' && $globalLogin) {
    $slugs = ['default'];
    foreach (pedidozap_listar_lojas() as $loja) {
        $s = (string) ($loja['slug'] ?? '');
        if ($s !== '' && !in_array($s, $slugs, true)) {
            $slugs[] = $s;
        }
    }
    foreach ($slugs as $slugCand) {
        if ($slugCand !== 'default' && !Database::tenantDataExists($slugCand)) {
            continue;
        }
        $res = pz_login_tentar_tenant($slugCand, $user, $pass);
        if ($res) {
            pedidozap_limite_login(false, true);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

pedidozap_limite_login(true);
http_response_code(401);
echo json_encode(['success' => false, 'message' => 'Usuário/empresa ou senha incorretos.'], JSON_UNESCAPED_UNICODE);
