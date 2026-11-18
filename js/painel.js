let pedidos = [];
let entregadoresLista = [];
let lastDataHash = '';
const statusSyncPendentes = new Set();

function carregarEntregadoresPainel() {
    const user = sessionStorage.getItem('admin_user') || '';
    const pass = sessionStorage.getItem('admin_pass') || '';
    if (!user || !pass) return;
    fetch(apiUrl(`gestao_api.php?action=entregadores_listar&user=${encodeURIComponent(user)}&pass=${encodeURIComponent(pass)}${tenantQuery()}`))
        .then(r => r.json())
        .then(d => {
            if (d && d.success && Array.isArray(d.entregadores)) {
                entregadoresLista = d.entregadores.filter(e => e && e.ativo !== false);
                renderizarPainel();
            }
        })
        .catch(() => { /* ignore */ });
}
let idsConhecidos = null;
let audioCtx = null;
let tituloOriginal = document.title;
let piscaTitulo = null;
let timerAlarmeContinuo = null;
let turnoAtivo = false;
let ultimoUpdatedPainel = '';
let painelEstavaOffline = false;
let timerPollPainel = null;

function atualizarBadgeConexaoPainel(online) {
    const el = document.getElementById('painel-conn-badge');
    if (!el) return;
    el.className = 'conn-badge ' + (online ? 'on' : 'off');
    el.textContent = online ? '🟢 Online' : '🟠 Reconectando...';
}

function esc(valor) {
    return String(valor == null ? '' : valor).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function moeda(valor) {
    return 'R$ ' + (Number(valor) || 0).toFixed(2).replace('.', ',');
}

function somAtivo() {
    return localStorage.getItem('painel_som') !== '0';
}

function atualizarBotaoSom() {
    const btn = document.getElementById('btn-som');
    if (!btn) return;
    btn.innerHTML = somAtivo()
        ? '<i class="fa-solid fa-bell"></i> Som ligado'
        : '<i class="fa-solid fa-bell-slash"></i> Som desligado';
}

function alternarSom() {
    localStorage.setItem('painel_som', somAtivo() ? '0' : '1');
    prepararAudio();
    if (somAtivo()) tocarAlerta();
    atualizarBotaoSom();
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
}

function prepararAudio() {
    try {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') audioCtx.resume();
    } catch (e) {
        audioCtx = null;
    }
}

function tocarAlerta() {
    if (!somAtivo()) return;
    prepararAudio();
    if (!audioCtx) return;
    [0, 0.25, 0.5].forEach((atraso) => {
        const osc = audioCtx.createOscillator();
        const ganho = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        ganho.gain.setValueAtTime(0.0001, audioCtx.currentTime + atraso);
        ganho.gain.exponentialRampToValueAtTime(0.4, audioCtx.currentTime + atraso + 0.02);
        ganho.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + atraso + 0.2);
        osc.connect(ganho).connect(audioCtx.destination);
        osc.start(audioCtx.currentTime + atraso);
        osc.stop(audioCtx.currentTime + atraso + 0.22);
    });
}

function avisarPedidosNovos(novos) {
    if (!novos.length) return;
    tocarAlerta();

    if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
        const p = novos[0];
        new Notification(novos.length > 1 ? novos.length + ' pedidos novos' : 'Pedido novo #' + p.id, {
            body: (p.cliente || 'Cliente') + ' — ' + moeda(p.total),
            tag: 'pedido-novo'
        });
    }

    clearInterval(piscaTitulo);
    let alterna = false;
    piscaTitulo = setInterval(() => {
        document.title = alterna ? tituloOriginal : '(' + novos.length + ') Pedido novo!';
        alterna = !alterna;
    }, 1000);
}

function atualizarAlarmeContinuo() {
    const pendentesNovos = pedidos.filter((p) => (p.status || 'novo') === 'novo');
    const banner = document.getElementById('painel-alarme-banner');
    if (banner) {
        banner.hidden = pendentesNovos.length === 0;
        if (pendentesNovos.length > 0) {
            banner.textContent = '🔔 ' + pendentesNovos.length + ' pedido(s) novo(s) aguardando aceite! Clique em "Aceitar" para desligar o alarme.';
        }
    }
    if (pendentesNovos.length > 0 && somAtivo()) {
        if (!timerAlarmeContinuo) {
            timerAlarmeContinuo = setInterval(() => {
                const aindaNovos = pedidos.some((p) => (p.status || 'novo') === 'novo');
                if (!aindaNovos || !somAtivo()) {
                    clearInterval(timerAlarmeContinuo);
                    timerAlarmeContinuo = null;
                    return;
                }
                tocarAlerta();
            }, 4500);
        }
    } else if (timerAlarmeContinuo) {
        clearInterval(timerAlarmeContinuo);
        timerAlarmeContinuo = null;
    }
}

function montarLinkZapStatus(pedido, novoStatus) {
    const tel = String(pedido.celular || '').replace(/\D/g, '');
    if (tel.length < 10) return '';
    const telWa = tel.startsWith('55') ? tel : ('55' + tel);
    const nomeLoja = (window.APP_CONFIG && window.APP_CONFIG.NOME_LOJA) || 'Nossa Loja';
    const cod = pedido.codigo ? ('&c=' + encodeURIComponent(pedido.codigo)) : '';
    const rel = (typeof TENANT !== 'undefined' && TENANT.apiUrl)
        ? TENANT.apiUrl('rastreio.html?id=' + encodeURIComponent(pedido.id) + cod)
        : ('rastreio.html?id=' + encodeURIComponent(pedido.id) + cod);
    let abs = rel;
    try { abs = new URL(rel, window.location.href).href; } catch (e) {}

    const msgs = {
        'preparo': `Olá ${pedido.cliente || ''}! Seu pedido #${pedido.id} na *${nomeLoja}* foi aceito e está sendo preparado! 🔥\nAcompanhe ao vivo: ${abs}`,
        'em-rota': `🛵 *Pedido #${pedido.id} saiu para entrega!* (${nomeLoja})\nVeja onde o entregador está em tempo real:\n${abs}`,
        'concluido': `✅ Pedido #${pedido.id} entregue! Obrigado por pedir direto com a *${nomeLoja}*.\nSe puder, deixe suas estrelas aqui: ${abs}`
    };
    const txt = msgs[novoStatus] || (`Atualização do pedido #${pedido.id} (${nomeLoja}): ${abs}`);
    return 'https://wa.me/' + telWa + '?text=' + encodeURIComponent(txt);
}

