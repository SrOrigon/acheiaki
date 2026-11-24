<?php
/**
 * Seed idempotente e seguro da Loja Demonstração AcheIAki (loja-demonstracao-acheiaki).
 * - Prepara vitrine multissetor (alimentação + varejo) com fotos reais, avaliações, cupom DIRETO10 e logo/banner.
 * - Garante acesso de demonstração para clientes testarem o Painel do Dono (usuário: demo / senha: Demo@2026)
 *   sem jamais afrouxar autenticação de lojas reais nem sobrescrever alterações feitas depois no painel.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/store_schema.php';
require_once __DIR__ . '/saas_lib.php';

function pedidozap_garantir_loja_demo($forcar = false)
{
    $slug = 'loja-demonstracao-acheiaki';
    $versaoAlvo = 4;
    $anterior = Database::getTenant();

    try {
        Database::setTenant($slug);
        $cfgExist = Database::readStrict('config.json');
        if (!$forcar && is_array($cfgExist) && (int) ($cfgExist['DEMO_PACK_VERSION'] ?? 0) >= $versaoAlvo) {
            return false;
        }

        $cardapioExist = Database::readStrict('cardapio.json');
        if (!is_array($cardapioExist)) {
            $cardapioExist = [];
        }

        // Preserva URLs de mídia já existentes para itens demo1..demo8 quando válidas
        $imgExistentePorId = [];
        foreach ($cardapioExist as $lista) {
            if (!is_array($lista)) {
                continue;
            }
            foreach ($lista as $it) {
                if (is_array($it) && !empty($it['id']) && !empty($it['imagem'])) {
                    $imgExistentePorId[(string) $it['id']] = (string) $it['imagem'];
                }
            }
        }

        $img = static function ($id, $fallback) use ($imgExistentePorId) {
            return $imgExistentePorId[$id] ?? $fallback;
        };

        $config = pedidozap_normalizar_config(is_array($cfgExist) ? $cfgExist : [], $slug);
        $config['NOME_LOJA'] = 'Loja Demonstração AcheIAki';
        $config['NICHO'] = 'comercio';
        $config['SLOGAN'] = 'Vitrine demonstrativa Taxa Zero: alimentos, varejo, fotos reais e WhatsApp + IA ao vivo.';
        $config['DESCRICAO_SEO'] = 'Loja demonstração interativa AcheIAki para testar pedidos, catálogo com fotos e painel do lojista.';
        $config['WHATSAPP_NUMBER'] = '5521999990000';
        $config['TEMPO_ESPERA'] = '30-45 min';
        $config['TEMPO_ENTREGA_MIN'] = 35;
        $config['NIVEL_EXPERIENCIA'] = 'basico';
        $config['NIVEL_EXPERIENCIA_CONFIRMADO'] = true;
        $config['DEMO_PACK_VERSION'] = $versaoAlvo;
        $config['AJUDA'] = pedidozap_default_ajuda();
        $config['TUTORIAL']['passos'] = pedidozap_default_tutorial_passos();

        $config['IDENTIDADE'] = [
            'LOGO' => 'assets/images/acheiaki-icon.jpg?v=4',
            'FAVICON' => 'assets/images/acheiaki-icon.jpg?v=4',
            'HERO' => 'assets/images/hero-marketplace.jpg?v=3',
            'CORES' => [
                'primaria' => '#f97136',
                'secundaria' => '#231915',
                'fundo' => '#faf7f2',
            ],
        ];

        $config['TEXTOS']['HERO_TITULO'] = 'Loja Demonstração AcheIAki';
        $config['TEXTOS']['HERO_SUBTITULO'] = 'Explore o catálogo com fotos, simule um pedido ou entre no Painel do Dono para testar como fica sua loja.';
        $config['TEXTOS']['SECAO_OFERTAS'] = 'Ofertas em Destaque';
        $config['TEXTOS']['SECAO_DESTAQUES'] = 'Mais Pedidos na Vitrine';
        $config['TEXTOS']['SECAO_CARDAPIO'] = 'Explore nosso Catálogo';
        $config['TEXTOS']['SECAO_AVALIACOES'] = 'Avaliações de quem já usa o sistema';

        $config['ENDERECO'] = [
            'rua' => 'Rua Coronel Agostinho',
            'numero' => '150',
            'bairro' => 'Campo Grande',
            'cidade' => 'Rio de Janeiro',
            'estado' => 'RJ',
            'cep' => '23050360',
            'lat' => -22.9028,
            'lng' => -43.5586,
        ];
        $config['REDES']['whatsapp'] = '5521999990000';
        $config['HORARIOS'] = [
            'SEMANA' => ['inicio' => '08:00', 'fim' => '23:59'],
            'FIM_DE_SEMANA' => ['inicio' => '08:00', 'fim' => '23:59'],
            'FECHADO' => [],
            'TEXTO' => 'Todos os dias · 08:00 às 23:59 (Demo 24h)',
            'TEXTO_FOLGA' => '',
        ];
        $config['FRETE'] = [
            'modo' => 'fixo',
            'taxa_fixa' => 6.0,
            'unidade_distancia' => 'km',
            'taxa_base' => 6.0,
            'valor_unidade' => 0,
            'raio_max_km' => 25.0,
            'usar_faixas' => false,
            'faixas' => [],
            'frete_gratis_acima' => 80.0,
            'pedido_minimo' => 0,
            'peso_unidade' => 'kg',
            'peso_taxa_base' => 0,
            'peso_valor_unidade' => 0,
            'bairros' => [],
        ];
        $config['PAGAMENTOS'] = [
            'pix' => true,
            'debito' => true,
            'credito' => true,
            'dinheiro' => true,
            'chave_pix' => 'demo@acheiaki.com.br',
        ];
        $config['AGENTE_IA'] = [
            'nome' => 'Copiloto AcheIAki IA',
            'vitrine_ativo' => true,
            'tom' => 'profissional_amigavel',
            'alerta_margem_minima' => 25,
            'fidelidade_ativo' => true,
            'fidelidade_pontos_por_real' => 1,
            'fidelidade_pedidos_cupom' => 5,
            'fidelidade_cupom_codigo' => 'DIRETO10',
            'resumo_whatsapp_ativo' => true,
        ];
        $config['AVALIACOES'] = [
            ['nome' => 'Camila Ferreira (Lojista)', 'estrelas' => 5, 'comentario' => 'Subi as fotos pelo celular em 10 minutos e economizei mais de R$ 900 no primeiro mês sem pagar taxa de aplicativo.'],
            ['nome' => 'Ricardo Mendes (Comércio)', 'estrelas' => 5, 'comentario' => 'Meus clientes clicam no link do WhatsApp, já veem tudo com preço real e o pedido cai pronto no painel.'],
            ['nome' => 'Juliana Costa (Cliente)', 'estrelas' => 5, 'comentario' => 'Muito fácil de escolher, não precisa baixar nada e ainda acompanhei a entrega ao vivo!'],
        ];

        $catsDemo = [
            'promocoes' => ['nome' => 'Promoções', 'subtitulo' => 'Ofertas com cupom DIRETO10', 'emoji' => '🏷️', 'icone' => 'fa-tags', 'cor' => '#dc2626', 'ativo' => true],
            'eletronicos' => ['nome' => 'Eletrônicos', 'subtitulo' => 'Áudio, cabos e acessórios', 'emoji' => '📱', 'icone' => 'fa-mobile-screen-button', 'cor' => '#231915', 'ativo' => true],
            'moda' => ['nome' => 'Moda & Estilo', 'subtitulo' => 'Roupas e calçados', 'emoji' => '👕', 'icone' => 'fa-shirt', 'cor' => '#b45309', 'ativo' => true],
            'petshop' => ['nome' => 'Pet Shop', 'subtitulo' => 'Rações e brinquedos', 'emoji' => '🐾', 'icone' => 'fa-paw', 'cor' => '#15803d', 'ativo' => true],
            'beleza' => ['nome' => 'Beleza & Kits', 'subtitulo' => 'Skincare e presentes', 'emoji' => '✨', 'icone' => 'fa-wand-magic-sparkles', 'cor' => '#db2777', 'ativo' => true],
            'lanches' => ['nome' => 'Lanches', 'subtitulo' => 'Artesanais na hora', 'emoji' => '🍔', 'icone' => 'fa-burger', 'cor' => '#f97136', 'ativo' => true],
            'porcoes' => ['nome' => 'Porções', 'subtitulo' => 'Crocantes para dividir', 'emoji' => '🍟', 'icone' => 'fa-bowl-food', 'cor' => '#f59e0b', 'ativo' => true],
            'bebidas' => ['nome' => 'Bebidas', 'subtitulo' => 'Sempre geladas', 'emoji' => '🥤', 'icone' => 'fa-martini-glass-citrus', 'cor' => '#2563eb', 'ativo' => true],
            'sobremesas' => ['nome' => 'Sobremesas', 'subtitulo' => 'Doces especiais', 'emoji' => '🍰', 'icone' => 'fa-cake-candles', 'cor' => '#be185d', 'ativo' => true],
        ];
        $config['CATEGORIAS'] = $catsDemo;
        foreach ($catsDemo as $cSlug => $cMeta) {
            $config['TEXTOS']['PAGINAS'][$cSlug] = [
                'titulo' => $cMeta['nome'],
                'subtitulo' => $cMeta['subtitulo'],
                'hero' => '',
            ];
        }

        $cardapio = [
            'promocoes' => [
                [
                    'id' => 'promo-fone-tws',
                    'nome' => 'Fone Bluetooth TWS + Case Carregadora',
                    'descricao' => 'Som estéreo nítido, microfone integrado e até 18h de bateria com estojo portátil.',
                    'preco' => 79.90,
                    'preco_original' => 119.90,
                    'imagem' => 'assets/images/demo-fone.jpg',
                    'promocao' => true,
                    'destaque' => true,
                    'ativo' => true,
                ],
                [
                    'id' => 'promo-xbacon-combo',
                    'nome' => 'Combo X-Bacon Artesanal + Batata',
                    'descricao' => 'Blend 160g, cheddar cremoso, bacon crocante + porção média de batata frita.',
                    'preco' => 34.90,
                    'preco_original' => 42.00,
                    'imagem' => $img('demo3', 'assets/images/xbacon.png'),
                    'promocao' => true,
                    'destaque' => true,
                    'ativo' => true,
                ],
            ],
            'eletronicos' => [
                [
                    'id' => 'demo-fone-1',
                    'nome' => 'Fone Bluetooth TWS Sem Fio',
                    'descricao' => 'Conexão instantânea Bluetooth 5.3, toque inteligente e garantia direta da loja.',
                    'preco' => 89.90,
                    'imagem' => 'assets/images/demo-fone.jpg',
                    'maisPedido' => true,
                    'ativo' => true,
                ],
            ],
            'moda' => [
                [
                    'id' => 'demo-moda-1',
                    'nome' => 'Camiseta Algodão Premium Terracota',
                    'descricao' => 'Malha 100% algodão penteado, toque macio e caimento moderno (P, M, G e GG).',
                    'preco' => 69.90,
                    'imagem' => 'assets/images/demo-camiseta.jpg',
                    'tamanhos' => [
                        ['nome' => 'Tamanho P', 'preco' => 69.90],
                        ['nome' => 'Tamanho M', 'preco' => 69.90],
                        ['nome' => 'Tamanho G', 'preco' => 69.90],
                        ['nome' => 'Tamanho GG', 'preco' => 74.90],
                    ],
                    'maisPedido' => true,
                    'ativo' => true,
                ],
            ],
            'petshop' => [
                [
                    'id' => 'demo-pet-1',
                    'nome' => 'Kit Ração Premium 3kg + Brinquedo Corda',
                    'descricao' => 'Nutrição completa sem corantes artificiais + corda resistente para diversão do seu pet.',
                    'preco' => 84.90,
                    'imagem' => 'assets/images/demo-racao.jpg',
                    'ativo' => true,
                ],
            ],
            'beleza' => [
                [
                    'id' => 'demo-beleza-1',
                    'nome' => 'Kit Skincare Sérum Vitamina C + Hidratante',
                    'descricao' => 'Tratamento facial completo para hidratação intensa e brilho natural. Ótima opção para presente.',
                    'preco' => 94.90,
                    'imagem' => 'assets/images/demo-kit-beleza.jpg',
                    'maisPedido' => true,
                    'ativo' => true,
                ],
            ],
            'lanches' => [
                [
                    'id' => 'demo1',
                    'nome' => 'X-Burguer Clássico',
                    'descricao' => 'Pão brioche selado na manteiga, hambúrguer 150g suculento, queijo derretido e molho especial.',
                    'preco' => 22.00,
                    'imagem' => $img('demo1', 'assets/images/babyburguer.png'),
                    'ativo' => true,
                ],
                [
                    'id' => 'demo2',
                    'nome' => 'X-Salada Especial',
                    'descricao' => 'Pão brioche, carne artesanal 150g, queijo prato, alface americana, tomate e maionese da casa.',
                    'preco' => 24.00,
                    'imagem' => $img('demo2', 'assets/images/xsalada.png'),
                    'ativo' => true,
                ],
                [
                    'id' => 'demo3',
                    'nome' => 'X-Bacon Artesanal',
                    'descricao' => 'Hambúrguer 160g na chapa, cheddar cremoso e tiras de bacon bem crocantes.',
                    'preco' => 28.00,
                    'imagem' => $img('demo3', 'assets/images/xbacon.png'),
                    'maisPedido' => true,
                    'ativo' => true,
                ],
                [
                    'id' => 'demo4',
                    'nome' => 'Frango Crocante Especial',
                    'descricao' => 'Filé de frango empanado dourado, queijo, salada fresca e molho cremoso.',
                    'preco' => 26.00,
                    'imagem' => $img('demo4', 'assets/images/bigburguer.png'),
                    'ativo' => true,
                ],
            ],
            'porcoes' => [
                [
                    'id' => 'demo5',
                    'nome' => 'Batata Frita Crocante (350g)',
                    'descricao' => 'Sequinhos por fora e macios por dentro, servida quentinha com toque de sal e páprica.',
                    'preco' => 14.00,
                    'imagem' => $img('demo5', 'assets/images/batatagrande.png'),
                    'ativo' => true,
                ],
            ],
            'bebidas' => [
                [
                    'id' => 'demo6',
                    'nome' => 'Refrigerante Lata 350ml',
                    'descricao' => 'Entregue bem gelado para acompanhar seu pedido.',
                    'preco' => 7.00,
                    'imagem' => $img('demo6', 'assets/images/refri-lata.png'),
                    'ativo' => true,
                ],
                [
                    'id' => 'demo7',
                    'nome' => 'Suco Natural da Fruta (400ml)',
                    'descricao' => 'Preparado na hora, refrescante e natural.',
                    'preco' => 9.00,
                    'imagem' => $img('demo7', 'assets/images/refri-lata.png'),
                    'ativo' => true,
                ],
            ],
            'sobremesas' => [
                [
                    'id' => 'demo8',
                    'nome' => 'Brownie de Chocolate Belga',
                    'descricao' => 'Casquinha crocante com interior cremoso de chocolate meio amargo.',
                    'preco' => 12.00,
                    'imagem' => $img('demo8', 'assets/images/salgadinho.png'),
                    'ativo' => true,
                ],
            ],
        ];

        Database::write('config.json', $config);
        Database::write('cardapio.json', $cardapio);
        pedidozap_salvar_credenciais('demo', 'Demo@2026');

        $mkt = Database::readStrict('marketing.json');
        if (!is_array($mkt)) {
            $mkt = ['cupons' => [], 'fidelidade' => []];
        }
        $temCupom = false;
        foreach ((array) ($mkt['cupons'] ?? []) as $cp) {
            if (is_array($cp) && strtoupper((string) ($cp['codigo'] ?? '')) === 'DIRETO10') {
                $temCupom = true;
                break;
            }
        }
        if (!$temCupom) {
            $mkt['cupons'][] = [
                'codigo' => 'DIRETO10',
                'tipo' => 'percentual',
                'valor' => 10,
                'minimo_pedido' => 20,
                'ativo' => true,
                'usos' => 0,
                'criado_em' => date('c'),
            ];
            Database::write('marketing.json', $mkt);
        }

        // Garante registro comercial ativo e Enterprise no SaaS sem risco de bloqueio/shadowban
        pedidozap_salvar_registro_loja($slug, [
            'nome_empresa' => 'Loja Demonstração AcheIAki',
            'razao_social' => 'AcheIAki Tecnologia e Vitrine Local LTDA',
            'nicho' => 'comercio',
            'login' => 'demo',
            'whatsapp' => '5521999990000',
            'cidade' => 'Rio de Janeiro',
            'bairro' => 'Campo Grande',
            'estado' => 'RJ',
            'status' => 'ativo',
            'plano' => 'enterprise',
            'plano_escolhido' => 'enterprise',
            'em_teste' => false,
            'vencimento' => '2030-12-31',
            'destaque' => true,
            'cnpj_verificado' => true,
        ]);

        if (function_exists('pz_cache_del')) {
            pz_cache_del('pub_store_' . $slug);
            pz_cache_del('mkt_lojas_all');
        }
        return true;
    } finally {
        Database::setTenant($anterior);
    }
}
