let pedidosCozinha = [];
let chaveCozinha = '';
let textosComanda = {};
let audioCtx = null;
const impressoesPendentes = new Set();
let hashAnterior = '';

function storageCozinha(nome) {
    return (typeof TENANT !== 'undefined') ? TENANT.storageKey(nome) : nome;
}

function somAtivo() {
    return localStorage.getItem(storageCozinha('cozinha_som')) !== '0';
}

function autoImprimirAtivo() {
    return localStorage.getItem(storageCozinha('cozinha_auto_imprimir')) !== '0';
}

function prepararAudio() {
    try {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') audioCtx.resume();
    } catch (e) {
        audioCtx = null;
    }
}

function tocarNovaComanda() {
    if (!somAtivo()) return;
    prepararAudio();
    if (!audioCtx) return;
    [0, 0.18, 0.36].forEach((atraso) => {
        const osc = audioCtx.createOscillator();
        const ganho = audioCtx.createGain();
        osc.type = 'square';
        osc.frequency.value = 660;
        ganho.gain.setValueAtTime(0.0001, audioCtx.currentTime + atraso);
        ganho.gain.exponentialRampToValueAtTime(0.35, audioCtx.currentTime + atraso + 0.02);
        ganho.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + atraso + 0.25);
        osc.connect(ganho).connect(audioCtx.destination);
        osc.start(audioCtx.currentTime + atraso);
        osc.stop(audioCtx.currentTime + atraso + 0.28);
    });
}

function tenantQuery() {
    if (typeof TENANT !== 'undefined' && TENANT.slug && TENANT.slug !== 'default') {
        return '&tenant=' + encodeURIComponent(TENANT.slug);
    }
    return '';
}

function apiUrl(path) {
    if (typeof TENANT !== 'undefined' && TENANT.apiUrl) {
        return TENANT.apiUrl(path);
    }
    return path;
}

function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function tempoDesde(bruto) {
    if (!bruto) return '';
    let d = new Date(bruto);
    if (isNaN(d.getTime())) d = new Date(String(bruto).replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    const min = Math.max(0, Math.floor((Date.now() - d.getTime()) / 60000));
    if (min < 1) return 'agora';
    if (min === 1) return '1 min';
    return min + ' min';
}

function aplicarTextosUi(cfg) {
    textosComanda = cfg || {};
    const ajC = (typeof configAjuda === 'function' ? configAjuda().cozinha : null) || (window.APP_CONFIG && APP_CONFIG.AJUDA && APP_CONFIG.AJUDA.cozinha) || {};
    const intro = document.getElementById('cozinha-intro');
    if (intro && ajC.intro) intro.textContent = ajC.intro;
    const mapa = typeof mapaFerramentas === 'function' ? mapaFerramentas(ajC.ferramentas) : {};
    ['impressao', 'som', 'atualizar'].forEach((id) => {
        if (typeof preencherExplicacaoFerramenta === 'function') {
            preencherExplicacaoFerramenta(id, mapa,
                document.getElementById('ajuda-cozinha-titulo-' + id),
                document.getElementById('ajuda-cozinha-texto-' + id));
        }
    });
    if (typeof desenharCozinhaFichasAjuda === 'function') desenharCozinhaFichasAjuda();
    const dica = document.getElementById('cozinha-dica');
    if (dica && textosComanda.cozinha_instrucao) dica.textContent = textosComanda.cozinha_instrucao;
    const vazio = document.getElementById('cozinha-vazia');
    if (vazio && textosComanda.cozinha_vazio) vazio.textContent = textosComanda.cozinha_vazio;
    const btnImp = document.getElementById('btn-auto-imprimir');
    if (btnImp && autoImprimirAtivo()) {
        btnImp.innerHTML = '<i class="fa-solid fa-print"></i> Impressão automática ligada';
    }
}

async function marcarImpressa(pedido) {
    const comandaId = pedido.comanda_id;
    if (!comandaId) return false;
    try {
        const res = await fetch(apiUrl('marcar_comanda_impressa.php?k=' + encodeURIComponent(chaveCozinha) + tenantQuery()), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: pedido.id,
                comanda_id: comandaId,
                k: chaveCozinha,
                tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default'
            })
        });
        const data = await res.json();
        if (data.success) {
            pedido.comanda_impressa_id = comandaId;
            return true;
        }
        if (data.comanda_id) {
            pedido.comanda_id = data.comanda_id;
        }
        return false;
    } catch (e) {
        console.warn('Falha ao marcar comanda impressa', e);
        return false;
    }
}