function mostrarAtalhoZapStatus(pedido, novoStatus) {
    const chk = document.getElementById('chk-zap-auto-status');
    if (chk && !chk.checked) return;
    const url = montarLinkZapStatus(pedido, novoStatus);
    if (!url) return;
    let bar = document.getElementById('painel-zap-toast');
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'painel-zap-toast';
        bar.style.cssText = 'position:fixed;bottom:18px;left:50%;transform:translateX(-50%);z-index:99999;background:#0b192c;color:#fff;padding:12px 16px;border-radius:14px;display:flex;align-items:center;gap:12px;box-shadow:0 16px 32px rgba(0,0,0,0.3);border:2px solid #25D366;flex-wrap:wrap;max-width:92vw;';
        document.body.appendChild(bar);
    }
    const rotulo = novoStatus === 'em-rota' ? 'Saiu p/ entrega' : (novoStatus === 'preparo' ? 'Aceito/Preparo' : 'Entregue');
    bar.innerHTML = '<span><strong>Pedido #' + esc(pedido.id) + '</strong> (' + esc(rotulo) + ')</span>'
        + '<a href="' + esc(url) + '" target="_blank" rel="noopener" style="background:#25D366;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none;font-weight:800;"><i class="fa-brands fa-whatsapp"></i> Enviar status agora</a>'
        + '<button type="button" onclick="this.parentElement.style.display=\'none\'" style="background:transparent;border:none;color:#94a3b8;font-size:1.1rem;cursor:pointer;">&times;</button>';
    bar.style.display = 'flex';
    clearTimeout(bar._t);
    bar._t = setTimeout(() => { bar.style.display = 'none'; }, 12000);
}

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        clearInterval(piscaTitulo);
        document.title = tituloOriginal;
    }
});

function detectarNovos(lista) {
    const atuais = new Set(lista.map((p) => String(p.id)));
    if (idsConhecidos === null) {
        idsConhecidos = atuais;
        return;
    }
    const novos = lista.filter((p) => !idsConhecidos.has(String(p.id)) && (p.status || 'novo') === 'novo');
    idsConhecidos = atuais;
    avisarPedidosNovos(novos);
}

function ehHoje(pedido) {
    const bruto = pedido.timestamp || pedido.data;
    if (!bruto) return false;
    let d = new Date(bruto);
    if (isNaN(d.getTime())) d = new Date(String(bruto).replace(' ', 'T'));
    if (isNaN(d.getTime())) return false;
    return d.toDateString() === new Date().toDateString();
}

function renderizarResumoDia() {
    const alvo = document.getElementById('resumo-dia');
    if (!alvo) return;
    const hoje = pedidos.filter((p) => ehHoje(p) && p.status !== 'arquivado');
    const faturamento = hoje.reduce((acc, p) => acc + (Number(p.total) || 0), 0);
    const ticket = hoje.length ? faturamento / hoje.length : 0;
    const abertos = pedidos.filter((p) => ['novo', 'preparo', 'em-rota'].includes(p.status || 'novo')).length;
    alvo.innerHTML =
        '<div><span>Pedidos hoje</span><strong>' + hoje.length + '</strong></div>' +
        '<div><span>Vendido hoje</span><strong>' + moeda(faturamento) + '</strong></div>' +
        '<div><span>Ticket médio</span><strong>' + moeda(ticket) + '</strong></div>' +
        '<div><span>Em andamento</span><strong>' + abertos + '</strong></div>';
}

function painelStorage(nome) {
    return (typeof TENANT !== 'undefined') ? TENANT.storageKey(nome) : nome;
}

function textosComandaPainel() {
    return (window.APP_CONFIG && APP_CONFIG.COMANDA) || {};
}

function imprimirComanda(pedido, modo, forcar) {
    if (!window.ComandaPedidoZap) {
        const t = textosComandaPainel();
        alert(t.erro_popup || 'Carregue a página novamente (módulo de comanda não encontrado).');
        return;
    }
  const efetivoModo = modo === 'completa' ? 'completa' : 'cozinha';
    ComandaPedidoZap.imprimir(pedido, {
        modo: efetivoModo,
        forcar: !!forcar,
        comanda: textosComandaPainel(),
        nomeLoja: (window.APP_CONFIG && APP_CONFIG.NOME_LOJA) || ''
    });
}

function imprimirPainelAoPreparo() {
    const cfg = textosComandaPainel();
    if (cfg.painel_imprimir_auto === false) return false;
    return localStorage.getItem(painelStorage('painel_imprimir_cozinha')) !== '0';
}

async function marcarComandaImpressa(pedido) {
    if (!pedido || !pedido.comanda_id) return false;
    const user = sessionStorage.getItem('admin_user') || '';
    const pass = sessionStorage.getItem('admin_pass') || '';
    try {
        const res = await fetch(apiUrl('marcar_comanda_impressa.php?user=' + encodeURIComponent(user) + '&pass=' + encodeURIComponent(pass) + tenantQuery()), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: pedido.id,
                comanda_id: pedido.comanda_id,
                tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default'
            })
        });
        const data = await res.json();
        if (data.success) {
            pedido.comanda_impressa_id = pedido.comanda_id;
            return true;
        }
        if (data.comanda_id) pedido.comanda_id = data.comanda_id;
        return false;
    } catch (e) {
        console.warn('Não marcou comanda impressa', e);
        return false;
    }
}

