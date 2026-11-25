<?php

/**
 * Controle comercial do Micro SaaS: registro das lojas, plano e chave mestre.
 * A senha do lojista permanece só com hash. O master entra por impersonação.
 */
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/planos_lib.php';

function pedidozap_nicho_presets()
{
    require_once __DIR__ . '/store_schema.php';
    $cat = function ($slug, $nome, $emoji, $cor, $sub) {
        return [
            'slug' => $slug,
            'nome' => $nome,
            'subtitulo' => $sub,
            'emoji' => $emoji,
            'icone' => pedidozap_icone_categoria($slug),
            'cor' => $cor,
            'ativo' => true,
        ];
    };

    return [
        'lanchonete' => [
            'rotulo' => 'Lanchonete',
            'categorias' => [
                $cat('lanches', 'Lanches', '🍔', '#f97136', 'Os mais pedidos'),
                $cat('porcoes', 'Porções', '🍟', '#f59e0b', 'Para acompanhar'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
                $cat('sobremesas', 'Sobremesas', '🍰', '#db2777', 'Para fechar'),
            ],
        ],
        'pensao' => [
            'rotulo' => 'Pensão / comida caseira',
            'categorias' => [
                $cat('pratos', 'Pratos feitos', '🍽️', '#b45309', 'Almoço do dia'),
                $cat('marmitas', 'Marmitas', '🍱', '#ea580c', 'P, M e G'),
                $cat('guarnicoes', 'Guarnições', '🥗', '#16a34a', 'Acompanhamentos'),
                $cat('sopas', 'Sopas e caldos', '🥣', '#d97706', 'Quentinhas'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
            ],
        ],
        'marmitaria' => [
            'rotulo' => 'Marmitaria',
            'categorias' => [
                $cat('marmitas', 'Marmitas', '🍱', '#ea580c', 'Monte a sua'),
                $cat('executivos', 'Executivos', '🥗', '#15803d', 'Pratos leves'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
            ],
        ],
        'pizzaria' => [
            'rotulo' => 'Pizzaria',
            'categorias' => [
                $cat('pizzas', 'Pizzas', '🍕', '#dc2626', 'Tradicionais e especiais'),
                $cat('bordas', 'Bordas e adicionais', '🧀', '#d97706', 'Para incrementar'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
            ],
        ],
        'acai' => [
            'rotulo' => 'Açaí',
            'categorias' => [
                $cat('acai', 'Açaí', '🫐', '#7e22ce', 'Tigelas'),
                $cat('complementos', 'Complementos', '🍓', '#db2777', 'Monte do seu jeito'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
            ],
        ],
        'confeitaria' => [
            'rotulo' => 'Doceria / confeitaria',
            'grupo' => 'alimentacao',
            'categorias' => [
                $cat('bolos', 'Bolos e fatias', '🎂', '#db2777', 'Encomendas'),
                $cat('doces', 'Doces', '🍬', '#f59e0b', 'Para presentear'),
                $cat('bebidas', 'Bebidas', '☕', '#78350f', 'Para acompanhar'),
            ],
        ],
        'bebidas' => [
            'rotulo' => 'Distribuidora de Bebidas & Adega',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('cervejas', 'Cervejas & Chopp', '🍺', '#d97706', 'Bem geladas'),
                $cat('refrigerantes', 'Refrigerantes & Sucos', '🥤', '#ea580c', '2L, latas e fardos'),
                $cat('destilados', 'Vinhos & Destilados', '🍷', '#991b1b', 'Seleção especial'),
                $cat('conveniencia', 'Água, Gelo & Carvão', '🧊', '#0284c7', 'Para seu churrasco'),
            ],
        ],
        'mercado' => [
            'rotulo' => 'Mercado, Mercearia & Hortifruti',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('hortifruti', 'Hortifruti Fresco', '🥬', '#15803d', 'Frutas e verduras'),
                $cat('mercearia', 'Mercearia & Despensa', '🛒', '#ea580c', 'Arroz, feijão e básicos'),
                $cat('acougue', 'Carnes & Frios', '🥩', '#b91c1c', 'Cortes e queijos'),
                $cat('limpeza', 'Limpeza & Higiene', '🧼', '#0f766e', 'Para casa toda'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Água, sucos e refri'),
            ],
        ],
        'farmacia' => [
            'rotulo' => 'Farmácia, Saúde & Perfumaria',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('medicamentos', 'Saúde & Bem-Estar', '💊', '#dc2626', 'Uso diário'),
                $cat('higiene', 'Higiene Pessoal', '🧴', '#15803d', 'Cuidados diários'),
                $cat('bebe', 'Mamãe & Bebê', '🍼', '#d97706', 'Fraldas e cuidados'),
                $cat('suplementos', 'Vitaminas & Suplementos', '💪', '#ea580c', 'Energia e imunidade'),
            ],
        ],
        'petshop' => [
            'rotulo' => 'Pet Shop & Casa de Ração',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('caes', 'Rações para Cães', '🐶', '#ea580c', 'Filhotes e adultos'),
                $cat('gatos', 'Rações & Areia Gatos', '🐱', '#b45309', 'Sabores variados'),
                $cat('petiscos', 'Petiscos & Brinquedos', '🦴', '#15803d', 'Para alegrar o pet'),
                $cat('higiene_pet', 'Higiene & Cuidados', '🛁', '#0f766e', 'Shampoo e antipulgas'),
            ],
        ],
        'moda' => [
            'rotulo' => 'Roupas, Calçados & Acessórios',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('feminino', 'Moda Feminina', '👗', '#db2777', 'Lançamentos e looks'),
                $cat('masculino', 'Moda Masculina', '👕', '#231915', 'Camisetas, calças e kits'),
                $cat('calcados', 'Calçados & Tênis', '👟', '#ea580c', 'Conforto e estilo'),
                $cat('acessorios', 'Bolsas & Acessórios', '👜', '#b45309', 'Para completar o visual'),
            ],
        ],
        'eletronicos' => [
            'rotulo' => 'Eletrônicos, Celulares & Informática',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('celulares', 'Acessórios de Celular', '📱', '#ea580c', 'Capinhas, películas e cabos'),
                $cat('audio', 'Fones & Caixas de Som', '🎧', '#231915', 'Bluetooth e áudio'),
                $cat('informatica', 'Informática & Gamer', '💻', '#b45309', 'Mouses, teclados e hubs'),
                $cat('energia', 'Carregadores & Baterias', '🔋', '#15803d', 'Turbo e portáteis'),
            ],
        ],
        'beleza' => [
            'rotulo' => 'Cosméticos, Maquiagem & Perfumaria',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('maquiagem', 'Maquiagem', '💄', '#db2777', 'Bases, batons e paletas'),
                $cat('skincare', 'Skincare & Rosto', '✨', '#ea580c', 'Séruns e hidratantes'),
                $cat('cabelos', 'Cabelos & Finalizadores', '💆', '#b45309', 'Hidratação e brilho'),
                $cat('perfumes', 'Perfumes & Kits', '🎁', '#7e22ce', 'Presentes prontos'),
            ],
        ],
        'papelaria' => [
            'rotulo' => 'Papelaria, Presentes & Floricultura',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('presentes', 'Presentes & Cestas', '🎁', '#ea580c', 'Prontos para surpreender'),
                $cat('flores', 'Flores & Plantas', '💐', '#15803d', 'Buquês e vasos'),
                $cat('escolar', 'Escolar & Escritório', '✏️', '#b45309', 'Cadernos, canetas e kits'),
                $cat('brinquedos', 'Brinquedos & Jogos', '🧸', '#dc2626', 'Diversão para crianças'),
            ],
        ],
        'ferragens' => [
            'rotulo' => 'Construção, Ferragens & Utilidades',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('ferramentas', 'Ferramentas', '🔧', '#b45309', 'Manuais e elétricas'),
                $cat('eletrica', 'Elétrica & Iluminação', '💡', '#f59e0b', 'Lâmpadas, fios e tomadas'),
                $cat('hidraulica', 'Hidráulica & Reparos', '🚿', '#0f766e', 'Torneiras e conexões'),
                $cat('utilidades', 'Casa & Jardim', '🏡', '#15803d', 'Utilidades do dia a dia'),
            ],
        ],
        'comercio' => [
            'rotulo' => 'Loja / Comércio em Geral (Qualquer Produto)',
            'grupo' => 'varejo',
            'categorias' => [
                $cat('destaques', 'Ofertas & Destaques', '🏷️', '#ea580c', 'Melhores preços da loja'),
                $cat('catalogo', 'Produtos Principais', '🛍️', '#231915', 'Escolha e peça direto'),
                $cat('novidades', 'Novidades', '✨', '#b45309', 'Recém-chegados'),
                $cat('combos', 'Kits & Combos', '📦', '#15803d', 'Mais economia juntos'),
            ],
        ],
    ];
}

function pedidozap_saas_config()
{
    $anterior = Database::getTenant();
    try {
        Database::setTenant('default');
        $data = Database::readStrict('saas_config.json');
    } finally {
        Database::setTenant($anterior);
    }

    if (!is_array($data) || empty($data)) {
        $path = __DIR__ . '/data/saas_config.json';
        if (is_file($path)) {
            $fileData = json_decode(@file_get_contents($path) ?: '', true);
            if (is_array($fileData)) {
                $data = $fileData;
            }
        }
    }
    if (!is_array($data)) {
        $data = [];
    }

    $cfg = array_merge([
        'nome_plataforma' => 'AcheIAki',
        'slogan_plataforma' => 'Lanches, Pizzarias e Comida perto de você',
        'suporte_whatsapp' => '',
        'dpo_email' => 'privacidade@acheiaki.com.br',
        'cnpj_plataforma' => '',
        'dominio_base' => '',
        'google_site_verification' => '',
        'mensalidade_valor' => 59.90,
        'planos_precos' => pz_planos_precos_padrao(),
        'desconto_anual_pct' => 20,
        'dias_teste_gratis' => 30,
        'tolerancia_atraso_dias' => 2,
        'multa_reativacao_pct' => 10,
        'mp_access_token' => '',
    ], $data);
    $cfg['planos_precos'] = array_merge(pz_planos_precos_padrao(), is_array($cfg['planos_precos'] ?? null) ? $cfg['planos_precos'] : []);
    $cfg['dias_teste_gratis'] = max(1, min(30, (int) ($cfg['dias_teste_gratis'] ?? 30)));
    $cfg['tolerancia_atraso_dias'] = max(0, min(2, (int) ($cfg['tolerancia_atraso_dias'] ?? 2)));
    $cfg['multa_reativacao_pct'] = max(0, min(10, round((float) ($cfg['multa_reativacao_pct'] ?? 10), 2)));

    $envMp = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_MP_ACCESS_TOKEN') : '';
    if ($envMp !== '') {
        $cfg['mp_access_token'] = $envMp;
    }
    $envDpo = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_DPO_EMAIL') : '';
    if ($envDpo !== '') {
        $cfg['dpo_email'] = $envDpo;
    }
    $envGsc = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_GSC_TOKEN') : '';
    if ($envGsc !== '') {
        $cfg['google_site_verification'] = $envGsc;
    }
    return $cfg;
}

/** Versão da configuração que pode ir para o navegador do master: nunca devolve o token. */
function pedidozap_saas_config_publica()
{
    $saas = pedidozap_saas_config();
    $token = (string) ($saas['mp_access_token'] ?? '');
    unset($saas['mp_access_token']);
    $saas['mp_configurado'] = $token !== '';
    $saas['mp_token_final'] = $token !== '' ? substr($token, -4) : '';
    $saas['mp_modo_teste'] = strpos($token, 'TEST-') === 0;
    $saas['webhook_url'] = pedidozap_base_url() . '/mp_webhook.php';
    $saas['planos_catalogo'] = array_values(pz_planos_catalogo($saas));
    return $saas;
}

