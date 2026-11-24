<?php
/**
 * Catálogo Oficial de Planos e Controle Real de Recursos (Feature Gating) — AcheIAki
 * Modelo comercial: 1 única plataforma, 0% comissão por pedido, 3 planos valorizados:
 *   - essencial  (R$ 79,90/mês  | Anual 20% OFF: R$ 63,92/mês)  — Porta de entrada
 *   - pro        (R$ 149,90/mês | Anual 20% OFF: R$ 119,92/mês) — Carro-chefe recomendado
 *   - enterprise (R$ 249,90/mês | Anual 20% OFF: R$ 199,92/mês) — Operação completa & Redes
 */

function pz_planos_precos_padrao(): array
{
    return [
        'essencial' => 79.90,
        'pro' => 149.90,
        'enterprise' => 249.90,
    ];
}

function pz_plano_normalizar($slug): string
{
    $s = strtolower(trim((string) $slug));
    $mapa = [
        'essencial' => 'essencial',
        'basico' => 'essencial',
        'básico' => 'essencial',
        'entrada' => 'essencial',
        'pro' => 'pro',
        'profissional' => 'pro',
        'intermediario' => 'pro',
        'mensal' => 'pro',
        'teste' => 'pro',
        'enterprise' => 'enterprise',
        'empresarial' => 'enterprise',
        'rede' => 'enterprise',
        'premium' => 'enterprise',
        'avancado' => 'enterprise',
        'avançado' => 'enterprise',
        'completo' => 'enterprise',
    ];
    return $mapa[$s] ?? 'essencial';
}

function pz_planos_ordem(): array
{
    return ['essencial', 'pro', 'enterprise'];
}