async function tentarImprimirComandaPreparo(pedido, comandaId) {
    if (!imprimirPainelAoPreparo() || !pedido || !window.ComandaPedidoZap) return;
    if (comandaId) pedido.comanda_id = comandaId;
    if (!pedido.comanda_id) return;
    const chaveImp = painelStorage('comanda_imp_' + pedido.comanda_id);
    try {
        if (localStorage.getItem(chaveImp) === '1') return;
    } catch (e) {}
    if (!ComandaPedidoZap.precisaImprimir(pedido)) return;
    try { localStorage.setItem(chaveImp, '1'); } catch (e) {}
    const ok = ComandaPedidoZap.imprimir(pedido, {
        modo: 'cozinha',
        comanda: textosComandaPainel(),
        nomeLoja: (window.APP_CONFIG && APP_CONFIG.NOME_LOJA) || ''
    });
    if (ok) await marcarComandaImpressa(pedido);
}

async function copiarLinkCozinha(renovar) {
    const user = sessionStorage.getItem('admin_user') || '';
    const pass = sessionStorage.getItem('admin_pass') || '';
    if (renovar && !confirm('Gerar link novo? O link antigo da cozinha deixa de funcionar.')) return;
    try {
        const res = await fetch(apiUrl(`cozinha_link.php?user=${encodeURIComponent(user)}&pass=${encodeURIComponent(pass)}${renovar ? '&acao=renovar' : ''}${tenantQuery()}`));
        const data = await res.json();
        if (!data.success) {
            alert(data.message || 'Não foi possível gerar o link da cozinha.');
            return;
        }
        const relativo = apiUrl('cozinha.html?k=' + encodeURIComponent(data.chave));
        const link = new URL(relativo, window.location.href).href;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(link);
            alert('Link da cozinha copiado. Abra no tablet ou PC da cozinha e conecte à impressora:\n\n' + link);
        } else {
            prompt('Link da cozinha (abra no computador da cozinha):', link);
        }
    } catch (e) {
        alert('Sem conexão com o servidor.');
    }
}

function alternarImpressaoCozinha() {
    localStorage.setItem(painelStorage('painel_imprimir_cozinha'), imprimirPainelAoPreparo() ? '0' : '1');
    atualizarBotaoImpressaoCozinha();
}

function atualizarBotaoImpressaoCozinha() {
    const btn = document.getElementById('btn-imprimir-cozinha');
    if (!btn) return;
    const t = textosComandaPainel();
    btn.innerHTML = imprimirPainelAoPreparo()
        ? '<i class="fa-solid fa-print"></i> ' + (t.painel_imprimir_ligado || 'Imprimir ao mandar pra cozinha')
        : '<i class="fa-solid fa-print"></i> ' + (t.painel_imprimir_desligado || 'Só imprime na tela da cozinha');
}

async function copiarLinkEntregador(renovar) {
    const user = sessionStorage.getItem('admin_user') || '';
    const pass = sessionStorage.getItem('admin_pass') || '';
    if (renovar && !confirm('Gerar um link novo? O link antigo do entregador para de funcionar.')) return;
    try {
        const res = await fetch(apiUrl(`entregador_link.php?user=${encodeURIComponent(user)}&pass=${encodeURIComponent(pass)}${renovar ? '&acao=renovar' : ''}${tenantQuery()}`));
        const data = await res.json();
        if (!data.success) {
            alert(data.message || 'Não foi possível gerar o link.');
            return;
        }
        const relativo = apiUrl('motoboy.html?k=' + encodeURIComponent(data.chave));
        const link = new URL(relativo, window.location.href).href;
        const texto = 'Link do entregador (abra no celular e deixe salvo):\n' + link;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(link);
            alert('Link copiado. Envie para o seu entregador pelo WhatsApp.\n\n' + link);
        } else {
            prompt(texto, link);
        }
    } catch (e) {
        alert('Sem conexão com o servidor.');
    }
}

async function pedirLogin() {
    const usuario = prompt("Usuário:");
    const senha = usuario ? prompt("Senha:") : null;
    if (!usuario || !senha) return;
    try {
        const res = await fetch(apiUrl('login.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user: usuario, pass: senha, tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default' })
        });
        const data = await res.json();
        if (!data.success) {
            alert(data.message || 'Usuário ou senha incorretos.');
            return;
        }
        sessionStorage.setItem('admin_user', data.user || usuario);
        sessionStorage.setItem('admin_pass', data.token || senha);
        location.reload();
    } catch (e) {
        alert('Sem conexão com o servidor.');
    }
}

const FLUXO_STATUS = {
    novo: { anterior: null, proximo: 'preparo', label: 'Começar a preparar' },
    preparo: { anterior: 'novo', proximo: 'em-rota', label: 'Saiu para entrega' },
    'em-rota': { anterior: 'preparo', proximo: 'concluido', label: 'Já entreguei' },
    concluido: { anterior: 'em-rota', proximo: 'arquivado', label: 'Guardar pedido' },
    arquivado: { anterior: 'concluido', proximo: null, label: null }
};

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

function lojaTravada() {
    return !!(window.PedidoZapStore && PedidoZapStore.lojaBloqueada) && sessionStorage.getItem('admin_master') !== '1';
}

