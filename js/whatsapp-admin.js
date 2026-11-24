/**
 * Controlador compacto de WhatsApp + IA Ao Vivo para o painel da loja (admin.html)
 */
(function () {
    'use strict';

    let conversasCache = [];
    let chaveSelecionada = '';
    let carregandoStatus = false;

    function el(id) {
        return document.getElementById(id);
    }

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function credenciais() {
        return {
            user: sessionStorage.getItem('admin_user') || '',
            pass: sessionStorage.getItem('admin_pass') || ''
        };
    }

    function wppUrl() {
        if (typeof TENANT !== 'undefined' && typeof TENANT.apiUrl === 'function') {
            return TENANT.apiUrl('whatsapp_api.php');
        }
        return 'whatsapp_api.php';
    }

    function avisar(msg, erro) {
        const box = el('wpp-feedback');
        if (!box) return;
        box.textContent = msg;
        box.style.background = erro ? '#fef2f2' : '#ecfdf5';
        box.style.color = erro ? '#991b1b' : '#14532d';
        box.hidden = false;
        clearTimeout(box._t);
        box._t = setTimeout(function () { box.hidden = true; }, 5000);
    }

    async function apiWpp(corpo) {
        const auth = credenciais();
        if (!auth.user || !auth.pass) return { success: false, message: 'Faça login primeiro.' };
        const res = await fetch(wppUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({
                auth: auth,
                tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default'
            }, corpo || {}))
        });
        return res.json();
    }

    function preencherConfig(cfg, statusConn) {
        if (cfg) {
            if (el('wpp-chk-ia')) el('wpp-chk-ia').checked = !!cfg.ia_auto_responder;
            if (el('wpp-chk-notif')) el('wpp-chk-notif').checked = !!cfg.notificar_pedidos;
            if (el('wpp-provider')) el('wpp-provider').value = cfg.provider || 'plataforma';
            if (el('wpp-instance')) el('wpp-instance').value = cfg.instance || '';
            if (el('wpp-api-url')) el('wpp-api-url').value = cfg.api_url || '';
            if (el('wpp-phone-id')) el('wpp-phone-id').value = cfg.phone_id || '';
            if (el('wpp-saudacao')) el('wpp-saudacao').value = cfg.saudacao_personalizada || '';
            if (el('wpp-api-key')) {
                el('wpp-api-key').placeholder = cfg.api_key_configurada
                    ? 'Token salvo. Deixe em branco para manter.'
                    : 'Deixe vazio para usar da plataforma';
            }
            if (el('wpp-webhook-url')) {
                el('wpp-webhook-url').textContent = cfg.webhook_url || (window.location.origin + '/whatsapp_webhook.php');
            }
        }
        const pill = el('wpp-status-pill');
        if (pill && statusConn) {
            pill.textContent = statusConn.rotulo || (statusConn.conectado ? 'Conectado' : 'Modo Teste IA');
            if (statusConn.conectado) {
                pill.style.background = '#dcfce7';
                pill.style.color = '#166534';
            } else if (statusConn.estado === 'modo_teste') {
                pill.style.background = '#fff7ed';
                pill.style.color = '#9a3412';
            } else {
                pill.style.background = '#e0f2fe';
                pill.style.color = '#075985';
            }
        }
    }

    function renderizarMensagens() {
        const box = el('wpp-chat-mensagens');
        const titulo = el('wpp-chat-titulo');
        const btnPausa = el('wpp-btn-pausar-ia');
        if (!box) return;

        const ativa = conversasCache.find(function (c) { return c.chave === chaveSelecionada; }) || conversasCache[0];
        if (!ativa) {
            if (titulo) titulo.textContent = 'Nenhuma conversa ainda';
            if (btnPausa) btnPausa.hidden = true;
            box.innerHTML = '<small style="color:#64748b;">Clique em "Testar IA agora" para simular um cliente perguntando no WhatsApp ou conecte seu número.</small>';
            return;
        }
        chaveSelecionada = ativa.chave;
        if (titulo) {
            titulo.textContent = (ativa.nome || 'Cliente') + ' (' + (ativa.telefone || '') + ')';
        }
        if (btnPausa) {
            btnPausa.hidden = false;
            btnPausa.textContent = ativa.ia_pausada ? '🤖 Reativar IA nesta conversa' : '🙋 Pausar IA (responder manual)';
            btnPausa.style.background = ativa.ia_pausada ? '#15803d' : '#475569';
            btnPausa.style.color = '#fff';
        }

        const msgs = Array.isArray(ativa.mensagens) ? ativa.mensagens : [];
        box.innerHTML = msgs.map(function (m) {
            const entrada = m.direcao === 'entrada';
            const tagAutor = entrada ? 'Cliente' : (m.autor === 'ia' ? '🤖 IA AcheIAki' : (m.autor === 'sistema' ? '📦 Pedido' : '👤 Loja'));
            const align = entrada ? 'flex-start' : 'flex-end';
            const bg = entrada ? '#ffffff' : (m.autor === 'ia' ? '#dcf8c6' : '#ffedd5');
            const hora = (m.criado_em || '').slice(11, 16);
            return '<div style="align-self:' + align + ';max-width:88%;background:' + bg + ';padding:6px 9px;border-radius:9px;box-shadow:0 1px 1px rgba(0,0,0,0.08);">'
                + '<div style="font-size:0.7rem;font-weight:700;color:#475569;margin-bottom:2px;">' + esc(tagAutor) + (hora ? ' · ' + esc(hora) : '') + '</div>'
                + '<div style="white-space:pre-wrap;line-height:1.35;color:#111827;">' + esc(m.texto) + '</div>'
                + '</div>';
        }).join('');
        box.scrollTop = box.scrollHeight;
    }

    function renderizarConversas(lista) {
        conversasCache = Array.isArray(lista) ? lista : [];
        const box = el('wpp-lista-conversas');
        if (!box) return;
        if (!conversasCache.length) {
            box.innerHTML = '<small style="color:#64748b;padding:8px;">Nenhuma conversa ainda. Toque em "Testar IA agora" ou conecte seu WhatsApp.</small>';
            renderizarMensagens();
            return;
        }
        if (!chaveSelecionada || !conversasCache.some(function (c) { return c.chave === chaveSelecionada; })) {
            chaveSelecionada = conversasCache[0].chave;
        }
        box.innerHTML = conversasCache.map(function (c) {
            const ativo = c.chave === chaveSelecionada;
            const badge = c.ia_pausada ? '🙋 Manual' : '🤖 IA';
            return '<button type="button" data-wpp-chave="' + esc(c.chave) + '" style="text-align:left;padding:7px 9px;border-radius:8px;border:1px solid ' + (ativo ? '#15803d' : '#e2e8f0') + ';background:' + (ativo ? '#ecfdf5' : '#fff') + ';cursor:pointer;">'
                + '<div style="display:flex;justify-content:space-between;gap:4px;font-size:0.8rem;font-weight:700;color:#1e293b;">'
                + '<span>' + esc(c.nome || c.telefone) + '</span>'
                + '<span style="font-size:0.68rem;color:#15803d;">' + badge + '</span>'
                + '</div>'
                + '<div style="font-size:0.74rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(c.ultima_mensagem || '') + '</div>'
                + '</button>';
        }).join('');

        box.querySelectorAll('[data-wpp-chave]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                chaveSelecionada = btn.getAttribute('data-wpp-chave') || '';
                renderizarConversas(conversasCache);
            });
        });
        renderizarMensagens();
    }

    async function carregarTudo(verificarApi) {
        if (carregandoStatus) return;
        const auth = credenciais();
        if (!auth.user || !auth.pass) return;
        carregandoStatus = true;
        try {
            const data = await apiWpp({ acao: 'status', verificar_conexao: !!verificarApi });
            if (data && data.success) {
                preencherConfig(data.config, data.status_conexao);
                renderizarConversas(data.conversas);
            }
        } catch (e) {
            /* offline */
        } finally {
            carregandoStatus = false;
        }
    }

    function ligarEventos() {
        if (!el('wpp-aovivo-card')) return;

        const btnToggle = el('wpp-btn-toggle-api');
        if (btnToggle) {
            btnToggle.addEventListener('click', function () {
                const av = el('wpp-api-avancado');
                if (av) av.hidden = !av.hidden;
            });
        }

        const btnSalvar = el('wpp-btn-salvar');
        if (btnSalvar) {
            btnSalvar.addEventListener('click', async function () {
                btnSalvar.disabled = true;
                try {
                    const patch = {
                        ia_auto_responder: !!(el('wpp-chk-ia') && el('wpp-chk-ia').checked),
                        notificar_pedidos: !!(el('wpp-chk-notif') && el('wpp-chk-notif').checked),
                        provider: (el('wpp-provider') || {}).value || 'plataforma',
                        instance: ((el('wpp-instance') || {}).value || '').trim(),
                        api_url: ((el('wpp-api-url') || {}).value || '').trim(),
                        phone_id: ((el('wpp-phone-id') || {}).value || '').trim(),
                        saudacao_personalizada: ((el('wpp-saudacao') || {}).value || '').trim()
                    };
                    const k = ((el('wpp-api-key') || {}).value || '').trim();
                    if (k) patch.api_key = k;
                    const r = await apiWpp({ acao: 'salvar_config', config: patch });
                    if (el('wpp-api-key')) el('wpp-api-key').value = '';
                    if (r.config) preencherConfig(r.config, r.status_conexao);
                    avisar(r.message || (r.success ? 'Salvo!' : 'Erro ao salvar.'), !r.success);
                } finally {
                    btnSalvar.disabled = false;
                }
            });
        }

        const btnQr = el('wpp-btn-qr');
        if (btnQr) {
            btnQr.addEventListener('click', async function () {
                btnQr.disabled = true;
                const box = el('wpp-qr-box');
                const msg = el('wpp-qr-msg');
                const img = el('wpp-qr-img');
                const code = el('wpp-pairing-code');
                if (box) box.hidden = false;
                if (msg) msg.textContent = 'Gerando QR Code do WhatsApp...';
                try {
                    const r = await apiWpp({ acao: 'gerar_qr' });
                    if (msg) msg.textContent = r.message || '';
                    if (img) {
                        if (r.qrcode_base64) {
                            img.src = r.qrcode_base64.indexOf('data:') === 0 ? r.qrcode_base64 : ('data:image/png;base64,' + r.qrcode_base64);
                            img.style.display = 'block';
                        } else {
                            img.style.display = 'none';
                        }
                    }
                    if (code) {
                        code.textContent = r.pairing_code ? ('Código: ' + r.pairing_code) : '';
                    }
                    if (r.conectado) carregarTudo(true);
                } finally {
                    btnQr.disabled = false;
                }
            });
        }

        const btnTestar = el('wpp-btn-testar');
        if (btnTestar) {
            btnTestar.addEventListener('click', async function () {
                const pergunta = prompt('Simule a pergunta de um cliente no WhatsApp da sua loja:', 'Oi! O que você recomenda de mais em conta hoje e qual o horário?');
                if (pergunta === null) return;
                btnTestar.disabled = true;
                try {
                    const telLoja = ((el('input-whatsapp') || {}).value || '').replace(/\D/g, '') || '5521999990001';
                    const r = await apiWpp({
                        acao: 'simular_cliente',
                        telefone: telLoja,
                        nome: 'Cliente Simulação',
                        mensagem: pergunta
                    });
                    if (r.conversas) {
                        if (r.conversa && r.conversa.chave) chaveSelecionada = r.conversa.chave;
                        renderizarConversas(r.conversas);
                    }
                    avisar(r.success ? 'IA respondeu ao vivo na conversa abaixo!' : (r.message || 'Erro no teste.'), !r.success);
                } finally {
                    btnTestar.disabled = false;
                }
            });
        }

        const btnChatRefresh = el('wpp-btn-atualizar-chat');
        if (btnChatRefresh) {
            btnChatRefresh.addEventListener('click', function () { carregarTudo(true); });
        }

        const btnPausa = el('wpp-btn-pausar-ia');
        if (btnPausa) {
            btnPausa.addEventListener('click', async function () {
                const ativa = conversasCache.find(function (c) { return c.chave === chaveSelecionada; });
                if (!ativa) return;
                const r = await apiWpp({
                    acao: 'alternar_ia',
                    telefone: ativa.telefone,
                    ia_ativa: !!ativa.ia_pausada
                });
                if (r.conversas) renderizarConversas(r.conversas);
            });
        }

        const enviarManual = async function () {
            const inp = el('wpp-reply-input');
            const ativa = conversasCache.find(function (c) { return c.chave === chaveSelecionada; });
            if (!inp || !ativa) return;
            const txt = inp.value.trim();
            if (!txt) return;
            inp.disabled = true;
            try {
                const r = await apiWpp({
                    acao: 'enviar_mensagem',
                    telefone: ativa.telefone,
                    nome: ativa.nome,
                    mensagem: txt,
                    pausar_ia: true
                });
                if (r.success) inp.value = '';
                if (r.conversas) renderizarConversas(r.conversas);
                avisar(r.message || '', !r.success);
            } finally {
                inp.disabled = false;
                inp.focus();
            }
        };

        if (el('wpp-reply-btn')) el('wpp-reply-btn').addEventListener('click', enviarManual);
        if (el('wpp-reply-input')) {
            el('wpp-reply-input').addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    enviarManual();
                }
            });
        }

        document.querySelectorAll('#admin-tabs [data-tab="contato"]').forEach(function (tabBtn) {
            tabBtn.addEventListener('click', function () {
                setTimeout(function () { carregarTudo(false); }, 60);
            });
        });

        // Assina atualizações ao vivo via SSE da plataforma
        if (window.AcheIAkiRealtime && typeof window.AcheIAkiRealtime.escutar === 'function') {
            try {
                window.AcheIAkiRealtime.escutar(function (evento) {
                    if (evento && evento.event === 'wpp_mensagem') {
                        carregarTudo(false);
                    }
                });
            } catch (e) { /* silencioso */ }
        }
    }

    function aguardarAuth() {
        ligarEventos();
        let tentativas = 0;
        const timer = setInterval(function () {
            tentativas++;
            const a = credenciais();
            if (a.user && a.pass) {
                clearInterval(timer);
                carregarTudo(false);
            } else if (tentativas > 60) {
                clearInterval(timer);
            }
        }, 400);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', aguardarAuth);
    } else {
        aguardarAuth();
    }
})();
