/**
 * PedidoZap — Controlador reversível do Modo Análise / Apresentação.
 * Alivia travas momentâneas para demonstração e verificação funcional
 * mantendo 100% da arquitetura e validações de produção preservadas.
 */
(function (global) {
    'use strict';

    const STORAGE_KEY = 'pz_modo_apresentacao';
    const HIDE_PILL_KEY = 'pz_modo_apresentacao_oculto';

    function lerParametroUrl() {
        try {
            const p = new URLSearchParams(global.location ? global.location.search : '');
            if (p.get('strict') === '1' || p.get('apresentacao') === '0') {
                localStorage.setItem(STORAGE_KEY, '0');
                return '0';
            }
            if (p.get('strict') === '0' || p.get('apresentacao') === '1') {
                localStorage.setItem(STORAGE_KEY, '1');
                return '1';
            }
        } catch (e) { /* noop */ }
        return null;
    }

    function ativo() {
        lerParametroUrl();
        try {
            const val = localStorage.getItem(STORAGE_KEY);
            if (val === '0') return false;
        } catch (e) { /* noop */ }
        return true;
    }

    function alternar(forcar) {
        const novo = (typeof forcar === 'boolean') ? forcar : !ativo();
        try {
            localStorage.setItem(STORAGE_KEY, novo ? '1' : '0');
            sessionStorage.removeItem(HIDE_PILL_KEY);
        } catch (e) { /* noop */ }
        global.location.reload();
    }

    function montarBotaoControle() {
        if (typeof document === 'undefined' || !document.body) return;
        if (document.getElementById('pz-modo-apresentacao-pill')) return;
        try {
            if (sessionStorage.getItem(HIDE_PILL_KEY) === '1') return;
        } catch (e) { /* noop */ }

        const liberado = ativo();
        if (!document.getElementById('pz-modo-apresentacao-css')) {
            const st = document.createElement('style');
            st.id = 'pz-modo-apresentacao-css';
            st.textContent = '@media (max-width:640px){#pz-modo-apresentacao-pill{left:8px!important;bottom:8px!important;padding:5px 8px!important;gap:5px!important;font-size:11px!important;max-width:calc(100vw - 148px)!important;}#pz-modo-apresentacao-pill .pz-modo-lbl{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:92px;}#pz-modo-toggle-btn{padding:4px 7px!important;font-size:10px!important;}}';
            document.head.appendChild(st);
        }
        const wrap = document.createElement('div');
        wrap.id = 'pz-modo-apresentacao-pill';
        wrap.style.cssText = [
            'position:fixed',
            'left:12px',
            'bottom:12px',
            'z-index:9990',
            'display:inline-flex',
            'align-items:center',
            'gap:8px',
            'padding:7px 11px',
            'border-radius:999px',
            'font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif',
            'font-size:12px',
            'font-weight:700',
            'box-shadow:0 8px 24px rgba(15,23,42,0.25)',
            'backdrop-filter:blur(8px)',
            liberado
                ? 'background:rgba(15,23,42,0.9);color:#fde68a;border:1px solid rgba(251,191,36,0.4);'
                : 'background:rgba(255,255,255,0.95);color:#0f172a;border:1px solid #cbd5e1;'
        ].join(';');

        const dot = '<span style="width:8px;height:8px;border-radius:50%;background:' + (liberado ? '#22c55e' : '#ef4444') + ';flex-shrink:0;"></span>';
        const txt = liberado ? 'Modo Análise' : 'Modo Produção';
        const btnTxt = liberado ? 'Ativar travas' : 'Liberar análise';

        wrap.innerHTML = ''
            + dot
            + '<span class="pz-modo-lbl">' + txt + '</span>'
            + '<button type="button" id="pz-modo-toggle-btn" style="border:none;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap;background:' + (liberado ? '#f97316' : '#0f172a') + ';color:#fff;">' + btnTxt + '</button>'
            + '<button type="button" id="pz-modo-fechar-pill" title="Ocultar aviso nesta sessão" style="border:none;background:transparent;color:inherit;opacity:0.7;cursor:pointer;font-size:14px;line-height:1;padding:0 2px;">&times;</button>';

        document.body.appendChild(wrap);
        document.getElementById('pz-modo-toggle-btn').addEventListener('click', () => alternar());
        document.getElementById('pz-modo-fechar-pill').addEventListener('click', () => {
            try { sessionStorage.setItem(HIDE_PILL_KEY, '1'); } catch (e) { /* noop */ }
            wrap.remove();
        });
    }

    global.PedidoZapApresentacao = {
        ativo,
        alternar
    };

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', montarBotaoControle);
        } else {
            montarBotaoControle();
        }
    }
})(typeof window !== 'undefined' ? window : globalThis);
