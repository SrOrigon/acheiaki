// --- ADMIN LOGIC ---

function adminPreviewKey(name) {
    return (typeof TENANT !== 'undefined') ? TENANT.storageKey(name) : name;
}

async function obterTokenNovo(user, senha) {
    try {
        const url = (typeof TENANT !== 'undefined' && TENANT.apiUrl) ? TENANT.apiUrl('login.php') : 'login.php';
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user, pass: senha, tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default' })
        });
        const data = await res.json();
        return (data && data.success && data.token) ? data.token : senha;
    } catch (e) {
        return senha;
    }
}

let cobrancaIniciada = false;

async function chamarAssinatura(corpo) {
    const url = (typeof TENANT !== 'undefined' && TENANT.apiUrl) ? TENANT.apiUrl('assinatura.php') : 'assinatura.php';
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({
            auth: { user: sessionStorage.getItem('admin_user') || '', pass: sessionStorage.getItem('admin_pass') || '' },
            tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default'
        }, corpo))
    });
    return res.json();
}

function textoAssinatura(assinatura) {
    const rotulos = { ativo: 'Mensalidade em dia', bloqueado: 'Loja bloqueada', pendente: 'Pagamento pendente', teste: 'Teste grátis' };
    const extra = assinatura.motivo === 'vencido' ? ' O prazo acabou e o cardápio público está pausado.' : '';
    return 'Plano ' + (assinatura.plano || 'básico') + (assinatura.vencimento ? ' até ' + assinatura.vencimento.split('-').reverse().join('/') : '')
        + ' — ' + (rotulos[assinatura.status] || assinatura.status) + extra
        + (assinatura.assinatura_status === 'authorized' ? ' Renovação automática ativa.' : '');
}

function mostrarFaixaCobranca(assinatura) {
    let faixa = document.getElementById('faixa-cobranca');
    const dias = assinatura.vencimento
        ? Math.ceil((new Date(assinatura.vencimento + 'T23:59:59') - new Date()) / 86400000)
        : null;
    const automatica = assinatura.assinatura_status === 'authorized';
    let texto = '';
    if (assinatura.status === 'bloqueado') {
        texto = 'Sua loja está pausada para novos pedidos. Regularize a mensalidade para voltar a vender.';
    } else if (!automatica && (assinatura.status === 'teste' || assinatura.em_teste) && dias !== null) {
        texto = dias > 0 ? 'Teste grátis: faltam ' + dias + ' dia(s). Assine agora e não perca nenhum pedido.' : 'Seu teste grátis termina hoje.';
    } else if (!automatica && dias !== null && dias <= 5) {
        texto = 'Sua mensalidade vence em ' + Math.max(dias, 0) + ' dia(s).';
    }
    if (!texto) {
        if (faixa) faixa.remove();
        return;
    }
    if (!faixa) {
        faixa = document.createElement('div');
        faixa.id = 'faixa-cobranca';
        faixa.style.cssText = 'position:sticky;top:0;z-index:9999;padding:12px 16px;text-align:center;font-weight:700;color:#fff;cursor:pointer;';
        faixa.addEventListener('click', () => {
            const aba = document.querySelector('[data-tab="conta"]');
            if (aba) aba.click();
            const cartao = document.querySelector('[data-painel="conta"]');
            if (cartao) cartao.scrollIntoView({ behavior: 'smooth' });
        });
        document.body.prepend(faixa);
    }
    faixa.style.background = assinatura.status === 'bloqueado' ? '#b91c1c' : '#d97706';
    faixa.textContent = texto + (assinatura.pagamento_online ? ' Clique para pagar.' : '');
}

function iniciarCobrancaOnline(assinatura) {
    if (!assinatura) return;
    mostrarFaixaCobranca(assinatura);
    const bloco = document.getElementById('conta-pagamento');
    if (!bloco || cobrancaIniciada) return;
    cobrancaIniciada = true;

    const resumo = document.getElementById('conta-resumo');
    const botaoAssinar = document.getElementById('btn-assinar');
    const botaoSync = document.getElementById('btn-sincronizar-assinatura');
    const campoEmail = document.getElementById('conta-email');

    const aplicar = (dados) => {
        if (!dados) return;
        if (window.PedidoZapStore) PedidoZapStore.assinatura = dados;
        if (resumo) resumo.textContent = textoAssinatura(dados);
        mostrarFaixaCobranca(dados);
        if (botaoAssinar) {
            botaoAssinar.textContent = dados.assinatura_status === 'authorized' ? 'Trocar cartão / refazer assinatura' : 'Assinar com Mercado Pago';
        }
    };

    if (assinatura.pagamento_online) {
        bloco.style.display = 'block';
        const valor = Number(assinatura.mensalidade_valor || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        document.getElementById('conta-valor').textContent = valor + ' por mês';
    }
    aplicar(assinatura);

    botaoAssinar.addEventListener('click', async () => {
        const email = campoEmail.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert('Informe um e-mail válido para a cobrança.');
            campoEmail.focus();
            return;
        }
        botaoAssinar.disabled = true;
        botaoAssinar.textContent = 'Abrindo o Mercado Pago...';
        try {
            const data = await chamarAssinatura({ acao: 'criar', email });
            if (data.success && data.url) {
                window.location.href = data.url;
                return;
            }
            alert(data.message || 'Não foi possível iniciar o pagamento.');
        } catch (e) {
            alert('Sem conexão com o servidor.');
        }
        botaoAssinar.disabled = false;
        aplicar(PedidoZapStore && PedidoZapStore.assinatura);
    });

    const sincronizar = async (preapprovalId, silencioso) => {
        botaoSync.disabled = true;
        try {
            const data = await chamarAssinatura({ acao: 'sincronizar', preapproval_id: preapprovalId || '' });
            if (data.email && !campoEmail.value) campoEmail.value = data.email;
            if (data.assinatura) aplicar(data.assinatura);
            if (!silencioso) {
                if (data.pago) alert('Pagamento confirmado! Sua loja está liberada.');
                else if (data.status === 'authorized' || data.status === 'pending') alert('Assinatura registrada. Assim que o Mercado Pago aprovar a cobrança, a loja é liberada automaticamente.');
                else alert(data.message || 'Ainda não encontramos pagamento aprovado.');
            }
        } catch (e) {
            if (!silencioso) alert('Sem conexão com o servidor.');
        }
        botaoSync.disabled = false;
    };
    botaoSync.addEventListener('click', () => sincronizar('', false));

    const params = new URLSearchParams(window.location.search);
    if (params.get('assinatura') === 'retorno') {
        sincronizar(params.get('preapproval_id') || '', false);
        params.delete('assinatura');
        params.delete('preapproval_id');
        const qs = params.toString();
        history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
    } else if (assinatura.pagamento_online) {
        sincronizar('', true);
    }
}

function adminPageUrl(page) {
    if (typeof TENANT !== 'undefined' && TENANT.apiUrl) {
        return TENANT.apiUrl(page);
    }
    return page;
}

