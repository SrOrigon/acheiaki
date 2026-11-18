<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/saas_lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use o formulário de cadastro.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}

if (trim((string) ($body['website'] ?? '')) !== '') {
    echo json_encode(['success' => false, 'message' => 'Não foi possível criar a loja.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$whatsapp = preg_replace('/\D/', '', (string) ($body['whatsapp'] ?? ''));
if (strlen($whatsapp) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Informe o WhatsApp com DDD.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$perfil = is_array($body['perfil'] ?? null) ? $body['perfil'] : null;
$nichoCadastro = trim((string) ($body['nicho'] ?? ''));
if ($nichoCadastro === '' && $perfil && !empty($perfil['nicho_sugerido'])) {
    $nichoCadastro = (string) $perfil['nicho_sugerido'];
}
if ($nichoCadastro === '') {
    $nichoCadastro = 'lanchonete';
}

$exigirTermos = !empty($body['exigir_termos']) || !array_key_exists('aceitou_termos', $body) ? true : !empty($body['aceitou_termos']);
if (array_key_exists('aceitou_termos', $body) && empty($body['aceitou_termos'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'É obrigatório ler e aceitar os Termos de Uso e a Política de Privacidade (LGPD) para criar sua conta.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$resultado = pedidozap_criar_loja([
    'nome_empresa' => $body['nome_empresa'] ?? '',
    'razao_social' => $body['razao_social'] ?? '',
    'cnpj' => $body['cnpj'] ?? '',
    'nicho' => $nichoCadastro,
    'whatsapp' => $whatsapp,
    'login' => $body['login'] ?? '',
    'senha' => $body['senha'] ?? '',
    'rua' => $body['rua'] ?? '',
    'numero' => $body['numero'] ?? '',
    'cidade' => $body['cidade'] ?? '',
    'bairro' => $body['bairro'] ?? '',
    'estado' => $body['estado'] ?? '',
    'cep' => $body['cep'] ?? '',
    'lat' => $body['lat'] ?? 0,
    'lng' => $body['lng'] ?? 0,
    'raio_entrega_km' => $body['raio_entrega_km'] ?? 10,
    'tempo_medio_min' => $body['tempo_medio_min'] ?? 35,
    'taxa_entrega_base' => $body['taxa_entrega_base'] ?? 0,
    'pagamentos' => is_array($body['pagamentos'] ?? null) ? $body['pagamentos'] : [],
    'perfil' => $perfil,
    'nivel_experiencia' => $body['nivel_experiencia'] ?? 'basico',
    'nivel_experiencia_confirmado' => array_key_exists('nivel_experiencia', $body),
    'consentimento_lgpd' => [
        'termos_aceitos' => !empty($body['aceitou_termos']) || !array_key_exists('aceitou_termos', $body),
        'ia_personalizacao' => !array_key_exists('ia_personalizacao', $body) || !empty($body['ia_personalizacao']),
        'versao_termos' => '2026-11',
        'aceito_em' => date('c'),
        'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
    ],
    'exigir_cadastro_completo' => true,
    'em_teste' => true,
    'status' => 'teste',
    'plano' => 'teste',
]);

if (!$resultado['success']) {
    http_response_code(400);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = $resultado['slug'];
$base = rtrim(pedidozap_base_url(), '/');
echo json_encode([
    'success' => true,
    'slug' => $slug,
    'login' => $resultado['login'],
    'vencimento' => $resultado['vencimento'],
    'url_cardapio' => $base . '/loja/' . rawurlencode($slug) . '/',
    'url_painel' => $base . '/loja/' . rawurlencode($slug) . '/admin.html',
    'url_tutorial' => $base . '/loja/' . rawurlencode($slug) . '/tutorial.html',
    'perfil' => $perfil ? ['nicho_sugerido' => $perfil['nicho_sugerido'] ?? '', 'melhorias' => $perfil['melhorias'] ?? []] : null,
], JSON_UNESCAPED_UNICODE);
