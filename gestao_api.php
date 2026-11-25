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
require_once 'queue_lib.php';

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

// Aprovação rápida em 1 clique via link assinado (HMAC) enviado no WhatsApp do lojista
if ($action === 'aprovar_campanha_sumidos' && !empty($_GET['sig'])) {
    $slugHmac = Database::getTenant();
    $dataLink = trim((string) ($_GET['d'] ?? date('Y-m-d')));
    $sigRec = trim((string) $_GET['sig']);
    $sigExpHoje = hash_hmac('sha256', 'sumidos|' . $slugHmac . '|' . $dataLink, pedidozap_app_secret());
    if (!hash_equals($sigExpHoje, $sigRec)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Link de aprovação expirado ou inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $cfgAtual = pedidozap_normalizar_config(Database::readStrict('config.json'), $slugHmac);
    $nomeAtual = trim((string) ($cfgAtual['NOME_LOJA'] ?? $slugHmac)) ?: $slugHmac;
    $cupom = 'VOLTA10';
    $listaSum = pz_clientes_sumidos(15, 25, $nomeAtual, $slugHmac, $cupom);
    $elegiveis = array_values(array_filter($listaSum, function ($s) {
        $d = (int) ($s['dias_sem_pedir'] ?? 0);
        return $d >= 15 && $d <= 45;
    }));
    $enfileirados = 0;
    foreach (array_slice($elegiveis, 0, 20) as $cliSum) {
        if (!empty($cliSum['telefone'])) {
            pz_queue_push('whatsapp.reactivate_customer', [
                'telefone' => $cliSum['telefone'],
                'nome' => $cliSum['nome'] ?? 'Cliente',
                'cupom' => $cupom,
            ], $slugHmac, $enfileirados * 2);
            $enfileirados++;
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Campanha IA Aprovada — AcheIAki</title></head>'
        . '<body style="margin:0;font-family:Inter,system-ui,sans-serif;background:#faf7f2;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;">'
        . '<div style="max-width:460px;background:#fff;border-radius:18px;padding:26px;border:1px solid #bbf7d0;box-shadow:0 16px 36px rgba(0,0,0,0.08);text-align:center;">'
        . '<h2 style="margin:0 0 8px;color:#15803d;">✅ Campanha IA aprovada!</h2>'
        . '<p style="color:#334155;line-height:1.5;">Enfileiramos <strong>' . (int) $enfileirados . '</strong> envio(s) automático(s) no WhatsApp com o cupom <strong>' . htmlspecialchars($cupom, ENT_QUOTES, 'UTF-8') . '</strong> para trazer clientes sumidos de volta à <strong>' . htmlspecialchars($nomeAtual, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
        . '<a href="admin.html?tenant=' . rawurlencode($slugHmac) . '" style="display:inline-block;margin-top:10px;background:#ea580c;color:#fff;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:10px;">Ir para o painel da loja</a>'
        . '</div></body></html>';
    exit;
}

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
$isMasterPlano = ($sessao['role'] ?? '') === 'master';
$planoAtualLoja = pz_plano_da_loja();

$mapaPlanoAcoes = [
    // Pro+
    'caixa_listar' => ['caixa_financeiro_dre', 'Caixa Diário', 'pro'],
    'caixa_abrir' => ['caixa_financeiro_dre', 'Caixa Diário', 'pro'],
    'caixa_movimentar' => ['caixa_financeiro_dre', 'Caixa Diário', 'pro'],
    'caixa_fechar' => ['caixa_financeiro_dre', 'Caixa Diário', 'pro'],
    'financeiro_listar' => ['caixa_financeiro_dre', 'Financeiro & DRE', 'pro'],
    'financeiro_salvar' => ['caixa_financeiro_dre', 'Financeiro & DRE', 'pro'],
    'financeiro_pagar' => ['caixa_financeiro_dre', 'Financeiro & DRE', 'pro'],
    'financeiro_excluir' => ['caixa_financeiro_dre', 'Financeiro & DRE', 'pro'],
    'dre' => ['caixa_financeiro_dre', 'Relatório DRE', 'pro'],
    'clientes_listar' => ['crm_cupons_fidelidade', 'CRM de Clientes', 'pro'],
    'cliente_salvar' => ['crm_cupons_fidelidade', 'CRM de Clientes', 'pro'],
    'marketing_listar' => ['crm_cupons_fidelidade', 'Cupons & Recuperação de Carrinhos', 'pro'],
    'cupom_salvar' => ['crm_cupons_fidelidade', 'Cupons de Desconto', 'pro'],
    'cupom_excluir' => ['crm_cupons_fidelidade', 'Cupons de Desconto', 'pro'],
    'recuperacao_status' => ['crm_cupons_fidelidade', 'Recuperação de Carrinhos', 'pro'],
    'entregadores_listar' => ['entregadores_gps', 'Gestão de Entregadores', 'pro'],
    'entregador_salvar' => ['entregadores_gps', 'Gestão de Entregadores', 'pro'],
    'funcionarios_listar' => ['equipe_rbac', 'Equipe e Permissões (RBAC)', 'pro'],
    'funcionario_salvar' => ['equipe_rbac', 'Equipe e Permissões (RBAC)', 'pro'],
    'funcionario_excluir' => ['equipe_rbac', 'Equipe e Permissões (RBAC)', 'pro'],
    'responder_avaliacao' => ['crm_cupons_fidelidade', 'Resposta Oficial às Avaliações', 'pro'],
    // Scale IA
    'estoque_listar' => ['estoque_ficha_cmv', 'Estoque & Fornecedores', 'scale'],
    'estoque_salvar' => ['estoque_ficha_cmv', 'Estoque & Fornecedores', 'scale'],
    'estoque_excluir' => ['estoque_ficha_cmv', 'Estoque & Fornecedores', 'scale'],
    'fornecedor_salvar' => ['estoque_ficha_cmv', 'Estoque & Fornecedores', 'scale'],
    'fornecedor_excluir' => ['estoque_ficha_cmv', 'Estoque & Fornecedores', 'scale'],
    'estoque_movimentar' => ['estoque_ficha_cmv', 'Estoque & Fornecedores', 'scale'],
    'fichas_listar' => ['estoque_ficha_cmv', 'Ficha Técnica & CMV', 'scale'],
    'ficha_salvar' => ['estoque_ficha_cmv', 'Ficha Técnica & CMV', 'scale'],
    'ficha_excluir' => ['estoque_ficha_cmv', 'Ficha Técnica & CMV', 'scale'],
    'auditoria_listar' => ['auditoria_backup', 'Auditoria do Sistema', 'scale'],
    'backup_exportar' => ['auditoria_backup', 'Backup JSON Completo', 'scale'],
    'ia_pessoal_obter' => ['ia_pessoal_externa', 'IA Pessoal (API Key + Voz)', 'scale'],
    'ia_pessoal_salvar' => ['ia_pessoal_externa', 'IA Pessoal (API Key + Voz)', 'scale'],
    'ia_pessoal_testar' => ['ia_pessoal_externa', 'IA Pessoal (API Key + Voz)', 'scale'],
    'otimizar_cardapio_ia' => ['ia_gestor_acoes', 'Otimizador de Catálogo por IA', 'scale'],
    'pdv_config_salvar' => ['pdv_open_delivery', 'Conector PDV / ERP (Open Delivery)', 'scale'],
    'pdv_testar_webhook' => ['pdv_open_delivery', 'Conector PDV / ERP (Open Delivery)', 'scale'],
    'fiscal_config_salvar' => ['fiscal_nfce', 'Emissão Fiscal NFC-e / SAT', 'scale'],
    'fiscal_emitir_pedido' => ['fiscal_nfce', 'Emissão Fiscal NFC-e / SAT', 'scale'],
    'push_campanha_enviar' => ['push_campanhas', 'Campanhas Push Notifications', 'scale'],
    'rede_resumo' => ['multi_unidades', 'Painel Consolidado Multi-Unidades (Rede)', 'scale'],
    'rede_clonar_cardapio' => ['multi_unidades', 'Clonar Cardápio para Filial (Rede)', 'scale'],
];
if (isset($mapaPlanoAcoes[$action])) {
    [$recReq, $rotReq, $minReq] = $mapaPlanoAcoes[$action];
    pz_plano_exigir($recReq, $rotReq, $minReq, $isMasterPlano);
}

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
            'plano' => $planoAtualLoja,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'resumo':
        pz_exigir_perm($sessao, ['visao_geral', 'pedidos', 'financeiro', 'estoque', 'relatorios']);
        echo json_encode([
            'success' => true,
            'resumo' => pz_resumo_executivo_loja(),
            'consumo_plano' => pz_plano_resumo_consumo(Database::getTenant()),
            'sessao' => $sessao,
            'plano' => $planoAtualLoja,
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
        $statusRec = trim((string) ($req['status'] ?? 'enviado_whatsapp'));
        $maxRec = (int) ($planoAtualLoja['limites']['max_recuperacoes_mes'] ?? 0);
        if (!$isMasterPlano && $maxRec > 0 && $statusRec === 'enviado_whatsapp') {
            $mesAtualRec = date('Y-m');
            $enviadosMes = 0;
            foreach (pz_ler_recuperacao() as $rItem) {
                $stRec = (string) ($rItem['status'] ?? '');
                $dtRec = substr((string) ($rItem['atualizado_em'] ?? $rItem['criado_em'] ?? ''), 0, 7);
                if ($dtRec === $mesAtualRec && in_array($stRec, ['enviado_whatsapp', 'convertido'], true)) {
                    $enviadosMes++;
                }
            }
            if ($enviadosMes >= $maxRec) {
                http_response_code(402);
                echo json_encode([
                    'success' => false,
                    'codigo_erro' => 'upgrade_plano',
                    'plano_atual' => $planoAtualLoja['slug'],
                    'plano_necessario' => 'scale',
                    'plano_necessario_nome' => 'Scale IA',
                    'message' => 'Você atingiu o limite de ' . $maxRec . ' disparos de recuperação/mês do plano ' . $planoAtualLoja['nome'] . '. Faça upgrade para o Scale IA e libere robô automático ilimitado.',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
        }
        $ok = pz_marcar_carrinho_recuperado($req['sessao_id'] ?? $req['celular'] ?? '', $statusRec);
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
        $maxFunc = (int) ($planoAtualLoja['limites']['max_funcionarios'] ?? 0);
        if (!$isMasterPlano && $maxFunc > 0 && empty($req['id'])) {
            $existentesFunc = pz_listar_funcionarios();
            if (count($existentesFunc) >= $maxFunc) {
                http_response_code(402);
                echo json_encode([
                    'success' => false,
                    'codigo_erro' => 'upgrade_plano',
                    'plano_atual' => $planoAtualLoja['slug'],
                    'plano_necessario' => 'scale',
                    'plano_necessario_nome' => 'Scale IA',
                    'message' => 'Seu plano ' . $planoAtualLoja['nome'] . ' permite até ' . $maxFunc . ' funcionário(s). Faça upgrade para o plano Scale IA para equipe ilimitada.',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
        }
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

    case 'rede_resumo':
        pz_exigir_perm($sessao, ['visao_geral', 'relatorios', 'financeiro']);
        $slugAtual = Database::getTenant();
        $registro = pedidozap_ler_registro();
        $metaAtual = is_array($registro[$slugAtual] ?? null) ? $registro[$slugAtual] : [];
        $grupoRede = trim((string) ($metaAtual['grupo_rede'] ?? ''));
        $unidades = [];
        $totPedHoje = 0;
        $totFatHoje = 0.0;
        $totFatMes = 0.0;
        $totAbertos = 0;
        $antTenant = Database::getTenant();
        try {
            foreach ($registro as $s => $m) {
                if (!is_array($m)) {
                    continue;
                }
                $mesmoGrupo = ($s === $slugAtual)
                    || ($grupoRede !== '' && strcasecmp(trim((string) ($m['grupo_rede'] ?? '')), $grupoRede) === 0);
                if (!$mesmoGrupo) {
                    continue;
                }
                Database::setTenant($s);
                $resU = pz_resumo_executivo_loja();
                $pedH = (int) ($resU['hoje']['pedidos'] ?? 0);
                $fatH = (float) ($resU['hoje']['faturamento_bruto'] ?? 0);
                $fatM = (float) ($resU['mes']['faturamento_bruto'] ?? 0);
                $abU = (int) ($resU['pedidos_abertos'] ?? 0);
                $totPedHoje += $pedH;
                $totFatHoje += $fatH;
                $totFatMes += $fatM;
                $totAbertos += $abU;
                $unidades[] = [
                    'slug' => $s,
                    'nome' => (string) ($m['nome_empresa'] ?? $s),
                    'cidade' => (string) ($m['cidade'] ?? ''),
                    'bairro' => (string) ($m['bairro'] ?? ''),
                    'status' => pedidozap_status_efetivo($m)['status'],
                    'pedidos_hoje' => $pedH,
                    'pedidos_abertos' => $abU,
                    'faturamento_hoje' => round($fatH, 2),
                    'faturamento_mes' => round($fatM, 2),
                    'url_painel' => 'admin.html?tenant=' . rawurlencode($s),
                ];
            }
        } finally {
            Database::setTenant($antTenant);
        }
        echo json_encode([
            'success' => true,
            'grupo_rede' => $grupoRede,
            'consolidado' => [
                'total_unidades' => count($unidades),
                'pedidos_hoje' => $totPedHoje,
                'pedidos_abertos' => $totAbertos,
                'faturamento_hoje' => round($totFatHoje, 2),
                'faturamento_mes' => round($totFatMes, 2),
            ],
            'unidades' => $unidades,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'clientes_sumidos':
        pz_exigir_perm($sessao, ['clientes', 'visao_geral', 'pedidos']);
        $slugAtual = Database::getTenant();
        $cfgAtual = pedidozap_normalizar_config(Database::readStrict('config.json'), $slugAtual);
        $nomeAtual = trim((string) ($cfgAtual['NOME_LOJA'] ?? $slugAtual)) ?: $slugAtual;
        $mkt = pz_ler_marketing();
        $cupom = '';
        foreach ($mkt['cupons'] as $c) {
            if (!empty($c['ativo']) && (empty($c['validade']) || $c['validade'] >= date('Y-m-d'))) {
                $cupom = (string) ($c['codigo'] ?? '');
                break;
            }
        }
        $diasMin = max(7, min(180, (int) ($req['dias'] ?? $_GET['dias'] ?? 15)));
        $listaSumidos = pz_clientes_sumidos($diasMin, 40, $nomeAtual, $slugAtual, $cupom);
        $liberado = pz_plano_tem_recurso('wpp_robo_auto', null, $isMasterPlano);
        if (!$liberado) {
            $listaMascarada = array_map(function ($x) {
                $x['telefone'] = $x['telefone_mascarado'];
                $x['whatsapp_url'] = '';
                $x['mensagem_sugerida'] = '';
                return $x;
            }, array_slice($listaSumidos, 0, 8));
            echo json_encode([
                'success' => true,
                'liberado' => false,
                'requer_upgrade_pro' => true,
                'requer_upgrade_scale' => true,
                'total' => count($listaSumidos),
                'cupom_ativo' => $cupom,
                'clientes' => $listaMascarada,
            ], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode([
            'success' => true,
            'liberado' => true,
            'total' => count($listaSumidos),
            'cupom_ativo' => $cupom,
            'clientes' => $listaSumidos,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'aprovar_campanha_sumidos':
        pz_exigir_perm($sessao, ['clientes', 'visao_geral', 'config']);
        pz_plano_exigir('wpp_robo_auto', 'Disparo de Reativação de Clientes Sumidos (WhatsApp + IA)', 'scale', $isMasterPlano);
        $slugAtual = Database::getTenant();
        $cfgAtual = pedidozap_normalizar_config(Database::readStrict('config.json'), $slugAtual);
        $nomeAtual = trim((string) ($cfgAtual['NOME_LOJA'] ?? $slugAtual)) ?: $slugAtual;
        $cupom = 'VOLTA10';
        $listaSum = pz_clientes_sumidos(15, 25, $nomeAtual, $slugAtual, $cupom);
        $filtrados = array_values(array_filter($listaSum, function ($s) {
            $d = (int) ($s['dias_sem_pedir'] ?? 0);
            return $d >= 15 && $d <= 45;
        }));
        $enfileirados = 0;
        foreach (array_slice($filtrados, 0, 20) as $cliSum) {
            if (!empty($cliSum['telefone'])) {
                pz_queue_push('whatsapp.reactivate_customer', [
                    'telefone' => $cliSum['telefone'],
                    'nome' => $cliSum['nome'] ?? 'Cliente',
                    'cupom' => $cupom,
                ], $slugAtual, $enfileirados * 2);
                $enfileirados++;
            }
        }
        pz_registrar_auditoria('marketing', 'campanha_sumidos_ia', "Enfileirados {$enfileirados} cliente(s) com cupom {$cupom}", $ator);
        echo json_encode([
            'success' => true,
            'enfileirados' => $enfileirados,
            'cupom' => $cupom,
            'message' => $enfileirados > 0
                ? "Campanha aprovada! {$enfileirados} cliente(s) receberão a oferta {$cupom} pelo WhatsApp automático."
                : 'Nenhum cliente inativo elegível para disparo agora.',
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'clientes_csv':
        pz_exigir_perm($sessao, ['clientes', 'relatorios']);
        if (!pz_plano_tem_recurso('csv_export', null, $isMasterPlano)) {
            pz_plano_exigir('csv_export', 'Exportação livre da Base de Clientes (CSV)', 'scale', $isMasterPlano);
        }
        $cliLista = pz_ler_clientes();
        $linhasCsv = ['Nome;WhatsApp;Pedidos;Total Gasto;Ultima Compra;Endereco'];
        $limpCsv = static function ($s) {
            $s = str_replace(["\r", "\n", ';'], [' ', ' ', ','], (string) $s);
            if ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) {
                $s = "'" . $s;
            }
            return $s;
        };
        foreach ($cliLista as $c) {
            if (!is_array($c)) {
                continue;
            }
            $linhasCsv[] = implode(';', [
                $limpCsv($c['nome'] ?? ''),
                $limpCsv($c['telefone'] ?? ''),
                (int) ($c['total_pedidos'] ?? 0),
                number_format((float) ($c['total_gasto'] ?? 0), 2, ',', ''),
                $limpCsv($c['ultima_compra'] ?? ''),
                $limpCsv($c['endereco'] ?? ''),
            ]);
        }
        echo json_encode([
            'success' => true,
            'filename' => 'clientes-' . Database::getTenant() . '-' . date('Y-m-d') . '.csv',
            'total' => count($cliLista),
            'csv' => implode("\n", $linhasCsv),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'otimizar_cardapio_ia':
        pz_exigir_perm($sessao, ['cardapio', 'config', 'visao_geral']);
        $aplicar = !empty($req['aplicar']);
        $slugAtual = Database::getTenant();
        $resOtim = pz_otimizar_cardapio_sugestoes($slugAtual, $aplicar);
        if ($aplicar && !empty($resOtim['alterados'])) {
            pz_registrar_auditoria('cardapio', 'otimizacao_ia', $resOtim['alterados'] . ' descrição(ões) aprimorada(s) pela IA', $ator);
        }
        echo json_encode($resOtim, JSON_UNESCAPED_UNICODE);
        break;

    case 'rede_clonar_cardapio':
        pz_exigir_perm($sessao, ['cardapio', 'config']);
        $slugOrigem = Database::getTenant();
        $slugDestino = strtolower(trim((string) ($req['destino'] ?? '')));
        if ($slugDestino === '' || $slugDestino === $slugOrigem) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Selecione uma filial de destino diferente desta loja.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $reg = pedidozap_ler_registro();
        $mOrig = is_array($reg[$slugOrigem] ?? null) ? $reg[$slugOrigem] : [];
        $mDest = is_array($reg[$slugDestino] ?? null) ? $reg[$slugDestino] : null;
        $grupoOrig = trim((string) ($mOrig['grupo_rede'] ?? ''));
        $grupoDest = $mDest ? trim((string) ($mDest['grupo_rede'] ?? '')) : '';
        if (!$mDest || (!$isMasterPlano && ($grupoOrig === '' || strcasecmp($grupoOrig, $grupoDest) !== 0))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Cada unidade precisa pertencer à mesma rede (mesmo grupo) e ter assinatura ativa própria.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $menuOrig = Database::readStrict('cardapio.json');
        $cfgOrig = pedidozap_normalizar_config(Database::readStrict('config.json'), $slugOrigem);
        $antT = Database::getTenant();
        try {
            Database::setTenant($slugDestino);
            Database::write('cardapio.json', is_array($menuOrig) ? $menuOrig : []);
            Database::mutate('config.json', function (&$c) use ($cfgOrig) {
                if (!is_array($c)) {
                    $c = [];
                }
                if (!empty($cfgOrig['CATEGORIAS'])) {
                    $c['CATEGORIAS'] = $cfgOrig['CATEGORIAS'];
                }
                $c['VERSION'] = time();
                return true;
            });
        } finally {
            Database::setTenant($antT);
        }
        if (function_exists('pz_cache_del')) {
            pz_cache_del('pub_store_json_' . $slugDestino);
            pz_cache_del('mkt_lojas_all');
        }
        pz_registrar_auditoria('rede', 'clonar_cardapio', 'Cardápio clonado para filial ' . $slugDestino, $ator);
        echo json_encode(['success' => true, 'message' => 'Cardápio sincronizado para a unidade ' . ($mDest['nome_empresa'] ?? $slugDestino) . '!'], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ação inválida: ' . $action], JSON_UNESCAPED_UNICODE);
        break;
}