async function imprimirPedido(pedido, opcoes) {
    if (!window.ComandaPedidoZap) return;
    const forcar = opcoes && opcoes.forcar;
    const silencioso = opcoes && opcoes.silencioso;
    if (!forcar && !ComandaPedidoZap.precisaImprimir(pedido)) return;
    if (!pedido.comanda_id) return;

    const chave = pedido.id + ':' + pedido.comanda_id;
    if (impressoesPendentes.has(chave)) return;
    impressoesPendentes.add(chave);

    const ok = ComandaPedidoZap.imprimir(pedido, {
        modo: 'cozinha',
        forcar: !!forcar,
        nomeLoja: document.getElementById('nome-loja')?.dataset.nome || '',
        comanda: Object.assign({}, window.APP_CONFIG && APP_CONFIG.COMANDA, textosComanda)
    });

    if (ok) {
        await marcarImpressa(pedido);
        if (!silencioso) tocarNovaComanda();
    }
    impressoesPendentes.delete(chave);
}

async function avancarStatus(id) {
    const btn = document.querySelector('.btn-pronto[data-id="' + id + '"]');
    if (btn) btn.disabled = true;
    try {
        const res = await fetch(apiUrl('update_order_status.php?k=' + encodeURIComponent(chaveCozinha) + tenantQuery()), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id,
                status: 'em-rota',
                k: chaveCozinha,
                tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default'
            })
        });
        const data = await res.json();
        if (!data.success) alert(data.message || 'Não foi possível atualizar o pedido.');
        else {
            if (window.PedidoZapCelulas && window.PedidoZapCelulas.emitir) {
                window.PedidoZapCelulas.emitir('pedido_status', {
                    tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default',
                    id,
                    status: 'em-rota'
                });
            }
            carregarPedidos();
        }
    } catch (e) {
        alert('Sem conexão com o servidor.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

function textoBotao(tipo) {
    if (tipo === 'imprimir') return textosComanda.cozinha_btn_imprimir || 'Reimprimir comanda';
    return textosComanda.cozinha_btn_pronto || 'Pronto — sai para entrega';
}

function renderizar() {
    const grid = document.getElementById('lista-cozinha');
    const vazio = document.getElementById('cozinha-vazia');
    if (!grid) return;

    const emPreparo = pedidosCozinha.filter((p) => (p.status || 'preparo') === 'preparo');
    document.getElementById('contagem-cozinha').textContent = String(emPreparo.length);

    if (!emPreparo.length) {
        grid.innerHTML = '';
        if (vazio) vazio.hidden = false;
        return;
    }
    if (vazio) vazio.hidden = true;

    const badgeBalcao = (textosComanda.texto_canal_balcao || textosComanda.titulo_via_baladao || '');

    grid.innerHTML = emPreparo.map((p) => {
        const itens = (p.itens || []).map((item) => {
            const ops = (item.opcionais || []).map((op) =>
                '<li>+ ' + esc(op.nome) + '</li>'
            ).join('');
            const tam = item.tamanho ? ' <span class="tam">(' + esc(item.tamanho) + ')</span>' : '';
            return '<li><strong>' + esc(item.quantidade || 1) + '×</strong> ' + esc(item.nome) + tam
                + (ops ? '<ul class="ops">' + ops + '</ul>' : '') + '</li>';
        }).join('');

        const desde = tempoDesde(p.cozinha_desde || p.timestamp);
        const obs = p.observacao
            ? '<div class="obs-card"><strong>' + esc(textosComanda.rotulo_obs || 'OBS') + ':</strong> ' + esc(p.observacao) + '</div>'
            : '';
        const selo = p.canal === 'atendente' && badgeBalcao
            ? ' <span class="selo-balcao">' + esc(badgeBalcao) + '</span>' : '';

        return '<article class="ficha" data-id="' + esc(p.id) + '">'
            + '<header><span class="num">#' + esc(p.id) + selo + '</span>'
            + '<span class="tempo">' + esc(desde) + '</span></header>'
            + '<p class="cliente">' + esc(p.cliente || '') + (p.entrega ? ' · ' + esc(p.entrega) : '') + '</p>'
            + '<ul class="itens">' + (itens || '<li>—</li>') + '</ul>'
            + obs
            + '<footer>'
            + '<button type="button" class="btn-imprimir" data-id="' + esc(p.id) + '"><i class="fa-solid fa-print"></i> ' + esc(textoBotao('imprimir')) + '</button>'
            + '<button type="button" class="btn-pronto" data-id="' + esc(p.id) + '">' + esc(textoBotao('pronto')) + '</button>'
            + '</footer></article>';
    }).join('');

    grid.querySelectorAll('.btn-imprimir').forEach((btn) => {
        btn.addEventListener('click', () => {
            const ped = pedidosCozinha.find((p) => String(p.id) === String(btn.dataset.id));
            if (ped) imprimirPedido(ped, { forcar: true, silencioso: true });
        });
    });
    grid.querySelectorAll('.btn-pronto').forEach((btn) => {
        btn.addEventListener('click', () => avancarStatus(btn.dataset.id));
    });
}

function processarNovidades(lista, nomeLoja, cfgComanda) {
    if (cfgComanda) {
        if (window.APP_CONFIG) APP_CONFIG.COMANDA = Object.assign({}, APP_CONFIG.COMANDA || {}, cfgComanda);
        aplicarTextosUi(cfgComanda);
    }
    if (nomeLoja) {
        const titulo = document.getElementById('nome-loja');
        if (titulo) {
            titulo.textContent = nomeLoja;
            titulo.dataset.nome = nomeLoja;
        }
        document.title = (textosComanda.titulo_via_cozinha || 'Cozinha') + ' — ' + nomeLoja;
    }

    const preparo = lista.filter((p) => p.status === 'preparo');

    preparo.forEach((ped) => {
        const antigo = pedidosCozinha.find((p) => String(p.id) === String(ped.id));
        const entrou = !antigo || antigo.status !== 'preparo';
        const novaComanda = antigo && antigo.comanda_id !== ped.comanda_id;
        if (autoImprimirAtivo() && ped.comanda_id && (entrou || novaComanda) && ComandaPedidoZap.precisaImprimir(ped)) {
            imprimirPedido(ped, { silencioso: false });
        } else if (entrou || novaComanda) {
            tocarNovaComanda();
        }
    });

    pedidosCozinha = lista;
    hashAnterior = JSON.stringify(lista.map((p) => [p.id, p.status, p.comanda_id, p.comanda_impressa_id]));
    renderizar();
}

function carregarPedidos() {
    if (!chaveCozinha) {
        const err = document.getElementById('erro-cozinha');
        if (err) {
            err.hidden = false;
            err.textContent = 'Link inválido. Gere o link da cozinha no painel de pedidos.';
        }
        return;
    }

    const incluirNovos = localStorage.getItem(storageCozinha('cozinha_mostrar_novos')) === '1' ? '&incluir_novos=1' : '';
    fetch(apiUrl('get_cozinha_pedidos.php?k=' + encodeURIComponent(chaveCozinha) + incluirNovos + '&v=' + Date.now() + tenantQuery()))
        .then((r) => {
            if (r.status === 401) throw new Error('auth');
            return r.json();
        })
        .then((data) => {
            if (!data.success) throw new Error(data.message || 'erro');
            const hash = JSON.stringify((data.pedidos || []).map((p) => [p.id, p.status, p.comanda_id, p.comanda_impressa_id]));
            if (hash !== hashAnterior) {
                processarNovidades(data.pedidos || [], data.nome_loja, data.comanda);
            }
            const stamp = document.getElementById('atualizado');
            if (stamp) stamp.textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');
        })
        .catch((e) => {
            const err = document.getElementById('erro-cozinha');
            if (!err) return;
            if (e.message === 'auth') {
                err.hidden = false;
                err.textContent = 'Este link expirou ou é inválido. Gere um link novo no painel de pedidos.';
            }
        });
}

function alternarSom() {
    localStorage.setItem(storageCozinha('cozinha_som'), somAtivo() ? '0' : '1');
    atualizarControles();
    if (somAtivo()) tocarNovaComanda();
}

function alternarAutoImprimir() {
    localStorage.setItem(storageCozinha('cozinha_auto_imprimir'), autoImprimirAtivo() ? '0' : '1');
    atualizarControles();
}

function atualizarControles() {
    const btnSom = document.getElementById('btn-som-cozinha');
    if (btnSom) {
        btnSom.innerHTML = somAtivo()
            ? '<i class="fa-solid fa-bell"></i> Som ligado'
            : '<i class="fa-solid fa-bell-slash"></i> Som desligado';
    }
    const btnImp = document.getElementById('btn-auto-imprimir');
    if (btnImp) {
        btnImp.innerHTML = autoImprimirAtivo()
            ? '<i class="fa-solid fa-print"></i> Impressão automática ligada'
            : '<i class="fa-solid fa-print"></i> Impressão automática desligada';
    }
}

function iniciar() {
    const params = new URLSearchParams(window.location.search);
    chaveCozinha = params.get('k') || sessionStorage.getItem(storageCozinha('cozinha_k')) || '';
    if (params.get('k')) sessionStorage.setItem(storageCozinha('cozinha_k'), chaveCozinha);

    if (window.APP_CONFIG && APP_CONFIG.COMANDA) aplicarTextosUi(APP_CONFIG.COMANDA);

    const linkPainel = document.getElementById('cozinha-link-painel');
    const linkLoja = document.getElementById('cozinha-link-loja');
    const slugAtual = (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : 'default';
    if (linkPainel && typeof TENANT !== 'undefined' && TENANT.apiUrl) {
        linkPainel.href = TENANT.apiUrl('painel.html');
    }
    if (linkLoja) {
        linkLoja.href = (typeof PedidoZapCelulas !== 'undefined')
            ? PedidoZapCelulas.urlComTenant('index.html', slugAtual)
            : ((typeof TENANT !== 'undefined' && TENANT.apiUrl) ? TENANT.apiUrl('index.html') : 'index.html');
    }

    atualizarControles();
    document.getElementById('btn-som-cozinha')?.addEventListener('click', alternarSom);
    document.getElementById('btn-auto-imprimir')?.addEventListener('click', alternarAutoImprimir);
    document.getElementById('btn-atualizar')?.addEventListener('click', carregarPedidos);
    const btnTurnoCoz = document.getElementById('btn-iniciar-turno-cozinha');
    if (btnTurnoCoz) {
        btnTurnoCoz.addEventListener('click', () => {
            prepararAudio();
            localStorage.setItem(storageCozinha('cozinha_som'), '1');
            atualizarControles();
            tocarNovaComanda();
            btnTurnoCoz.style.background = '#15803d';
            btnTurnoCoz.innerHTML = '<i class="fa-solid fa-check-double"></i> Turno da Cozinha Ativo — Som Liberado!';
        });
    }

    document.addEventListener('click', prepararAudio, { once: true });
    carregarPedidos();
    let tickCozinha = 0;
    setInterval(() => {
        tickCozinha++;
        const sseOk = window.AcheIAkiRealtime && window.AcheIAkiRealtime.estaConectado && window.AcheIAkiRealtime.estaConectado();
        if (sseOk && tickCozinha % 3 !== 0) return; // SSE entrega instantaneamente; backup leve a cada ~21s
        carregarPedidos();
    }, 7000);
    if (window.PedidoZapCelulas && window.PedidoZapCelulas.escutar) {
        window.PedidoZapCelulas.escutar((ev) => {
            if (!ev || !ev.tipo) return;
            const slugAtual = (typeof TENANT !== 'undefined') ? TENANT.slug : 'default';
            if (ev.dados && ev.dados.tenant && ev.dados.tenant !== slugAtual) return;
            if (['pedido_criado', 'pedido_status'].includes(ev.tipo)) {
                carregarPedidos();
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', iniciar);
