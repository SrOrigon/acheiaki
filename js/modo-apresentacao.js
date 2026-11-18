/**
 * PedidoZap — Controlador reversível do Modo Análise / Apresentação.
 * Alivia travas momentâneas para demonstração e verificação funcional
 * mantendo 100% da arquitetura e validações de produção preservadas.
 */
(function (global) {
    'use strict';

    const STORAGE_KEY = 'pz_modo_apresentacao';

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
        } catch (e) { /* noop */ }
        global.location.reload();
    }

    global.PedidoZapApresentacao = {
        ativo,
        alternar
    };
})(typeof window !== 'undefined' ? window : globalThis);