function pedidozap_salvar_saas_config(array $config)
{
    $atual = pedidozap_saas_config();
    $atual['nome_plataforma'] = trim((string) ($config['nome_plataforma'] ?? $atual['nome_plataforma']));
    if (array_key_exists('slogan_plataforma', $config)) {
        $slogan = trim((string) $config['slogan_plataforma']);
        if ($slogan !== '') {
            $atual['slogan_plataforma'] = $slogan;
        }
    }
    $atual['suporte_whatsapp'] = preg_replace('/\D/', '', (string) ($config['suporte_whatsapp'] ?? $atual['suporte_whatsapp'] ?? ''));
    if (array_key_exists('dpo_email', $config)) {
        $dpo = trim((string) $config['dpo_email']);
        if ($dpo === '' || filter_var($dpo, FILTER_VALIDATE_EMAIL)) {
            $atual['dpo_email'] = $dpo;
        }
    }
    if (array_key_exists('cnpj_plataforma', $config)) {
        $atual['cnpj_plataforma'] = trim((string) $config['cnpj_plataforma']);
    }
    if (array_key_exists('dominio_base', $config)) {
        $dominio = strtolower(trim((string) $config['dominio_base']));
        $dominio = preg_replace('#^https?://#', '', $dominio);
        $dominio = preg_replace('#/.*$#', '', $dominio);
        $atual['dominio_base'] = preg_replace('/^www\./', '', $dominio);
    }
    if (array_key_exists('google_site_verification', $config)) {
        $gsc = trim((string) $config['google_site_verification']);
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $gsc, $m)) {
            $gsc = trim($m[1]);
        }
        $atual['google_site_verification'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $gsc);
    }
    if (isset($config['planos_precos']) && is_array($config['planos_precos'])) {
        $precosBase = is_array($atual['planos_precos'] ?? null) ? $atual['planos_precos'] : pz_planos_precos_padrao();
        foreach (pz_planos_ordem() as $slugPlano) {
            if (array_key_exists($slugPlano, $config['planos_precos'])) {
                $valP = (float) str_replace(',', '.', (string) $config['planos_precos'][$slugPlano]);
                if ($valP > 0) {
                    $precosBase[$slugPlano] = round($valP, 2);
                }
            }
        }
        $atual['planos_precos'] = $precosBase;
        $atual['mensalidade_valor'] = (float) ($precosBase['start'] ?? 59.90);
    } elseif (array_key_exists('mensalidade_valor', $config)) {
        $valor = (float) str_replace(',', '.', (string) $config['mensalidade_valor']);
        $atual['mensalidade_valor'] = $valor > 0 ? round($valor, 2) : 0;
    }
    if (array_key_exists('desconto_anual_pct', $config)) {
        $atual['desconto_anual_pct'] = max(0, min(50, (int) $config['desconto_anual_pct']));
    }
    if (array_key_exists('dias_teste_gratis', $config)) {
        $atual['dias_teste_gratis'] = max(1, min(30, (int) $config['dias_teste_gratis']));
    }
    if (array_key_exists('tolerancia_atraso_dias', $config)) {
        $atual['tolerancia_atraso_dias'] = max(0, min(2, (int) $config['tolerancia_atraso_dias']));
    }
    if (array_key_exists('multa_reativacao_pct', $config)) {
        $atual['multa_reativacao_pct'] = max(0, min(10, round((float) str_replace(',', '.', (string) $config['multa_reativacao_pct']), 2)));
    }
    $token = trim((string) ($config['mp_access_token'] ?? ''));
    if ($token !== '') {
        $atual['mp_access_token'] = $token;
    }
    if (!empty($config['mp_remover_token'])) {
        $atual['mp_access_token'] = '';
    }
    $anterior = Database::getTenant();
    try {
        Database::setTenant('default');
        $ok = Database::write('saas_config.json', $atual);
    } finally {
        Database::setTenant($anterior);
    }
    if (function_exists('pz_cache_del')) {
        pz_cache_del('mkt_lojas_all');
    }
    return $ok;
}

function pedidozap_ler_registro()
{
    $anterior = Database::getTenant();
    try {
        Database::setTenant('default');
        $registro = Database::read('tenants_registry.json');
    } finally {
        Database::setTenant($anterior);
    }
    return is_array($registro) ? $registro : [];
}

function pedidozap_salvar_registro_loja($slug, array $meta)
{
    $slug = strtolower(trim((string) $slug));
    if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
        return false;
    }

    $anterior = Database::getTenant();
    try {
        Database::setTenant('default');
        $ok = Database::mutate('tenants_registry.json', function (&$registro) use ($slug, $meta) {
            if (!is_array($registro)) {
                $registro = [];
            }
            $atual = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
            $registro[$slug] = array_merge($atual, $meta, [
                'slug' => $slug,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            if (empty($registro[$slug]['criado_em'])) {
                $registro[$slug]['criado_em'] = date('Y-m-d H:i:s');
            }
            return true;
        });
    } finally {
        Database::setTenant($anterior);
    }
    if ($ok && function_exists('pz_cache_del')) {
        pz_cache_del('pub_store_json_' . $slug);
        pz_cache_del('mkt_lojas_all');
    }
    return $ok;
}

function pedidozap_fmt_brl($valor)
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

function pedidozap_registrar_aviso_lista(array $atuais, $chave, $nivel, $titulo, $mensagem)
{
    $lista = is_array($atuais) ? array_values($atuais) : [];
    foreach ($lista as $item) {
        if (is_array($item) && ($item['chave'] ?? '') === $chave) {
            return $lista;
        }
    }
    array_unshift($lista, [
        'chave' => (string) $chave,
        'nivel' => (string) $nivel,
        'titulo' => (string) $titulo,
        'mensagem' => (string) $mensagem,
        'data' => date('c'),
        'data_br' => date('d/m/Y H:i'),
    ]);
    return array_slice($lista, 0, 20);
}

function pedidozap_parse_data_iso(string $data): ?int
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m)) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    $ts = strtotime($data . ' 00:00:00');
    return $ts !== false ? $ts : null;
}

