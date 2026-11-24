<?php
/**
 * Esquema padrão da loja (white-label).
 * Mescla configurações antigas sem apagar o que o lojista já salvou.
 */

function pedidozap_is_assoc(array $arr)
{
    if ($arr === []) {
        return false;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
}

function pedidozap_merge_assoc(array $base, array $over)
{
    foreach ($over as $key => $value) {
        if (
            is_array($value)
            && isset($base[$key])
            && is_array($base[$key])
            && pedidozap_is_assoc($value)
            && pedidozap_is_assoc($base[$key])
        ) {
            $base[$key] = pedidozap_merge_assoc($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

function pedidozap_default_tutorial_passos()
{
    return [
        [
            'id' => 'identidade',
            'titulo' => 'Nome, logo e cores',
            'texto' => 'Coloque o nome que o cliente vê, envie a logo e escolha as cores. Depois toque em Salvar no topo.',
            'aba' => 'identidade',
            'link' => '',
        ],
        [
            'id' => 'whatsapp',
            'titulo' => 'WhatsApp e horário',
            'texto' => 'Informe o número com DDD e os horários em que a loja abre e fecha.',
            'aba' => 'contato',
            'link' => '',
        ],
        [
            'id' => 'cardapio',
            'titulo' => 'Produtos, preços e fotos',
            'texto' => 'Cadastre seus produtos, pratos ou serviços com preço e foto na aba Meus Produtos.',
            'aba' => 'cardapio',
            'link' => '',
        ],
        [
            'id' => 'entrega',
            'titulo' => 'Entrega e taxa',
            'texto' => 'Defina se entrega, se o cliente busca na loja e quanto cobra de frete.',
            'aba' => 'frete',
            'link' => '',
        ],
        [
            'id' => 'pagamento',
            'titulo' => 'Formas de pagamento',
            'texto' => 'Marque PIX, dinheiro, cartão — o que você aceita no balcão e na entrega.',
            'aba' => 'pagamentos',
            'link' => '',
        ],
        [
            'id' => 'relatorios',
            'titulo' => 'Ver o que mais vendeu',
            'texto' => 'Abra O que vendeu, escolha o mês e veja quais itens saíram mais e quais saíram menos.',
            'aba' => 'relatorios',
            'link' => '',
        ],
        [
            'id' => 'pedidos',
            'titulo' => 'Painel de pedidos',
            'texto' => 'Quando alguém comprar, o pedido aparece aqui. Você arrasta ou toca no botão laranja para avançar.',
            'aba' => '',
            'link' => 'painel.html',
        ],
        [
            'id' => 'cozinha',
            'titulo' => 'Preparo / Expedição e comanda',
            'texto' => 'Copie o link da tela de preparo/expedição no painel de pedidos. A comanda imprime na térmica quando o pedido vai para separação ou preparo.',
            'aba' => 'comandas',
            'link' => '',
        ],
        [
            'id' => 'teste',
            'titulo' => 'Testar como cliente',
            'texto' => 'Abra sua vitrine em outra aba e faça um pedido de teste. Confira se chegou no painel.',
            'aba' => '',
            'link' => 'index.html',
        ],
    ];
}

function pedidozap_default_ajuda_painel_ferramentas()
{
    return [
        ['id' => 'som', 'titulo' => 'Som de novo pedido', 'texto' => 'Deixe ligado. Quando chegar pedido novo, o celular ou computador faz um barulho para você perceber.'],
        ['id' => 'atendimento', 'titulo' => 'Pedido no balcão ou telefone', 'texto' => 'Use quando alguém compra aí na loja, WhatsApp ou telefone. Você coloca o nome e abre o catálogo para montar o pedido.'],
        ['id' => 'cozinha', 'titulo' => 'Link do preparo / expedição', 'texto' => 'Toque uma vez para copiar o endereço. Cole no tablet ou no computador da cozinha/balcão. Lá aparecem só os pedidos que estão sendo preparados ou separados.'],
        ['id' => 'impressao', 'titulo' => 'Imprimir comanda automaticamente', 'texto' => 'Ligado = ao mandar para preparo/separação, a impressora de 80 mm pode imprimir sozinha. Desligado = só imprime na tela de preparo.'],
        ['id' => 'entregador', 'titulo' => 'Link do entregador', 'texto' => 'Copia o endereço para o celular de quem faz entrega. A pessoa escolhe o pedido e o cliente acompanha no mapa.'],
        ['id' => 'trocar_entregador', 'titulo' => 'Trocar link do entregador', 'texto' => 'Só use se o link antigo vazou ou não funciona mais. O link anterior para de valer.'],
        ['id' => 'atualizar', 'titulo' => 'Atualizar lista', 'texto' => 'Busca de novo os pedidos no servidor. Use se a tela parecer desatualizada.'],
        ['id' => 'voltar', 'titulo' => 'Voltar ao painel da loja', 'texto' => 'Para mudar preço, produto, horário, foto ou nome da loja.'],
    ];
}

function pedidozap_default_ajuda_painel_colunas()
{
    return [
        ['id' => 'novo', 'titulo' => 'Pedidos novos', 'texto' => 'Acabaram de chegar. Toque no botão laranja do pedido para aceitar e iniciar preparo ou separação.'],
        ['id' => 'preparo', 'titulo' => 'Em preparo / separação', 'texto' => 'Estão sendo montados agora. Quando ficarem prontos, avance para “saiu para entrega” ou “entregue”.'],
        ['id' => 'em-rota', 'titulo' => 'Saiu para entrega', 'texto' => 'O entregador já saiu. Quando o cliente receber, marque como entregue.'],
        ['id' => 'concluido', 'titulo' => 'Entregues', 'texto' => 'Pedidos finalizados hoje. Pode guardar para consultar depois.'],
        ['id' => 'arquivado', 'titulo' => 'Guardados', 'texto' => 'Pedidos que você arquivou. O botão vermelho embaixo apaga tudo desta lista.'],
    ];
}

function pedidozap_default_ajuda_cardapio_passos()
{
    return [
        ['id' => 'escolher', 'titulo' => '1. Toque no prato', 'texto' => 'Abra o item que você quer. Se pedir tamanho ou complemento, marque e confirme.'],
        ['id' => 'carrinho', 'titulo' => '2. Olhe o carrinho', 'texto' => 'A faixa laranja embaixo mostra o valor. Toque em Ver pedido para revisar tudo.'],
        ['id' => 'entrega', 'titulo' => '3. Entrega ou buscar', 'texto' => 'Escolha receber em casa ou buscar na loja. Para entrega, preencha nome, telefone e endereço com calma.'],
        ['id' => 'finalizar', 'titulo' => '4. Confirmar e enviar', 'texto' => 'Confira itens e forma de pagamento. Ao enviar, a loja recebe na hora — não precisa mandar print no WhatsApp.'],
    ];
}

function pedidozap_default_ajuda_cozinha_fichas()
{
    return [
        ['id' => 'reimprimir', 'titulo' => 'Reimprimir comanda', 'texto' => 'Use se a impressora não imprimiu ou o papel rasgou. Não muda o status do pedido.'],
        ['id' => 'pronto', 'titulo' => 'Pronto / saiu', 'texto' => 'Toque quando o pedido estiver montado. Ele sai desta tela e vai para entrega ou retirada no painel.'],
    ];
}

function pedidozap_default_ajuda()
{
    return [
        'admin' => [
            'tutorial' => 'Personalize o passo a passo e os textos de ajuda. Tudo que o lojista lê pode ser mudado aqui.',
            'identidade' => 'Na aba Nome e Foto, coloque o nome da loja, envie a logo e escolha as cores. Depois toque em Salvar.',
            'contato' => 'Na aba WhatsApp e Horário, conecte seu WhatsApp + IA Ao Vivo, informe seu número com DDD, horários e endereço da loja.',
            'cardapio' => 'Na aba Meus Produtos, cadastre seus produtos, pratos ou serviços. Em cada item envie uma foto e defina o preço.',
            'frete' => 'Diga se entrega, se o cliente busca na loja e quanto cobrar de entrega.',
            'pagamentos' => 'Na aba Como Receber, marque as formas de pagamento que você aceita (PIX, dinheiro, cartão).',
            'relatorios' => 'Escolha o mês e veja quais itens venderam mais ou menos — ajuda a planejar compras.',
            'comandas' => 'Textos da impressora térmica e botões da tela de preparo/expedição.',
            'atendimento' => 'Tela para atendente registrar venda no balcão, loja ou telefone com nome e WhatsApp do cliente.',
            'textos' => 'Frases que aparecem na vitrine pública (banner e títulos das seções).',
            'segmento' => 'Tipo de negócio (alimentação, varejo ou serviços) e categorias exibidas na vitrine.',
            'conta' => 'Na aba Meu Plano, acompanhe seu plano, teste grátis e formas de pagamento.',
            'seguranca' => 'Troque usuário e senha de entrada. Não apague nada aqui sem ter certeza.',
        ],
        'painel' => [
            'titulo' => 'Pedidos da sua loja',
            'intro' => 'Cada pedido é um cartão. Use o botão laranja grande em cada cartão para avançar a etapa. Abaixo está o que faz cada botão do topo.',
            'rodape_colunas' => 'Arrastar com o dedo também funciona, mas o botão laranja é o mais fácil.',
            'lixeira' => 'Apaga todos os pedidos guardados. Só use quando tiver certeza.',
            'ferramentas' => pedidozap_default_ajuda_painel_ferramentas(),
            'colunas' => pedidozap_default_ajuda_painel_colunas(),
        ],
        'cozinha' => [
            'titulo' => 'Tela da cozinha',
            'intro' => 'Só aparecem pedidos que alguém já mandou para preparar. Imprima ou marque pronto quando sair.',
            'sem_link' => 'Abra esta página pelo botão marrom “Link da cozinha” na tela de pedidos. Se digitar o endereço na mão, não funciona.',
            'ferramentas' => [
                ['id' => 'impressao', 'titulo' => 'Impressão automática', 'texto' => 'Ligada = pedido novo imprime na térmica 80 mm sozinho. Mantenha o papel na impressora.'],
                ['id' => 'som', 'titulo' => 'Som', 'texto' => 'Avisa com um bip quando entra pedido novo na cozinha.'],
                ['id' => 'atualizar', 'titulo' => 'Atualizar', 'texto' => 'Busca pedidos de novo. Use se a tela parou de mudar.'],
            ],
            'fichas_titulo' => 'Botões em cada pedido da lista',
            'fichas' => pedidozap_default_ajuda_cozinha_fichas(),
        ],
        'cardapio' => [
            'ativo' => true,
            'titulo' => 'Como pedir — é fácil',
            'intro' => 'Siga os 4 passos abaixo. Qualquer dúvida, fale com a loja pelo WhatsApp no final da página.',
            'passos' => pedidozap_default_ajuda_cardapio_passos(),
            'carrinho_flutuante' => 'Seu pedido fica nesta faixa. Toque em Ver pedido para continuar.',
            'modal_carrinho' => 'Confira cada item. Você pode mudar quantidade antes de seguir.',
            'modal_endereco' => 'Coloque nome, telefone e endereço corretos para a entrega chegar certa.',
            'checkout_frete' => 'O valor da entrega (se houver) aparece no resumo antes de você confirmar.',
            'checkout_final' => 'Escolha como quer receber e toque para enviar o pedido para a loja.',
        ],
        'entregador' => [
            'titulo' => 'Celular do entregador',
            'intro' => 'Escolha o pedido na lista, toque em Começar a entrega e permita o GPS. O cliente vê onde você está no mapa.',
            'lista' => 'Mostra pedidos que já saíram para entrega. Toque no nome certo antes de começar.',
            'sem_pedido' => 'Se não aparecer, volte ao painel de pedidos e confira se o pedido está em “saiu para entrega”.',
            'gps' => 'O celular pode pedir permissão de localização. Toque em Permitir — sem isso o mapa não funciona.',
        ],
        'atendimento' => [
            'extra' => 'Primeiro coloque nome e telefone de quem está pedindo. Depois toque no botão grande para abrir o cardápio igual ao do cliente.',
        ],
    ];
}

function pedidozap_icone_categoria($slug, $iconeAtual = '')
{
    $s = strtolower(trim((string) $slug));
    $cur = trim((string) $iconeAtual);
    $mapa = [
        'promocoes' => 'fa-tags', 'destaques' => 'fa-tags', 'ofertas' => 'fa-tags',
        'combos' => 'fa-box-open', 'kits' => 'fa-box-open', 'novidades' => 'fa-star',
        'catalogo' => 'fa-bag-shopping', 'acessorios' => 'fa-bag-shopping',
        'servicos' => 'fa-briefcase',
        'lanches' => 'fa-burger', 'hamburgueres' => 'fa-burger',
        'porcoes' => 'fa-bowl-food', 'guarnicoes' => 'fa-bowl-food',
        'bebidas' => 'fa-martini-glass-citrus', 'refrigerantes' => 'fa-martini-glass-citrus',
        'cervejas' => 'fa-beer-mug-empty', 'destilados' => 'fa-wine-glass', 'conveniencia' => 'fa-snowflake',
        'sobremesas' => 'fa-cake-candles', 'bolos' => 'fa-cake-candles', 'doces' => 'fa-candy-cane',
        'pizzas' => 'fa-pizza-slice', 'bordas' => 'fa-pizza-slice',
        'acai' => 'fa-ice-cream', 'complementos' => 'fa-ice-cream',
        'pratos' => 'fa-utensils', 'marmitas' => 'fa-utensils', 'executivos' => 'fa-utensils', 'sopas' => 'fa-mug-hot',
        'hortifruti' => 'fa-carrot', 'mercearia' => 'fa-basket-shopping', 'mercado' => 'fa-basket-shopping',
        'acougue' => 'fa-drumstick-bite', 'limpeza' => 'fa-pump-soap', 'higiene' => 'fa-pump-soap',
        'medicamentos' => 'fa-pills', 'bebe' => 'fa-baby', 'suplementos' => 'fa-heart-pulse',
        'caes' => 'fa-paw', 'gatos' => 'fa-paw', 'petiscos' => 'fa-paw', 'higiene_pet' => 'fa-paw',
        'feminino' => 'fa-shirt', 'masculino' => 'fa-shirt', 'calcados' => 'fa-shoe-prints',
        'celulares' => 'fa-mobile-screen-button', 'audio' => 'fa-headphones',
        'informatica' => 'fa-laptop', 'energia' => 'fa-bolt',
        'maquiagem' => 'fa-wand-magic-sparkles', 'skincare' => 'fa-wand-magic-sparkles', 'cabelos' => 'fa-wand-magic-sparkles',
        'perfumes' => 'fa-gift', 'presentes' => 'fa-gift', 'flores' => 'fa-seedling',
        'escolar' => 'fa-pen-ruler', 'brinquedos' => 'fa-puzzle-piece',
        'ferramentas' => 'fa-screwdriver-wrench', 'eletrica' => 'fa-lightbulb',
        'hidraulica' => 'fa-faucet', 'utilidades' => 'fa-house',
    ];
    if (isset($mapa[$s]) && ($cur === '' || $cur === 'fa-utensils')) {
        return $mapa[$s];
    }
    if ($cur !== '' && $cur !== 'fa-utensils') {
        return $cur;
    }
    return $mapa[$s] ?? 'fa-store';
}

function pedidozap_default_categorias()
{
    return [
        'promocoes' => [
            'nome' => 'Promoções',
            'subtitulo' => 'Melhores ofertas',
            'icone' => 'fa-tags',
            'emoji' => '🏷️',
            'cor' => '#dc2626',
            'ativo' => true,
        ],
        'hamburgueres' => [
            'nome' => 'Hambúrgueres',
            'subtitulo' => 'Artesanais',
            'icone' => 'fa-burger',
            'emoji' => '🍔',
            'cor' => '#f97136',
            'ativo' => true,
        ],
        'porcoes' => [
            'nome' => 'Porções',
            'subtitulo' => 'Para compartilhar',
            'icone' => 'fa-hotdog',
            'emoji' => '🍟',
            'cor' => '#f59e0b',
            'ativo' => true,
        ],
        'bebidas' => [
            'nome' => 'Bebidas',
            'subtitulo' => 'Geladas',
            'icone' => 'fa-martini-glass-citrus',
            'emoji' => '🥤',
            'cor' => '#2563eb',
            'ativo' => true,
        ],
        'combos' => [
            'nome' => 'Combos',
            'subtitulo' => 'Ofertas completas',
            'icone' => 'fa-box-open',
            'emoji' => '📦',
            'cor' => '#b91c1c',
            'ativo' => true,
        ],
        'acai' => [
            'nome' => 'Açaís',
            'subtitulo' => 'Do seu jeito',
            'icone' => 'fa-ice-cream',
            'emoji' => '🫐',
            'cor' => '#7e22ce',
            'ativo' => true,
        ],
    ];
}

function pedidozap_default_config()
{
    $pagina = [
        'titulo' => '',
        'subtitulo' => '',
        'hero' => '',
    ];

    return [
        'TENANT_SLUG' => 'default',
        'NOME_LOJA' => 'Nome da Sua Loja',
        'SLOGAN' => 'Peça online e receba no conforto da sua casa.',
        'DESCRICAO_SEO' => 'Catálogo online para pedidos diretos pelo WhatsApp.',
        'PALAVRAS_CHAVE' => '',
        'WHATSAPP_NUMBER' => '',
        'IDENTIDADE' => [
            'LOGO' => 'assets/images/placeholder-logo.svg',
            'FAVICON' => 'assets/images/placeholder-logo.svg',
            'HERO' => 'assets/images/placeholder-hero.svg',
            'CORES' => [
                'primaria' => '#f97136',
                'secundaria' => '#1f2937',
                'fundo' => '#f9fafb',
            ],
        ],
        'TEXTOS' => [
            'HERO_TITULO' => 'Bem-vindo à sua loja',
            'HERO_SUBTITULO' => 'Escolha seus favoritos e peça agora.',
            'SECAO_OFERTAS' => 'Ofertas do Dia',
            'SECAO_DESTAQUES' => 'Destaques',
            'SECAO_CARDAPIO' => 'Explore nosso Catálogo',
            'SECAO_AVALIACOES' => 'O que nossos clientes dizem',
            'PAGINAS' => [
                'hamburgueres' => $pagina,
                'porcoes' => $pagina,
                'bebidas' => $pagina,
                'acai' => $pagina,
                'combos' => $pagina,
            ],
        ],
        'BANNER_WPP' => [
            'ativo' => false,
            'titulo' => 'Quer ofertas exclusivas?',
            'mensagem' => 'Olá! Vi as ofertas no site e quero saber os descontos de hoje.',
            'botao' => 'Pedir no Zap',
        ],
        'UPSELL' => [
            'ativo' => false,
            'produto_id' => '',
            'imagem' => '',
            'titulo' => 'Que tal adicionar mais um item?',
            'subtitulo' => 'Complete seu pedido com uma sugestão da casa.',
        ],
        'AVALIACOES' => [],
        'CHECKOUT' => [
            'aviso_observacoes' => 'Use este campo apenas para avisos ou remoção de ingredientes. Itens extras pedidos aqui não serão cobrados.',
            'agendamento_inicio' => '18:00',
            'agendamento_fim' => '23:30',
            'agendamento_texto' => 'Agendamentos disponíveis apenas no horário de funcionamento.',
        ],
        'ENDERECO' => [
            'rua' => '',
            'numero' => '',
            'bairro' => '',
            'cidade' => '',
            'estado' => '',
            'cep' => '',
            'lat' => null,
            'lng' => null,
        ],
        'REDES' => [
            'instagram' => '',
            'facebook' => '',
            'whatsapp' => '',
        ],
        'RODAPE' => [
            'titulo_horario' => 'Horário',
            'titulo_contato' => 'Fale com a gente',
            'frase' => '',
        ],
        'HORARIOS' => [
            'SEMANA' => ['inicio' => '18:00', 'fim' => '23:30'],
            'FIM_DE_SEMANA' => ['inicio' => '18:00', 'fim' => '00:00'],
            'FECHADO' => [],
            'TEXTO' => '',
            'TEXTO_FOLGA' => '',
        ],
        'ALTA_DEMANDA' => false,
        'TEMPO_ESPERA' => '40-60 min',
        'TEMPO_ENTREGA_MIN' => 35,
        'CNPJ' => '',
        'RAZAO_SOCIAL' => '',
        'FRETE' => [
            'modo' => 'gratis',
            'taxa_fixa' => 0,
            'unidade_distancia' => 'km',
            'taxa_base' => 0,
            'valor_unidade' => 0,
            'raio_max_km' => 0,
            'usar_faixas' => false,
            'faixas' => [],
            'frete_gratis_acima' => 0,
            'pedido_minimo' => 0,
            'peso_unidade' => 'kg',
            'peso_taxa_base' => 0,
            'peso_valor_unidade' => 0,
            'bairros' => [],
        ],
        'PAGAMENTOS' => [
            'pix' => true,
            'debito' => true,
            'credito' => true,
            'dinheiro' => true,
            'chave_pix' => '',
        ],
        'NICHO' => 'lanchonete',
        'LOGISTICA' => [
            'modelo' => 'propria',
            'aceita_retirada' => true,
            'despacho_manual' => false,
        ],
        'ATENDIMENTO' => [
            'ativo' => true,
            'titulo_pagina' => 'Pedido manual',
            'instrucao' => 'Informe o cliente e abra o catálogo para montar o pedido.',
            'label_nome' => 'Nome do cliente',
            'label_telefone' => 'Telefone (com DDD)',
            'btn_abrir_cardapio' => 'Abrir catálogo',
            'banner_cardapio' => 'Modo atendente — pedido manual',
            'texto_sucesso' => 'Pedido registrado. Confira no painel de pedidos.',
            'abrir_whatsapp' => false,
            'modo_padrao' => 'retirada',
            'rotulo_entrega_manual' => 'Pedido pelo balcão / telefone',
            'rotulo_badge_painel' => 'Balcão',
            'link_voltar_painel' => 'Voltar ao painel de pedidos',
            'link_novo_pedido' => 'Fazer outro pedido',
            'btn_confirmar_pedido' => 'Registrar pedido',
            'btn_confirmar_whatsapp' => 'Enviar pedido pelo WhatsApp',
            'texto_loja_fechada' => 'Loja fechada — agende o pedido',
        ],
        'COMANDA' => [
            'titulo_via_cozinha' => 'PREPARO / EXPEDIÇÃO',
            'titulo_via_baladao' => 'BALCÃO',
            'rotulo_pedido' => 'Pedido',
            'rotulo_itens' => 'ITENS',
            'rotulo_cliente' => 'Cliente',
            'rotulo_telefone' => 'Tel.',
            'rotulo_entrega' => 'Entrega',
            'rotulo_pagamento' => 'Pagamento',
            'rotulo_total' => 'TOTAL',
            'rotulo_frete' => 'Taxa entrega',
            'rotulo_obs' => 'OBS',
            'rodape_cozinha' => 'Confira os itens antes de enviar.',
            'rodape_completa' => 'Obrigado pela preferência!',
            'mostrar_pagamento_cozinha' => false,
            'mostrar_telefone_cozinha' => true,
            'largura_mm' => 80,
            'painel_imprimir_ligado' => 'Imprimir ao mandar para preparo',
            'painel_imprimir_desligado' => 'Só imprime na tela de preparo',
            'cozinha_btn_imprimir' => 'Reimprimir comanda',
            'cozinha_btn_pronto' => 'Pronto — sai para entrega',
            'cozinha_vazio' => 'Nenhum pedido em preparo ou separação no momento.',
            'cozinha_instrucao' => 'Deixe esta página aberta na impressora térmica 80 mm.',
            'erro_popup' => 'Libere pop-ups neste site para imprimir a comanda.',
            'texto_canal_balcao' => 'Balcão',
            'painel_imprimir_auto' => true,
        ],
        'RELATORIO' => [
            'titulo' => 'Relatório mensal',
            'subtitulo' => 'Quantidade pedida de cada item no período.',
            'rotulo_mais_vendidos' => 'O que mais saiu',
            'rotulo_menos_vendidos' => 'O que menos saiu',
            'rotulo_tabela' => 'Todos os itens do mês',
            'coluna_item' => 'Item',
            'coluna_qtd' => 'Quantidade',
            'coluna_valor' => 'Valor (R$)',
            'vazio' => 'Nenhum pedido neste mês.',
        ],
        'TUTORIAL' => [
            'ativo' => true,
            'titulo' => 'Primeiros passos',
            'subtitulo' => 'Siga esta ordem na primeira semana com a sua loja.',
            'intro' => 'Cada etapa leva poucos minutos. Você pode marcar como feito e voltar quando quiser.',
            'btn_abrir' => 'Abrir tutorial passo a passo',
            'btn_ir_passo' => 'Ir fazer agora',
            'btn_marcar' => 'Marcar como feito',
            'btn_desmarcar' => 'Desmarcar',
            'texto_progresso' => 'concluídos',
            'rotulo_video' => 'Vídeo de ajuda (opcional)',
            'video_url' => '',
            'destaque_novos_dias' => 14,
            'passos' => pedidozap_default_tutorial_passos(),
            'primeiro_acesso_ativo' => true,
            'primeiro_acesso_titulo' => 'Bem-vindo! Quer um tour rápido?',
            'primeiro_acesso_texto' => 'Montamos um passo a passo bem simples. Em uns 15 minutos você deixa a loja pronta — sem precisar ligar para ninguém.',
            'primeiro_acesso_btn_tutorial' => 'Sim, me mostre o passo a passo',
            'primeiro_acesso_btn_depois' => 'Agora não, quero explorar',
        ],
        'AJUDA' => pedidozap_default_ajuda(),
        'SIMPLICIDADE' => [
            'titulo_painel' => 'Painel da sua loja',
            'subtitulo_painel' => 'Toque na parte que quer mudar. Quando terminar, toque no botão verde Salvar lá em cima.',
            'dica_abas' => 'Escolha um tema abaixo. Não precisa mudar tudo hoje — vá por partes.',
            'regra_1' => 'Sempre toque em Salvar depois de mudar algo.',
            'regra_2' => 'Em dúvida? Abra o passo a passo em Primeiros passos.',
            'regra_3' => 'Pedidos do cliente ficam no botão laranja Ver pedidos.',
            'lembrete_salvar_titulo' => 'Não esqueça de Salvar',
            'lembrete_salvar' => 'O cliente só vê as mudanças depois que você tocar em Salvar no topo.',
            'painel_instrucao' => 'Novo pedido? Toque no botão laranja em cada card para avançar. Para cozinha, use Começar a preparar — a comanda pode imprimir e aparece no link da cozinha (botão marrom acima). Deixe o som ligado para ouvir cada pedido.',
            'login_titulo' => 'Entrar no painel da loja',
            'login_dica' => 'Digite o usuário e a senha que você criou no cadastro. Se esqueceu, entre uma vez e troque na aba Senha.',
            'login_btn' => 'Entrar no painel',
            'login_erro' => 'Usuário ou senha incorretos. Confira e tente de novo.',
        ],
        'CATEGORIAS' => pedidozap_default_categorias(),
        'AGENTE_IA' => [
            'nome' => 'Copiloto AcheIAki IA',
            'vitrine_ativo' => true,
            'tom' => 'profissional_amigavel',
            'alerta_margem_minima' => 25,
            'fidelidade_ativo' => true,
            'fidelidade_pontos_por_real' => 1,
            'fidelidade_pedidos_cupom' => 5,
            'fidelidade_cupom_codigo' => 'FIDELIDADE10',
            'resumo_whatsapp_ativo' => true,
        ],
        'VERSION' => 1,
        'NIVEL_EXPERIENCIA' => 'basico',
        'NIVEL_EXPERIENCIA_CONFIRMADO' => false,
    ];
}

function pedidozap_normalizar_config($saved, $tenant = 'default')
{
    $config = pedidozap_merge_assoc(pedidozap_default_config(), is_array($saved) ? $saved : []);
    $config['TENANT_SLUG'] = $tenant;
    $nivelBruto = strtolower(trim((string) ($config['NIVEL_EXPERIENCIA'] ?? 'basico')));
    if ($nivelBruto === 'facil') {
        $nivelBruto = 'basico';
    } elseif ($nivelBruto === 'completo') {
        $nivelBruto = 'expert';
    }
    $config['NIVEL_EXPERIENCIA'] = in_array($nivelBruto, ['basico', 'avancado', 'expert'], true) ? $nivelBruto : 'basico';
    $config['NIVEL_EXPERIENCIA_CONFIRMADO'] = !empty($config['NIVEL_EXPERIENCIA_CONFIRMADO']);
    if (!isset($config['NOME_LOJA']) || trim((string) $config['NOME_LOJA']) === '') {
        $config['NOME_LOJA'] = 'Nome da Sua Loja';
    }

    // Atualiza textos e ícones legados de versões antigas que ainda apontavam só para comida/cardápio
    $secCard = trim((string) ($config['TEXTOS']['SECAO_CARDAPIO'] ?? ''));
    if ($secCard === '' || strcasecmp($secCard, 'Explore nosso Cardápio') === 0 || strcasecmp($secCard, 'Cardápio') === 0) {
        $config['TEXTOS']['SECAO_CARDAPIO'] = 'Explore nosso Catálogo';
    }
    if (($config['DESCRICAO_SEO'] ?? '') === 'Cardápio digital para pedidos pelo WhatsApp.') {
        $config['DESCRICAO_SEO'] = 'Catálogo online para pedidos diretos pelo WhatsApp.';
    }
    if (($config['ATENDIMENTO']['btn_abrir_cardapio'] ?? '') === 'Abrir cardápio') {
        $config['ATENDIMENTO']['btn_abrir_cardapio'] = 'Abrir catálogo';
    }
    if (($config['ATENDIMENTO']['instrucao'] ?? '') === 'Informe o cliente e abra o cardápio para montar o pedido.') {
        $config['ATENDIMENTO']['instrucao'] = 'Informe o cliente e abra o catálogo para montar o pedido.';
    }
    if (($config['COMANDA']['cozinha_vazio'] ?? '') === 'Nenhum pedido na cozinha no momento.') {
        $config['COMANDA']['cozinha_vazio'] = 'Nenhum pedido em preparo ou separação no momento.';
    }
    if (($config['COMANDA']['painel_imprimir_ligado'] ?? '') === 'Imprimir ao mandar pra cozinha') {
        $config['COMANDA']['painel_imprimir_ligado'] = 'Imprimir ao mandar para preparo';
    }
    if (($config['COMANDA']['painel_imprimir_desligado'] ?? '') === 'Só imprime na tela da cozinha') {
        $config['COMANDA']['painel_imprimir_desligado'] = 'Só imprime na tela de preparo';
    }
    if (is_array($config['CATEGORIAS'] ?? null)) {
        foreach ($config['CATEGORIAS'] as $catSlug => &$catInfo) {
            if (is_array($catInfo)) {
                $catInfo['icone'] = pedidozap_icone_categoria((string) $catSlug, (string) ($catInfo['icone'] ?? ''));
            }
        }
        unset($catInfo);
    }

    $config['ENDERECO']['lat'] = pedidozap_nullable_float($config['ENDERECO']['lat'] ?? null);
    $config['ENDERECO']['lng'] = pedidozap_nullable_float($config['ENDERECO']['lng'] ?? null);

    if (!is_array($config['AVALIACOES'])) {
        $config['AVALIACOES'] = [];
    }
    if (!is_array($config['FRETE']['faixas'])) {
        $config['FRETE']['faixas'] = [];
    }
    if (!is_array($config['FRETE']['bairros'])) {
        $config['FRETE']['bairros'] = [];
    }
    if (!is_array($config['HORARIOS']['FECHADO'])) {
        $config['HORARIOS']['FECHADO'] = [];
    }
    if (!isset($config['TUTORIAL']) || !is_array($config['TUTORIAL'])) {
        $config['TUTORIAL'] = pedidozap_default_config()['TUTORIAL'];
    }
    if (!is_array($config['TUTORIAL']['passos'] ?? null) || $config['TUTORIAL']['passos'] === []) {
        $config['TUTORIAL']['passos'] = pedidozap_default_tutorial_passos();
    }
    if (!isset($config['SIMPLICIDADE']) || !is_array($config['SIMPLICIDADE'])) {
        $config['SIMPLICIDADE'] = pedidozap_default_config()['SIMPLICIDADE'];
    }
    if (!isset($config['AJUDA']) || !is_array($config['AJUDA'])) {
        $config['AJUDA'] = pedidozap_default_ajuda();
    }
    $ajudaPadrao = pedidozap_default_ajuda();
    if (!is_array($config['AJUDA']['painel']['ferramentas'] ?? null) || $config['AJUDA']['painel']['ferramentas'] === []) {
        $config['AJUDA']['painel']['ferramentas'] = $ajudaPadrao['painel']['ferramentas'];
    }
    if (!is_array($config['AJUDA']['painel']['colunas'] ?? null) || $config['AJUDA']['painel']['colunas'] === []) {
        $config['AJUDA']['painel']['colunas'] = $ajudaPadrao['painel']['colunas'];
    }
    if (!is_array($config['AJUDA']['cozinha']['ferramentas'] ?? null) || $config['AJUDA']['cozinha']['ferramentas'] === []) {
        $config['AJUDA']['cozinha']['ferramentas'] = $ajudaPadrao['cozinha']['ferramentas'];
    }
    if (!is_array($config['AJUDA']['cozinha']['fichas'] ?? null) || $config['AJUDA']['cozinha']['fichas'] === []) {
        $config['AJUDA']['cozinha']['fichas'] = $ajudaPadrao['cozinha']['fichas'];
    }
    if (!isset($config['AJUDA']['cardapio']) || !is_array($config['AJUDA']['cardapio'])) {
        $config['AJUDA']['cardapio'] = $ajudaPadrao['cardapio'];
    }
    if (!is_array($config['AJUDA']['cardapio']['passos'] ?? null) || $config['AJUDA']['cardapio']['passos'] === []) {
        $config['AJUDA']['cardapio']['passos'] = $ajudaPadrao['cardapio']['passos'];
    }

    foreach (['ADMIN_AUTH', 'ADMIN_PASS', 'ADMIN_USER', 'PASS', 'USER', 'SENHA', 'pass_hash'] as $secret) {
        unset($config[$secret]);
    }

    return $config;
}

/**
 * Expõe só categorias da config — evita chaves fantasmas (hamburgueres vazio etc.).
 */
function pedidozap_filtrar_cardapio_publico(array $cardapio, array $config)
{
    $cats = $config['CATEGORIAS'] ?? [];
    if (!is_array($cats) || $cats === []) {
        return is_array($cardapio) ? $cardapio : [];
    }
    $out = [];
    foreach (array_keys($cats) as $slug) {
        $meta = $cats[$slug] ?? [];
        if (isset($meta['ativo']) && $meta['ativo'] === false) {
            continue;
        }
        $lista = $cardapio[$slug] ?? [];
        $out[$slug] = is_array($lista) ? $lista : [];
    }
    return $out;
}

function pedidozap_limpar_cardapio_para_salvar(array $cardapio, array $config)
{
    $filtrado = pedidozap_filtrar_cardapio_publico($cardapio, $config);
    foreach ($cardapio as $chave => $lista) {
        if (!isset($filtrado[$chave]) && is_array($lista)) {
            $filtrado[$chave] = $lista;
        }
    }
    foreach ($filtrado as $cat => &$lista) {
        if (!is_array($lista)) {
            continue;
        }
        foreach ($lista as $i => &$item) {
            if (!is_array($item)) {
                continue;
            }
            $img = preg_replace('/[\x00-\x1F\x7F]/', '', trim((string) ($item['imagem'] ?? '')));
            if ($img === '' || stripos($img, 'data:image') === 0) {
                $item['imagem'] = 'assets/images/placeholder-logo.svg';
            } elseif (strlen($img) > 500) {
                $item['imagem'] = function_exists('mb_substr') ? mb_substr($img, 0, 500, 'UTF-8') : substr($img, 0, 500);
            } else {
                $item['imagem'] = $img;
            }
            unset($item['imagens'], $item['galeria'], $item['fotos']);
        }
        unset($item);
    }
    unset($lista);
    return $filtrado;
}

/** Tutorial e textos de ajuda vêm do PHP (store_schema); não duplicar no SQL. */
function pedidozap_config_para_sql(array $config)
{
    $c = $config;
    unset($c['TUTORIAL'], $c['AJUDA']);
    return $c;
}

function pedidozap_cardapio_refs_imagem_produto(array $cardapio)
{
    $nomes = [];
    foreach ($cardapio as $lista) {
        if (!is_array($lista)) {
            continue;
        }
        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }
            $img = trim((string) ($item['imagem'] ?? ''));
            if ($img === '' || stripos($img, 'data:image') === 0) {
                continue;
            }
            if (!function_exists('pz_media_nome_de_ref')) {
                continue;
            }
            $nome = pz_media_nome_de_ref($img);
            if ($nome !== null && strpos($nome, 'prod_') === 0) {
                $nomes[$nome] = true;
            }
        }
    }
    return $nomes;
}

/** Remove fotos de produto que saíram do cardápio (uma foto por item). */
function pedidozap_sincronizar_midias_cardapio($tenant, array $cardapioAntigo, array $cardapioNovo)
{
    if (!function_exists('pz_db_media_delete') || !function_exists('pz_media_delete_disk')) {
        return;
    }
    $tenant = preg_replace('/[^a-z0-9_-]/i', '', (string) $tenant) ?: 'default';
    $antes = pedidozap_cardapio_refs_imagem_produto(is_array($cardapioAntigo) ? $cardapioAntigo : []);
    $depois = pedidozap_cardapio_refs_imagem_produto(is_array($cardapioNovo) ? $cardapioNovo : []);
    foreach ($antes as $nome => $_) {
        if (!isset($depois[$nome])) {
            pz_db_media_delete($tenant, $nome);
            pz_media_delete_disk($tenant, $nome);
        }
    }
}

function pedidozap_nullable_float($value)
{
    if ($value === null || $value === '' || is_array($value) || is_object($value)) {
        return null;
    }
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
    }
    if (!is_numeric($value)) {
        return null;
    }
    $num = (float) $value;
    return is_finite($num) ? $num : null;
}

function pedidozap_coords_validas($lat, $lng): bool
{
    $latF = pedidozap_nullable_float($lat);
    $lngF = pedidozap_nullable_float($lng);
    if ($latF === null || $lngF === null) {
        return false;
    }
    if (abs($latF) < 0.0001 && abs($lngF) < 0.0001) {
        return false;
    }
    return abs($latF) <= 90.0 && abs($lngF) <= 180.0;
}

function pedidozap_endereco_loja_texto(array $endereco)
{
    $rua = trim((string) ($endereco['rua'] ?? ''));
    $numero = trim((string) ($endereco['numero'] ?? ''));
    $linha1 = ($rua !== '' && $numero !== '') ? ($rua . ', ' . $numero) : trim($rua . ' ' . $numero);
    $linha2Partes = array_filter([
        $endereco['bairro'] ?? '',
        $endereco['cidade'] ?? '',
        $endereco['estado'] ?? '',
        $endereco['cep'] ?? '',
    ], function ($parte) {
        return trim((string) $parte) !== '';
    });
    return [
        'linha1' => $linha1,
        'linha2' => implode(' - ', $linha2Partes),
    ];
}

function pedidozap_geocode_query($query)
{
    $query = trim((string) $query);
    if (strlen($query) < 5 || strlen($query) > 300) {
        return null;
    }

    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&q=' . rawurlencode($query);
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'User-Agent: AcheIAkiPlatform/1.0 (cardapio digital)',
                'Accept: application/json',
            ],
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $httpCode >= 200 && $httpCode < 300) {
            $raw = $resp;
        }
    }
    if ($raw === false && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => "User-Agent: AcheIAkiPlatform/1.0 (cardapio digital)\r\nAccept: application/json\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
    }
    if ($raw === false || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json[0]['lat'], $json[0]['lon']) || !is_numeric($json[0]['lat']) || !is_numeric($json[0]['lon'])) {
        return null;
    }
    $lat = (float) $json[0]['lat'];
    $lng = (float) $json[0]['lon'];
    if (!pedidozap_coords_validas($lat, $lng)) {
        return null;
    }

    return [
        'lat' => $lat,
        'lng' => $lng,
        'label' => $json[0]['display_name'] ?? '',
    ];
}

function pedidozap_tentar_geocode_loja(array &$config)
{
    $end = $config['ENDERECO'] ?? [];
    if (pedidozap_coords_validas($end['lat'] ?? null, $end['lng'] ?? null)) {
        return;
    }

    $partes = array_filter([
        $end['rua'] ?? '',
        $end['numero'] ?? '',
        $end['bairro'] ?? '',
        $end['cidade'] ?? '',
        $end['estado'] ?? '',
        $end['cep'] ?? '',
        'Brasil',
    ], function ($parte) {
        return trim((string) $parte) !== '';
    });

    if (count($partes) < 3) {
        return;
    }

    $geo = pedidozap_geocode_query(implode(', ', $partes));
    if (!$geo) {
        return;
    }
    $config['ENDERECO']['lat'] = $geo['lat'];
    $config['ENDERECO']['lng'] = $geo['lng'];
}