function carregarPedidos(forcarFull) {
    const user = sessionStorage.getItem('admin_user');
    const pass = sessionStorage.getItem('admin_pass');

    if (!user || !pass) {
        pedirLogin();
        return;
    }

    const sinceQs = (!forcarFull && ultimoUpdatedPainel) ? ('&since=' + encodeURIComponent(ultimoUpdatedPainel)) : '';
    fetch(apiUrl(`get_orders.php?delta=1&user=${encodeURIComponent(user)}&pass=${encodeURIComponent(pass)}${sinceQs}${tenantQuery()}`))
        .then(response => {
            if (response.status === 401) {
                sessionStorage.removeItem('admin_user');
                sessionStorage.removeItem('admin_pass');
                alert("Sessão expirada ou credenciais incorretas.");
                location.reload();
                return null;
            }
            if (response.status === 304) {
                return { success: true, changed: false };
            }
            return response.json();
        })
        .then(res => {
            if (!res) return;
            const voltouOnline = painelEstavaOffline;
            painelEstavaOffline = false;
            atualizarBadgeConexaoPainel(true);

            if (res.updated_at) ultimoUpdatedPainel = String(res.updated_at);
            if (res.changed === false) {
                const lu = document.getElementById('last-update');
                if (lu) lu.innerText = 'Sincronizado às ' + new Date().toLocaleTimeString();
                atualizarAlarmeContinuo();
                return;
            }
            const data = Array.isArray(res) ? res : (Array.isArray(res.orders) ? res.orders : null);
            if (!Array.isArray(data)) return;

            detectarNovos(data);
            const currentDataHash = JSON.stringify(data);
            if (currentDataHash === lastDataHash) {
                document.getElementById('last-update').innerText = 'Sincronizado às ' + new Date().toLocaleTimeString();
                atualizarAlarmeContinuo();
                return;
            }

            lastDataHash = currentDataHash;
            pedidos = data;
            renderizarPainel();
            if (voltouOnline && data.some((p) => (p.status || 'novo') === 'novo')) {
                tocarAlerta();
            }
            document.getElementById('last-update').innerText = 'Atualizado às ' + new Date().toLocaleTimeString();
        })
        .catch(err => {
            console.error("Erro ao carregar pedidos", err);
            painelEstavaOffline = true;
            atualizarBadgeConexaoPainel(false);
        });
}