async function iniciarAdmin() {

    // 1. Verificação de Segurança (Client-Side)
    if (!sessionStorage.getItem('admin_logado')) {
        window.location.href = (typeof TENANT !== 'undefined' && TENANT.slug !== 'default')
            ? adminPageUrl('index.html')
            : 'cadastro.html?modo=entrar';
        return;
    }

    try {
        if (typeof PedidoZapStore !== 'undefined') {
            await PedidoZapStore.load({ fallbackStatic: true, allowEmptyTenant: true });
        }
    } catch (err) {
        console.error('Admin: falha ao carregar dados da loja', err);
        alert('Não foi possível carregar os dados deste estabelecimento. Verifique o slug (?tenant=) e o servidor PHP.');
        return;
    }

    // --- ELEMENTS ---
    const listaEditor = document.getElementById('lista-editor');
    const chkAltaDemanda = document.getElementById('chk-alta-demanda');
    const btnBaixarConfig = document.getElementById('btn-baixar-config');
    const btnGerarArquivo = document.getElementById('btn-gerar-arquivo');
    const btnLogout = document.getElementById('btn-logout');

    // --- DATA ---
    const dadosSalvosPreview = localStorage.getItem(adminPreviewKey('dados_cardapio_preview'));
    const configSalvaPreview = localStorage.getItem(adminPreviewKey('config_preview'));

    const dadosIniciais = (typeof CARDAPIO_DADOS !== 'undefined') ? CARDAPIO_DADOS : { hamburgueres: [], porcoes: [], bebidas: [], acai: [], combos: [], promocoes: [] };
    const configIniciais = (typeof APP_CONFIG !== 'undefined') ? APP_CONFIG : {};

    let dadosLocais = dadosSalvosPreview ? JSON.parse(dadosSalvosPreview) : JSON.parse(JSON.stringify(dadosIniciais));
    let configLocais = configSalvaPreview ? JSON.parse(configSalvaPreview) : JSON.parse(JSON.stringify(configIniciais));

    // --- INIT ---
    const inputTempoEspera = document.getElementById('input-tempo-espera');
    const inputWhatsApp = document.getElementById('input-whatsapp');
    const inputNomeLoja = document.getElementById('input-nome-loja');
    const inputTenantSlug = document.getElementById('input-tenant-slug');
    const tenantBadge = document.getElementById('tenant-badge');
    const linkVerSite = document.getElementById('link-ver-site');
    const linkKanban = document.getElementById('link-kanban');
    const inputAdminUser = document.getElementById('input-admin-user');
    const inputAdminPass = document.getElementById('input-admin-pass');

    const tenantAtual = (typeof TENANT !== 'undefined') ? TENANT.slug : 'default';
    configLocais.TENANT_SLUG = tenantAtual;

    if (tenantBadge) {
        tenantBadge.textContent = tenantAtual === 'default' ? 'default (loja principal)' : tenantAtual;
    }
    if (inputTenantSlug) {
        inputTenantSlug.value = tenantAtual;
        inputTenantSlug.readOnly = true;
    }
    if (linkVerSite) linkVerSite.href = tenantAtual === 'default' ? 'index.html?tenant=default' : adminPageUrl('index.html');
    if (linkKanban) linkKanban.href = adminPageUrl('painel.html');
    const linkCozinha = document.getElementById('link-cozinha');
    if (linkCozinha) linkCozinha.href = adminPageUrl('cozinha.html');
    const linkMotoboy = document.getElementById('link-motoboy');
    if (linkMotoboy) linkMotoboy.href = adminPageUrl('motoboy.html');

    // --- INIT CONFIG FIELDS ---
    if (chkAltaDemanda) {
        chkAltaDemanda.checked = configLocais.ALTA_DEMANDA || false;
        chkAltaDemanda.addEventListener('change', (e) => {
            configLocais.ALTA_DEMANDA = e.target.checked;
            salvarAlteracoesNoNavegador();
        });
    }

    if (inputTempoEspera) {
        inputTempoEspera.value = configLocais.TEMPO_ESPERA || "60-90 min";
        inputTempoEspera.addEventListener('input', (e) => {
            configLocais.TEMPO_ESPERA = e.target.value;
            salvarAlteracoesNoNavegador();
        });
    }

    if (inputNomeLoja) {
        inputNomeLoja.value = configLocais.NOME_LOJA || '';
        inputNomeLoja.addEventListener('input', (e) => {
            configLocais.NOME_LOJA = e.target.value.trim();
            salvarAlteracoesNoNavegador();
        });
    }

    if (inputWhatsApp) {
        inputWhatsApp.value = configLocais.WHATSAPP_NUMBER || "";
        inputWhatsApp.addEventListener('input', (e) => {
            // Trava: Apenas números
            let val = e.target.value.replace(/\D/g, '');
            if (val.length > 13) val = val.slice(0, 13);
            e.target.value = val;
            configLocais.WHATSAPP_NUMBER = val;
            salvarAlteracoesNoNavegador();
        });
    }

    // --- DIAS FECHADO E HORÁRIOS ---
    if (!configLocais.HORARIOS) configLocais.HORARIOS = {};
    if (!configLocais.HORARIOS.FECHADO) configLocais.HORARIOS.FECHADO = [];
    if (!configLocais.HORARIOS.SEMANA) configLocais.HORARIOS.SEMANA = { inicio: "18:00", fim: "23:30" };
    if (!configLocais.HORARIOS.FIM_DE_SEMANA) configLocais.HORARIOS.FIM_DE_SEMANA = { inicio: "18:00", fim: "00:00" };

    const inputHsIni = document.getElementById('input-horario-semana-inicio');
    const inputHsFim = document.getElementById('input-horario-semana-fim');
    const inputHfdsIni = document.getElementById('input-horario-fds-inicio');
    const inputHfdsFim = document.getElementById('input-horario-fds-fim');

    function formatTimeVal(val, defaultVal) {
        if (val === undefined || val === null) return defaultVal;
        if (typeof val === 'number') return (val < 10 ? '0' + val : val) + ':00';
        return val;
    }

    if (inputHsIni) {
        inputHsIni.value = formatTimeVal(configLocais.HORARIOS.SEMANA.inicio, "18:00");
        inputHsIni.addEventListener('change', (e) => {
            configLocais.HORARIOS.SEMANA.inicio = e.target.value;
            salvarAlteracoesNoNavegador();
        });
    }
    if (inputHsFim) {
        inputHsFim.value = formatTimeVal(configLocais.HORARIOS.SEMANA.fim, "23:30");
        inputHsFim.addEventListener('change', (e) => {
            configLocais.HORARIOS.SEMANA.fim = e.target.value;
            salvarAlteracoesNoNavegador();
        });
    }
    if (inputHfdsIni) {
        inputHfdsIni.value = formatTimeVal(configLocais.HORARIOS.FIM_DE_SEMANA.inicio, "18:00");
        inputHfdsIni.addEventListener('change', (e) => {
            configLocais.HORARIOS.FIM_DE_SEMANA.inicio = e.target.value;
            salvarAlteracoesNoNavegador();
        });
    }
    if (inputHfdsFim) {
        inputHfdsFim.value = formatTimeVal(configLocais.HORARIOS.FIM_DE_SEMANA.fim, "00:00");
        inputHfdsFim.addEventListener('change', (e) => {
            configLocais.HORARIOS.FIM_DE_SEMANA.fim = e.target.value;
            salvarAlteracoesNoNavegador();
        });
    }

    const chksDiasFechado = document.querySelectorAll('.chk-dia-fechado');
    if (chksDiasFechado.length > 0) {
        chksDiasFechado.forEach(chk => {
            const dia = parseInt(chk.value);
            // Marca se já estiver na config
            if (configLocais.HORARIOS.FECHADO.includes(dia)) {
                chk.checked = true;
                chk.parentElement.style.background = '#fee2e2'; // Vermelho claro p/ fechado
            }

            chk.addEventListener('change', (e) => {
                const diaVal = parseInt(e.target.value);
                if (e.target.checked) {
                    if (!configLocais.HORARIOS.FECHADO.includes(diaVal)) {
                        configLocais.HORARIOS.FECHADO.push(diaVal);
                    }
                    e.target.parentElement.style.background = '#fee2e2';
                } else {
                    configLocais.HORARIOS.FECHADO = configLocais.HORARIOS.FECHADO.filter(d => d !== diaVal);
                    e.target.parentElement.style.background = '#f3f4f6';
                }
                salvarAlteracoesNoNavegador();
            });
        });
    }

    const credenciaisAtuais = {
        user: sessionStorage.getItem('admin_user') || '',
        pass: sessionStorage.getItem('admin_pass') || ''
    };
    if (inputAdminUser) inputAdminUser.value = credenciaisAtuais.user;
    if (inputAdminPass) inputAdminPass.value = '';

    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            sessionStorage.removeItem('admin_logado');
            sessionStorage.removeItem('admin_user');
            sessionStorage.removeItem('admin_pass');
            window.location.href = tenantAtual === 'default' ? 'marketplace.html' : adminPageUrl('index.html');
        });
    }

    const btnVerSite = document.getElementById('btn-ver-site');
    if (btnVerSite) {
        btnVerSite.addEventListener('click', () => {
            window.open(tenantAtual === 'default' ? 'index.html?tenant=default' : adminPageUrl('index.html'), '_blank');
        });
    }

    // Botão Resetar Preview (Novo)
    const btnResetPreview = document.createElement('button');
        btnResetPreview.innerText = "Desfazer o que ainda não foi salvo";
    btnResetPreview.className = "btn-mini btn-delete";
    btnResetPreview.style.marginTop = "10px";
    btnResetPreview.onclick = () => {
        if (confirm("Isso apagará suas edições temporárias e voltará ao que está no arquivo original. Confirmar?")) {
            localStorage.removeItem(adminPreviewKey('dados_cardapio_preview'));
            localStorage.removeItem(adminPreviewKey('config_preview'));
            gravarEstadoAdminUi();
            location.reload();
        }
    };
    document.querySelector('.admin-header > div').appendChild(btnResetPreview);


    // --- TOAST FUNCTION ---
    function showToast(msg, type = 'success') {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.style.cssText = "position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;";
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.innerText = msg;
        toast.style.cssText = `
            background: ${type === 'error' ? '#ef4444' : (type === 'warning' ? '#f59e0b' : '#10b981')};
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            font-family: 'Roboto', sans-serif;
            font-weight: 500;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
        `;
        container.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        });

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function chaveEstadoAdminUi() {
        return (typeof TENANT !== 'undefined' && TENANT.storageKey)
            ? TENANT.storageKey('admin_ui')
            : 'admin_ui';
    }

    function gravarEstadoAdminUi() {
        try {
            const btn = document.querySelector('#admin-tabs button.ativo');
            const tab = btn ? btn.dataset.tab : 'identidade';
            let foco = '';
            if (tab === 'cardapio') {
                const pratos = document.getElementById('secao-pratos-precos');
                if (pratos && pratos.getBoundingClientRect().top < window.innerHeight * 0.5) {
                    foco = '#secao-pratos-precos';
                }
            }
            sessionStorage.setItem(chaveEstadoAdminUi(), JSON.stringify({
                tab,
                scroll: window.scrollY || 0,
                foco
            }));
        } catch (e) { /* sessionStorage */ }
    }

    function restaurarEstadoAdminUi() {
        try {
            const params = new URLSearchParams(window.location.search);
            if (params.get('tab')) return;
            const raw = sessionStorage.getItem(chaveEstadoAdminUi());
            if (!raw) return;
            const st = JSON.parse(raw);
            if (st.tab && typeof window.__pzAtivarAbaAdmin === 'function') {
                window.__pzAtivarAbaAdmin(st.tab);
            }
            if (st.scroll) {
                requestAnimationFrame(() => {
                    window.scrollTo(0, Number(st.scroll) || 0);
                });
            }
            if (st.foco) {
                const alvo = document.querySelector(st.foco);
                if (alvo) alvo.scrollIntoView({ block: 'start' });
            }
        } catch (e) { /* ignore */ }
    }

    window.gravarEstadoAdminUi = gravarEstadoAdminUi;
    window.restaurarEstadoAdminUi = restaurarEstadoAdminUi;

    // --- SYNC FUNCTION ---
    function salvarAlteracoesNoNavegador() {
        configLocais.TENANT_SLUG = tenantAtual;
        localStorage.setItem(adminPreviewKey('dados_cardapio_preview'), JSON.stringify(dadosLocais));
        localStorage.setItem(adminPreviewKey('config_preview'), JSON.stringify(configLocais));

        // Atualiza campos de exportação manual
        const rawCardapio = document.getElementById('raw-cardapio');
        const rawConfig = document.getElementById('raw-config');
        if (rawCardapio) rawCardapio.value = `const CARDAPIO_DADOS = ${JSON.stringify(dadosLocais, null, 4)};`;
        if (rawConfig) rawConfig.value = `const APP_CONFIG = ${JSON.stringify(configLocais, null, 4)};`;

        // Visualmente Feedback
        const btn = document.getElementById('btn-gerar-arquivo');
        if (btn) {
            btn.innerHTML = '<i class="fa-solid fa-save"></i> Salvar agora';
            btn.style.backgroundColor = '#eab308'; // Amarelo alerta
        }
    }


    // --- RENDER ---
    function renderizarEditor() {
        if (!listaEditor) return;
        listaEditor.innerHTML = '';

        // 1. Promoções (Combos do Dia)
        const sectionPromos = document.createElement('div');
        sectionPromos.innerHTML = `<div style="display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin: 20px 0 10px; color: #f97136; text-transform: uppercase; font-size: 0.9em;">Promoções / Combos do Dia</h4>
            <button onclick="adicionarPromocao()" class="btn-mini btn-edit" style="height:fit-content;">+ Nova Promoção</button>
        </div>`;
        listaEditor.appendChild(sectionPromos);

        const divPromosList = document.createElement('div');
        divPromosList.id = 'lista-promocoes-editor';
        listaEditor.appendChild(divPromosList);

        if (dadosLocais.promocoes) {
            dadosLocais.promocoes.forEach((item, index) => {
                const row = createItemRow('promocoes', item, index, true);
                divPromosList.appendChild(row);
            });
        }

        // 2. Outras Categorias
        const labelsCategorias = {
            'hamburgueres': '🍔 Hambúrgueres',
            'porcoes': '🍟 Porções',
            'bebidas': '🥤 Bebidas',
            'acai': '🫐 Açaís',
            'combos': '📦 Combos'
        };

        const chavesCategorias = Object.keys((configLocais && configLocais.CATEGORIAS) || {}).filter((cat) => cat !== 'promocoes');
        Object.keys(dadosLocais).forEach((cat) => {
            if (cat !== 'promocoes' && Array.isArray(dadosLocais[cat]) && chavesCategorias.indexOf(cat) === -1) chavesCategorias.push(cat);
        });
        chavesCategorias.forEach(cat => {
            const sectionCat = document.createElement('div');
            const meta = (configLocais.CATEGORIAS || {})[cat] || {};
            const label = (meta.emoji ? meta.emoji + ' ' : '') + (meta.nome || labelsCategorias[cat] || cat);
            sectionCat.innerHTML = `<div style="display:flex; justify-content:space-between; align-items:center;">
                <h4 style="margin: 20px 0 10px; color: #f97136; text-transform: uppercase; font-size: 0.9em;">${label}</h4>
                <button onclick="window.adicionarItem('${cat}')" class="btn-mini btn-edit" style="height:fit-content;">+ Novo Item</button>
            </div>`;
            listaEditor.appendChild(sectionCat);

            if (dadosLocais[cat]) {
                dadosLocais[cat].forEach((item, index) => {
                    listaEditor.appendChild(createItemRow(cat, item, index, false));
                });
            }
        });
    }

    function createItemRow(category, item, index, isPromo) {
        const row = document.createElement('div');
        row.className = 'item-row';
        row.style.alignItems = "flex-start";

        const safeVal = (val) => val || '';

        let extraFieldsHtml = '';
        let deleteBtnHtml = '';
        let imageControlHtml = '';

        const previewSrc = safeVal(item.imagem) || 'assets/images/placeholder-logo.svg';
        const isBase64 = item.imagem && item.imagem.startsWith('data:');
        const valImagem = (isBase64 ? '' : safeVal(item.imagem)).replace(/"/g, '&quot;');
        imageControlHtml = `
                <label for="upload-${category}-${index}" class="btn-foto-produto" title="Enviar foto do celular ou computador">
                    <i class="fa-solid fa-camera"></i> Enviar foto
                </label>
                <input type="file" id="upload-${category}-${index}" accept="image/*" capture="environment" style="display:none;" onchange="window.uploadImagem('${category}', ${index}, this)">
                <input type="text" value="${valImagem}" style="width:100%; font-size:0.95rem; padding:10px; border:2px solid #d1d5db; border-radius:10px; box-sizing:border-box;" onchange="window.atualizarItem('${category}', ${index}, 'imagem', this.value); window.__pzRenderEditor && window.__pzRenderEditor();" placeholder="ou cole link https://...">
        `;

        if (isPromo) {
            extraFieldsHtml = `
                <textarea placeholder="Descrição da Promoção" style="width:100%; margin-bottom:5px; font-size:0.85em; resize:vertical; height:60px;" onchange="window.atualizarItem('${category}', ${index}, 'descricao', this.value)">${safeVal(item.descricao)}</textarea>
                <div style="display:flex; gap:10px;">
                     <input type="number" step="0.50" placeholder="Preço Original (R$)" value="${item.precoOriginal || ''}" style="width: 120px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size:0.8em;" onchange="window.atualizarItem('${category}', ${index}, 'precoOriginal', this.value)" title="Preço 'De'">
                </div>
                <div style="margin-top:5px;">
                    <label style="font-size:0.8em;"><input type="checkbox" ${item.ativo !== false ? 'checked' : ''} onchange="window.atualizarItem('${category}', ${index}, 'ativo', this.checked)"> Ativo no Site</label>
                </div>
            `;
        } else {
            // Descrição simples para itens comuns
            extraFieldsHtml = `
                <input type="text" value="${safeVal(item.descricao)}" placeholder="Descrição (ingredientes)" style="width: 100%; margin-bottom: 5px; border:1px solid #eee; padding:5px; color:#666; font-size:0.9em;" onchange="window.atualizarItem('${category}', ${index}, 'descricao', this.value)">
            `;
        }

        const gruposTexto = (item.grupos && item.grupos.length)
            ? item.grupos.map((g) => '# ' + (g.nome || 'Complementos') + '|' + (g.min || 0) + '|' + (g.max || 1) + '\n' + (g.itens || []).map((op) => op.nome + '|' + (op.preco || 0)).join('\n')).join('\n')
            : (item.opcionais || []).map((op) => op.nome + '|' + (op.preco || 0)).join('\n');
        const tamanhosTexto = (item.tamanhos || []).map((t) => t.nome + '|' + (t.preco || 0)).join('\n');
        extraFieldsHtml += `
            <textarea placeholder="# Carne|1|1&#10;Bife|0&#10;# Guarnição|2|2&#10;Arroz|0" style="width:100%; margin-top:6px; font-size:0.8em; height:70px;" onchange="window.atualizarGrupos('${category}', ${index}, this.value)">${gruposTexto}</textarea>
            <textarea placeholder="Tamanhos: P|18&#10;M|22" style="width:100%; margin-top:6px; font-size:0.8em; height:50px;" onchange="window.atualizarTamanhos('${category}', ${index}, this.value)">${tamanhosTexto}</textarea>
            <div style="display:flex; gap:12px; align-items:center; margin-top:6px; flex-wrap:wrap;">
                <label style="font-size:0.8em;">Peso (g) <input type="number" value="${item.peso || 0}" style="width:80px;" onchange="window.atualizarItem('${category}', ${index}, 'peso', this.value)"></label>
                <label style="font-size:0.8em;"><input type="checkbox" ${item.destaque ? 'checked' : ''} onchange="window.atualizarItem('${category}', ${index}, 'destaque', this.checked)"> Destaque</label>
                <label style="font-size:0.8em;"><input type="checkbox" ${item.ativo !== false ? 'checked' : ''} onchange="window.atualizarItem('${category}', ${index}, 'ativo', this.checked)"> Ativo</label>
                <label style="font-size:0.82em;background:${item.esgotado ? '#fee2e2' : '#f1f5f9'};color:${item.esgotado ? '#991b1b' : '#334155'};padding:4px 9px;border-radius:999px;font-weight:700;cursor:pointer;"><input type="checkbox" ${item.esgotado ? 'checked' : ''} onchange="window.atualizarItem('${category}', ${index}, 'esgotado', this.checked); window.__pzToggleEsgotadoServidor && window.__pzToggleEsgotadoServidor('${String(item.id || '')}', this.checked);"> ${item.esgotado ? 'ESGOTADO HOJE' : 'Marcar Esgotado'}</label>
            </div>`;

        // Botão de excluir para todos
        deleteBtnHtml = `<button class="btn-mini btn-delete" onclick="window.removerItem('${category}', ${index})" title="Apagar Item"><i class="fa-solid fa-trash"></i></button>`;

        row.innerHTML = `
            <div class="produto-foto-col" style="width:140px;">
                <img src="${previewSrc.replace(/"/g, '&quot;')}" class="item-img" alt="Foto do prato" onerror="this.onerror=null;this.src='assets/images/placeholder-logo.svg'">
                ${imageControlHtml}
            </div>
            <div class="item-details" style="margin-left: 15px;">
                <input type="text" value="${item.nome}" placeholder="Nome do Produto" style="font-weight: bold; border:1px solid #eee; padding:5px; width: 100%; margin-bottom: 5px;" onchange="window.atualizarItem('${category}', ${index}, 'nome', this.value)">
                ${extraFieldsHtml}
                <div style="display:flex; gap: 10px; align-items:center; margin-top: 5px;">
                    <span style="color:#888; font-size:0.9em;">R$</span>
                    <input type="number" step="0.50" value="${item.preco}" style="width: 80px; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="window.atualizarItem('${category}', ${index}, 'preco', this.value)">
                </div>
            </div>
            <div class="item-actions">
                ${deleteBtnHtml}
            </div>
        `;
        return row;
    }

    // --- GLOBAL SCOPE FUNCTIONS ---
    async function enviarFotoServidor(dataUrl, imagemAnterior) {
        const saveUrl = (typeof TENANT !== 'undefined' && TENANT.apiUrl)
            ? TENANT.apiUrl('upload_produto_imagem.php')
            : 'upload_produto_imagem.php';
        const res = await fetch(saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                auth: { user: credenciaisAtuais.user, pass: credenciaisAtuais.pass },
                tenant: tenantAtual,
                imagem: dataUrl,
                imagem_anterior: imagemAnterior || ''
            })
        });
        const result = await res.json().catch(() => ({}));
        if (!res.ok || !result.success) {
            throw new Error(result.message || 'Não foi possível enviar a foto.');
        }
        return result.url;
    }

    window.uploadImagem = function (cat, index, input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        const label = input.parentElement && input.parentElement.querySelector('.btn-foto-produto');
        if (label) {
            label.disabled = true;
            label.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
        }
        const reader = new FileReader();
        reader.onload = function (e) {
            const img = new Image();
            img.src = e.target.result;
            img.onload = async function () {
                const canvas = document.createElement('canvas');
                const ctx2 = canvas.getContext('2d');
                const MAX_WIDTH = 720;
                let width = img.width;
                let height = img.height;
                if (width > MAX_WIDTH) {
                    height *= MAX_WIDTH / width;
                    width = MAX_WIDTH;
                }
                canvas.width = width;
                canvas.height = height;
                ctx2.drawImage(img, 0, 0, width, height);
                const dataUrl = canvas.toDataURL('image/jpeg', 0.7);
                const imagemAnterior = (dadosLocais[cat][index] && dadosLocais[cat][index].imagem) || '';
                try {
                    const urlFinal = await enviarFotoServidor(dataUrl, imagemAnterior);
                    dadosLocais[cat][index].imagem = urlFinal;
                    salvarAlteracoesNoNavegador();
                    gravarEstadoAdminUi();
                    renderizarEditor();
                    showToast('Foto do prato atualizada. Toque em Salvar para publicar no site.', 'success');
                } catch (err) {
                    showToast(err.message || 'Erro ao processar a foto.', 'error');
                } finally {
                    if (label) {
                        label.disabled = false;
                        label.innerHTML = '<i class="fa-solid fa-camera"></i> Enviar foto';
                    }
                    input.value = '';
                }
            };
        };
        reader.readAsDataURL(file);
    };

    window.atualizarItem = function (cat, index, field, value) {
        if (field === 'preco' || field === 'precoOriginal' || field === 'peso') {
            value = (value !== '' && value !== null && typeof value !== 'boolean') ? parseFloat(value) : value;
        }
        dadosLocais[cat][index][field] = value;
        salvarAlteracoesNoNavegador();
    };

    window.atualizarGrupos = function (cat, index, texto) {
        const grupos = [];
        let atual = null;
        String(texto || '').split('\n').forEach((linha) => {
            const t = linha.trim();
            if (!t) return;
            if (t.charAt(0) === '#') {
                const cabeca = t.replace(/^#\s*/, '').split('|');
                atual = {
                    nome: (cabeca[0] || 'Complementos').trim(),
                    min: parseInt(cabeca[1], 10) || 0,
                    max: parseInt(cabeca[2], 10) || 20,
                    itens: []
                };
                grupos.push(atual);
                return;
            }
            if (!atual) {
                atual = { nome: 'Complementos', min: 0, max: 20, itens: [] };
                grupos.push(atual);
            }
            const partes = t.split('|');
            atual.itens.push({
                nome: (partes[0] || '').trim(),
                preco: parseFloat(String(partes[1] || '0').replace(',', '.')) || 0
            });
        });
        dadosLocais[cat][index].grupos = grupos;
        dadosLocais[cat][index].opcionais = grupos.reduce((acc, g) => acc.concat(g.itens), []);
        salvarAlteracoesNoNavegador();
    };

    window.atualizarTamanhos = function (cat, index, texto) {
        dadosLocais[cat][index].tamanhos = String(texto || '').split('\n').map((linha) => linha.trim()).filter(Boolean).map((linha) => {
            const partes = linha.split('|');
            return { nome: (partes[0] || '').trim(), preco: parseFloat(String(partes[1] || '0').replace(',', '.')) || 0 };
        }).filter((t) => t.nome);
        salvarAlteracoesNoNavegador();
    };

    window.removerItem = function (cat, index) {
        if (confirm('Tem certeza que deseja apagar este item permanentemente?')) {
            dadosLocais[cat].splice(index, 1);
            salvarAlteracoesNoNavegador();
            renderizarEditor();
            showToast('Item removido. Toque em Salvar para liberar a foto antiga no servidor.', 'success');
        }
    };

    window.adicionarPromocao = function () {
        const novaPromo = {
            id: Date.now(),
            nome: "Novo Combo",
            descricao: "Descreva o combo aqui",
            preco: 0.00,
            precoOriginal: 0.00,
            imagem: "assets/images/placeholder-logo.svg",
            ehPromocao: true,
            ativo: true
        };
        if (!dadosLocais.promocoes) dadosLocais.promocoes = [];
        dadosLocais.promocoes.unshift(novaPromo);
        salvarAlteracoesNoNavegador();
        renderizarEditor();
    };

    window.adicionarItem = function (cat) {
        const novoItem = {
            id: Date.now(),
            nome: "Novo Item",
            descricao: "Descrição do item",
            preco: 0.00,
            imagem: "assets/images/placeholder-logo.svg"
        };
        if (!dadosLocais[cat]) dadosLocais[cat] = [];
        dadosLocais[cat].unshift(novoItem);
        salvarAlteracoesNoNavegador();
        renderizarEditor();
    };

    // --- ACTIONS ---

    if (btnGerarArquivo) {
        btnGerarArquivo.addEventListener('click', async () => {
            // --- TRAVA DE SEGURANÇA FINAL ---
            const adminUser = sessionStorage.getItem('admin_user');
            const adminPass = sessionStorage.getItem('admin_pass');

            if (!adminUser || !adminPass) {
                showToast("Erro: Credenciais de autenticação não encontradas. Tente logar novamente.", "error");
                return;
            }

            gravarEstadoAdminUi();
            btnGerarArquivo.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvando Online...';
            btnGerarArquivo.disabled = true;

            const novaSenha = (inputAdminPass && inputAdminPass.value || '').trim();
            const novoUsuario = (inputAdminUser && inputAdminUser.value || '').trim();
            if (novoUsuario && novoUsuario !== credenciaisAtuais.user && !novaSenha) {
                showToast('Informe a nova senha junto com o novo usuário.', 'error');
                btnGerarArquivo.disabled = false;
                btnGerarArquivo.innerHTML = '<i class="fa-solid fa-save"></i> Salvar';
                return;
            }
            const payloadStr = JSON.stringify(dadosLocais);
            if (payloadStr.length > 4_500_000) {
                showToast('O cardápio ficou muito pesado (fotos). Envie as fotos de novo pelo botão azul ou use links de imagem.', 'error');
                btnGerarArquivo.disabled = false;
                btnGerarArquivo.innerHTML = '<i class="fa-solid fa-save"></i> Salvar';
                return;
            }
            if (payloadStr.indexOf('data:image') !== -1) {
                showToast('Há foto que não foi para o servidor. Use "Enviar foto" em cada prato antes de Salvar.', 'error');
                btnGerarArquivo.disabled = false;
                btnGerarArquivo.innerHTML = '<i class="fa-solid fa-save"></i> Salvar';
                return;
            }

            const payload = {
                auth: {
                    user: credenciaisAtuais.user || adminUser,
                    pass: credenciaisAtuais.pass || adminPass
                },
                tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default',
                cardapio: dadosLocais,
                config: configLocais
            };
            if (novaSenha) {
                payload.auth_update = {
                    user: novoUsuario || credenciaisAtuais.user,
                    pass: novaSenha
                };
            }

            try {
                const saveUrl = (typeof TENANT !== 'undefined' && TENANT.apiUrl)
                    ? TENANT.apiUrl('save_data.php')
                    : 'save_data.php';
                const response = await fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (result.success) {
                    const destino = result.tenant && result.tenant !== 'default'
                        ? 'tenant: ' + result.tenant
                        : 'loja principal';
                    showToast('✅ Salvo no servidor (' + destino + ')', 'success');
                    if (window.PedidoZapCelulas && window.PedidoZapCelulas.emitir) {
                        window.PedidoZapCelulas.emitir('loja_atualizada', { tenant: tenantAtual });
                    }
                    btnGerarArquivo.innerHTML = '<i class="fa-solid fa-check"></i> Salvo!';
                    btnGerarArquivo.style.backgroundColor = '#10b981';

                    localStorage.removeItem(adminPreviewKey('dados_cardapio_preview'));
                    localStorage.removeItem(adminPreviewKey('config_preview'));

                    if (typeof PedidoZapStore !== 'undefined') {
                        await PedidoZapStore.load({ fallbackStatic: tenantAtual === 'default' });
                        dadosLocais = JSON.parse(JSON.stringify(window.CARDAPIO_DADOS || dadosLocais));
                        configLocais = JSON.parse(JSON.stringify(window.APP_CONFIG || configLocais));
                        if (novaSenha) {
                            credenciaisAtuais.user = novoUsuario || credenciaisAtuais.user;
                            credenciaisAtuais.pass = await obterTokenNovo(credenciaisAtuais.user, novaSenha);
                            sessionStorage.setItem('admin_pass', credenciaisAtuais.pass);
                            sessionStorage.setItem('admin_user', credenciaisAtuais.user);
                        }
                        renderizarEditor();
                    }
                    if (window.__pzMontarCategorias) window.__pzMontarCategorias();
                    gravarEstadoAdminUi();
                    setTimeout(restaurarEstadoAdminUi, 50);
                } else {
                    showToast('❌ Erro: ' + result.message, 'error');
                    btnGerarArquivo.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Tentar Novamente';
                    btnGerarArquivo.style.backgroundColor = '#ef4444';
                }
            } catch (error) {
                console.error(error);
                showToast('❌ Erro de conexão com o servidor.', 'error');
                btnGerarArquivo.innerHTML = '<i class="fa-solid fa-wifi"></i> Erro de Rede';
            } finally {
                btnGerarArquivo.disabled = false;
                setTimeout(() => {
                    btnGerarArquivo.innerHTML = '<i class="fa-solid fa-save"></i> Salvar';
                    btnGerarArquivo.style.backgroundColor = '';
                }, 3000);
            }
        });
    }

    function aplicarSugestaoNicho() {
        const select = document.getElementById('input-nicho');
        const preset = NICHOS_LOJA[select ? select.value : ''] || NICHOS_LOJA.lanchonete;
        const temItens = Object.keys(dadosLocais).some((chave) => chave !== 'promocoes' && (dadosLocais[chave] || []).length);
        if (temItens && !confirm('A loja já tem produtos. As categorias sugeridas serão acrescentadas e os produtos atuais continuam.')) return;
        if (!configLocais.CATEGORIAS) configLocais.CATEGORIAS = {};
        if (!temItens) {
            const promos = configLocais.CATEGORIAS.promocoes;
            configLocais.CATEGORIAS = promos ? { promocoes: promos } : {};
        }
        preset.categorias.forEach((cat) => {
            configLocais.CATEGORIAS[cat.slug] = {
                nome: cat.nome, subtitulo: cat.subtitulo, emoji: cat.emoji, icone: 'fa-utensils', cor: cat.cor, ativo: true
            };
            if (!dadosLocais[cat.slug]) dadosLocais[cat.slug] = [];
            if (!configLocais.TEXTOS.PAGINAS) configLocais.TEXTOS.PAGINAS = {};
            configLocais.TEXTOS.PAGINAS[cat.slug] = configLocais.TEXTOS.PAGINAS[cat.slug] || { titulo: cat.nome, subtitulo: cat.subtitulo };
        });
        configLocais.NICHO = preset.id;
        salvarAlteracoesNoNavegador();
        if (window.__pzMontarCategorias) window.__pzMontarCategorias();
        renderizarEditor();
        showToast('Sugestões do segmento aplicadas. Salve para publicar.', 'success');
    }

    renderizarEditor();

    // Initialize
    window.__pzAdmin = {
        get config() { return configLocais; },
        set config(v) { configLocais = v; },
        getCardapio() { return dadosLocais; },
        setCardapio(v) { dadosLocais = v; },
        salvar: salvarAlteracoesNoNavegador,
        credenciais: credenciaisAtuais,
        toast: showToast,
        tenant: tenantAtual
    };
    window.__pzRenderEditor = renderizarEditor;
    window.__pzToggleEsgotadoServidor = async function (prodId, esgotado) {
        if (!prodId || !credenciaisAtuais.user || !credenciaisAtuais.pass) return;
        try {
            const url = (typeof TENANT !== 'undefined' && TENANT.apiUrl)
                ? TENANT.apiUrl('gestao_api.php?action=produto_esgotado_rapido')
                : 'gestao_api.php?action=produto_esgotado_rapido';
            const r = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    auth: { user: credenciaisAtuais.user, pass: credenciaisAtuais.pass },
                    id: prodId,
                    esgotado: Boolean(esgotado)
                })
            });
            const d = await r.json();
            if (d && d.success) {
                showToast(esgotado ? 'Item marcado como ESGOTADO hoje no cardápio!' : 'Item reativado no cardápio!', 'success');
                renderizarEditor();
            }
        } catch (e) { /* usuário ainda pode salvar completo */ }
    };
    ligarWhiteLabel();
    const btnNovaCat = document.getElementById('btn-nova-categoria');
    if (btnNovaCat) btnNovaCat.addEventListener('click', () => {
        const slug = (document.getElementById('input-nova-cat-slug').value || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
        const nome = (document.getElementById('input-nova-cat-nome').value || '').trim();
        if (!slug || !nome) {
            showToast('Informe o identificador e o nome da categoria.', 'error');
            return;
        }
        if (!configLocais.CATEGORIAS) configLocais.CATEGORIAS = {};
        configLocais.CATEGORIAS[slug] = configLocais.CATEGORIAS[slug] || { nome: nome, subtitulo: '', emoji: '', icone: 'fa-utensils', cor: '#f97136', ativo: true };
        configLocais.CATEGORIAS[slug].nome = nome;
        if (!dadosLocais[slug]) dadosLocais[slug] = [];
        salvarAlteracoesNoNavegador();
        if (window.__pzMontarCategorias) window.__pzMontarCategorias();
        renderizarEditor();
    });
    const btnNicho = document.getElementById('btn-aplicar-nicho');
    if (btnNicho) btnNicho.addEventListener('click', aplicarSugestaoNicho);

    window.addEventListener('scroll', () => {
        clearTimeout(window.__pzScrollSaveTimer);
        window.__pzScrollSaveTimer = setTimeout(gravarEstadoAdminUi, 400);
    }, { passive: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciarAdmin);
} else {
    iniciarAdmin();
}

const NICHOS_LOJA = {
    lanchonete: { id: 'lanchonete', categorias: [
        { slug: 'lanches', nome: 'Lanches', subtitulo: 'Os mais pedidos', emoji: '🍔', cor: '#f97136' },
        { slug: 'porcoes', nome: 'Porções', subtitulo: 'Para acompanhar', emoji: '🍟', cor: '#f59e0b' },
        { slug: 'bebidas', nome: 'Bebidas', subtitulo: 'Geladas', emoji: '🥤', cor: '#2563eb' },
        { slug: 'sobremesas', nome: 'Sobremesas', subtitulo: 'Para fechar', emoji: '🍰', cor: '#db2777' }
    ]},
    pensao: { id: 'pensao', categorias: [
        { slug: 'pratos', nome: 'Pratos feitos', subtitulo: 'Almoço do dia', emoji: '🍽️', cor: '#b45309' },
        { slug: 'marmitas', nome: 'Marmitas', subtitulo: 'P, M e G', emoji: '🍱', cor: '#ea580c' },
        { slug: 'guarnicoes', nome: 'Guarnições', subtitulo: 'Acompanhamentos', emoji: '🥗', cor: '#16a34a' },
        { slug: 'sopas', nome: 'Sopas e caldos', subtitulo: 'Quentinhas', emoji: '🥣', cor: '#0369a1' },
        { slug: 'bebidas', nome: 'Bebidas', subtitulo: 'Geladas', emoji: '🥤', cor: '#2563eb' }
    ]},
    marmitaria: { id: 'marmitaria', categorias: [
        { slug: 'marmitas', nome: 'Marmitas', subtitulo: 'Monte a sua', emoji: '🍱', cor: '#ea580c' },
        { slug: 'executivos', nome: 'Executivos', subtitulo: 'Pratos leves', emoji: '🥗', cor: '#15803d' },
        { slug: 'bebidas', nome: 'Bebidas', subtitulo: 'Geladas', emoji: '🥤', cor: '#2563eb' }
    ]},
    pizzaria: { id: 'pizzaria', categorias: [
        { slug: 'pizzas', nome: 'Pizzas', subtitulo: 'Tradicionais e especiais', emoji: '🍕', cor: '#dc2626' },
        { slug: 'bordas', nome: 'Bordas e adicionais', subtitulo: 'Para incrementar', emoji: '🧀', cor: '#d97706' },
        { slug: 'bebidas', nome: 'Bebidas', subtitulo: 'Geladas', emoji: '🥤', cor: '#2563eb' }
    ]},
    acai: { id: 'acai', categorias: [
        { slug: 'acai', nome: 'Açaí', subtitulo: 'Tigelas', emoji: '🫐', cor: '#7e22ce' },
        { slug: 'complementos', nome: 'Complementos', subtitulo: 'Monte do seu jeito', emoji: '🍓', cor: '#db2777' },
        { slug: 'bebidas', nome: 'Bebidas', subtitulo: 'Geladas', emoji: '🥤', cor: '#2563eb' }
    ]},
    confeitaria: { id: 'confeitaria', categorias: [
        { slug: 'bolos', nome: 'Bolos e fatias', subtitulo: 'Encomendas', emoji: '🎂', cor: '#db2777' },
        { slug: 'doces', nome: 'Doces', subtitulo: 'Para presentear', emoji: '🍬', cor: '#f59e0b' },
        { slug: 'bebidas', nome: 'Bebidas', subtitulo: 'Para acompanhar', emoji: '☕', cor: '#78350f' }
    ]}
};

function ligarWhiteLabel() {
    const ctx = window.__pzAdmin;
    if (!ctx) return;
    const cfg = ctx.config;

    function preencher(destino, origem) {
        Object.keys(origem).forEach((chave) => {
            const valor = origem[chave];
            if (destino[chave] == null) {
                destino[chave] = Array.isArray(valor) ? [] : (valor && typeof valor === 'object' ? {} : valor);
            }
            if (valor && typeof valor === 'object' && !Array.isArray(valor) && destino[chave] && typeof destino[chave] === 'object') {
                preencher(destino[chave], valor);
            }
        });
    }

    preencher(cfg, {
        IDENTIDADE: { LOGO: '', FAVICON: '', HERO: '', CORES: { primaria: '#f97136', secundaria: '#1f2937', fundo: '#f9fafb' } },
        TEXTOS: { HERO_TITULO: '', HERO_SUBTITULO: '', SECAO_OFERTAS: '', SECAO_DESTAQUES: '', SECAO_CARDAPIO: '', SECAO_AVALIACOES: '', PAGINAS: {} },
        BANNER_WPP: { ativo: false, titulo: '', mensagem: '', botao: '' },
        UPSELL: { ativo: false, produto_id: '', imagem: '', titulo: '', subtitulo: '' },
        AVALIACOES: [],
        CHECKOUT: { aviso_observacoes: '', agendamento_inicio: '', agendamento_fim: '', agendamento_texto: '' },
        ENDERECO: { rua: '', numero: '', bairro: '', cidade: '', estado: '', cep: '', lat: '', lng: '' },
        REDES: { instagram: '', facebook: '', whatsapp: '' },
        HORARIOS: { TEXTO: '', TEXTO_FOLGA: '' },
        RODAPE: { titulo_horario: 'Horário', titulo_contato: 'Fale com a gente', frase: '' },
        FRETE: { modo: 'gratis', faixas: [], bairros: [], usar_faixas: false },
        PAGAMENTOS: { pix: true, debito: true, credito: true, dinheiro: true, chave_pix: '' },
        CATEGORIAS: {},
        NICHO: 'lanchonete',
        LOGISTICA: { modelo: 'propria', aceita_retirada: true, despacho_manual: false },
        ATENDIMENTO: {
            ativo: true,
            titulo_pagina: 'Pedido manual',
            instrucao: 'Informe o cliente e abra o cardápio para montar o pedido.',
            label_nome: 'Nome do cliente',
            label_telefone: 'Telefone (com DDD)',
            btn_abrir_cardapio: 'Abrir cardápio',
            banner_cardapio: 'Modo atendente — pedido manual',
            texto_sucesso: 'Pedido registrado. Confira no painel de pedidos.',
            abrir_whatsapp: false,
            modo_padrao: 'retirada',
            rotulo_entrega_manual: 'Pedido pelo balcão / telefone',
            rotulo_badge_painel: 'Balcão',
            link_voltar_painel: 'Voltar ao painel de pedidos',
            link_novo_pedido: 'Fazer outro pedido',
            btn_confirmar_pedido: 'Registrar pedido',
            btn_confirmar_whatsapp: 'Enviar pedido pelo WhatsApp',
            texto_loja_fechada: 'Loja fechada — agende o pedido'
        },
        COMANDA: {
            titulo_via_cozinha: 'COZINHA',
            texto_canal_balcao: 'Balcão',
            rodape_cozinha: 'Confira os itens antes de enviar.',
            rodape_completa: 'Obrigado pela preferência!',
            cozinha_instrucao: 'Mantenha esta tela aberta na impressora 80 mm. Ao aceitar no painel, a comanda imprime aqui.',
            cozinha_btn_pronto: 'Pronto — sai para entrega',
            cozinha_btn_imprimir: 'Reimprimir comanda',
            cozinha_vazio: 'Nenhum pedido na cozinha no momento.',
            painel_imprimir_ligado: 'Imprimir ao mandar pra cozinha',
            painel_imprimir_desligado: 'Só imprime na tela da cozinha',
            mostrar_telefone_cozinha: true,
            mostrar_pagamento_cozinha: false,
            painel_imprimir_auto: true,
            erro_popup: 'Libere pop-ups neste site para imprimir a comanda.'
        },
        RELATORIO: {
            titulo: 'Relatório mensal',
            subtitulo: 'Quantidade pedida de cada item no período.',
            rotulo_mais_vendidos: 'O que mais saiu',
            rotulo_menos_vendidos: 'O que menos saiu',
            rotulo_tabela: 'Todos os itens do mês',
            coluna_item: 'Item',
            coluna_qtd: 'Quantidade',
            coluna_valor: 'Valor (R$)',
            vazio: 'Nenhum pedido neste mês.'
        },
        TUTORIAL: {
            ativo: true,
            titulo: 'Primeiros passos',
            subtitulo: 'Siga esta ordem na primeira semana com a sua loja.',
            intro: 'Cada etapa leva poucos minutos. Você pode marcar como feito e voltar quando quiser.',
            btn_abrir: 'Abrir tutorial passo a passo',
            btn_ir_passo: 'Ir fazer agora',
            btn_marcar: 'Marcar como feito',
            btn_desmarcar: 'Desmarcar',
            texto_progresso: 'concluídos',
            rotulo_video: 'Vídeo de ajuda (opcional)',
            video_url: '',
            passos: [],
            primeiro_acesso_ativo: true,
            primeiro_acesso_titulo: 'Bem-vindo! Quer um tour rápido?',
            primeiro_acesso_texto: 'Montamos um passo a passo bem simples. Em uns 15 minutos você deixa a loja pronta — sem precisar ligar para ninguém.',
            primeiro_acesso_btn_tutorial: 'Sim, me mostre o passo a passo',
            primeiro_acesso_btn_depois: 'Agora não, quero explorar'
        },
        SIMPLICIDADE: {
            titulo_painel: 'Painel da sua loja',
            subtitulo_painel: 'Toque na parte que quer mudar. Quando terminar, toque no botão verde Salvar lá em cima.',
            dica_abas: 'Escolha um tema abaixo. Não precisa mudar tudo hoje — vá por partes.',
            regra_1: 'Sempre toque em Salvar depois de mudar algo.',
            regra_2: 'Em dúvida? Abra o passo a passo em Primeiros passos.',
            regra_3: 'Pedidos do cliente ficam no botão laranja Ver pedidos.',
            lembrete_salvar_titulo: 'Não esqueça de Salvar',
            lembrete_salvar: 'O cliente só vê as mudanças depois que você tocar em Salvar no topo.',
            painel_instrucao: '',
            login_titulo: 'Entrar no painel da loja',
            login_dica: 'Digite o usuário e a senha que você criou no cadastro.',
            login_btn: 'Entrar no painel',
            login_erro: 'Usuário ou senha incorretos. Confira e tente de novo.'
        }
    });
    if (!cfg.ATENDIMENTO) cfg.ATENDIMENTO = {};
    if (!cfg.RELATORIO) cfg.RELATORIO = {};
    if (!cfg.COMANDA) cfg.COMANDA = {};
    if (!cfg.TUTORIAL) cfg.TUTORIAL = {};
    if (!cfg.SIMPLICIDADE) cfg.SIMPLICIDADE = {};
    if (!cfg.AJUDA) cfg.AJUDA = { admin: {}, painel: { ferramentas: [], colunas: [] }, cozinha: {}, entregador: {}, atendimento: {} };
    if (!cfg.AJUDA.painel) cfg.AJUDA.painel = { ferramentas: [], colunas: [] };
    if (!cfg.AJUDA.cozinha) cfg.AJUDA.cozinha = { ferramentas: [], fichas: [] };
    if (!cfg.AJUDA.cardapio) cfg.AJUDA.cardapio = { passos: [] };
    if (!cfg.AJUDA.admin) cfg.AJUDA.admin = {};
    if (!Array.isArray(cfg.AJUDA.painel.ferramentas)) cfg.AJUDA.painel.ferramentas = [];
    if (!Array.isArray(cfg.AJUDA.painel.colunas)) cfg.AJUDA.painel.colunas = [];
    if (!Array.isArray(cfg.AJUDA.cozinha.ferramentas)) cfg.AJUDA.cozinha.ferramentas = [];
    if (!Array.isArray(cfg.AJUDA.cozinha.fichas)) cfg.AJUDA.cozinha.fichas = [];
    if (!Array.isArray(cfg.AJUDA.cardapio.passos)) cfg.AJUDA.cardapio.passos = [];
    if (!Array.isArray(cfg.TUTORIAL.passos)) cfg.TUTORIAL.passos = [];
    if (!Array.isArray(cfg.AVALIACOES)) cfg.AVALIACOES = [];
    if (!Array.isArray(cfg.FRETE.faixas)) cfg.FRETE.faixas = [];
    if (!Array.isArray(cfg.FRETE.bairros)) cfg.FRETE.bairros = [];

    function bind(id, ler, gravar) {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.type === 'checkbox') el.checked = !!ler();
        else if (ler() != null) el.value = ler();
        const evento = (el.type === 'checkbox' || el.tagName === 'SELECT') ? 'change' : 'input';
        el.addEventListener(evento, () => {
            gravar(el.type === 'checkbox' ? el.checked : el.value);
            ctx.salvar();
        });
    }

    function num(id, ler, gravar) {
        bind(id, () => (ler() == null ? '' : ler()), (valor) => gravar(valor === '' ? 0 : parseFloat(String(valor).replace(',', '.')) || 0));
    }

    bind('input-cnpj', () => cfg.CNPJ || '', (v) => { cfg.CNPJ = v; });
    bind('input-razao-social', () => cfg.RAZAO_SOCIAL || '', (v) => { cfg.RAZAO_SOCIAL = v; });
    bind('input-slogan', () => cfg.SLOGAN, (v) => { cfg.SLOGAN = v; });
    bind('input-seo', () => cfg.DESCRICAO_SEO, (v) => { cfg.DESCRICAO_SEO = v; });
    bind('input-keywords', () => cfg.PALAVRAS_CHAVE, (v) => { cfg.PALAVRAS_CHAVE = v; });
    bind('input-cor-primaria', () => cfg.IDENTIDADE.CORES.primaria, (v) => { cfg.IDENTIDADE.CORES.primaria = v; });
    bind('input-cor-secundaria', () => cfg.IDENTIDADE.CORES.secundaria, (v) => { cfg.IDENTIDADE.CORES.secundaria = v; });
    bind('input-cor-fundo', () => cfg.IDENTIDADE.CORES.fundo, (v) => { cfg.IDENTIDADE.CORES.fundo = v; });
    bind('input-logo', () => cfg.IDENTIDADE.LOGO, (v) => { cfg.IDENTIDADE.LOGO = v; });
    bind('input-favicon', () => cfg.IDENTIDADE.FAVICON, (v) => { cfg.IDENTIDADE.FAVICON = v; });
    bind('input-hero', () => cfg.IDENTIDADE.HERO, (v) => { cfg.IDENTIDADE.HERO = v; });
    bind('input-hero-titulo', () => cfg.TEXTOS.HERO_TITULO, (v) => { cfg.TEXTOS.HERO_TITULO = v; });
    bind('input-hero-sub', () => cfg.TEXTOS.HERO_SUBTITULO, (v) => { cfg.TEXTOS.HERO_SUBTITULO = v; });
    bind('input-sec-ofertas', () => cfg.TEXTOS.SECAO_OFERTAS, (v) => { cfg.TEXTOS.SECAO_OFERTAS = v; });
    bind('input-sec-destaques', () => cfg.TEXTOS.SECAO_DESTAQUES, (v) => { cfg.TEXTOS.SECAO_DESTAQUES = v; });
    bind('input-sec-cardapio', () => cfg.TEXTOS.SECAO_CARDAPIO, (v) => { cfg.TEXTOS.SECAO_CARDAPIO = v; });
    bind('input-sec-avaliacoes', () => cfg.TEXTOS.SECAO_AVALIACOES, (v) => { cfg.TEXTOS.SECAO_AVALIACOES = v; });
    bind('chk-banner-wpp', () => cfg.BANNER_WPP.ativo, (v) => { cfg.BANNER_WPP.ativo = v; });
    bind('input-banner-titulo', () => cfg.BANNER_WPP.titulo, (v) => { cfg.BANNER_WPP.titulo = v; });
    bind('input-banner-botao', () => cfg.BANNER_WPP.botao, (v) => { cfg.BANNER_WPP.botao = v; });
    bind('input-banner-msg', () => cfg.BANNER_WPP.mensagem, (v) => { cfg.BANNER_WPP.mensagem = v; });
    bind('chk-upsell', () => cfg.UPSELL.ativo, (v) => { cfg.UPSELL.ativo = v; });
    bind('input-upsell-produto', () => cfg.UPSELL.produto_id, (v) => { cfg.UPSELL.produto_id = v; });
    bind('input-upsell-titulo', () => cfg.UPSELL.titulo, (v) => { cfg.UPSELL.titulo = v; });
    bind('input-upsell-sub', () => cfg.UPSELL.subtitulo, (v) => { cfg.UPSELL.subtitulo = v; });
    bind('input-upsell-img', () => cfg.UPSELL.imagem, (v) => { cfg.UPSELL.imagem = v; });
    bind('input-end-rua', () => cfg.ENDERECO.rua, (v) => { cfg.ENDERECO.rua = v; });
    bind('input-end-numero', () => cfg.ENDERECO.numero, (v) => { cfg.ENDERECO.numero = v; });
    bind('input-end-bairro', () => cfg.ENDERECO.bairro, (v) => { cfg.ENDERECO.bairro = v; });
    bind('input-end-cidade', () => cfg.ENDERECO.cidade, (v) => { cfg.ENDERECO.cidade = v; });
    bind('input-end-estado', () => cfg.ENDERECO.estado, (v) => { cfg.ENDERECO.estado = v; });
    bind('input-end-cep', () => cfg.ENDERECO.cep, (v) => { cfg.ENDERECO.cep = v; });
    bind('input-end-lat', () => cfg.ENDERECO.lat ?? '', (v) => { cfg.ENDERECO.lat = v === '' ? null : parseFloat(v); });
    bind('input-end-lng', () => cfg.ENDERECO.lng ?? '', (v) => { cfg.ENDERECO.lng = v === '' ? null : parseFloat(v); });
    bind('input-instagram', () => cfg.REDES.instagram, (v) => { cfg.REDES.instagram = v; });
    bind('input-facebook', () => cfg.REDES.facebook, (v) => { cfg.REDES.facebook = v; });
    bind('input-horario-texto', () => cfg.HORARIOS.TEXTO, (v) => { cfg.HORARIOS.TEXTO = v; });
    bind('input-horario-folga', () => cfg.HORARIOS.TEXTO_FOLGA, (v) => { cfg.HORARIOS.TEXTO_FOLGA = v; });
    bind('input-rodape-horario', () => cfg.RODAPE.titulo_horario, (v) => { cfg.RODAPE.titulo_horario = v; });
    bind('input-rodape-contato', () => cfg.RODAPE.titulo_contato, (v) => { cfg.RODAPE.titulo_contato = v; });
    bind('input-rodape-frase', () => cfg.RODAPE.frase, (v) => { cfg.RODAPE.frase = v; });
    bind('input-frete-modo', () => cfg.FRETE.modo, (v) => { cfg.FRETE.modo = v; });
    bind('input-frete-unidade', () => cfg.FRETE.unidade_distancia, (v) => { cfg.FRETE.unidade_distancia = v; });
    bind('input-peso-unidade', () => cfg.FRETE.peso_unidade, (v) => { cfg.FRETE.peso_unidade = v; });
    bind('chk-usar-faixas', () => cfg.FRETE.usar_faixas, (v) => { cfg.FRETE.usar_faixas = v; });
    num('input-taxa-fixa', () => cfg.FRETE.taxa_fixa, (v) => { cfg.FRETE.taxa_fixa = v; });
    num('input-taxa-base', () => cfg.FRETE.taxa_base, (v) => { cfg.FRETE.taxa_base = v; });
    num('input-valor-unidade', () => cfg.FRETE.valor_unidade, (v) => { cfg.FRETE.valor_unidade = v; });
    num('input-raio-max', () => cfg.FRETE.raio_max_km, (v) => { cfg.FRETE.raio_max_km = v; });
    num('input-pedido-minimo', () => cfg.FRETE.pedido_minimo, (v) => { cfg.FRETE.pedido_minimo = v; });
    num('input-frete-gratis', () => cfg.FRETE.frete_gratis_acima, (v) => { cfg.FRETE.frete_gratis_acima = v; });
    num('input-peso-base', () => cfg.FRETE.peso_taxa_base, (v) => { cfg.FRETE.peso_taxa_base = v; });
    num('input-peso-valor', () => cfg.FRETE.peso_valor_unidade, (v) => { cfg.FRETE.peso_valor_unidade = v; });
    bind('chk-pay-pix', () => cfg.PAGAMENTOS.pix !== false, (v) => { cfg.PAGAMENTOS.pix = v; });
    bind('chk-pay-debito', () => cfg.PAGAMENTOS.debito !== false, (v) => { cfg.PAGAMENTOS.debito = v; });
    bind('chk-pay-credito', () => cfg.PAGAMENTOS.credito !== false, (v) => { cfg.PAGAMENTOS.credito = v; });
    bind('chk-pay-dinheiro', () => cfg.PAGAMENTOS.dinheiro !== false, (v) => { cfg.PAGAMENTOS.dinheiro = v; });
    bind('input-chave-pix', () => cfg.PAGAMENTOS.chave_pix, (v) => { cfg.PAGAMENTOS.chave_pix = v; });
    bind('input-agenda-ini', () => cfg.CHECKOUT.agendamento_inicio, (v) => { cfg.CHECKOUT.agendamento_inicio = v; });
    bind('input-agenda-fim', () => cfg.CHECKOUT.agendamento_fim, (v) => { cfg.CHECKOUT.agendamento_fim = v; });
    bind('input-agenda-texto', () => cfg.CHECKOUT.agendamento_texto, (v) => { cfg.CHECKOUT.agendamento_texto = v; });
    bind('input-aviso-obs', () => cfg.CHECKOUT.aviso_observacoes, (v) => { cfg.CHECKOUT.aviso_observacoes = v; });
    bind('input-nicho', () => cfg.NICHO || 'lanchonete', (v) => { cfg.NICHO = v; });
    bind('input-logistica', () => (cfg.LOGISTICA && cfg.LOGISTICA.modelo) || 'propria', (v) => { cfg.LOGISTICA.modelo = v; });
    bind('chk-aceita-retirada', () => cfg.LOGISTICA.aceita_retirada !== false, (v) => { cfg.LOGISTICA.aceita_retirada = v; });
    bind('chk-despacho-manual', () => !!cfg.LOGISTICA.despacho_manual, (v) => { cfg.LOGISTICA.despacho_manual = v; });

    const assinatura = window.PedidoZapStore && PedidoZapStore.assinatura;
    const resumoConta = document.getElementById('conta-resumo');
    if (resumoConta) {
        if (!assinatura) resumoConta.textContent = 'O status do plano aparece quando o servidor da loja responde.';
        else {
            const rotulos = { ativo: 'Mensalidade em dia', bloqueado: 'Loja bloqueada', pendente: 'Pagamento pendente', teste: 'Teste grátis' };
            const extra = assinatura.motivo === 'vencido' ? ' O prazo acabou e o cardápio público está pausado.' : '';
            resumoConta.textContent = 'Plano ' + (assinatura.plano || 'básico') + (assinatura.vencimento ? ' até ' + assinatura.vencimento : '') + ' — ' + (rotulos[assinatura.status] || assinatura.status) + extra;
        }
    }
    const linkSuporte = document.getElementById('conta-suporte');
    if (linkSuporte && assinatura && assinatura.suporte_whatsapp) {
        linkSuporte.href = 'https://wa.me/' + String(assinatura.suporte_whatsapp).replace(/\D/g, '');
        linkSuporte.style.display = 'inline-flex';
    }
    if (sessionStorage.getItem('admin_master') === '1') {
        const volta = document.getElementById('link-painel-master');
        if (volta) volta.style.display = 'block';
    }
    iniciarCobrancaOnline(assinatura);

    const faixas = document.getElementById('input-faixas');
    if (faixas) {
        faixas.value = (cfg.FRETE.faixas || []).map((f) => (f.ate_km || '') + '|' + (f.valor || 0)).join('\n');
        faixas.addEventListener('change', () => {
            cfg.FRETE.faixas = faixas.value.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => {
                const partes = l.split('|');
                return { ate_km: parseFloat(partes[0]) || 0, valor: parseFloat(String(partes[1] || '0').replace(',', '.')) || 0 };
            });
            ctx.salvar();
        });
    }
    const bairros = document.getElementById('input-bairros');
    if (bairros) {
        bairros.value = (cfg.FRETE.bairros || []).map((b) => (b.nome || '') + '|' + (b.valor || 0)).join('\n');
        bairros.addEventListener('change', () => {
            cfg.FRETE.bairros = bairros.value.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => {
                const partes = l.split('|');
                return { nome: (partes[0] || '').trim(), valor: parseFloat(String(partes[1] || '0').replace(',', '.')) || 0 };
            });
            ctx.salvar();
        });
    }

    function lerArquivoImagem(input, destino) {
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = () => {
                const img = new Image();
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const max = 900;
                    let w = img.width;
                    let h = img.height;
                    if (w > max) { h *= max / w; w = max; }
                    canvas.width = w;
                    canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    destino(canvas.toDataURL('image/jpeg', 0.72));
                    ctx.salvar();
                };
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    }
    const fileLogo = document.getElementById('file-logo');
    const fileFav = document.getElementById('file-favicon');
    const fileHero = document.getElementById('file-hero');
    if (fileLogo) lerArquivoImagem(fileLogo, (url) => { cfg.IDENTIDADE.LOGO = url; const campo = document.getElementById('input-logo'); if (campo) campo.value = '[imagem enviada]'; });
    if (fileFav) lerArquivoImagem(fileFav, (url) => { cfg.IDENTIDADE.FAVICON = url; });
    if (fileHero) lerArquivoImagem(fileHero, (url) => { cfg.IDENTIDADE.HERO = url; });

    const boxCat = document.getElementById('lista-categorias-admin');
    function desenharCategorias() {
        if (!boxCat || !cfg.CATEGORIAS) return;
        boxCat.innerHTML = Object.keys(cfg.CATEGORIAS).map((chave) => {
            const cat = cfg.CATEGORIAS[chave];
            const pagina = (cfg.TEXTOS.PAGINAS && cfg.TEXTOS.PAGINAS[chave]) || { titulo: '', subtitulo: '' };
            return '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-bottom:8px;">'
                + '<strong>' + chave + '</strong>'
                + '<div class="grade-admin" style="margin-top:8px;">'
                + '<input data-c="' + chave + '" data-f="nome" value="' + String(cat.nome || '').replace(/"/g, '&quot;') + '" placeholder="Nome">'
                + '<input data-c="' + chave + '" data-f="subtitulo" value="' + String(cat.subtitulo || '').replace(/"/g, '&quot;') + '" placeholder="Subtítulo do card">'
                + '<input data-c="' + chave + '" data-f="emoji" value="' + String(cat.emoji || '').replace(/"/g, '&quot;') + '" placeholder="Emoji">'
                + '<input data-c="' + chave + '" data-f="cor" type="color" value="' + (cat.cor || '#f97136') + '">'
                + '<label><input data-c="' + chave + '" data-f="ativo" type="checkbox" ' + (cat.ativo !== false ? 'checked' : '') + '> Visível</label>'
                + '<input data-c="' + chave + '" data-f="pagina-titulo" value="' + String(pagina.titulo || '').replace(/"/g, '&quot;') + '" placeholder="Título da página">'
                + '<input data-c="' + chave + '" data-f="pagina-sub" value="' + String(pagina.subtitulo || '').replace(/"/g, '&quot;') + '" placeholder="Subtítulo da página">'
                + '</div></div>';
        }).join('');
    }
    window.__pzMontarCategorias = desenharCategorias;
    desenharCategorias();
    if (boxCat) {
        boxCat.addEventListener('input', (e) => {
            const el = e.target;
            const chave = el.dataset.c;
            const campo = el.dataset.f;
            if (!chave || !cfg.CATEGORIAS[chave]) return;
            if (campo === 'ativo') cfg.CATEGORIAS[chave].ativo = el.checked;
            else if (campo === 'pagina-titulo' || campo === 'pagina-sub') {
                if (!cfg.TEXTOS.PAGINAS[chave]) cfg.TEXTOS.PAGINAS[chave] = { titulo: '', subtitulo: '', hero: '' };
                cfg.TEXTOS.PAGINAS[chave][campo === 'pagina-titulo' ? 'titulo' : 'subtitulo'] = el.value;
            } else cfg.CATEGORIAS[chave][campo] = campo === 'ativo' ? el.checked : el.value;
            ctx.salvar();
        });
        boxCat.addEventListener('change', (e) => {
            if (e.target.dataset.f === 'ativo') {
                cfg.CATEGORIAS[e.target.dataset.c].ativo = e.target.checked;
                ctx.salvar();
            }
        });
    }

    const boxAv = document.getElementById('lista-avaliacoes-admin');
    function desenharAvaliacoes() {
        if (!boxAv) return;
        boxAv.innerHTML = cfg.AVALIACOES.map((av, i) => {
            return '<div style="display:grid;gap:6px;margin-bottom:10px;border-bottom:1px solid #eee;padding-bottom:8px;">'
                + '<input data-av="' + i + '" data-f="nome" value="' + String(av.nome || '').replace(/"/g, '&quot;') + '" placeholder="Nome">'
                + '<textarea data-av="' + i + '" data-f="texto" rows="2" placeholder="Depoimento">' + String(av.texto || '') + '</textarea>'
                + '<input data-av="' + i + '" data-f="foto" value="' + String(av.foto || '').replace(/"/g, '&quot;') + '" placeholder="URL da foto">'
                + '<button type="button" data-rm-av="' + i + '">Remover</button></div>';
        }).join('');
    }
    desenharAvaliacoes();
    if (boxAv) {
        boxAv.addEventListener('input', (e) => {
            const i = e.target.dataset.av;
            if (i == null || !cfg.AVALIACOES[i]) return;
            cfg.AVALIACOES[i][e.target.dataset.f] = e.target.value;
            ctx.salvar();
        });
        boxAv.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-rm-av]');
            if (!btn) return;
            cfg.AVALIACOES.splice(Number(btn.dataset.rmAv), 1);
            desenharAvaliacoes();
            ctx.salvar();
        });
    }
    const addAv = document.getElementById('btn-add-avaliacao');
    if (addAv) addAv.addEventListener('click', () => {
        cfg.AVALIACOES.push({ nome: '', texto: '', foto: '' });
        desenharAvaliacoes();
        ctx.salvar();
    });

    bind('chk-atendimento-ativo', () => cfg.ATENDIMENTO.ativo !== false, (v) => { cfg.ATENDIMENTO.ativo = v; });
    bind('input-atd-titulo', () => cfg.ATENDIMENTO.titulo_pagina, (v) => { cfg.ATENDIMENTO.titulo_pagina = v; });
    bind('input-atd-instrucao', () => cfg.ATENDIMENTO.instrucao, (v) => { cfg.ATENDIMENTO.instrucao = v; });
    bind('input-atd-nome', () => cfg.ATENDIMENTO.label_nome, (v) => { cfg.ATENDIMENTO.label_nome = v; });
    bind('input-atd-tel', () => cfg.ATENDIMENTO.label_telefone, (v) => { cfg.ATENDIMENTO.label_telefone = v; });
    bind('input-atd-btn', () => cfg.ATENDIMENTO.btn_abrir_cardapio, (v) => { cfg.ATENDIMENTO.btn_abrir_cardapio = v; });
    bind('input-atd-banner', () => cfg.ATENDIMENTO.banner_cardapio, (v) => { cfg.ATENDIMENTO.banner_cardapio = v; });
    bind('input-atd-sucesso', () => cfg.ATENDIMENTO.texto_sucesso, (v) => { cfg.ATENDIMENTO.texto_sucesso = v; });
    bind('input-atd-entrega', () => cfg.ATENDIMENTO.rotulo_entrega_manual, (v) => { cfg.ATENDIMENTO.rotulo_entrega_manual = v; });
    bind('input-atd-badge', () => cfg.ATENDIMENTO.rotulo_badge_painel, (v) => { cfg.ATENDIMENTO.rotulo_badge_painel = v; });
    bind('input-atd-voltar', () => cfg.ATENDIMENTO.link_voltar_painel, (v) => { cfg.ATENDIMENTO.link_voltar_painel = v; });
    bind('input-atd-novo', () => cfg.ATENDIMENTO.link_novo_pedido, (v) => { cfg.ATENDIMENTO.link_novo_pedido = v; });
    bind('input-atd-btn-registrar', () => cfg.ATENDIMENTO.btn_confirmar_pedido, (v) => { cfg.ATENDIMENTO.btn_confirmar_pedido = v; });
    bind('input-atd-btn-zap', () => cfg.ATENDIMENTO.btn_confirmar_whatsapp, (v) => { cfg.ATENDIMENTO.btn_confirmar_whatsapp = v; });
    bind('input-atd-modo', () => cfg.ATENDIMENTO.modo_padrao || 'retirada', (v) => { cfg.ATENDIMENTO.modo_padrao = v; });
    bind('chk-atd-whatsapp', () => !!cfg.ATENDIMENTO.abrir_whatsapp, (v) => { cfg.ATENDIMENTO.abrir_whatsapp = v; });

    bind('input-cmd-via', () => cfg.COMANDA.titulo_via_cozinha, (v) => { cfg.COMANDA.titulo_via_cozinha = v; });
    bind('input-cmd-balcao', () => cfg.COMANDA.texto_canal_balcao, (v) => { cfg.COMANDA.texto_canal_balcao = v; });
    bind('input-cmd-rodape-cozinha', () => cfg.COMANDA.rodape_cozinha, (v) => { cfg.COMANDA.rodape_cozinha = v; });
    bind('input-cmd-rodape-completa', () => cfg.COMANDA.rodape_completa, (v) => { cfg.COMANDA.rodape_completa = v; });
    bind('input-cmd-instrucao', () => cfg.COMANDA.cozinha_instrucao, (v) => { cfg.COMANDA.cozinha_instrucao = v; });
    bind('input-cmd-btn-pronto', () => cfg.COMANDA.cozinha_btn_pronto, (v) => { cfg.COMANDA.cozinha_btn_pronto = v; });
    bind('input-cmd-btn-reimp', () => cfg.COMANDA.cozinha_btn_imprimir, (v) => { cfg.COMANDA.cozinha_btn_imprimir = v; });
    bind('input-cmd-painel-on', () => cfg.COMANDA.painel_imprimir_ligado, (v) => { cfg.COMANDA.painel_imprimir_ligado = v; });
    bind('input-cmd-painel-off', () => cfg.COMANDA.painel_imprimir_desligado, (v) => { cfg.COMANDA.painel_imprimir_desligado = v; });
    bind('chk-cmd-tel-cozinha', () => cfg.COMANDA.mostrar_telefone_cozinha !== false, (v) => { cfg.COMANDA.mostrar_telefone_cozinha = v; });
    bind('chk-cmd-pag-cozinha', () => !!cfg.COMANDA.mostrar_pagamento_cozinha, (v) => { cfg.COMANDA.mostrar_pagamento_cozinha = v; });
    bind('chk-cmd-painel-auto', () => cfg.COMANDA.painel_imprimir_auto !== false, (v) => { cfg.COMANDA.painel_imprimir_auto = v; });

    bind('input-rel-titulo', () => cfg.RELATORIO.titulo, (v) => { cfg.RELATORIO.titulo = v; aplicarRotulosRelatorio(); });
    bind('input-rel-sub', () => cfg.RELATORIO.subtitulo, (v) => { cfg.RELATORIO.subtitulo = v; aplicarRotulosRelatorio(); });
    bind('input-rel-mais', () => cfg.RELATORIO.rotulo_mais_vendidos, (v) => { cfg.RELATORIO.rotulo_mais_vendidos = v; aplicarRotulosRelatorio(); });
    bind('input-rel-menos', () => cfg.RELATORIO.rotulo_menos_vendidos, (v) => { cfg.RELATORIO.rotulo_menos_vendidos = v; aplicarRotulosRelatorio(); });
    bind('input-rel-tabela', () => cfg.RELATORIO.rotulo_tabela, (v) => { cfg.RELATORIO.rotulo_tabela = v; aplicarRotulosRelatorio(); });
    bind('input-rel-vazio', () => cfg.RELATORIO.vazio, (v) => { cfg.RELATORIO.vazio = v; });

    const linkAtd = document.getElementById('link-abrir-atendimento');
    if (linkAtd) linkAtd.href = adminPageUrl('atendimento.html');
    const linkAtdTopo = document.getElementById('link-atendimento');
    if (linkAtdTopo) linkAtdTopo.href = adminPageUrl('atendimento.html');

    const ABAS_TUTORIAL = [
        { id: '', rotulo: '— usar só o link —' },
        { id: 'identidade', rotulo: 'Minha loja' },
        { id: 'textos', rotulo: 'Frases' },
        { id: 'contato', rotulo: 'Endereço / WhatsApp' },
        { id: 'frete', rotulo: 'Entrega' },
        { id: 'pagamentos', rotulo: 'Pagamento' },
        { id: 'cardapio', rotulo: 'Cardápio' },
        { id: 'segmento', rotulo: 'Tipo de comida' },
        { id: 'relatorios', rotulo: 'Relatórios' },
        { id: 'comandas', rotulo: 'Comandas' },
        { id: 'atendimento', rotulo: 'Atendimento' },
        { id: 'tutorial', rotulo: 'Primeiros passos (edição)' },
        { id: 'conta', rotulo: 'Mensalidade' },
        { id: 'seguranca', rotulo: 'Senha' }
    ];

    function slugPassoTutorial(texto, fallback) {
        const base = String(texto || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '')
            .slice(0, 48);
        return base || fallback || 'passo';
    }

    function chaveProgressoTutorial() {
        return (typeof TENANT !== 'undefined' && TENANT.storageKey)
            ? TENANT.storageKey('tutorial_progress')
            : 'tutorial_progress';
    }

    function lerProgressoTutorial() {
        try {
            const raw = localStorage.getItem(chaveProgressoTutorial());
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

    function atualizarGuiaInicio() {
        const tut = cfg.TUTORIAL || {};
        const card = document.getElementById('card-guia-inicio');
        if (!card) return;
        const ativo = tut.ativo !== false;
        card.hidden = !ativo;
        if (!ativo) return;

        const elTit = document.getElementById('guia-titulo');
        const elSub = document.getElementById('guia-subtitulo');
        const elProg = document.getElementById('guia-progresso');
        const elLista = document.getElementById('guia-lista-resumo');
        const linkTut = document.getElementById('link-tutorial-interativo');

        if (elTit) elTit.innerHTML = '<i class="fa-solid fa-route"></i> ' + String(tut.titulo || 'Primeiros passos');
        if (elSub) elSub.textContent = tut.subtitulo || '';
        if (linkTut) {
            linkTut.href = adminPageUrl('tutorial.html');
            linkTut.textContent = tut.btn_abrir || 'Abrir tutorial passo a passo';
        }

        const passos = Array.isArray(tut.passos) ? tut.passos : [];
        const prog = lerProgressoTutorial();
        let feitos = 0;
        passos.forEach((p, i) => {
            const id = p.id || slugPassoTutorial(p.titulo, 'p' + i);
            if (prog[id]) feitos += 1;
        });
        const rotulo = tut.texto_progresso || 'concluídos';
        if (elProg) {
            elProg.textContent = passos.length
                ? (feitos + ' de ' + passos.length + ' ' + rotulo)
                : 'Configure os passos na aba Primeiros passos.';
        }
        if (elLista) {
            elLista.innerHTML = passos.map((p) =>
                '<li><strong>' + String(p.titulo || 'Passo').replace(/</g, '&lt;') + '</strong> — '
                + String(p.texto || '').replace(/</g, '&lt;') + '</li>'
            ).join('');
        }
    }

    const boxPassosTut = document.getElementById('lista-passos-tutorial');
    function desenharPassosTutorial() {
        if (!boxPassosTut) return;
        const passos = cfg.TUTORIAL.passos;
        boxPassosTut.innerHTML = passos.map((p, i) => {
            const opts = ABAS_TUTORIAL.map((a) => {
                const sel = (p.aba || '') === a.id ? ' selected' : '';
                return '<option value="' + a.id + '"' + sel + '>' + a.rotulo + '</option>';
            }).join('');
            return '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:10px;background:#f9fafb;">'
                + '<p style="margin:0 0 8px;font-size:0.85rem;color:#6b7280;">Passo ' + (i + 1) + ' · id: ' + String(p.id || '').replace(/</g, '&lt;') + '</p>'
                + '<input data-pt="' + i + '" data-f="titulo" value="' + String(p.titulo || '').replace(/"/g, '&quot;') + '" placeholder="Título" style="width:100%;margin-bottom:6px;padding:10px;border-radius:8px;border:1px solid #d1d5db;">'
                + '<textarea data-pt="' + i + '" data-f="texto" rows="2" placeholder="Explicação" style="width:100%;margin-bottom:6px;padding:10px;border-radius:8px;border:1px solid #d1d5db;">' + String(p.texto || '') + '</textarea>'
                + '<label style="display:block;margin-bottom:4px;font-weight:600;">Aba do painel</label>'
                + '<select data-pt="' + i + '" data-f="aba" style="width:100%;margin-bottom:6px;padding:10px;border-radius:8px;border:1px solid #d1d5db;">' + opts + '</select>'
                + '<input data-pt="' + i + '" data-f="link" value="' + String(p.link || '').replace(/"/g, '&quot;') + '" placeholder="Link (ex.: painel.html)" style="width:100%;margin-bottom:8px;padding:10px;border-radius:8px;border:1px solid #d1d5db;">'
                + '<button type="button" data-rm-pt="' + i + '" style="background:#ef4444;color:#fff;border:none;border-radius:8px;padding:8px 12px;cursor:pointer;">Remover passo</button>'
                + '</div>';
        }).join('');
        atualizarGuiaInicio();
    }
    desenharPassosTutorial();

    if (boxPassosTut) {
        boxPassosTut.addEventListener('input', (e) => {
            const i = e.target.dataset.pt;
            if (i == null || !cfg.TUTORIAL.passos[i]) return;
            const campo = e.target.dataset.f;
            cfg.TUTORIAL.passos[i][campo] = e.target.value;
            if (campo === 'titulo' && !cfg.TUTORIAL.passos[i].id) {
                cfg.TUTORIAL.passos[i].id = slugPassoTutorial(e.target.value, 'passo-' + i);
            }
            ctx.salvar();
            atualizarGuiaInicio();
        });
        boxPassosTut.addEventListener('change', (e) => {
            const i = e.target.dataset.pt;
            if (i == null || !cfg.TUTORIAL.passos[i] || e.target.dataset.f !== 'aba') return;
            cfg.TUTORIAL.passos[i].aba = e.target.value;
            ctx.salvar();
        });
        boxPassosTut.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-rm-pt]');
            if (!btn) return;
            cfg.TUTORIAL.passos.splice(Number(btn.dataset.rmPt), 1);
            desenharPassosTutorial();
            ctx.salvar();
        });
    }

    const addPasso = document.getElementById('btn-add-passo-tutorial');
    if (addPasso) addPasso.addEventListener('click', () => {
        const n = cfg.TUTORIAL.passos.length + 1;
        cfg.TUTORIAL.passos.push({
            id: 'novo-' + n,
            titulo: 'Novo passo ' + n,
            texto: '',
            aba: 'identidade',
            link: ''
        });
        desenharPassosTutorial();
        ctx.salvar();
    });

    bind('chk-tutorial-ativo', () => cfg.TUTORIAL.ativo !== false, (v) => { cfg.TUTORIAL.ativo = v; atualizarGuiaInicio(); });
    bind('input-tut-titulo', () => cfg.TUTORIAL.titulo, (v) => { cfg.TUTORIAL.titulo = v; atualizarGuiaInicio(); });
    bind('input-tut-sub', () => cfg.TUTORIAL.subtitulo, (v) => { cfg.TUTORIAL.subtitulo = v; atualizarGuiaInicio(); });
    bind('input-tut-intro', () => cfg.TUTORIAL.intro, (v) => { cfg.TUTORIAL.intro = v; });
    bind('input-tut-btn-abrir', () => cfg.TUTORIAL.btn_abrir, (v) => { cfg.TUTORIAL.btn_abrir = v; atualizarGuiaInicio(); });
    bind('input-tut-btn-ir', () => cfg.TUTORIAL.btn_ir_passo, (v) => { cfg.TUTORIAL.btn_ir_passo = v; });
    bind('input-tut-btn-marca', () => cfg.TUTORIAL.btn_marcar, (v) => { cfg.TUTORIAL.btn_marcar = v; });
    bind('input-tut-btn-desmarca', () => cfg.TUTORIAL.btn_desmarcar, (v) => { cfg.TUTORIAL.btn_desmarcar = v; });
    bind('input-tut-progresso', () => cfg.TUTORIAL.texto_progresso, (v) => { cfg.TUTORIAL.texto_progresso = v; atualizarGuiaInicio(); });
    bind('input-tut-video-rotulo', () => cfg.TUTORIAL.rotulo_video, (v) => { cfg.TUTORIAL.rotulo_video = v; });
    bind('input-tut-video', () => cfg.TUTORIAL.video_url, (v) => { cfg.TUTORIAL.video_url = v; });

    const linkPreviewTut = document.getElementById('link-preview-tutorial');
    if (linkPreviewTut) linkPreviewTut.href = adminPageUrl('tutorial.html');

    function aplicarSimplicidadeNaTela() {
        const sim = cfg.SIMPLICIDADE || {};
        const tut = cfg.TUTORIAL || {};
        const mapTexto = {
            'admin-titulo-principal': sim.titulo_painel,
            'admin-subtitulo-principal': sim.subtitulo_painel,
            'admin-dica-abas': sim.dica_abas,
            'sim-regra-1': sim.regra_1,
            'sim-regra-2': sim.regra_2,
            'sim-regra-3': sim.regra_3,
            'sim-lem-texto-dom': sim.lembrete_salvar
        };
        Object.keys(mapTexto).forEach((id) => {
            const el = document.getElementById(id);
            if (el && mapTexto[id]) el.textContent = mapTexto[id];
        });
        const lemTit = document.getElementById('sim-lem-titulo-dom');
        if (lemTit && sim.lembrete_salvar_titulo) {
            lemTit.innerHTML = '<i class="fa-solid fa-cloud-check"></i> ' + String(sim.lembrete_salvar_titulo).replace(/</g, '&lt;');
        }
        const paTitulo = tut.primeiro_acesso_titulo || 'Bem-vindo!';
        const elBvTit = document.getElementById('modal-bv-titulo');
        const elBvTxt = document.getElementById('modal-bv-texto');
        const elBvBtnT = document.getElementById('modal-bv-btn-tutorial');
        const elBvBtnD = document.getElementById('modal-bv-btn-depois');
        if (elBvTit) elBvTit.textContent = paTitulo;
        if (elBvTxt) elBvTxt.textContent = tut.primeiro_acesso_texto || '';
        if (elBvBtnT) {
            elBvBtnT.textContent = tut.primeiro_acesso_btn_tutorial || 'Abrir passo a passo';
            elBvBtnT.href = adminPageUrl('tutorial.html');
        }
        if (elBvBtnD) elBvBtnD.textContent = tut.primeiro_acesso_btn_depois || 'Agora não';
    }

    function chaveBoasVindas() {
        return (typeof TENANT !== 'undefined' && TENANT.storageKey)
            ? TENANT.storageKey('admin_boas_vindas_visto')
            : 'admin_boas_vindas_visto';
    }

    function fecharBoasVindas(marcarVisto) {
        const modal = document.getElementById('modal-boas-vindas');
        if (modal) modal.hidden = true;
        if (marcarVisto) {
            try { localStorage.setItem(chaveBoasVindas(), '1'); } catch (e) { /* */ }
        }
    }

    function tentarBoasVindas() {
        const modalNivel = document.getElementById('modal-nivel-boas-vindas');
        if (modalNivel && !modalNivel.hidden) return;
        const tut = cfg.TUTORIAL || {};
        if (tut.primeiro_acesso_ativo === false) return;
        try {
            if (localStorage.getItem(chaveBoasVindas()) === '1') return;
        } catch (e) { return; }
        const modal = document.getElementById('modal-boas-vindas');
        if (!modal) return;
        aplicarSimplicidadeNaTela();
        modal.hidden = false;
        const btnDepois = document.getElementById('modal-bv-btn-depois');
        if (btnDepois && !btnDepois.dataset.ouvinte) {
            btnDepois.dataset.ouvinte = '1';
            btnDepois.addEventListener('click', () => {
                fecharBoasVindas(true);
                const ir = window.__pzAtivarAbaAdmin || ativarAbaAdmin;
                if (typeof ir === 'function') ir('tutorial');
            });
        }
        const btnTut = document.getElementById('modal-bv-btn-tutorial');
        if (btnTut && !btnTut.dataset.ouvinte) {
            btnTut.dataset.ouvinte = '1';
            btnTut.addEventListener('click', () => fecharBoasVindas(true));
        }
    }

    aplicarSimplicidadeNaTela();

    bind('chk-primeiro-acesso', () => cfg.TUTORIAL.primeiro_acesso_ativo !== false, (v) => { cfg.TUTORIAL.primeiro_acesso_ativo = v; });
    bind('input-pa-titulo', () => cfg.TUTORIAL.primeiro_acesso_titulo, (v) => { cfg.TUTORIAL.primeiro_acesso_titulo = v; aplicarSimplicidadeNaTela(); });
    bind('input-pa-texto', () => cfg.TUTORIAL.primeiro_acesso_texto, (v) => { cfg.TUTORIAL.primeiro_acesso_texto = v; aplicarSimplicidadeNaTela(); });
    bind('input-pa-btn-tut', () => cfg.TUTORIAL.primeiro_acesso_btn_tutorial, (v) => { cfg.TUTORIAL.primeiro_acesso_btn_tutorial = v; aplicarSimplicidadeNaTela(); });
    bind('input-pa-btn-depois', () => cfg.TUTORIAL.primeiro_acesso_btn_depois, (v) => { cfg.TUTORIAL.primeiro_acesso_btn_depois = v; aplicarSimplicidadeNaTela(); });

    bind('input-sim-titulo', () => cfg.SIMPLICIDADE.titulo_painel, (v) => { cfg.SIMPLICIDADE.titulo_painel = v; aplicarSimplicidadeNaTela(); });
    bind('input-sim-sub', () => cfg.SIMPLICIDADE.subtitulo_painel, (v) => { cfg.SIMPLICIDADE.subtitulo_painel = v; aplicarSimplicidadeNaTela(); });
    bind('input-sim-abas', () => cfg.SIMPLICIDADE.dica_abas, (v) => { cfg.SIMPLICIDADE.dica_abas = v; aplicarSimplicidadeNaTela(); });
    bind('input-sim-regra1', () => cfg.SIMPLICIDADE.regra_1, (v) => { cfg.SIMPLICIDADE.regra_1 = v; aplicarSimplicidadeNaTela(); });
    bind('input-sim-regra2', () => cfg.SIMPLICIDADE.regra_2, (v) => { cfg.SIMPLICIDADE.regra_2 = v; aplicarSimplicidadeNaTela(); });
    bind('input-sim-regra3', () => cfg.SIMPLICIDADE.regra_3, (v) => { cfg.SIMPLICIDADE.regra_3 = v; aplicarSimplicidadeNaTela(); });
    bind('input-sim-lem-titulo', () => cfg.SIMPLICIDADE.lembrete_salvar_titulo, (v) => { cfg.SIMPLICIDADE.lembrete_salvar_titulo = v; aplicarSimplicidadeNaTela(); });
    bind('input-sim-lem-texto', () => cfg.SIMPLICIDADE.lembrete_salvar, (v) => { cfg.SIMPLICIDADE.lembrete_salvar = v; aplicarSimplicidadeNaTela(); });
    bind('input-sim-login-titulo', () => cfg.SIMPLICIDADE.login_titulo, (v) => { cfg.SIMPLICIDADE.login_titulo = v; });
    bind('input-sim-login-dica', () => cfg.SIMPLICIDADE.login_dica, (v) => { cfg.SIMPLICIDADE.login_dica = v; });
    bind('input-sim-login-btn', () => cfg.SIMPLICIDADE.login_btn, (v) => { cfg.SIMPLICIDADE.login_btn = v; });
    bind('input-sim-painel', () => (cfg.AJUDA.painel && cfg.AJUDA.painel.intro) || cfg.SIMPLICIDADE.painel_instrucao, (v) => {
        if (!cfg.AJUDA.painel) cfg.AJUDA.painel = {};
        cfg.AJUDA.painel.intro = v;
        cfg.SIMPLICIDADE.painel_instrucao = v;
    });

    function aplicarAjudaAdminAbas() {
        const mapa = (cfg.AJUDA && cfg.AJUDA.admin) ? cfg.AJUDA.admin : {};
        document.querySelectorAll('[data-ajuda-aba]').forEach((el) => {
            const chave = el.dataset.ajudaAba;
            if (chave && mapa[chave]) el.textContent = mapa[chave];
        });
    }

    const boxAjudaFerr = document.getElementById('lista-ajuda-painel-ferramentas');
    const boxAjudaCol = document.getElementById('lista-ajuda-painel-colunas');
    const boxAjudaCozFerr = document.getElementById('lista-ajuda-cozinha-ferramentas');
    const boxAjudaCozFichas = document.getElementById('lista-ajuda-cozinha-fichas');
    const boxAjudaCardPassos = document.getElementById('lista-ajuda-cardapio-passos');

    function desenharAjudaPainelListas() {
        const ferr = cfg.AJUDA.painel.ferramentas || [];
        const cols = cfg.AJUDA.painel.colunas || [];
        const cozFerr = cfg.AJUDA.cozinha.ferramentas || [];
        const cozFichas = cfg.AJUDA.cozinha.fichas || [];
        const cardPassos = cfg.AJUDA.cardapio.passos || [];
        if (boxAjudaFerr) {
            boxAjudaFerr.innerHTML = ferr.map((f, i) =>
                '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-bottom:8px;background:#f9fafb;">'
                + '<input data-af="' + i + '" data-f="titulo" value="' + String(f.titulo || '').replace(/"/g, '&quot;') + '" placeholder="Nome do botão" style="width:100%;margin-bottom:6px;padding:10px;">'
                + '<textarea data-af="' + i + '" data-f="texto" rows="2" placeholder="Explicação simples" style="width:100%;padding:10px;">' + String(f.texto || '') + '</textarea>'
                + '<p style="margin:6px 0 0;font-size:0.85rem;color:#6b7280;">id: ' + String(f.id || '') + '</p></div>'
            ).join('');
        }
        if (boxAjudaCol) {
            boxAjudaCol.innerHTML = cols.map((c, i) =>
                '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-bottom:8px;background:#f9fafb;">'
                + '<input data-ac="' + i + '" data-f="titulo" value="' + String(c.titulo || '').replace(/"/g, '&quot;') + '" placeholder="Nome da coluna" style="width:100%;margin-bottom:6px;padding:10px;">'
                + '<textarea data-ac="' + i + '" data-f="texto" rows="2" placeholder="O que fazer nesta coluna" style="width:100%;padding:10px;">' + String(c.texto || '') + '</textarea>'
                + '</div>'
            ).join('');
        }
        if (boxAjudaCozFerr) {
            boxAjudaCozFerr.innerHTML = cozFerr.map((f, i) =>
                '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-bottom:8px;background:#f0fdfa;">'
                + '<input data-cf="' + i + '" data-f="titulo" value="' + String(f.titulo || '').replace(/"/g, '&quot;') + '" style="width:100%;margin-bottom:6px;padding:10px;">'
                + '<textarea data-cf="' + i + '" data-f="texto" rows="2" style="width:100%;padding:10px;">' + String(f.texto || '') + '</textarea>'
                + '<p style="margin:6px 0 0;font-size:0.85rem;color:#6b7280;">id: ' + String(f.id || '') + '</p></div>'
            ).join('');
        }
        if (boxAjudaCozFichas) {
            boxAjudaCozFichas.innerHTML = cozFichas.map((f, i) =>
                '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-bottom:8px;background:#fff7ed;">'
                + '<input data-cz="' + i + '" data-f="titulo" value="' + String(f.titulo || '').replace(/"/g, '&quot;') + '" style="width:100%;margin-bottom:6px;padding:10px;">'
                + '<textarea data-cz="' + i + '" data-f="texto" rows="2" style="width:100%;padding:10px;">' + String(f.texto || '') + '</textarea></div>'
            ).join('');
        }
        if (boxAjudaCardPassos) {
            boxAjudaCardPassos.innerHTML = cardPassos.map((p, i) =>
                '<div style="border:1px solid #fed7aa;border-radius:10px;padding:10px;margin-bottom:8px;background:#fffbeb;">'
                + '<input data-cp="' + i + '" data-f="titulo" value="' + String(p.titulo || '').replace(/"/g, '&quot;') + '" style="width:100%;margin-bottom:6px;padding:10px;">'
                + '<textarea data-cp="' + i + '" data-f="texto" rows="2" style="width:100%;padding:10px;">' + String(p.texto || '') + '</textarea></div>'
            ).join('');
        }
        aplicarAjudaAdminAbas();
    }
    desenharAjudaPainelListas();

    if (boxAjudaFerr) {
        boxAjudaFerr.addEventListener('input', (e) => {
            const i = e.target.dataset.af;
            if (i == null || !cfg.AJUDA.painel.ferramentas[i]) return;
            cfg.AJUDA.painel.ferramentas[i][e.target.dataset.f] = e.target.value;
            ctx.salvar();
        });
    }
    if (boxAjudaCol) {
        boxAjudaCol.addEventListener('input', (e) => {
            const i = e.target.dataset.ac;
            if (i == null || !cfg.AJUDA.painel.colunas[i]) return;
            cfg.AJUDA.painel.colunas[i][e.target.dataset.f] = e.target.value;
            ctx.salvar();
        });
    }
    if (boxAjudaCozFerr) {
        boxAjudaCozFerr.addEventListener('input', (e) => {
            const i = e.target.dataset.cf;
            if (i == null || !cfg.AJUDA.cozinha.ferramentas[i]) return;
            cfg.AJUDA.cozinha.ferramentas[i][e.target.dataset.f] = e.target.value;
            ctx.salvar();
        });
    }
    if (boxAjudaCozFichas) {
        boxAjudaCozFichas.addEventListener('input', (e) => {
            const i = e.target.dataset.cz;
            if (i == null || !cfg.AJUDA.cozinha.fichas[i]) return;
            cfg.AJUDA.cozinha.fichas[i][e.target.dataset.f] = e.target.value;
            ctx.salvar();
        });
    }
    if (boxAjudaCardPassos) {
        boxAjudaCardPassos.addEventListener('input', (e) => {
            const i = e.target.dataset.cp;
            if (i == null || !cfg.AJUDA.cardapio.passos[i]) return;
            cfg.AJUDA.cardapio.passos[i][e.target.dataset.f] = e.target.value;
            ctx.salvar();
        });
    }

    bind('input-ajuda-cozinha-fichas-tit', () => cfg.AJUDA.cozinha.fichas_titulo || '', (v) => { cfg.AJUDA.cozinha.fichas_titulo = v; });
    bind('chk-ajuda-cardapio-ativo', () => cfg.AJUDA.cardapio.ativo !== false, (v) => { cfg.AJUDA.cardapio.ativo = v; });
    bind('input-ajuda-card-titulo', () => cfg.AJUDA.cardapio.titulo || '', (v) => { cfg.AJUDA.cardapio.titulo = v; });
    bind('input-ajuda-card-intro', () => cfg.AJUDA.cardapio.intro || '', (v) => { cfg.AJUDA.cardapio.intro = v; });
    bind('input-ajuda-card-carrinho', () => cfg.AJUDA.cardapio.carrinho_flutuante || '', (v) => { cfg.AJUDA.cardapio.carrinho_flutuante = v; });
    bind('input-ajuda-card-modal-car', () => cfg.AJUDA.cardapio.modal_carrinho || '', (v) => { cfg.AJUDA.cardapio.modal_carrinho = v; });
    bind('input-ajuda-card-modal-end', () => cfg.AJUDA.cardapio.modal_endereco || '', (v) => { cfg.AJUDA.cardapio.modal_endereco = v; });
    bind('input-ajuda-card-frete', () => cfg.AJUDA.cardapio.checkout_frete || '', (v) => { cfg.AJUDA.cardapio.checkout_frete = v; });
    bind('input-ajuda-card-final', () => cfg.AJUDA.cardapio.checkout_final || '', (v) => { cfg.AJUDA.cardapio.checkout_final = v; });

    bind('input-ajuda-cozinha-intro', () => (cfg.AJUDA.cozinha && cfg.AJUDA.cozinha.intro) || '', (v) => {
        if (!cfg.AJUDA.cozinha) cfg.AJUDA.cozinha = {};
        cfg.AJUDA.cozinha.intro = v;
    });
    bind('input-ajuda-entregador-intro', () => (cfg.AJUDA.entregador && cfg.AJUDA.entregador.intro) || '', (v) => {
        if (!cfg.AJUDA.entregador) cfg.AJUDA.entregador = {};
        cfg.AJUDA.entregador.intro = v;
    });
    bind('input-ajuda-atendimento-extra', () => (cfg.AJUDA.atendimento && cfg.AJUDA.atendimento.extra) || '', (v) => {
        if (!cfg.AJUDA.atendimento) cfg.AJUDA.atendimento = {};
        cfg.AJUDA.atendimento.extra = v;
    });

    function moedaRel(v) {
        return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ',');
    }

    function aplicarRotulosRelatorio() {
        const r = cfg.RELATORIO || {};
        const map = {
            'rel-titulo': r.titulo,
            'rel-subtitulo': r.subtitulo,
            'rel-rotulo-mais': r.rotulo_mais_vendidos,
            'rel-rotulo-menos': r.rotulo_menos_vendidos,
            'rel-rotulo-tabela': r.rotulo_tabela,
            'rel-col-item': r.coluna_item,
            'rel-col-qtd': r.coluna_qtd,
            'rel-col-valor': r.coluna_valor,
            'rel-vazio': r.vazio
        };
        Object.keys(map).forEach((id) => {
            const el = document.getElementById(id);
            if (el && map[id]) el.textContent = map[id];
        });
    }
    aplicarRotulosRelatorio();

    const inputMes = document.getElementById('input-relatorio-mes');
    if (inputMes && !inputMes.value) {
        const d = new Date();
        inputMes.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    }

    function desenharListaRel(id, lista) {
        const el = document.getElementById(id);
        if (!el) return;
        if (!lista || !lista.length) {
            el.innerHTML = '<p style="color:#6b7280;margin:0;">—</p>';
            return;
        }
        el.innerHTML = '<ol style="margin:0;padding-left:1.2rem;">' + lista.map((item) =>
            '<li style="margin-bottom:6px;"><strong>' + escaparRel(String(item.nome)) + '</strong> — ' + item.quantidade + ' un.</li>'
        ).join('') + '</ol>';
    }

    function escaparRel(t) {
        return t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    async function carregarRelatorioVendas() {
        const user = sessionStorage.getItem('admin_user') || '';
        const pass = sessionStorage.getItem('admin_pass') || '';
        const mes = (inputMes && inputMes.value) || new Date().toISOString().slice(0, 7);
        const base = (typeof TENANT !== 'undefined' && TENANT.apiUrl) ? TENANT.apiUrl('relatorio_vendas.php') : 'relatorio_vendas.php';
        const url = base + '?user=' + encodeURIComponent(user) + '&pass=' + encodeURIComponent(pass) + '&mes=' + encodeURIComponent(mes)
            + ((typeof TENANT !== 'undefined' && TENANT.slug && TENANT.slug !== 'default') ? '&tenant=' + encodeURIComponent(TENANT.slug) : '');
        try {
            const res = await fetch(url);
            const data = await res.json();
            if (!data.success) {
                ctx.toast(data.message || 'Não foi possível carregar o relatório.', 'error');
                return;
            }
            const resumo = document.getElementById('rel-resumo');
            if (resumo && data.resumo) {
                resumo.innerHTML = '<div><small>Pedidos</small><strong style="display:block;font-size:1.4em;">' + data.resumo.pedidos + '</strong></div>'
                    + '<div><small>Itens</small><strong style="display:block;font-size:1.4em;">' + data.resumo.itens + '</strong></div>'
                    + '<div><small>Faturamento</small><strong style="display:block;font-size:1.4em;">' + moedaRel(data.resumo.faturamento) + '</strong></div>';
            }
            desenharListaRel('rel-mais', data.mais_vendidos);
            desenharListaRel('rel-menos', data.menos_vendidos);
            const corpo = document.getElementById('rel-corpo');
            const vazio = document.getElementById('rel-vazio');
            const tabela = document.getElementById('rel-tabela');
            if (!data.itens || !data.itens.length) {
                if (corpo) corpo.innerHTML = '';
                if (tabela) tabela.style.display = 'none';
                if (vazio) { vazio.style.display = 'block'; vazio.textContent = (cfg.RELATORIO.vazio || ''); }
                return;
            }
            if (tabela) tabela.style.display = '';
            if (vazio) vazio.style.display = 'none';
            if (corpo) {
                corpo.innerHTML = data.itens.map((item) =>
                    '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' + escaparRel(item.nome) + '</td>'
                    + '<td style="padding:8px;text-align:right;border-bottom:1px solid #eee;">' + item.quantidade + '</td>'
                    + '<td style="padding:8px;text-align:right;border-bottom:1px solid #eee;">' + moedaRel(item.faturamento) + '</td></tr>'
                ).join('');
            }
        } catch (e) {
            ctx.toast('Erro de conexão ao carregar relatório.', 'error');
        }
    }

    const btnRel = document.getElementById('btn-carregar-relatorio');
    if (btnRel) btnRel.addEventListener('click', carregarRelatorioVendas);

    function renderizarPainelExpert() {
        const grid = document.getElementById('expert-resumo-grid');
        const txtCfg = document.getElementById('expert-json-config');
        const txtCard = document.getElementById('expert-json-cardapio');
        if (!grid && !txtCfg && !txtCard) return;

        const c = ctx.config || {};
        const end = c.ENDERECO || {};
        const f = c.FRETE || {};
        const slug = ctx.tenant || 'default';
        const base = window.location.origin + window.location.pathname.replace(/[^/]*$/, '');
        const items = [
            { k: 'Tenant Slug', v: slug },
            { k: 'Nome da Empresa', v: c.NOME_LOJA || '—' },
            { k: 'CNPJ / Razão Social', v: (c.CNPJ || 'Não informado') + (c.RAZAO_SOCIAL ? ' · ' + c.RAZAO_SOCIAL : '') },
            { k: 'WhatsApp / Segmento', v: (c.WHATSAPP_NUMBER || '—') + ' · ' + (c.NICHO || 'lanchonete') },
            { k: 'Endereço Completo', v: [end.rua, end.numero, end.bairro, end.cidade, end.estado, end.cep].filter(Boolean).join(', ') || 'Não cadastrado' },
            { k: 'Coordenadas GPS & Raio', v: (end.lat != null ? end.lat : '0') + ', ' + (end.lng != null ? end.lng : '0') + ' · Raio: ' + (f.raio_max_km || 10) + ' km' },
            { k: 'Vitrine Pública', v: slug === 'default' ? base + 'index.html' : base + 'loja/' + encodeURIComponent(slug) + '/' },
            { k: 'Endpoints Principais', v: 'get_public_store.php?tenant=' + slug + ' | save_data.php?tenant=' + slug + ' | agente_api.php?tenant=' + slug }
        ];
        if (grid) {
            grid.innerHTML = items.map((it) =>
                '<div class="pz-expert-kv"><strong>' + String(it.k).replace(/</g, '&lt;') + '</strong><span>' + String(it.v).replace(/</g, '&lt;') + '</span></div>'
            ).join('');
        }
        if (txtCfg) txtCfg.value = JSON.stringify(ctx.config || {}, null, 2);
        if (txtCard) txtCard.value = JSON.stringify(ctx.getCardapio() || {}, null, 2);
    }

    window.__pzRenderPainelExpert = renderizarPainelExpert;

    (function vincularAcoesExpert() {
        document.getElementById('btn-expert-recarregar')?.addEventListener('click', () => {
            renderizarPainelExpert();
            ctx.toast('JSON atualizado com os dados atuais do painel.', 'success');
        });
        document.getElementById('btn-expert-aplicar')?.addEventListener('click', () => {
            try {
                const txtCfg = document.getElementById('expert-json-config');
                const txtCard = document.getElementById('expert-json-cardapio');
                if (txtCfg && txtCfg.value.trim()) {
                    const parsedCfg = JSON.parse(txtCfg.value);
                    if (!parsedCfg || typeof parsedCfg !== 'object' || Array.isArray(parsedCfg)) {
                        throw new Error('config.json precisa ser um objeto JSON válido.');
                    }
                    ctx.config = parsedCfg;
                }
                if (txtCard && txtCard.value.trim()) {
                    const parsedCard = JSON.parse(txtCard.value);
                    if (!parsedCard || typeof parsedCard !== 'object' || Array.isArray(parsedCard)) {
                        throw new Error('cardapio.json precisa ser um objeto JSON válido.');
                    }
                    ctx.setCardapio(parsedCard);
                }
                ctx.salvar();
                if (typeof window.__pzRenderEditor === 'function') window.__pzRenderEditor();
                if (typeof window.__pzMontarCategorias === 'function') window.__pzMontarCategorias();
                renderizarPainelExpert();
                ctx.toast('JSON aplicado! Clique no botão verde Salvar no topo para gravar no servidor.', 'success');
            } catch (err) {
                ctx.toast('Erro no JSON: ' + (err.message || 'sintaxe inválida.'), 'error');
            }
        });
        document.getElementById('btn-expert-baixar')?.addEventListener('click', () => {
            const pacote = {
                exported_at: new Date().toISOString(),
                tenant: ctx.tenant,
                config: ctx.config,
                cardapio: ctx.getCardapio()
            };
            const blob = new Blob([JSON.stringify(pacote, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'expert-' + (ctx.tenant || 'loja') + '.json';
            a.click();
            URL.revokeObjectURL(url);
        });
        document.getElementById('btn-expert-copiar-api')?.addEventListener('click', () => {
            const slug = encodeURIComponent(ctx.tenant || 'default');
            const base = window.location.origin + window.location.pathname.replace(/[^/]*$/, '');
            const texto = [
                'Loja Pública: ' + base + 'loja/' + slug + '/',
                'GET Config/Cardápio: ' + base + 'get_public_store.php?tenant=' + slug,
                'POST Salvar Dados: ' + base + 'save_data.php?tenant=' + slug,
                'POST Agente IA: ' + base + 'agente_api.php?tenant=' + slug
            ].join('\n');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(texto).then(() => ctx.toast('Endpoints copiados!', 'success'));
            }
        });
    })();

    function ativarAbaAdmin(tab) {
        if (!tab) return;
        const btn = document.querySelector('#admin-tabs [data-tab="' + tab + '"]');
        if (!btn) return;
        document.querySelectorAll('#admin-tabs button').forEach((b) => b.classList.toggle('ativo', b === btn));
        document.querySelectorAll('[data-painel]').forEach((sec) => {
            sec.hidden = sec.dataset.painel !== tab;
        });
        if (tab === 'relatorios') carregarRelatorioVendas();
        if (tab === 'expert-dados') renderizarPainelExpert();
        if (window.FoodGestaoAdmin && window.FoodGestaoAdmin.aoAtivarAba) {
            window.FoodGestaoAdmin.aoAtivarAba(tab);
        }
    }

    (function vincularConfigAgenteIA() {
        if (!cfg.AGENTE_IA) cfg.AGENTE_IA = {};
        const elNome = document.getElementById('input-agente-nome');
        const elMargem = document.getElementById('input-agente-margem');
        const elVitrine = document.getElementById('chk-agente-vitrine');
        if (elNome) {
            elNome.value = cfg.AGENTE_IA.nome || 'Copiloto Food IA';
            elNome.addEventListener('input', () => { cfg.AGENTE_IA.nome = elNome.value.trim() || 'Copiloto Food IA'; });
        }
        if (elMargem) {
            elMargem.value = cfg.AGENTE_IA.alerta_margem_minima != null ? cfg.AGENTE_IA.alerta_margem_minima : 25;
            elMargem.addEventListener('input', () => { cfg.AGENTE_IA.alerta_margem_minima = parseFloat(elMargem.value) || 25; });
        }
        if (elVitrine) {
            elVitrine.checked = cfg.AGENTE_IA.vitrine_ativo !== false;
            elVitrine.addEventListener('change', () => { cfg.AGENTE_IA.vitrine_ativo = !!elVitrine.checked; });
        }
    })();

    window.__pzAtivarAbaAdmin = ativarAbaAdmin;

    document.querySelectorAll('#admin-tabs button').forEach((btn) => {
        btn.addEventListener('click', () => {
            ativarAbaAdmin(btn.dataset.tab);
            if (typeof window.gravarEstadoAdminUi === 'function') window.gravarEstadoAdminUi();
        });
    });

    try {
        const tabUrl = new URLSearchParams(window.location.search).get('tab');
        if (tabUrl) ativarAbaAdmin(tabUrl);
        else if (typeof window.restaurarEstadoAdminUi === 'function') window.restaurarEstadoAdminUi();
    } catch (e) { /* URL inválida */ }

    (function tentarEscolhaNivelInicial() {
        const slug = ctx.tenant || 'default';
        const chaveConf = slug + '_nivel_confirmado';
        let confLocal = false;
        try { confLocal = localStorage.getItem(chaveConf) === '1'; } catch (e) { /* */ }
        if (window.PedidoZapModoAdmin && typeof window.PedidoZapModoAdmin.set === 'function') {
            let salvo = cfg.NIVEL_EXPERIENCIA || 'basico';
            try { salvo = localStorage.getItem(slug + '_modo_admin') || salvo; } catch (e) { /* */ }
            window.PedidoZapModoAdmin.set(salvo, { silenciarConfig: true, naoMarcarConfirmado: true });
        }
        if (!confLocal && !cfg.NIVEL_EXPERIENCIA_CONFIRMADO) {
            const modalNivel = document.getElementById('modal-nivel-boas-vindas');
            if (modalNivel) modalNivel.hidden = false;
        }
    })();

    tentarBoasVindas();

    async function restaurar(tipo) {
        const confirmacao = prompt('Digite ZERAR para confirmar: ' + tipo);
        if (confirmacao !== 'ZERAR') return;
        const saveUrl = (typeof TENANT !== 'undefined' && TENANT.apiUrl) ? TENANT.apiUrl('save_data.php') : 'save_data.php';
        const response = await fetch(saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                auth: { user: ctx.credenciais.user, pass: ctx.credenciais.pass },
                tenant: ctx.tenant,
                reset: tipo
            })
        });
        const result = await response.json();
        ctx.toast(result.success ? 'Restauração concluída.' : (result.message || 'Falha ao restaurar.'), result.success ? 'success' : 'error');
        if (result.success) setTimeout(() => location.reload(), 700);
    }
    const mapReset = { 'btn-reset-cardapio': 'cardapio', 'btn-reset-pedidos': 'pedidos', 'btn-reset-config': 'config' };
    Object.keys(mapReset).forEach((id) => {
        const btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', () => restaurar(mapReset[id]));
    });
}
