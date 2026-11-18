/**
 * AcheIAki — Cache Offline do Cardápio e Carrinho (IndexedDB + localStorage + SW).
 * Mantém cardápio, configurações, imagens e carrinho salvos mesmo sem internet.
 */
(function (global) {
    'use strict';

    const DB_NAME = 'acheiaki_offline_v1';
    const STORE_NAME = 'kv';
    const LS_STORE_PREFIX = 'pz_offline_store_';
    const LS_CART_PREFIX = 'pz_offline_cart_';
    const LS_PEND_PREFIX = 'pz_offline_pending_order_';

    let dbPromise = null;

    function openDb() {
        if (dbPromise) return dbPromise;
        if (typeof indexedDB === 'undefined') return Promise.resolve(null);
        dbPromise = new Promise((resolve) => {
            try {
                const req = indexedDB.open(DB_NAME, 1);
                req.onupgradeneeded = () => {
                    const db = req.result;
                    if (!db.objectStoreNames.contains(STORE_NAME)) {
                        db.createObjectStore(STORE_NAME);
                    }
                };
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => resolve(null);
            } catch (e) {
                resolve(null);
            }
        });
        return dbPromise;
    }

    async function idbSet(key, value) {
        const db = await openDb();
        if (!db) return false;
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                tx.objectStore(STORE_NAME).put(value, key);
                tx.oncomplete = () => resolve(true);
                tx.onerror = () => resolve(false);
            } catch (e) {
                resolve(false);
            }
        });
    }

    async function idbGet(key) {
        const db = await openDb();
        if (!db) return null;
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(STORE_NAME, 'readonly');
                const req = tx.objectStore(STORE_NAME).get(key);
                req.onsuccess = () => resolve(req.result || null);
                req.onerror = () => resolve(null);
            } catch (e) {
                resolve(null);
            }
        });
    }

    async function idbDelete(key) {
        const db = await openDb();
        if (!db) return false;
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                tx.objectStore(STORE_NAME).delete(key);
                tx.oncomplete = () => resolve(true);
                tx.onerror = () => resolve(false);
            } catch (e) {
                resolve(false);
            }
        });
    }

    function lsSetJson(key, val) {
        try {
            localStorage.setItem(key, JSON.stringify(val));
            return true;
        } catch (e) {
            return false;
        }
    }

    function lsGetJson(key) {
        try {
            const raw = localStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function coletarImagensLoja(config, cardapio) {
        const urls = new Set();
        const add = (u) => {
            if (!u || typeof u !== 'string') return;
            const t = u.trim();
            if (!t || t.startsWith('data:')) return;
            urls.add(t);
        };
        if (config && config.IDENTIDADE) {
            add(config.IDENTIDADE.LOGO);
            add(config.IDENTIDADE.FAVICON);
            add(config.IDENTIDADE.HERO);
        }
        if (config && config.UPSELL) add(config.UPSELL.imagem);
        if (cardapio && typeof cardapio === 'object') {
            Object.keys(cardapio).forEach((cat) => {
                const arr = Array.isArray(cardapio[cat]) ? cardapio[cat] : [];
                arr.forEach((item) => add(item && item.imagem));
            });
        }
        return Array.from(urls).slice(0, 120);
    }

    function preCachearImagensNoSw(urls) {
        if (!urls || !urls.length) return;
        try {
            if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({
                    type: 'CACHE_STORE_ASSETS',
                    urls: urls
                });
            }
        } catch (e) { /* noop */ }
    }

    async function salvarLoja(slug, payload) {
        const s = slug || 'default';
        const registro = {
            slug: s,
            config: (payload && payload.config) || {},
            cardapio: (payload && payload.cardapio) || {},
            assinatura: (payload && payload.assinatura) || null,
            loja_bloqueada: !!(payload && payload.loja_bloqueada),
            version: (payload && payload.version) || 0,
            savedAt: Date.now()
        };
        lsSetJson(LS_STORE_PREFIX + s, registro);
        await idbSet('store:' + s, registro);
        preCachearImagensNoSw(coletarImagensLoja(registro.config, registro.cardapio));
        return registro;
    }

    async function lerLoja(slug) {
        const s = slug || 'default';
        const idb = await idbGet('store:' + s);
        if (idb && idb.config && idb.cardapio) return idb;
        const ls = lsGetJson(LS_STORE_PREFIX + s);
        if (ls && ls.config && ls.cardapio) return ls;
        return null;
    }

    function salvarCarrinho(cartKey, estado) {
        const k = cartKey || 'carrinhoAcheIAki';
        const snapshot = {
            carrinho: Array.isArray(estado && estado.carrinho) ? estado.carrinho : [],
            enderecoCliente: (estado && estado.enderecoCliente) || {},
            cupomAplicado: (estado && estado.cupomAplicado) || { codigo: '', desconto: 0 },
            modoRecebimento: (estado && estado.modoRecebimento) || 'entrega',
            savedAt: Date.now()
        };
        lsSetJson(LS_CART_PREFIX + k, snapshot);
        idbSet('cart:' + k, snapshot);
    }

    async function lerCarrinho(cartKey) {
        const k = cartKey || 'carrinhoAcheIAki';
        const idb = await idbGet('cart:' + k);
        const ls = lsGetJson(LS_CART_PREFIX + k);
        if (idb && ls) {
            return (Number(idb.savedAt || 0) >= Number(ls.savedAt || 0)) ? idb : ls;
        }
        return idb || ls || null;
    }

    async function salvarPedidoPendente(slug, item) {
        const s = slug || 'default';
        const payload = Object.assign({ savedAt: Date.now(), tenant: s }, item || {});
        lsSetJson(LS_PEND_PREFIX + s, payload);
        await idbSet('pending_order:' + s, payload);
        return payload;
    }

    async function lerPedidoPendente(slug) {
        const s = slug || 'default';
        const idb = await idbGet('pending_order:' + s);
        if (idb && idb.pedidoData) return idb;
        const ls = lsGetJson(LS_PEND_PREFIX + s);
        return (ls && ls.pedidoData) ? ls : null;
    }

    async function limparPedidoPendente(slug) {
        const s = slug || 'default';
        try { localStorage.removeItem(LS_PEND_PREFIX + s); } catch (e) { /* noop */ }
        await idbDelete('pending_order:' + s);
    }

    function mostrarFaixaOffline(ativo, textoCustom) {
        if (typeof document === 'undefined' || !document.body) return;
        let el = document.getElementById('pz-offline-status-bar');
        if (!ativo) {
            if (el) el.remove();
            return;
        }
        if (!el) {
            el = document.createElement('div');
            el.id = 'pz-offline-status-bar';
            el.setAttribute('role', 'status');
            el.style.cssText = 'position:sticky;top:0;z-index:9998;background:#0f172a;color:#fde68a;padding:8px 14px;font-size:0.86rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;text-align:center;border-bottom:1px solid rgba(251,191,36,0.35);';
            document.body.prepend(el);
        }
        el.innerHTML = '<i class="fa-solid fa-wifi"></i> <span>' + (textoCustom || 'Modo offline: cardápio e carrinho salvos nesta página.') + '</span>';
    }

    function registrarServiceWorker() {
        if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;
        if (!global.location || !global.location.protocol.startsWith('http')) return;
        try {
            const path = global.location.pathname || '/';
            const swPath = path.includes('/loja/') ? '/sw.js' : 'sw.js';
            navigator.serviceWorker.register(swPath, { scope: '/' }).catch(() => {
                navigator.serviceWorker.register('sw.js').catch(() => {});
            });
        } catch (e) { /* noop */ }
    }

    registrarServiceWorker();

    global.PedidoZapOfflineCache = {
        salvarLoja,
        lerLoja,
        salvarCarrinho,
        lerCarrinho,
        salvarPedidoPendente,
        lerPedidoPendente,
        limparPedidoPendente,
        mostrarFaixaOffline,
        registrarServiceWorker
    };
})(typeof window !== 'undefined' ? window : globalThis);