function renderizarPainel() {
    let aviso = document.getElementById('aviso-loja-travada');
    if (lojaTravada()) {
        if (!aviso) {
            aviso = document.createElement('p');
            aviso.id = 'aviso-loja-travada';
            aviso.className = 'aviso-painel';
            aviso.textContent = 'A mensalidade venceu. Dá para ver os pedidos, mas não dá para avançar nem receber pedido novo até o pagamento.';
            const quadro = document.querySelector('.board');
            if (quadro && quadro.parentNode) quadro.parentNode.insertBefore(aviso, quadro);
        }
    } else if (aviso) {
        aviso.remove();
    }

    const cols = {
        'novo': document.querySelector('#col-novo .column-content'),
        'preparo': document.querySelector('#col-preparo .column-content'),
        'em-rota': document.querySelector('#col-em-rota .column-content'),
        'concluido': document.querySelector('#col-concluido .column-content'),
        'arquivado': document.querySelector('#col-arquivado .column-content')
    };

    Object.values(cols).forEach(col => { if (col) col.textContent = ''; });

    const fragments = {
        'novo': document.createDocumentFragment(),
        'preparo': document.createDocumentFragment(),
        'em-rota': document.createDocumentFragment(),
        'concluido': document.createDocumentFragment(),
        'arquivado': document.createDocumentFragment()
    };

    let counts = { novo: 0, preparo: 0, 'em-rota': 0, concluido: 0, arquivado: 0 };

    pedidos.forEach(pedido => {
        const status = pedido.status || 'novo';
        if (counts.hasOwnProperty(status)) {
            counts[status]++;
        }

        let itensHTML = '';
        if (pedido.itens && Array.isArray(pedido.itens)) {
            pedido.itens.forEach(item => {
                itensHTML += `<div>${esc(item.quantidade)}x ${esc(item.nome)}</div>`;
                (item.opcionais || []).forEach(op => {
                    const extra = op.preco > 0 ? ` (+R$ ${Number(op.preco).toFixed(2).replace('.', ',')})` : '';
                    itensHTML += `<div style="font-size:0.85em; color:#666; margin-left:10px;">+ ${esc(op.nome)}${extra}</div>`;
                });
            });
        }

        const card = document.createElement('div');
        card.className = 'card';
        card.draggable = true;
        const idSeguro = String(pedido.id).replace(/[^0-9A-Za-z_-]/g, '');
        card.id = 'pedido-' + idSeguro;
        card.dataset.status = status;
        card.ondragstart = drag;

        const dataRaw = pedido.created_at || pedido.timestamp || pedido.data || new Date().toISOString();
        let dataObj = new Date(dataRaw);
        if (isNaN(dataObj.getTime()) && typeof dataRaw === 'string') {
            dataObj = new Date(dataRaw.replace(' ', 'T'));
        }
        const dataFormatada = isNaN(dataObj.getTime()) ? "---" : dataObj.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        const minutosEspera = !isNaN(dataObj.getTime()) ? Math.max(0, Math.floor((Date.now() - dataObj.getTime()) / 60000)) : 0;
        const emAtrasoAceite = (status === 'novo' && minutosEspera >= 5);
        if (emAtrasoAceite) {
            card.classList.add('card-atraso-aceite');
        }
        const badgeAtraso = emAtrasoAceite
            ? `<div class="badge-atraso-aceite">${minutosEspera >= 8 ? '🚨 ATRASO CRÍTICO' : '⚠️ AGUARDANDO ACEITE'} (${minutosEspera} min)</div>`
            : '';

        card.addEventListener('touchstart', touchStart, { passive: false });
        card.addEventListener('touchmove', touchMove, { passive: false });
        card.addEventListener('touchend', touchEnd, { passive: false });

        const despachoManual = !!(window.APP_CONFIG && APP_CONFIG.LOGISTICA && APP_CONFIG.LOGISTICA.despacho_manual);
        const fluxo = FLUXO_STATUS[status] || FLUXO_STATUS.novo;
        const travada = lojaTravada();
        const btnAnterior = (!travada && fluxo.anterior)
            ? `<button type="button" class="btn-status btn-status-back" data-id="${idSeguro}" data-status="${fluxo.anterior}">Voltar</button>`
            : '';
        const btnProximo = (!travada && fluxo.proximo)
            ? `<button type="button" class="btn-status btn-status-fwd" data-id="${idSeguro}" data-status="${fluxo.proximo}" title="${fluxo.label}">${fluxo.label}</button>`
            : '';

        const codigoQs = pedido.codigo ? '&c=' + encodeURIComponent(pedido.codigo) : '';
        const rastreioLink = esc(typeof TENANT !== 'undefined'
            ? TENANT.apiUrl(`rastreio.html?id=${idSeguro}${codigoQs}`)
            : `rastreio.html?id=${idSeguro}${codigoQs}`);
        const motoboyLink = esc(typeof TENANT !== 'undefined'
            ? TENANT.apiUrl(`motoboy.html?id=${idSeguro}${codigoQs}`)
            : `motoboy.html?id=${idSeguro}${codigoQs}`);

        const conf = pedido.conferencia || {};
        const alertaValor = conf.divergente
            ? `<div class="alerta-valor"><strong>Confira o valor antes de aceitar.</strong> Pelo cardápio atual, o total seria ${moeda(conf.total_servidor)}.${(conf.problemas || []).length ? '<br>' + conf.problemas.map(esc).join('<br>') : ''}</div>`
            : '';

        card.innerHTML = `
            ${badgeAtraso}
            <div class="card-header">
                <span>#${esc(pedido.id)}</span>
                <span>${dataFormatada}</span>
            </div>
            <div class="card-title">${esc(pedido.cliente || 'Sem Nome')}${pedido.canal === 'atendente' ? ' <span style="font-size:0.75em;background:#0d9488;color:#fff;padding:2px 6px;border-radius:6px;">' + esc((window.APP_CONFIG && APP_CONFIG.ATENDIMENTO && APP_CONFIG.ATENDIMENTO.rotulo_badge_painel) || '') + '</span>' : ''}</div>
            <div style="font-size: 0.85em; color: #d97706; font-weight: bold; margin-bottom: 5px;">${esc(pedido.entrega || 'N/A')}</div>
            ${alertaValor}
            <div class="card-items">${itensHTML}</div>
            ${pedido.observacao ? `<div style="background: #fee2e2; color: #b91c1c; padding: 5px; border-radius: 4px; font-size: 0.85em; margin-bottom: 10px;"><strong>Obs:</strong> ${esc(pedido.observacao)}</div>` : ''}
            ${despachoManual ? `<div style="display:flex; flex-direction:column; gap:6px; margin:8px 0;">
                <button type="button" class="btn-copiar-pedido" data-id="${idSeguro}">Copiar dados do pedido</button>
                <input class="input-rastreio" data-id="${idSeguro}" value="${esc(pedido.rastreio_externo || '')}" placeholder="Cole o link de acompanhamento (https://...)" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:6px;">
            </div>` : ''}
            <div class="card-quick-actions">
                ${btnAnterior}
                ${btnProximo}
            </div>
            ${(pedido.estoque_baixado || pedido.entregador_nome) ? `<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
                ${pedido.estoque_baixado ? `<span class="pz-badge-estoque"><i class="fa-solid fa-cubes"></i> Estoque baixado</span>` : ''}
                ${pedido.entregador_nome ? `<span class="pz-badge-entregador"><i class="fa-solid fa-motorcycle"></i> ${esc(pedido.entregador_nome)}</span>` : ''}
            </div>` : ''}
            ${(!travada && entregadoresLista.length && (status === 'preparo' || status === 'em-rota')) ? `<div style="margin:6px 0;">
                <select class="select-entregador-card" data-id="${idSeguro}" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;font-size:0.84rem;">
                    <option value="">Selecionar entregador...</option>
                    ${entregadoresLista.map(e => `<option value="${esc(e.id)}" data-nome="${esc(e.nome)}" ${String(pedido.entregador_id || '') === String(e.id) ? 'selected' : ''}>${esc(e.nome)} (${esc(e.veiculo || 'Moto')})</option>`).join('')}
                </select>
            </div>` : ''}
            <div class="card-footer">
                <strong style="color: #059669;">${moeda(pedido.total)}</strong>
                ${pedido.frete != null ? `<span style="display:block;font-size:0.75em;color:#6b7280;">Entrega ${Number(pedido.frete).toFixed(2).replace('.', ',')}</span>` : ''}
                <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                    <button type="button" class="btn-imprimir" data-id="${idSeguro}" title="Imprimir comanda"><i class="fa-solid fa-print"></i></button>
                    <button type="button" class="btn-rawbt" data-id="${idSeguro}" title="Imprimir via RawBT (Android Bluetooth 58mm/80mm)" style="background:#0f172a;color:#fff;border:none;padding:6px 8px;border-radius:6px;cursor:pointer;font-size:0.78em;font-weight:700;">BT</button>
                    ${(!travada && status !== 'arquivado') ? `<button type="button" class="btn-cancelar-pedido" data-id="${idSeguro}" title="Cancelar pedido e estornar estoque/financeiro" style="background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;padding:5px 8px;border-radius:4px;cursor:pointer;font-size:0.82em;"><i class="fa-solid fa-ban"></i></button>` : ''}
                    ${status === 'em-rota' ? `
                        <a href="${rastreioLink}" target="_blank" class="btn-track" title="Ver no Mapa" style="background: #8b5cf6; color: white; padding: 5px 10px; border-radius: 4px; font-size: 0.85em; text-decoration: none;"><i class="fa-solid fa-location-dot"></i></a>
                    ` : ''}
                    <a href="${motoboyLink}" target="_blank" title="Área do Entregador" style="background: #1f2937; color: white; padding: 5px 10px; border-radius: 4px; font-size: 0.85em; text-decoration: none;"><i class="fa-solid fa-motorcycle"></i></a>
                    <a href="${esc(montarLinkZapStatus(pedido, status) || ('https://wa.me/55' + (pedido.celular || '').replace(/\D/g, '')))}" target="_blank" class="btn-wpp" title="Notificar status no WhatsApp">WhatsApp</a>
                </div>
            </div>
        `;

        if (fragments[status]) {
            fragments[status].appendChild(card);
        }
    });

    Object.keys(fragments).forEach(key => {
        if (cols[key]) cols[key].appendChild(fragments[key]);
    });

    Object.keys(counts).forEach(s => {
        const countEl = document.querySelector(`#col-${s} .count`);
        if (countEl) countEl.innerText = counts[s];
    });

    renderizarResumoDia();
    atualizarAlarmeContinuo();

    document.querySelectorAll('.btn-imprimir').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const ped = pedidos.find((p) => String(p.id) === String(btn.dataset.id));
            if (!ped) return;
            const modo = (ped.status || 'novo') === 'preparo' ? 'cozinha' : 'completa';
            imprimirComanda(ped, modo, true);
        });
    });

    document.querySelectorAll('.btn-rawbt').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const ped = pedidos.find((p) => String(p.id) === String(btn.dataset.id));
            if (!ped || !window.ComandaPedidoZap || !window.ComandaPedidoZap.imprimirRawBT) return;
            window.ComandaPedidoZap.imprimirRawBT(ped, {
                nomeLoja: (window.APP_CONFIG && window.APP_CONFIG.NOME_LOJA) || '',
                comanda: window.APP_CONFIG && window.APP_CONFIG.COMANDA
            });
        });
    });

    document.querySelectorAll('.btn-copiar-pedido').forEach((btn) => {
        btn.addEventListener('click', () => {
            const ped = pedidos.find((p) => String(p.id) === String(btn.dataset.id));
            if (!ped) return;
            const end = ped.endereco || {};
            const linhas = [
                ped.cliente, ped.celular,
                [end.rua, end.numero, end.bairro, end.cidade, end.cep].filter(Boolean).join(', '),
                end.complemento, end.referencia,
                ...(ped.itens || []).map((item) => (item.quantidade || 1) + 'x ' + item.nome)
            ].filter(Boolean);
            const texto = linhas.join('\n');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(texto).then(() => alert('Dados do pedido copiados.'));
            } else {
                prompt('Copie os dados do pedido:', texto);
            }
        });
    });
    document.querySelectorAll('.input-rastreio').forEach((input) => {
        input.addEventListener('change', () => {
            const ped = pedidos.find((p) => String(p.id) === String(input.dataset.id));
            if (!ped) return;
            ped.rastreio_externo = input.value.trim();
            atualizarStatusBackend(ped.id, ped.status, null, ped.rastreio_externo);
        });
    });

    document.querySelectorAll('.btn-status').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = btn.dataset.id;
            const novoStatus = btn.dataset.status;
            if (id && novoStatus) {
                const pedAntes = pedidos.find(p => String(p.id) === String(id));
                const statusAnt = pedAntes ? pedAntes.status : null;
                const ehVoltar = btn.classList.contains('btn-status-back');
                aplicarStatusLocal(id, novoStatus);
                atualizarStatusBackend(id, novoStatus, statusAnt, null, ehVoltar ? { forcar_rollback: true } : null);
            }
        });
    });

    document.querySelectorAll('.select-entregador-card').forEach(sel => {
        sel.addEventListener('change', (e) => {
            e.stopPropagation();
            const id = sel.dataset.id;
            const opt = sel.options[sel.selectedIndex];
            const ped = pedidos.find(p => String(p.id) === String(id));
            if (!ped || !opt) return;
            ped.entregador_id = opt.value;
            ped.entregador_nome = opt.dataset.nome || '';
            atualizarStatusBackend(id, ped.status || 'preparo', null, null, {
                entregador_id: ped.entregador_id,
                entregador_nome: ped.entregador_nome
            });
        });
    });

    document.querySelectorAll('.btn-cancelar-pedido').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = btn.dataset.id;
            const motivo = prompt('Cancelar pedido #' + id + ' e estornar estoque/financeiro?\nInforme o motivo (opcional):', 'Cancelado pelo operador');
            if (motivo === null) return;
            aplicarStatusLocal(id, 'arquivado');
            atualizarStatusBackend(id, 'cancelado', null, null, { cancelar: true, motivo_cancelamento: motivo });
        });
    });
}

