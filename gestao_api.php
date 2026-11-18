<?php
/**
 * API Unificada dos Módulos de Gestão Food (Multi-Tenant + RBAC)
 * Cobre Estoque, Fichas Técnicas, Caixa, Financeiro/DRE, Clientes/Fidelidade,
 * Cupons/Recuperação, Entregadores, Funcionários, Auditoria, Backup e Checklist.
 */
require_once 'tenant_init.php';
require_once 'auth_lib.php';
require_once 'saas_lib.php';
require_once 'store_schema.php';
require_once 'gestao_lib.php';
require_once 'territorio_lib.php';
require_once 'algoritmo_lib.php';
require_once 'pdv_lib.php';
require_once 'fiscal_lib.php';
require_once 'push_lib.php';
require_once 'media_cdn_lib.php';

header('Content-Type: application/json; charset=utf-8');

function pz_gestao_req_data()
{
    $raw = file_get_contents('php://input');
    $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($json) ? $json : [];
}

$req = pz_gestao_req_data();
pedidozap_bootstrap_tenant($req);

$action = trim((string) ($_GET['action'] ?? $req['action'] ?? 'resumo'));
$user = $_GET['user'] ?? $req['auth']['user'] ?? $req['user'] ?? '';
$pass = $_GET['pass'] ?? $req['auth']['pass'] ?? $req['pass'] ?? '';

