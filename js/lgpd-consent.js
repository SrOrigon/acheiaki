/**
 * Gerenciador de Consentimento LGPD e Coleta de Dados pela IA — AcheIAki
 * Garante base legal (Art. 7º I da Lei 13.709/2018) antes de coletar sinais comportamentais.
 */
(function (global) {
    'use strict';

    const STORAGE_KEY = 'acheiaki_lgpd_consent_v1';
    const VERSAO_TERMOS = '2026-11';

    function ler() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            const d = JSON.parse(raw);
            if (d && typeof d === 'object' && d.versao === VERSAO_TERMOS) return d;
        } catch (e) { /* ignore */ }
        return null;
    }

    function getVisitorIdSafe() {
        if (global.PZAlgoritmo && typeof global.PZAlgoritmo.getVisitorId === 'function') {
            return global.PZAlgoritmo.getVisitorId();
        }
        try {
            return localStorage.getItem('pz_alg_visitor_id') || '';
        } catch (e) {
            return '';
        }
    }

    function salvar(iaPersonalizacao) {
        const registro = {
            versao: VERSAO_TERMOS,
            essenciais: true,
            ia_personalizacao: Boolean(iaPersonalizacao),
            atualizado_em: new Date().toISOString()
        };
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(registro));
        } catch (e) { /* ignore */ }

        // Se o usuário recusou ou revogou a IA, apaga o perfil comportamental no servidor
        if (!registro.ia_personalizacao) {
            const vid = getVisitorIdSafe();
            if (vid) {
                fetch('algoritmo_api.php?action=excluir_perfil_visitante', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ visitor_id: vid })
                }).catch(() => {});
            }
        }

        // Sincroniza com a conta do comprador logado, se houver
        try {
            const token = localStorage.getItem('pz_comprador_token') || '';
            if (token) {
                fetch('comprador_api.php?action=atualizar_consentimento_lgpd', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token, ia_personalizacao: registro.ia_personalizacao })
                }).catch(() => {});
            }
        } catch (e) { /* ignore */ }

        const banner = document.getElementById('aki-lgpd-banner');
        const modal = document.getElementById('aki-lgpd-modal');
        if (banner) banner.hidden = true;
        if (modal) modal.hidden = true;

        try {
            global.dispatchEvent(new CustomEvent('acheiaki:lgpd-atualizado', { detail: registro }));
        } catch (e) { /* ignore */ }

        return registro;
    }

    function podeUsarIA() {
        const reg = ler();
        return Boolean(reg && reg.ia_personalizacao === true);
    }

    function garantirCss() {
        if (document.getElementById('aki-lgpd-css-link')) return;
        const jaTem = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
            .some((l) => (l.getAttribute('href') || '').includes('lgpd-consent.css'));
        if (jaTem) return;
        const link = document.createElement('link');
        link.id = 'aki-lgpd-css-link';
        link.rel = 'stylesheet';
        link.href = 'css/lgpd-consent.css';
        document.head.appendChild(link);
    }

    function montarUI() {
        const pagina = document.body && document.body.dataset.pagina;
        if (pagina === 'admin' || pagina === 'painel' || pagina === 'cozinha' || pagina === 'motoboy') return;

        garantirCss();

        let temTokenComprador = false;
        try { temTokenComprador = Boolean(localStorage.getItem('pz_comprador_token')); } catch (e) {}
        const noPortalSemLogin = Boolean(document.getElementById('mkt-modal-conta')) && !temTokenComprador;
        const portalAberto = Boolean(document.getElementById('mkt-modal-conta') && !document.getElementById('mkt-modal-conta').hidden) || noPortalSemLogin;

        if (!document.getElementById('aki-lgpd-banner')) {
            const banner = document.createElement('aside');
            banner.id = 'aki-lgpd-banner';
            banner.className = 'aki-lgpd-banner';
            banner.setAttribute('role', 'dialog');
            banner.setAttribute('aria-label', 'Permissão para mostrar preços perto de você');
            banner.hidden = Boolean(ler()) || portalAberto;
            banner.innerHTML = ''
                + '<div class="aki-lgpd-banner-top">'
                + '<div class="aki-lgpd-ico"><i class="fa-solid fa-shield-halved"></i></div>'
                + '<div>'
                + '<h3 style="font-size:1.12rem;">Posso mostrar os melhores preços perto de você?</h3>'
                + '<p style="font-size:0.95rem;">Guardamos seu carrinho e, com sua permissão, usamos o que você procura para mostrar comida mais barata na sua cidade. <a href="termos.html" target="_blank">Termos</a> · <a href="privacidade.html" target="_blank">Privacidade</a></p>'
                + '</div>'
                + '</div>'
                + '<div class="aki-lgpd-btns">'
                + '<button type="button" class="aki-lgpd-btn aki-lgpd-btn-ghost" id="aki-lgpd-config">Escolher</button>'
                + '<button type="button" class="aki-lgpd-btn aki-lgpd-btn-sec" id="aki-lgpd-essencial">Só o básico</button>'
                + '<button type="button" class="aki-lgpd-btn aki-lgpd-btn-prim" id="aki-lgpd-aceitar" style="font-size:0.92rem;padding:10px 16px;"><i class="fa-solid fa-check"></i> Sim, pode mostrar</button>'
                + '</div>';
            document.body.appendChild(banner);

            document.getElementById('aki-lgpd-aceitar').addEventListener('click', () => salvar(true));
            document.getElementById('aki-lgpd-essencial').addEventListener('click', () => salvar(false));
            document.getElementById('aki-lgpd-config').addEventListener('click', abrirPreferencias);
        } else if (portalAberto) {
            document.getElementById('aki-lgpd-banner').hidden = true;
        }

        if (!document.getElementById('aki-lgpd-modal')) {
            const modal = document.createElement('div');
            modal.id = 'aki-lgpd-modal';
            modal.className = 'aki-lgpd-modal';
            modal.hidden = true;
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.innerHTML = ''
                + '<div class="aki-lgpd-modal-card">'
                + '<div style="display:flex;justify-content:space-between;align-items:center;">'
                + '<h3 style="margin:0;color:#231915;">Preferências de Privacidade e IA (LGPD)</h3>'
                + '<button type="button" id="aki-lgpd-m-fechar" style="border:none;background:#f5efe6;width:32px;height:32px;border-radius:50%;font-size:1.2rem;cursor:pointer;">&times;</button>'
                + '</div>'
                + '<p style="margin:0;font-size:0.9rem;color:#475569;">Conforme a Lei nº 13.709/2018 (LGPD), você escolhe como seus dados são tratados. Consulte os <a href="termos.html" target="_blank" style="color:#c2410c;font-weight:700;">Termos de Uso</a> e a <a href="privacidade.html" target="_blank" style="color:#c2410c;font-weight:700;">Política de Privacidade</a>.</p>'
                + '<div class="aki-lgpd-opt">'
                + '<div><strong>1. Funcionamento Essencial</strong><small>Sessão de login, carrinho de compras, endereço selecionado e segurança contra fraudes. Sempre ativo.</small></div>'
                + '<input type="checkbox" class="aki-lgpd-switch" checked disabled aria-label="Cookies essenciais sempre ativos">'
                + '</div>'
                + '<div class="aki-lgpd-opt">'
                + '<div><strong>2. Coleta para IA e Algoritmo Regional</strong><small>Permite que a IA do AcheIAki analise suas buscas, cliques e tempo de visualização na região para recomendar pratos e os menores preços perto de você. Se desmarcar, seu perfil comportamental é apagado.</small></div>'
                + '<input type="checkbox" id="aki-lgpd-chk-ia" class="aki-lgpd-switch">'
                + '</div>'
                + '<div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">'
                + '<button type="button" id="aki-lgpd-m-salvar" class="aki-lgpd-btn aki-lgpd-btn-prim" style="background:#ea580c;color:#fff;">Salvar minhas preferências</button>'
                + '</div>'
                + '</div>';
            document.body.appendChild(modal);

            document.getElementById('aki-lgpd-m-fechar').addEventListener('click', () => { modal.hidden = true; });
            document.getElementById('aki-lgpd-m-salvar').addEventListener('click', () => {
                const chk = document.getElementById('aki-lgpd-chk-ia');
                salvar(Boolean(chk && chk.checked));
            });
        }
    }

    function abrirPreferencias() {
        montarUI();
        const reg = ler();
        const chk = document.getElementById('aki-lgpd-chk-ia');
        if (chk) chk.checked = reg ? Boolean(reg.ia_personalizacao) : true;
        const modal = document.getElementById('aki-lgpd-modal');
        if (modal) modal.hidden = false;
    }

    global.AcheIAkiLGPD = {
        ler,
        salvar,
        podeUsarIA,
        abrirPreferencias,
        VERSAO_TERMOS
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montarUI);
    else montarUI();
})(typeof window !== 'undefined' ? window : globalThis);
