/**
 * Atendente Virtual AcheIAki da Vitrine Pública (index.html e categorias)
 * Tira dúvidas sobre cardápio, preços, entregas, horários e cupons com dados reais da loja
 * e recomendações personalizadas pelo perfil LGPD do visitante.
 */
(function (global) {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtMd(s) {
        return esc(s)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }

    function montarVitrineIA() {
        const pagina = document.body && document.body.dataset.pagina;
        const paginasVitrine = ['home', 'categoria', 'acai', 'bebidas', 'combos', 'hamburgueres', 'porcoes'];
        if (pagina && paginasVitrine.indexOf(pagina) === -1) return;
        const cfg = (global.APP_CONFIG && global.APP_CONFIG.AGENTE_IA) || {};
        if (cfg.vitrine_ativo === false) return;
        if (document.getElementById('pz-vitrine-ia-fab')) return;

        const nomeBot = cfg.nome || 'AcheIAki · Atendente IA';

        const fab = document.createElement('button');
        fab.id = 'pz-vitrine-ia-fab';
        fab.type = 'button';
        fab.setAttribute('aria-expanded', 'false');
        fab.innerHTML = '<i class="fa-solid fa-robot"></i> Dúvidas? IA';

        const box = document.createElement('div');
        box.id = 'pz-vitrine-ia-box';
        box.hidden = true;
        box.innerHTML = ''
            + '<div class="pz-assist-topo">'
            + '<div><h2>' + esc(nomeBot) + '</h2><p>Recomendações, preços, entrega, horário e cupons</p></div>'
            + '<button type="button" class="pz-assist-fechar" aria-label="Fechar">×</button>'
            + '</div>'
            + '<div class="pz-assist-mensagens" id="pz-vit-msgs"></div>'
            + '<div class="pz-assist-atalhos" id="pz-vit-atalhos"></div>'
            + '<div class="pz-assist-entrada">'
            + '<input type="text" id="pz-vit-input" placeholder="Ex.: o que você recomenda ou opções baratas?" autocomplete="off">'
            + '<button type="button" id="pz-vit-enviar">→</button>'
            + '</div>';

        document.body.appendChild(fab);
        document.body.appendChild(box);

        const msgs = document.getElementById('pz-vit-msgs');
        const atalhos = document.getElementById('pz-vit-atalhos');
        const input = document.getElementById('pz-vit-input');
        const btnEnviar = document.getElementById('pz-vit-enviar');

        function addMsg(role, texto) {
            const d = document.createElement('div');
            d.className = 'pz-msg ' + (role === 'user' ? 'pz-msg-user' : 'pz-msg-bot');
            if (role === 'user') d.textContent = texto;
            else d.innerHTML = fmtMd(texto);
            msgs.appendChild(d);
            msgs.scrollTop = msgs.scrollHeight;
        }

        function setAtalhos(lista) {
            atalhos.innerHTML = '';
            (lista || []).forEach((txt) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = txt;
                b.addEventListener('click', () => enviar(txt));
                atalhos.appendChild(b);
            });
        }

        let saudou = false;
        async function enviar(texto) {
            const q = String(texto || '').trim();
            if (!q && saudou) return;
            if (q) addMsg('user', q);

            const podeIA = !(global.AcheIAkiLGPD && typeof global.AcheIAkiLGPD.podeUsarIA === 'function')
                || global.AcheIAkiLGPD.podeUsarIA();
            let visitorId = '';
            if (podeIA && global.PedidoZapLocalizacao && typeof global.PedidoZapLocalizacao.obterVisitorId === 'function') {
                visitorId = global.PedidoZapLocalizacao.obterVisitorId();
            }

            if (q && global.PedidoZapLocalizacao && typeof global.PedidoZapLocalizacao.registrar === 'function') {
                global.PedidoZapLocalizacao.registrar('busca', {
                    termo: q,
                    tenant: (typeof global.TENANT !== 'undefined') ? global.TENANT.slug : 'default'
                });
            }

            const url = (typeof global.TENANT !== 'undefined' && global.TENANT.apiUrl)
                ? global.TENANT.apiUrl('agente_api.php?action=vitrine_responder')
                : 'agente_api.php?action=vitrine_responder';
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tenant: (typeof global.TENANT !== 'undefined') ? global.TENANT.slug : 'default',
                        pergunta: q,
                        visitor_id: visitorId,
                        consentimento_ia: podeIA
                    })
                });
                const data = await r.json();
                if (data && data.ativo === false) {
                    box.hidden = true;
                    fab.style.display = 'none';
                    return;
                }
                if (data && data.resposta) {
                    addMsg('bot', data.resposta);
                    if (Array.isArray(data.sugestoes)) setAtalhos(data.sugestoes);
                }
            } catch (e) {
                addMsg('bot', 'Você pode navegar pelas categorias acima e adicionar seus itens ao carrinho.');
            }
            saudou = true;
        }

        fab.addEventListener('click', () => {
            const abrindo = box.hidden;
            box.hidden = !abrindo;
            fab.setAttribute('aria-expanded', abrindo ? 'true' : 'false');
            if (abrindo && !saudou) enviar('');
        });
        box.querySelector('.pz-assist-fechar').addEventListener('click', () => {
            box.hidden = true;
            fab.setAttribute('aria-expanded', 'false');
        });
        btnEnviar.addEventListener('click', () => {
            const v = input.value;
            input.value = '';
            enviar(v);
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const v = input.value;
                input.value = '';
                enviar(v);
            }
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => setTimeout(montarVitrineIA, 400));
    else setTimeout(montarVitrineIA, 400);
})(typeof window !== 'undefined' ? window : globalThis);