function aplicarStatusLocal(id, novoStatus) {
    const ped = pedidos.find(p => String(p.id) === String(id));
    if (ped) {
        ped.status = novoStatus;
    }
    renderizarPainel();
}

function esvaziarLixeira() {
    const user = sessionStorage.getItem('admin_user');
    const pass = sessionStorage.getItem('admin_pass');

    if (!confirm("Tem certeza que deseja apagar permanentemente todos os pedidos na lixeira?")) return;

    fetch(apiUrl(`delete_archived_orders.php?user=${encodeURIComponent(user)}&pass=${encodeURIComponent(pass)}${tenantQuery()}`), {
        method: 'POST'
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                pedidos = pedidos.filter(p => p.status !== 'arquivado');
                renderizarPainel();
                alert("Lixeira esvaziada!");
            } else {
                alert("Erro ao esvaziar: " + data.message);
            }
        })
        .catch(err => {
            console.error("Erro ao deletar", err);
            alert("Erro de conexão ao tentar esvaziar a lixeira.");
        });
}

function allowDrop(ev) {
    ev.preventDefault();
    const col = ev.target.closest('.column-content');
    if (col) col.classList.add('drop-target');
}

function drag(ev) {
    const card = ev.currentTarget && ev.currentTarget.classList.contains('card')
        ? ev.currentTarget
        : ev.target.closest('.card');
    if (!card) return;
    ev.dataTransfer.setData("text", card.id);
    card.classList.add('dragging');
}

function drop(ev) {
    ev.preventDefault();
    document.querySelectorAll('.column-content.drop-target').forEach(el => el.classList.remove('drop-target'));

    const data = ev.dataTransfer.getData("text");
    const card = document.getElementById(data);
    if (!card) return;

    const columnContent = ev.target.closest('.column-content');
    if (!columnContent) return;

    const novoStatus = columnContent.parentElement.getAttribute('data-status');
    const pedidoId = data.replace('pedido-', '');
    const statusAnterior = card.dataset.status;

    if (statusAnterior === novoStatus) {
        card.classList.remove('dragging');
        return;
    }

    columnContent.appendChild(card);
    card.classList.remove('dragging');
    card.dataset.status = novoStatus;

    aplicarStatusLocal(pedidoId, novoStatus);
    atualizarStatusBackend(pedidoId, novoStatus, statusAnterior);
}