function pz_planos_catalogo(?array $saasCfg = null): array
{
    if ($saasCfg === null && function_exists('pedidozap_saas_config')) {
        $saasCfg = pedidozap_saas_config();
    }
    $saasCfg = is_array($saasCfg) ? $saasCfg : [];
    $precosPadrao = pz_planos_precos_padrao();
    $precosSalvos = is_array($saasCfg['planos_precos'] ?? null) ? $saasCfg['planos_precos'] : [];

    $precoDe = static function (string $slug) use ($precosPadrao, $precosSalvos): float {
        if (isset($precosSalvos[$slug]) && is_numeric($precosSalvos[$slug]) && (float) $precosSalvos[$slug] > 0) {
            return round((float) $precosSalvos[$slug], 2);
        }
        return $precosPadrao[$slug];
    };

    $descAnualPct = isset($saasCfg['desconto_anual_pct']) && is_numeric($saasCfg['desconto_anual_pct'])
        ? max(0, min(50, (int) $saasCfg['desconto_anual_pct']))
        : 20;
    $fatorAnual = (100 - $descAnualPct) / 100;

    $calcAnual = static function (float $mensal) use ($fatorAnual): array {
        $equivMensal = round($mensal * $fatorAnual, 2);
        $totalAnual = round($equivMensal * 12, 2);
        return [$totalAnual, $equivMensal];
    };

    $pEssencial = $precoDe('essencial');
    $pPro = $precoDe('pro');
    $pEnterprise = $precoDe('enterprise');

    [$aEssencial, $amEssencial] = $calcAnual($pEssencial);
    [$aPro, $amPro] = $calcAnual($pPro);
    [$aEnterprise, $amEnterprise] = $calcAnual($pEnterprise);

    return [
        'essencial' => [
            'slug' => 'essencial',
            'nome' => 'Essencial',
            'subtitulo' => 'Porta de entrada sem comissão',
            'publico' => 'Quem está começando no delivery próprio e quer sair das taxas de aplicativos.',
            'badge' => 'Porta de entrada',
            'recomendado' => false,
            'preco_mensal' => $pEssencial,
            'preco_anual' => $aEssencial,
            'preco_anual_mensal' => $amEssencial,
            'desconto_anual_pct' => $descAnualPct,
            'boost_territorio' => 0,
            'selo_marketplace' => '',
            'limites' => [
                'max_produtos' => 50,
                'max_funcionarios' => 0,
                'max_unidades' => 1,
            ],
            'recursos' => [
                'vitrine_marketplace' => true,
                'pedidos_ilimitados_zero_comissao' => true,
                'painel_kanban_cozinha' => true,
                'impressao_termica' => true,
                'rastreio_cliente' => true,
                'avaliacoes_publicas' => true,
                'cardapio_ilimitado' => false,
                'caixa_financeiro_dre' => false,
                'crm_cupons_fidelidade' => false,
                'entregadores_gps' => false,
                'equipe_rbac' => false,
                'ia_vitrine' => false,
                'ia_gestor_acoes' => false,
                'ia_pessoal_externa' => false,
                'estoque_ficha_cmv' => false,
                'push_campanhas' => false,
                'pdv_open_delivery' => false,
                'fiscal_nfce' => false,
                'auditoria_backup' => false,
                'destaque_marketplace' => false,
                'multi_unidades' => false,
            ],
            'itens_destaque' => [
                '0% de comissão por pedido e vendas ilimitadas',
                'Cardápio digital próprio (/loja/sua-loja) + presença no Marketplace Regional',
                'Até 50 produtos cadastrados com compressão WebP automática',
                'Painel de pedidos, Tela da Cozinha e impressão de comandas (58mm/80mm)',
                'Rastreio em tempo real para o cliente e avaliações públicas',
                'Acesso do proprietário (sem financeiro, IA ou automações)',
            ],
        ],
        'pro' => [
            'slug' => 'pro',
            'nome' => 'Pro',
            'subtitulo' => 'O carro-chefe para faturar mais',
            'publico' => 'Restaurantes e deliverys ativos que querem vender mais, fidelizar e controlar o caixa.',
            'badge' => 'Recomendado',
            'recomendado' => true,
            'preco_mensal' => $pPro,
            'preco_anual' => $aPro,
            'preco_anual_mensal' => $amPro,
            'desconto_anual_pct' => $descAnualPct,
            'boost_territorio' => 5,
            'selo_marketplace' => '',
            'limites' => [
                'max_produtos' => 0, // 0 = ilimitado
                'max_funcionarios' => 5,
                'max_unidades' => 1,
            ],
            'recursos' => [
                'vitrine_marketplace' => true,
                'pedidos_ilimitados_zero_comissao' => true,
                'painel_kanban_cozinha' => true,
                'impressao_termica' => true,
                'rastreio_cliente' => true,
                'avaliacoes_publicas' => true,
                'cardapio_ilimitado' => true,
                'caixa_financeiro_dre' => true,
                'crm_cupons_fidelidade' => true,
                'entregadores_gps' => true,
                'equipe_rbac' => true,
                'ia_vitrine' => true,
                'ia_gestor_acoes' => false,
                'ia_pessoal_externa' => false,
                'estoque_ficha_cmv' => false,
                'push_campanhas' => false,
                'pdv_open_delivery' => false,
                'fiscal_nfce' => false,
                'auditoria_backup' => false,
                'destaque_marketplace' => false,
                'multi_unidades' => false,
            ],
            'itens_destaque' => [
                'Tudo do Essencial + produtos ilimitados no cardápio',
                'Até 5 funcionários com controle de permissões (RBAC)',
                'Caixa diário, Contas a Pagar/Receber e DRE financeiro completo',
                'Cupons de desconto, Programa de Fidelidade e Recuperação de Carrinhos Abandonados',
                'Gestão de entregadores próprios com mapa GPS ao vivo',
                'Atendente Virtual com IA na vitrine e resposta oficial às avaliações',
            ],
        ],
        'enterprise' => [
            'slug' => 'enterprise',
            'nome' => 'Enterprise / Rede',
            'subtitulo' => 'Operação completa e múltiplas lojas',
            'publico' => 'Operações de alto volume, franquias e redes que exigem automação fiscal, PDV e escala.',
            'badge' => 'Operação Completa',
            'recomendado' => false,
            'preco_mensal' => $pEnterprise,
            'preco_anual' => $aEnterprise,
            'preco_anual_mensal' => $amEnterprise,
            'desconto_anual_pct' => $descAnualPct,
            'boost_territorio' => 12,
            'selo_marketplace' => 'Destaque Enterprise',
            'limites' => [
                'max_produtos' => 0,
                'max_funcionarios' => 0, // 0 = ilimitado
                'max_unidades' => 0,     // 0 = ilimitado
            ],
            'recursos' => [
                'vitrine_marketplace' => true,
                'pedidos_ilimitados_zero_comissao' => true,
                'painel_kanban_cozinha' => true,
                'impressao_termica' => true,
                'rastreio_cliente' => true,
                'avaliacoes_publicas' => true,
                'cardapio_ilimitado' => true,
                'caixa_financeiro_dre' => true,
                'crm_cupons_fidelidade' => true,
                'entregadores_gps' => true,
                'equipe_rbac' => true,
                'ia_vitrine' => true,
                'ia_gestor_acoes' => true,
                'ia_pessoal_externa' => true,
                'estoque_ficha_cmv' => true,
                'push_campanhas' => true,
                'pdv_open_delivery' => true,
                'fiscal_nfce' => true,
                'auditoria_backup' => true,
                'destaque_marketplace' => true,
                'multi_unidades' => true,
            ],
            'itens_destaque' => [
                'Tudo do Pro + funcionários ilimitados',
                'Controle de Estoque, Fornecedores e Ficha Técnica com CMV automático',
                'Emissão Fiscal NFC-e / SAT + Integração PDV / ERP (Open Delivery)',
                'Campanhas Push Notifications + Copiloto IA completo e IA Pessoal (API/Voz)',
                'Auditoria completa, Backup JSON e Destaque Máximo nas buscas',
                'Painel Consolidado para Múltiplas Lojas / Filiais (Rede)',
            ],
        ],
    ];
}

