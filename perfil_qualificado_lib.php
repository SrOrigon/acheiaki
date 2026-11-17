<?php
require_once __DIR__ . '/integracoes_lib.php';
require_once __DIR__ . '/saas_lib.php';

function pedidozap_inferir_nicho($sinais)
{
    $sinais = is_array($sinais) ? $sinais : [];
    $texto = mb_strtolower(implode(' ', array_filter([
        $sinais['nome'] ?? '',
        $sinais['cnae_descricao'] ?? '',
        implode(' ', $sinais['tipos'] ?? []),
    ])));
    $cnae = (int) ($sinais['cnae'] ?? 0);

    $mapa = [
        'pizzaria' => ['pizza', 'pizzaria', 'rotisseria'],
        'acai' => ['acai', 'açaí', 'acaiteria'],
        'marmitaria' => ['marmita', 'marmitaria', 'refeicao', 'self service', 'quentinha'],
        'pensao' => ['pensao', 'pensão', 'caseira', 'almoco', 'restaurante'],
        'confeitaria' => ['confeitaria', 'doceria', 'bolo', 'padaria', 'bakery', 'cake'],
        'lanchonete' => ['lanche', 'hamburguer', 'burger', 'lanchonete', 'fast food', 'sandwich'],
    ];

    if ($cnae >= 5610101 && $cnae <= 5610999) {
        return 'pensao';
    }
    if ($cnae >= 5620101 && $cnae <= 5620999) {
        return 'marmitaria';
    }
    if ($cnae === 1096101 || $cnae === 1094500) {
        return 'confeitaria';
    }

    foreach ($mapa as $nicho => $palavras) {
        foreach ($palavras as $p) {
            if (strpos($texto, $p) !== false) {
                return $nicho;
            }
        }
    }
    if (in_array('meal_delivery', $sinais['tipos'] ?? [], true) || in_array('meal_takeaway', $sinais['tipos'] ?? [], true)) {
        return 'lanchonete';
    }
    return 'lanchonete';
}

function pedidozap_montar_perfil(array $partes)
{
    $empresa = is_array($partes['empresa'] ?? null) ? $partes['empresa'] : null;
    $comercio = is_array($partes['comercio'] ?? null) ? $partes['comercio'] : null;

    $nome = '';
    if ($comercio && !empty($comercio['nome'])) {
        $nome = $comercio['nome'];
    } elseif ($empresa && !empty($empresa['nome_fantasia'])) {
        $nome = $empresa['nome_fantasia'];
    } elseif ($empresa && !empty($empresa['razao_social'])) {
        $nome = $empresa['razao_social'];
    }

    $telefone = '';
    if ($comercio && !empty($comercio['telefone'])) {
        $telefone = $comercio['telefone'];
    } elseif ($empresa && !empty($empresa['telefone'])) {
        $telefone = $empresa['telefone'];
    }

    $nicho = pedidozap_inferir_nicho([
        'nome' => $nome,
        'cnae' => $empresa['cnae'] ?? null,
        'cnae_descricao' => $empresa['cnae_descricao'] ?? '',
        'tipos' => $comercio['tipos'] ?? [],
    ]);

    $endereco = $empresa['endereco'] ?? [];
    if ($comercio && empty($endereco['rua']) && !empty($comercio['endereco_formatado'])) {
        $endereco['texto_maps'] = $comercio['endereco_formatado'];
    }
    if ($comercio && isset($comercio['lat'])) {
        $endereco['lat'] = $comercio['lat'];
        $endereco['lng'] = $comercio['lng'];
    }

    $perfil = [
        'nome_exibicao' => $nome,
        'nicho_sugerido' => $nicho,
        'nicho_rotulo' => pedidozap_nicho_presets()[$nicho]['rotulo'] ?? $nicho,
        'cnpj' => $empresa['cnpj'] ?? '',
        'razao_social' => $empresa['razao_social'] ?? '',
        'nome_fantasia' => $empresa['nome_fantasia'] ?? '',
        'cnae' => $empresa['cnae'] ?? null,
        'cnae_descricao' => $empresa['cnae_descricao'] ?? '',
        'porte' => $empresa['porte'] ?? '',
        'google_place_id' => $comercio['place_id'] ?? '',
        'maps_url' => $comercio['maps_url'] ?? '',
        'website' => $comercio['website'] ?? '',
        'telefone' => $telefone,
        'nota_google' => $comercio['nota'] ?? null,
        'avaliacoes_google' => $comercio['avaliacoes_total'] ?? null,
        'horarios_google' => $comercio['horarios_texto'] ?? [],
        'tipos_google' => $comercio['tipos'] ?? [],
        'endereco' => $endereco,
        'atualizado_em' => date('c'),
        'fontes' => array_values(array_filter([
            $empresa ? 'cnpj' : null,
            $comercio ? 'google_maps' : null,
        ])),
    ];

    $perfil['melhorias'] = pedidozap_gerar_melhorias_perfil($perfil);
    return $perfil;
}

