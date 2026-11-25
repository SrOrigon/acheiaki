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
    $antInd = Database::getTenant();
    Database::setTenant('default');
    $indicacoes = Database::read('indicacoes_lojas.json');
    Database::setTenant($antInd);
    echo json_encode([
        'success' => true,
        'lojas' => pedidozap_resumo_lojas(),
        'nichos' => pedidozap_nicho_presets(),
        'saas' => pedidozap_saas_config_publica(),
        'planos' => array_values(pz_planos_catalogo()),
        'integracoes' => pedidozap_integracoes_publicas(),
        'db_config' => function_exists('pz_db_config_publica') ? pz_db_config_publica() : null,
        'indicacoes' => is_array($indicacoes) ? $indicacoes : [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'indicar_status') {
    $idInd = trim((string) ($body['id'] ?? ''));
    $stInd = in_array(($body['status'] ?? ''), ['pendente', 'abordada', 'cadastrada'], true) ? $body['status'] : 'abordada';
    $antInd = Database::getTenant();
    Database::setTenant('default');
    Database::mutate('indicacoes_lojas.json', function (&$lista) use ($idInd, $stInd) {
        if (!is_array($lista)) {
            return false;
        }
        foreach ($lista as $i => $row) {
            if (is_array($row) && ($row['id'] ?? '') === $idInd) {
                $lista[$i]['status'] = $stInd;
                return true;
            }
        }
        return false;
    });
    $indicacoes = Database::read('indicacoes_lojas.json');
    Database::setTenant($antInd);
    echo json_encode(['success' => true, 'indicacoes' => is_array($indicacoes) ? $indicacoes : []], JSON_UNESCAPED_UNICODE);
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
    foreach (['nome_empresa', 'razao_social', 'nicho', 'login', 'status', 'plano', 'plano_escolhido', 'ciclo_cobranca', 'grupo_rede', 'vencimento', 'whatsapp', 'destaque', 'cidade', 'bairro'] as $campo) {
        if (array_key_exists($campo, $body)) {
            $meta[$campo] = is_string($body[$campo]) ? trim($body[$campo]) : $body[$campo];
        }
    }
    if (isset($meta['plano']) && $meta['plano'] !== '') {
        $meta['plano'] = pz_plano_normalizar($meta['plano']);
        $meta['plano_escolhido'] = $meta['plano'];
    }
    if (isset($meta['status'])) {
        if (!in_array($meta['status'], ['ativo', 'bloqueado', 'pendente', 'teste'], true)) {
            $meta['status'] = 'ativo';
        }
        if ($meta['status'] === 'ativo') {
            $meta['bloqueio_motivo'] = '';
            $meta['shadowban_ativo'] = false;
            $meta['taxa_reativacao_pendente'] = false;
            $meta['em_teste'] = false;
        } elseif ($meta['status'] === 'bloqueado') {
            $meta['bloqueio_motivo'] = 'manual';
            $meta['shadowban_ativo'] = true;
        }
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
        'plano' => pz_plano_normalizar((string) ($body['plano'] ?? 'essencial')),
        'plano_escolhido' => pz_plano_normalizar((string) ($body['plano'] ?? 'essencial')),
        'grupo_rede' => trim((string) ($body['grupo_rede'] ?? '')),
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

if ($acao === 'clonar_vitrine') {
    $nomeEmp = trim((string) ($body['nome_empresa'] ?? ''));
    $slugNovo = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim((string) ($body['slug'] ?? ''))));
    if ($slugNovo === '' && $nomeEmp !== '') {
        $slugNovo = pedidozap_slugify($nomeEmp);
    }
    $nichoAlvo = trim((string) ($body['nicho'] ?? 'lanchonete')) ?: 'lanchonete';
    $loginNovo = trim((string) ($body['login'] ?? 'admin')) ?: 'admin';
    $senhaUsada = (string) ($body['senha'] ?? '');
    if (strlen($senhaUsada) < 6) {
        $senhaUsada = 'Aki@' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
    $diasCortesia = max(7, min(90, (int) ($body['dias_cortesia'] ?? 30)));
    $vencPiloto = trim((string) ($body['vencimento'] ?? '')) ?: date('Y-m-d', strtotime('+' . $diasCortesia . ' days'));
    $bairroPiloto = trim((string) ($body['bairro'] ?? 'Campo Grande')) ?: 'Campo Grande';
    $cidadePiloto = trim((string) ($body['cidade'] ?? 'Rio de Janeiro')) ?: 'Rio de Janeiro';
    $ufPiloto = strtoupper(substr(trim((string) ($body['estado'] ?? 'RJ')) ?: 'RJ', 0, 2));
    $zapPiloto = preg_replace('/\D/', '', (string) ($body['whatsapp'] ?? ''));
    $planoPiloto = pz_plano_normalizar((string) ($body['plano'] ?? 'pro'));

    $criado = pedidozap_criar_loja([
        'nome_empresa' => $nomeEmp,
        'slug' => $slugNovo,
        'nicho' => $nichoAlvo,
        'login' => $loginNovo,
        'senha' => $senhaUsada,
        'plano' => $planoPiloto,
        'plano_escolhido' => $planoPiloto,
        'vencimento' => $vencPiloto,
        'whatsapp' => $zapPiloto,
        'bairro' => $bairroPiloto,
        'cidade' => $cidadePiloto,
        'estado' => $ufPiloto,
        'taxa_entrega_base' => is_numeric($body['taxa_entrega'] ?? null) ? (float) $body['taxa_entrega'] : 5.0,
        'tempo_medio_min' => 35,
        'status' => 'ativo',
    ]);
    if (empty($criado['success'])) {
        http_response_code(400);
        echo json_encode($criado, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $slugFinal = (string) $criado['slug'];
    $origemSlug = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim((string) ($body['origem_slug'] ?? ''))));
    $itensTexto = trim((string) ($body['itens_rapidos'] ?? ''));

    $antT = Database::getTenant();
    $qtdItens = 0;
    try {
        Database::setTenant($slugFinal);
        $cfgDest = pedidozap_normalizar_config(Database::readStrict('config.json'), $slugFinal);
        $cardDest = Database::readStrict('cardapio.json');
        if (!is_array($cardDest)) {
            $cardDest = [];
        }

        if ($origemSlug !== '' && ($origemSlug === 'loja-demonstracao-acheiaki' || Database::tenantDataExists($origemSlug))) {
            Database::setTenant($origemSlug);
            $cfgOrig = pedidozap_normalizar_config(Database::readStrict('config.json'), $origemSlug);
            $cardOrig = Database::readStrict('cardapio.json');
            Database::setTenant($slugFinal);
            if (is_array($cardOrig) && $cardOrig) {
                $cardDest = $cardOrig;
            }
            if (!empty($cfgOrig['CATEGORIAS']) && is_array($cfgOrig['CATEGORIAS'])) {
                $cfgDest['CATEGORIAS'] = $cfgOrig['CATEGORIAS'];
            }
            if (!empty($cfgOrig['TEXTOS']['PAGINAS']) && is_array($cfgOrig['TEXTOS']['PAGINAS'])) {
                $cfgDest['TEXTOS']['PAGINAS'] = $cfgOrig['TEXTOS']['PAGINAS'];
            }
            if (!empty($cfgOrig['IDENTIDADE']['CORES'])) {
                $cfgDest['IDENTIDADE']['CORES'] = $cfgOrig['IDENTIDADE']['CORES'];
            }
        }

        if ($itensTexto !== '') {
            $catsKeys = array_keys($cfgDest['CATEGORIAS'] ?? []);
            $catAlvo = 'destaques';
            foreach ($catsKeys as $ck) {
                if ($ck !== 'promocoes') {
                    $catAlvo = $ck;
                    break;
                }
            }
            if (!isset($cfgDest['CATEGORIAS'][$catAlvo])) {
                $cfgDest['CATEGORIAS'][$catAlvo] = ['nome' => 'Mais Pedidos', 'subtitulo' => 'Seleção da loja', 'emoji' => '⭐', 'icone' => 'fa-star', 'cor' => '#ea580c', 'ativo' => true];
            }
            if (!isset($cardDest[$catAlvo]) || !is_array($cardDest[$catAlvo])) {
                $cardDest[$catAlvo] = [];
            }
            $linhas = preg_split('/\r\n|\r|\n/', $itensTexto) ?: [];
            $idxItem = 1;
            foreach (array_slice($linhas, 0, 30) as $linha) {
                $linha = trim($linha);
                if ($linha === '') {
                    continue;
                }
                $partes = array_map('trim', explode('|', $linha));
                $nomeItem = $partes[0] ?? '';
                if ($nomeItem === '') {
                    continue;
                }
                $precoNum = isset($partes[1]) ? (float) str_replace(['R$', ' ', ','], ['', '', '.'], $partes[1]) : 0.0;
                $descItem = $partes[2] ?? 'Produto direto da loja sem taxa de aplicativo.';
                $cardDest[$catAlvo][] = [
                    'id' => 'piloto_' . $idxItem . '_' . substr(bin2hex(random_bytes(2)), 0, 4),
                    'nome' => $nomeItem,
                    'descricao' => $descItem,
                    'preco' => max(0.0, round($precoNum, 2)),
                    'imagem' => 'assets/images/placeholder-logo.svg',
                    'destaque' => $idxItem <= 4,
                    'maisPedido' => $idxItem <= 2,
                    'ativo' => true,
                ];
                $idxItem++;
            }
        }

        foreach ($cardDest as $arrCat) {
            if (is_array($arrCat)) {
                $qtdItens += count($arrCat);
            }
        }
        $cfgDest['NOME_LOJA'] = $nomeEmp;
        $cfgDest['TEXTOS']['HERO_TITULO'] = $nomeEmp;
        $cfgDest['TEXTOS']['HERO_SUBTITULO'] = 'Peça direto sem taxa de aplicativo — ' . $bairroPiloto . ', ' . $cidadePiloto . '.';
        $cfgDest['TEXTOS']['SECAO_CARDAPIO'] = 'Explore nosso Catálogo';
        $cfgDest['ENDERECO']['bairro'] = $bairroPiloto;
        $cfgDest['ENDERECO']['cidade'] = $cidadePiloto;
        $cfgDest['ENDERECO']['estado'] = $ufPiloto;
        if ($zapPiloto !== '') {
            $cfgDest['WHATSAPP_NUMBER'] = $zapPiloto;
            $cfgDest['REDES']['whatsapp'] = $zapPiloto;
        }
        Database::write('config.json', $cfgDest);
        Database::write('cardapio.json', $cardDest);
    } finally {
        Database::setTenant($antT);
    }

    echo json_encode([
        'success' => true,
        'slug' => $slugFinal,
        'nome_empresa' => $nomeEmp,
        'whatsapp' => $zapPiloto,
        'login' => $loginNovo,
        'senha_usada' => $senhaUsada,
        'vencimento' => $vencPiloto,
        'qtd_itens' => $qtdItens,
        'url_curta' => '/' . $slugFinal,
        'url_loja' => 'loja/' . rawurlencode($slugFinal) . '/',
        'url_admin' => 'admin.html?tenant=' . rawurlencode($slugFinal),
        'lojas' => pedidozap_resumo_lojas(),
    ], JSON_UNESCAPED_UNICODE);
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