function pedidozap_somar_ciclo(int $baseTs, string $ciclo = 'mensal'): int
{
    $meses = strtolower(trim($ciclo)) === 'anual' ? 12 : 1;
    try {
        $dt = (new DateTimeImmutable('@' . $baseTs))->setTimezone(new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
        $diaOrig = (int) $dt->format('j');
        $primeiroAlvo = $dt->modify('first day of +' . $meses . ' months');
        $ultimoDia = (int) $primeiroAlvo->format('t');
        $diaFinal = min($diaOrig, $ultimoDia);
        $alvo = $primeiroAlvo->setDate((int) $primeiroAlvo->format('Y'), (int) $primeiroAlvo->format('n'), $diaFinal);
        return $alvo->getTimestamp();
    } catch (\Throwable $e) {
        return (int) strtotime('+' . $meses . ' months', $baseTs);
    }
}

function pedidozap_status_efetivo(array $loja, ?array $saas = null)
{
    if ($saas === null) {
        $saas = pedidozap_saas_config();
    }
    $status = $loja['status'] ?? 'ativo';
    if (!in_array($status, ['ativo', 'bloqueado', 'pendente', 'teste', 'atrasado'], true)) {
        $status = 'ativo';
    }
    if ($status === 'bloqueado') {
        $m = (string) ($loja['bloqueio_motivo'] ?? 'manual');
        return ['status' => 'bloqueado', 'motivo' => $m !== '' ? $m : 'manual'];
    }
    $vencimento = trim((string) ($loja['vencimento'] ?? ''));
    $vencDiaTs = pedidozap_parse_data_iso($vencimento);
    if ($vencDiaTs === null) {
        return ['status' => $status, 'motivo' => ''];
    }

    $vencTsEnd = strtotime($vencimento . ' 23:59:59');
    $agora = time();
    if ($vencTsEnd === false || $agora <= $vencTsEnd) {
        return ['status' => $status, 'motivo' => ''];
    }

    // Período de teste gratuito sem pagamento prévio expira exatamente no fim dos 7 dias.
    $ehTestePuro = (!empty($loja['em_teste']) || $status === 'teste') && empty($loja['ultimo_pagamento']);
    if ($ehTestePuro) {
        return ['status' => 'bloqueado', 'motivo' => 'teste_encerrado'];
    }

    $toleranciaDias = max(0, min(2, (int) ($saas['tolerancia_atraso_dias'] ?? 2)));
    $limiteTolerancia = strtotime($vencimento . ' +' . $toleranciaDias . ' days 23:59:59');
    if ($toleranciaDias > 0 && $limiteTolerancia !== false && $agora <= $limiteTolerancia) {
        return ['status' => 'atrasado', 'motivo' => 'tolerancia'];
    }
    return ['status' => 'bloqueado', 'motivo' => 'vencido'];
}

function pedidozap_cobranca_detalhes(array $loja, ?array $saas = null, ?string $planoOverride = null, ?string $cicloOverride = null)
{
    if ($saas === null) {
        $saas = pedidozap_saas_config();
    }
    $efetivo = pedidozap_status_efetivo($loja, $saas);
    $status = $efetivo['status'];
    $motivo = $efetivo['motivo'];

    $slug = strtolower(trim((string) ($loja['slug'] ?? 'default')));
    $planoBase = $planoOverride !== null
        ? pz_plano_normalizar($planoOverride)
        : pz_plano_normalizar((string) ($loja['plano_escolhido'] ?? $loja['plano'] ?? 'essencial'));
    $cicloBase = $cicloOverride !== null
        ? (strtolower(trim($cicloOverride)) === 'anual' ? 'anual' : 'mensal')
        : (strtolower((string) ($loja['ciclo_cobranca'] ?? 'mensal')) === 'anual' ? 'anual' : 'mensal');

    $valorNormal = round((float) pz_plano_preco_ciclo($planoBase, $cicloBase, $saas), 2);
    $multaPctConfig = max(0.0, min(10.0, round((float) ($saas['multa_reativacao_pct'] ?? 10), 2)));
    $toleranciaMax = max(0, min(2, (int) ($saas['tolerancia_atraso_dias'] ?? 2)));

    $vencimento = trim((string) ($loja['vencimento'] ?? ''));
    $vencDiaTs = pedidozap_parse_data_iso($vencimento);
    $hoje = date('Y-m-d');
    $diasRestantes = null;
    $diasAtraso = 0;
    $dataBloqueioPrevista = '';
    $vencBr = $vencDiaTs !== null ? date('d/m/Y', $vencDiaTs) : '';
    $bloqueioBr = '';

    $ehTestePuro = (!empty($loja['em_teste']) || (($loja['status'] ?? '') === 'teste')) && empty($loja['ultimo_pagamento']);
    if ($vencDiaTs !== null) {
        $diffDias = (int) floor(($vencDiaTs - strtotime($hoje . ' 00:00:00')) / 86400);
        if ($diffDias >= 0) {
            $diasRestantes = $diffDias;
        } else {
            $diasAtraso = abs($diffDias);
        }
        $dataBloqueioTs = $ehTestePuro
            ? $vencDiaTs
            : strtotime($vencimento . ' +' . $toleranciaMax . ' days');
        if ($dataBloqueioTs !== false) {
            $dataBloqueioPrevista = date('Y-m-d', $dataBloqueioTs);
            $bloqueioBr = date('d/m/Y', $dataBloqueioTs);
        }
    }

    // Encargo contratual de 10% incide após ultrapassar os 2 dias de tolerância em assinatura/plano regularizado (ou pendência marcada).
    $temHistoricoContratado = empty($loja['em_teste'])
        || !empty($loja['ultimo_pagamento'])
        || !empty($loja['assinatura_id'])
        || !empty($loja['taxa_reativacao_pendente']);
    $encargoAtivo = ($status === 'bloqueado' && $motivo === 'vencido' && $temHistoricoContratado && $multaPctConfig > 0)
        || (!empty($loja['taxa_reativacao_pendente']) && $status === 'bloqueado' && $multaPctConfig > 0);
    $encargoPct = $encargoAtivo ? $multaPctConfig : 0.0;
    $encargoValor = $encargoAtivo ? round($valorNormal * ($encargoPct / 100.0), 2) : 0.0;
    $valorTotal = round($valorNormal + $encargoValor, 2);

    $shadowban = ($status === 'bloqueado');
    $emAtraso = ($status === 'atrasado') || ($status === 'bloqueado' && $motivo === 'vencido');
    $tolRestante = ($status === 'atrasado') ? max(0, $toleranciaMax - $diasAtraso) : 0;

    $automaticaAtiva = (($loja['assinatura_status'] ?? '') === 'authorized');
    $modoPreferido = $automaticaAtiva
        ? 'recorrente'
        : (((string) ($loja['modo_cobranca_preferido'] ?? 'avulso')) === 'recorrente' ? 'recorrente' : 'avulso');

    $aviso = null;
    if ($status === 'bloqueado') {
        if ($motivo === 'manual') {
            $aviso = [
                'nivel' => 'bloqueado',
                'chave' => 'bloqueado_manual',
                'titulo' => 'Loja pausada pela administração (Shadowban ativo)',
                'mensagem' => 'Sua vitrine está oculta para compradores no Marketplace e no link direto, mas todo o seu cadastro, cardápio, pedidos e clientes continuam 100% salvos sem perdas.',
                'cta_texto' => 'Falar com o suporte',
            ];
        } elseif ($motivo === 'teste_encerrado') {
            $aviso = [
                'nivel' => 'bloqueado',
                'chave' => 'teste_encerrado_' . $vencimento,
                'titulo' => 'Período de teste grátis de 7 dias encerrado — Vitrine oculta (Shadowban)',
                'mensagem' => 'Seus 7 dias de teste terminaram em ' . $vencBr . '. Sua página desapareceu para os compradores, mas TODO o seu cardápio, fotos, clientes e configurações permanecem 100% salvos. Escolha um plano e ative agora para voltar ao ar na hora.',
                'cta_texto' => 'Ativar plano e voltar ao ar (' . pedidozap_fmt_brl($valorTotal) . ')',
            ];
        } else {
            $aviso = [
                'nivel' => 'bloqueado',
                'chave' => 'shadowban_10pct_' . $vencimento,
                'titulo' => 'Shadowban por atraso superior a ' . $toleranciaMax . ' dias — Loja oculta para compradores',
                'mensagem' => 'O pagamento venceu em ' . $vencBr . ' e ultrapassou o limite máximo de ' . $toleranciaMax . ' dias de tolerância (em ' . $bloqueioBr . '). Sua loja desapareceu do Marketplace e do link público, porém continua 100% cadastrada sem perder nenhum produto, foto ou pedido. Para reativar imediatamente: ' . pedidozap_fmt_brl($valorNormal) . ' + ' . rtrim(rtrim((string) $encargoPct, '0'), '.') . '% encargo de reativação (' . pedidozap_fmt_brl($encargoValor) . ') = ' . pedidozap_fmt_brl($valorTotal) . '.',
                'cta_texto' => 'Pagar ' . pedidozap_fmt_brl($valorTotal) . ' e tirar do Shadowban',
            ];
        }
    } elseif ($status === 'atrasado') {
        $multaFmt = rtrim(rtrim((string) $multaPctConfig, '0'), '.');
        $aviso = [
            'nivel' => 'urgente',
            'chave' => 'atraso_' . min($toleranciaMax, max(1, $diasAtraso)) . 'd_' . $vencimento,
            'titulo' => 'PAGAMENTO EM ATRASO — Dia ' . $diasAtraso . ' de ' . $toleranciaMax . ' de tolerância máxima',
            'mensagem' => 'Seu plano venceu em ' . $vencBr . '. Você tem no máximo até ' . $bloqueioBr . ' às 23:59 para pagar o valor normal (' . pedidozap_fmt_brl($valorNormal) . '). Após esses ' . $toleranciaMax . ' dias, sua página entra em shadowban (desaparece para compradores sem apagar seus dados) e será cobrado +' . $multaFmt . '% (' . pedidozap_fmt_brl($valorNormal * (1 + ($multaPctConfig / 100))) . ') para voltar.',
            'cta_texto' => 'Regularizar agora sem multa (' . pedidozap_fmt_brl($valorNormal) . ')',
        ];
    } elseif ($status === 'teste' || !empty($loja['em_teste'])) {
        if ($diasRestantes !== null) {
            $tituloT = $diasRestantes <= 0
                ? 'Último dia do teste grátis de 7 dias!'
                : 'Teste grátis: faltam ' . $diasRestantes . ' dia(s) (até ' . $vencBr . ')';
            $aviso = [
                'nivel' => $diasRestantes <= 2 ? 'aviso' : 'info',
                'chave' => ($diasRestantes <= 0 ? 'teste_hoje_' : ($diasRestantes <= 2 ? 'teste_2d_' : 'teste_ativo_')) . $vencimento,
                'titulo' => $tituloT,
                'mensagem' => 'Ao final do teste de 7 dias (' . $vencBr . '), a loja é ocultada para compradores até a escolha do plano (sem perder seus cadastros). Ative pagamento automático recorrente ou PIX/Cartão avulso antes do prazo.',
                'cta_texto' => 'Escolher plano agora (' . pedidozap_fmt_brl($valorNormal) . ')',
            ];
        }
    } elseif (!$automaticaAtiva && $diasRestantes !== null && $diasRestantes <= 5) {
        $tituloV = $diasRestantes === 0
            ? 'Sua mensalidade VENCE HOJE (' . $vencBr . ')!'
            : 'Aviso de prazo: sua mensalidade vence em ' . $diasRestantes . ' dia(s) (' . $vencBr . ')';
        $aviso = [
            'nivel' => $diasRestantes <= 1 ? 'aviso' : 'info',
            'chave' => ($diasRestantes === 0 ? 'venc_hoje_' : ($diasRestantes <= 2 ? 'venc_2d_' : 'venc_5d_')) . $vencimento,
            'titulo' => $tituloV,
            'mensagem' => 'Valor do ciclo: ' . pedidozap_fmt_brl($valorNormal) . '. Você pode pagar avulso ou ativar a renovação automática recorrente. Após o vencimento há tolerância máxima de ' . $toleranciaMax . ' dias (até ' . $bloqueioBr . ') antes do shadowban e acréscimo de 10%.',
            'cta_texto' => 'Pagar ou ativar recorrência',
        ];
    }

    return [
        'slug' => $slug,
        'status' => $status,
        'motivo' => $motivo,
        'shadowban' => $shadowban,
        'em_atraso' => $emAtraso,
        'dias_restantes' => $diasRestantes,
        'dias_atraso' => $diasAtraso,
        'tolerancia_max_dias' => $toleranciaMax,
        'tolerancia_restante_dias' => $tolRestante,
        'data_bloqueio_prevista' => $dataBloqueioPrevista,
        'encargo_reativacao_ativo' => $encargoAtivo,
        'encargo_reativacao_pct' => $encargoPct,
        'encargo_reativacao_valor' => $encargoValor,
        'valor_normal' => $valorNormal,
        'valor_total_cobrar' => $valorTotal,
        'modo_cobranca_preferido' => $modoPreferido,
        'automatica_ativa' => $automaticaAtiva,
        'aviso' => $aviso,
        'dados_preservados' => true,
    ];
}

function pedidozap_assinatura_publica($slug)
{
    $slug = strtolower(trim((string) $slug));
    if ($slug === '') {
        $slug = 'default';
    }
    $registro = pedidozap_ler_registro();
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
    $saas = pedidozap_saas_config();
    $planoInfo = pz_plano_da_loja($slug, $loja, $saas);
    $catalogo = pz_planos_catalogo($saas);
    $det = pedidozap_cobranca_detalhes($loja, $saas, $planoInfo['slug'], $planoInfo['ciclo']);
    $precoAtual = (float) ($planoInfo['preco_mensal'] ?? 79.90);
    $notifs = is_array($loja['cobranca_notificacoes'] ?? null) ? array_slice($loja['cobranca_notificacoes'], 0, 10) : [];

    return [
        'status' => $det['status'],
        'status_cadastro' => $loja['status'] ?? 'ativo',
        'motivo' => $det['motivo'],
        'shadowban' => $det['shadowban'],
        'em_atraso' => $det['em_atraso'],
        'dias_restantes' => $det['dias_restantes'],
        'dias_atraso' => $det['dias_atraso'],
        'tolerancia_max_dias' => $det['tolerancia_max_dias'],
        'tolerancia_restante_dias' => $det['tolerancia_restante_dias'],
        'data_bloqueio_prevista' => $det['data_bloqueio_prevista'],
        'encargo_reativacao_ativo' => $det['encargo_reativacao_ativo'],
        'encargo_reativacao_pct' => $det['encargo_reativacao_pct'],
        'encargo_reativacao_valor' => $det['encargo_reativacao_valor'],
        'valor_normal' => $det['valor_normal'],
        'valor_total_cobrar' => $det['valor_total_cobrar'],
        'modo_cobranca_preferido' => $det['modo_cobranca_preferido'],
        'automatica_ativa' => $det['automatica_ativa'],
        'cobranca_aviso' => $det['aviso'],
        'cobranca_notificacoes' => $notifs,
        'dados_preservados' => true,
        'em_teste' => !empty($loja['em_teste']),
        'plano' => $planoInfo['nome'],
        'plano_slug' => $planoInfo['slug'],
        'plano_nome' => $planoInfo['nome'],
        'plano_subtitulo' => $planoInfo['subtitulo'],
        'plano_badge' => $planoInfo['badge'],
        'ciclo' => $planoInfo['ciclo'],
        'grupo_rede' => $planoInfo['grupo_rede'],
        'limites' => $planoInfo['limites'],
        'recursos' => $planoInfo['recursos'],
        'planos_catalogo' => array_values($catalogo),
        'desconto_anual_pct' => (int) ($saas['desconto_anual_pct'] ?? 20),
        'dias_teste_gratis' => max(1, min(30, (int) ($saas['dias_teste_gratis'] ?? 30))),
        'multa_reativacao_pct' => (float) ($saas['multa_reativacao_pct'] ?? 10),
        'vencimento' => $loja['vencimento'] ?? '',
        'nicho' => $loja['nicho'] ?? ($loja['NICHO'] ?? ''),
        'suporte_whatsapp' => $saas['suporte_whatsapp'] ?? '',
        'nome_plataforma' => $saas['nome_plataforma'] ?? 'AcheIAki',
        'mensalidade_valor' => $precoAtual,
        'pagamento_online' => !empty($saas['mp_access_token']) && $precoAtual > 0,
        'assinatura_status' => $loja['assinatura_status'] ?? '',
        'ultimo_pagamento' => $loja['ultimo_pagamento'] ?? '',
    ];
}

function pedidozap_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $saas = pedidozap_saas_config();
    $envHost = function_exists('pz_db_env_val') ? pz_db_env_val('RAILWAY_PUBLIC_DOMAIN') : '';
    $host = !empty($saas['dominio_base'])
        ? $saas['dominio_base']
        : ($_SERVER['HTTP_HOST'] ?? ($envHost !== '' ? $envHost : 'localhost'));
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = ($script === '' || strpos($script, '/') === false) ? '' : rtrim(dirname($script), '/.');
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }
    if (!empty($saas['dominio_base']) || $envHost !== '') {
        $https = true;
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/**
 * Chamada à API do Mercado Pago com o token guardado só no servidor.
 * Retorna [codigoHttp, respostaDecodificada].
 */
function pedidozap_mp_request($metodo, $caminho, $corpo = null)
{
    $token = (string) (pedidozap_saas_config()['mp_access_token'] ?? '');
    if ($token === '') {
        return [0, ['message' => 'Mercado Pago não configurado.']];
    }
    if (!function_exists('curl_init')) {
        return [0, ['message' => 'Extensão cURL indisponível no servidor.']];
    }
    $ch = curl_init('https://api.mercadopago.com' . $caminho);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    if ($metodo === 'POST') {
        $idem = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : sha1(uniqid('mp', true));
        $headers[] = 'X-Idempotency-Key: ' . $idem;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }
    $resposta = curl_exec($ch);
    $curlErr = curl_error($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resposta === false) {
        return [$codigo, ['message' => 'Falha de comunicação com Mercado Pago' . ($curlErr !== '' ? ': ' . $curlErr : '.')]];
    }
    $dados = json_decode((string) $resposta, true);
    return [$codigo, is_array($dados) ? $dados : []];
}

function pedidozap_mp_criar_cobranca($slug, $email, $planoSlug = null, $ciclo = 'mensal', $modo = 'recorrente')
{
    $slug = strtolower(trim((string) $slug));
    $saas = pedidozap_saas_config();
    $registro = pedidozap_ler_registro();
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
    $planoFinal = pz_plano_normalizar($planoSlug ?: ($loja['plano_escolhido'] ?? $loja['plano'] ?? 'pro'));
    $cicloFinal = strtolower(trim((string) $ciclo)) === 'anual' ? 'anual' : 'mensal';
    $modoFinal = strtolower(trim((string) $modo)) === 'avulso' ? 'avulso' : 'recorrente';

    $det = pedidozap_cobranca_detalhes($loja, $saas, $planoFinal, $cicloFinal);
    if (!empty($det['encargo_reativacao_ativo'])) {
        // Após 2 dias de atraso (shadowban), a reativação exige pagamento único com os 10% sobre o plano.
        return pedidozap_mp_criar_pagamento_avulso($slug, $email, $planoFinal, $cicloFinal, true);
    }
    if ($modoFinal === 'avulso') {
        return pedidozap_mp_criar_pagamento_avulso($slug, $email, $planoFinal, $cicloFinal, false);
    }
    return pedidozap_mp_criar_assinatura($slug, $email, $planoFinal, $cicloFinal);
}

function pedidozap_mp_criar_pagamento_avulso($slug, $email, $planoSlug = null, $ciclo = 'mensal', $forcarReativacao = null)
{
    $slug = strtolower(trim((string) $slug));
    $email = trim((string) $email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Informe um e-mail válido do responsável para gerar a cobrança.'];
    }
    $saas = pedidozap_saas_config();
    $registro = pedidozap_ler_registro();
    if ($slug === '' || (!isset($registro[$slug]) && !Database::tenantDataExists($slug))) {
        return ['success' => false, 'message' => 'Loja não encontrada para gerar a cobrança.'];
    }
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
    $planoFinal = pz_plano_normalizar($planoSlug ?: ($loja['plano_escolhido'] ?? $loja['plano'] ?? 'pro'));
    $cicloFinal = strtolower(trim((string) $ciclo)) === 'anual' ? 'anual' : 'mensal';
    $cat = pz_planos_catalogo($saas);
    $planoDef = $cat[$planoFinal] ?? $cat['pro'];

    $det = pedidozap_cobranca_detalhes($loja, $saas, $planoFinal, $cicloFinal);
    $comEncargo = $forcarReativacao === null ? !empty($det['encargo_reativacao_ativo']) : (bool) $forcarReativacao;
    $valorBase = (float) $det['valor_normal'];
    $multaPct = $comEncargo ? max(0.0, min(10.0, (float) ($saas['multa_reativacao_pct'] ?? 10))) : 0.0;
    $valorEncargo = $comEncargo ? round($valorBase * ($multaPct / 100.0), 2) : 0.0;
    $valorTotal = round($valorBase + $valorEncargo, 2);

    $token = (string) ($saas['mp_access_token'] ?? '');
    if ($valorTotal <= 0 || $token === '') {
        return ['success' => false, 'message' => 'O pagamento online ainda não foi ativado pela plataforma. Fale com o suporte.'];
    }

    $nome = (string) ($loja['nome_empresa'] ?? $slug);
    $plat = (string) ($saas['nome_plataforma'] ?: 'AcheIAki');
    $nonce = substr(bin2hex(random_bytes(4)), 0, 8);
    $extRef = 'pay|' . $slug . '|' . $planoFinal . '|' . $cicloFinal . '|' . $nonce . '|' . ($comEncargo ? (string) $multaPct : '0');

    $itensMp = [
        [
            'id' => 'acheiaki-' . $planoFinal . '-' . $cicloFinal,
            'title' => $plat . ' - Plano ' . $planoDef['nome'] . ' (' . ($cicloFinal === 'anual' ? 'Anual' : 'Mensal') . ') - ' . $nome,
            'description' => 'Assinatura de software sem comissão sobre vendas (' . $slug . ')',
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => $valorBase,
        ],
    ];
    if ($comEncargo && $valorEncargo > 0) {
        $itensMp[] = [
            'id' => 'acheiaki-reativacao-10pct',
            'title' => 'Encargo de reativação por atraso superior a ' . (int) ($det['tolerancia_max_dias'] ?? 2) . ' dias (' . rtrim(rtrim((string) $multaPct, '0'), '.') . '%)',
            'description' => 'Taxa contratual de reativação de vitrine após shadowban por inadimplência',
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => $valorEncargo,
        ];
    }

    $retornoBase = pedidozap_base_url() . '/admin.html?assinatura=retorno' . ($slug !== 'default' ? '&tenant=' . rawurlencode($slug) : '');
    [$codigo, $dados] = pedidozap_mp_request('POST', '/checkout/preferences', [
        'items' => $itensMp,
        'payer' => ['email' => $email],
        'external_reference' => $extRef,
        'notification_url' => pedidozap_base_url() . '/mp_webhook.php',
        'back_urls' => [
            'success' => $retornoBase . '&mp_status=approved',
            'pending' => $retornoBase . '&mp_status=pending',
            'failure' => $retornoBase . '&mp_status=failure',
        ],
        'auto_return' => 'approved',
        'statement_descriptor' => 'ACHEIAKI SAAS',
        'metadata' => [
            'slug' => $slug,
            'plano' => $planoFinal,
            'ciclo' => $cicloFinal,
            'tipo' => $comEncargo ? 'reativacao' : 'avulso',
            'valor_base' => $valorBase,
            'encargo_pct' => $multaPct,
            'valor_encargo' => $valorEncargo,
            'valor_total' => $valorTotal,
        ],
    ]);

    $urlCheckout = (strpos($token, 'TEST-') === 0 && !empty($dados['sandbox_init_point']))
        ? (string) $dados['sandbox_init_point']
        : (string) ($dados['init_point'] ?? '');
    if ($codigo < 200 || $codigo >= 300 || $urlCheckout === '') {
        $msg = $dados['message'] ?? 'O Mercado Pago recusou a criação da cobrança.';
        return ['success' => false, 'message' => 'Mercado Pago: ' . $msg];
    }

    pedidozap_salvar_registro_loja($slug, [
        'mp_pref_id' => (string) ($dados['id'] ?? ''),
        'mp_ext_ref_pendente' => $extRef,
        'assinatura_email' => $email,
        'plano_pendente' => $planoFinal,
        'ciclo_pendente' => $cicloFinal,
        'plano_escolhido' => $planoFinal,
        'valor_pendente_cobrar' => $valorTotal,
        'modo_cobranca_preferido' => 'avulso',
    ]);

    return [
        'success' => true,
        'url' => $urlCheckout,
        'modo' => $comEncargo ? 'reativacao' : 'avulso',
        'reativacao' => $comEncargo,
        'plano' => $planoFinal,
        'ciclo' => $cicloFinal,
        'valor' => $valorTotal,
        'valor_normal' => $valorBase,
        'encargo_valor' => $valorEncargo,
    ];
}

function pedidozap_mp_criar_assinatura($slug, $email, $planoSlug = null, $ciclo = 'mensal')
{
    $slug = strtolower(trim((string) $slug));
    $email = trim((string) $email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Informe um e-mail válido do responsável para ativar a renovação automática.'];
    }
    $saas = pedidozap_saas_config();
    $registro = pedidozap_ler_registro();
    if ($slug === '' || (!isset($registro[$slug]) && !Database::tenantDataExists($slug))) {
        return ['success' => false, 'message' => 'Loja não encontrada para ativar a assinatura.'];
    }
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
    $planoFinal = pz_plano_normalizar($planoSlug ?: ($loja['plano_escolhido'] ?? $loja['plano'] ?? 'pro'));
    $cicloFinal = strtolower(trim((string) $ciclo)) === 'anual' ? 'anual' : 'mensal';
    $cat = pz_planos_catalogo($saas);
    $planoDef = $cat[$planoFinal] ?? $cat['pro'];
    $valor = pz_plano_preco_ciclo($planoFinal, $cicloFinal, $saas);
    if ($valor <= 0 || empty($saas['mp_access_token'])) {
        return ['success' => false, 'message' => 'O pagamento online ainda não foi ativado pela plataforma. Fale com o suporte.'];
    }
    $det = pedidozap_cobranca_detalhes($loja, $saas, $planoFinal, $cicloFinal);
    if (!empty($det['encargo_reativacao_ativo'])) {
        return pedidozap_mp_criar_pagamento_avulso($slug, $email, $planoFinal, $cicloFinal, true);
    }
    $nome = $loja['nome_empresa'] ?? $slug;

    $retorno = pedidozap_base_url() . '/admin.html?assinatura=retorno' . ($slug !== 'default' ? '&tenant=' . rawurlencode($slug) : '');
    $extSubRef = 'sub|' . $slug . '|' . $planoFinal . '|' . $cicloFinal;
    [$codigo, $dados] = pedidozap_mp_request('POST', '/preapproval', [
        'reason' => ($saas['nome_plataforma'] ?: 'AcheIAki') . ' - Plano ' . $planoDef['nome'] . ' (' . ($cicloFinal === 'anual' ? 'Anual' : 'Mensal') . ') - ' . $nome,
        'external_reference' => $extSubRef,
        'payer_email' => $email,
        'back_url' => $retorno,
        'auto_recurring' => [
            'frequency' => $cicloFinal === 'anual' ? 12 : 1,
            'frequency_type' => 'months',
            'transaction_amount' => $valor,
            'currency_id' => 'BRL',
        ],
        'status' => 'pending',
    ]);
    if ($codigo < 200 || $codigo >= 300 || empty($dados['init_point'])) {
        $msg = $dados['message'] ?? 'O Mercado Pago recusou a criação da assinatura.';
        return ['success' => false, 'message' => 'Mercado Pago: ' . $msg];
    }
    pedidozap_salvar_registro_loja($slug, [
        'assinatura_id' => (string) ($dados['id'] ?? ''),
        'assinatura_status' => (string) ($dados['status'] ?? 'pending'),
        'assinatura_email' => $email,
        'plano_pendente' => $planoFinal,
        'ciclo_pendente' => $cicloFinal,
        'plano_escolhido' => $planoFinal,
        'modo_cobranca_preferido' => 'recorrente',
    ]);
    return ['success' => true, 'url' => $dados['init_point'], 'modo' => 'recorrente', 'plano' => $planoFinal, 'ciclo' => $cicloFinal, 'valor' => $valor];
}

function pedidozap_mp_cancelar_assinatura($slug)
{
    $slug = strtolower(trim((string) $slug));
    if ($slug === '') {
        return ['success' => false, 'message' => 'Loja não informada.'];
    }
    $registro = pedidozap_ler_registro();
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
    $preId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($loja['assinatura_id'] ?? ''));
    if ($preId === '') {
        pedidozap_salvar_registro_loja($slug, ['assinatura_status' => 'cancelled', 'modo_cobranca_preferido' => 'avulso']);
        return ['success' => true, 'message' => 'Renovação automática desativada.'];
    }
    [$codigo, $res] = pedidozap_mp_request('PUT', '/preapproval/' . $preId, ['status' => 'cancelled']);
    if ($codigo < 200 || $codigo >= 300) {
        $msg = (string) ($res['message'] ?? 'Não foi possível cancelar a renovação automática no Mercado Pago.');
        return ['success' => false, 'message' => $msg];
    }
    $notifs = pedidozap_registrar_aviso_lista(
        $loja['cobranca_notificacoes'] ?? [],
        'cancel_rec_' . date('Y-m-d_H'),
        'info',
        'Renovação automática cancelada',
        'A cobrança automática foi encerrada. Seu acesso permanece ativo até o vencimento atual.'
    );
    pedidozap_salvar_registro_loja($slug, [
        'assinatura_status' => 'cancelled',
        'modo_cobranca_preferido' => 'avulso',
        'cobranca_notificacoes' => $notifs,
    ]);
    return ['success' => true, 'message' => 'Renovação automática cancelada. Sua loja continua ativa até o vencimento já pago.'];
}