document.addEventListener('dragend', (ev) => {
    if (ev.target.classList) ev.target.classList.remove('dragging');
    document.querySelectorAll('.column-content.drop-target').forEach(el => el.classList.remove('drop-target'));
});

let touchCard = null;
let touchOffsetX = 0;
let touchOffsetY = 0;
let isMoving = false;

function touchStart(e) {
    if (e.target.closest('button, a, input')) return;
    const target = e.target.closest('.card');
    if (!target) return;

    touchCard = target;
    touchCard.classList.add('dragging');
    const touch = e.touches[0];
    const rect = touchCard.getBoundingClientRect();
    touchOffsetX = touch.clientX - rect.left;
    touchOffsetY = touch.clientY - rect.top;

    touchCard.style.position = 'fixed';
    touchCard.style.width = rect.width + 'px';
    touchCard.style.height = rect.height + 'px';
    touchCard.style.zIndex = '9999';
    touchCard.style.pointerEvents = 'none';
    updatePosition(touch.clientX, touch.clientY);
}

function updatePosition(x, y) {
    if (!touchCard) return;
    const posX = x - touchOffsetX;
    const posY = y - touchOffsetY;
    touchCard.style.transform = `translate3d(${posX}px, ${posY}px, 0) rotate(3deg) scale(1.05)`;
}

function touchMove(e) {
    if (!touchCard) return;
    e.preventDefault();
    const touch = e.touches[0];
    if (!isMoving) {
        isMoving = true;
        requestAnimationFrame(() => {
            if (touchCard) updatePosition(touch.clientX, touch.clientY);
            isMoving = false;
        });
    }
}

function touchEnd(e) {
    if (!touchCard) return;

    const finalTouch = e.changedTouches[0];
    const x = finalTouch.clientX;
    const y = finalTouch.clientY;
    const statusAnterior = touchCard.dataset.status;

    touchCard.classList.remove('dragging');
    touchCard.style.position = '';
    touchCard.style.width = '';
    touchCard.style.height = '';
    touchCard.style.zIndex = '';
    touchCard.style.pointerEvents = 'auto';
    touchCard.style.transform = '';

    const elementUnder = document.elementFromPoint(x, y);
    const columnContent = elementUnder ? elementUnder.closest('.column-content') : null;

    if (columnContent) {
        const novoStatus = columnContent.parentElement.getAttribute('data-status');
        const pedidoId = touchCard.id.replace('pedido-', '');
        columnContent.appendChild(touchCard);
        touchCard.dataset.status = novoStatus;
        if (statusAnterior !== novoStatus) {
            aplicarStatusLocal(pedidoId, novoStatus);
            atualizarStatusBackend(pedidoId, novoStatus, statusAnterior);
        }
    }

    touchCard = null;
    isMoving = false;
}

function atualizarStatusBackend(id, status, statusAnteriorParaRollback, rastreioExterno, extras) {
    const chave = id + ':' + status + ':' + (rastreioExterno || '') + ':' + (extras && extras.entregador_id ? extras.entregador_id : '');
    if (statusSyncPendentes.has(chave)) return;
    statusSyncPendentes.add(chave);

    const user = sessionStorage.getItem('admin_user');
    const pass = sessionStorage.getItem('admin_pass');

    const body = Object.assign({
        id,
        status,
        tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default'
    }, extras || {});
    if (typeof rastreioExterno === 'string') body.rastreio_externo = rastreioExterno;

    fetch(apiUrl(`update_order_status.php?user=${encodeURIComponent(user)}&pass=${encodeURIComponent(pass)}${tenantQuery()}`), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    })
        .then(res => res.json())
        .then(data => {
            statusSyncPendentes.delete(chave);
            if (!data.success) {
                alert(data.message || "Erro ao sincronizar status com o servidor.");
                if (statusAnteriorParaRollback) {
                    aplicarStatusLocal(id, statusAnteriorParaRollback);
                }
                carregarPedidos();
            } else {
                const ped = pedidos.find(p => String(p.id) === String(id));
                if (ped) {
                    ped.status = status === 'cancelado' ? 'arquivado' : status;
                    if (status === 'cancelado') ped.cancelado = true;
                    if (data.comanda_id) ped.comanda_id = data.comanda_id;
                    if (data.comanda_impressa_id != null) ped.comanda_impressa_id = data.comanda_impressa_id;
                    if (data.estoque_baixado != null) ped.estoque_baixado = data.estoque_baixado;
                    if (data.entregador_nome != null) ped.entregador_nome = data.entregador_nome;
                    renderizarPainel();
                    if (status === 'preparo') tentarImprimirComandaPreparo(ped, data.comanda_id);
                    if (['preparo', 'em-rota', 'concluido'].includes(ped.status)) {
                        mostrarAtalhoZapStatus(ped, ped.status);
                    }
                    if (window.PedidoZapCelulas && window.PedidoZapCelulas.emitir) {
                        window.PedidoZapCelulas.emitir('pedido_status', {
                            tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default',
                            id: ped.id,
                            codigo: ped.codigo || '',
                            status: ped.status
                        });
                    }
                }
            }
        })
        .catch(err => {
            statusSyncPendentes.delete(chave);
            console.error("Erro API", err);
            if (statusAnteriorParaRollback) {
                aplicarStatusLocal(id, statusAnteriorParaRollback);
            }
            carregarPedidos();
        });
}