function pedidozap_gerar_melhorias_perfil(array $perfil)
{
    $melhorias = [];
    $nicho = $perfil['nicho_sugerido'] ?? 'lanchonete';

    $melhorias[] = [
        'id' => 'nicho_segmento',
        'prioridade' => 'alta',
        'titulo' => 'Segmento ideal: ' . ($perfil['nicho_rotulo'] ?? $nicho),
        'descricao' => 'Ajustamos categorias e textos do cardápio para o tipo de negócio detectado.',
        'aba' => 'segmento',
        'auto_aplicavel' => true,
    ];

    if (!empty($perfil['nome_exibicao'])) {
        $melhorias[] = [
            'id' => 'nome_loja',
            'prioridade' => 'alta',
            'titulo' => 'Nome da loja no site',
            'descricao' => 'Usar “' . $perfil['nome_exibicao'] . '” como nome visível para o cliente.',
            'aba' => 'identidade',
            'auto_aplicavel' => true,
        ];
    }

    if (!empty($perfil['telefone'])) {
        $melhorias[] = [
            'id' => 'whatsapp',
            'prioridade' => 'alta',
            'titulo' => 'WhatsApp da loja',
            'descricao' => 'Vincular o número encontrado para pedidos caírem no seu celular.',
            'aba' => 'contato',
            'auto_aplicavel' => true,
        ];
    }

    if (!empty($perfil['endereco']['rua']) || !empty($perfil['endereco']['texto_maps'])) {
        $melhorias[] = [
            'id' => 'endereco',
            'prioridade' => 'media',
            'titulo' => 'Endereço no cardápio',
            'descricao' => 'Mostrar onde fica a loja e calcular entrega com mais precisão.',
            'aba' => 'contato',
            'auto_aplicavel' => true,
        ];
    }

    $melhorias[] = [
        'id' => 'frete_entrega',
        'prioridade' => 'media',
        'titulo' => 'Configurar entrega e retirada',
        'descricao' => 'Negócios locais costumam vender mais com “buscar na loja” + entrega por bairro.',
        'aba' => 'frete',
        'auto_aplicavel' => true,
    ];

    if (!empty($perfil['nota_google']) && (float) $perfil['nota_google'] >= 4.0) {
        $melhorias[] = [
            'id' => 'prova_social',
            'prioridade' => 'baixa',
            'titulo' => 'Destaque de reputação',
            'descricao' => 'Sua nota no Google é ' . $perfil['nota_google'] . '. Vale citar isso na página inicial.',
            'aba' => 'textos',
            'auto_aplicavel' => false,
        ];
    }

    if ($nicho === 'pizzaria') {
        $melhorias[] = [
            'id' => 'cardapio_tamanhos',
            'prioridade' => 'alta',
            'titulo' => 'Pizzas por tamanho',
            'descricao' => 'Cadastre brotinho, média e grande com preços claros — cliente pede mais rápido.',
            'aba' => 'cardapio',
            'auto_aplicavel' => false,
        ];
    }
    if ($nicho === 'marmitaria' || $nicho === 'pensao') {
        $melhorias[] = [
            'id' => 'marmita_tamanhos',
            'prioridade' => 'alta',
            'titulo' => 'Marmitas P / M / G',
            'descricao' => 'Use tamanhos simples; no balcão o atendimento fica mais rápido.',
            'aba' => 'cardapio',
            'auto_aplicavel' => false,
        ];
    }
    if ($nicho === 'acai') {
        $melhorias[] = [
            'id' => 'complementos',
            'prioridade' => 'media',
            'titulo' => 'Complementos do açaí',
            'descricao' => 'Granola, leite ninho e frutas vendem bem como opcionais.',
            'aba' => 'cardapio',
            'auto_aplicavel' => false,
        ];
    }

    $melhorias[] = [
        'id' => 'cozinha_comanda',
        'prioridade' => 'media',
        'titulo' => 'Tela e comanda da cozinha',
        'descricao' => 'Com volume de pedidos, a cozinha em tela grande evita erro.',
        'aba' => 'comandas',
        'auto_aplicavel' => false,
    ];

    if (!empty($perfil['cnpj'])) {
        $melhorias[] = [
            'id' => 'pix_formal',
            'prioridade' => 'media',
            'titulo' => 'PIX com CNPJ',
            'descricao' => 'Empresa formal: ofereça PIX na entrega e destaque confiança no checkout.',
            'aba' => 'pagamentos',
            'auto_aplicavel' => false,
        ];
    }

    if (!empty($perfil['horarios_google'])) {
        $melhorias[] = [
            'id' => 'horarios',
            'prioridade' => 'media',
            'titulo' => 'Horários de funcionamento',
            'descricao' => 'Encontramos horários no Google — confira na aba contato e ajuste se precisar.',
            'aba' => 'contato',
            'auto_aplicavel' => false,
        ];
    }

    return $melhorias;
}