/**
 * Consulta pagamento único (Checkout Pro / Reativação / Avulso) no Mercado Pago.
 */
function pedidozap_mp_sincronizar_pagamento($paymentId, $slugEsperado = null)
{
    $paymentId = preg_replace('/[^0-9]/', '', (string) $paymentId);
    if ($paymentId === '') {
        return ['success' => false, 'message' => 'Pagamento não identificado.'];
    }
    [$codigo, $pag] = pedidozap_mp_request('GET', '/v1/payments/' . $paymentId);
    if ($codigo !== 200 || empty($pag['id'])) {
        return ['success' => false, 'message' => 'Não foi possível consultar o pagamento no Mercado Pago.'];
    }

    $extRef = (string) ($pag['external_reference'] ?? '');
    $metaPag = is_array($pag['metadata'] ?? null) ? $pag['metadata'] : [];
    $slug = strtolower(trim((string) ($metaPag['slug'] ?? '')));
    $planoPag = (string) ($metaPag['plano'] ?? '');
    $cicloPag = (string) ($metaPag['ciclo'] ?? '');
    $encargoPctPag = isset($metaPag['encargo_pct']) ? (float) $metaPag['encargo_pct'] : null;

    if (strpos($extRef, 'pay|') === 0) {
        $partes = explode('|', $extRef);
        if ($slug === '' && !empty($partes[1])) {
            $slug = strtolower(trim((string) $partes[1]));
        }
        if ($planoPag === '' && !empty($partes[2])) {
            $planoPag = (string) $partes[2];
        }
        if ($cicloPag === '' && !empty($partes[3])) {
            $cicloPag = (string) $partes[3];
        }
        if ($encargoPctPag === null && isset($partes[5])) {
            $encargoPctPag = (float) $partes[5];
        }
    }

    if ($slug === '' || ($slugEsperado !== null && $slug !== $slugEsperado)) {
        return ['success' => false, 'message' => 'Pagamento não pertence a esta loja.'];
    }
    $registro = pedidozap_ler_registro();
    if (!isset($registro[$slug]) && !Database::tenantDataExists($slug)) {
        return ['success' => false, 'message' => 'Loja desconhecida.'];
    }
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
    $statusPag = (string) ($pag['status'] ?? '');

    if ($statusPag !== 'approved') {
        pedidozap_salvar_registro_loja($slug, [
            'ultimo_mp_payment_status' => $statusPag,
        ]);
        return ['success' => true, 'slug' => $slug, 'status' => $statusPag, 'pago' => false];
    }

    $processados = is_array($loja['mp_pagamentos_processados'] ?? null) ? $loja['mp_pagamentos_processados'] : [];
    if (in_array((string) $paymentId, $processados, true)) {
        return ['success' => true, 'slug' => $slug, 'status' => 'approved', 'pago' => true, 'ja_processado' => true];
    }

    $saas = pedidozap_saas_config();
    $planoFinal = pz_plano_normalizar($planoPag !== '' ? $planoPag : ($loja['plano_pendente'] ?? $loja['plano_escolhido'] ?? $loja['plano'] ?? 'pro'));
    $cicloFinal = strtolower(trim($cicloPag !== '' ? $cicloPag : (string) ($loja['ciclo_pendente'] ?? $loja['ciclo_cobranca'] ?? 'mensal'))) === 'anual' ? 'anual' : 'mensal';

    $dataAprov = (string) ($pag['date_approved'] ?? $pag['date_last_updated'] ?? date('c'));
    $pagoTs = strtotime($dataAprov) ?: time();

    $det = pedidozap_cobranca_detalhes($loja, $saas, $planoFinal, $cicloFinal);
    $limiteGraceTs = !empty($det['data_bloqueio_prevista']) ? strtotime($det['data_bloqueio_prevista'] . ' 23:59:59') : false;
    $exigirEncargo = !empty($det['encargo_reativacao_ativo']) && !($limiteGraceTs && $pagoTs <= $limiteGraceTs);
    $valorEsperado = $exigirEncargo ? (float) $det['valor_total_cobrar'] : (float) $det['valor_normal'];
    $valorPago = (float) ($pag['transaction_amount'] ?? 0);
    if ($valorPago + 0.05 < $valorEsperado) {
        return ['success' => false, 'message' => 'Valor pago (' . pedidozap_fmt_brl($valorPago) . ') inferior ao devido (' . pedidozap_fmt_brl($valorEsperado) . ').'];
    }

    $jaProcessado = false;
    $anterior = Database::getTenant();
    try {
        Database::setTenant('default');
        Database::mutate('tenants_registry.json', function (&$reg) use (
            $slug,
            $paymentId,
            $planoFinal,
            $cicloFinal,
            $pagoTs,
            $valorPago,
            $det,
            &$jaProcessado
        ) {
            if (!is_array($reg)) {
                $reg = [];
            }
            $cur = is_array($reg[$slug] ?? null) ? $reg[$slug] : [];
            $procAtual = is_array($cur['mp_pagamentos_processados'] ?? null) ? $cur['mp_pagamentos_processados'] : [];
            if (in_array((string) $paymentId, $procAtual, true)) {
                $jaProcessado = true;
                return false;
            }
            $atualTs = pedidozap_parse_data_iso(trim((string) ($cur['vencimento'] ?? '')));
            $hojeTs = strtotime(date('Y-m-d') . ' 00:00:00');
            $baseRenov = ($atualTs !== null && $atualTs > $hojeTs) ? $atualTs : $pagoTs;
            $novoVenc = date('Y-m-d', pedidozap_somar_ciclo($baseRenov, $cicloFinal));

            $procAtual[] = (string) $paymentId;
            $procAtual = array_slice($procAtual, -20);
            $notifs = pedidozap_registrar_aviso_lista(
                is_array($cur['cobranca_notificacoes'] ?? null) ? $cur['cobranca_notificacoes'] : [],
                'pag_ok_' . $paymentId,
                'ok',
                !empty($det['encargo_reativacao_ativo']) ? 'Loja reativada e liberada do shadowban!' : 'Pagamento confirmado!',
                'Pagamento #' . $paymentId . ' aprovado (' . pedidozap_fmt_brl($valorPago) . '). Novo vencimento: ' . date('d/m/Y', strtotime($novoVenc)) . '.'
            );
            $reg[$slug] = array_merge($cur, [
                'slug' => $slug,
                'status' => 'ativo',
                'bloqueio_motivo' => '',
                'em_teste' => false,
                'shadowban_ativo' => false,
                'taxa_reativacao_pendente' => false,
                'plano' => $planoFinal,
                'plano_escolhido' => $planoFinal,
                'ciclo_cobranca' => $cicloFinal,
                'vencimento' => $novoVenc,
                'ultimo_pagamento' => date('Y-m-d', $pagoTs),
                'ultimo_mp_payment_id' => (string) $paymentId,
                'ultimo_mp_payment_status' => 'approved',
                'mp_pagamentos_processados' => $procAtual,
                'cobranca_notificacoes' => $notifs,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        });
    } finally {
        Database::setTenant($anterior);
    }
    if ($jaProcessado) {
        return ['success' => true, 'slug' => $slug, 'status' => 'approved', 'pago' => true, 'ja_processado' => true];
    }
    if (function_exists('pz_cache_del')) {
        pz_cache_del('pub_store_json_' . $slug);
        pz_cache_del('mkt_lojas_all');
    }

    return ['success' => true, 'slug' => $slug, 'status' => 'approved', 'pago' => true];
}

function pedidozap_mp_buscar_ultimo_pagamento($slug)
{
    $slug = strtolower(trim((string) $slug));
    $registro = pedidozap_ler_registro();
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
    $extRef = trim((string) ($loja['mp_ext_ref_pendente'] ?? ''));
    if ($extRef === '') {
        return ['success' => false, 'message' => 'Nenhum pagamento avulso pendente encontrado.'];
    }
    [$codigo, $res] = pedidozap_mp_request(
        'GET',
        '/v1/payments/search?external_reference=' . rawurlencode($extRef) . '&sort=date_created&criteria=desc&limit=5'
    );
    if ($codigo !== 200 || empty($res['results']) || !is_array($res['results'])) {
        return ['success' => false, 'message' => 'Ainda não localizamos pagamento aprovado para esta cobrança.'];
    }
    foreach ($res['results'] as $item) {
        if (is_array($item) && !empty($item['id']) && ($item['status'] ?? '') === 'approved') {
            return pedidozap_mp_sincronizar_pagamento((string) $item['id'], $slug);
        }
    }
    $st = (string) ($res['results'][0]['status'] ?? 'pending');
    return ['success' => true, 'slug' => $slug, 'status' => $st, 'pago' => false];
}

/**
 * Consulta a assinatura no Mercado Pago e atualiza vencimento/status da loja.
 * Nunca confia no corpo do webhook: sempre relê da API.
 */
function pedidozap_mp_sincronizar_assinatura($preapprovalId, $slugEsperado = null)
{
    $preapprovalId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $preapprovalId);
    if ($preapprovalId === '') {
        return ['success' => false, 'message' => 'Assinatura não encontrada.'];
    }
    [$codigo, $pre] = pedidozap_mp_request('GET', '/preapproval/' . $preapprovalId);
    if ($codigo !== 200 || empty($pre['id'])) {
        return ['success' => false, 'message' => 'Não foi possível consultar a assinatura no Mercado Pago.'];
    }
    $extPre = strtolower(trim((string) ($pre['external_reference'] ?? '')));
    $slug = $extPre;
    $planoPre = '';
    $cicloPre = '';
    if (strpos($extPre, 'sub|') === 0) {
        $partesSub = explode('|', $extPre);
        $slug = strtolower(trim((string) ($partesSub[1] ?? '')));
        $planoPre = (string) ($partesSub[2] ?? '');
        $cicloPre = (string) ($partesSub[3] ?? '');
    }
    if ($slug === '' || ($slugEsperado !== null && $slug !== strtolower(trim((string) $slugEsperado)))) {
        return ['success' => false, 'message' => 'Assinatura de outra loja.'];
    }
    $registro = pedidozap_ler_registro();
    if (!isset($registro[$slug]) && !Database::tenantDataExists($slug)) {
        return ['success' => false, 'message' => 'Loja desconhecida.'];
    }
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];

    $status = (string) ($pre['status'] ?? '');
    $meta = [
        'assinatura_id' => (string) $pre['id'],
        'assinatura_status' => $status,
    ];

    $ultimaCobranca = (string) ($pre['summarized']['last_charged_date'] ?? '');
    $cobrancas = (int) ($pre['summarized']['charged_quantity'] ?? 0);
    if ($status === 'authorized' && $cobrancas > 0 && $ultimaCobranca !== '') {
        $pago = strtotime($ultimaCobranca);
        $candidatos = [];
        $cicloPago = $cicloPre !== '' ? $cicloPre : (string) ($loja['ciclo_pendente'] ?? $loja['ciclo_cobranca'] ?? 'mensal');
        if ($pago) {
            $candidatos[] = pedidozap_somar_ciclo($pago, $cicloPago);
            $meta['ultimo_pagamento'] = date('Y-m-d', $pago);
        }
        $proxima = strtotime((string) ($pre['next_payment_date'] ?? ''));
        if ($proxima) {
            $candidatos[] = $proxima;
        }
        if ($candidatos) {
            $novo = max($candidatos);
            $atual = pedidozap_parse_data_iso(trim((string) ($loja['vencimento'] ?? '')));
            $meta['vencimento'] = date('Y-m-d', ($atual !== null && $atual > $novo) ? $atual : $novo);
        }
        $meta['status'] = 'ativo';
        $meta['bloqueio_motivo'] = '';
        $meta['em_teste'] = false;
        $meta['shadowban_ativo'] = false;
        $meta['taxa_reativacao_pendente'] = false;
        $meta['modo_cobranca_preferido'] = 'recorrente';
        $planoAtivo = pz_plano_normalizar($planoPre !== '' ? $planoPre : (string) ($loja['plano_pendente'] ?? $loja['plano_escolhido'] ?? $loja['plano'] ?? 'pro'));
        $meta['plano'] = $planoAtivo;
        $meta['plano_escolhido'] = $planoAtivo;
        $meta['ciclo_cobranca'] = strtolower(trim($cicloPago)) === 'anual' ? 'anual' : 'mensal';
        $meta['cobranca_notificacoes'] = pedidozap_registrar_aviso_lista(
            $loja['cobranca_notificacoes'] ?? [],
            'rec_ok_' . ($meta['ultimo_pagamento'] ?? date('Y-m-d')),
            'ok',
            'Cobrança automática confirmada',
            'Assinatura recorrente renovada. Próximo vencimento em ' . (!empty($meta['vencimento']) ? date('d/m/Y', strtotime($meta['vencimento'])) : '') . '.'
        );
    } elseif (in_array($status, ['cancelled', 'paused'], true) && (($loja['assinatura_status'] ?? '') === 'authorized')) {
        $meta['cobranca_notificacoes'] = pedidozap_registrar_aviso_lista(
            $loja['cobranca_notificacoes'] ?? [],
            'rec_off_' . $status . '_' . date('Y-m-d'),
            'aviso',
            'Cobrança automática ' . ($status === 'paused' ? 'pausada' : 'cancelada'),
            'Sua assinatura automática mudou de status no Mercado Pago. Confira na aba Meu Plano para não ficar em atraso.'
        );
    }

    pedidozap_salvar_registro_loja($slug, $meta);
    return ['success' => true, 'slug' => $slug, 'status' => $status, 'pago' => isset($meta['vencimento'])];
}