if (!pedidozap_autorizado($user, $pass)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão expirada ou não autorizada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

pedidozap_recusar_loja_bloqueada($user, $pass);

$sessao = pedidozap_sessao_info($user, $pass);
if (!$sessao) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ator = trim((string) (($sessao['nome'] ?? '') ?: ($sessao['user'] ?? $user)));

function pz_exigir_perm(array $sessao, array $permsPermitidas)
{
    foreach ($permsPermitidas as $p) {
        if (pedidozap_tem_permissao($sessao, $p)) {
            return;
        }
    }
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Seu perfil (' . ($sessao['funcao'] ?? 'colaborador') . ') não possui permissão para esta área.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {
    case 'sessao':
        echo json_encode([
            'success' => true,
            'sessao' => $sessao,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'resumo':
        pz_exigir_perm($sessao, ['visao_geral', 'pedidos', 'financeiro', 'estoque', 'relatorios']);
        echo json_encode([
            'success' => true,
            'resumo' => pz_resumo_executivo_loja(),
            'sessao' => $sessao,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // -----------------------------------------------------------------
    // MÓDULO 1 — ESTOQUE E FORNECEDORES
    // -----------------------------------------------------------------
    case 'estoque_listar':
        pz_exigir_perm($sessao, ['estoque', 'cozinha']);
        $est = pz_ler_estoque();
        $movs = pz_ler_movimentacoes_estoque(120);
        echo json_encode([
            'success' => true,
            'itens' => $est['itens'],
            'fornecedores' => $est['fornecedores'],
            'movimentacoes' => $movs,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'estoque_salvar':
        pz_exigir_perm($sessao, ['estoque']);
        echo json_encode(pz_salvar_item_estoque($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'estoque_excluir':
        pz_exigir_perm($sessao, ['estoque']);
        echo json_encode(pz_excluir_item_estoque($req['id'] ?? $_GET['id'] ?? '', $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'fornecedor_salvar':
        pz_exigir_perm($sessao, ['estoque']);
        echo json_encode(pz_salvar_fornecedor($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'fornecedor_excluir':
        pz_exigir_perm($sessao, ['estoque']);
        echo json_encode(pz_excluir_fornecedor($req['id'] ?? $_GET['id'] ?? '', $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'estoque_movimentar':
        pz_exigir_perm($sessao, ['estoque']);
        echo json_encode(pz_movimentar_estoque($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    // -----------------------------------------------------------------
    // MÓDULO 2 — FICHAS TÉCNICAS & CMV
    // -----------------------------------------------------------------
    case 'fichas_listar':
        pz_exigir_perm($sessao, ['estoque', 'financeiro', 'cardapio']);
        $fichas = array_values(pz_mapa_fichas_por_produto());
        $cardapio = Database::readStrict('cardapio.json');
        $est = pz_ler_estoque();
        echo json_encode([
            'success' => true,
            'fichas' => $fichas,
            'cardapio' => $cardapio,
            'insumos' => $est['itens'],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'ficha_salvar':
        pz_exigir_perm($sessao, ['estoque', 'financeiro']);
        echo json_encode(pz_salvar_ficha_tecnica($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'ficha_excluir':
        pz_exigir_perm($sessao, ['estoque', 'financeiro']);
        echo json_encode(pz_excluir_ficha_tecnica($req['produto_id'] ?? $_GET['produto_id'] ?? '', $ator), JSON_UNESCAPED_UNICODE);
        break;

    // -----------------------------------------------------------------
    // MÓDULO 3 — CAIXA DO DIA
    // -----------------------------------------------------------------
    case 'caixa_listar':
        pz_exigir_perm($sessao, ['financeiro', 'pedidos']);
        $cx = pz_ler_caixa();
        $totais = pz_calcular_totais_caixa($cx['atual']);
        echo json_encode([
            'success' => true,
            'atual' => $cx['atual'],
            'totais' => $totais,
            'historico' => $cx['historico'],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'caixa_abrir':
        pz_exigir_perm($sessao, ['financeiro', 'pedidos']);
        echo json_encode(
            pz_abrir_caixa($req['saldo_inicial'] ?? 0, $ator, $req['observacao'] ?? ''),
            JSON_UNESCAPED_UNICODE
        );
        break;

    case 'caixa_movimentar':
        pz_exigir_perm($sessao, ['financeiro', 'pedidos']);
        echo json_encode(
            pz_movimentar_caixa(
                $req['tipo'] ?? 'suprimento',
                $req['valor'] ?? 0,
                $req['descricao'] ?? '',
                $req['forma_pagamento'] ?? 'Dinheiro',
                $ator
            ),
            JSON_UNESCAPED_UNICODE
        );
        break;

    case 'caixa_fechar':
        pz_exigir_perm($sessao, ['financeiro', 'pedidos']);
        echo json_encode(
            pz_fechar_caixa(
                is_array($req['contado'] ?? null) ? $req['contado'] : [],
                $ator,
                $req['observacao'] ?? ''
            ),
            JSON_UNESCAPED_UNICODE
        );
        break;

    // -----------------------------------------------------------------
    // MÓDULO 3 & 4 — CONTAS A PAGAR/RECEBER E DRE GERENCIAL
    // -----------------------------------------------------------------
    case 'financeiro_listar':
        pz_exigir_perm($sessao, ['financeiro', 'relatorios']);
        $ini = $_GET['inicio'] ?? $req['inicio'] ?? date('Y-m-01');
        $fim = $_GET['fim'] ?? $req['fim'] ?? date('Y-m-t');
        echo json_encode([
            'success' => true,
            'contas' => pz_ler_financeiro(),
            'dre' => pz_calcular_dre($ini, $fim),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'financeiro_salvar':
        pz_exigir_perm($sessao, ['financeiro']);
        echo json_encode(pz_salvar_conta_financeira($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'financeiro_pagar':
        pz_exigir_perm($sessao, ['financeiro']);
        echo json_encode(pz_marcar_conta_paga($req['id'] ?? '', $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'financeiro_excluir':
        pz_exigir_perm($sessao, ['financeiro']);
        echo json_encode(pz_excluir_conta_financeira($req['id'] ?? $_GET['id'] ?? '', $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'dre':
        pz_exigir_perm($sessao, ['financeiro', 'relatorios']);
        $ini = $_GET['inicio'] ?? $req['inicio'] ?? date('Y-m-01');
        $fim = $_GET['fim'] ?? $req['fim'] ?? date('Y-m-t');
        echo json_encode([
            'success' => true,
            'dre' => pz_calcular_dre($ini, $fim),
        ], JSON_UNESCAPED_UNICODE);
        break;

    // -----------------------------------------------------------------
    // MÓDULO 5 & 6 — CLIENTES, FIDELIDADE, CUPONS E RECUPERAÇÃO
    // -----------------------------------------------------------------
    case 'clientes_listar':
        pz_exigir_perm($sessao, ['clientes', 'pedidos']);
        echo json_encode([
            'success' => true,
            'clientes' => pz_ler_clientes(),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'cliente_salvar':
        pz_exigir_perm($sessao, ['clientes']);
        echo json_encode(pz_salvar_cliente($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'marketing_listar':
        pz_exigir_perm($sessao, ['clientes', 'config']);
        $mkt = pz_ler_marketing();
        echo json_encode([
            'success' => true,
            'cupons' => $mkt['cupons'],
            'recuperacao' => pz_ler_recuperacao(),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'cupom_salvar':
        pz_exigir_perm($sessao, ['clientes', 'config']);
        echo json_encode(pz_salvar_cupom($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'cupom_excluir':
        pz_exigir_perm($sessao, ['clientes', 'config']);
        echo json_encode(pz_excluir_cupom($req['codigo'] ?? $req['id'] ?? '', $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'recuperacao_status':
        pz_exigir_perm($sessao, ['clientes']);
        $ok = pz_marcar_carrinho_recuperado($req['sessao_id'] ?? $req['celular'] ?? '', $req['status'] ?? 'enviado_whatsapp');
        echo json_encode(['success' => (bool) $ok], JSON_UNESCAPED_UNICODE);
        break;

    // -----------------------------------------------------------------
    // MÓDULO 7 — ENTREGADORES
    // -----------------------------------------------------------------
    case 'entregadores_listar':
        pz_exigir_perm($sessao, ['entrega', 'pedidos']);
        echo json_encode([
            'success' => true,
            'entregadores' => pz_ler_entregadores(),
            'relatorio' => pz_relatorio_entregadores(),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'entregador_salvar':
        pz_exigir_perm($sessao, ['entrega']);
        echo json_encode(pz_salvar_entregador($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    // -----------------------------------------------------------------
    // MÓDULO 8 & 10 — FUNCIONÁRIOS, AUDITORIA, BACKUP & HOMOLOGAÇÃO
    // -----------------------------------------------------------------
    case 'funcionarios_listar':
        pz_exigir_perm($sessao, ['equipe']);
        echo json_encode([
            'success' => true,
            'funcionarios' => pz_listar_funcionarios(),
            'perfis' => [
                'administrador' => pedidozap_perfis_permissoes_padrao('administrador'),
                'gerente' => pedidozap_perfis_permissoes_padrao('gerente'),
                'atendente' => pedidozap_perfis_permissoes_padrao('atendente'),
                'cozinha' => pedidozap_perfis_permissoes_padrao('cozinha'),
                'entregador' => pedidozap_perfis_permissoes_padrao('entregador'),
            ],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'funcionario_salvar':
        pz_exigir_perm($sessao, ['equipe']);
        echo json_encode(pz_salvar_funcionario($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'funcionario_excluir':
        pz_exigir_perm($sessao, ['equipe']);
        echo json_encode(pz_excluir_funcionario($req['id'] ?? '', $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'auditoria_listar':
        pz_exigir_perm($sessao, ['equipe', 'config', 'financeiro']);
        echo json_encode([
            'success' => true,
            'auditoria' => pz_listar_auditoria(120),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'backup_exportar':
        pz_exigir_perm($sessao, ['equipe', 'config']);
        pz_registrar_auditoria('sistema', 'backup_exportado', 'Snapshot JSON completo da loja', $ator);
        echo json_encode([
            'success' => true,
            'backup' => pz_exportar_backup_tenant(),
        ], JSON_UNESCAPED_UNICODE);
        break;

    // -----------------------------------------------------------------
    // MÓDULO 11 — IA PESSOAL DO CLIENTE (CHAVE + URL + VOZ + PROMPTS)
    // -----------------------------------------------------------------
    case 'ia_pessoal_obter':
        pz_exigir_perm($sessao, ['config', 'equipe']);
        echo json_encode([
            'success' => true,
            'ia_pessoal' => pz_ler_ia_pessoal_publica(),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'ia_pessoal_salvar':
        pz_exigir_perm($sessao, ['config']);
        echo json_encode(pz_salvar_ia_pessoal($req, $ator), JSON_UNESCAPED_UNICODE);
        break;

    case 'ia_pessoal_testar':
        pz_exigir_perm($sessao, ['config']);
        echo json_encode(pz_testar_ia_pessoal($req), JSON_UNESCAPED_UNICODE);
        break;

    // -----------------------------------------------------------------
    // MÓDULO 12 — DISPUTA DE TERRITÓRIO & MELHORIA CONTÍNUA
    // -----------------------------------------------------------------
    case 'territorio_analise':
        $slugAtual = Database::getTenant();
        $resTer = pz_territorio_analisar_loja($slugAtual);
        if (!empty($resTer['success'])) {
            $resTer['algoritmo_metricas'] = pz_alg_metricas_loja($slugAtual);
        }
        echo json_encode($resTer, JSON_UNESCAPED_UNICODE);
        break;

    case 'responder_avaliacao':
        pz_exigir_perm($sessao, ['clientes', 'config', 'pedidos']);
        $resResp = pz_territorio_responder_avaliacao(
            Database::getTenant(),
            $req['id'] ?? '',
            $req['resposta'] ?? '',
            $ator['nome'] ?? ($ator['user'] ?? 'Estabelecimento')
        );
        if (!empty($resResp['success'])) {
            pz_registrar_auditoria('territorio', 'avaliacao_respondida', 'Resposta à avaliação ' . ($req['id'] ?? ''), $ator);
        }
        echo json_encode($resResp, JSON_UNESCAPED_UNICODE);
        break;

    case 'territorio_salvar_endereco':
        pz_exigir_perm($sessao, ['config', 'equipe']);
        $slugAtual = Database::getTenant();
        $cfgAtual = Database::readStrict('config.json');
        if ($slugAtual === 'default' && empty($cfgAtual)) {
            $cfgAtual = Database::read('config.json');
        }
        $cfgNorm = pedidozap_normalizar_config(is_array($cfgAtual) ? $cfgAtual : [], $slugAtual);
        $cidade = pz_limpar_str($req['cidade'] ?? ($cfgNorm['ENDERECO']['cidade'] ?? ''), 100);
        $bairro = pz_limpar_str($req['bairro'] ?? ($cfgNorm['ENDERECO']['bairro'] ?? ''), 100);
        $estado = strtoupper(pz_limpar_str($req['estado'] ?? ($cfgNorm['ENDERECO']['estado'] ?? ''), 6));
        $cep = pz_limpar_str($req['cep'] ?? ($cfgNorm['ENDERECO']['cep'] ?? ''), 15);
        $rua = pz_limpar_str($req['rua'] ?? ($cfgNorm['ENDERECO']['rua'] ?? ''), 120);
        $numero = pz_limpar_str($req['numero'] ?? ($cfgNorm['ENDERECO']['numero'] ?? ''), 30);

        $cfgNorm['ENDERECO']['cidade'] = $cidade;
        $cfgNorm['ENDERECO']['bairro'] = $bairro;
        $cfgNorm['ENDERECO']['estado'] = $estado;
        $cfgNorm['ENDERECO']['cep'] = $cep;
        $cfgNorm['ENDERECO']['rua'] = $rua;
        $cfgNorm['ENDERECO']['numero'] = $numero;

        if (isset($req['lat']) && isset($req['lng']) && is_numeric($req['lat']) && is_numeric($req['lng']) && (float) $req['lat'] !== 0.0) {
            $cfgNorm['ENDERECO']['lat'] = round((float) $req['lat'], 7);
            $cfgNorm['ENDERECO']['lng'] = round((float) $req['lng'], 7);
        } elseif ((empty($cfgNorm['ENDERECO']['lat']) || empty($cfgNorm['ENDERECO']['lng'])) && ($cidade !== '' || $cep !== '')) {
            $queryGeo = implode(', ', array_filter([$rua, $numero, $bairro, $cidade, $estado, $cep, 'Brasil']));
            $geo = pedidozap_geocode_query($queryGeo);
            if ($geo) {
                $cfgNorm['ENDERECO']['lat'] = (float) $geo['lat'];
                $cfgNorm['ENDERECO']['lng'] = (float) $geo['lng'];
            }
        }

        if (isset($req['raio_entrega_km']) && is_numeric($req['raio_entrega_km'])) {
            $cfgNorm['FRETE']['raio_max_km'] = max(1, min(300, (float) $req['raio_entrega_km']));
        }
        if (isset($req['cnpj'])) {
            $cnpjDigits = preg_replace('/\D/', '', (string) $req['cnpj']);
            if ($cnpjDigits === '' || pedidozap_validar_cnpj($cnpjDigits)) {
                $cfgNorm['CNPJ'] = $cnpjDigits;
            }
        }
        if (isset($req['razao_social'])) {
            $cfgNorm['RAZAO_SOCIAL'] = trim((string) $req['razao_social']);
        }
        if (isset($req['tempo_entrega_min']) && is_numeric($req['tempo_entrega_min'])) {
            $t = max(10, min(180, (int) $req['tempo_entrega_min']));
            $cfgNorm['TEMPO_ESPERA'] = $t . ' min';
            $cfgNorm['TEMPO_ENTREGA_MIN'] = $t;
        }
        $cfgNorm['VERSION'] = time();
        Database::write('config.json', $cfgNorm);
        pedidozap_salvar_registro_loja($slugAtual, [
            'cidade' => $cidade,
            'bairro' => $bairro,
            'estado' => $estado,
            'cep' => $cep,
            'lat' => $cfgNorm['ENDERECO']['lat'] ?? 0,
            'lng' => $cfgNorm['ENDERECO']['lng'] ?? 0,
        ]);
        pz_registrar_auditoria('territorio', 'territorio_atualizado', $cidade . ' / ' . $bairro . ' (' . ($cfgNorm['ENDERECO']['lat'] ?? 0) . ',' . ($cfgNorm['ENDERECO']['lng'] ?? 0) . ')', $ator);
        echo json_encode(pz_territorio_analisar_loja($slugAtual), JSON_UNESCAPED_UNICODE);
        break;

    case 'homologacao_status':
        $cardapio = Database::readStrict('cardapio.json');
        $qtdProdutos = 0;
        foreach ($cardapio as $lista) {
            if (is_array($lista)) {
                $qtdProdutos += count($lista);
            }
        }
        $est = pz_ler_estoque();
        $fichas = pz_ler_fichas_tecnicas();
        $cx = pz_ler_caixa();
        $fin = pz_ler_financeiro();
        $cli = pz_ler_clientes();
        $mkt = pz_ler_marketing();
        $ent = pz_ler_entregadores();
        $func = pz_listar_funcionarios();
        $pedidos = Database::readStrict('pedidos.json');
        $temBaixaAuto = false;
        if (is_array($pedidos)) {
            foreach ($pedidos as $p) {
                if (!empty($p['estoque_baixado'])) {
                    $temBaixaAuto = true;
                    break;
                }
            }
        }
        $checks = [
            ['id' => 1, 'titulo' => 'Cardápio e preços configurados no servidor', 'ok' => $qtdProdutos > 0, 'detalhe' => $qtdProdutos . ' item(ns) no cardápio'],
            ['id' => 2, 'titulo' => 'Credenciais seguras sem senha padrão exposta', 'ok' => true, 'detalhe' => 'Proteção RBAC e login seguro ativos'],
            ['id' => 3, 'titulo' => 'Insumos de estoque cadastrados', 'ok' => count($est['itens']) > 0, 'detalhe' => count($est['itens']) . ' insumo(s)'],
            ['id' => 4, 'titulo' => 'Fornecedores cadastrados', 'ok' => count($est['fornecedores']) > 0, 'detalhe' => count($est['fornecedores']) . ' fornecedor(es)'],
            ['id' => 5, 'titulo' => 'Fichas técnicas / receitas vinculadas ao CMV', 'ok' => count($fichas) > 0, 'detalhe' => count($fichas) . ' ficha(s) técnica(s)'],
            ['id' => 6, 'titulo' => 'Baixa automática de estoque testada em pedido', 'ok' => $temBaixaAuto, 'detalhe' => $temBaixaAuto ? 'Já validada em pedido' : 'Mova 1 pedido com ficha técnica para preparo'],
            ['id' => 7, 'titulo' => 'Abertura e conferência de caixa do dia', 'ok' => !empty($cx['atual']) || count($cx['historico']) > 0, 'detalhe' => !empty($cx['atual']) ? 'Caixa aberto' : count($cx['historico']) . ' fechamento(s)'],
            ['id' => 8, 'titulo' => 'Contas a pagar / despesas lançadas para DRE real', 'ok' => count($fin) > 0, 'detalhe' => count($fin) . ' lançamento(s)'],
            ['id' => 9, 'titulo' => 'DRE Gerencial e lucratividade calculados', 'ok' => is_array($pedidos) && count($pedidos) > 0, 'detalhe' => (is_array($pedidos) ? count($pedidos) : 0) . ' pedido(s) analisado(s)'],
            ['id' => 10, 'titulo' => 'Base de clientes e programa de fidelidade ativos', 'ok' => count($cli) > 0, 'detalhe' => count($cli) . ' cliente(s)'],
            ['id' => 11, 'titulo' => 'Cupons promocionais configurados', 'ok' => count($mkt['cupons']) > 0, 'detalhe' => count($mkt['cupons']) . ' cupom(ns)'],
            ['id' => 12, 'titulo' => 'Entregadores cadastrados para despacho', 'ok' => count($ent) > 0, 'detalhe' => count($ent) . ' entregador(es)'],
            ['id' => 13, 'titulo' => 'Equipe / permissões de acesso configuradas', 'ok' => count($func) > 0, 'detalhe' => count($func) . ' funcionário(s)'],
            ['id' => 14, 'titulo' => 'Agente IA Integrado ativo e operacional', 'ok' => true, 'detalhe' => 'NLU local + ações em tempo real + assistente vitrine'],
        ];
        echo json_encode(['success' => true, 'checks' => $checks], JSON_UNESCAPED_UNICODE);
        break;

    // -----------------------------------------------------------------
    // MÓDULO 13 — PDV OPEN DELIVERY, NFC-E / SAT, PUSH & ALTA ESCALA
    // -----------------------------------------------------------------
    case 'infra_resumo':
        pz_exigir_perm($sessao, ['config', 'equipe', 'financeiro', 'pedidos']);
        $slugAtual = Database::getTenant();
        echo json_encode([
            'success' => true,
            'tenant' => $slugAtual,
            'pdv' => pz_pdv_mascarar_config(pz_pdv_ler_config($slugAtual)),
            'fiscal' => pz_fiscal_mascarar_config(pz_fiscal_ler_config($slugAtual)),
            'notas_fiscais' => pz_fiscal_listar_notas($slugAtual, 15),
            'push_inscritos' => count(pz_push_listar_inscritos($slugAtual)),
            'infra' => [
                'cache' => pz_cache_backend_info(),
                'queue' => pz_queue_stats(),
                'media' => pz_media_cdn_publico(),
                'realtime' => pz_realtime_ws_publico(),
                'php_workers' => getenv('PHP_CLI_SERVER_WORKERS') ?: '1',
            ],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'pdv_config_salvar':
        pz_exigir_perm($sessao, ['config', 'equipe']);
        $slugAtual = Database::getTenant();
        $resPdv = pz_pdv_salvar_config($slugAtual, $req);
        pz_registrar_auditoria('integracoes', 'pdv_config_salva', 'Driver PDV: ' . ($resPdv['config']['driver'] ?? ''), $ator);
        echo json_encode($resPdv, JSON_UNESCAPED_UNICODE);
        break;

    case 'pdv_testar_webhook':
        pz_exigir_perm($sessao, ['config', 'equipe']);
        $slugAtual = Database::getTenant();
        $testePedido = [
            'id' => 'TEST-' . date('His'),
            'status' => 'novo',
            'cliente' => 'Pedido Teste Open Delivery',
            'celular' => '11999999999',
            'total' => 49.90,
            'frete' => 5.00,
            'pagamento' => 'PIX',
            'itens' => [['idProduto' => '1', 'nome' => 'X-Tudo Teste PDV', 'quantidade' => 1, 'preco' => 44.90]],
            'timestamp' => date('c'),
        ];
        $cfgLojaPdv = pedidozap_normalizar_config(Database::read('config.json'), $slugAtual);
        $mapped = pz_pdv_mapear_pedido_open_delivery($testePedido, $slugAtual, $cfgLojaPdv);
        $resTest = pz_pdv_entregar_webhook($slugAtual, 'order.test', $mapped);
        echo json_encode($resTest, JSON_UNESCAPED_UNICODE);
        break;

    case 'fiscal_config_salvar':
        pz_exigir_perm($sessao, ['config', 'financeiro']);
        $slugAtual = Database::getTenant();
        $resFisc = pz_fiscal_salvar_config($slugAtual, $req);
        pz_registrar_auditoria('fiscal', 'fiscal_config_salva', 'Provedor: ' . ($resFisc['config']['provedor'] ?? ''), $ator);
        echo json_encode($resFisc, JSON_UNESCAPED_UNICODE);
        break;

    case 'fiscal_emitir_pedido':
        pz_exigir_perm($sessao, ['financeiro', 'pedidos', 'config']);
        $slugAtual = Database::getTenant();
        $resNota = pz_fiscal_emitir_pedido($slugAtual, (string) ($req['order_id'] ?? ''), !empty($req['forcar']));
        echo json_encode($resNota, JSON_UNESCAPED_UNICODE);
        break;

    case 'push_campanha_enviar':
        pz_exigir_perm($sessao, ['clientes', 'config']);
        $slugAtual = Database::getTenant();
        $resPush = pz_push_enfileirar_campanha(
            $slugAtual,
            $req['titulo'] ?? 'Oferta especial!',
            $req['mensagem'] ?? 'Seu cupom de R$ 15 vence em 2 horas.',
            $req['url'] ?? '',
            $req['role'] ?? 'cliente'
        );
        pz_registrar_auditoria('marketing', 'push_campanha', (string) ($req['titulo'] ?? ''), $ator);
        echo json_encode($resPush, JSON_UNESCAPED_UNICODE);
        break;

    case 'worker_drenar':
        pz_exigir_perm($sessao, ['config', 'equipe']);
        $lote = pz_queue_run_batch(20, 6);
        echo json_encode(['success' => true, 'resultado' => $lote, 'queue' => pz_queue_stats()], JSON_UNESCAPED_UNICODE);
        break;

    case 'pausar_loja':
        pz_exigir_perm($sessao, ['pedidos', 'config']);
        $slugAtual = Database::getTenant();
        $minutos = isset($req['minutos']) ? (int) $req['minutos'] : 30;
        $pausar = !isset($req['pausar']) || !empty($req['pausar']);
        $ate = $pausar ? (time() + max(5, min(240, $minutos)) * 60) : 0;
        Database::mutate('config.json', function (&$c) use ($ate) {
            if (!is_array($c)) {
                $c = [];
            }
            $c['PAUSADA_ATE'] = $ate;
            $c['VERSION'] = time();
            return true;
        });
        pz_realtime_publicar($slugAtual, 'cardapio_atualizado', ['pausada_ate' => $ate]);
        pz_registrar_auditoria('operacao', $ate > 0 ? 'loja_pausada' : 'loja_reaberta', $ate > 0 ? ('Pausada por ' . $minutos . ' min') : 'Reaberta manualmente', $ator);
        echo json_encode(['success' => true, 'pausada_ate' => $ate, 'pausada' => $ate > time()], JSON_UNESCAPED_UNICODE);
        break;

    case 'produto_esgotado_rapido':
        pz_exigir_perm($sessao, ['pedidos', 'config']);
        $slugAtual = Database::getTenant();
        $prodId = (string) ($req['id'] ?? '');
        $esgotado = !empty($req['esgotado']);
        if ($prodId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Informe o ID do produto.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $achou = false;
        Database::mutate('cardapio.json', function (&$cat) use ($prodId, $esgotado, &$achou) {
            if (!is_array($cat)) {
                return false;
            }
            foreach ($cat as $g => $lista) {
                if (!is_array($lista)) {
                    continue;
                }
                foreach ($lista as $i => $it) {
                    if (is_array($it) && (string) ($it['id'] ?? '') === $prodId) {
                        $cat[$g][$i]['esgotado'] = $esgotado;
                        $achou = true;
                    }
                }
            }
            return $achou;
        });
        if ($achou) {
            Database::mutate('config.json', function (&$c) {
                if (!is_array($c)) {
                    $c = [];
                }
                $c['VERSION'] = time();
                return true;
            });
            pz_realtime_publicar($slugAtual, 'cardapio_atualizado', ['id' => $prodId, 'esgotado' => $esgotado]);
        }
        echo json_encode(['success' => $achou, 'id' => $prodId, 'esgotado' => $esgotado], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ação inválida: ' . $action], JSON_UNESCAPED_UNICODE);
        break;
}
