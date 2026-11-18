/**
 * Assistente PedidoZap — IA local independente (sem API externa).
 * Motor de linguagem natural, base de conhecimento do sistema e cardápios por nicho,
 * memória conversacional, auditoria da loja e controle completo do painel/cadastro.
 */
(function (global) {
    'use strict';

    const NICHOS_INFO = {
        pensao: {
            rotulo: 'Pensão / comida caseira',
            palavras: ['pensao', 'comida caseira', 'caseira', 'almoco', 'almoço', 'pf', 'prato feito', 'restaurante', 'comida']
        },
        marmitaria: {
            rotulo: 'Marmitaria / quentinha',
            palavras: ['marmita', 'marmitaria', 'quentinha', 'executivo', 'fitness', 'fit']
        },
        lanchonete: {
            rotulo: 'Lanchonete / hamburgueria',
            palavras: ['lanche', 'lanchonete', 'hamburguer', 'hamburgueria', 'burger', 'artesanal', 'hot dog', 'cachorro quente', 'pastel', 'salgado']
        },
        pizzaria: {
            rotulo: 'Pizzaria',
            palavras: ['pizza', 'pizzaria', 'esfiha', 'calzone', 'forno']
        },
        acai: {
            rotulo: 'Açaiteria / sorveteria',
            palavras: ['acai', 'acaiteria', 'sorvete', 'sorveteria', 'tigela', 'barca', 'gelato']
        },
        confeitaria: {
            rotulo: 'Doceria / confeitaria',
            palavras: ['doce', 'doceria', 'confeitaria', 'bolo', 'torta', 'brigadeiro', 'padaria', 'sobremesa']
        }
    };

    const CORES_PT = {
        laranja: '#f97136',
        vermelho: '#dc2626',
        azul: '#2563eb',
        verde: '#16a34a',
        roxo: '#7e22ce',
        rosa: '#db2777',
        amarelo: '#eab308',
        dourado: '#d97706',
        preto: '#111827',
        cinza: '#4b5563',
        marrom: '#78350f',
        turquesa: '#0d9488',
        ciano: '#0891b2'
    };

    // Base de conhecimento culinário local para sugestão e criação rápida de cardápios
    const CARDAPIOS_BASE = {
        lanchonete: [
            { cat: 'lanches', nome: 'X-Burger Clássico', descricao: 'Pão brioche, hambúrguer 150g, queijo derretido e molho da casa', preco: 22.00 },
            { cat: 'lanches', nome: 'X-Salada Especial', descricao: 'Pão, hambúrguer, queijo, alface, tomate e maionese temperada', preco: 25.00 },
            { cat: 'lanches', nome: 'X-Bacon Duplo', descricao: 'Dois hambúrgueres, cheddar cremoso e bacon crocante', preco: 32.00 },
            { cat: 'porcoes', nome: 'Batata Frita Média', descricao: 'Porção crocante 300g com sal e orégano', preco: 16.00 },
            { cat: 'porcoes', nome: 'Batata com Cheddar e Bacon', descricao: 'Batata frita 400g coberta com cheddar e cubos de bacon', preco: 26.00 },
            { cat: 'bebidas', nome: 'Coca-Cola Lata 350ml', descricao: 'Bem gelada', preco: 6.50 },
            { cat: 'bebidas', nome: 'Guaraná Antarctica Lata 350ml', descricao: 'Bem gelado', preco: 6.00 },
            { cat: 'sobremesas', nome: 'Brownie com Sorvete', descricao: 'Brownie quentinho com bola de creme', preco: 16.00 }
        ],
        marmitaria: [
            { cat: 'marmitas', nome: 'Marmita P (Tradicional)', descricao: 'Arroz, feijão, carne do dia, guarnição e salada', preco: 18.00 },
            { cat: 'marmitas', nome: 'Marmita M (Tradicional)', descricao: 'Arroz, feijão, 1 carne à escolha, 2 acompanhamentos e salada', preco: 22.00 },
            { cat: 'marmitas', nome: 'Marmita G (Caprichada)', descricao: 'Arroz, feijão, 2 carnes ou bife acebolado, farofa, fritas e salada', preco: 27.00 },
            { cat: 'executivos', nome: 'Executivo Frango Grelhado', descricao: 'Filé de frango grelhado, arroz integral, feijão e legumes', preco: 25.00 },
            { cat: 'bebidas', nome: 'Suco Natural de Laranja 300ml', descricao: 'Feito na hora', preco: 8.00 },
            { cat: 'bebidas', nome: 'Refrigerante Lata 350ml', descricao: 'Coca, Guaraná ou Fanta', preco: 6.00 }
        ],
        pensao: [
            { cat: 'pratos', nome: 'PF Bife Acebolado', descricao: 'Arroz, feijão caseiro, bife acebolado, ovo, farofa e salada', preco: 26.00 },
            { cat: 'pratos', nome: 'PF Frango à Parmegiana', descricao: 'Filé empanado ao molho de tomate, queijo, arroz e batata frita', preco: 29.00 },
            { cat: 'marmitas', nome: 'Marmita Caseira Média', descricao: 'Comida feita no dia com tempero caseiro', preco: 22.00 },
            { cat: 'guarnicoes', nome: 'Porção de Torresmo', descricao: 'Crocante na hora (200g)', preco: 14.00 },
            { cat: 'sopas', nome: 'Caldo Verde 500ml', descricao: 'Com calabresa, couve e torradinhas', preco: 18.00 },
            { cat: 'bebidas', nome: 'Suco de Maracujá 400ml', descricao: 'Natural da fruta', preco: 8.50 }
        ],
        pizzaria: [
            { cat: 'pizzas', nome: 'Pizza Calabresa (Grande 8 fatias)', descricao: 'Molho de tomate, mussarela, calabresa fatiada e cebola', preco: 48.00 },
            { cat: 'pizzas', nome: 'Pizza Mussarela (Grande 8 fatias)', descricao: 'Molho artesanal, bastante mussarela e orégano', preco: 45.00 },
            { cat: 'pizzas', nome: 'Pizza Portuguesa (Grande 8 fatias)', descricao: 'Mussarela, presunto, ovo, cebola, ervilha e azeitona', preco: 52.00 },
            { cat: 'pizzas', nome: 'Pizza Frango com Catupiry', descricao: 'Frango desfiado temperado e requeijão cremoso', preco: 52.00 },
            { cat: 'bordas', nome: 'Borda Recheada de Catupiry', descricao: 'Adicional na pizza inteira', preco: 10.00 },
            { cat: 'bebidas', nome: 'Coca-Cola 2 Litros', descricao: 'Garrafa gelada para a família', preco: 14.00 }
        ],
        acai: [
            { cat: 'acai', nome: 'Açaí no Copo 300ml', descricao: 'Açaí cremoso puro com 2 acompanhamentos grátis', preco: 15.00 },
            { cat: 'acai', nome: 'Açaí no Copo 500ml', descricao: 'Açaí cremoso com 3 acompanhamentos grátis', preco: 21.00 },
            { cat: 'acai', nome: 'Barca de Açaí 1 Litro', descricao: 'Para compartilhar: 5 acompanhamentos + 2 caldas', preco: 42.00 },
            { cat: 'complementos', nome: 'Adicional Leite Ninho + Nutella', descricao: 'Porção extra generosa', preco: 6.00 },
            { cat: 'bebidas', nome: 'Água Mineral Sem Gás 500ml', descricao: 'Gelada', preco: 4.00 }
        ],
        confeitaria: [
            { cat: 'bolos', nome: 'Fatia de Bolo de Chocolate Belga', descricao: 'Massa fofinha com cobertura de brigadeiro cremoso', preco: 14.00 },
            { cat: 'bolos', nome: 'Bolo Caseiro de Cenoura com Chocolate', descricao: 'Bolo inteiro (aprox. 800g)', preco: 38.00 },
            { cat: 'doces', nome: 'Caixa com 6 Brigadeiros Gourmet', descricao: 'Tradicional, ninho e meio amargo', preco: 22.00 },
            { cat: 'doces', nome: 'Pudim de Leite Condensado (Pote)', descricao: 'Com calda caramelizada', preco: 12.00 },
            { cat: 'bebidas', nome: 'Café Expresso / Cappuccino 200ml', descricao: 'Quentinho na hora', preco: 8.00 }
        ]
    };

    // Base de conhecimento sobre o funcionamento do sistema AcheIAki
    const CONHECIMENTO_SISTEMA = [
        {
            chaves: ['acheiaki', 'marketplace', 'algoritmo', 'regiao', 'região', 'melhor preco', 'como funciona o site'],
            resposta: 'O AcheIAki ("Ache + IA + ki") é o Marketplace Inteligente da sua Região: ele organiza as empresas verificadas (CNPJ) da cidade e usa um Algoritmo Inteligente local para ranquear melhor preço por segmento, menor distância no seu raio de entrega e avaliação no território — com pedido direto e taxa zero de comissão.'
        },
        {
            chaves: ['comissao', 'taxa', 'mensalidade', 'plano', 'preco do sistema', 'quanto custa', 'gratis', 'teste'],
            resposta: 'O AcheIAki funciona com mensalidade fixa — ZERO comissão por pedido! Novas empresas têm 7 dias grátis para testar com todas as funções liberadas. Na aba "Mensalidade" você acompanha o vencimento e o pagamento.'
        },
        {
            chaves: ['cozinha', 'comanda', 'impressora', '80mm', 'imprimir'],
            resposta: 'A Tela da Cozinha (cozinha.html) fica aberta perto da impressora térmica 80mm. Quando você aceita um pedido no Painel de Pedidos, a comanda é enviada automaticamente para a cozinha e pode ser impressa na hora! No modo completo, a aba "Cozinha / comanda" deixa personalizar os textos da via.'
        },
        {
            chaves: ['balcao', 'manual', 'telefone', 'atendimento', 'atendente'],
            resposta: 'Para anotar pedidos de quem chega no balcão ou liga no telefone, use o botão verde "Pedido manual (balcão)" (atendimento.html). Você informa nome e telefone do cliente, monta o pedido no cardápio e ele cai direto no seu painel e na cozinha.'
        },
        {
            chaves: ['motoboy', 'entregador', 'rastreio', 'acompanhar', 'mapa'],
            resposta: 'A tela do entregador (motoboy.html) foi feita para o celular de quem faz a entrega: ele vê os pedidos prontos, abre a rota e atualiza o status. O cliente recebe o link de rastreio (rastreio.html) para acompanhar o pedido!'
        },
        {
            chaves: ['painel de pedidos', 'kanban', 'ver pedidos', 'onde chegam'],
            resposta: 'Todos os pedidos feitos pelo site ou pelo balcão aparecem no botão laranja "Ver pedidos" (painel.html), organizados por etapa: Novo, Na Cozinha, Saiu para Entrega e Concluído, além de também irem para o seu WhatsApp.'
        },
        {
            chaves: ['foto', 'imagem', 'logo', 'banner', 'capa'],
            resposta: 'Para colocar foto nos pratos: vá na aba "Pratos e preços" e clique no ícone de foto ao lado de cada item para enviar do celular ou computador. Para logo e capa da loja, vá na aba "Nome e cores".'
        },
        {
            chaves: ['modo facil', 'modo completo', 'avancado', 'abas escondidas'],
            resposta: 'O Modo Fácil mostra só o essencial para não confundir (Nome, WhatsApp, Cardápio, Entrega, Pagamento, Mensalidade e Senha). Se quiser ver Relatórios, Comanda, Balcão, Textos do site e Categorias, marque "Mostrar painel completo" ou me diga "painel completo".'
        },
        {
            chaves: ['link', 'url', 'divulgar', 'site', 'vitrine', 'cardapio publico'],
            resposta: 'Para abrir e copiar o link do seu cardápio para colocar na bio do Instagram e no WhatsApp, clique no botão "Ver o cardápio" no topo do painel. Qualquer mudança feita aqui só vai para os clientes depois que você clicar em "Salvar" (ou me pedir "salva").'
        },
        {
            chaves: ['relatorio', 'vendas', 'mais vendido', 'faturamento'],
            resposta: 'Os relatórios de vendas ficam na aba "O que vendeu" (visível no painel completo). Lá você vê o total faturado e o ranking do que mais e menos saiu no mês.'
        }
    ];

    function normalizar(txt) {
        return (txt || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function slugificar(s) {
        return normalizar(s).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'item';
    }

    function formatarMoeda(v) {
        return 'R$ ' + Number(v || 0).toFixed(2).replace('.', ',');
    }

    // Distância de Levenshtein simples para tolerar erros de digitação em nomes de pratos
    function similaridade(a, b) {
        const s1 = normalizar(a);
        const s2 = normalizar(b);
        if (!s1 || !s2) return 0;
        if (s1 === s2) return 1;
        if (s2.includes(s1) || s1.includes(s2)) {
            return Math.min(s1.length, s2.length) / Math.max(s1.length, s2.length) + 0.25;
        }
        const m = s1.length;
        const n = s2.length;
        const dp = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
        for (let i = 0; i <= m; i++) dp[i][0] = i;
        for (let j = 0; j <= n; j++) dp[0][j] = j;
        for (let i = 1; i <= m; i++) {
            for (let j = 1; j <= n; j++) {
                const cost = s1[i - 1] === s2[j - 1] ? 0 : 1;
                dp[i][j] = Math.min(dp[i - 1][j] + 1, dp[i][j - 1] + 1, dp[i - 1][j - 1] + cost);
            }
        }
        return 1 - dp[m][n] / Math.max(m, n);
    }

    // Extrai preço evitando confundir 2L, 500ml, 350g, x-2, etc.
    function extrairPreco(texto) {
        if (!texto) return null;
        // Remove medidas comuns para não confundir com preço
        const limpo = String(texto)
            .replace(/\b\d+\s*(?:ml|l|lt|litro|litros|g|gr|gramas|kg|un|fatias?)\b/gi, ' ')
            .replace(/\bx-?\d+\b/gi, ' ');

        const padroes = [
            /r\$\s*(\d{1,4})(?:[.,](\d{1,2}))?/i,
            /(\d{1,4})(?:[.,](\d{1,2}))?\s*(?:reais?|real|conto|contos|pila)\b/i,
            /(?:por|a|de|para|pra|valor|preco|preço|custa|sai)\s*(?:r\$\s*)?(\d{1,4})(?:[.,](\d{1,2}))?/i,
            /(\d{1,4})[.,](\d{2})\b/,
            /\b(\d{1,4})\b/
        ];

        for (const re of padroes) {
            const m = limpo.match(re);
            if (m) {
                const inteiro = m[1];
                const dec = m[2] ? m[2].padEnd(2, '0').slice(0, 2) : '00';
                const valor = parseFloat(inteiro + '.' + dec);
                if (!isNaN(valor) && valor >= 0 && valor <= 5000) return valor;
            }
        }
        return null;
    }

    function limparNomeItem(texto) {
        let nome = String(texto || '')
            .replace(/\br\$\s*\d{1,4}(?:[.,]\d{1,2})?/gi, '')
            .replace(/\b\d{1,4}(?:[.,]\d{1,2})?\s*(?:reais?|real|conto|contos|pila)\b/gi, '')
            .replace(/\b(?:por|para|pra|custa|valor|preco|preço)\s+\d{1,4}(?:[.,]\d{1,2})?\b/gi, '')
            .replace(/\b\d{1,4}[.,]\d{2}\b/g, '')
            .replace(/\s+\d{1,4}\s*$/g, '')
            .replace(/^(?:quero\s+|pode\s+|por favor\s+)?(?:cadastra(?:r)?|adiciona(?:r)?|coloca(?:r)?|bota(?:r)?|cria(?:r)?|inclui(?:r)?|novo prato|novo item|insere|inserir|poe|põe)\s+/i, '')
            .replace(/^(?:um|uma|uns|umas|o|a|os|as)\s+/i, '')
            .replace(/^[-–•:]\s*/, '')
            .replace(/\s{2,}/g, ' ')
            .trim();

        // Remove preposição solta no final ("X-Bacon por" -> "X-Bacon")
        nome = nome.replace(/\s+(?:por|a|de|no valor de|custando)$/i, '').trim();
        return nome;
    }

    // Divide listas de itens sem quebrar nomes como "Arroz e Feijão por 22"
    function parseListaItens(texto) {
        const bruto = String(texto || '').trim();
        if (!bruto) return [];

        // Primeiro divide por vírgula, ponto e vírgula ou quebra de linha
        const blocosPrimarios = bruto.split(/[,;\n]+/).map((p) => p.trim()).filter(Boolean);
        const partes = [];

        blocosPrimarios.forEach((bloco) => {
            // Só divide por " e " se o lado esquerdo já tiver um preço explícito
            const sub = bloco.split(/\s+e\s+/i);
            if (sub.length > 1) {
                let acumulador = sub[0];
                for (let i = 1; i < sub.length; i++) {
                    if (extrairPreco(acumulador) !== null && extrairPreco(sub[i]) !== null) {
                        partes.push(acumulador.trim());
                        acumulador = sub[i];
                    } else {
                        acumulador += ' e ' + sub[i];
                    }
                }
                if (acumulador.trim()) partes.push(acumulador.trim());
            } else {
                partes.push(bloco);
            }
        });

        const itens = [];
        partes.forEach((p) => {
            const preco = extrairPreco(p);
            if (preco == null) return;

            let descricao = '';
            let trechoNome = p;
            const mDesc = p.match(/\(([^)]{3,120})\)/);
            if (mDesc) {
                descricao = mDesc[1].trim();
                trechoNome = p.replace(mDesc[0], ' ');
            }

            const nome = limparNomeItem(trechoNome);
            if (nome.length < 2) return;
            itens.push({
                nome: nome.charAt(0).toUpperCase() + nome.slice(1),
                preco: preco,
                descricao: descricao
            });
        });
        return itens;
    }

    function acharCategoria(cardapio, config, hint) {
        const h = normalizar(hint || '');
        const catsConfig = (config && config.CATEGORIAS) || {};
        const todasCats = Array.from(new Set([
            ...Object.keys(catsConfig),
            ...Object.keys(cardapio || {})
        ])).filter((c) => c !== 'promocoes');

        // 1. Match direto no nome ou slug da categoria da loja
        for (const c of todasCats) {
            const nome = normalizar((catsConfig[c] || {}).nome || c);
            if (h && (h.includes(nome) || h.includes(normalizar(c)) || (nome.length >= 4 && nome.includes(h)))) {
                return c;
            }
        }

        // 2. Sinônimos culinários mapeados para categorias existentes
        const padroes = [
            { re: /bebida|refri|refrigerante|coca|guarana|fanta|suco|agua|cerveja|chopp|cafe|cha|milkshake/, alvos: ['bebidas'] },
            { re: /porc|batata|frita|calabresa|torresmo|anel|cebola|nugget|isca|salgad/, alvos: ['porcoes', 'guarnicoes'] },
            { re: /marmita|quentinha|executivo|pf|prato feito|bife|feijoada|almoco/, alvos: ['marmitas', 'pratos', 'executivos'] },
            { re: /sopa|caldo/, alvos: ['sopas', 'pratos'] },
            { re: /pizza|broto|esfiha|calzone/, alvos: ['pizzas'] },
            { re: /borda|adicional|complemento/, alvos: ['bordas', 'complementos'] },
            { re: /acai|tigela|barca|sorvete/, alvos: ['acai'] },
            { re: /bolo|torta|fatia/, alvos: ['bolos', 'sobremesas', 'doces'] },
            { re: /doce|brigadeiro|pudim|brownie|sobremesa|mousse|pav/, alvos: ['sobremesas', 'doces', 'bolos'] },
            { re: /lanche|burger|hamburguer|x-|xsalada|xbacon|xtudo|xegg|hot dog|sanduiche|pastel/, alvos: ['lanches', 'hamburgueres'] },
            { re: /combo|promo|oferta|kit/, alvos: ['combos', 'promocoes'] }
        ];

        for (const p of padroes) {
            if (p.re.test(h)) {
                for (const alvo of p.alvos) {
                    if (catsConfig[alvo] || (cardapio && cardapio[alvo])) return alvo;
                }
            }
        }

        return todasCats[0] || 'lanches';
    }

    function validarCnpjLocal(cnpj) {
        const num = String(cnpj || '').replace(/\D/g, '');
        if (num.length !== 14 || /^(\d)\1{13}$/.test(num)) return false;
        const calc = (base, pesos) => {
            let soma = 0;
            for (let i = 0; i < pesos.length; i++) soma += parseInt(base[i], 10) * pesos[i];
            const resto = soma % 11;
            return resto < 2 ? 0 : 11 - resto;
        };
        const d1 = calc(num, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        const d2 = calc(num, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        return parseInt(num[12], 10) === d1 && parseInt(num[13], 10) === d2;
    }

    class AssistenteIA {
        constructor(opcoes) {
            this.ctx = opcoes.contexto || 'admin';
            this.elMsg = opcoes.elMensagens;
            this.elAtalhos = opcoes.elAtalhos;
            this.onCadastroCompleto = opcoes.onCadastroCompleto;
            this.estadoCadastro = { passo: 'livre', dados: {} };
            this.aguardando = null; // Estado de diálogo multi-turno no admin
            this.pilhaDesfazer = []; // Undo local para alterações do assistente
            this.historico = [];
            this.inicializarUi(opcoes);
        }

        inicializarUi(opcoes) {
            if (opcoes.abrirAoIniciar) this.abrirPainel();
            if (this.ctx === 'cadastro') {
                this.renderAtalhos(['Quero uma lanchonete', 'Só marmita', 'Pizzaria', 'Como funciona?']);
                this.falar(
                    opcoes.mensagemInicial ||
                    'Oi! Sou a assistente inteligente do AcheIAki (O Marketplace Inteligente da sua Região).\n' +
                    'Posso criar sua loja conversando — você pode me falar tudo de uma vez (ex.: "Minha loja chama Sabor da Vila, vendo marmita e zap 11999887766") ou ir passo a passo.\n\n' +
                    'Qual o nome do seu estabelecimento (ou que tipo de comida você vende)?'
                );
            } else if (this.ctx === 'painel') {
                this.renderAtalhos([
                    'Quais pedidos estão em aberto agora?',
                    'Quanto vendi hoje?',
                    'O que preciso comprar de estoque?',
                    'Como está o caixa?'
                ]);
                this.falar(
                    opcoes.mensagemInicial ||
                    'Copiloto de Turno ativo! Posso consultar pedidos em aberto, mover status (ex.: "Mover pedido #123 para preparo"), checar estoque crítico ou conferir o caixa.'
                );
            } else {
                this.renderAtalhos([
                    'Quanto vendi e qual meu lucro hoje?',
                    'O que preciso comprar de estoque?',
                    'Qual meu produto mais lucrativo?',
                    'Quais pedidos estão em aberto agora?',
                    'Montar cardápio inicial'
                ]);
                if (!opcoes.silencioso) {
                    this.falar(
                        opcoes.mensagemInicial ||
                        'Sou o Agente IA Integrado do Food Gestão! Consulto vendas reais, DRE, estoque/CMV, caixa e opero o sistema com você:\n' +
                        '• "Quanto vendi hoje?" ou "Qual meu produto mais lucrativo?"\n' +
                        '• "Abrir caixa com 100 reais" ou "Lançar despesa Gás 110 reais"\n' +
                        '• "O que preciso comprar de estoque?" ou "Criar cupom VOLTA10 10%"\n' +
                        '• "Cadastra X-Bacon por 28" ou "Taxa fixa 5 reais"'
                    );
                }
            }
        }

        _escHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        _formatarMarkdownBasico(texto) {
            const seguro = this._escHtml(texto);
            return seguro
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/\n/g, '<br>');
        }

        falar(texto) {
            if (!this.elMsg) return;
            const div = document.createElement('div');
            div.className = 'pz-msg pz-msg-bot';
            div.textContent = texto;
            this.elMsg.appendChild(div);
            this.elMsg.scrollTop = this.elMsg.scrollHeight;
            this.historico.push({ role: 'bot', text: texto });
        }

        falarRico(payload) {
            if (!this.elMsg) return;
            const div = document.createElement('div');
            div.className = 'pz-msg pz-msg-bot';

            const corpo = document.createElement('div');
            corpo.innerHTML = this._formatarMarkdownBasico(payload.resposta || '');
            div.appendChild(corpo);

            if (payload.acao_executada) {
                const okDiv = document.createElement('div');
                okDiv.className = 'pz-acao-ok';
                okDiv.textContent = '✓ ' + payload.acao_executada;
                div.appendChild(okDiv);
            }

            if (Array.isArray(payload.cards_metricas) && payload.cards_metricas.length) {
                const grid = document.createElement('div');
                grid.className = 'pz-msg-cards';
                payload.cards_metricas.forEach((c) => {
                    const card = document.createElement('div');
                    card.className = 'pz-mini-card';
                    card.innerHTML = '<span>' + this._escHtml(c.titulo) + '</span><strong>' + this._escHtml(c.valor) + '</strong>';
                    grid.appendChild(card);
                });
                div.appendChild(grid);
            }

            if (Array.isArray(payload.acoes_ui) && payload.acoes_ui.length) {
                const acoesDiv = document.createElement('div');
                acoesDiv.className = 'pz-msg-acoes';
                payload.acoes_ui.forEach((ac) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'pz-msg-acao-btn';
                    b.textContent = ac.label || 'Abrir';
                    b.addEventListener('click', () => {
                        if (ac.aba && global.__pzAtivarAbaAdmin) {
                            global.__pzAtivarAbaAdmin(ac.aba);
                        } else if (ac.comando) {
                            this.receber(ac.comando);
                        }
                    });
                    acoesDiv.appendChild(b);
                });
                div.appendChild(acoesDiv);
            }

            if (Array.isArray(payload.origem_dados) && payload.origem_dados.length) {
                const meta = document.createElement('div');
                meta.className = 'pz-msg-meta';
                const lbl = document.createElement('span');
                lbl.style.fontSize = '0.72rem';
                lbl.style.color = '#64748b';
                lbl.textContent = 'Origem:';
                meta.appendChild(lbl);
                payload.origem_dados.forEach((src) => {
                    const sp = document.createElement('span');
                    sp.className = 'pz-origem-badge';
                    sp.textContent = src;
                    meta.appendChild(sp);
                });
                div.appendChild(meta);
            }

            this.elMsg.appendChild(div);
            this.elMsg.scrollTop = this.elMsg.scrollHeight;
            this.historico.push({ role: 'bot', text: payload.resposta || '' });
            if (Array.isArray(payload.sugestoes) && payload.sugestoes.length) {
                this.renderAtalhos(payload.sugestoes);
            }
        }

        usuario(texto) {
            if (!this.elMsg) return;
            const div = document.createElement('div');
            div.className = 'pz-msg pz-msg-user';
            div.textContent = texto;
            this.elMsg.appendChild(div);
            this.elMsg.scrollTop = this.elMsg.scrollHeight;
            this.historico.push({ role: 'user', text: texto });
        }

        renderAtalhos(lista) {
            if (!this.elAtalhos) return;
            this.elAtalhos.innerHTML = '';
            (lista || []).forEach((rotulo) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = rotulo;
                b.addEventListener('click', () => this.receber(rotulo));
                this.elAtalhos.appendChild(b);
            });
        }

        _ehComandoEstritamenteLocal(entrada) {
            const n = normalizarTexto(entrada);
            if (this.aguardando) return true;
            if (/^(desfaz|desfazer|ctrl\+z|volta atras|salv(a|ar|e)|publica|salvar tudo)$/.test(n)) return true;
            // Quando o cliente configurou sua IA pessoal, deixa comandos naturais passarem por ela (com fallback local)
            if (global.__pzIaPessoalStatus && global.__pzIaPessoalStatus.ativa) return false;
            if (/painel completo|modo avan|nivel avan|modo expert|nivel expert|nivel basico|modo basico|acesso total|mostrar tudo|todas as abas|modo facil/.test(n)) return true;
            if (/analis(ar)? minha loja|monta(r)? (minha )?(pagina|cardapio)|cardapio inicial|ensina(r)?.*cardapio|como fazer.*cardapio|sugere prato/.test(n)) return true;
            if (/^(cadastra|adiciona|coloca|bota|inclui|novo prato|novo produto)\b/.test(n) && !/fornecedor|insumo|estoque|funcionario|entregador|cupom/.test(n)) return true;
            if (/^(muda|altera|troca|pausa|desativa|ativa|reativa|apaga|exclui|remove)\b/.test(n) && !/pedido|cupom|estoque|caixa/.test(n)) return true;
            return false;
        }

        async _consultarAgenteServidor(entrada, opcoes) {
            let user = '';
            let pass = '';
            try {
                user = sessionStorage.getItem('admin_user') || '';
                pass = sessionStorage.getItem('admin_pass') || '';
            } catch (e) { /* ignore */ }
            if (!user || !pass) return { delegar_local: true };

            const url = typeof global.TENANT !== 'undefined' && global.TENANT.apiUrl
                ? global.TENANT.apiUrl('agente_api.php?action=perguntar')
                : 'agente_api.php?action=perguntar';

            try {
                const resp = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        auth: { user, pass },
                        pergunta: entrada,
                        via_voz: Boolean(opcoes && opcoes.viaVoz)
                    })
                });
                const data = await resp.json();
                return data || { delegar_local: true };
            } catch (err) {
                return { delegar_local: true };
            }
        }

        _aplicarAcoesResposta(res, viaVoz) {
            if (!res) return;
            if (res.acao_abrir_aba && typeof global.__pzAtivarAbaAdmin === 'function') {
                global.__pzAtivarAbaAdmin(res.acao_abrir_aba);
            }
            if (Array.isArray(res.acoes_locais) && res.acoes_locais.length && this.ctx === 'admin') {
                res.acoes_locais.forEach((cmd) => {
                    if (cmd && typeof cmd === 'string') {
                        this.fluxoAdmin(cmd);
                    }
                });
            }
            if (viaVoz && (res.ler_resposta_voz || (global.__pzIaPessoalStatus && global.__pzIaPessoalStatus.ler_resposta_voz))) {
                falarEmVozAlta(res.resposta || '');
            }
        }

        async receber(texto, opcoes) {
            const entrada = (texto || '').trim();
            if (!entrada) return;
            const viaVoz = Boolean(opcoes && opcoes.viaVoz);
            this.usuario(entrada);
            if (this.ctx === 'cadastro') {
                this.fluxoCadastro(entrada);
                return;
            }
            if (this._ehComandoEstritamenteLocal(entrada) && this.ctx === 'admin') {
                this.fluxoAdmin(entrada);
                return;
            }

            const res = await this._consultarAgenteServidor(entrada, opcoes);
            if (res && res.success && !res.delegar_local) {
                this.falarRico(res);
                this._aplicarAcoesResposta(res, viaVoz);
                if (Array.isArray(res.recarregar_modulos) && res.recarregar_modulos.length) {
                    global.dispatchEvent(new CustomEvent('pz:agente-mutacao', { detail: res }));
                }
                return;
            }

            if (this.ctx === 'painel') {
                const msgFallback = 'Não consegui processar no servidor agora. Tente perguntar sobre pedidos em aberto, estoque, caixa ou vendas do dia.';
                this.falar(msgFallback);
                if (viaVoz) falarEmVozAlta(msgFallback);
                return;
            }
            this.fluxoAdmin(entrada);
        }

        detectarNicho(texto) {
            const n = normalizar(texto);
            for (const [id, info] of Object.entries(NICHOS_INFO)) {
                if (info.palavras.some((p) => n.includes(normalizar(p)))) return id;
            }
            return null;
        }

        responderConhecimentoSistema(n) {
            // Dúvidas gerais de funcionamento
            for (const item of CONHECIMENTO_SISTEMA) {
                if (item.chaves.some((c) => n.includes(normalizar(c)))) {
                    return item.resposta;
                }
            }
            return null;
        }

        // =========================================================
        // FLUXO DE CADASTRO INTELIGENTE E MALEÁVEL (cadastro.html)
        // =========================================================
        fluxoCadastro(entrada) {
            const st = this.estadoCadastro;
            const d = st.dados;
            const n = normalizar(entrada);

            if (/^(cancelar|parar|sair)$/.test(n)) {
                this.estadoCadastro = { passo: 'livre', dados: {} };
                this.falar('Tudo bem! Zerei os dados por aqui. Se quiser recomeçar ou usar o formulário acima, é só escolher.');
                return;
            }

            // Responde dúvidas sobre o sistema sem perder o progresso do cadastro!
            if (/\?|como funciona|tem comissao|quanto custa|o que vem|gratis|teste|acheiaki|marketplace/.test(n)) {
                const resp = this.responderConhecimentoSistema(n) ||
                    'O AcheIAki coloca sua empresa no Marketplace Inteligente da Região com algoritmo de preço e localização, cardápio digital, painel de pedidos, cozinha, entregador e IA de gestão — sem comissão por pedido e com 7 dias grátis.';
                this.falar(resp + '\n\n' + this.proximaPerguntaCadastro());
                return;
            }

            // Detecta intenção de editar algum campo no meio ou na confirmação
            if (st.passo === 'confirmar' || /^(mudar|alterar|trocar|corrigir)\b/.test(n)) {
                if (/^(sim|pode criar|criar|confirmo|ok|bora|vai)$/.test(n) && st.passo === 'confirmar') {
                    this.finalizarCadastro();
                    return;
                }
                if (/^(nao|não|recomecar|recomeçar|zerar)$/.test(n) && st.passo === 'confirmar') {
                    this.estadoCadastro = { passo: 'livre', dados: {} };
                    this.falar('Sem problemas! Vamos do zero. Qual o nome do seu estabelecimento?');
                    return;
                }
                if (/(nome|loja)/.test(n)) {
                    const novo = entrada.replace(/^.*?(?:para|pra|:)\s*/i, '').trim();
                    if (novo && novo.length >= 2 && novo !== entrada) {
                        d.nome_empresa = novo;
                        this.falar('Nome atualizado para "' + d.nome_empresa + '".\n' + this.proximaPerguntaCadastro());
                    } else {
                        st.passo = 'nome';
                        this.falar('Qual o novo nome da loja?');
                    }
                    return;
                }
                if (/(tipo|nicho|segmento|comida)/.test(n)) {
                    const nicho = this.detectarNicho(entrada);
                    if (nicho) {
                        d.nicho = nicho;
                        this.falar('Tipo atualizado para ' + NICHOS_INFO[nicho].rotulo + '.\n' + this.proximaPerguntaCadastro());
                    } else {
                        st.passo = 'nicho';
                        this.falar('Qual o tipo de comida? (lanches, marmita, pizza, açaí, pensão ou doces)');
                    }
                    return;
                }
                if (/(zap|whatsapp|telefone|numero)/.test(n)) {
                    const dig = entrada.replace(/\D/g, '');
                    if (dig.length >= 10) {
                        d.whatsapp = dig.slice(0, 13);
                        this.falar('WhatsApp atualizado para ' + d.whatsapp + '.\n' + this.proximaPerguntaCadastro());
                    } else {
                        st.passo = 'whatsapp';
                        this.falar('Qual o novo WhatsApp com DDD?');
                    }
                    return;
                }
                if (/cnpj/.test(n)) {
                    const c = entrada.replace(/\D/g, '');
                    if (c.length === 14 && validarCnpjLocal(c)) {
                        d.cnpj = c;
                        this.falar('CNPJ atualizado!\n' + this.proximaPerguntaCadastro());
                    } else {
                        st.passo = 'cnpj';
                        this.falar('Informe o novo CNPJ válido com 14 dígitos:');
                    }
                    return;
                }
                if (/(cep|endereco|endereço|cidade)/.test(n)) {
                    st.passo = 'cep';
                    this.falar('Digite o novo CEP (8 dígitos) do endereço da empresa:');
                    return;
                }
                if (/raio/.test(n)) {
                    const km = parseInt(entrada.replace(/\D/g, ''), 10);
                    if (km >= 1 && km <= 300) {
                        d.raio_entrega_km = km;
                        this.falar('Raio de entrega atualizado para ' + km + ' km.\n' + this.proximaPerguntaCadastro());
                    } else {
                        st.passo = 'raio';
                        this.falar('Qual o raio máximo de entrega em km (de 1 até 300)?');
                    }
                    return;
                }
                if (/(usuario|login)/.test(n)) {
                    st.passo = 'login';
                    this.falar('Qual o novo usuário para entrar no painel?');
                    return;
                }
                if (/senha/.test(n)) {
                    st.passo = 'senha';
                    this.falar('Digite a nova senha (mínimo 6 caracteres):');
                    return;
                }
            }

            // Tratamento de atalhos ou frases onde o usuário só escolheu o nicho/guia
            if (/me guia|passo a passo|ajuda/.test(n)) {
                st.passo = 'nome';
                this.falar('Combinado! Vou te guiar passo a passo.\nPrimeiro: qual é o nome do seu estabelecimento?');
                return;
            }

            // Extração multi-entidade inteligente se o usuário mandou uma frase completa
            let extraiuAlgo = false;
            const mNomeFrase = entrada.match(/(?:chama|nome(?: da loja)?|e\s+a|minha loja e|sou o|sou a)\s+["']?([A-Za-zÀ-ÿ0-9\s'&.-]{2,45}?)(?=["']?(?:,|\.|;| e | vendo| meu| zap| whats|$))/i);
            if (mNomeFrase && !d.nome_empresa) {
                const candidato = mNomeFrase[1].trim();
                if (!this.detectarNicho(candidato) || candidato.split(' ').length > 1) {
                    d.nome_empresa = candidato;
                    extraiuAlgo = true;
                }
            }

            const nichoDetectado = this.detectarNicho(entrada);
            if (nichoDetectado && (!d.nicho || st.passo === 'nicho' || /^(quero|so|só|vendo|trabalho com|e uma|eh uma)\b/.test(n))) {
                d.nicho = nichoDetectado;
                extraiuAlgo = true;
                // Se a frase for só o nicho (ex.: "Quero uma lanchonete", "Só marmita", "Pizzaria"), NÃO usa como nome!
                if (st.passo === 'livre' || st.passo === 'nome') {
                    const semNicho = n.replace(/quero|uma|um|so|só|de|vendo|trabalho com|lanchonete|marmitaria|marmita|pizzaria|pizza|pensao|acai|confeitaria|doceria/g, '').trim();
                    if (semNicho.length < 3) {
                        this.falar('Boa! Segmento: ' + NICHOS_INFO[nichoDetectado].rotulo + '.\n' + this.proximaPerguntaCadastro());
                        return;
                    }
                }
            }

            const digitos = entrada.replace(/\D/g, '');
            if (digitos.length === 14 && validarCnpjLocal(digitos) && (!d.cnpj || st.passo === 'cnpj')) {
                d.cnpj = digitos;
                extraiuAlgo = true;
            } else if (digitos.length >= 10 && digitos.length <= 13 && (!d.whatsapp || st.passo === 'whatsapp')) {
                d.whatsapp = digitos;
                extraiuAlgo = true;
            }

            // Se estava aguardando um passo específico, preenche o passo atual
            if (!extraiuAlgo || ['login', 'senha', 'nome', 'whatsapp', 'cnpj', 'cep', 'endereco_manual', 'numero', 'raio', 'nivel'].includes(st.passo)) {
                switch (st.passo) {
                    case 'livre':
                    case 'nome':
                        if (!d.nome_empresa) {
                            if (entrada.length < 2) {
                                this.falar('O nome ficou muito curto. Pode digitar o nome da sua loja?');
                                return;
                            }
                            d.nome_empresa = entrada;
                        }
                        break;
                    case 'cnpj': {
                        if (digitos.length !== 14 || !validarCnpjLocal(digitos)) {
                            this.falar('Esse CNPJ não é válido. Todo estabelecimento precisa de um CNPJ válido (14 dígitos) para evitar falhas. Tente novamente:');
                            return;
                        }
                        d.cnpj = digitos;
                        break;
                    }
                    case 'nicho':
                        d.nicho = nichoDetectado || 'lanchonete';
                        break;
                    case 'whatsapp':
                        if (digitos.length < 10) {
                            this.falar('Preciso de pelo menos 10 dígitos com DDD (ex.: 11999887766). Tenta de novo?');
                            return;
                        }
                        d.whatsapp = digitos.slice(0, 13);
                        break;
                    case 'cep': {
                        if (digitos.length !== 8) {
                            this.falar('Digite os 8 dígitos do CEP da sua empresa (ex.: 74000-000):');
                            return;
                        }
                        d.cep = digitos;
                        this.falar('Consultando endereço pelo CEP ' + digitos + '...');
                        fetch('https://viacep.com.br/ws/' + digitos + '/json/')
                            .then((r) => r.json())
                            .then((via) => {
                                if (via && !via.erro && via.localidade && via.uf) {
                                    d.rua = via.logradouro || d.rua || '';
                                    d.bairro = via.bairro || d.bairro || '';
                                    d.cidade = via.localidade;
                                    d.estado = via.uf;
                                    if (!d.rua || !d.bairro) {
                                        st.passo = 'endereco_manual';
                                        this.falar('CEP geral de ' + d.cidade + '-' + d.estado + '. Informe a Rua e Bairro separados por vírgula (ex.: Rua 10, Centro):');
                                        return;
                                    }
                                    this.falar('Endereço encontrado: ' + d.rua + ', ' + d.bairro + ' — ' + d.cidade + '/' + d.estado + '.\n' + this.proximaPerguntaCadastro());
                                } else {
                                    st.passo = 'endereco_manual';
                                    this.falar('CEP não encontrado automaticamente. Informe: Rua, Bairro, Cidade, UF separados por vírgula:');
                                }
                            })
                            .catch(() => {
                                st.passo = 'endereco_manual';
                                this.falar('Sem resposta do ViaCEP. Informe: Rua, Bairro, Cidade, UF separados por vírgula:');
                            });
                        return;
                    }
                    case 'endereco_manual': {
                        const partes = entrada.split(',').map((s) => s.trim()).filter(Boolean);
                        if (partes.length < 2) {
                            this.falar('Separe por vírgula, por favor. Ex.: Av. Brasil, Centro' + (d.cidade ? '' : ', Goiânia, GO'));
                            return;
                        }
                        d.rua = partes[0];
                        d.bairro = partes[1];
                        if (partes[2]) d.cidade = partes[2];
                        if (partes[3]) d.estado = partes[3].slice(0, 2).toUpperCase();
                        if (!d.cidade || !d.estado) {
                            this.falar('Faltou Cidade e UF. Digite: Rua, Bairro, Cidade, UF');
                            return;
                        }
                        break;
                    }
                    case 'numero':
                        if (!entrada.trim()) {
                            this.falar('Informe o número do estabelecimento (ou "S/N"):');
                            return;
                        }
                        d.numero = entrada.trim().slice(0, 20);
                        break;
                    case 'raio': {
                        const km = parseInt(digitos || entrada, 10);
                        if (!km || km < 1 || km > 300) {
                            this.falar('Informe um raio de entrega válido entre 1 e 300 km (ex.: 10 ou 100):');
                            return;
                        }
                        d.raio_entrega_km = km;
                        break;
                    }
                    case 'login': {
                        const u = entrada.replace(/\s+/g, '').toLowerCase();
                        if (u.length < 3) {
                            this.falar('Escolha um usuário com pelo menos 3 letras (ex.: maria ou saborvila).');
                            return;
                        }
                        d.login = u;
                        break;
                    }
                    case 'senha':
                        if (entrada.length < 6) {
                            this.falar('A senha precisa ter pelo menos 6 caracteres. Digite uma senha mais forte:');
                            return;
                        }
                        d.senha = entrada;
                        break;
                    case 'nivel': {
                        if (/expert|total|tecnolog/.test(n)) d.nivel_experiencia = 'expert';
                        else if (/avanc|manual/.test(n)) d.nivel_experiencia = 'avancado';
                        else d.nivel_experiencia = 'basico';
                        break;
                    }
                    case 'confirmar':
                        this.falar('Digite SIM para criar a loja, NÃO para recomeçar, ou me diga o que quer mudar (ex.: "mudar CNPJ", "mudar raio").');
                        return;
                }
            }

            this.falar(this.proximaPerguntaCadastro());
        }

        proximaPerguntaCadastro() {
            const st = this.estadoCadastro;
            const d = st.dados;

            if (!d.nome_empresa) {
                st.passo = 'nome';
                this.renderAtalhos(['Me guia passo a passo']);
                return 'Qual o nome do seu estabelecimento?';
            }
            if (!d.cnpj) {
                st.passo = 'cnpj';
                this.renderAtalhos([]);
                return 'Qual é o CNPJ válido da empresa "' + d.nome_empresa + '"? (Obrigatório, 14 dígitos)';
            }
            if (!d.nicho) {
                st.passo = 'nicho';
                this.renderAtalhos(['Lanches', 'Marmita', 'Pizza', 'Pensão', 'Açaí', 'Doceria']);
                return 'Que tipo de comida a "' + d.nome_empresa + '" vende?';
            }
            if (!d.whatsapp) {
                st.passo = 'whatsapp';
                this.renderAtalhos([]);
                return 'Qual o WhatsApp da loja (com DDD) para receber os pedidos?';
            }
            if (!d.cep || !d.rua || !d.cidade || !d.estado) {
                st.passo = 'cep';
                this.renderAtalhos([]);
                return 'Qual o CEP completo da empresa (8 dígitos)? É obrigatório para aparecer na busca por localização.';
            }
            if (!d.numero) {
                st.passo = 'numero';
                this.renderAtalhos(['S/N']);
                return 'Qual o número do endereço na ' + d.rua + '?';
            }
            if (!d.raio_entrega_km) {
                st.passo = 'raio';
                this.renderAtalhos(['10', '25', '50', '100']);
                return 'Até quantos quilômetros (km) sua empresa entrega? (De 1 até 300 km, ex.: 10 ou 100)';
            }
            if (!d.login) {
                st.passo = 'login';
                const sugestao = slugificar(d.nome_empresa).replace(/-/g, '').slice(0, 12) || 'loja';
                this.renderAtalhos([sugestao]);
                return 'Crie um usuário para entrar no painel (ex.: ' + sugestao + '):';
            }
            if (!d.senha) {
                st.passo = 'senha';
                this.renderAtalhos([]);
                return 'Agora escolha uma senha com pelo menos 6 caracteres:';
            }
            if (!d.nivel_experiencia) {
                st.passo = 'nivel';
                this.renderAtalhos(['Básico (Com IA)', 'Avançado (IA + Manual)', 'Expert (Acesso Total)']);
                return 'Como você prefere montar e gerenciar sua página?\n• Básico: a IA monta tudo e te ensina o cardápio\n• Avançado: IA + controles manuais\n• Expert: acesso total a todos os dados e tecnologias';
            }

            st.passo = 'confirmar';
            this.renderAtalhos(['SIM', 'Mudar CNPJ', 'Mudar raio', 'NÃO']);
            const rotNivel = { basico: 'Básico (IA guiada)', avancado: 'Avançado (IA + Manual)', expert: 'Expert (Acesso Total)' }[d.nivel_experiencia] || 'Básico';
            return (
                'Tudo pronto! Confira o resumo obrigatório:\n' +
                '• Loja: ' + d.nome_empresa + '\n' +
                '• Nível escolhido: ' + rotNivel + '\n' +
                '• CNPJ: ' + d.cnpj + '\n' +
                '• Segmento: ' + (NICHOS_INFO[d.nicho] ? NICHOS_INFO[d.nicho].rotulo : d.nicho) + '\n' +
                '• WhatsApp: ' + d.whatsapp + '\n' +
                '• Endereço: ' + d.rua + ', ' + d.numero + ' - ' + d.bairro + ', ' + d.cidade + '/' + d.estado + ' (' + d.cep + ')\n' +
                '• Raio de entrega: até ' + d.raio_entrega_km + ' km\n' +
                '• Usuário: ' + d.login + '\n\n' +
                'Ao confirmar, você concorda com os Termos de Uso e Política de Privacidade (LGPD) do AcheIAki.\n' +
                'Digite SIM para criar sua loja completa agora (ou peça para alterar qualquer campo).'
            );
        }

        finalizarCadastro() {
            const d = this.estadoCadastro.dados;
            if (typeof this.onCadastroCompleto === 'function') {
                this.onCadastroCompleto(d);
            } else {
                this.falar('Configuração pronta! (Callback de cadastro não ligado.)');
            }
        }

        // =========================================================
        // INTEGRAÇÃO COM O PAINEL ADMIN (admin.html / admin.js)
        // =========================================================
        adminApi() {
            return global.__pzAdmin || null;
        }

        registrarUndo(descricao) {
            const api = this.adminApi();
            if (!api) return;
            try {
                this.pilhaDesfazer.push({
                    descricao: descricao,
                    config: JSON.parse(JSON.stringify(api.config)),
                    cardapio: JSON.parse(JSON.stringify(api.getCardapio()))
                });
                if (this.pilhaDesfazer.length > 15) this.pilhaDesfazer.shift();
            } catch (e) { /* ignore */ }
        }

        desfazerUltimaAcao() {
            const api = this.adminApi();
            if (!api || !this.pilhaDesfazer.length) {
                this.falar('Não há nenhuma alteração recente para desfazer.');
                return;
            }
            const snap = this.pilhaDesfazer.pop();
            api.config = snap.config;
            api.setCardapio(snap.cardapio);
            this.sincronizarDom();
            if (typeof global.__pzRenderEditor === 'function') global.__pzRenderEditor();
            if (typeof global.__pzMontarCategorias === 'function') global.__pzMontarCategorias();
            if (typeof api.salvar === 'function') api.salvar();
            this.falar('Desfeito: "' + snap.descricao + '". Tudo voltou como estava antes.');
        }

        sincronizarDom() {
            const api = this.adminApi();
            if (!api || !api.config) return;
            const cfg = api.config;
            const setVal = (id, val) => {
                const el = document.getElementById(id);
                if (!el || val == null) return;
                if (el.type === 'checkbox') el.checked = !!val;
                else el.value = val;
            };
            setVal('input-nome-loja', cfg.NOME_LOJA);
            setVal('input-slogan', cfg.SLOGAN);
            setVal('input-whatsapp', cfg.WHATSAPP_NUMBER);
            setVal('input-nicho', cfg.NICHO);
            if (cfg.IDENTIDADE && cfg.IDENTIDADE.CORES) {
                setVal('input-cor-primaria', cfg.IDENTIDADE.CORES.primaria);
                setVal('input-cor-secundaria', cfg.IDENTIDADE.CORES.secundaria);
                setVal('input-cor-fundo', cfg.IDENTIDADE.CORES.fundo);
            }
            if (cfg.ENDERECO) {
                setVal('input-end-rua', cfg.ENDERECO.rua);
                setVal('input-end-numero', cfg.ENDERECO.numero);
                setVal('input-end-bairro', cfg.ENDERECO.bairro);
                setVal('input-end-cidade', cfg.ENDERECO.cidade);
                setVal('input-end-estado', cfg.ENDERECO.estado);
                setVal('input-end-cep', cfg.ENDERECO.cep);
            }
            if (cfg.FRETE) {
                setVal('input-frete-modo', cfg.FRETE.modo);
                setVal('input-taxa-fixa', cfg.FRETE.taxa_fixa);
                setVal('input-frete-gratis', cfg.FRETE.frete_gratis_acima);
                setVal('input-pedido-minimo', cfg.FRETE.pedido_minimo);
                const bairrosEl = document.getElementById('input-bairros');
                if (bairrosEl && Array.isArray(cfg.FRETE.bairros)) {
                    bairrosEl.value = cfg.FRETE.bairros.map((b) => (b.nome || '') + '|' + (b.valor || 0)).join('\n');
                }
            }
            if (cfg.LOGISTICA) {
                setVal('input-logistica', cfg.LOGISTICA.modelo);
                setVal('chk-aceita-retirada', cfg.LOGISTICA.aceita_retirada !== false);
            }
            if (cfg.PAGAMENTOS) {
                setVal('chk-pay-pix', cfg.PAGAMENTOS.pix !== false);
                setVal('chk-pay-debito', cfg.PAGAMENTOS.debito !== false);
                setVal('chk-pay-credito', cfg.PAGAMENTOS.credito !== false);
                setVal('chk-pay-dinheiro', cfg.PAGAMENTOS.dinheiro !== false);
                setVal('input-chave-pix', cfg.PAGAMENTOS.chave_pix);
            }
            if (cfg.HORARIOS) {
                setVal('input-horario-texto', cfg.HORARIOS.TEXTO);
                setVal('input-horario-folga', cfg.HORARIOS.TEXTO_FOLGA);
            }
            if (cfg.REDES) {
                setVal('input-instagram', cfg.REDES.instagram);
                setVal('input-facebook', cfg.REDES.facebook);
            }
        }

        dispararSalvar() {
            const btn = document.getElementById('btn-gerar-arquivo');
            if (btn) {
                btn.click();
                return true;
            }
            return false;
        }

        buscarItensNoCardapio(termo) {
            const api = this.adminApi();
            if (!api || !api.getCardapio) return [];
            const cardapio = api.getCardapio();
            const resultados = [];
            const q = normalizar(termo);
            Object.keys(cardapio).forEach((cat) => {
                const lista = Array.isArray(cardapio[cat]) ? cardapio[cat] : [];
                lista.forEach((item, index) => {
                    const nomeNorm = normalizar(item.nome);
                    let score = 0;
                    if (nomeNorm === q) score = 2.0;
                    else if (nomeNorm.includes(q) && q.length >= 2) score = 1.4;
                    else score = similaridade(q, nomeNorm);
                    if (score >= 0.55) {
                        resultados.push({ cat, index, item, score });
                    }
                });
            });
            resultados.sort((a, b) => b.score - a.score);
            return resultados;
        }

        adicionarPratos(itens, categoriaHint) {
            const api = this.adminApi();
            if (!api || !api.getCardapio) {
                this.falar('Painel ainda não carregou. Espere uns segundos e tente de novo.');
                return [];
            }
            this.registrarUndo('Adicionar ' + itens.length + ' item(ns)');
            const cardapio = api.getCardapio();
            const cfg = api.config;
            const adicionados = [];
            itens.forEach((item) => {
                const cat = item.cat || acharCategoria(cardapio, cfg, (categoriaHint || '') + ' ' + item.nome);
                if (!cardapio[cat]) cardapio[cat] = [];
                cardapio[cat].unshift({
                    id: Date.now() + Math.floor(Math.random() * 10000),
                    nome: item.nome,
                    descricao: item.descricao || 'Preparado na hora',
                    preco: item.preco,
                    imagem: 'assets/images/placeholder-logo.svg',
                    ativo: true
                });
                adicionados.push({ nome: item.nome, preco: item.preco, cat: cat });
            });
            api.setCardapio(cardapio);
            if (typeof global.__pzRenderEditor === 'function') global.__pzRenderEditor();
            if (typeof api.salvar === 'function') api.salvar();
            if (typeof global.__pzAtivarAbaAdmin === 'function') global.__pzAtivarAbaAdmin('cardapio');
            return adicionados;
        }

        // Auditoria 100% local (sem API externa)
        auditarLoja() {
            const api = this.adminApi();
            if (!api) return 'Painel ainda carregando.';
            const cfg = api.config || {};
            const cardapio = api.getCardapio() || {};
            const pendencias = [];
            const pontosPositivos = [];
            let pontos = 100;

            const nome = (cfg.NOME_LOJA || '').trim();
            if (!nome || /nome da sua loja/i.test(nome)) {
                pendencias.push('Defina o nome real da loja (ex.: "Nome da loja Sabor da Vila").');
                pontos -= 15;
            } else {
                pontosPositivos.push('Nome configurado: ' + nome);
            }

            const zap = String(cfg.WHATSAPP_NUMBER || '').replace(/\D/g, '');
            if (zap.length < 10) {
                pendencias.push('Falta o WhatsApp com DDD para receber os pedidos (ex.: "WhatsApp 11999887766").');
                pontos -= 20;
            } else {
                pontosPositivos.push('WhatsApp pronto: ' + zap);
            }

            let totalItens = 0;
            let itensSemPreco = 0;
            let itensSemFoto = 0;
            Object.keys(cardapio).forEach((cat) => {
                const arr = Array.isArray(cardapio[cat]) ? cardapio[cat] : [];
                arr.forEach((it) => {
                    totalItens++;
                    if (!it.preco || Number(it.preco) <= 0) itensSemPreco++;
                    if (!it.imagem || String(it.imagem).includes('placeholder')) itensSemFoto++;
                });
            });

            if (totalItens === 0) {
                pendencias.push('Seu cardápio está vazio! Diga "montar cardápio inicial" que eu crio os pratos base para o seu tipo de comida.');
                pontos -= 25;
            } else {
                pontosPositivos.push(totalItens + ' prato(s) no cardápio');
                if (itensSemPreco > 0) {
                    pendencias.push(itensSemPreco + ' item(ns) estão com preço R$ 0,00.');
                    pontos -= 10;
                }
                if (itensSemFoto > 0) {
                    pendencias.push(itensSemFoto + ' item(ns) ainda estão sem foto própria (você pode enviar na aba "Pratos e preços").');
                    pontos -= 5;
                }
            }

            const end = cfg.ENDERECO || {};
            if (!end.rua || !end.cidade) {
                pendencias.push('Endereço incompleto (diga: "Endereço Rua das Flores, 100, Centro, São Paulo").');
                pontos -= 10;
            } else {
                pontosPositivos.push('Endereço preenchido');
            }

            const pag = cfg.PAGAMENTOS || {};
            if (pag.pix !== false && !pag.chave_pix) {
                pendencias.push('PIX está ativo mas sem chave cadastrada (diga: "Chave pix seu@email.com").');
                pontos -= 10;
            }

            if (!cfg.FRETE || !cfg.FRETE.modo) {
                pendencias.push('Configure a taxa de entrega (ex.: "Frete grátis" ou "Taxa de entrega 5 reais").');
                pontos -= 10;
            }

            pontos = Math.max(10, Math.min(100, pontos));
            const atalhos = [];
            if (totalItens === 0) atalhos.push('Montar cardápio inicial');
            if (zap.length < 10) atalhos.push('Mudar WhatsApp');
            atalhos.push('Configurar entrega', 'Salvar tudo');
            this.renderAtalhos(atalhos);

            let msg = 'Diagnóstico local da sua loja (' + pontos + '/100):\n\n';
            if (pontosPositivos.length) msg += 'O que já está bom:\n• ' + pontosPositivos.join('\n• ') + '\n\n';
            if (pendencias.length) msg += 'O que recomendo ajustar agora:\n• ' + pendencias.join('\n• ');
            else msg += 'Parabéns! Sua loja está completa e pronta para vender muito. Não esqueça de clicar em Salvar!';
            return msg;
        }

        // =========================================================
        // FLUXO PRINCIPAL DO ADMIN (fluxoAdmin)
        // =========================================================
        fluxoAdmin(entrada) {
            const api = this.adminApi();
            const n = normalizar(entrada);

            // 0. Cancelar / Desfazer
            if (/^(cancela|cancelar|esquece|deixa pra la|parar)$/.test(n)) {
                this.aguardando = null;
                this.falar('Tudo bem, cancelei o que estávamos fazendo. Como posso ajudar agora?');
                return;
            }
            if (/desfaz|desfazer|volta atras|voltar atras|ctrl z/.test(n)) {
                this.desfazerUltimaAcao();
                return;
            }

            // 1. Continuação de diálogo multi-turno (aguardando resposta do usuário)
            if (this.aguardando) {
                const pend = this.aguardando;
                if (pend.tipo === 'preco_novo_item') {
                    const p = extrairPreco(entrada);
                    if (p === null) {
                        this.falar('Não entendi o valor. Me diga só o número ou em reais (ex.: 25 ou 25,90), ou digite "cancelar".');
                        return;
                    }
                    this.aguardando = null;
                    const res = this.adicionarPratos([{ nome: pend.nome, preco: p, descricao: pend.descricao }], pend.nome);
                    this.falar('Pronto! Cadastrei "' + res[0].nome + '" por ' + formatarMoeda(p) + ' na categoria ' + res[0].cat + '. Digite "salvar" para publicar.');
                    return;
                }
                if (pend.tipo === 'novo_preco_existente') {
                    const p = extrairPreco(entrada);
                    if (p === null) {
                        this.falar('Qual o novo preço em reais? (ex.: 29,90) ou "cancelar".');
                        return;
                    }
                    this.aguardando = null;
                    this.registrarUndo('Alterar preço de ' + pend.match.item.nome);
                    const antigo = pend.match.item.preco;
                    pend.match.item.preco = p;
                    api.setCardapio(api.getCardapio());
                    global.__pzRenderEditor && global.__pzRenderEditor();
                    api.salvar && api.salvar();
                    this.falar('Preço de "' + pend.match.item.nome + '" alterado de ' + formatarMoeda(antigo) + ' para ' + formatarMoeda(p) + '.');
                    return;
                }
                if (pend.tipo === 'confirmar_remocao') {
                    this.aguardando = null;
                    if (/^(sim|pode|apaga|remove|confirmo|ok)$/.test(n)) {
                        this.registrarUndo('Remover ' + pend.match.item.nome);
                        const cardapio = api.getCardapio();
                        cardapio[pend.match.cat].splice(pend.match.index, 1);
                        api.setCardapio(cardapio);
                        global.__pzRenderEditor && global.__pzRenderEditor();
                        api.salvar && api.salvar();
                        this.falar('Removi "' + pend.match.item.nome + '" do cardápio. (Se foi engano, digite "desfazer".)');
                    } else {
                        this.falar('Ok, mantive "' + pend.match.item.nome + '" no cardápio.');
                    }
                    return;
                }
                if (pend.tipo === 'confirmar_cardapio_base') {
                    this.aguardando = null;
                    if (/^(sim|pode|monta|adiciona|ok|quero)$/.test(n)) {
                        this.aplicarCardapioBase(pend.nicho);
                    } else {
                        this.falar('Tudo bem, não adicionei os itens de exemplo.');
                    }
                    return;
                }
            }

            // 2. Modos do painel (3 níveis) e salvar
            if (/modo expert|nivel expert|painel completo|acesso total|mostrar tudo|todas as abas/.test(n)) {
                global.PedidoZapModoAdmin && global.PedidoZapModoAdmin.set('expert');
                this.falar('Nível Expert ativado! Liberei acesso total a todas as informações de cadastro, JSON/APIs, Estoque/CMV, Caixa/DRE e Equipe.');
                return;
            }
            if (/modo avan|nivel avan/.test(n)) {
                global.PedidoZapModoAdmin && global.PedidoZapModoAdmin.set('avancado');
                this.falar('Nível Avançado ativado! Você continua com ajuda da IA e agora vê os controles manuais de entrega, categorias, textos, cupons e relatórios.');
                return;
            }
            if (/modo basico|nivel basico|modo facil|esconder avan|so o basico/.test(n)) {
                global.PedidoZapModoAdmin && global.PedidoZapModoAdmin.set('basico');
                this.falar('Nível Básico ativado! Eu cuido da montagem da página e te ensino a criar seu cardápio passo a passo.');
                return;
            }
            if (/ensina(r)?.*cardapio|como fazer.*cardapio|tutorial.*cardapio/.test(n)) {
                this.renderAtalhos(['Montar cardápio inicial', 'Cadastra X-Bacon 28', 'Abrir Pratos e preços']);
                this.falar(
                    'Aula rápida para fazer um cardápio que vende muito:\n' +
                    '1) Comece com um cardápio base tocando em "Montar cardápio inicial" ou me ditando os pratos (ex.: "Cadastra Marmita P 22, Suco 7").\n' +
                    '2) Vá em "Pratos e preços" e envie 1 foto real e nítida para cada item.\n' +
                    '3) Coloque 1 item em Promoção para atrair cliques no Marketplace.\n' +
                    '4) Sempre toque no botão verde SALVAR no topo para publicar!'
                );
                return;
            }
            if (/monta(r)? (minha )?pagina|construir (minha )?pagina/.test(n)) {
                const nichoPag = this.detectarNicho(entrada) || (api && api.config && api.config.NICHO) || 'lanchonete';
                if (api && api.config) {
                    if (!api.config.TEXTOS) api.config.TEXTOS = {};
                    const nomeLoja = api.config.NOME_LOJA || 'Sua Loja';
                    if (!api.config.TEXTOS.HERO_TITULO) api.config.TEXTOS.HERO_TITULO = 'Bem-vindo à ' + nomeLoja;
                    if (!api.config.TEXTOS.HERO_SUBTITULO) api.config.TEXTOS.HERO_SUBTITULO = 'Peça direto pelo cardápio digital sem taxas extras e receba rapidinho.';
                }
                const cardAtual = api && api.getCardapio ? api.getCardapio() : {};
                const temItens = Object.values(cardAtual).some((arr) => Array.isArray(arr) && arr.length > 0);
                if (!temItens) {
                    this.aplicarCardapioBase(nichoPag);
                } else {
                    api && api.salvar && api.salvar();
                    this.falar('Atualizei os textos de boas-vindas da sua página e mantive os pratos que você já cadastrou! Toque em "Salvar" no topo para publicar.');
                }
                return;
            }
            if (/^(salv(a|ar|e)|grava|publica|salvar tudo)$/.test(n) || /salva(r)? (tudo|no servidor|alteracoes)/.test(n)) {
                if (this.dispararSalvar()) this.falar('Mandei salvar tudo no servidor! Confira a confirmação no topo.');
                else this.falar('Não encontrei o botão Salvar na página.');
                return;
            }

            // 3. Auditoria / Diagnóstico local da loja
            if (/analis|diagnostic|como esta minha loja|o que falta|melhoria|perfil|dica|sugestao para loja/.test(n) && !/prato|cardapio|item/.test(n)) {
                this.falar(this.auditarLoja());
                return;
            }

            // 4. Cardápio inicial / Sugestões culinárias por nicho
            if (/monta(r)? cardapio|cardapio inicial|cardapio base|sugere prato|ideia de prato|popular cardapio|exemplos de prato/.test(n)) {
                const nicho = this.detectarNicho(entrada) || (api && api.config && api.config.NICHO) || 'lanchonete';
                const listaBase = CARDAPIOS_BASE[nicho] || CARDAPIOS_BASE.lanchonete;
                const preview = listaBase.slice(0, 5).map((i) => '• ' + i.nome + ' — ' + formatarMoeda(i.preco)).join('\n');
                if (/monta|cria|adiciona|coloca|bota/.test(n)) {
                    this.aplicarCardapioBase(nicho);
                } else {
                    this.aguardando = { tipo: 'confirmar_cardapio_base', nicho: nicho };
                    this.renderAtalhos(['SIM, adicionar tudo', 'Não']);
                    this.falar('Para ' + (NICHOS_INFO[nicho] ? NICHOS_INFO[nicho].rotulo : nicho) + ', sugiro estes pratos iniciais:\n' + preview + '\n(...total de ' + listaBase.length + ' itens)\n\nQuer que eu adicione todos eles no seu cardápio agora? (SIM / NÃO)');
                }
                return;
            }

            // 5. Consultar o cardápio atual
            if (/o que tem no (meu )?cardapio|listar pratos|ver cardapio|quantos pratos|meus pratos|quais (itens|pratos|bebidas|lanches|pizzas|marmitas)/.test(n)) {
                const cardapio = api ? api.getCardapio() : {};
                const cats = Object.keys(cardapio);
                const linhas = [];
                let total = 0;
                cats.forEach((cat) => {
                    const arr = Array.isArray(cardapio[cat]) ? cardapio[cat] : [];
                    if (!arr.length) return;
                    total += arr.length;
                    const resumoItens = arr.slice(0, 4).map((it) => it.nome + ' (' + formatarMoeda(it.preco) + (it.ativo === false ? ' - PAUSADO' : '') + ')').join(', ');
                    linhas.push('• ' + cat.toUpperCase() + ' (' + arr.length + '): ' + resumoItens + (arr.length > 4 ? '...' : ''));
                });
                if (!total) {
                    this.renderAtalhos(['Montar cardápio inicial', 'Cadastrar prato']);
                    this.falar('Seu cardápio ainda está vazio. Quer que eu monte um cardápio inicial ou prefere me ditar os pratos?');
                } else {
                    this.falar('Você tem ' + total + ' item(ns) no cardápio:\n' + linhas.join('\n'));
                }
                return;
            }

            // 6. Editar preço, pausar/ativar ou remover item existente
            if (/(muda|altera|troca|atualiza|ajusta|sobe|aumenta|abaixa|diminui).*(preco|valor)/.test(n) || /passa a custar|agora e \d/.test(n)) {
                const novoPreco = extrairPreco(entrada);
                const termoLimpo = entrada
                    .replace(/r\$\s*\d{1,4}(?:[.,]\d{1,2})?/gi, '')
                    .replace(/\d{1,4}(?:[.,]\d{1,2})?\s*(?:reais?|real|conto|contos)?/gi, '')
                    .replace(/\b(?:muda|altera|troca|atualiza|ajusta|sobe|aumenta|abaixa|diminui|o|a|do|da|de|preco|preço|valor|para|pra|por)\b/gi, ' ')
                    .replace(/\s+/g, ' ').trim();
                const matches = this.buscarItensNoCardapio(termoLimpo);
                if (!matches.length) {
                    this.falar('Não encontrei nenhum prato parecido com "' + termoLimpo + '" no seu cardápio.');
                    return;
                }
                const alvo = matches[0];
                if (novoPreco === null) {
                    this.aguardando = { tipo: 'novo_preco_existente', match: alvo };
                    this.falar('Achei "' + alvo.item.nome + '" (atual: ' + formatarMoeda(alvo.item.preco) + '). Para qual valor quer mudar?');
                    return;
                }
                this.registrarUndo('Alterar preço de ' + alvo.item.nome);
                const antigo = alvo.item.preco;
                alvo.item.preco = novoPreco;
                api.setCardapio(api.getCardapio());
                global.__pzRenderEditor && global.__pzRenderEditor();
                api.salvar && api.salvar();
                this.falar('Preço de "' + alvo.item.nome + '" atualizado de ' + formatarMoeda(antigo) + ' para ' + formatarMoeda(novoPreco) + '.');
                return;
            }

            if (/^(pausa|desativa|desativar|esconde|tira do ar|acabou|ativa|ativar|reativa|volta)\b/.test(n)) {
                const ativar = /^(ativa|ativar|reativa|volta)/.test(n);
                const termo = n.replace(/^(pausa|pausar|desativa|desativar|esconde|tira do ar|acabou|ativa|ativar|reativa|volta)\s+(?:o|a|os|as)?\s*/i, '').trim();
                const matches = this.buscarItensNoCardapio(termo);
                if (!matches.length) {
                    this.falar('Não encontrei "' + termo + '" no cardápio. Diga o nome do prato para eu ' + (ativar ? 'ativar.' : 'pausar.'));
                    return;
                }
                const alvo = matches[0];
                this.registrarUndo((ativar ? 'Ativar ' : 'Pausar ') + alvo.item.nome);
                alvo.item.ativo = ativar;
                api.setCardapio(api.getCardapio());
                global.__pzRenderEditor && global.__pzRenderEditor();
                api.salvar && api.salvar();
                this.falar((ativar ? 'Ativei "' : 'Pausei "') + alvo.item.nome + '" na vitrine. Digite "salvar" para aplicar.');
                return;
            }

            if (/^(apaga|apagar|remove|remover|exclui|excluir|deleta|deletar)\b/.test(n)) {
                const termo = n.replace(/^(apaga|apagar|remove|remover|exclui|excluir|deleta|deletar)\s+(?:o|a|os|as)?\s*/i, '').trim();
                const matches = this.buscarItensNoCardapio(termo);
                if (!matches.length) {
                    this.falar('Não encontrei "' + termo + '" no cardápio.');
                    return;
                }
                const alvo = matches[0];
                this.aguardando = { tipo: 'confirmar_remocao', match: alvo };
                this.renderAtalhos(['SIM', 'Não']);
                this.falar('Tem certeza que deseja excluir "' + alvo.item.nome + '" (' + formatarMoeda(alvo.item.preco) + ') do cardápio? (SIM / NÃO)');
                return;
            }

            // 7. Cadastrar pratos (unitário ou em lote, com ou sem preço)
            if (/cadastr|adicion|coloc|bota|cria|novo prato|novo item|inclui|insere|poe/.test(n) || entrada === 'Cadastrar prato') {
                const lista = parseListaItens(entrada);
                if (lista.length) {
                    const res = this.adicionarPratos(lista, entrada);
                    const resumo = res.map((r) => '• ' + r.nome + ' — ' + formatarMoeda(r.preco) + ' (' + r.cat + ')').join('\n');
                    this.falar('Adicionei ' + res.length + ' item(ns):\n' + resumo + '\n\nDigite "salvar" para publicar ou "desfazer" se quiser voltar.');
                    return;
                }
                // Usuário disse "cadastra X-Bacon" sem informar o preço
                const nomeSemPreco = limparNomeItem(entrada);
                if (nomeSemPreco.length >= 2 && !/^prato$|^item$/.test(normalizar(nomeSemPreco))) {
                    this.aguardando = { tipo: 'preco_novo_item', nome: nomeSemPreco.charAt(0).toUpperCase() + nomeSemPreco.slice(1), descricao: '' };
                    this.falar('Certo! Qual o preço de "' + this.aguardando.nome + '"?');
                    return;
                }
                this.falar('Me diga o nome e o preço! Exemplos:\n• "Cadastra X-Salada por 22"\n• "Arroz e Feijão 20, Coca Lata 6, Suco 8"');
                return;
            }

            // 8. Configurar Entrega / Frete
            if (/frete|taxa de entrega|entrega|pedido minimo|retirada|bairro/.test(n) && !/^abre|^mostra|^vai/.test(n)) {
                if (!api) return;
                this.registrarUndo('Configurar entrega');
                const cfg = api.config;
                if (!cfg.FRETE) cfg.FRETE = {};
                if (!cfg.LOGISTICA) cfg.LOGISTICA = {};

                if (/frete gratis acima|gratis acima/.test(n)) {
                    const v = extrairPreco(entrada);
                    if (v !== null) {
                        cfg.FRETE.frete_gratis_acima = v;
                        this.sincronizarDom();
                        api.salvar && api.salvar();
                        this.falar('Configurado: frete grátis para pedidos acima de ' + formatarMoeda(v) + '.');
                        return;
                    }
                }
                if (/pedido minimo/.test(n)) {
                    const v = extrairPreco(entrada);
                    if (v !== null) {
                        cfg.FRETE.pedido_minimo = v;
                        this.sincronizarDom();
                        api.salvar && api.salvar();
                        this.falar('Pedido mínimo definido para ' + formatarMoeda(v) + '.');
                        return;
                    }
                }
                if (/frete gratis|entrega gratis|nao cobro entrega|sem taxa/.test(n)) {
                    cfg.FRETE.modo = 'gratis';
                    cfg.FRETE.taxa_fixa = 0;
                    this.sincronizarDom();
                    api.salvar && api.salvar();
                    this.falar('Entrega configurada como GRÁTIS.');
                    return;
                }
                if (/so retirada|apenas retirada|so busca/.test(n)) {
                    cfg.LOGISTICA.modelo = 'retirada';
                    cfg.LOGISTICA.aceita_retirada = true;
                    this.sincronizarDom();
                    api.salvar && api.salvar();
                    this.falar('Configurado: cliente só busca na loja (retirada).');
                    return;
                }
                const taxa = extrairPreco(entrada);
                if (taxa !== null) {
                    cfg.FRETE.modo = 'fixo';
                    cfg.FRETE.taxa_fixa = taxa;
                    this.sincronizarDom();
                    api.salvar && api.salvar();
                    this.falar('Taxa fixa de entrega definida para ' + formatarMoeda(taxa) + '.');
                    return;
                }
                global.__pzAtivarAbaAdmin && global.__pzAtivarAbaAdmin('frete');
                this.falar('Abri a aba Entrega. Você também pode me dizer: "frete grátis", "taxa fixa 6 reais" ou "pedido mínimo 20".');
                return;
            }

            // 9. Configurar Pagamentos / Chave PIX
            if (/pix|credito|debito|dinheiro|pagamento|pagar/.test(n) && !/^abre|^mostra/.test(n) && !/como/.test(n)) {
                if (!api) return;
                this.registrarUndo('Configurar pagamentos');
                const cfg = api.config;
                if (!cfg.PAGAMENTOS) cfg.PAGAMENTOS = {};
                const mPix = entrada.match(/chave\s*pix\s*[:\-]?\s*(\S+)/i) || entrada.match(/pix\s+(?:e|é|:)?\s*([A-Za-z0-9@._\-+]{5,})/i);
                if (mPix && !/^(pix|ativo|sim|nao|aceito)$/i.test(mPix[1])) {
                    cfg.PAGAMENTOS.pix = true;
                    cfg.PAGAMENTOS.chave_pix = mPix[1].trim();
                    this.sincronizarDom();
                    api.salvar && api.salvar();
                    this.falar('Chave PIX atualizada para: ' + cfg.PAGAMENTOS.chave_pix);
                    return;
                }
                const desligar = /nao aceito|desativa|tirar/.test(n);
                let mudou = [];
                if (/pix/.test(n)) { cfg.PAGAMENTOS.pix = !desligar; mudou.push('PIX'); }
                if (/credito/.test(n)) { cfg.PAGAMENTOS.credito = !desligar; mudou.push('Crédito'); }
                if (/debito/.test(n)) { cfg.PAGAMENTOS.debito = !desligar; mudou.push('Débito'); }
                if (/dinheiro/.test(n)) { cfg.PAGAMENTOS.dinheiro = !desligar; mudou.push('Dinheiro'); }
                if (mudou.length) {
                    this.sincronizarDom();
                    api.salvar && api.salvar();
                    this.falar((desligar ? 'Desativei: ' : 'Ativei: ') + mudou.join(', ') + '.');
                    return;
                }
            }

            // 10. Horário / Tempo de espera / Alta demanda
            if (/tempo de espera|demora|preparo/.test(n)) {
                const mTempo = entrada.match(/(\d+\s*(?:a|-|ate|até)?\s*\d*\s*min\w*)/i);
                if (mTempo && api) {
                    this.registrarUndo('Alterar tempo de espera');
                    api.config.TEMPO_ESPERA = mTempo[1].trim();
                    api.salvar && api.salvar();
                    this.falar('Tempo de espera atualizado para "' + api.config.TEMPO_ESPERA + '".');
                    return;
                }
            }
            if (/alta demanda|muito movimento|atraso/.test(n)) {
                if (api) {
                    const ligar = !/desativ|tirar|normal|deslig/.test(n);
                    api.config.ALTA_DEMANDA = ligar;
                    api.salvar && api.salvar();
                    this.falar(ligar ? 'Modo Alta Demanda ATIVADO (avisa o cliente sobre possível demora).' : 'Modo Alta Demanda DESATIVADO.');
                    return;
                }
            }

            // 11. WhatsApp, Nome da Loja, Nicho, Cor, Endereço
            const mZap = entrada.match(/(?:whatsapp|zap|whats|wpp|telefone|numero|número)\s*[:\-]?\s*([\d\s()\-+]{8,})/i)
                || (/^\d{10,13}$/.test(entrada.replace(/\D/g, '')) && /whatsapp|zap|numero|telefone/.test(n) ? [null, entrada] : null);
            if (mZap) {
                const w = String(mZap[1] || entrada).replace(/\D/g, '').slice(0, 13);
                if (w.length < 10) {
                    this.falar('O número precisa ter DDD + número (pelo menos 10 dígitos).');
                    return;
                }
                this.registrarUndo('Alterar WhatsApp');
                api.config.WHATSAPP_NUMBER = w;
                this.sincronizarDom();
                api.salvar && api.salvar();
                this.falar('WhatsApp atualizado para ' + w + '. Digite "salvar" para publicar.');
                return;
            }

            const mNome = entrada.match(/(?:nome (?:da loja|do site|do comercio)?|mudar nome para|chama(?:r)?(?: a loja)?(?: de)?)\s*[:\-]?\s*(.+)/i);
            if (mNome && mNome[1].trim().length > 1) {
                const novoNome = mNome[1].trim();
                this.registrarUndo('Alterar nome da loja');
                api.config.NOME_LOJA = novoNome;
                this.sincronizarDom();
                api.salvar && api.salvar();
                this.falar('Nome da loja alterado para "' + novoNome + '". Digite "salvar" quando quiser publicar.');
                return;
            }

            const mEnd = entrada.match(/(?:meu\s+)?endere[cç]o\s*(?:e|é|:)?\s*(.+)/i);
            if (mEnd && mEnd[1].trim().length > 5 && !/^abre|^mostra/.test(n)) {
                const partes = mEnd[1].split(',').map((s) => s.trim()).filter(Boolean);
                if (api) {
                    this.registrarUndo('Atualizar endereço');
                    if (!api.config.ENDERECO) api.config.ENDERECO = {};
                    if (partes[0]) api.config.ENDERECO.rua = partes[0];
                    if (partes[1]) api.config.ENDERECO.numero = partes[1];
                    if (partes[2]) api.config.ENDERECO.bairro = partes[2];
                    if (partes[3]) api.config.ENDERECO.cidade = partes[3];
                    this.sincronizarDom();
                    api.salvar && api.salvar();
                    this.falar('Endereço atualizado! Confira na aba "WhatsApp e endereço" e digite "salvar".');
                    return;
                }
            }

            if (/cor (?:principal|primaria|da loja|do site)/.test(n)) {
                const mHex = entrada.match(/#[0-9a-fA-F]{6}/);
                let hex = mHex ? mHex[0] : null;
                if (!hex) {
                    for (const [nomeCor, cod] of Object.entries(CORES_PT)) {
                        if (n.includes(nomeCor)) { hex = cod; break; }
                    }
                }
                if (hex && api) {
                    this.registrarUndo('Mudar cor principal');
                    if (!api.config.IDENTIDADE) api.config.IDENTIDADE = {};
                    if (!api.config.IDENTIDADE.CORES) api.config.IDENTIDADE.CORES = {};
                    api.config.IDENTIDADE.CORES.primaria = hex;
                    this.sincronizarDom();
                    api.salvar && api.salvar();
                    this.falar('Cor principal alterada para ' + hex + '.');
                    return;
                }
            }

            const nicho = this.detectarNicho(entrada);
            if (nicho && /(segmento|nicho|tipo de comida|sou uma|vendo|mudar para|trocar para)/.test(n)) {
                this.registrarUndo('Mudar segmento');
                api.config.NICHO = nicho;
                this.sincronizarDom();
                const btnNicho = document.getElementById('btn-aplicar-nicho');
                if (btnNicho) btnNicho.click();
                this.falar('Segmento alterado para ' + NICHOS_INFO[nicho].rotulo + ' com categorias ajustadas!');
                return;
            }

            // 12. Navegação entre abas
            if (/abre|abrir|vai (para|na)|mostra(r)?|ir para/.test(n)) {
                const mapaAbas = [
                    { re: /visao geral|dashboard|resumo|copiloto|central ia/, aba: 'visao-geral', msg: 'Abri Visão Geral & IA.' },
                    { re: /estoque|ficha tecnica|cmv|insumo|fornecedor/, aba: 'estoque', msg: 'Abri Estoque, Insumos & Ficha Técnica/CMV.' },
                    { re: /financeiro|caixa|dre|contas|despesa/, aba: 'financeiro', msg: 'Abri Caixa, Contas & DRE.' },
                    { re: /cliente|fidelidade|cupom|cupons|recuperacao|abandono/, aba: 'clientes', msg: 'Abri Clientes, Cupons & Recuperação.' },
                    { re: /entregador|motoboy/, aba: 'entregadores', msg: 'Abri Gestão de Entregadores.' },
                    { re: /equipe|funcionario|auditoria|backup|checklist|homologacao/, aba: 'equipe', msg: 'Abri Equipe, Auditoria & Homologação.' },
                    { re: /tutorial|comeco|começo|passos/, aba: 'tutorial', msg: 'Abri os Primeiros Passos.' },
                    { re: /nome|cor|logo|identidade/, aba: 'identidade', msg: 'Abri Nome e Cores.' },
                    { re: /whatsapp|zap|endereco|endereço|horario|horário/, aba: 'contato', msg: 'Abri WhatsApp e Endereço.' },
                    { re: /prato|cardapio|preco|preço|categoria/, aba: 'cardapio', msg: 'Abri Pratos e Preços.' },
                    { re: /entrega|frete|taxa/, aba: 'frete', msg: 'Abri Entrega.' },
                    { re: /pagamento|pagar|pix|cartao/, aba: 'pagamentos', msg: 'Abri Como Pagar.' },
                    { re: /relatorio|vendeu|venda/, aba: 'relatorios', msg: 'Abri Relatórios (disponível no painel completo).' },
                    { re: /cozinha|comanda|impress/, aba: 'comandas', msg: 'Abri Cozinha / Comanda.' },
                    { re: /balcao|manual|atendimento/, aba: 'atendimento', msg: 'Abri Pedido no Balcão.' },
                    { re: /mensalidade|plano|conta|assinatura/, aba: 'conta', msg: 'Abri Mensalidade.' },
                    { re: /senha|seguranca/, aba: 'seguranca', msg: 'Abri Trocar Senha.' }
                ];
                for (const m of mapaAbas) {
                    if (m.re.test(n)) {
                        global.__pzAtivarAbaAdmin && global.__pzAtivarAbaAdmin(m.aba);
                        this.falar(m.msg);
                        return;
                    }
                }
            }

            // 13. Base de conhecimento sobre o PedidoZap (como usar cozinha, motoboy, etc.)
            const respConhecimento = this.responderConhecimentoSistema(n);
            if (respConhecimento) {
                this.falar(respConhecimento);
                return;
            }

            // 14. Validação local de CNPJ digitado
            const digitos = entrada.replace(/\D/g, '');
            if (digitos.length === 14) {
                const ok = validarCnpjLocal(digitos);
                this.falar(ok
                    ? 'O CNPJ ' + digitos + ' é matematicamente válido! Como nosso sistema funciona 100% independente sem APIs externas, me diga o nome e o tipo de comida da loja que eu configuro tudo por aqui.'
                    : 'Esse CNPJ (' + digitos + ') não passou na validação dos dígitos verificadores. Confira os números.');
                return;
            }

            // 15. Ajuda geral
            if (/ajuda|o que (voce|você) faz|comandos|menu/.test(n)) {
                this.falar(
                    'Sou 100% local e consigo fazer quase tudo por conversa:\n' +
                    '• Cadastrar pratos: "Cadastra X-Bacon 28, Coca 6"\n' +
                    '• Editar/pausar/apagar: "Muda preço do X-Bacon para 30", "Pausa Coca", "Apaga Suco"\n' +
                    '• Montar cardápio pronto: "Montar cardápio inicial"\n' +
                    '• Loja e contato: "Nome da loja...", "WhatsApp 119...", "Endereço Rua X, 10, Centro, SP"\n' +
                    '• Entrega e PIX: "Taxa fixa 5", "Frete grátis", "Chave pix ..."\n' +
                    '• Auditoria: "Analisar minha loja"\n' +
                    '• Desfazer erro: "Desfazer" | Publicar: "Salvar"'
                );
                return;
            }

            // 16. Fallback inteligente: tenta interpretar uma lista solta de itens com preço
            const listaSolta = parseListaItens(entrada);
            if (listaSolta.length >= 1) {
                const res = this.adicionarPratos(listaSolta, entrada);
                this.falar('Entendi como item(ns) do cardápio! Adicionei ' + res.length + ':\n' + res.map((r) => '• ' + r.nome + ' — ' + formatarMoeda(r.preco)).join('\n'));
                return;
            }

            this.falar('Não tenho certeza do que você quis dizer. Tente algo como:\n• "Cadastra X-Salada 22"\n• "Muda preço da Coca para 7"\n• "Taxa fixa 5 reais"\n• "Analisar minha loja"\n• "Ajuda"');
        }

        aplicarCardapioBase(nicho) {
            const lista = CARDAPIOS_BASE[nicho] || CARDAPIOS_BASE.lanchonete;
            const res = this.adicionarPratos(lista, '');
            this.falar(
                'Adicionei ' + res.length + ' itens sugeridos para ' + (NICHOS_INFO[nicho] ? NICHOS_INFO[nicho].rotulo : nicho) + '!\n' +
                'Você pode editar qualquer preço dizendo por exemplo "muda preço do ... para ..." ou clicar em "Salvar" lá em cima para publicar.'
            );
        }

        abrirPainel() {
            const painel = document.getElementById('pz-assistente-painel');
            const fab = document.getElementById('pz-assistente-fab');
            if (painel) painel.hidden = false;
            if (fab) fab.setAttribute('aria-expanded', 'true');
        }

        fecharPainel() {
            const painel = document.getElementById('pz-assistente-painel');
            const fab = document.getElementById('pz-assistente-fab');
            if (painel) painel.hidden = true;
            if (fab) fab.setAttribute('aria-expanded', 'false');
        }
    }

    function falarEmVozAlta(texto) {
        if (!texto || typeof global.speechSynthesis === 'undefined') return;
        const limpo = String(texto)
            .replace(/\[\[ACAO:[\s\S]*?\]\]/gi, '')
            .replace(/[*_`#>]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        if (!limpo) return;
        try {
            global.speechSynthesis.cancel();
            const utt = new SpeechSynthesisUtterance(limpo.slice(0, 650));
            utt.lang = 'pt-BR';
            utt.rate = 1.05;
            global.speechSynthesis.speak(utt);
        } catch (e) { /* ignore */ }
    }

    async function obterStatusIaPessoal() {
        if (global.__pzIaPessoalStatus && typeof global.__pzIaPessoalStatus.ativa === 'boolean') {
            return global.__pzIaPessoalStatus;
        }
        let user = '';
        let pass = '';
        try {
            user = sessionStorage.getItem('admin_user') || '';
            pass = sessionStorage.getItem('admin_pass') || '';
        } catch (e) { /* ignore */ }
        if (!user || !pass) return { ativa: false, voz_ativa: false };

        const url = typeof global.TENANT !== 'undefined' && global.TENANT.apiUrl
            ? global.TENANT.apiUrl('agente_api.php?action=diagnostico')
            : 'agente_api.php?action=diagnostico';
        try {
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ auth: { user, pass } })
            });
            const data = await resp.json();
            if (data && data.ia_pessoal) {
                global.__pzIaPessoalStatus = data.ia_pessoal;
                return data.ia_pessoal;
            }
        } catch (e) { /* ignore */ }
        return { ativa: false, voz_ativa: false };
    }

    function anexarControleVoz(botao, input, assistente) {
        if (!botao || !input || !assistente) return;
        if (botao.dataset.vozLigado === '1') return;
        botao.dataset.vozLigado = '1';
        botao.hidden = true;

        const atualizarVisibilidade = (st) => {
            const ativo = Boolean(st && st.ativa && st.voz_ativa);
            botao.hidden = !ativo;
            if (!ativo) return;
            const SpeechRec = global.SpeechRecognition || global.webkitSpeechRecognition;
            botao.title = SpeechRec
                ? 'Falar comando com sua IA pessoal (PT-BR)'
                : 'Reconhecimento de voz indisponível neste navegador (use Chrome/Edge).';
        };

        obterStatusIaPessoal().then(atualizarVisibilidade);
        global.addEventListener('pz:ia-pessoal-atualizada', (ev) => {
            atualizarVisibilidade(ev && ev.detail ? ev.detail : { ativa: false, voz_ativa: false });
        });

        let reconhecimento = null;
        let ouvindo = false;

        const pararEscuta = () => {
            ouvindo = false;
            botao.classList.remove('ouvindo');
            if (reconhecimento) {
                try { reconhecimento.stop(); } catch (e) { /* ignore */ }
            }
        };

        botao.addEventListener('click', () => {
            const SpeechRec = global.SpeechRecognition || global.webkitSpeechRecognition;
            if (!SpeechRec) {
                assistente.falar('Este navegador não suporta reconhecimento de voz nativo. Use Google Chrome ou Microsoft Edge.');
                return;
            }
            if (ouvindo) {
                pararEscuta();
                return;
            }
            try {
                reconhecimento = new SpeechRec();
                reconhecimento.lang = 'pt-BR';
                reconhecimento.interimResults = true;
                reconhecimento.maxAlternatives = 1;
                ouvindo = true;
                botao.classList.add('ouvindo');
                let textoFinal = '';

                reconhecimento.onresult = (event) => {
                    let parcial = '';
                    for (let i = event.resultIndex; i < event.results.length; i++) {
                        const trecho = event.results[i][0] ? event.results[i][0].transcript : '';
                        if (event.results[i].isFinal) textoFinal += ' ' + trecho;
                        else parcial += trecho;
                    }
                    const combinado = (textoFinal + ' ' + parcial).trim();
                    if (combinado) input.value = combinado;
                };

                reconhecimento.onerror = (ev) => {
                    const err = ev && ev.error ? ev.error : '';
                    pararEscuta();
                    if (err === 'not-allowed' || err === 'service-not-allowed') {
                        assistente.falar('Permita o uso do microfone no navegador para enviar comandos por voz.');
                    }
                };

                reconhecimento.onend = () => {
                    const envio = (textoFinal || input.value || '').trim();
                    const estavaOuvindo = ouvindo;
                    ouvindo = false;
                    botao.classList.remove('ouvindo');
                    if (estavaOuvindo && envio) {
                        input.value = '';
                        assistente.receber(envio, { viaVoz: true });
                    }
                };

                reconhecimento.start();
            } catch (e) {
                pararEscuta();
                assistente.falar('Não foi possível iniciar o microfone agora.');
            }
        });
    }

    function montarWidgetFlutuante(contexto) {
        const ctx = contexto || 'admin';
        if (document.getElementById('pz-assistente-fab')) return;
        const fab = document.createElement('button');
        fab.id = 'pz-assistente-fab';
        fab.type = 'button';
        fab.setAttribute('aria-expanded', 'false');
        fab.innerHTML = '<i class="fa-solid fa-robot"></i> Agente IA';

        const fix = document.createElement('div');
        fix.id = 'pz-assistente-painel';
        fix.hidden = true;
        fix.innerHTML = ''
            + '<div class="pz-assist-topo">'
            + '<div><h2>Agente <span style="color:#00d4ff;">IA</span> — AcheIAki</h2><p>' + (ctx === 'painel' ? 'Copiloto do turno em tempo real.' : 'Gestão financeira, estoque, pedidos e cardápio.') + '</p></div>'
            + '<button type="button" class="pz-assist-fechar" aria-label="Fechar">×</button>'
            + '</div>'
            + '<div class="pz-assist-mensagens" id="pz-assist-msgs"></div>'
            + '<div class="pz-assist-atalhos" id="pz-assist-atalhos"></div>'
            + '<div class="pz-assist-entrada">'
            + '<input type="text" id="pz-assist-input" placeholder="Ex.: quanto vendi hoje? ou cadastra suco 7" autocomplete="off">'
            + '<button type="button" id="pz-assist-voz" class="pz-btn-voz" hidden aria-label="Comando de voz"><i class="fa-solid fa-microphone"></i></button>'
            + '<button type="button" id="pz-assist-enviar" title="Enviar">→</button>'
            + '</div>';

        document.body.appendChild(fab);
        document.body.appendChild(fix);

        const assistente = new AssistenteIA({
            contexto: ctx,
            elMensagens: document.getElementById('pz-assist-msgs'),
            elAtalhos: document.getElementById('pz-assist-atalhos'),
            abrirAoIniciar: false
        });

        fab.addEventListener('click', () => assistente.abrirPainel());
        fix.querySelector('.pz-assist-fechar').addEventListener('click', () => assistente.fecharPainel());

        const input = document.getElementById('pz-assist-input');
        const btnVoz = document.getElementById('pz-assist-voz');
        anexarControleVoz(btnVoz, input, assistente);
        const enviar = () => {
            const v = input.value;
            input.value = '';
            assistente.receber(v);
        };
        document.getElementById('pz-assist-enviar').addEventListener('click', enviar);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') enviar();
        });

        global.PedidoZapAssistente = assistente;
    }

    function normalizarNivelAdmin(valor) {
        const v = String(valor || '').toLowerCase().trim();
        if (v === 'expert' || v === 'completo') return 'expert';
        if (v === 'avancado') return 'avancado';
        return 'basico';
    }

    function initModoAdmin() {
        const slug = (typeof TENANT !== 'undefined' ? TENANT.slug : 'default');
        const chave = slug + '_modo_admin';
        const chaveConfirmado = slug + '_nivel_confirmado';
        const corpo = document.body;

        const textosDica = {
            basico: 'Nível Básico: a IA monta toda a sua página e te ensina a criar o cardápio passo a passo.',
            avancado: 'Nível Avançado: ajuda da IA + opções manuais de entrega, categorias, textos, cupons e vendas.',
            expert: 'Nível Expert: acesso total a todos os dados de cadastro, JSON bruto, APIs, estoque/DRE e equipe.'
        };

        const aplicar = (modo, opcoes) => {
            const opts = opcoes || {};
            const nivel = normalizarNivelAdmin(modo);
            corpo.dataset.modoAdmin = nivel;
            try {
                localStorage.setItem(chave, nivel);
                if (!opts.naoMarcarConfirmado) localStorage.setItem(chaveConfirmado, '1');
            } catch (e) { /* ignore */ }

            if (!opts.silenciarConfig && global.__pzAdmin && global.__pzAdmin.config) {
                global.__pzAdmin.config.NIVEL_EXPERIENCIA = nivel;
                if (!opts.naoMarcarConfirmado) global.__pzAdmin.config.NIVEL_EXPERIENCIA_CONFIRMADO = true;
                if (typeof global.__pzAdmin.salvar === 'function') global.__pzAdmin.salvar();
            }

            document.querySelectorAll('#barra-modo-admin [data-set-nivel]').forEach((b) => {
                b.classList.toggle('ativo', b.getAttribute('data-set-nivel') === nivel);
            });

            const ativo = document.querySelector('#admin-tabs button.ativo');
            if (ativo) {
                const modAba = ativo.getAttribute('data-modo') || 'basico';
                const ocultaNoBasico = nivel === 'basico' && (modAba === 'avancado' || modAba === 'expert');
                const ocultaNoAvancado = nivel === 'avancado' && modAba === 'expert';
                if ((ocultaNoBasico || ocultaNoAvancado) && global.__pzAtivarAbaAdmin) {
                    global.__pzAtivarAbaAdmin('visao-geral');
                }
            }

            const dica = document.getElementById('dica-modo-admin');
            if (dica) dica.textContent = textosDica[nivel];
            if (nivel === 'expert' && typeof global.__pzRenderPainelExpert === 'function') {
                global.__pzRenderPainelExpert();
            }
        };

        let salvo = 'basico';
        try {
            const cfgNivel = global.__pzAdmin && global.__pzAdmin.config && global.__pzAdmin.config.NIVEL_EXPERIENCIA;
            salvo = localStorage.getItem(chave) || cfgNivel || 'basico';
        } catch (e) { /* ignore */ }

        aplicar(salvo, { silenciarConfig: true, naoMarcarConfirmado: true });

        document.querySelectorAll('#barra-modo-admin [data-set-nivel]').forEach((btn) => {
            btn.addEventListener('click', () => aplicar(btn.getAttribute('data-set-nivel')));
        });

        const abrirAssistenteCom = (cmd) => {
            if (!global.PedidoZapAssistente) return;
            global.PedidoZapAssistente.abrirPainel();
            if (cmd) global.PedidoZapAssistente.receber(cmd);
        };

        document.getElementById('btn-basico-ia-montar')?.addEventListener('click', () => abrirAssistenteCom('Montar minha página'));
        document.getElementById('btn-basico-ia-ensinar')?.addEventListener('click', () => abrirAssistenteCom('Ensinar como fazer meu cardápio'));
        document.getElementById('btn-basico-ia-auditar')?.addEventListener('click', () => abrirAssistenteCom('Analisar minha loja'));

        document.getElementById('btn-abrir-escolha-nivel')?.addEventListener('click', () => {
            const m = document.getElementById('modal-nivel-boas-vindas');
            if (m) m.hidden = false;
        });

        document.querySelectorAll('#modal-nivel-boas-vindas [data-escolher-nivel-modal]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const escolhido = normalizarNivelAdmin(btn.getAttribute('data-escolher-nivel-modal'));
                aplicar(escolhido);
                const m = document.getElementById('modal-nivel-boas-vindas');
                if (m) m.hidden = true;
                if (escolhido === 'basico') {
                    setTimeout(() => abrirAssistenteCom('Ensinar como fazer meu cardápio'), 250);
                } else if (escolhido === 'expert' && global.__pzAtivarAbaAdmin) {
                    global.__pzAtivarAbaAdmin('expert-dados');
                }
            });
        });

        global.PedidoZapModoAdmin = { set: aplicar, get: () => normalizarNivelAdmin(corpo.dataset.modoAdmin) };
    }

    function initCadastroEmbed() {
        const wrap = document.getElementById('cadastro-assistente-embed');
        if (!wrap) return;
        const msgs = wrap.querySelector('.pz-assist-mensagens');
        const atalhos = wrap.querySelector('.pz-assist-atalhos');
        const input = wrap.querySelector('input');
        const btn = wrap.querySelector('button[data-enviar]');

        const assistente = new AssistenteIA({
            contexto: 'cadastro',
            elMensagens: msgs,
            elAtalhos: atalhos,
            onCadastroCompleto(dados) {
                const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
                setVal('nome', dados.nome_empresa);
                setVal('cad-cnpj', dados.cnpj);
                setVal('nicho', dados.nicho || 'lanchonete');
                setVal('whatsapp', dados.whatsapp);
                setVal('cad-cep', dados.cep);
                setVal('cad-rua', dados.rua);
                setVal('cad-numero', dados.numero);
                setVal('cad-bairro', dados.bairro);
                setVal('cad-cidade', dados.cidade);
                setVal('cad-estado', dados.estado);
                setVal('cad-raio', dados.raio_entrega_km || 10);
                setVal('login', dados.login);
                setVal('senha', dados.senha);
                setVal('cad-senha-conf', dados.senha);

                const locSalva = (global.PedidoZapLocalizacao && global.PedidoZapLocalizacao.obterSalva)
                    ? global.PedidoZapLocalizacao.obterSalva()
                    : null;
                const latEl = document.getElementById('cad-lat');
                const lngEl = document.getElementById('cad-lng');

                assistente.falar('Perfeito! Validando CNPJ, localização e criando sua loja no AcheIAki...');
                fetch('cadastro_loja.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        nome_empresa: dados.nome_empresa,
                        cnpj: dados.cnpj,
                        nicho: dados.nicho,
                        whatsapp: dados.whatsapp,
                        cep: dados.cep,
                        rua: dados.rua,
                        numero: dados.numero,
                        bairro: dados.bairro,
                        cidade: dados.cidade,
                        estado: dados.estado,
                        lat: (locSalva && locSalva.lat) || (latEl && latEl.value) || 0,
                        lng: (locSalva && locSalva.lng) || (lngEl && lngEl.value) || 0,
                        raio_entrega_km: dados.raio_entrega_km || 10,
                        tempo_medio_min: 35,
                        nivel_experiencia: dados.nivel_experiencia || 'basico',
                        pagamentos: ['Pix', 'Cartão de Crédito', 'Dinheiro'],
                        login: dados.login,
                        senha: dados.senha,
                        senha_confirmar: dados.senha,
                        aceitou_termos: true,
                        ia_personalizacao: true,
                        website: '',
                        perfil: global.__pzPerfilCadastro || null
                    })
                }).then((r) => r.json()).then((data) => {
                    if (!data.success) {
                        assistente.falar(data.message || 'Não foi possível criar. Tente corrigir os dados ou usar o formulário.');
                        return;
                    }
                    const formEl = document.getElementById('form-cadastro');
                    if (formEl) formEl.hidden = true;
                    document.getElementById('secao-assistente-cadastro').hidden = true;
                    const pronto = document.getElementById('pronto');
                    pronto.hidden = false;
                    const quando = data.vencimento ? data.vencimento.split('-').reverse().join('/') : '';
                    document.getElementById('texto-pronto').textContent =
                        'Teste grátis até ' + quando + '. Usuário: ' + data.login + '.';
                    document.getElementById('link-cardapio').href = data.url_cardapio;
                    document.getElementById('link-painel').href = data.url_painel;
                    if (data.url_tutorial) document.getElementById('link-tutorial').href = data.url_tutorial;
                }).catch(() => assistente.falar('Sem conexão com o servidor local. Tente de novo.'));
            }
        });

        const enviar = () => {
            assistente.receber(input.value);
            input.value = '';
        };
        btn.addEventListener('click', enviar);
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') enviar(); });
    }

    function boot() {
        const pagina = document.body && document.body.dataset.pagina;
        if (pagina === 'admin') {
            initModoAdmin();
            const esperarAdmin = () => {
                if (global.__pzAdmin) montarWidgetFlutuante('admin');
                else setTimeout(esperarAdmin, 300);
            };
            esperarAdmin();
        } else if (pagina === 'painel' || document.getElementById('col-novo')) {
            const esperarLogin = () => {
                try {
                    if (sessionStorage.getItem('admin_user') && sessionStorage.getItem('admin_pass')) {
                        montarWidgetFlutuante('painel');
                        return;
                    }
                } catch (e) { /* ignore */ }
                setTimeout(esperarLogin, 500);
            };
            esperarLogin();
        }
        if (document.getElementById('cadastro-assistente-embed')) initCadastroEmbed();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();

    global.PedidoZapAssistenteCore = {
        AssistenteIA,
        anexarControleVoz,
        falarEmVozAlta,
        parseListaItens,
        extrairPreco,
        validarCnpjLocal,
        CARDAPIOS_BASE,
        NICHOS_INFO
    };
})(typeof window !== 'undefined' ? window : globalThis);