/**
 * Varredura periódica de vencimentos (executada pelo worker):
 * aplica marcação de shadowban / taxa de 10% após 2 dias, grava histórico de avisos e limpa caches.
 */
function pedidozap_varrer_vencimentos_e_avisos()
{
    $saas = pedidozap_saas_config();
    $registro = pedidozap_ler_registro();
    if (!is_array($registro) || empty($registro)) {
        return ['lojas' => 0, 'atualizadas' => 0];
    }
    $atualizadas = 0;
    foreach ($registro as $slug => $loja) {
        if (!is_array($loja)) {
            continue;
        }
        $det = pedidozap_cobranca_detalhes($loja, $saas);
        $patch = [];
        $mudouShadowban = (!empty($det['shadowban']) !== !empty($loja['shadowban_ativo']));
        if ($mudouShadowban) {
            $patch['shadowban_ativo'] = (bool) $det['shadowban'];
            if ($det['shadowban']) {
                $patch['shadowban_desde'] = date('Y-m-d H:i:s');
            }
        }
        if (!empty($det['encargo_reativacao_ativo']) && empty($loja['taxa_reativacao_pendente'])) {
            $patch['taxa_reativacao_pendente'] = true;
        } elseif (empty($det['encargo_reativacao_ativo']) && !empty($loja['taxa_reativacao_pendente']) && $det['status'] !== 'bloqueado') {
            $patch['taxa_reativacao_pendente'] = false;
        }
        if (!empty($det['aviso']['chave'])) {
            $antes = is_array($loja['cobranca_notificacoes'] ?? null) ? $loja['cobranca_notificacoes'] : [];
            $depois = pedidozap_registrar_aviso_lista(
                $antes,
                $det['aviso']['chave'],
                $det['aviso']['nivel'],
                $det['aviso']['titulo'],
                $det['aviso']['mensagem']
            );
            if (count($depois) !== count($antes) || ($depois[0]['chave'] ?? '') !== ($antes[0]['chave'] ?? '')) {
                $patch['cobranca_notificacoes'] = $depois;
            }
        }
        if (!empty($patch)) {
            pedidozap_salvar_registro_loja($slug, $patch);
            $atualizadas++;
        }
    }
    return ['lojas' => count($registro), 'atualizadas' => $atualizadas];
}

