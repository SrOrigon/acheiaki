<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/store_schema.php';
require_once __DIR__ . '/integracoes_lib.php';
require_once __DIR__ . '/territorio_lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}

$user = $body['auth']['user'] ?? $_GET['user'] ?? '';
$pass = $body['auth']['pass'] ?? $_GET['pass'] ?? '';

if (pedidozap_autorizado($user, $pass) !== 'master') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Acesso exclusivo do painel master.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$acao = $body['acao'] ?? $_GET['acao'] ?? 'listar';

if ($acao === 'listar') {
    echo json_encode([
        'success' => true,
        'lojas' => pedidozap_resumo_lojas(),
        'nichos' => pedidozap_nicho_presets(),
        'saas' => pedidozap_saas_config_publica(),
        'integracoes' => pedidozap_integracoes_publicas(),
        'db_config' => function_exists('pz_db_config_publica') ? pz_db_config_publica() : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'trocar_master') {
    $novoUser = trim((string) ($body['novo_user'] ?? $user));
    $novaSenha = (string) ($body['nova_senha'] ?? '');
    $ok = pedidozap_salvar_master($novoUser, $novaSenha);
    echo json_encode(['success' => (bool) $ok, 'user' => $novoUser], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'salvar_saas') {
    $ok = pedidozap_salvar_saas_config($body['saas'] ?? []);
    echo json_encode(['success' => $ok, 'saas' => pedidozap_saas_config_publica()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'atualizar') {
    $slug = $body['slug'] ?? '';
    $meta = [];
    foreach (['nome_empresa', 'razao_social', 'nicho', 'login', 'status', 'plano', 'vencimento', 'whatsapp', 'destaque', 'cidade', 'bairro'] as $campo) {
        if (array_key_exists($campo, $body)) {
            $meta[$campo] = is_string($body[$campo]) ? trim($body[$campo]) : $body[$campo];
        }
    }
    if (isset($meta['status']) && !in_array($meta['status'], ['ativo', 'bloqueado', 'pendente', 'teste'], true)) {
        $meta['status'] = 'ativo';
    }
    $ok = pedidozap_salvar_registro_loja($slug, $meta);
    echo json_encode(['success' => (bool) $ok, 'lojas' => pedidozap_resumo_lojas()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'redefinir_senha') {
    $slug = strtolower(trim((string) ($body['slug'] ?? '')));
    $nova = (string) ($body['senha'] ?? '');
    $login = trim((string) ($body['login'] ?? ''));
    if ($slug === '' || $nova === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe a loja e a nova senha.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    if ($login === '') {
        $login = pedidozap_usuario_atual() ?: 'admin';
    }
    $ok = pedidozap_salvar_credenciais($login, $nova);
    Database::setTenant($anterior);
    if ($ok) {
        pedidozap_salvar_registro_loja($slug, ['login' => $login]);
    }
    echo json_encode(['success' => (bool) $ok], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'criar') {
    $resultado = pedidozap_criar_loja([
        'nome_empresa' => $body['nome_empresa'] ?? '',
        'razao_social' => $body['razao_social'] ?? '',
        'slug' => $body['slug'] ?? '',
        'nicho' => $body['nicho'] ?? 'lanchonete',
        'login' => $body['login'] ?? 'admin',
        'senha' => $body['senha'] ?? '',
        'plano' => $body['plano'] ?? 'basico',
        'vencimento' => $body['vencimento'] ?? '',
        'whatsapp' => $body['whatsapp'] ?? '',
        'status' => 'ativo',
    ]);
    if (!$resultado['success']) {
        http_response_code(400);
    }
    $resultado['lojas'] = pedidozap_resumo_lojas();
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'integracoes') {
    echo json_encode([
        'success' => true,
        'integracoes' => pedidozap_integracoes_publicas(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'salvar_integracoes') {
    $patch = is_array($body['integracoes'] ?? null) ? $body['integracoes'] : [];
    if (isset($patch['google_maps_api_key'])) {
        $patch['google_maps_habilitado'] = trim((string) $patch['google_maps_api_key']) !== '';
    }
    $ok = pedidozap_salvar_integracoes($patch);
    echo json_encode([
        'success' => $ok,
        'integracoes' => pedidozap_integracoes_publicas(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'salvar_db') {
    $cfg = is_array($body['db'] ?? null) ? $body['db'] : [];
    $ok = pz_db_salvar_config($cfg);
    $pub = pz_db_config_publica();
    $migrarMsg = '';
    if (!empty($body['migrar']) && !empty($pub['conectado'])) {
        $pdo = pz_pdo(true);
        if ($pdo) {
            $qtd = pz_migrar_json_para_sql($pdo);
            pz_territorio_coletar_lojas();
            $migrarMsg = " {$qtd} arquivo(s) JSON importado(s) para o SQL.";
        }
    }
    echo json_encode([
        'success' => $ok,
        'db_config' => $pub,
        'message' => ($pub['conectado'] ? 'Conectado ao banco relacional e schema criado!' : ($pub['erro_conexao'] ?: 'Configuração salva (modo JSON ativo).')) . $migrarMsg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'migrar_sql') {
    try {
        $pdo = pz_pdo(true);
        if (!$pdo) {
            throw new RuntimeException('Habilite e salve um banco MySQL/PostgreSQL válido primeiro.');
        }
        $qtd = pz_migrar_json_para_sql($pdo);
        pz_territorio_coletar_lojas();
        echo json_encode([
            'success' => true,
            'message' => "Migração concluída: {$qtd} documento(s) importado(s) e índice territorial atualizado.",
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($acao === 'uso_banco') {
    // Relatório leve (somente master) do que ocupa espaço no banco: prova que lá só há dados de clientes/lojas.
    try {
        $pdo = pz_pdo(true);
        if (!$pdo) {
            throw new RuntimeException('Banco SQL não está ativo (modo JSON local).');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tabelas = [];
        $totalDados = 0;
        $rotulos = [
            'pz_compradores' => 'Contas de clientes (compradores)',
            'pz_pedidos_mkt' => 'Pedidos do marketplace',
            'pz_avaliacoes' => 'Avaliações',
            'pz_kv_store' => 'Dados das lojas (cardápio, config, pedidos, clientes)',
            'pz_media_store' => 'Fotos de produtos (compactadas)',
            'pz_tenants_index' => 'Índice de lojas por região',
        ];
        foreach ($rotulos as $tab => $rotulo) {
            $bytes = 0;
            $linhas = 0;
            try {
                $linhas = (int) $pdo->query("SELECT COUNT(*) FROM {$tab}")->fetchColumn();
                if ($driver === 'pgsql') {
                    $bytes = (int) $pdo->query("SELECT pg_total_relation_size('{$tab}')")->fetchColumn();
                } else {
                    $st = $pdo->prepare('SELECT COALESCE(data_length + index_length, 0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                    $st->execute([$tab]);
                    $bytes = (int) $st->fetchColumn();
                }
            } catch (Throwable $e) {
                // tabela ainda não criada
            }
            $totalDados += $bytes;
            $tabelas[] = ['tabela' => $tab, 'rotulo' => $rotulo, 'linhas' => $linhas, 'bytes' => $bytes];
        }
        $bancoTotal = $totalDados;
        if ($driver === 'pgsql') {
            $bancoTotal = (int) $pdo->query('SELECT pg_database_size(current_database())')->fetchColumn();
        }
        echo json_encode([
            'success' => true,
            'driver' => $driver,
            'bytes_dados_sistema' => $totalDados,
            'bytes_banco_total' => $bancoTotal,
            'tabelas' => $tabelas,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação desconhecida.'], JSON_UNESCAPED_UNICODE);
