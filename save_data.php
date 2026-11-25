<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/store_schema.php';

header('Content-Type: application/json; charset=utf-8');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

pedidozap_bootstrap_tenant(is_array($data) ? $data : null);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dados inválidos recebidos.']);
    exit;
}

$user = $data['auth']['user'] ?? '';
$pass = $data['auth']['pass'] ?? '';

if (!pedidozap_autorizado($user, $pass)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado: Credenciais inválidas.']);
    exit;
}
pedidozap_recusar_loja_bloqueada($user, $pass);

$sessao = pedidozap_sessao_info($user, $pass);
if (isset($data['cardapio']) && !pedidozap_tem_permissao($sessao, 'cardapio')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sem permissão para alterar o cardápio.']);
    exit;
}
if ((isset($data['config']) || !empty($data['auth_update']) || !empty($data['reset'])) && !pedidozap_tem_permissao($sessao, 'config')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sem permissão para alterar configurações da loja.']);
    exit;
}

$tenant = Database::getTenant();
$success = true;
$messages = [];
$version = time();

try {
    if (!empty($data['auth_update']['pass'])) {
        $novoUsuario = trim((string) ($data['auth_update']['user'] ?? ''));
        if ($novoUsuario === '') {
            $novoUsuario = pedidozap_usuario_atual();
        }
        if (!pedidozap_salvar_credenciais($novoUsuario, (string) $data['auth_update']['pass'])) {
            $success = false;
            $messages[] = 'Não foi possível atualizar o login.';
        }
    }

    if (!empty($data['reset'])) {
        $reset = (string) $data['reset'];
        if ($reset === 'cardapio' || $reset === 'tudo') {
            $cardapioAntigoReset = Database::readStrict('cardapio.json');
            $vazio = Database::emptyCardapioStructure();
            pedidozap_sincronizar_midias_cardapio($tenant, is_array($cardapioAntigoReset) ? $cardapioAntigoReset : [], $vazio);
            Database::write('cardapio.json', $vazio);
        }
        if ($reset === 'pedidos' || $reset === 'tudo') {
            Database::write('pedidos.json', []);
        }
        if ($reset === 'config' || $reset === 'tudo') {
            $limpo = pedidozap_normalizar_config([], $tenant);
            $limpo['VERSION'] = $version;
            Database::write('config.json', $limpo);
        }
        if (!in_array($reset, ['cardapio', 'pedidos', 'config', 'tudo'], true)) {
            $success = false;
            $messages[] = 'Tipo de restauração inválido.';
        }
    }

    if (isset($data['cardapio']) && is_array($data['cardapio'])) {
        $isMasterSave = ($sessao['role'] ?? '') === 'master';
        $planoSave = pz_plano_da_loja($tenant);
        $maxProdsPlano = (int) ($planoSave['limites']['max_produtos'] ?? 0);
        if (!$isMasterSave && $maxProdsPlano > 0) {
            $qtdProdsEnv = pz_plano_contar_produtos_cardapio($data['cardapio']);
            if ($qtdProdsEnv > $maxProdsPlano) {
                $proxPlanoSlug = $planoSave['slug'] === 'start' ? 'pro' : 'scale';
                $proxPlanoNome = $planoSave['slug'] === 'start' ? 'Pro (até 80 itens)' : 'Scale IA (ilimitado)';
                http_response_code(402);
                echo json_encode([
                    'success' => false,
                    'codigo_erro' => 'upgrade_plano',
                    'plano_atual' => $planoSave['slug'],
                    'plano_necessario' => $proxPlanoSlug,
                    'plano_necessario_nome' => $proxPlanoNome,
                    'message' => 'Seu plano ' . $planoSave['nome'] . ' permite até ' . $maxProdsPlano . ' produtos no cardápio (você enviou ' . $qtdProdsEnv . '). Faça upgrade para o plano ' . $proxPlanoNome . '.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $configAtual = Database::read('config.json');
        $configNorm = pedidozap_normalizar_config(is_array($configAtual) ? $configAtual : [], $tenant);
        if (isset($data['config']) && is_array($data['config'])) {
            $configNorm = pedidozap_normalizar_config($data['config'], $tenant);
        }
        $cardapioAntigo = Database::readStrict('cardapio.json');
        $cardapio = pedidozap_limpar_cardapio_para_salvar($data['cardapio'], $configNorm);
        pedidozap_sincronizar_midias_cardapio($tenant, is_array($cardapioAntigo) ? $cardapioAntigo : [], $cardapio);
        if (!Database::write('cardapio.json', $cardapio)) {
            $success = false;
            $messages[] = 'Erro ao salvar cardapio.json do estabelecimento.';
        }
    }

    if (isset($data['config']) && is_array($data['config'])) {
        $config = pedidozap_normalizar_config($data['config'], $tenant);
        $config['VERSION'] = $version;
        pedidozap_tentar_geocode_loja($config);
        $config = pedidozap_config_para_sql($config);

        if (!Database::write('config.json', $config)) {
            $success = false;
            $messages[] = 'Erro ao salvar config.json do estabelecimento.';
        }
    }

    $gerarJsLegado = $tenant === 'default' && empty($data['reset'])
        && !(function_exists('pz_db_sql_modo_ativo') && pz_db_sql_modo_ativo())
        && !(function_exists('pz_runtime_railway') && pz_runtime_railway());

    if ($gerarJsLegado) {
        if (isset($data['cardapio'])) {
            $cardapioSalvo = Database::readStrict('cardapio.json');
            $cardapioContent = "window.CARDAPIO_DADOS = " . json_encode($cardapioSalvo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ";";
            if (file_put_contents(__DIR__ . '/js/dados_cardapio.js', $cardapioContent) === false) {
                $success = false;
                $messages[] = 'Erro ao atualizar js/dados_cardapio.js (legado).';
            }
        }

        if (isset($data['config'])) {
            $configJs = Database::readStrict('config.json');
            $configContent = "/**\n * AcheIAki — configuração da loja (gerada pelo painel).\n */\nwindow.APP_CONFIG = " .
                json_encode($configJs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ";";
            if (file_put_contents(__DIR__ . '/js/config.js', $configContent) === false) {
                $success = false;
                $messages[] = 'Erro ao atualizar js/config.js (legado).';
            }
        }
    }

    $nomeLoja = $data['config']['NOME_LOJA'] ?? 'Nome da Sua Loja';
    Database::registerTenant($tenant, [
        'nome_loja' => $nomeLoja,
        'whatsapp' => $data['config']['WHATSAPP_NUMBER'] ?? null,
    ]);
} catch (Exception $e) {
    $success = false;
    $messages[] = $e->getMessage();
}

echo json_encode([
    'success' => $success,
    'message' => $success
        ? 'Dados salvos.'
        : implode(', ', $messages),
    'tenant' => $tenant,
    'version' => $version,
    'user' => pedidozap_usuario_atual(),
], JSON_UNESCAPED_UNICODE);