function pedidozap_aplicar_perfil_em_config(array $config, array $perfil, $somenteAuto = true)
{
    $idsAplicar = [];
    foreach ($perfil['melhorias'] ?? [] as $m) {
        if (!$somenteAuto || !empty($m['auto_aplicavel'])) {
            $idsAplicar[$m['id']] = true;
        }
    }
    return pedidozap_aplicar_melhorias_config($config, $perfil, array_keys($idsAplicar));
}

function pedidozap_aplicar_melhorias_config(array $config, array $perfil, array $idsMelhorias = null)
{
    $ids = $idsMelhorias === null ? null : array_flip($idsMelhorias);
    $aplica = function ($id) use ($ids) {
        return $ids === null || isset($ids[$id]);
    };

    if ($aplica('nome_loja') && !empty($perfil['nome_exibicao'])) {
        $config['NOME_LOJA'] = $perfil['nome_exibicao'];
    }
    if ($aplica('whatsapp') && !empty($perfil['telefone'])) {
        $config['WHATSAPP_NUMBER'] = pedidozap_somente_digitos($perfil['telefone']);
        if (!isset($config['REDES']) || !is_array($config['REDES'])) {
            $config['REDES'] = [];
        }
        $config['REDES']['whatsapp'] = $config['WHATSAPP_NUMBER'];
    }
    if ($aplica('endereco') && !empty($perfil['endereco']) && is_array($perfil['endereco'])) {
        $e = $perfil['endereco'];
        $config['ENDERECO'] = array_merge($config['ENDERECO'] ?? [], array_filter([
            'rua' => $e['rua'] ?? '',
            'numero' => $e['numero'] ?? '',
            'bairro' => $e['bairro'] ?? '',
            'cidade' => $e['cidade'] ?? '',
            'estado' => $e['estado'] ?? '',
            'cep' => $e['cep'] ?? '',
            'lat' => $e['lat'] ?? null,
            'lng' => $e['lng'] ?? null,
            'complemento' => $e['texto_maps'] ?? '',
        ], function ($v) {
            return $v !== null && $v !== '';
        }));
    }
    if ($aplica('frete_entrega')) {
        if (!isset($config['FRETE']) || !is_array($config['FRETE'])) {
            $config['FRETE'] = [];
        }
        $config['FRETE']['modo'] = $config['FRETE']['modo'] ?? 'bairro';
        if (!isset($config['LOGISTICA']) || !is_array($config['LOGISTICA'])) {
            $config['LOGISTICA'] = [];
        }
        $config['LOGISTICA']['modelo'] = $config['LOGISTICA']['modelo'] ?? 'mista';
        $config['LOGISTICA']['aceita_retirada'] = $config['LOGISTICA']['aceita_retirada'] ?? true;
        if (!isset($config['ATENDIMENTO']) || !is_array($config['ATENDIMENTO'])) {
            $config['ATENDIMENTO'] = [];
        }
        $config['ATENDIMENTO']['modo_padrao'] = $config['ATENDIMENTO']['modo_padrao'] ?? 'entrega';
    }
    if ($aplica('nicho_segmento') && !empty($perfil['nicho_sugerido'])) {
        $config['NICHO'] = $perfil['nicho_sugerido'];
        $presets = pedidozap_nicho_presets();
        $preset = $presets[$perfil['nicho_sugerido']] ?? null;
        if ($preset) {
            $cats = [];
            foreach ($preset['categorias'] as $cat) {
                $slug = $cat['slug'];
                $copy = $cat;
                unset($copy['slug']);
                $cats[$slug] = $copy;
            }
            $config['CATEGORIAS'] = array_merge($config['CATEGORIAS'] ?? [], $cats);
        }
    }

    $slogan = 'Peça pelo link — entrega e retirada.';
    if (!empty($perfil['nota_google']) && (float) $perfil['nota_google'] >= 4.2) {
        $slogan = 'Nota ' . $perfil['nota_google'] . ' no Google — peça direto pelo cardápio.';
    }
    if (trim((string) ($config['SLOGAN'] ?? '')) === '' || ($config['SLOGAN'] ?? '') === 'Peça online e receba no conforto da sua casa.') {
        $config['SLOGAN'] = $slogan;
    }

    $config['PERFIL_QUALIFICADO'] = array_merge($perfil, [
        'melhorias_aplicadas_em' => date('c'),
    ]);

    return $config;
}