function pedidozap_recusar_loja_bloqueada($user, $pass)
{
    if (pedidozap_autorizado($user, $pass) === 'master') {
        return;
    }
    if (!pedidozap_loja_bloqueada(Database::getTenant())) {
        return;
    }
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'shadowban' => true,
        'message' => 'A mensalidade desta loja ultrapassou o prazo e a loja está em shadowban/bloqueio (todos os seus dados permanecem salvos). Regularize na aba Meu Plano para voltar ao ar.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function pedidozap_slugify($nome)
{
    $bruto = trim((string) $nome);
    $texto = function_exists('mb_strtolower') ? mb_strtolower($bruto, 'UTF-8') : strtolower($bruto);
    $texto = strtr($texto, [
        'á' => 'a',
        'à' => 'a',
        'ã' => 'a',
        'â' => 'a',
        'ä' => 'a',
        'é' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'í' => 'i',
        'ï' => 'i',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ö' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ç' => 'c',
        'ñ' => 'n',
    ]);
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    $texto = trim((string) $texto, '-');
    $texto = substr($texto, 0, 40);
    $reservados = ['default', 'www', 'loja', 'cadastro', 'admin', 'superadmin', 'app', 'mail', 'api'];
    if ($texto === '' || in_array($texto, $reservados, true)) {
        $texto = 'minha-loja';
    }
    $registro = pedidozap_ler_registro();
    $base = $texto;
    $n = 2;
    while (Database::tenantDataExists($texto) || isset($registro[$texto])) {
        $texto = $base . '-' . $n;
        $n++;
        if ($n > 200) {
            $texto = $base . '-' . substr(uniqid(), -4);
            break;
        }
    }
    return $texto;
}

function pedidozap_criar_loja(array $dados)
{
    require_once __DIR__ . '/store_schema.php';
    require_once __DIR__ . '/auth_lib.php';
    require_once __DIR__ . '/planos_lib.php';
    require_once __DIR__ . '/integracoes_lib.php';

    $nome = trim((string) ($dados['nome_empresa'] ?? ''));
    $login = trim((string) ($dados['login'] ?? 'admin'));
    $senha = (string) ($dados['senha'] ?? '');
    $nicho = (string) ($dados['nicho'] ?? 'lanchonete');
    $perfil = is_array($dados['perfil'] ?? null) ? $dados['perfil'] : null;

    $cnpj = preg_replace('/\D/', '', (string) ($dados['cnpj'] ?? ($perfil['cnpj'] ?? '')));
    $razaoSocial = trim(strip_tags((string) ($dados['razao_social'] ?? ($perfil['razao_social'] ?? ''))));
    $whatsapp = preg_replace('/\D/', '', (string) ($dados['whatsapp'] ?? ($perfil['telefone'] ?? '')));
    $cep = preg_replace('/\D/', '', (string) ($dados['cep'] ?? ($perfil['endereco']['cep'] ?? '')));
    $rua = trim(strip_tags((string) ($dados['rua'] ?? ($perfil['endereco']['rua'] ?? ''))));
    $numero = trim(strip_tags((string) ($dados['numero'] ?? ($perfil['endereco']['numero'] ?? ''))));
    $bairro = trim(strip_tags((string) ($dados['bairro'] ?? ($perfil['endereco']['bairro'] ?? ''))));
    $cidade = trim(strip_tags((string) ($dados['cidade'] ?? ($perfil['endereco']['cidade'] ?? ''))));
    $estado = strtoupper(trim(strip_tags((string) ($dados['estado'] ?? ($perfil['endereco']['estado'] ?? '')))));
    $raioKm = isset($dados['raio_entrega_km']) && is_numeric($dados['raio_entrega_km']) ? (float) $dados['raio_entrega_km'] : 10.0;
    $tempoMin = isset($dados['tempo_medio_min']) && is_numeric($dados['tempo_medio_min']) ? (int) $dados['tempo_medio_min'] : 35;
    $taxaBase = isset($dados['taxa_entrega_base']) && is_numeric($dados['taxa_entrega_base']) ? max(0.0, (float) $dados['taxa_entrega_base']) : 0.0;

    $lenStr = static function ($txt) {
        return function_exists('mb_strlen') ? mb_strlen((string) $txt, 'UTF-8') : strlen((string) $txt);
    };
    $exigirCompleto = !empty($dados['exigir_cadastro_completo']);
    if ($exigirCompleto) {
        if ($lenStr($nome) < 3) {
            return ['success' => false, 'message' => 'Informe o nome completo do estabelecimento (mínimo 3 caracteres).'];
        }
        if (!pedidozap_validar_cnpj($cnpj)) {
            return ['success' => false, 'message' => 'CNPJ obrigatório inválido. Confira os 14 dígitos do CNPJ da empresa.'];
        }
        if (strlen($whatsapp) < 10) {
            return ['success' => false, 'message' => 'WhatsApp da loja obrigatório com DDD (mínimo 10 dígitos).'];
        }
        if (strlen($cep) !== 8 || $lenStr($rua) < 3 || $numero === '' || $lenStr($bairro) < 2 || $lenStr($cidade) < 2 || strlen($estado) !== 2) {
            return ['success' => false, 'message' => 'Preencha o endereço completo obrigatório da empresa (CEP com 8 dígitos, Rua, Número, Bairro, Cidade e UF).'];
        }
        if ($raioKm < 1 || $raioKm > 300) {
            return ['success' => false, 'message' => 'Informe o raio máximo de entrega entre 1 km e 300 km (ex.: 10 km ou 100 km).'];
        }
        if ($tempoMin < 10 || $tempoMin > 180) {
            return ['success' => false, 'message' => 'Informe o tempo estimado de entrega entre 10 e 180 minutos.'];
        }
    }

    $slug = strtolower(trim((string) ($dados['slug'] ?? '')));
    if ($slug === '') {
        $slug = pedidozap_slugify($nome);
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) || $slug === 'default') {
        return ['success' => false, 'message' => 'O endereço da loja precisa ter só letras minúsculas, números e hífen.'];
    }
    $tamanhoNome = $lenStr($nome);
    $tamanhoSenha = $lenStr($senha);
    if ($tamanhoNome < 3 || $senha === '' || $tamanhoSenha < 6) {
        return ['success' => false, 'message' => 'Informe o nome da loja e uma senha com pelo menos 6 caracteres.'];
    }
    if ($login === '') {
        $login = 'admin';
    }
    $regExist = pedidozap_ler_registro();
    if (Database::tenantDataExists($slug) || isset($regExist[$slug])) {
        return ['success' => false, 'message' => 'Já existe uma loja com esse endereço.'];
    }

    // Evita duplicidade de CNPJ em lojas ativas
    if ($cnpj !== '') {
        foreach ($regExist as $mExist) {
            if (is_array($mExist) && preg_replace('/\D/', '', (string) ($mExist['cnpj'] ?? '')) === $cnpj) {
                return ['success' => false, 'message' => 'Este CNPJ já está cadastrado no sistema. Entre no painel da sua loja ou fale com o suporte.'];
            }
        }
    }

    $presets = pedidozap_nicho_presets();
    $preset = $presets[$nicho] ?? $presets['lanchonete'];
    $nicho = isset($presets[$nicho]) ? $nicho : 'lanchonete';
    if ($perfil && !empty($perfil['nicho_sugerido']) && isset($presets[$perfil['nicho_sugerido']]) && empty($dados['nicho'])) {
        $nicho = $perfil['nicho_sugerido'];
        $preset = $presets[$nicho];
    }

    $pagamentos = is_array($dados['pagamentos'] ?? null) ? $dados['pagamentos'] : [];
    $pagFinal = [
        'pix' => array_key_exists('pix', $pagamentos) ? !empty($pagamentos['pix']) : true,
        'debito' => array_key_exists('debito', $pagamentos) ? !empty($pagamentos['debito']) : true,
        'credito' => array_key_exists('credito', $pagamentos) ? !empty($pagamentos['credito']) : true,
        'dinheiro' => array_key_exists('dinheiro', $pagamentos) ? !empty($pagamentos['dinheiro']) : true,
        'chave_pix' => trim(strip_tags((string) ($pagamentos['chave_pix'] ?? ''))),
    ];
    if ($exigirCompleto && !$pagFinal['pix'] && !$pagFinal['debito'] && !$pagFinal['credito'] && !$pagFinal['dinheiro']) {
        return ['success' => false, 'message' => 'Selecione pelo menos 1 forma de pagamento aceita pela empresa.'];
    }

    $nivelExp = strtolower(trim((string) ($dados['nivel_experiencia'] ?? 'basico')));
    if ($nivelExp === 'facil') {
        $nivelExp = 'basico';
    } elseif ($nivelExp === 'completo') {
        $nivelExp = 'expert';
    }
    if (!in_array($nivelExp, ['basico', 'avancado', 'expert'], true)) {
        $nivelExp = 'basico';
    }

    $config = pedidozap_normalizar_config([
        'NOME_LOJA' => $nome,
        'CNPJ' => $cnpj,
        'RAZAO_SOCIAL' => $razaoSocial,
        'NICHO' => $nicho,
        'NIVEL_EXPERIENCIA' => $nivelExp,
        'NIVEL_EXPERIENCIA_CONFIRMADO' => !empty($dados['nivel_experiencia_confirmado']),
        'REDES' => ['whatsapp' => $whatsapp],
        'WHATSAPP_NUMBER' => $whatsapp,
        'TEMPO_ENTREGA_MIN' => max(10, min(180, $tempoMin)),
        'TEMPO_ESPERA' => max(10, min(180, $tempoMin)) . ' min',
        'PAGAMENTOS' => $pagFinal,
        'FRETE' => [
            'modo' => $taxaBase > 0 ? 'fixo' : 'gratis',
            'taxa_fixa' => round($taxaBase, 2),
            'taxa_base' => round($taxaBase, 2),
            'raio_max_km' => max(1.0, min(300.0, round($raioKm, 1))),
        ],
    ], $slug);
    $config['CATEGORIAS'] = [];
    $cardapio = [];
    foreach ($preset['categorias'] as $categoria) {
        $chave = $categoria['slug'];
        unset($categoria['slug']);
        $config['CATEGORIAS'][$chave] = $categoria;
        $config['TEXTOS']['PAGINAS'][$chave] = [
            'titulo' => $categoria['nome'],
            'subtitulo' => $categoria['subtitulo'],
            'hero' => '',
        ];
        $cardapio[$chave] = [];
    }

    if ($perfil) {
        require_once __DIR__ . '/perfil_qualificado_lib.php';
        $config = pedidozap_aplicar_melhorias_config($config, $perfil, ['nome_loja', 'whatsapp', 'endereco', 'frete_entrega']);
        $config['PERFIL_QUALIFICADO'] = array_merge($perfil, ['melhorias_aplicadas_em' => date('c')]);
    }

    if ($rua !== '') {
        $config['ENDERECO']['rua'] = $rua;
    }
    if ($numero !== '') {
        $config['ENDERECO']['numero'] = $numero;
    }
    if ($cidade !== '') {
        $config['ENDERECO']['cidade'] = $cidade;
    }
    if ($bairro !== '') {
        $config['ENDERECO']['bairro'] = $bairro;
    }
    if ($estado !== '') {
        $config['ENDERECO']['estado'] = substr($estado, 0, 2);
    }
    if ($cep !== '') {
        $config['ENDERECO']['cep'] = $cep;
    }
    if (pedidozap_coords_validas($dados['lat'] ?? null, $dados['lng'] ?? null)) {
        $config['ENDERECO']['lat'] = round((float) $dados['lat'], 7);
        $config['ENDERECO']['lng'] = round((float) $dados['lng'], 7);
    } else {
        if (!pedidozap_coords_validas($config['ENDERECO']['lat'] ?? null, $config['ENDERECO']['lng'] ?? null)) {
            $config['ENDERECO']['lat'] = null;
            $config['ENDERECO']['lng'] = null;
            if ($config['ENDERECO']['cidade'] !== '' || $config['ENDERECO']['cep'] !== '') {
                pedidozap_tentar_geocode_loja($config);
            }
        }
    }

    if ($exigirCompleto && !pedidozap_coords_validas($config['ENDERECO']['lat'] ?? null, $config['ENDERECO']['lng'] ?? null)) {
        return ['success' => false, 'message' => 'Não foi possível localizar as coordenadas GPS do endereço. Toque em "Usar minha localização (GPS)" ou confira o CEP e a cidade antes de concluir.'];
    }

    if (!empty($dados['consentimento_lgpd']) && is_array($dados['consentimento_lgpd'])) {
        $config['CONSENTIMENTO_LGPD'] = $dados['consentimento_lgpd'];
    }

    $anterior = Database::getTenant();
    try {
        Database::setTenant($slug);
        $okConfig = Database::write('config.json', $config);
        $okMenu = Database::write('cardapio.json', $cardapio);
        $okAuth = pedidozap_salvar_credenciais($login, $senha);
        Database::write('pedidos.json', []);
    } finally {
        Database::setTenant($anterior);
    }

    if (!$okConfig || !$okMenu || !$okAuth) {
        return ['success' => false, 'message' => 'Não foi possível gravar os dados iniciais da loja. Tente novamente.'];
    }

    $vencimento = trim((string) ($dados['vencimento'] ?? ''));
    $statusInicial = (string) ($dados['status'] ?? (!empty($dados['em_teste']) ? 'teste' : 'ativo'));
    $emTeste = !empty($dados['em_teste']) || $statusInicial === 'teste';
    $diasTrial = max(1, min(30, (int) (pedidozap_saas_config()['dias_teste_gratis'] ?? 30)));
    if ($emTeste) {
        $limiteTrialTs = strtotime('+' . $diasTrial . ' days');
        $vencInformadoTs = pedidozap_parse_data_iso($vencimento);
        if ($vencInformadoTs === null || $vencInformadoTs > $limiteTrialTs) {
            $vencimento = date('Y-m-d', $limiteTrialTs);
        }
    }
    $planoEscolhido = pz_plano_normalizar((string) ($dados['plano_escolhido'] ?? ($dados['plano'] ?? 'scale')));
    $storeIdUnico = 'st_' . substr(sha1($slug . '|' . microtime(true)), 0, 12);
    $okRegistro = pedidozap_salvar_registro_loja($slug, [
        'store_id' => $storeIdUnico,
        'nome_empresa' => $nome,
        'razao_social' => $razaoSocial,
        'cnpj' => $cnpj,
        'google_place_id' => $perfil ? (string) ($perfil['google_place_id'] ?? '') : '',
        'nicho' => $nicho,
        'login' => $login,
        'status' => $statusInicial,
        'plano' => $emTeste ? 'teste' : $planoEscolhido,
        'plano_escolhido' => $planoEscolhido,
        'ciclo_cobranca' => strtolower((string) ($dados['ciclo_cobranca'] ?? 'mensal')) === 'anual' ? 'anual' : 'mensal',
        'grupo_rede' => trim(strip_tags((string) ($dados['grupo_rede'] ?? ''))),
        'vencimento' => $vencimento,
        'whatsapp' => $whatsapp,
        'cidade' => $config['ENDERECO']['cidade'] ?? '',
        'bairro' => $config['ENDERECO']['bairro'] ?? '',
        'estado' => $config['ENDERECO']['estado'] ?? '',
        'cep' => $config['ENDERECO']['cep'] ?? '',
        'lat' => $config['ENDERECO']['lat'] ?? 0,
        'lng' => $config['ENDERECO']['lng'] ?? 0,
        'em_teste' => $emTeste,
        'criado_em' => date('Y-m-d H:i:s'),
    ]);

    return [
        'success' => (bool) $okRegistro,
        'message' => $okRegistro ? '' : 'Não foi possível finalizar o registro da loja.',
        'slug' => $slug,
        'store_id' => $storeIdUnico,
        'vencimento' => $vencimento,
        'login' => $login,
    ];
}