function aplicarTextosSimplicidadePainel() {
    const sim = (window.APP_CONFIG && APP_CONFIG.SIMPLICIDADE) || {};
    const ajuda = (typeof configAjuda === 'function') ? configAjuda() : ((window.APP_CONFIG && APP_CONFIG.AJUDA) || {});
    const painel = ajuda.painel || {};
    const simIntro = (sim.painel_instrucao || '').trim();
    const intro = document.getElementById('painel-intro-grande');
    if (intro) {
        intro.textContent = simIntro || painel.intro || intro.textContent;
    }
    const dicaCols = document.getElementById('painel-dica-colunas');
    if (dicaCols && painel.rodape_colunas) dicaCols.textContent = painel.rodape_colunas;
    const lixeira = document.getElementById('dica-lixeira');
    if (lixeira && painel.lixeira) lixeira.textContent = painel.lixeira;

    const mapa = mapaFerramentas(painel.ferramentas);
    const ids = ['som', 'atendimento', 'cozinha', 'impressao', 'entregador', 'trocar_entregador', 'atualizar', 'voltar'];
    ids.forEach((id) => {
        preencherExplicacaoFerramenta(
            id,
            mapa,
            document.getElementById('ajuda-titulo-' + id),
            document.getElementById('ajuda-texto-' + id)
        );
    });

    (painel.colunas || []).forEach((col) => {
        const el = document.getElementById('dica-col-' + col.id);
        if (el) el.textContent = col.texto || '';
        const head = document.querySelector('#col-' + col.id + ' .column-header');
        if (head && col.titulo) {
            const countEl = head.querySelector('.count');
            const countHtml = countEl ? countEl.outerHTML : '';
            head.innerHTML = col.titulo + ' ' + countHtml;
        }
    });

    const linkAdmin = document.getElementById('link-voltar-admin-painel');
    if (linkAdmin && typeof TENANT !== 'undefined' && TENANT.apiUrl) {
        linkAdmin.href = TENANT.apiUrl('admin.html');
    }
    const linkAtd = document.getElementById('link-atendimento-painel');
    if (linkAtd && typeof TENANT !== 'undefined' && TENANT.apiUrl) {
        linkAtd.href = TENANT.apiUrl('atendimento.html');
    }
}

document.addEventListener('pedidozap-store', function () {
    aplicarTextosSimplicidadePainel();
    if (typeof renderizarPainel === 'function') renderizarPainel();
});

(function exigirLoginPainel() {
    if (!sessionStorage.getItem('admin_logado')) {
        const dest = (typeof TENANT !== 'undefined' && TENANT.apiUrl) ? TENANT.apiUrl('admin.html') : 'admin.html';
        window.location.replace(dest);
        return;
    }
})();

document.addEventListener('click', prepararAudio, { once: true });
document.addEventListener('DOMContentLoaded', () => {
    if (!sessionStorage.getItem('admin_logado')) return;
    aplicarTextosSimplicidadePainel();
    atualizarBotaoSom();
    atualizarBotaoImpressaoCozinha();
});
atualizarBotaoSom();
atualizarBotaoImpressaoCozinha();

if (sessionStorage.getItem('admin_logado')) {
    carregarEntregadoresPainel();
    carregarPedidos();
}
window.addEventListener('pz:agente-mutacao', () => {
    if (sessionStorage.getItem('admin_logado')) carregarPedidos();
});
if (sessionStorage.getItem('admin_logado')) {
    function agendarPollPainel() {
        clearTimeout(timerPollPainel);
        const sseOk = window.AcheIAkiRealtime && window.AcheIAkiRealtime.estaConectado && window.AcheIAkiRealtime.estaConectado();
        const base = sseOk ? 18000 : 7000;
        const jitter = sseOk ? 6000 : 4000;
        const espera = base + Math.floor(Math.random() * jitter);
        timerPollPainel = setTimeout(() => {
            if (!document.hidden) carregarPedidos(false);
            agendarPollPainel();
        }, espera);
    }
    agendarPollPainel();
    window.addEventListener('online', () => {
        prepararAudio();
        atualizarBadgeConexaoPainel(true);
        carregarPedidos(true);
    });
    window.addEventListener('offline', () => atualizarBadgeConexaoPainel(false));

    const btnTurno = document.getElementById('btn-iniciar-turno');
    if (btnTurno) {
        btnTurno.addEventListener('click', () => {
            localStorage.setItem('painel_som', '1');
            prepararAudio();
            tocarAlerta();
            atualizarBotaoSom();
            turnoAtivo = true;
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
            btnTurno.style.background = '#15803d';
            btnTurno.innerHTML = '<i class="fa-solid fa-check-double"></i> Turno Ativo — Alarme Contínuo Liberado';
            atualizarAlarmeContinuo();
        });
    }

    const btnPausar = document.getElementById('btn-pausar-loja');
    const atualizarBtnPausa = () => {
        if (!btnPausar) return;
        const ate = Number((window.APP_CONFIG && window.APP_CONFIG.PAUSADA_ATE) || 0) * 1000;
        if (ate > Date.now()) {
            const h = new Date(ate).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            btnPausar.style.background = '#16a34a';
            btnPausar.style.borderColor = '#15803d';
            btnPausar.style.color = '#fff';
            btnPausar.innerHTML = '<i class="fa-solid fa-play"></i> Pausada até ' + h + ' · Reabrir agora';
        } else {
            btnPausar.style.background = '#fef2f2';
            btnPausar.style.borderColor = '#dc2626';
            btnPausar.style.color = '#991b1b';
            btnPausar.innerHTML = '<i class="fa-solid fa-pause"></i> Pausar loja 30 min';
        }
    };
    document.addEventListener('pedidozap-store', atualizarBtnPausa);
    atualizarBtnPausa();

    if (btnPausar) {
        btnPausar.addEventListener('click', async () => {
            const ate = Number((window.APP_CONFIG && window.APP_CONFIG.PAUSADA_ATE) || 0) * 1000;
            const querPausar = ate <= Date.now();
            const user = sessionStorage.getItem('admin_user') || '';
            const pass = sessionStorage.getItem('admin_pass') || '';
            btnPausar.disabled = true;
            try {
                const r = await fetch(apiUrl('gestao_api.php?action=pausar_loja'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ auth: { user, pass }, pausar: querPausar, minutos: 30 })
                });
                const d = await r.json();
                if (d && d.success) {
                    if (!window.APP_CONFIG) window.APP_CONFIG = {};
                    window.APP_CONFIG.PAUSADA_ATE = d.pausada_ate || 0;
                    atualizarBtnPausa();
                }
            } catch (e) {
                alert('Erro ao alterar pausa da loja.');
            } finally {
                btnPausar.disabled = false;
            }
        });
    }
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