function pz_plano_preco_ciclo(string $planoSlug, string $ciclo = 'mensal', ?array $saasCfg = null): float
{
    $norm = pz_plano_normalizar($planoSlug);
    $cat = pz_planos_catalogo($saasCfg);
    $def = $cat[$norm] ?? $cat['essencial'];
    return strtolower(trim($ciclo)) === 'anual' ? (float) $def['preco_anual'] : (float) $def['preco_mensal'];
}

function pz_plano_da_loja(?string $slugTenant = null, ?array $lojaMeta = null, ?array $saasCfg = null): array
{
    $tenant = $slugTenant !== null && $slugTenant !== ''
        ? $slugTenant
        : (class_exists('Database') ? Database::getTenant() : 'default');

    if ($lojaMeta !== null) {
        $loja = $lojaMeta;
    } else {
        $registro = function_exists('pedidozap_ler_registro') ? pedidozap_ler_registro() : [];
        $loja = is_array($registro[$tenant] ?? null) ? $registro[$tenant] : [];
    }
    $planoBruto = (string) ($loja['plano'] ?? '');
    $emTeste = !empty($loja['em_teste']) || $planoBruto === 'teste' || (($loja['status'] ?? '') === 'teste');

    if ($tenant === 'default' && $planoBruto === '') {
        $slugEfetivo = 'enterprise';
    } elseif ($emTeste && !empty($loja['plano_escolhido'])) {
        $slugEfetivo = pz_plano_normalizar((string) $loja['plano_escolhido']);
    } elseif ($emTeste && ($planoBruto === '' || $planoBruto === 'teste')) {
        $slugEfetivo = 'pro';
    } else {
        $slugEfetivo = pz_plano_normalizar($planoBruto !== '' ? $planoBruto : 'essencial');
    }

    $catalogo = pz_planos_catalogo($saasCfg);
    $def = $catalogo[$slugEfetivo] ?? $catalogo['essencial'];
    $def['em_teste'] = $emTeste;
    $def['plano_bruto'] = $planoBruto;
    $def['ciclo'] = strtolower((string) ($loja['ciclo_cobranca'] ?? 'mensal')) === 'anual' ? 'anual' : 'mensal';
    $def['grupo_rede'] = trim((string) ($loja['grupo_rede'] ?? ''));
    return $def;
}

function pz_plano_tem_recurso(string $recurso, ?string $slugTenant = null, bool $isMaster = false): bool
{
    if ($isMaster) {
        return true;
    }
    $plano = pz_plano_da_loja($slugTenant);
    return !empty($plano['recursos'][$recurso]);
}

function pz_plano_exigir(string $recurso, string $rotuloRecurso, string $planoMinimo = 'pro', bool $isMaster = false): void
{
    if (pz_plano_tem_recurso($recurso, null, $isMaster)) {
        return;
    }
    $atual = pz_plano_da_loja();
    $cat = pz_planos_catalogo();
    $minDef = $cat[pz_plano_normalizar($planoMinimo)] ?? $cat['pro'];
    http_response_code(402);
    echo json_encode([
        'success' => false,
        'codigo_erro' => 'upgrade_plano',
        'recurso' => $recurso,
        'plano_atual' => $atual['slug'],
        'plano_atual_nome' => $atual['nome'],
        'plano_necessario' => $minDef['slug'],
        'plano_necessario_nome' => $minDef['nome'],
        'preco_necessario' => $minDef['preco_mensal'],
        'message' => $rotuloRecurso . ' está disponível a partir do plano ' . $minDef['nome']
            . ' (R$ ' . number_format((float) $minDef['preco_mensal'], 2, ',', '.') . '/mês). Faça upgrade na aba Meu Plano.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function pz_plano_contar_produtos_cardapio($cardapio): int
{
    if (!is_array($cardapio)) {
        return 0;
    }
    $total = 0;
    foreach ($cardapio as $lista) {
        if (!is_array($lista)) {
            continue;
        }
        foreach ($lista as $item) {
            if (is_array($item) && trim((string) ($item['nome'] ?? '')) !== '') {
                $total++;
            }
        }
    }
    return $total;
}

function pz_planos_em_breve(): array
{
    return [
        [
            'slug' => 'rede_entregadores',
            'titulo' => 'Rede de Entregadores Parceiros',
            'badge' => 'Em breve',
            'descricao' => 'Chame motoboys disponíveis na região nos horários de pico com rastreio ao vivo no painel.',
        ],
        [
            'slug' => 'split_automatico',
            'titulo' => 'Split Automático Pix & Cartão',
            'badge' => 'Em breve',
            'descricao' => 'Recebimento direto na sua conta bancária no ato do pedido online com conciliação automática.',
        ],
        [
            'slug' => 'clube_cashback',
            'titulo' => 'Clube de Cashback do Bairro',
            'badge' => 'Em breve',
            'descricao' => 'Clientes acumulam saldo direto para recompra nas lojas parceiras da região sem custo de comissão.',
        ],
    ];
}