/**
 * Sincroniza cardápio e preços entre matriz e filial (1 unidade física = 1 assinatura própria).
 */
function pedidozap_sincronizar_cardapio_filial(string $origemSlug, string $destinoSlug): array
{
    $origem = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim($origemSlug)));
    $destino = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim($destinoSlug)));
    if ($origem === '' || $destino === '' || $origem === $destino) {
        return ['success' => false, 'message' => 'Informe uma unidade filial diferente da atual.'];
    }
    $reg = pedidozap_ler_registro();
    $mOrig = is_array($reg[$origem] ?? null) ? $reg[$origem] : [];
    $mDest = is_array($reg[$destino] ?? null) ? $reg[$destino] : [];
    if (empty($mDest) && !Database::tenantDataExists($destino)) {
        return ['success' => false, 'message' => 'Unidade filial não encontrada.'];
    }
    $gOrig = trim((string) ($mOrig['grupo_rede'] ?? ''));
    $gDest = trim((string) ($mDest['grupo_rede'] ?? ''));
    $cnpjOrig = preg_replace('/\D/', '', (string) ($mOrig['cnpj'] ?? ''));
    $cnpjDest = preg_replace('/\D/', '', (string) ($mDest['cnpj'] ?? ''));
    $mesmoDono = ($gOrig !== '' && strcasecmp($gOrig, $gDest) === 0)
        || ($cnpjOrig !== '' && strlen($cnpjOrig) >= 8 && substr($cnpjOrig, 0, 8) === substr($cnpjDest, 0, 8));
    if (!$mesmoDono) {
        return ['success' => false, 'message' => 'A filial precisa pertencer ao mesmo Grupo de Rede ou raiz de CNPJ da matriz.'];
    }
    if (pedidozap_loja_bloqueada($destino)) {
        return ['success' => false, 'message' => 'A filial selecionada está com assinatura pendente/bloqueada. Cada unidade física exige assinatura ativa própria.'];
    }

    $ant = Database::getTenant();
    try {
        Database::setTenant($origem);
        $cardOrig = Database::readStrict('cardapio.json');
        $cfgOrig = Database::readStrict('config.json');
        if (!is_array($cardOrig)) {
            return ['success' => false, 'message' => 'Catálogo da matriz vazio.'];
        }
        Database::setTenant($destino);
        $cfgDest = is_array(Database::readStrict('config.json')) ? Database::readStrict('config.json') : [];
        if (is_array($cfgOrig) && !empty($cfgOrig['CATEGORIAS'])) {
            $cfgDest['CATEGORIAS'] = $cfgOrig['CATEGORIAS'];
        }
        if (is_array($cfgOrig) && !empty($cfgOrig['TEXTOS']['PAGINAS'])) {
            $cfgDest['TEXTOS']['PAGINAS'] = $cfgOrig['TEXTOS']['PAGINAS'];
        }
        $cfgDest['VERSION'] = time();
        Database::write('cardapio.json', $cardOrig);
        Database::write('config.json', $cfgDest);
        return ['success' => true, 'message' => "Cardápio e preços sincronizados de {$origem} para {$destino}!"];
    } finally {
        Database::setTenant($ant);
    }
}

function pedidozap_loja_bloqueada($slug)
{
    $slug = strtolower(trim((string) $slug));
    if ($slug === '') {
        $slug = 'default';
    }
    return pedidozap_assinatura_publica($slug)['status'] === 'bloqueado';
}

function pedidozap_master_file()
{
    return __DIR__ . '/data/master_auth.json';
}

function pedidozap_ler_master()
{
    $envUser = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_MASTER_USER') : '';
    $envPass = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_MASTER_PASS') : '';
    if ($envUser !== '' && $envPass !== '') {
        return [
            'user' => $envUser,
            'pass_plain_env' => $envPass,
            'pass_hash' => hash('sha256', $envUser . '|' . $envPass),
        ];
    }

    $anterior = Database::getTenant();
    try {
        Database::setTenant('default');
        $data = Database::readStrict('master_auth.json');
    } finally {
        Database::setTenant($anterior);
    }
    if (is_array($data) && !empty($data['user']) && !empty($data['pass_hash'])) {
        return $data;
    }

    $path = pedidozap_master_file();
    if (is_file($path)) {
        $fileData = json_decode(@file_get_contents($path) ?: '', true);
        if (is_array($fileData) && !empty($fileData['user']) && !empty($fileData['pass_hash'])) {
            return $fileData;
        }
    }
    return null;
}

function pedidozap_verificar_master($user, $pass)
{
    $data = pedidozap_ler_master();
    if (!is_array($data) || empty($data['user'])) {
        return false;
    }
    if (!hash_equals((string) $data['user'], (string) $user)) {
        return false;
    }
    if (isset($data['pass_plain_env'])) {
        return hash_equals((string) $data['pass_plain_env'], (string) $pass);
    }
    if (empty($data['pass_hash'])) {
        return false;
    }
    return password_verify((string) $pass, $data['pass_hash']);
}

function pedidozap_salvar_master($user, $pass)
{
    $user = trim((string) $user);
    $pass = (string) $pass;
    if ($user === '' || $pass === '') {
        return false;
    }
    $payload = [
        'user' => $user,
        'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'updated_at' => date('c'),
    ];
    $anterior = Database::getTenant();
    try {
        Database::setTenant('default');
        $ok = Database::write('master_auth.json', $payload);
    } finally {
        Database::setTenant($anterior);
    }
    return $ok;
}

function pedidozap_perfis_permissoes_padrao($funcao = 'atendente')
{
    $funcao = strtolower(trim((string) $funcao));
    $todas = [
        'visao_geral' => true,
        'pedidos' => true,
        'cardapio' => true,
        'entrega' => true,
        'clientes' => true,
        'estoque' => true,
        'financeiro' => true,
        'relatorios' => true,
        'cozinha' => true,
        'equipe' => true,
        'config' => true,
        'ia_financeiro' => true,
        'ia_estoque' => true,
        'ia_operacao' => true,
    ];
    if ($funcao === 'administrador') {
        return $todas;
    }
    if ($funcao === 'gerente') {
        $p = $todas;
        $p['equipe'] = false;
        return $p;
    }
    if ($funcao === 'cozinha') {
        return array_merge(array_fill_keys(array_keys($todas), false), [
            'cozinha' => true,
            'pedidos' => true,
            'estoque' => true,
            'ia_operacao' => true,
            'ia_estoque' => true,
        ]);
    }
    if ($funcao === 'entregador') {
        return array_merge(array_fill_keys(array_keys($todas), false), [
            'entrega' => true,
            'pedidos' => true,
            'ia_operacao' => true,
        ]);
    }
    // atendente padrão
    return array_merge(array_fill_keys(array_keys($todas), false), [
        'visao_geral' => true,
        'pedidos' => true,
        'cardapio' => true,
        'clientes' => true,
        'entrega' => true,
        'cozinha' => true,
        'ia_operacao' => true,
    ]);
}

function pedidozap_buscar_funcionario($user)
{
    $user = trim((string) $user);
    if ($user === '') {
        return null;
    }
    $lista = Database::readStrict('funcionarios.json');
    if (!is_array($lista)) {
        return null;
    }
    foreach ($lista as $func) {
        if (!is_array($func) || empty($func['login'])) {
            continue;
        }
        if (strcasecmp((string) $func['login'], $user) === 0) {
            return $func;
        }
    }
    return null;
}

