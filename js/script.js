function iniciarVitrine() {

    // --- GLOBAL BRANDING FALLBACK (Senior Audit) ---
    // Este listener garante que NENHUMA imagem quebrada (especialmente logos antigos)
    // estrague o visual do site. Se falhar, carregamos a logo oficial.
    document.addEventListener('error', function (e) {
        if (e.target.tagName === 'IMG') {
            const img = e.target;
            if (img.src.includes('logo.jpeg') && img.dataset.retried) return;
            img.dataset.retried = "true";
            img.src = 'assets/images/logo.jpeg';
        }
    }, true);


    // --- ELEMENTOS DO DOM ---
    const containerPrincipal = document.querySelector('.container-principal');
    const rodapeCarrinho = document.querySelector('.rodape-carrinho');
    const modalCarrinho = document.getElementById('modal-carrinho');
    const btnFecharModalCarrinho = document.getElementById('btn-fechar-modal');
    const listaItensCarrinhoModal = document.getElementById('lista-itens-carrinho');
    const spanTotalModal = document.getElementById('total-carrinho-modal');
    const btnFinalizarModal = document.getElementById('btn-finalizar-modal');
    const modalCustomizacao = document.getElementById('modal-customizacao');
    const btnFecharModalCustomizacao = document.getElementById('btn-fechar-custom-modal');
    const nomeItemCustomizado = document.getElementById('custom-item-nome');
    const listaOpcionais = document.getElementById('lista-opcionais');
    const btnAdicionarCustomizado = document.getElementById('btn-adicionar-customizado');
    const modalEndereco = document.getElementById('modal-endereco');
    const btnFecharModalEndereco = document.getElementById('btn-fechar-endereco-modal');
    const btnConfirmarEndereco = document.getElementById('btn-confirmar-endereco');
    const inputCep = document.getElementById('input-cep');
    const inputRua = document.getElementById('input-rua');
    const inputBairro = document.getElementById('input-bairro');
    const inputCidade = document.getElementById('input-cidade');
    const inputNumero = document.getElementById('input-numero');
    const cepSpinner = document.getElementById('cep-spinner');
    const modalConfirmacao = document.getElementById('modal-confirmacao');
    const btnFecharConfirmacaoModal = document.getElementById('btn-fechar-confirmacao-modal');
    const resumoPedidoDiv = document.getElementById('resumo-pedido');
    const campoHorario = document.getElementById('campo-horario');
    const inputHorario = document.getElementById('input-horario');
    const radiosTipoEntrega = document.querySelectorAll('input[name="tipo-entrega"]');
    const btnEnviarPedidoFinal = document.getElementById('btn-enviar-pedido-final');

    // Novos elementos (Pagamento e Observação)
    const inputObservacao = document.getElementById('input-observacao');
    const radiosPagamento = document.querySelectorAll('input[name="forma-pagamento"]');
    const campoTroco = document.getElementById('campo-troco');
    const inputTroco = document.getElementById('input-troco');

    // Modais e Upsell
    const modalUpsell = document.getElementById('modal-upsell');
    const btnAdicionarUpsell = document.getElementById('btn-adicionar-upsell');
    const btnRecusarUpsell = document.getElementById('btn-recusar-upsell');

    // --- DADOS E CONFIGURAÇÃO ---
    let cardapio;
    let config;

    // Tenta carregar do LocalStorage (Preview do Admin)
    const previewKeyDados = (typeof TENANT !== 'undefined')
        ? TENANT.storageKey('dados_cardapio_preview')
        : 'dados_cardapio_preview';
    const previewKeyConfig = (typeof TENANT !== 'undefined')
        ? TENANT.storageKey('config_preview')
        : 'config_preview';

    const previewDados = localStorage.getItem(previewKeyDados);
    const previewConfig = localStorage.getItem(previewKeyConfig);

    const cardapioVazio = { hamburgueres: [], porcoes: [], bebidas: [], acai: [], combos: [], promocoes: [] };

    if (previewDados) {
        cardapio = JSON.parse(previewDados);
    } else if (typeof PedidoZapStore !== 'undefined' && PedidoZapStore.ready && PedidoZapStore.cardapio) {
        cardapio = PedidoZapStore.cardapio;
    } else {
        cardapio = (typeof CARDAPIO_DADOS !== 'undefined') ? CARDAPIO_DADOS : cardapioVazio;
    }

    if (previewConfig) {
        config = JSON.parse(previewConfig);
    } else if (typeof PedidoZapStore !== 'undefined' && PedidoZapStore.ready && PedidoZapStore.config && PedidoZapStore.config.NOME_LOJA) {
        config = PedidoZapStore.config;
    } else if (typeof APP_CONFIG !== 'undefined' && APP_CONFIG) {
        config = APP_CONFIG;
    } else {
        config = {};
    }

    const cartStorageKey = (typeof TENANT !== 'undefined')
        ? TENANT.storageKey('carrinhoAcheIAki')
        : 'carrinhoAcheIAki';
    const fidelidadeStorageKey = (typeof TENANT !== 'undefined')
        ? TENANT.storageKey('historicoFidelidadeAcheIAki')
        : 'historicoFidelidadeAcheIAki';
    let nomeLoja = (config.NOME_LOJA || 'Nome da Sua Loja').trim();
    if (typeof APP_CONFIG !== 'undefined' && APP_CONFIG.NOME_LOJA) {
        nomeLoja = String(APP_CONFIG.NOME_LOJA).trim();
    }

    const atendimentoCfg = config.ATENDIMENTO || {};
    const paramsUrl = new URLSearchParams(window.location.search);
    const canalAtendente = paramsUrl.get('canal') === 'atendente' || sessionStorage.getItem('canal_atendente') === '1';

    function storageAtendente(chave) {
        return (typeof TENANT !== 'undefined') ? TENANT.storageKey(chave) : chave;
    }

    function textoBotaoConfirmar() {
        if (canalAtendente) {
            return atendimentoCfg.btn_confirmar_pedido || '';
        }
        return atendimentoCfg.btn_confirmar_whatsapp || (config.CHECKOUT && config.CHECKOUT.btn_whatsapp) || '';
    }

    if (canalAtendente) {
        if (!sessionStorage.getItem('admin_logado')) {
            const dest = (typeof TENANT !== 'undefined' && TENANT.apiUrl) ? TENANT.apiUrl('atendimento.html') : 'atendimento.html';
            window.location.replace(dest);
            return;
        }
        sessionStorage.setItem('canal_atendente', '1');
        const faixa = document.createElement('div');
        faixa.id = 'faixa-atendente';
        faixa.style.cssText = 'position:sticky;top:0;z-index:99999;background:#1e293b;color:#f8fafc;padding:10px 14px;text-align:center;font-weight:700;';
        faixa.textContent = atendimentoCfg.banner_cardapio || '';
        if (atendimentoCfg.link_novo_pedido) {
            const novo = document.createElement('a');
            novo.href = (typeof TENANT !== 'undefined' && TENANT.apiUrl) ? TENANT.apiUrl('atendimento.html') : 'atendimento.html';
            novo.style.cssText = 'color:#fdba74;margin-left:10px;';
            novo.textContent = atendimentoCfg.link_novo_pedido;
            faixa.appendChild(document.createTextNode(' '));
            faixa.appendChild(novo);
        }
        document.body.prepend(faixa);
    }

    let carrinho = JSON.parse(localStorage.getItem(cartStorageKey)) || [];
    let checkoutTokenAtual = '';
    let enviandoPedido = false;
    function novoCheckoutToken() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'chk_' + window.crypto.randomUUID().replace(/-/g, '');
        }
        return 'chk_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
    }
    let itemParaCustomizar = null;
    let enderecoCliente = {};

    function formatarMoeda(valor) {
        const n = Number(valor) || 0;
        return 'R$ ' + n.toFixed(2).replace('.', ',');
    }

    let freteAtual = null;
    let cupomAplicado = { codigo: '', desconto: 0 };
    const sessaoClienteKey = (typeof TENANT !== 'undefined') ? TENANT.storageKey('pz_sessao_cliente') : 'pz_sessao_cliente';
    let sessaoClienteVitrine = localStorage.getItem(sessaoClienteKey);
    if (!sessaoClienteVitrine) {
        sessaoClienteVitrine = 'cli_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
        localStorage.setItem(sessaoClienteKey, sessaoClienteVitrine);
    }
    let timerHeartbeatCarrinho = null;
    let modoRecebimento = 'entrega';
    if (canalAtendente && (atendimentoCfg.modo_padrao || 'retirada') === 'retirada') {
        modoRecebimento = 'retirada';
    }

    function subtotalCarrinho() {
        return carrinho.reduce((acc, item) => acc + ((Number(item.precoTotal) || 0) * item.quantidade), 0);
    }

    function calcularFreteAtual(extra) {
        const subtotal = subtotalCarrinho();
        if (!window.PedidoZapFrete) {
            freteAtual = { valor: 0, subtotal: subtotal, total: subtotal, ok: true, rotulo: 'Entrega', detalhe: '', foraDaArea: false, abaixoDoMinimo: false };
            return freteAtual;
        }
        const peso = carrinho.reduce((acc, item) => acc + ((Number(item.pesoGramas) || 0) * item.quantidade), 0);
        const qtd = carrinho.reduce((acc, item) => acc + item.quantidade, 0);
        freteAtual = PedidoZapFrete.calcular(Object.assign({
            config: config,
            subtotal: subtotal,
            pesoGramas: peso,
            qtdItens: qtd,
            retirada: modoRecebimento === 'retirada',
            bairro: enderecoCliente.bairro || '',
            distanciaKm: enderecoCliente.distanciaKm
        }, extra || {}));
        return freteAtual;
    }

    function htmlResumoValores(info) {
        if (!info) return '';
        const pendente = !info.ok && !info.foraDaArea && !info.abaixoDoMinimo && !enderecoCliente.rua;
        if (pendente) {
            return '<p style="margin:0;">Subtotal: <strong>' + formatarMoeda(info.subtotal) + '</strong></p>'
                + '<p style="margin:0;color:#6b7280;">A taxa de entrega entra depois do endereço.</p>';
        }
        const desc = Number(cupomAplicado.desconto || 0);
        const totalComDesc = Math.max(0, Number(info.total || 0) - desc);
        const aviso = !info.ok
            ? '<p style="color:#b91c1c;margin:4px 0 0;">' + (info.detalhe || info.rotulo) + '</p>'
            : '';
        return '<p style="margin:0;">Subtotal: <strong>' + formatarMoeda(info.subtotal) + '</strong></p>'
            + '<p style="margin:0;">Taxa de entrega' + (info.detalhe ? ' (' + info.detalhe + ')' : '') + ': <strong>' + formatarMoeda(info.valor) + '</strong></p>'
            + (desc > 0 ? '<p style="margin:0;color:#059669;">Desconto (' + cupomAplicado.codigo + '): <strong>-' + formatarMoeda(desc) + '</strong></p>' : '')
            + '<p style="margin:4px 0 0;font-size:1.05em;">Total: <strong>' + formatarMoeda(totalComDesc) + '</strong></p>'
            + aviso;
    }

    function aplicarLogistica() {
        const log = config.LOGISTICA || {};
        const soRetirada = log.modelo === 'retirada';
        const semRetirada = log.aceita_retirada === false && !soRetirada;
        document.querySelectorAll('input[value="entrega"]').forEach((el) => {
            const wrap = el.closest('.form-grupo-radio');
            if (wrap) wrap.style.display = soRetirada ? 'none' : '';
        });
        document.querySelectorAll('input[value="retirada"]').forEach((el) => {
            const wrap = el.closest('.form-grupo-radio');
            if (wrap) wrap.style.display = semRetirada ? 'none' : '';
            if (soRetirada) el.checked = true;
        });
        if (soRetirada) modoRecebimento = 'retirada';
        if (semRetirada && modoRecebimento === 'retirada') modoRecebimento = 'entrega';
    }

    function pintarResumoFrete() {
        const info = freteAtual || calcularFreteAtual();
        ['resumo-frete-carrinho', 'resumo-frete-confirmacao'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.innerHTML = htmlResumoValores(info);
        });
        return info;
    }

    function garantirBlocosFrete() {
        const rodapeCarrinho = document.querySelector('#modal-carrinho .modal-rodape');
        if (rodapeCarrinho && !document.getElementById('resumo-frete-carrinho')) {
            const bloco = document.createElement('div');
            bloco.id = 'resumo-frete-carrinho';
            bloco.style.cssText = 'width:100%;text-align:right;font-size:0.92em;margin-bottom:8px;';
            rodapeCarrinho.insertBefore(bloco, rodapeCarrinho.firstChild);
        }
        const corpo = document.querySelector('#modal-confirmacao .modal-corpo-confirmacao');
        if (corpo && !document.getElementById('bloco-recebimento')) {
            const bloco = document.createElement('div');
            bloco.id = 'bloco-recebimento';
            bloco.innerHTML = '<h4>Como receber</h4>'
                + '<div class="form-grupo-radio"><input type="radio" id="recebimento-entrega" name="modo-recebimento" value="entrega" checked><label for="recebimento-entrega">Quero receber em casa</label></div>'
                + '<div class="form-grupo-radio"><input type="radio" id="recebimento-retirada" name="modo-recebimento" value="retirada"><label for="recebimento-retirada">Vou buscar na loja</label></div>'
                + '<div id="resumo-frete-confirmacao" style="margin:8px 0 12px;"></div>'
                + '<p data-bind="chave-pix" style="display:none;font-size:0.9em;"></p>';
            const resumo = document.getElementById('resumo-pedido');
            if (resumo && resumo.parentNode) resumo.parentNode.insertBefore(bloco, resumo.nextSibling);
            else corpo.prepend(bloco);
            bloco.querySelectorAll('input[name="modo-recebimento"]').forEach((radio) => {
                radio.addEventListener('change', () => {
                    modoRecebimento = radio.value;
                    calcularFreteAtual();
                    pintarResumoFrete();
                });
            });
        }
        const formEndereco = document.querySelector('#modal-endereco .modal-corpo-form');
        if (formEndereco && !document.getElementById('frete-preview')) {
            const escolha = document.createElement('div');
            escolha.id = 'bloco-recebimento-endereco';
            escolha.innerHTML = '<div class="form-grupo-radio"><input type="radio" id="modo-entrega" name="modo-recebimento-endereco" value="entrega" checked><label for="modo-entrega">Quero receber em casa</label></div>'
                + '<div class="form-grupo-radio"><input type="radio" id="modo-retirada" name="modo-recebimento-endereco" value="retirada"><label for="modo-retirada">Vou buscar na loja (sem taxa)</label></div>';
            formEndereco.prepend(escolha);
            escolha.querySelectorAll('input').forEach((radio) => {
                radio.addEventListener('change', () => {
                    modoRecebimento = radio.value;
                    calcularFreteAtual();
                    const prev = document.getElementById('frete-preview');
                    if (prev && freteAtual) prev.textContent = freteAtual.rotulo + ': ' + formatarMoeda(freteAtual.valor);
                });
            });
            const prev = document.createElement('p');
            prev.id = 'frete-preview';
            prev.style.cssText = 'font-size:0.9em;margin-top:8px;';
            formEndereco.appendChild(prev);
        }
        aplicarLogistica();
    }

    function normalizarOpcionais(opcionais) {
        if (!Array.isArray(opcionais)) return [];
        return opcionais
            .map(op => ({
                nome: String(op.nome || '').trim(),
                preco: parseFloat(op.preco) || 0
            }))
            .filter(op => op.nome)
            .sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'));
    }

    function calcularPrecoUnitario(produto, opcionaisSelecionados) {
        const base = parseFloat(produto.preco) || 0;
        const extras = normalizarOpcionais(opcionaisSelecionados)
            .reduce((acc, op) => acc + op.preco, 0);
        return base + extras;
    }

    function chaveOpcionais(opcionais) {
        return JSON.stringify(normalizarOpcionais(opcionais));
    }

    function apiFetch(path, options) {
        if (typeof TENANT !== 'undefined' && TENANT.fetchJson) {
            return TENANT.fetchJson(path, options);
        }
        return fetch(path, options);
    }


    // --- CONTROLE DE ALTA DEMANDA (CONFIG) ---
    if (config.ALTA_DEMANDA) {
        const avisos = document.querySelectorAll('.aviso-demanda');
        const spansTempo = document.querySelectorAll('.tempo-espera-display');

        avisos.forEach(aviso => aviso.style.display = 'block');

        if (config.TEMPO_ESPERA) {
            spansTempo.forEach(span => span.innerText = config.TEMPO_ESPERA);
        }
    } else {
        const avisos = document.querySelectorAll('.aviso-demanda');
        avisos.forEach(aviso => aviso.style.display = 'none');
    }

    // --- TOAST NOTIFICATIONS (NOVO) ---
    function showToast(message, type = 'sucesso') {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;

        let icon = 'fa-check-circle';
        if (type === 'erro') icon = 'fa-circle-exclamation';
        if (type === 'info') icon = 'fa-circle-info';

        toast.innerHTML = `<i class="fa-solid ${icon}"></i> <span>${message}</span>`;
        container.appendChild(toast);

        // Remove após 3 segundos
        setTimeout(() => {
            toast.style.animation = 'slideOutToast 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // --- FUNÇÕES ---

    function abrirModalUpsell() {
        modalUpsell.classList.remove('escondido');
    }

    function handleProdutoClick(idProduto) {
        const produto = encontrarProdutoPorId(idProduto);
        if (!produto) return;
        if (produto.esgotado) {
            showToast('Esgotado para hoje.', 'info');
            return;
        }

        const temOpcoes = (produto.opcionais && produto.opcionais.length)
            || (produto.grupos && produto.grupos.length)
            || (produto.tamanhos && produto.tamanhos.length);
        if (temOpcoes) {
            abrirModalCustomizacao(produto);
        } else {
            adicionarAoCarrinho(produto);

            // Sugerir up-sell se for hambúrguer e NÃO tiver fritas ainda
            const upsell = config.UPSELL || {};
            const oferecer = upsell.ativo && upsell.produto_id && String(upsell.produto_id) !== String(produto.id);
            const jaTem = carrinho.some((item) => String(item.idProduto) === String(upsell.produto_id));
            if (oferecer && !jaTem && document.getElementById('modal-upsell')) {
                setTimeout(() => abrirModalUpsell(), 400);
            }
        }
    }

    function adicionarAoCarrinho(produto, opcionaisSelecionados = [], tamanhoSelecionado = null) {
        const opcionaisNorm = normalizarOpcionais(opcionaisSelecionados);
        const tamanhoNome = tamanhoSelecionado ? tamanhoSelecionado.nome : '';
        const itemExistente = carrinho.find(item => {
            const mesmoProduto = String(item.idProduto) === String(produto.id);
            const mesmosOpcionais = chaveOpcionais(item.opcionais) === chaveOpcionais(opcionaisNorm);
            return mesmoProduto && mesmosOpcionais && (item.tamanho || '') === tamanhoNome;
        });

        if (itemExistente) {
            itemExistente.quantidade++;
        } else {
            const idUnicoCarrinho = Date.now();
            const baseProduto = Object.assign({}, produto);
            if (tamanhoSelecionado) baseProduto.preco = Number(tamanhoSelecionado.preco) || 0;
            const precoBase = parseFloat(baseProduto.preco) || 0;
            const precoTotalItem = calcularPrecoUnitario(baseProduto, opcionaisNorm);
            carrinho.push({
                id: idUnicoCarrinho, idProduto: produto.id,
                nome: produto.nome + (tamanhoNome ? ' (' + tamanhoNome + ')' : ''),
                imagem: produto.imagem, precoBase, precoTotal: precoTotalItem, quantidade: 1,
                opcionais: opcionaisNorm,
                tamanho: tamanhoNome,
                pesoGramas: Number(produto.peso) || 0
            });
        }
        atualizarCarrinho();
        animarCarrinho();
        showToast(`${produto.nome} adicionado ao carrinho!`, 'sucesso');
    }

    function salvarCarrinho() {
        try {
            localStorage.setItem(cartStorageKey, JSON.stringify(carrinho));
        } catch (e) { /* quota fallback */ }
        if (window.PedidoZapOfflineCache && typeof window.PedidoZapOfflineCache.salvarCarrinho === 'function') {
            window.PedidoZapOfflineCache.salvarCarrinho(cartStorageKey, {
                carrinho: carrinho,
                enderecoCliente: enderecoCliente,
                cupomAplicado: cupomAplicado,
                modoRecebimento: modoRecebimento
            });
        }
        if (canalAtendente || (typeof navigator !== 'undefined' && navigator.onLine === false)) return;
        clearTimeout(timerHeartbeatCarrinho);
        timerHeartbeatCarrinho = setTimeout(() => {
            const total = subtotalCarrinho();
            apiFetch('recuperacao_api.php?action=heartbeat_carrinho', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default',
                    sessao_id: sessaoClienteVitrine,
                    cliente: enderecoCliente.nome || '',
                    celular: enderecoCliente.celular || '',
                    total: total,
                    itens: carrinho.map(i => ({ nome: i.nome, quantidade: i.quantidade }))
                })
            }).catch(() => { /* ignore */ });
        }, 1800);
    }

    function animarCarrinho() {
        const icon = document.querySelector('.carrinho-info i') || document.querySelector('.fa-cart-shopping');
        if (icon) {
            icon.classList.remove('shake-animation');
            void icon.offsetWidth; // Trigger reflow
            icon.classList.add('shake-animation');
        }
    }

    function gruposDoProduto(produto) {
        if (produto.grupos && produto.grupos.length) return produto.grupos;
        if (produto.opcionais && produto.opcionais.length) {
            return [{ nome: 'Complementos', min: 0, max: 30, itens: produto.opcionais }];
        }
        return [];
    }

    function abrirModalCustomizacao(produto) {
        itemParaCustomizar = produto;
        nomeItemCustomizado.textContent = produto.nome;
        let html = '';
        (produto.tamanhos || []).forEach((tamanho, indice) => {
            const preco = Number(tamanho.preco) || 0;
            html += '<label class="opcional-item"><input type="radio" name="tamanho-item" data-nome="'
                + tamanho.nome + '" data-preco="' + preco + '"' + (indice === 0 ? ' checked' : '')
                + '><span>' + tamanho.nome + '</span><span class="opcional-preco">' + formatarMoeda(preco) + '</span></label>';
        });
        gruposDoProduto(produto).forEach((grupo, indice) => {
            const min = Number(grupo.min) || 0;
            const max = Number(grupo.max) || 30;
            const regra = min > 0 ? 'escolha de ' + min + ' a ' + max : 'até ' + max;
            html += '<h4 style="margin:12px 0 6px;" data-grupo-titulo="' + indice + '">' + (grupo.nome || 'Complementos') + ' (' + regra + ')</h4>';
            (grupo.itens || []).forEach((opcional) => {
                const preco = Number(opcional.preco) || 0;
                html += '<div class="opcional-item"><label><input type="checkbox" data-grupo="' + indice
                    + '" data-min="' + min + '" data-max="' + max + '" data-nome="' + opcional.nome
                    + '" data-preco="' + preco + '"><span>' + opcional.nome + '</span></label><span class="opcional-preco">+ '
                    + formatarMoeda(preco) + '</span></div>';
            });
        });
        listaOpcionais.innerHTML = html;
        listaOpcionais.querySelectorAll('input[type="checkbox"]').forEach((chk) => {
            chk.addEventListener('change', () => {
                const grupo = chk.dataset.grupo;
                const max = Number(chk.dataset.max) || 30;
                const marcados = listaOpcionais.querySelectorAll('input[data-grupo="' + grupo + '"]:checked');
                if (marcados.length > max) {
                    chk.checked = false;
                    showToast('Esse grupo aceita no máximo ' + max + '.', 'info');
                }
            });
        });
        modalCustomizacao.classList.remove('escondido');
    }

    function aumentarQuantidade(idUnico) { const item = carrinho.find(i => i.id === idUnico); if (item) { item.quantidade++; atualizarCarrinho(); } }

    function diminuirQuantidade(idUnico) {
        const item = carrinho.find(i => i.id === idUnico);
        if (item) {
            item.quantidade--;
            if (item.quantidade === 0) {
                carrinho = carrinho.filter(i => i.id !== idUnico);
            }
            atualizarCarrinho();
        }
    }

    function atualizarCarrinho() {
        if (!listaItensCarrinhoModal || !spanTotalModal) return;
        listaItensCarrinhoModal.innerHTML = '';
        let totalGeral = 0;
        let totalItens = 0;

        carrinho.forEach(item => {
            totalGeral += item.precoTotal * item.quantidade;
            totalItens += item.quantidade;
            listaItensCarrinhoModal.innerHTML += criarItemCarrinhoHTML(item);
        });

        if (carrinho.length === 0) {
            listaItensCarrinhoModal.innerHTML = `
                <div class="carrinho-vazio">
                    <i class="fa-solid fa-basket-shopping"></i>
                    <p>Seu carrinho está vazio.</p>
                    <p style="font-size:1em">Toque em Pedir no item que você quer.</p>
                </div>`;
        }

        const infoFrete = calcularFreteAtual();
        const totalFormatado = formatarMoeda(infoFrete.total || totalGeral);
        spanTotalModal.textContent = totalFormatado;
        pintarResumoFrete();

        // Atualiza a barra flutuante
        const carrinhoTotalFloat = document.getElementById('carrinho-total');
        if (carrinhoTotalFloat) carrinhoTotalFloat.textContent = totalFormatado;

        // Atualiza badge de contador se existir
        const infoCarrinhoFooter = document.querySelector('.carrinho-info');
        if (infoCarrinhoFooter) {
            let badge = infoCarrinhoFooter.querySelector('.carrinho-contador');
            if (!badge && totalItens > 0) {
                badge = document.createElement('span');
                badge.className = 'carrinho-contador';
                infoCarrinhoFooter.appendChild(badge);
            }
            if (badge) {
                if (totalItens > 0) badge.textContent = totalItens;
                else badge.remove();
            }
        }

        salvarCarrinho();
    }

    function criarItemCarrinhoHTML(item) {
        let opcionaisHTML = '';
        const ops = item.opcionais || [];
        if (ops.length > 0) {
            opcionaisHTML = '<ul style="font-size: 0.8em; color: #666; margin: 5px 0 0 15px; padding: 0;">';
            ops.forEach(op => {
                const extra = op.preco > 0 ? ` (${formatarMoeda(op.preco)})` : '';
                opcionaisHTML += `<li style="list-style-type: none;">+ ${op.nome}${extra}</li>`;
            });
            opcionaisHTML += '</ul>';
        }
        const precoUnit = Number(item.precoTotal) || 0;
        return `<div class="item-carrinho" data-id="${item.id}"><img src="${item.imagem}" alt="${item.nome}" loading="lazy"><div class="item-info"><p>${item.nome}</p>${opcionaisHTML}<p style="font-weight: bold; margin-top: 5px;">${formatarMoeda(precoUnit)}</p></div><div class="item-acoes"><button class="btn-diminuir">-</button><span>${item.quantidade}</span><button class="btn-aumentar">+</button></div></div>`;
    }

    async function buscarCep(cep) {
        cep = cep.replace(/\D/g, '');
        if (cep.length !== 8) { showToast("CEP inválido. Digite 8 números.", 'erro'); return; }

        cepSpinner.classList.remove('escondido');
        try {
            const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`); const data = await response.json();
            if (data.erro) { showToast("CEP não encontrado.", 'erro'); return; }
            inputRua.value = data.logradouro;
            inputBairro.value = data.bairro;
            inputCidade.value = data.localidade;
            inputNumero.focus();
            showToast("Endereço encontrado!", 'sucesso');
        } catch (error) {
            console.error("Erro ao buscar CEP:", error);
            showToast("Erro ao buscar CEP.", 'erro');
        } finally {
            cepSpinner.classList.add('escondido');
        }
    }

    function enviarPedidoWhatsApp() {
        if (carrinho.length === 0) return;

        const tipoEntrega = document.querySelector('input[name="tipo-entrega"]:checked').value;
        const formaPagamento = document.querySelector('input[name="forma-pagamento"]:checked')?.value;
        const observacao = inputObservacao.value.trim();
        const troco = inputTroco.value.trim();

        if (!formaPagamento) {
            showToast("Selecione uma forma de pagamento.", 'erro');
            return;
        }

        if (formaPagamento === 'Dinheiro' && !troco) {
            showToast("Informe se precisa de troco ou se é justo.", 'info');
            // Não bloqueia, mas avisa. Ou podemos bloquear se preferir.
        }

        let infoEntrega = "";

        if (tipoEntrega === 'agora') {
            if (!canalAtendente && !isLojaAberta()) {
                if (window.PedidoZapApresentacao && window.PedidoZapApresentacao.ativo()) {
                    showToast("Loja fora do horário — pedido liberado em Modo Análise.", 'info');
                } else {
                    showToast("A loja está fechada no momento. Selecione 'Agendar'.", 'erro');
                    return;
                }
            }
            infoEntrega = canalAtendente && modoRecebimento === 'retirada'
                ? (atendimentoCfg.rotulo_entrega_manual || infoEntrega)
                : "ENTREGA IMEDIATA";
        } else if (tipoEntrega === 'agendar') {
            if (!inputHorario.value) { showToast("Selecione um horário.", 'erro'); return; }

            // Validação de Horário Agendado com suporte a minutos e overnight
            const parts = inputHorario.value.split(':');
            const minAgendado = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);

            const regras = config.HORARIOS || {};
            const agora = new Date();
            const diaSemana = agora.getDay();
            const ehFimDeSemana = (diaSemana === 0 || diaSemana === 5 || diaSemana === 6);
            const horarioRegra = ehFimDeSemana ? (regras.FIM_DE_SEMANA || regras.SEMANA) : (regras.SEMANA || regras.FIM_DE_SEMANA);

            if (horarioRegra) {
                const parseMin = (val) => {
                    if (val === undefined || val === null) return 0;
                    if (typeof val === 'number') return val * 60;
                    if (typeof val === 'string') {
                        const p = val.split(':');
                        return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0);
                    }
                    return 0;
                };

                const inicioMin = parseMin(horarioRegra.inicio);
                const fimMin = parseMin(horarioRegra.fim);
                let dentroDoHorario = false;

                if (inicioMin < fimMin) {
                    dentroDoHorario = (minAgendado >= inicioMin && minAgendado < fimMin);
                } else if (inicioMin > fimMin) {
                    dentroDoHorario = (minAgendado >= inicioMin || minAgendado < fimMin);
                } else {
                    dentroDoHorario = true;
                }

                if (!dentroDoHorario) {
                    showToast(`Horário fora do nosso período de funcionamento (${horarioRegra.inicio} às ${horarioRegra.fim}).`, 'erro');
                    return;
                }
            }

            infoEntrega = `AGENDADO PARA: ${inputHorario.value}`;
        }

        // --- SISTEMA DE FIDELIZAÇÃO (POR TELEFONE) ---
        // Recupera o histórico geral (objeto: { 'numero': qtd, ... })
        let historicoFidelidade = JSON.parse(localStorage.getItem(fidelidadeStorageKey)) || {};

        // Usa apenas números para a chave
        const chaveCliente = enderecoCliente.celular.replace(/\D/g, '');

        // Pega a contagem atual para este número ou inicia em 0
        let pedidosRealizados = historicoFidelidade[chaveCliente] || 0;

        // Incrementa
        pedidosRealizados++;

        // Salva de volta
        historicoFidelidade[chaveCliente] = pedidosRealizados;
        localStorage.setItem(fidelidadeStorageKey, JSON.stringify(historicoFidelidade));

        let mensagemFidelidade = "";
        // Regra: A cada 5 pedidos ganha o cupom
        if (pedidosRealizados > 0 && pedidosRealizados % 5 === 0) {
            mensagemFidelidade = `\n🎁 *PARABÉNS!* Cliente fiel (${pedidosRealizados} pedidos). Ganhou: *CUPOM FIDELIDADE10* para o próximo!`;
            showToast("Parabéns! Você completou 5 pedidos e ganhou um cupom!", 'sucesso');
        }

        // --- FORMATAÇÃO TIPO IMPRESSORA TÉRMICA ---
        const pedidoNumero = Math.floor(100000 + Math.random() * 900000);
        let mensagem = `------------------------------\n`;
        mensagem += `*${nomeLoja.toUpperCase()} - PEDIDO #${pedidoNumero}*\n`;
        mensagem += `------------------------------\n`;
        mensagem += `DATA: ${new Date().toLocaleString('pt-BR')}\n`;
        mensagem += `CLIENTE: ${enderecoCliente.nome.toUpperCase()}\n`;
        mensagem += `TEL: ${enderecoCliente.celular}\n`;
        mensagem += `------------------------------\n`;
        mensagem += `*ITENS DO PEDIDO*\n\n`;

        carrinho.forEach(item => {
            const qtd = item.quantidade || 1;
            const unit = Number(item.precoTotal) || 0;
            const subtotal = unit * qtd;
            const base = item.precoBase != null ? Number(item.precoBase) : unit;
            mensagem += `${qtd}x ${item.nome.toUpperCase()}\n`;
            if (base !== unit || (item.opcionais && item.opcionais.length)) {
                mensagem += `  Base: ${formatarMoeda(base)}\n`;
            }
            (item.opcionais || []).forEach(op => {
                const extra = op.preco > 0 ? ` (${formatarMoeda(op.preco)})` : '';
                mensagem += `  + ${op.nome}${extra}\n`;
            });
            mensagem += `  Unit: ${formatarMoeda(unit)} | Subtotal: ${formatarMoeda(subtotal)}\n`;
            mensagem += `  ----------------\n`;
        });

        const infoFrete = calcularFreteAtual();
        if (!infoFrete.ok) {
            showToast(infoFrete.detalhe || 'Não é possível concluir a entrega com este endereço.', 'erro');
            return;
        }
        const descontoPedido = Number(cupomAplicado.desconto || 0);
        const totalGeral = Math.max(0, Number(infoFrete.total || 0) - descontoPedido);
        mensagem += `\nSUBTOTAL: ${formatarMoeda(infoFrete.subtotal)}\n`;
        mensagem += `TAXA DE ENTREGA: ${formatarMoeda(infoFrete.valor)}`;
        if (infoFrete.detalhe) mensagem += ` (${infoFrete.detalhe})`;
        if (descontoPedido > 0) mensagem += `\nDESCONTO (${cupomAplicado.codigo}): -${formatarMoeda(descontoPedido)}`;
        mensagem += `\n*TOTAL: ${formatarMoeda(totalGeral)}*\n`;
        mensagem += `------------------------------\n`;
        mensagem += `*ENTREGA*\n`;
        mensagem += `${infoEntrega}\n`;
        mensagem += `${enderecoCliente.rua}, ${enderecoCliente.numero}\n`;
        if (enderecoCliente.complemento) mensagem += `COMPL: ${enderecoCliente.complemento}\n`;
        mensagem += `${enderecoCliente.bairro} - ${enderecoCliente.cidade}\n`;
        if (enderecoCliente.referencia) mensagem += `REF: ${enderecoCliente.referencia}\n`;

        mensagem += `------------------------------\n`;
        mensagem += `*PAGAMENTO*\n`;
        mensagem += `FORMA: ${formaPagamento}\n`;
        if (formaPagamento === 'Dinheiro') {
            const valorPago = parseFloat(troco.replace(',', '.'));
            if (!isNaN(valorPago) && valorPago > totalGeral) {
                const valorTroco = valorPago - totalGeral;
                mensagem += `TOTAL: R$ ${totalGeral.toFixed(2).replace('.', ',')}\n`;
                mensagem += `PAGAR COM: R$ ${valorPago.toFixed(2).replace('.', ',')}\n`;
                mensagem += `*TROCO: R$ ${valorTroco.toFixed(2).replace('.', ',')}*\n`;
            } else {
                mensagem += `TROCO: ${troco ? 'R$ ' + troco : 'NÃO PRECISA'}\n`;
            }
        }

        mensagem += `\n`;

        if (observacao) {
            mensagem += `------------------------------\n`;
            mensagem += `*OBSERVAÇÕES*\n`;
            mensagem += `${observacao}\n`;
        }

        if (mensagemFidelidade) mensagem += `\n${mensagemFidelidade}\n`;

        mensagem += `------------------------------`;

        const codigoRastreio = gerarCodigoRastreio();
        const rastreioRelativo = (typeof TENANT !== 'undefined')
            ? TENANT.apiUrl(`rastreio.html?id=${pedidoNumero}&c=${codigoRastreio}`)
            : `rastreio.html?id=${pedidoNumero}&c=${codigoRastreio}`;
        let rastreioAbsoluto = rastreioRelativo;
        try { rastreioAbsoluto = new URL(rastreioRelativo, window.location.href).href; } catch (e) { /* mantém relativo */ }
        mensagem += `\n\n📍 Acompanhe seu pedido: ${rastreioAbsoluto}`;

        const algConsent = !(window.AcheIAkiLGPD && typeof window.AcheIAkiLGPD.podeUsarIA === 'function')
            || window.AcheIAkiLGPD.podeUsarIA();
        const visitorAlg = (algConsent && window.PZAlgoritmo && typeof window.PZAlgoritmo.getVisitorId === 'function')
            ? window.PZAlgoritmo.getVisitorId()
            : '';

        // --- SALVAR PEDIDO NO PAINEL (CRM) ---
        const paramsOrig = new URLSearchParams(window.location.search);
        const origParam = (paramsOrig.get('origem') || '').toLowerCase();
        const cupomOrig = (cupomAplicado.codigo || paramsOrig.get('cupom') || '').toUpperCase();
        let canalOrigem = 'link_direto';
        if (canalAtendente) {
            canalOrigem = 'atendente';
        } else if (cupomOrig.indexOf('VOLTA') === 0 || sessionStorage.getItem('pz_via_ia') === '1') {
            canalOrigem = 'recuperacao_ia';
        } else if (origParam === 'marketplace' || (document.referrer && document.referrer.indexOf('marketplace') !== -1)) {
            canalOrigem = 'marketplace';
        }

        const pedidoData = {
            id: pedidoNumero,
            codigo: codigoRastreio,
            tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default',
            data: new Date().toISOString(),
            cliente: enderecoCliente.nome,
            celular: enderecoCliente.celular,
            itens: carrinho.map(item => ({
                ...item,
                opcionais: item.opcionais || []
            })),
            subtotal: infoFrete.subtotal,
            frete: infoFrete.valor,
            frete_detalhe: infoFrete.rotulo + (infoFrete.detalhe ? ' — ' + infoFrete.detalhe : ''),
            distancia_km: infoFrete.distanciaKm,
            recebimento: modoRecebimento,
            total: totalGeral,
            entrega: infoEntrega,
            endereco: enderecoCliente,
            pagamento: formaPagamento,
            troco: troco,
            observacao: observacao,
            cupom: cupomAplicado.codigo || '',
            desconto: descontoPedido,
            sessao_cliente: sessaoClienteVitrine,
            visitor_id: visitorAlg,
            consentimento_ia: algConsent,
            status: 'novo',
            canal: canalOrigem
        };

        if (canalAtendente && atendimentoCfg.rotulo_entrega_manual) {
            pedidoData.entrega = atendimentoCfg.rotulo_entrega_manual;
        }

        const numeroWhatsApp = config.WHATSAPP_NUMBER;
        const abrirWhatsapp = !canalAtendente || !!atendimentoCfg.abrir_whatsapp;
        let janelaWhats = null;
        if (abrirWhatsapp && numeroWhatsApp) {
            janelaWhats = window.open(`https://wa.me/${numeroWhatsApp}?text=${encodeURIComponent(mensagem)}`, '_blank');
        }

        localStorage.setItem('ultimoPedidoId', pedidoData.id);
        localStorage.setItem('ultimoPedidoCodigo', codigoRastreio);

        try {
            const slugUlt = (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : 'default';
            localStorage.setItem('pz_ultimo_pedido_' + slugUlt, JSON.stringify({
                id: pedidoData.id,
                total: totalGeral,
                itens: carrinho.map((it) => Object.assign({}, it)),
                criadoEm: Date.now()
            }));
            localStorage.setItem('pz_checkout_perfil_rapido', JSON.stringify({
                nome: enderecoCliente.nome || '',
                celular: enderecoCliente.celular || '',
                cep: enderecoCliente.cep || '',
                rua: enderecoCliente.rua || '',
                numero: enderecoCliente.numero || '',
                complemento: enderecoCliente.complemento || '',
                bairro: enderecoCliente.bairro || '',
                cidade: enderecoCliente.cidade || '',
                referencia: enderecoCliente.referencia || ''
            }));
        } catch (e) { /* quota */ }

        const resumoCelulaPedido = {
            id: pedidoData.id,
            codigo: codigoRastreio,
            tenant: pedidoData.tenant,
            loja_nome: (config && config.NOME_LOJA) || pedidoData.tenant,
            celular: pedidoData.celular,
            total: pedidoData.total,
            status: 'novo',
            url_rastreio: rastreioRelativo
        };
        if (window.PedidoZapCelulas) {
            window.PedidoZapCelulas.registrarPedidoLocal(resumoCelulaPedido);
        }

        const destinoAposPedido = canalAtendente
            ? ((typeof TENANT !== 'undefined' && TENANT.apiUrl) ? TENANT.apiUrl('painel.html') : 'painel.html')
            : rastreioRelativo;

        if (window.PZAlgoritmo && typeof window.PZAlgoritmo.getVisitorId === 'function') {
            pedidoData.visitor_id = window.PZAlgoritmo.getVisitorId();
        }
        if (!checkoutTokenAtual) checkoutTokenAtual = novoCheckoutToken();
        pedidoData.checkout_token = checkoutTokenAtual;
        pedidoData.modo_analise = Boolean(window.PedidoZapApresentacao && window.PedidoZapApresentacao.ativo());
        if (canalAtendente || pedidoData.modo_analise) {
            try {
                const uAuth = sessionStorage.getItem('admin_user') || '';
                const pAuth = sessionStorage.getItem('admin_pass') || '';
                if (uAuth && pAuth) {
                    pedidoData.auth = { user: uAuth, pass: pAuth };
                }
            } catch (e) { }
        }

        if (enviandoPedido) return;
        enviandoPedido = true;
        if (btnEnviarPedidoFinal) btnEnviarPedidoFinal.disabled = true;

        let pedidoSalvoNoServidor = false;
        let erroServidorMsg = '';

        apiFetch('save_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pedidoData),
            keepalive: true
        }).then(async (res) => {
            const payload = await res.json().catch(() => ({}));
            if (res && res.ok && payload && payload.success) {
                pedidoSalvoNoServidor = true;
            } else if (res && (res.status === 409 || res.status === 400 || res.status === 422)) {
                erroServidorMsg = (payload && payload.message) || 'Confira o carrinho antes de finalizar.';
            }
            return payload;
        }).then((resp) => {
            if (!pedidoSalvoNoServidor) return;
            if (resp && resp.id) {
                localStorage.setItem('ultimoPedidoId', String(resp.id));
                resumoCelulaPedido.id = resp.id;
            }
            if (resp && resp.codigo && resp.codigo !== codigoRastreio) {
                localStorage.setItem('ultimoPedidoCodigo', resp.codigo);
                resumoCelulaPedido.codigo = resp.codigo;
                if (window.PedidoZapCelulas) {
                    window.PedidoZapCelulas.registrarPedidoLocal(resumoCelulaPedido);
                }
            }
            if (window.PedidoZapOfflineCache) {
                const s = (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : 'default';
                window.PedidoZapOfflineCache.limparPedidoPendente(s).catch(() => { });
            }
            if (window.PedidoZapCelulas) {
                window.PedidoZapCelulas.emitir('pedido_criado', resumoCelulaPedido);
            }
        }).catch(err => {
            console.error("Erro ao salvar no painel:", err);
        }).finally(() => {
            enviandoPedido = false;
            if (btnEnviarPedidoFinal) btnEnviarPedidoFinal.disabled = false;
            if (erroServidorMsg) {
                showToast(erroServidorMsg, 'erro');
                return;
            }
            if (!pedidoSalvoNoServidor && typeof navigator !== 'undefined' && navigator.onLine === false) {
                const s = (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : 'default';
                if (window.PedidoZapOfflineCache) {
                    window.PedidoZapOfflineCache.salvarPedidoPendente(s, {
                        pedidoData: pedidoData,
                        mensagem: mensagem,
                        rastreioRelativo: rastreioRelativo
                    }).catch(() => { });
                }
                salvarCarrinho();
                if (modalConfirmacao) modalConfirmacao.classList.add('escondido');
                showToast('Você está offline: seu carrinho e pedido ficaram salvos na página para enviar quando voltar a internet!', 'info');
                return;
            }
            checkoutTokenAtual = novoCheckoutToken();
            carrinho = [];
            salvarCarrinho();
            setTimeout(() => {
                if (canalAtendente && !abrirWhatsapp) {
                    alert(atendimentoCfg.texto_sucesso || '');
                    window.location.href = destinoAposPedido;
                    return;
                }
                if (!janelaWhats && abrirWhatsapp && numeroWhatsApp) {
                    window.location.href = `https://wa.me/${numeroWhatsApp}?text=${encodeURIComponent(mensagem)}`;
                    return;
                }
                window.location.href = destinoAposPedido;
            }, 800);
        });
    }

    function gerarCodigoRastreio() {
        const bytes = new Uint8Array(9);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    }

    // --- MÁSCARAS DE INPUT (Consolidado) ---
    const inputCelular = document.getElementById('input-celular');

    if (inputCelular) {
        if (!inputCelular.value.trim() && window.PedidoZapCelulas) {
            const pref = window.PedidoZapCelulas.obterTelefonePreferido();
            if (pref && pref.length >= 10) {
                inputCelular.value = pref.length > 10
                    ? pref.replace(/^(\d{2})(\d{5})(\d{4}).*/, '($1) $2-$3')
                    : pref.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
            }
        }
        inputCelular.addEventListener('input', (e) => {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.slice(0, 11);

            if (value.length > 10) {
                value = value.replace(/^(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
            } else if (value.length > 5) {
                value = value.replace(/^(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
            } else if (value.length > 2) {
                value = value.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
            } else if (value.length > 0) {
                value = value.replace(/^(\d{0,2})/, '($1');
            }
            e.target.value = value;
        });
    }

    if (inputCep) {
        inputCep.addEventListener('input', (e) => {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 8) value = value.slice(0, 8);
            if (value.length > 5) {
                value = value.replace(/^(\d{5})(\d)/, '$1-$2');
            }
            e.target.value = value;
        });
    }

    const btnDemoEndereco = document.getElementById('btn-demo-endereco');
    if (btnDemoEndereco && window.PedidoZapApresentacao && window.PedidoZapApresentacao.ativo()) {
        btnDemoEndereco.hidden = false;
        btnDemoEndereco.addEventListener('click', () => {
            const set = (id, v) => { const el = document.getElementById(id); if (el && !el.value.trim()) el.value = v; };
            set('input-nome', 'Cliente Análise');
            set('input-celular', '(62) 99999-8888');
            set('input-cep', '74215-030');
            set('input-rua', 'Av. T-63');
            set('input-numero', '1200');
            set('input-bairro', 'Setor Bueno');
            set('input-cidade', 'Goiânia');
            showToast('Dados de demonstração preenchidos!', 'sucesso');
        });
    }


    // --- EVENT LISTENERS ---

    if (btnAdicionarUpsell && btnRecusarUpsell && modalUpsell) {
        btnAdicionarUpsell.addEventListener('click', () => {
            const sugerido = (config.UPSELL && config.UPSELL.produto_id)
                ? encontrarProdutoPorId(config.UPSELL.produto_id)
                : null;
            if (sugerido) adicionarAoCarrinho(sugerido);
            modalUpsell.classList.add('escondido');
        });

        btnRecusarUpsell.addEventListener('click', () => {
            modalUpsell.classList.add('escondido');
        });
    }

    if (containerPrincipal) containerPrincipal.addEventListener('click', (e) => {
        const produtoDiv = e.target.closest('.produto, .produto-promocao');
        if (produtoDiv) {
            // Se clicar no botão de adicionar ou na imagem
            if (e.target.closest('.btn-adicionar') || e.target.closest('img')) {
                const idProduto = parseInt(produtoDiv.dataset.id);
                handleProdutoClick(idProduto);
            }
        }
    });

    if (btnAdicionarCustomizado && listaOpcionais && modalCustomizacao) btnAdicionarCustomizado.addEventListener('click', () => {
        const grupos = {};
        listaOpcionais.querySelectorAll('input[type="checkbox"]').forEach((chk) => {
            const id = chk.dataset.grupo || '0';
            if (!grupos[id]) grupos[id] = { min: Number(chk.dataset.min) || 0, max: Number(chk.dataset.max) || 30, qtd: 0 };
            if (chk.checked) grupos[id].qtd += 1;
        });
        const invalido = Object.keys(grupos).some((id) => grupos[id].qtd < grupos[id].min || grupos[id].qtd > grupos[id].max);
        if (invalido) {
            showToast('Confira as quantidades mínimas e máximas de cada grupo.', 'erro');
            return;
        }
        const opcionaisSelecionados = [];
        listaOpcionais.querySelectorAll('input[type="checkbox"]:checked').forEach((chk) => {
            opcionaisSelecionados.push({
                nome: chk.dataset.nome,
                preco: parseFloat(chk.dataset.preco) || 0
            });
        });
        const tamanhoEl = listaOpcionais.querySelector('input[name="tamanho-item"]:checked');
        const tamanho = tamanhoEl ? { nome: tamanhoEl.dataset.nome, preco: parseFloat(tamanhoEl.dataset.preco) || 0 } : null;
        adicionarAoCarrinho(itemParaCustomizar, opcionaisSelecionados, tamanho);
        modalCustomizacao.classList.add('escondido');
    });

    if (listaItensCarrinhoModal) listaItensCarrinhoModal.addEventListener('click', (e) => {
        const itemDiv = e.target.closest('.item-carrinho');
        if (!itemDiv) return;
        const idUnico = parseInt(itemDiv.dataset.id);
        if (e.target.classList.contains('btn-aumentar')) aumentarQuantidade(idUnico);
        if (e.target.classList.contains('btn-diminuir')) diminuirQuantidade(idUnico);
    });

    if (inputCep) inputCep.addEventListener('blur', () => {
        const cep = inputCep.value.replace(/\D/g, '');
        if (cep.length === 8) {
            buscarCep(cep);
        }
    });
    if (btnFinalizarModal && modalCarrinho && modalEndereco) btnFinalizarModal.addEventListener('click', () => {
        if (carrinho.length > 0) {
            if (canalAtendente) {
                const nomeEl = document.getElementById('input-nome');
                const telEl = document.getElementById('input-celular');
                if (nomeEl) nomeEl.value = sessionStorage.getItem(storageAtendente('atendente_cliente_nome')) || nomeEl.value;
                if (telEl) telEl.value = sessionStorage.getItem(storageAtendente('atendente_cliente_tel')) || telEl.value;
                const retiradaRad = document.querySelector('input[name="modo-recebimento-endereco"][value="retirada"]');
                if (retiradaRad && (atendimentoCfg.modo_padrao || 'retirada') === 'retirada') retiradaRad.checked = true;
            } else {
                const preencherCamposRapido = () => {
                    const fill = (id, val) => {
                        const el = document.getElementById(id);
                        if (el && !el.value.trim() && val) el.value = String(val).trim();
                    };
                    try {
                        const rapido = JSON.parse(localStorage.getItem('pz_checkout_perfil_rapido') || 'null');
                        if (rapido) {
                            fill('input-nome', rapido.nome);
                            fill('input-celular', rapido.celular);
                            fill('input-cep', rapido.cep);
                            fill('input-rua', rapido.rua);
                            fill('input-numero', rapido.numero);
                            fill('input-complemento', rapido.complemento);
                            fill('input-bairro', rapido.bairro);
                            fill('input-cidade', rapido.cidade);
                            fill('input-referencia', rapido.referencia);
                        }
                    } catch (e) { }
                    try {
                        const compRaw = localStorage.getItem('pz_comprador_info');
                        if (compRaw) {
                            const comp = JSON.parse(compRaw);
                            const ends = Array.isArray(comp.enderecos) ? comp.enderecos : [];
                            const endAtivo = ends[0] || {};
                            fill('input-nome', comp.nome);
                            fill('input-celular', comp.telefone);
                            fill('input-cep', endAtivo.cep);
                            fill('input-rua', endAtivo.rua);
                            fill('input-numero', endAtivo.numero);
                            fill('input-complemento', endAtivo.complemento);
                            fill('input-bairro', endAtivo.bairro);
                            fill('input-cidade', endAtivo.cidade);
                        }
                    } catch (e) { /* ignora erro de localStorage */ }
                    const pill = document.getElementById('pz-checkout-rapido-pill');
                    const nVal = (document.getElementById('input-nome') || {}).value || '';
                    const rVal = (document.getElementById('input-rua') || {}).value || '';
                    const tVal = (document.getElementById('input-celular') || {}).value || '';
                    if (pill) {
                        const pronto = nVal.trim().length >= 3 && tVal.replace(/\D/g, '').length >= 10 && rVal.trim().length >= 2;
                        pill.style.display = pronto ? 'flex' : 'none';
                        const txtPill = document.getElementById('pz-checkout-rapido-txt');
                        if (txtPill && pronto) {
                            txtPill.textContent = '⚡ Entrega p/ ' + nVal.split(' ')[0] + ' (' + rVal + ') salva!';
                        }
                    }
                };
                preencherCamposRapido();
            }
            modalCarrinho.classList.add('escondido');
            modalEndereco.classList.remove('escondido');
            checkoutTokenAtual = novoCheckoutToken();
        } else {
            showToast("Carrinho vazio!", 'info');
        }
    });

    if (btnConfirmarEndereco && modalEndereco && modalConfirmacao && resumoPedidoDiv) btnConfirmarEndereco.addEventListener('click', async () => {
        const retiradaEscolhida = document.querySelector('input[name="modo-recebimento-endereco"]:checked');
        if (retiradaEscolhida) modoRecebimento = retiradaEscolhida.value;
        const retirada = modoRecebimento === 'retirada';

        const nome = document.getElementById('input-nome').value.trim();
        const celularRaw = document.getElementById('input-celular').value.replace(/\D/g, '');
        const cepRaw = document.getElementById('input-cep').value.replace(/\D/g, '');
        const rua = document.getElementById('input-rua').value.trim();
        const numero = document.getElementById('input-numero').value.trim();
        const bairro = document.getElementById('input-bairro').value.trim();
        const cidade = document.getElementById('input-cidade').value.trim();

        let erros = [];

        // 1. Validação Nome (Mínimo 3 caracteres)
        if (nome.length < 3) {
            erros.push("nome");
            showToast("Por favor, digite seu nome (mínimo 3 letras).", "erro");
        }

        // 2. Validação Celular (10 ou 11 dígitos)
        if (celularRaw.length < 10) {
            erros.push("celular");
            showToast("Celular inválido. Digite DDD + Número.", "erro");
        }

        // 3. Validação CEP (dispensada na retirada)
        if (!retirada && cepRaw.length < 8) {
            erros.push("cep");
            showToast("O CEP deve ter 8 números.", "erro");
        }

        // 4. Campos Vazios
        const obrigatorios = retirada
            ? [
                { id: 'input-nome', val: nome },
                { id: 'input-celular', val: celularRaw }
            ]
            : [
                { id: 'input-nome', val: nome },
                { id: 'input-celular', val: celularRaw },
                { id: 'input-cep', val: cepRaw },
                { id: 'input-rua', val: rua },
                { id: 'input-numero', val: numero },
                { id: 'input-bairro', val: bairro },
                { id: 'input-cidade', val: cidade }
            ];

        obrigatorios.forEach(campo => {
            const el = document.getElementById(campo.id);
            if (!campo.val) {
                if (!erros.includes(campo.id)) erros.push(campo.id);
                el.classList.add('input-erro');
            } else {
                el.classList.remove('input-erro');
            }
            el.addEventListener('input', () => el.classList.remove('input-erro'));
        });

        if (erros.length > 0) {
            if (erros.some(e => !['nome', 'celular', 'cep'].includes(e))) {
                showToast("Preencha todos os campos obrigatórios em vermelho.", 'erro');
            }
            return;
        }

        enderecoCliente = {
            nome: document.getElementById('input-nome').value.trim(),
            celular: document.getElementById('input-celular').value.trim(),
            cep: document.getElementById('input-cep').value.trim(),
            rua: document.getElementById('input-rua').value.trim(),
            numero: document.getElementById('input-numero').value.trim(),
            complemento: document.getElementById('input-complemento').value.trim(),
            bairro: document.getElementById('input-bairro').value.trim(),
            cidade: document.getElementById('input-cidade').value.trim(),
            referencia: document.getElementById('input-referencia').value.trim(),
            distanciaKm: null
        };

        const modoFrete = (config.FRETE && config.FRETE.modo) || 'gratis';
        let usarFallbackDistancia = false;
        if (!retirada && modoFrete === 'distancia' && window.PedidoZapFrete) {
            const loja = config.ENDERECO || {};
            if (loja.lat == null || loja.lng == null) {
                usarFallbackDistancia = true;
            } else {
                const preview = document.getElementById('frete-preview');
                if (preview) preview.textContent = 'Calculando distância...';
                try {
                    const destino = await PedidoZapFrete.geocode([rua, numero, bairro, cidade, cepRaw, 'Brasil'].filter(Boolean).join(', '));
                    enderecoCliente.lat = destino.lat;
                    enderecoCliente.lng = destino.lng;
                    enderecoCliente.distanciaKm = PedidoZapFrete.haversineKm(Number(loja.lat), Number(loja.lng), destino.lat, destino.lng);
                } catch (err) {
                    enderecoCliente.distanciaKm = null;
                    usarFallbackDistancia = true;
                    showToast('Endereço sem ponto exato no mapa — aplicando taxa padrão/bairro para não travar seu pedido.', 'aviso');
                }
            }
        }

        const infoFrete = calcularFreteAtual(usarFallbackDistancia ? { fallbackDistancia: true } : undefined);
        const preview = document.getElementById('frete-preview');
        if (preview) preview.textContent = infoFrete.rotulo + ': ' + formatarMoeda(infoFrete.valor);
        if (!infoFrete.ok) {
            showToast(infoFrete.detalhe || 'Entrega indisponível para este endereço.', 'erro');
            return;
        }

        let resumoHTML = '';
        carrinho.forEach(item => {
            let linha = `<p><strong>${item.quantidade}x</strong> ${item.nome}`;
            const ops = item.opcionais || [];
            if (ops.length) {
                linha += ` <span style="color:#666;font-size:0.9em;">(${ops.map(o => o.nome).join(', ')})</span>`;
            }
            linha += ` — ${formatarMoeda(item.precoTotal)}</p>`;
            resumoHTML += linha;
        });
        resumoHTML += '<div style="margin-top:10px;border-top:1px solid #ddd;padding-top:8px;">' + htmlResumoValores(infoFrete) + '</div>';

        resumoPedidoDiv.innerHTML = resumoHTML;
        pintarResumoFrete();
        const radioConfirmado = document.querySelector('input[name="modo-recebimento"][value="' + modoRecebimento + '"]');
        if (radioConfirmado) radioConfirmado.checked = true;
        modalEndereco.classList.add('escondido');
        modalConfirmacao.classList.remove('escondido');
    });

    radiosTipoEntrega.forEach(radio => { radio.addEventListener('change', (e) => { if (campoHorario) campoHorario.classList.toggle('escondido', e.target.value !== 'agendar'); }); });
    if (btnEnviarPedidoFinal) btnEnviarPedidoFinal.addEventListener('click', enviarPedidoWhatsApp);

    const btnAplicarCupom = document.getElementById('btn-aplicar-cupom');
    const inputCupom = document.getElementById('input-cupom');
    const cupomFeedback = document.getElementById('cupom-feedback');
    if (btnAplicarCupom && inputCupom) {
        btnAplicarCupom.addEventListener('click', async () => {
            const cod = inputCupom.value.trim().toUpperCase();
            if (!cod) {
                cupomAplicado = { codigo: '', desconto: 0 };
                if (cupomFeedback) cupomFeedback.textContent = '';
                pintarResumoFrete();
                return;
            }
            const info = calcularFreteAtual();
            try {
                const resp = await apiFetch('recuperacao_api.php?action=validar_cupom', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default',
                        codigo: cod,
                        subtotal: info.subtotal,
                        frete: info.valor,
                        celular: enderecoCliente.celular || ''
                    })
                });
                const res = await resp.json();
                if (res && res.valido) {
                    cupomAplicado = { codigo: res.codigo || cod, desconto: Number(res.desconto || 0) };
                    if (cupomFeedback) {
                        cupomFeedback.style.color = '#059669';
                        cupomFeedback.textContent = (res.message || 'Cupom aplicado!') + ' Desconto: ' + formatarMoeda(cupomAplicado.desconto);
                    }
                } else {
                    cupomAplicado = { codigo: '', desconto: 0 };
                    if (cupomFeedback) {
                        cupomFeedback.style.color = '#dc2626';
                        cupomFeedback.textContent = (res && res.message) || 'Cupom inválido.';
                    }
                }
            } catch (e) {
                cupomAplicado = { codigo: '', desconto: 0 };
                if (cupomFeedback) {
                    cupomFeedback.style.color = '#dc2626';
                    cupomFeedback.textContent = 'Não foi possível validar o cupom agora.';
                }
            }
            pintarResumoFrete();
        });
    }

    const carrinhoFlutuante = document.getElementById('carrinho-flutuante');
    if (carrinhoFlutuante && modalCarrinho) carrinhoFlutuante.addEventListener('click', (e) => {
        if (carrinho.length > 0) {
            modalCarrinho.classList.remove('escondido');
        } else {
            showToast("Seu carrinho está vazio! Adicione itens para começar.", 'info');
        }
    });

    // --- BLOQUEIO DINÂMICO DE HORÁRIO ---

    function isLojaAberta() {
        if (config && Number(config.PAUSADA_ATE || 0) * 1000 > Date.now()) {
            return false;
        }
        const agora = new Date();
        const diaSemana = agora.getDay(); // 0 = Dom, 1 = Seg ... 6 = Sab

        const regras = config.HORARIOS;
        if (!regras) return true;

        // Verifica se hoje está na lista de dias fechados
        if (regras.FECHADO && Array.isArray(regras.FECHADO) && regras.FECHADO.map(Number).includes(diaSemana)) {
            return false;
        }

        // Sex(5), Sab(6), Dom(0) -> Fim de semana
        const ehFimDeSemana = diaSemana === 0 || diaSemana === 5 || diaSemana === 6;
        const horario = ehFimDeSemana ? (regras.FIM_DE_SEMANA || regras.SEMANA) : (regras.SEMANA || regras.FIM_DE_SEMANA);
        if (!horario) return true;

        const parseMin = (val) => {
            if (val === undefined || val === null) return 0;
            if (typeof val === 'number') return val * 60;
            if (typeof val === 'string') {
                const p = val.split(':');
                return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0);
            }
            return 0;
        };

        const inicioMin = parseMin(horario.inicio);
        const fimMin = parseMin(horario.fim);
        const agoraMin = agora.getHours() * 60 + agora.getMinutes();

        if (inicioMin < fimMin) {
            return agoraMin >= inicioMin && agoraMin < fimMin;
        } else if (inicioMin > fimMin) {
            // Suporte para funcionamento na madrugada (ex: 18:00 às 02:00)
            return agoraMin >= inicioMin || agoraMin < fimMin;
        } else {
            return true;
        }
    }

    // Atualiza estado do botão ao abrir modal de confirmação
    const observerConfirmacao = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.target.id === 'modal-confirmacao' && !mutation.target.classList.contains('escondido')) {
                verificarDisponibilidadeEntrega();
            }
        });
    });
    if (modalConfirmacao) observerConfirmacao.observe(modalConfirmacao, { attributes: true, attributeFilter: ['class'] });

    function verificarDisponibilidadeEntrega() {
        const aberta = isLojaAberta();

        if (!aberta) {
            if (window.PedidoZapApresentacao && window.PedidoZapApresentacao.ativo()) {
                btnEnviarPedidoFinal.disabled = false;
                btnEnviarPedidoFinal.classList.remove('desabilitado');
                btnEnviarPedidoFinal.textContent = (textoBotaoConfirmar() || 'Confirmar Pedido') + ' (Teste)';
                return;
            }
            // Se fechada, força agendamento ou bloqueia tudo? 
            // O pedido "Agora" deve ser bloqueado.
            if (document.getElementById('entrega-agora').checked) {
                btnEnviarPedidoFinal.disabled = true;
                btnEnviarPedidoFinal.classList.add('desabilitado');
                btnEnviarPedidoFinal.textContent = atendimentoCfg.texto_loja_fechada || textoBotaoConfirmar() || btnEnviarPedidoFinal.textContent;

                // Opção: Forçar seleção de agendamento?
                // Vamos deixar o usuário escolher agendar
            }
        } else {
            // Se aberta
            btnEnviarPedidoFinal.disabled = false;
            btnEnviarPedidoFinal.classList.remove('desabilitado');
            btnEnviarPedidoFinal.textContent = textoBotaoConfirmar() || btnEnviarPedidoFinal.textContent;
        }
    }

    if (btnEnviarPedidoFinal && textoBotaoConfirmar()) {
        btnEnviarPedidoFinal.textContent = textoBotaoConfirmar();
    }

    // Listener nos radios de entrega para revalidar botão
    radiosTipoEntrega.forEach(radio => {
        radio.addEventListener('change', (e) => {
            campoHorario.classList.toggle('escondido', e.target.value !== 'agendar');

            if (e.target.value === 'agora') {
                verificarDisponibilidadeEntrega();
            } else {
                // Se for agendar, libera o botão (pois validaremos o horario inserido)
                btnEnviarPedidoFinal.disabled = false;
                btnEnviarPedidoFinal.classList.remove('desabilitado');
                btnEnviarPedidoFinal.textContent = textoBotaoConfirmar() || btnEnviarPedidoFinal.textContent;
            }
        });
    });

    // --- UI EVENTS ---
    // Sticky Header Effect
    const header = document.querySelector('.cabecalho');
    if (header) window.addEventListener('scroll', () => {
        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });

    // Event Listener do Banner de Ofertas WhatsApp
    const btnWppOferta = document.getElementById('btn-wpp-oferta');
    if (btnWppOferta) {
        btnWppOferta.addEventListener('click', (e) => {
            e.preventDefault();
            const msg = (config.BANNER_WPP && config.BANNER_WPP.mensagem)
                ? config.BANNER_WPP.mensagem
                : 'Olá! Vi as ofertas no site e quero saber os descontos de hoje.';
            const num = config.WHATSAPP_NUMBER || (typeof APP_CONFIG !== 'undefined' ? APP_CONFIG.WHATSAPP_NUMBER : '');
            window.open(`https://wa.me/${num}?text=${encodeURIComponent(msg)}`, '_blank');
        });
    }

    // Fechar modais
    if (btnFecharModalCarrinho && modalCarrinho) btnFecharModalCarrinho.addEventListener('click', () => modalCarrinho.classList.add('escondido'));
    if (btnFecharModalCustomizacao && modalCustomizacao) btnFecharModalCustomizacao.addEventListener('click', () => modalCustomizacao.classList.add('escondido'));
    if (btnFecharModalEndereco && modalEndereco) btnFecharModalEndereco.addEventListener('click', () => modalEndereco.classList.add('escondido'));
    if (btnFecharConfirmacaoModal && modalConfirmacao) btnFecharConfirmacaoModal.addEventListener('click', () => modalConfirmacao.classList.add('escondido'));

    // Fechar ao clicar fora
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.classList.add('escondido');
        });
    });

    // Lógica para Formas de Pagamento (Destaque e Troco)
    radiosPagamento.forEach(radio => {
        radio.addEventListener('change', (e) => {
            // Remove destaque de todos os labels
            document.querySelectorAll('.radio-pagamento').forEach(label => {
                label.classList.remove('selecionado');
            });

            // Adiciona destaque ao selecionado
            if (e.target.checked) {
                e.target.closest('.radio-pagamento').classList.add('selecionado');
            }

            // Mostra/Oculta campo de troco
            if (e.target.value === 'Dinheiro') {
                campoTroco.classList.remove('escondido');
                inputTroco.focus();
            } else {
                campoTroco.classList.add('escondido');
                inputTroco.value = '';
            }
        });
    });

    // Máscara simples para troco (apenas números e vírgula/ponto)
    if (inputTroco) inputTroco.addEventListener('input', (e) => {
        e.target.value = e.target.value.replace(/[^0-9,.]/g, '');
    });

    function encontrarProdutoPorId(id) {
        const alvo = String(id);
        for (const categoria in cardapio) {
            const lista = cardapio[categoria];
            if (!Array.isArray(lista)) continue;
            const item = lista.find((produto) => String(produto.id) === alvo);
            if (item) return item;
        }
        return null;
    }

    function criarItemProdutoHTML(produto) {
        const classeProduto = produto.ehPromocao ? "produto-promocao" : "produto";
        const selo = produto.esgotado ? '<span style="display:inline-block;background:#fee2e2;color:#991b1b;border-radius:999px;padding:2px 8px;font-size:0.75em;">Esgotado hoje</span>' : '';

        if (produto.ehPromocao) {
            // ESTRUTURA PARA PROMOÇÃO (COM PREÇO 'DE')
            const displayPrecoOriginal = produto.precoOriginal ? `<span style="text-decoration: line-through; color: #999; font-size: 0.9em; margin-right: 5px;">R$ ${parseFloat(produto.precoOriginal).toFixed(2).replace('.', ',')}</span>` : '';
            const imgUrl = (produto.imagem || 'assets/images/logo.jpeg').replace(/'/g, "\\'");
            return `<div class="${classeProduto}" data-id="${produto.id}">
                        <div class="promo-img-wrapper">
                            <div class="promo-bg-blur" style="background-image: url('${imgUrl}');"></div>
                            <img src="${produto.imagem || 'assets/images/logo.jpeg'}" alt="${produto.nome}" loading="lazy" onerror="this.src='assets/images/logo.jpeg'; this.onerror=null;">
                        </div>
                        <div class="produto-info">
                            <h4>${produto.nome} ${selo}</h4>
                            <p>${produto.descricao}</p>
                            <div style="margin-top: 10px;">
                                ${displayPrecoOriginal}
                                <span class="produto-preco">R$ ${produto.preco.toFixed(2).replace('.', ',')}</span>
                            </div>
                        </div>
                        <div class="produto-acoes">
                            <button class="btn-adicionar">+</button>
                        </div>
                    </div>`;
        } else {
            // Nova estrutura Card Grande
            return `<div class="${classeProduto}" data-id="${produto.id}">
                    <img src="${produto.imagem}" alt="${produto.nome}" loading="lazy" onerror="this.src='assets/images/logo.jpeg'; this.onerror=null;">
                    <div class="produto-info">
                        <h4>${produto.nome} ${selo}</h4>
                        <p>${produto.descricao}</p>
                    </div>
                    <div class="produto-footer">
                        <span class="produto-preco">R$ ${produto.preco.toFixed(2).replace('.', ',')}</span>
                        <div class="produto-acoes">
                            <button class="btn-adicionar"><i class="fa-solid fa-plus"></i></button>
                        </div>
                    </div>
                </div>`;
        }
    }

    function renderizarCardapio(filtro = 'todos', termoBusca = '') {
        const listaDinamica = document.getElementById('lista-categoria');
        if (listaDinamica) {
            const cat = new URLSearchParams(location.search).get('cat') || '';
            const meta = (config.CATEGORIAS || {})[cat] || {};
            const titulo = document.querySelector('[data-bind="titulo-categoria"]');
            const paginaCfg = ((config.TEXTOS || {}).PAGINAS || {})[cat] || {};
            if (titulo) titulo.textContent = paginaCfg.titulo || meta.nome || 'Categoria';
            const iconeCat = document.querySelector('.categoria-titulo i');
            if (iconeCat && meta.icone) iconeCat.className = 'fa-solid ' + meta.icone;
            const itens = (cardapio[cat] || []).filter((item) => {
                if (!item || item.ativo === false) return false;
                const busca = (termoBusca || '').toLowerCase();
                if (!busca) return true;
                return (item.nome || '').toLowerCase().includes(busca) || (item.descricao || '').toLowerCase().includes(busca);
            });
            listaDinamica.innerHTML = itens.length
                ? itens.map((item) => criarItemProdutoHTML(item)).join('')
                : '<p class="msg-vazio">Nenhum item nesta categoria.</p>';
            return;
        }

        const secaoHamburgueres = document.getElementById('lista-hamburgueres');
        const secaoPorcoes = document.getElementById('lista-porcoes');
        const secaoBebidas = document.getElementById('lista-bebidas');
        const secaoAcai = document.getElementById('lista-acai');
        const secaoCombos = document.getElementById('lista-combos');

        const filtrar = (item) => {
            const matchCategoria = filtro === 'todos' ||
                (filtro === 'hamburgueres' && cardapio.hamburgueres && cardapio.hamburgueres.includes(item)) ||
                (filtro === 'porcoes' && cardapio.porcoes && cardapio.porcoes.includes(item)) ||
                (filtro === 'bebidas' && cardapio.bebidas && cardapio.bebidas.includes(item)) ||
                (filtro === 'acai' && cardapio.acai && cardapio.acai.includes(item)) ||
                (filtro === 'combos' && cardapio.combos && cardapio.combos.includes(item));
            const matchBusca = item.nome.toLowerCase().includes(termoBusca.toLowerCase()) ||
                (item.descricao || '').toLowerCase().includes(termoBusca.toLowerCase());
            return matchCategoria && matchBusca && item.ativo !== false;
        };

        const hamburgueresFiltrados = cardapio.hamburgueres ? cardapio.hamburgueres.filter(filtrar) : [];
        const porcoesFiltradas = cardapio.porcoes ? cardapio.porcoes.filter(filtrar) : [];
        const bebidasFiltradas = cardapio.bebidas ? cardapio.bebidas.filter(filtrar) : [];
        const acaiFiltrados = cardapio.acai ? cardapio.acai.filter(filtrar) : [];
        const combosFiltrados = cardapio.combos ? cardapio.combos.filter(filtrar) : [];

        const atualizarSecao = (elementoLista, itens, nomeFiltro) => {
            if (!elementoLista) return;
            const container = elementoLista.closest('.categoria');

            if (itens.length > 0) {
                if (container) container.style.display = 'block';
                // Otimização: Gera todo o HTML em memória antes de injetar no DOM
                const html = itens.map(item => criarItemProdutoHTML(item)).join('');
                elementoLista.innerHTML = html;
            } else {
                if (filtro === nomeFiltro && termoBusca.length > 0) {
                    if (container) container.style.display = 'block';
                    elementoLista.innerHTML = '<p class="msg-vazio">Nenhum item encontrado.</p>';
                } else if (!termoBusca && document.body && document.body.dataset.pagina && document.body.dataset.pagina !== 'home') {
                    if (container) container.style.display = 'block';
                    elementoLista.innerHTML = '<p class="msg-vazio">Nenhum item cadastrado nesta categoria.</p>';
                } else {
                    if (container) container.style.display = 'none';
                    elementoLista.textContent = '';
                }
            }
        };

        if (secaoHamburgueres) atualizarSecao(secaoHamburgueres, hamburgueresFiltrados, 'hamburgueres');
        if (secaoPorcoes) atualizarSecao(secaoPorcoes, porcoesFiltradas, 'porcoes');
        if (secaoBebidas) atualizarSecao(secaoBebidas, bebidasFiltradas, 'bebidas');
        if (secaoAcai) atualizarSecao(secaoAcai, acaiFiltrados, 'acai');
        if (secaoCombos) atualizarSecao(secaoCombos, combosFiltrados, 'combos');
    }

    function renderizarDestaques() {
        const secaoDestaques = document.getElementById('lista-mais-pedidos');
        if (!secaoDestaques) return;
        secaoDestaques.innerHTML = '';

        const destaques = [];
        Object.keys(cardapio).forEach((chave) => {
            (cardapio[chave] || []).forEach((item) => {
                if (item && item.destaque && item.ativo !== false) destaques.push(item);
            });
        });

        const secao = document.getElementById('mais-pedidos');
        if (!destaques.length) {
            secaoDestaques.innerHTML = '';
            if (secao) secao.style.display = 'none';
            return;
        }
        if (secao) secao.style.display = '';
        secaoDestaques.innerHTML = destaques.slice(0, 8).map((item) => criarItemProdutoHTML(item)).join('');
    }
    function renderizarPromocoes() {
        const secaoPromocoes = document.getElementById('lista-promocoes');
        if (!secaoPromocoes) return;
        secaoPromocoes.innerHTML = '';

        // Filter active promotions
        const promosAtivas = (cardapio.promocoes || []).filter(p => p.ativo !== false);
        const secaoPai = document.getElementById('promocoes');
        const bannerAtivo = config.BANNER_WPP && config.BANNER_WPP.ativo;
        if (secaoPai && !promosAtivas.length && !bannerAtivo) secaoPai.style.display = 'none';
        else if (secaoPai) secaoPai.style.display = '';
        document.querySelectorAll('nav.nav-desktop a[href="#promocoes"], nav.nav-desktop a[href*="promocoes"]').forEach((a) => {
            a.style.display = (promosAtivas.length || bannerAtivo) ? '' : 'none';
        });

        if (promosAtivas.length === 0) {
            // Optional: Hide section if no promos? Or show message.
            // For now, let's just leave empty or maybe hide the parent container if desired.
            // const container = document.getElementById('promocoes');
            // if(container) container.style.display = 'none';
        } else {
            const htmlPromos = promosAtivas.map(item => criarItemProdutoHTML(item)).join('');
            secaoPromocoes.innerHTML = htmlPromos;
        }
    }

    // --- LOGICA DE BUSCA E FILTRO ---
    const inputBusca = document.getElementById('input-busca');
    const btnsFiltro = document.querySelectorAll('.btn-filtro');
    let filtroAtivo = 'todos';

    let searchTimeout;
    if (inputBusca) {
        inputBusca.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                renderizarCardapio(filtroAtivo, e.target.value);
            }, 300);
        });
    }

    btnsFiltro.forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelector('.btn-filtro.ativo')?.classList.remove('ativo');
            btn.classList.add('ativo');
            filtroAtivo = btn.dataset.categoria;
            const termo = inputBusca ? inputBusca.value : '';
            renderizarCardapio(filtroAtivo, termo);

            // Auto-Scroll para a Lista (UX Delight)
            const targetSection = document.getElementById('cardapio');
            if (targetSection) {
                targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // --- LOGIN ADMIN ---
    const btnLoginAdmin = document.getElementById('btn-login-admin');
    const modalLogin = document.getElementById('modal-login');
    const btnFecharLogin = document.getElementById('btn-fechar-login');
    const btnEntrarAdmin = document.getElementById('btn-entrar-admin');
    const inputLoginUsuario = document.getElementById('login-usuario');
    const inputLoginSenha = document.getElementById('login-senha');
    const divLoginErro = document.getElementById('login-erro');

    function aplicarTextosLoginSimplicidade() {
        const sim = (window.APP_CONFIG && APP_CONFIG.SIMPLICIDADE) || {};
        const titulo = document.getElementById('login-titulo-modal');
        const dica = document.getElementById('login-dica-modal');
        const btn = document.getElementById('btn-entrar-admin');
        if (titulo && sim.login_titulo) titulo.textContent = sim.login_titulo;
        if (dica && sim.login_dica) dica.textContent = sim.login_dica;
        if (btn && sim.login_btn) btn.textContent = sim.login_btn;
        if (divLoginErro && sim.login_erro) divLoginErro.dataset.mensagemPadrao = sim.login_erro;
    }

    document.addEventListener('pedidozap-store', aplicarTextosLoginSimplicidade);
    document.addEventListener('pedidozap-store', function () {
        if (typeof aplicarAjudaCardapioCliente === 'function') aplicarAjudaCardapioCliente();
    });
    if (window.APP_CONFIG) aplicarTextosLoginSimplicidade();
    if (typeof aplicarAjudaCardapioCliente === 'function') aplicarAjudaCardapioCliente();

    if (btnLoginAdmin) {
        btnLoginAdmin.addEventListener('click', (e) => {
            e.preventDefault();
            modalLogin.classList.remove('escondido');
            setTimeout(() => {
                if (inputLoginUsuario && !inputLoginUsuario.value.trim()) inputLoginUsuario.focus();
                else if (inputLoginSenha) inputLoginSenha.focus();
            }, 60);
        });
    }

    if (btnFecharLogin) {
        btnFecharLogin.addEventListener('click', () => {
            modalLogin.classList.add('escondido');
            divLoginErro.style.display = 'none';
        });
    }

    const formLoginAdmin = document.getElementById('form-login-admin');
    if (formLoginAdmin) {
        formLoginAdmin.addEventListener('submit', (e) => {
            e.preventDefault();
            realizarLogin();
        });
    } else if (btnEntrarAdmin) {
        btnEntrarAdmin.addEventListener('click', realizarLogin);
    }

    if (inputLoginUsuario) {
        inputLoginUsuario.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            if (inputLoginSenha && !inputLoginSenha.value.trim()) {
                inputLoginSenha.focus();
            } else {
                realizarLogin();
            }
        });
    }

    if (inputLoginSenha) {
        inputLoginSenha.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            realizarLogin();
        });
    }

    async function realizarLogin() {
        if (!inputLoginUsuario || !inputLoginSenha) return;
        if (btnEntrarAdmin && btnEntrarAdmin.disabled) return;

        const usuario = inputLoginUsuario.value.trim();
        const senha = inputLoginSenha.value.trim();

        if (!usuario || !senha) {
            divLoginErro.style.display = 'block';
            divLoginErro.textContent = 'Preencha todos os campos.';
            return;
        }

        btnEntrarAdmin.disabled = true;
        btnEntrarAdmin.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Entrando...';

        try {
            const loginUrl = (typeof TENANT !== 'undefined') ? TENANT.apiUrl('login.php') : 'login.php';
            const response = await fetch(loginUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user: usuario,
                    pass: senha,
                    tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default'
                })
            });

            if (!response.ok && response.status !== 401 && response.status !== 429) {
                throw new Error("Erro no servidor");
            }

            const result = await response.json();

            if (result.success) {
                sessionStorage.setItem('admin_logado', 'true');
                sessionStorage.setItem('admin_user', result.user || usuario);
                sessionStorage.setItem('admin_pass', result.token || senha);
                sessionStorage.setItem('admin_role', result.role || 'lojista');
                sessionStorage.setItem('admin_nome', result.nome || result.user || usuario);
                sessionStorage.setItem('admin_funcao', result.funcao || '');
                try {
                    sessionStorage.setItem('admin_permissoes', JSON.stringify(result.permissoes || {}));
                } catch (e) { /* ignore */ }
                if (result.role === 'master') {
                    sessionStorage.setItem('admin_master', '1');
                    window.location.href = 'superadmin.html';
                    return;
                }
                sessionStorage.removeItem('admin_master');
                window.location.href = (typeof TENANT !== 'undefined') ? TENANT.apiUrl('admin.html') : 'admin.html';
            } else {
                // Erro
                divLoginErro.style.display = 'block';
                const msgErro = (divLoginErro && divLoginErro.dataset.mensagemPadrao) || 'Usuário ou senha incorretos.';
                divLoginErro.textContent = result.message || msgErro;
                modalLogin.classList.add('shake-animation');
                setTimeout(() => modalLogin.classList.remove('shake-animation'), 500);
            }
        } catch (error) {
            console.error("Erro no login:", error);
            divLoginErro.style.display = 'block';
            divLoginErro.textContent = 'Erro de conexão com o servidor.';
        } finally {
            btnEntrarAdmin.disabled = false;
            const sim = (window.APP_CONFIG && APP_CONFIG.SIMPLICIDADE) || {};
            btnEntrarAdmin.textContent = sim.login_btn || 'Entrar no painel';
        }
    }

    // --- INICIALIZAÇÃO ---
    garantirBlocosFrete();
    if (window.PedidoZapHydrate) PedidoZapHydrate.apply(config, cardapio);
    renderizarPromocoes(); renderizarDestaques(); renderizarCardapio(); atualizarCarrinho();

    (function iniciarExperienciaSemAtrito() {
        const pausaBanner = document.getElementById('pz-loja-pausada-banner');
        const pausaAteMs = Number((config && config.PAUSADA_ATE) || 0) * 1000;
        if (pausaBanner && pausaAteMs > Date.now()) {
            const hVolta = new Date(pausaAteMs).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            pausaBanner.textContent = '⏸️ Esta loja pausou novos pedidos temporariamente (alta demanda) e volta por volta das ' + hVolta + '.';
            pausaBanner.style.display = 'block';
        }

        const tempoEl = document.getElementById('pz-tempo-vitrine');
        const freteEl = document.getElementById('pz-frete-vitrine');
        const cepInput = document.getElementById('pz-cep-vitrine');
        const cepBtn = document.getElementById('pz-btn-cep-vitrine');
        if (tempoEl) {
            tempoEl.textContent = (config && config.TEMPO_ESPERA) ? config.TEMPO_ESPERA : '30–45 min';
        }
        const atualizarBannerFreteAntes = () => {
            if (!freteEl) return;
            const info = calcularFreteAtual();
            if (info && info.rotulo) {
                freteEl.textContent = Number(info.valor || 0) === 0
                    ? 'Grátis' + (info.detalhe ? ' (' + info.detalhe + ')' : '')
                    : formatarMoeda(info.valor) + (info.detalhe ? ' · ' + info.detalhe : '');
            }
        };
        try {
            const rapido = JSON.parse(localStorage.getItem('pz_checkout_perfil_rapido') || 'null');
            if (rapido && rapido.cep && cepInput && !cepInput.value) cepInput.value = rapido.cep;
            if (rapido && rapido.cidade) {
                enderecoCliente = Object.assign({}, enderecoCliente, rapido);
                calcularFreteAtual();
            }
        } catch (e) { }
        atualizarBannerFreteAntes();

        if (cepBtn && cepInput) {
            cepBtn.addEventListener('click', async () => {
                const cepLimpo = cepInput.value.replace(/\D/g, '');
                if (cepLimpo.length !== 8) {
                    showToast('Digite um CEP com 8 números para calcular entrega.', 'info');
                    return;
                }
                cepBtn.disabled = true;
                try {
                    const rCep = await fetch('https://viacep.com.br/ws/' + cepLimpo + '/json/');
                    const dCep = await rCep.json();
                    if (!dCep || dCep.erro) {
                        showToast('CEP não encontrado.', 'erro');
                        return;
                    }
                    enderecoCliente.cep = cepLimpo;
                    enderecoCliente.rua = dCep.logradouro || '';
                    enderecoCliente.bairro = dCep.bairro || '';
                    enderecoCliente.cidade = dCep.localidade || '';
                    const modoF = (config.FRETE && config.FRETE.modo) || 'gratis';
                    if (modoF === 'distancia' && window.PedidoZapFrete && config.ENDERECO && config.ENDERECO.lat != null) {
                        const dest = await window.PedidoZapFrete.geocode([dCep.logradouro, dCep.bairro, dCep.localidade, cepLimpo, 'Brasil'].filter(Boolean).join(', '));
                        enderecoCliente.distanciaKm = window.PedidoZapFrete.haversineKm(Number(config.ENDERECO.lat), Number(config.ENDERECO.lng), dest.lat, dest.lng);
                    }
                    calcularFreteAtual();
                    atualizarBannerFreteAntes();
                    const elCepModal = document.getElementById('input-cep');
                    if (elCepModal && !elCepModal.value) elCepModal.value = cepLimpo;
                    showToast('Taxa de entrega calculada!', 'sucesso');
                } catch (err) {
                    showToast('Não foi possível calcular o frete deste CEP agora.', 'erro');
                } finally {
                    cepBtn.disabled = false;
                }
            });
        }

        const btnRapidoModal = document.getElementById('pz-btn-checkout-rapido');
        if (btnRapidoModal && btnConfirmarEndereco) {
            btnRapidoModal.addEventListener('click', () => btnConfirmarEndereco.click());
        }

        const celModal = document.getElementById('input-celular');
        if (celModal) {
            celModal.addEventListener('blur', async () => {
                const digits = celModal.value.replace(/\D/g, '');
                if (digits.length < 10 || canalAtendente) return;
                const ruaEl = document.getElementById('input-rua');
                if (ruaEl && ruaEl.value.trim().length >= 3) return;
                try {
                    const tokComp = localStorage.getItem('pz_comprador_token') || '';
                    const resp = await apiFetch('comprador_api.php?action=vincular_rapido', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            telefone: digits,
                            token: tokComp,
                            tenant: (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : 'default'
                        })
                    });
                    const d = await resp.json();
                    if (d && d.success && d.encontrado && d.perfil) {
                        const fill = (id, v) => { const e = document.getElementById(id); if (e && !e.value.trim() && v) e.value = v; };
                        fill('input-nome', d.perfil.nome);
                        if (d.perfil.endereco) {
                            fill('input-cep', d.perfil.endereco.cep);
                            fill('input-rua', d.perfil.endereco.rua);
                            fill('input-numero', d.perfil.endereco.numero);
                            fill('input-complemento', d.perfil.endereco.complemento);
                            fill('input-bairro', d.perfil.endereco.bairro);
                            fill('input-cidade', d.perfil.endereco.cidade);
                        }
                        showToast('WhatsApp reconhecido! Dados preenchidos automaticamente.', 'sucesso');
                    } else if (d && d.success && d.requer_login) {
                        showToast('WhatsApp já cadastrado! Entre na sua conta para preencher o endereço salvo.', 'info');
                    }
                } catch (e) { }
            });
        }

        const slugRep = (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : 'default';
        const bannerRep = document.getElementById('pz-banner-repetir-pedido');
        const btnRep = document.getElementById('pz-btn-repetir-pedido');
        try {
            const ult = JSON.parse(localStorage.getItem('pz_ultimo_pedido_' + slugRep) || 'null');
            if (bannerRep && btnRep && ult && Array.isArray(ult.itens) && ult.itens.length > 0) {
                const tit = document.getElementById('pz-rep-titulo');
                const resEl = document.getElementById('pz-rep-resumo');
                if (tit) tit.textContent = 'Pedir novamente seu último combo — ' + formatarMoeda(ult.total || 0);
                if (resEl) resEl.textContent = ult.itens.map((i) => (i.quantidade || 1) + '× ' + (i.nome || 'Item')).join(' · ');
                bannerRep.style.display = 'flex';
                btnRep.addEventListener('click', () => {
                    carrinho = ult.itens.map((i, idx) => Object.assign({}, i, { id: Date.now() + idx }));
                    atualizarCarrinho();
                    if (btnFinalizarModal) {
                        btnFinalizarModal.click();
                    } else if (modalCarrinho) {
                        modalCarrinho.classList.remove('escondido');
                    }
                });
            }
        } catch (e) { }
    })();

    if (window.PedidoZapOfflineCache && typeof window.PedidoZapOfflineCache.lerCarrinho === 'function') {
        window.PedidoZapOfflineCache.lerCarrinho(cartStorageKey).then((snap) => {
            if (!snap || !Array.isArray(snap.carrinho)) return;
            if (carrinho.length === 0 && snap.carrinho.length > 0) {
                carrinho = snap.carrinho;
                if (snap.enderecoCliente && Object.keys(snap.enderecoCliente).length) enderecoCliente = snap.enderecoCliente;
                if (snap.cupomAplicado && snap.cupomAplicado.codigo) cupomAplicado = snap.cupomAplicado;
                if (snap.modoRecebimento) modoRecebimento = snap.modoRecebimento;
                atualizarCarrinho();
            }
        }).catch(() => { });

        const tentarReenviarPendente = () => {
            if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
            const slugPend = (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : 'default';
            window.PedidoZapOfflineCache.lerPedidoPendente(slugPend).then((pend) => {
                if (!pend || !pend.pedidoData) return;
                apiFetch('save_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(pend.pedidoData)
                }).then((r) => {
                    if (r && r.ok) {
                        window.PedidoZapOfflineCache.limparPedidoPendente(slugPend);
                        carrinho = [];
                        salvarCarrinho();
                        atualizarCarrinho();
                        showToast('Conexão voltou! Seu pedido salvo offline foi enviado para a loja.', 'sucesso');
                    }
                }).catch(() => { });
            }).catch(() => { });
        };
        tentarReenviarPendente();
        window.addEventListener('online', tentarReenviarPendente);
    }

    document.querySelectorAll('.nav-desktop a[href^="#"]').forEach((link) => {
        const id = (link.getAttribute('href') || '').replace('#', '');
        const alvo = document.getElementById(id);
        if (!alvo) return;
        link.style.display = window.getComputedStyle(alvo).display === 'none' ? 'none' : '';
    });

    if (typeof PedidoZapUI !== 'undefined' && PedidoZapUI.hidePreloader) {
        PedidoZapUI.hidePreloader();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciarVitrine);
} else {
    iniciarVitrine();
}