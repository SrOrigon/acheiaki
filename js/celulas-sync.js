/**
 * PedidoZap — Barramento de Comunicação em Tempo Real entre Células do Sistema.
 * Conecta Marketplace, Vitrine da Loja, Admin, Painel Kanban, Cozinha, Entregador e Rastreio
 * via BroadcastChannel + localStorage para atualização instantânea sem pontas soltas.
 */
(function (global) {
    'use strict';

    const STORAGE_EVENT_KEY = 'pz_celula_evento';
    const STORAGE_ORDERS_KEY = 'pz_ultimos_pedidos';
    const STORAGE_PHONE_KEY = 'pz_ultimo_telefone';
    const CHANNEL_NAME = 'pedidozap_celulas_sync';

    let bc = null;
    try {
        if ('BroadcastChannel' in global) {
            bc = new BroadcastChannel(CHANNEL_NAME);
        }
    } catch (e) {
        bc = null;
    }

    const ouvintes = new Set();

    function notificarLocais(evento) {
        if (!evento || !evento.tipo) return;
        ouvintes.forEach((fn) => {
            try { fn(evento); } catch (e) { /* isola falha de ouvinte */ }
        });
        try {
            global.dispatchEvent(new CustomEvent('pz:celula', { detail: evento }));
        } catch (e) { /* ignora */ }
    }

    if (bc) {
        bc.onmessage = (ev) => {
            if (ev && ev.data) notificarLocais(ev.data);
        };
    }

    global.addEventListener('storage', (ev) => {
        if (ev.key === STORAGE_EVENT_KEY && ev.newValue) {
            try {
                const parsed = JSON.parse(ev.newValue);
                notificarLocais(parsed);
            } catch (e) { /* ignora */ }
        }
    });

    function emitir(tipo, dados) {
        const evento = {
            tipo: String(tipo || ''),
            dados: dados || {},
            ts: Date.now(),
            nonce: Math.random().toString(36).slice(2, 8),
        };
        if (bc) {
            try { bc.postMessage(evento); } catch (e) { /* ignora */ }
        }
        try {
            localStorage.setItem(STORAGE_EVENT_KEY, JSON.stringify(evento));
        } catch (e) { /* quota ou privado */ }
        notificarLocais(evento);
        return evento;
    }

    function escutar(fn) {
        if (typeof fn !== 'function') return () => {};
        ouvintes.add(fn);
        return () => ouvintes.delete(fn);
    }

    function registrarPedidoLocal(pedido) {
        if (!pedido || typeof pedido !== 'object') return;
        const tel = String(pedido.celular || pedido.telefone || '').replace(/\D/g, '');
        if (tel.length >= 10) {
            try { localStorage.setItem(STORAGE_PHONE_KEY, tel); } catch (e) {}
        }
        try {
            const lista = JSON.parse(localStorage.getItem(STORAGE_ORDERS_KEY) || '[]');
            const arr = Array.isArray(lista) ? lista : [];
            const entrada = {
                id: pedido.id || '',
                codigo: pedido.codigo || '',
                tenant: pedido.tenant || (global.TENANT && global.TENANT.slug) || 'default',
                celular: tel,
                total: Number(pedido.total || 0),
                status: pedido.status || 'novo',
                ts: Date.now(),
            };
            const filtrado = arr.filter((p) => !(String(p.id) === String(entrada.id) && p.tenant === entrada.tenant));
            filtrado.unshift(entrada);
            localStorage.setItem(STORAGE_ORDERS_KEY, JSON.stringify(filtrado.slice(0, 15)));
        } catch (e) { /* ignora */ }
    }

    function obterTelefonePreferido() {
        try {
            const comp = JSON.parse(localStorage.getItem('pz_comprador_info') || 'null');
            if (comp && comp.telefone) return String(comp.telefone).replace(/\D/g, '');
        } catch (e) {}
        try {
            const tel = localStorage.getItem(STORAGE_PHONE_KEY) || '';
            if (tel) return String(tel).replace(/\D/g, '');
        } catch (e) {}
        return '';
    }

    function obterUltimosPedidos() {
        try {
            const lista = JSON.parse(localStorage.getItem(STORAGE_ORDERS_KEY) || '[]');
            return Array.isArray(lista) ? lista : [];
        } catch (e) {
            return [];
        }
    }

    function urlComTenant(pagina, slug) {
        const s = slug || (global.TENANT && global.TENANT.slug) || 'default';
        if (!pagina) return 'marketplace.html';
        if (pagina.indexOf('tenant=') !== -1) return pagina;
        const partes = String(pagina).split('#');
        const sep = partes[0].includes('?') ? '&' : '?';
        return partes[0] + sep + 'tenant=' + encodeURIComponent(s) + (partes[1] ? '#' + partes[1] : '');
    }

    global.PedidoZapCelulas = {
        emitir,
        escutar,
        registrarPedidoLocal,
        obterTelefonePreferido,
        obterUltimosPedidos,
        urlComTenant,
    };
})(typeof window !== 'undefined' ? window : globalThis);