function pedidozap_verificar_funcionario($user, $pass)
{
    $func = pedidozap_buscar_funcionario($user);
    if (!$func || empty($func['ativo']) || empty($func['pass_hash'])) {
        return null;
    }
    if (!password_verify((string) $pass, (string) $func['pass_hash'])) {
        return null;
    }
    return $func;
}

function pedidozap_autorizado($user, $pass)
{
    $pass = (string) $pass;
    if (strpos($pass, 'pzt.') === 0) {
        return pedidozap_validar_token($user, $pass);
    }
    if (pedidozap_verificar_master($user, $pass)) {
        return 'master';
    }
    if (pedidozap_verificar_credenciais($user, $pass)) {
        return 'lojista';
    }
    if (pedidozap_verificar_funcionario($user, $pass)) {
        return 'funcionario';
    }
    return false;
}

function pedidozap_sessao_info($user, $pass)
{
    $pass = (string) $pass;
    if (strpos($pass, 'pzt.') === 0) {
        $partes = explode('.', $pass);
        if (count($partes) === 3 && $partes[0] === 'pzt' && pedidozap_validar_token($user, $pass)) {
            $payload = json_decode(pedidozap_b64url_decode($partes[1]) ?: '', true);
            if (is_array($payload)) {
                $role = $payload['r'] ?? 'lojista';
                if ($role === 'funcionario') {
                    $func = pedidozap_buscar_funcionario($payload['u'] ?? '');
                    $perms = is_array($func['permissoes'] ?? null)
                        ? $func['permissoes']
                        : pedidozap_perfis_permissoes_padrao($func['funcao'] ?? 'atendente');
                    return [
                        'role' => 'funcionario',
                        'user' => (string) ($payload['u'] ?? $user),
                        'nome' => (string) ($func['nome'] ?? ($payload['u'] ?? $user)),
                        'funcao' => (string) ($func['funcao'] ?? 'atendente'),
                        'permissoes' => $perms,
                    ];
                }
                return [
                    'role' => $role,
                    'user' => (string) ($payload['u'] ?? $user),
                    'nome' => (string) ($payload['u'] ?? $user),
                    'funcao' => $role === 'master' ? 'master' : 'proprietario',
                    'permissoes' => pedidozap_perfis_permissoes_padrao('administrador'),
                ];
            }
        }
        return null;
    }

    $role = pedidozap_autorizado($user, $pass);
    if (!$role) {
        return null;
    }
    if ($role === 'funcionario') {
        $func = pedidozap_buscar_funcionario($user);
        $perms = is_array($func['permissoes'] ?? null)
            ? $func['permissoes']
            : pedidozap_perfis_permissoes_padrao($func['funcao'] ?? 'atendente');
        return [
            'role' => 'funcionario',
            'user' => (string) ($func['login'] ?? $user),
            'nome' => (string) ($func['nome'] ?? $user),
            'funcao' => (string) ($func['funcao'] ?? 'atendente'),
            'permissoes' => $perms,
        ];
    }
    return [
        'role' => $role,
        'user' => (string) $user,
        'nome' => (string) $user,
        'funcao' => $role === 'master' ? 'master' : 'proprietario',
        'permissoes' => pedidozap_perfis_permissoes_padrao('administrador'),
    ];
}

function pedidozap_tem_permissao(?array $sessao, $permissao)
{
    if (!$sessao) {
        return false;
    }
    if (($sessao['role'] ?? '') === 'master' || ($sessao['role'] ?? '') === 'lojista') {
        return true;
    }
    $perms = $sessao['permissoes'] ?? [];
    return !empty($perms[$permissao]);
}

function pedidozap_app_secret()
{
    $envSecret = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_APP_SECRET') : '';
    if (strlen($envSecret) >= 32) {
        return $envSecret;
    }
    $anterior = Database::getTenant();
    try {
        Database::setTenant('default');
        $data = Database::readStrict('app_secret.json');
        if (is_array($data) && !empty($data['secret']) && strlen($data['secret']) >= 32) {
            return $data['secret'];
        }
        $path = __DIR__ . '/data/app_secret.json';
        if (is_file($path)) {
            $fileData = json_decode(@file_get_contents($path) ?: '', true);
            if (is_array($fileData) && !empty($fileData['secret']) && strlen($fileData['secret']) >= 32) {
                Database::write('app_secret.json', $fileData);
                return $fileData['secret'];
            }
        }
        $secret = bin2hex(random_bytes(32));
        Database::write('app_secret.json', ['secret' => $secret, 'criado_em' => date('c')]);
        return $secret;
    } finally {
        Database::setTenant($anterior);
    }
}

function pedidozap_b64url($bin)
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function pedidozap_b64url_decode($txt)
{
    $txt = strtr((string) $txt, '-_', '+/');
    $pad = strlen($txt) % 4;
    if ($pad) {
        $txt .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($txt, true);
}

/**
 * Impressão digital do hash da senha: trocar a senha invalida todos os tokens emitidos antes.
 */
function pedidozap_digital_credencial($role, $user = '')
{
    if ($role === 'master') {
        $data = pedidozap_ler_master();
        $hash = is_array($data) ? (string) ($data['pass_hash'] ?? '') : '';
    } elseif ($role === 'funcionario') {
        $func = pedidozap_buscar_funcionario($user);
        $hash = is_array($func) && !empty($func['ativo']) ? (string) ($func['pass_hash'] ?? '') : 'inativo';
    } else {
        $auth = pedidozap_ler_auth();
        $hash = is_array($auth) ? (string) ($auth['pass_hash'] ?? '') : '';
    }
    return substr(hash('sha256', $hash), 0, 16);
}

function pedidozap_emitir_token($user, $role, $ttlSegundos = 259200)
{
    $roleNorm = in_array($role, ['master', 'funcionario', 'lojista'], true) ? $role : 'lojista';
    $payload = [
        'u' => (string) $user,
        'r' => $roleNorm,
        't' => Database::getTenant(),
        'e' => time() + (int) $ttlSegundos,
        'd' => pedidozap_digital_credencial($roleNorm, $user),
    ];
    $corpo = pedidozap_b64url(json_encode($payload));
    $assinatura = pedidozap_b64url(hash_hmac('sha256', $corpo, pedidozap_app_secret(), true));
    return 'pzt.' . $corpo . '.' . $assinatura;
}

function pedidozap_validar_token($user, $token)
{
    $partes = explode('.', (string) $token);
    if (count($partes) !== 3 || $partes[0] !== 'pzt') {
        return false;
    }
    $esperada = pedidozap_b64url(hash_hmac('sha256', $partes[1], pedidozap_app_secret(), true));
    if (!hash_equals($esperada, $partes[2])) {
        return false;
    }
    $payload = json_decode(pedidozap_b64url_decode($partes[1]) ?: '', true);
    if (!is_array($payload) || (int) ($payload['e'] ?? 0) < time() || empty($payload['u']) || !is_string($payload['u'])) {
        return false;
    }
    if ((string) $user !== '' && strcasecmp((string) $payload['u'], (string) $user) !== 0) {
        return false;
    }
    $role = $payload['r'] ?? '';
    $sigCred = isset($payload['d']) && is_string($payload['d']) ? $payload['d'] : '';
    if ($sigCred === '') {
        return false;
    }
    if ($role === 'master') {
        return hash_equals(pedidozap_digital_credencial('master'), $sigCred) ? 'master' : false;
    }
    if ($role === 'funcionario') {
        if (($payload['t'] ?? '') !== Database::getTenant()) {
            return false;
        }
        return hash_equals(pedidozap_digital_credencial('funcionario', $payload['u']), $sigCred) ? 'funcionario' : false;
    }
    if ($role !== 'lojista' || ($payload['t'] ?? '') !== Database::getTenant()) {
        return false;
    }
    return hash_equals(pedidozap_digital_credencial('lojista'), $sigCred) ? 'lojista' : false;
}

/**
 * Limite genérico por IP + escopo (protege APIs públicas contra abuso).
 * Retorna false quando o limite foi atingido.
 */
function pedidozap_client_ip()
{
    $cand = [
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($cand as $raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            continue;
        }
        $partes = explode(',', $raw);
        $ip = trim((string) ($partes[0] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'desconhecido');
}

function pedidozap_limite_requisicao($escopo, $maximo = 80, $janela = 60, $registrar = true)
{
    $escopo = preg_replace('/[^a-z0-9._-]/i', '_', (string) $escopo);
    if ($escopo === '') {
        $escopo = 'api';
    }
    $dir = __DIR__ . '/data/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ip = pedidozap_client_ip();
    $tenant = class_exists('Database') ? Database::getTenant() : 'default';
    $path = $dir . '/' . sha1($ip . '|' . $escopo . '|' . $tenant) . '.json';
    $agora = time();

    $raw = is_file($path) ? (@file_get_contents($path) ?: '') : '';
    $data = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data) || (int) ($data['inicio'] ?? 0) < $agora - $janela) {
        $data = ['inicio' => $agora, 'hits' => 0];
    }
    $data['hits'] = (int) ($data['hits'] ?? 0);
    if ($registrar) {
        $data['hits']++;
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }
    return $data['hits'] <= (int) $maximo;
}

function pedidozap_limite_login($registrarFalha = false, $limpar = false)
{
    $dir = __DIR__ . '/data/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ip = pedidozap_client_ip();
    $path = $dir . '/' . sha1($ip . '|' . Database::getTenant()) . '.json';
    $janela = 900;
    $maximo = 8;

    if ($limpar) {
        @unlink($path);
        return true;
    }

    $raw = is_file($path) ? (@file_get_contents($path) ?: '') : '';
    $data = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data) || (int) ($data['inicio'] ?? 0) < time() - $janela) {
        $data = ['inicio' => time(), 'falhas' => 0];
    }
    $data['falhas'] = (int) ($data['falhas'] ?? 0);
    if ($registrarFalha) {
        $data['falhas']++;
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }
    return $data['falhas'] < $maximo;
}

function pedidozap_listar_lojas()
{
    return pedidozap_resumo_lojas();
}

function pedidozap_resumo_lojas()
{
    $registro = pedidozap_ler_registro();
    $saas = pedidozap_saas_config();
    $lista = [];
    foreach ($registro as $slug => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $planoLojaResumo = pz_plano_da_loja((string) $slug, $meta, $saas);
        $det = pedidozap_cobranca_detalhes($meta, $saas, $planoLojaResumo['slug'], $planoLojaResumo['ciclo']);
        $lista[] = [
            'slug' => $slug,
            'nome_empresa' => $meta['nome_empresa'] ?? $meta['nome_loja'] ?? $slug,
            'razao_social' => $meta['razao_social'] ?? '',
            'nicho' => $meta['nicho'] ?? '',
            'login' => $meta['login'] ?? '',
            'status' => $meta['status'] ?? 'ativo',
            'status_efetivo' => $det['status'],
            'motivo' => $det['motivo'],
            'shadowban' => $det['shadowban'],
            'em_atraso' => $det['em_atraso'],
            'dias_restantes' => $det['dias_restantes'],
            'dias_atraso' => $det['dias_atraso'],
            'data_bloqueio_prevista' => $det['data_bloqueio_prevista'],
            'encargo_reativacao_ativo' => $det['encargo_reativacao_ativo'],
            'encargo_reativacao_pct' => $det['encargo_reativacao_pct'],
            'valor_normal' => $det['valor_normal'],
            'valor_total_cobrar' => $det['valor_total_cobrar'],
            'modo_cobranca_preferido' => $det['modo_cobranca_preferido'],
            'em_teste' => !empty($meta['em_teste']),
            'plano' => $planoLojaResumo['nome'],
            'plano_slug' => $planoLojaResumo['slug'],
            'ciclo_cobranca' => $planoLojaResumo['ciclo'],
            'grupo_rede' => $planoLojaResumo['grupo_rede'],
            'vencimento' => $meta['vencimento'] ?? '',
            'criado_em' => $meta['criado_em'] ?? '',
            'whatsapp' => $meta['whatsapp'] ?? '',
            'assinatura_status' => $meta['assinatura_status'] ?? '',
            'ultimo_pagamento' => $meta['ultimo_pagamento'] ?? '',
            'destaque' => !empty($meta['destaque']),
            'cidade' => $meta['cidade'] ?? '',
            'bairro' => $meta['bairro'] ?? '',
        ];
    }
    usort($lista, function ($a, $b) {
        return strcmp((string) ($b['criado_em'] ?? ''), (string) ($a['criado_em'] ?? ''));
    });
    return $lista;
}
