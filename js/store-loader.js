/**
 * PedidoZap — carrega cardápio/config por tenant (API isolada) antes da vitrine/admin.
 */
(function (global) {
    const DEFAULT_CARDAPIO = {
        hamburgueres: [],
        porcoes: [],
        bebidas: [],
        acai: [],
        combos: [],
        promocoes: []
    };

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = false;
            script.onload = () => resolve();
            script.onerror = () => {
                if (src.includes('?')) {
                    const clean = src.split('?')[0];
                    const retry = document.createElement('script');
                    retry.src = clean;
                    retry.async = false;
                    retry.onload = () => resolve();
                    retry.onerror = () => reject(new Error('Falha ao carregar: ' + src));
                    document.head.appendChild(retry);
                    return;
                }
                reject(new Error('Falha ao carregar: ' + src));
            };
            document.head.appendChild(script);
        });
    }

    function apiUrl(path) {
        if (global.TENANT && global.TENANT.apiUrl) {
            return global.TENANT.apiUrl(path);
        }
        return path;
    }

    function getSlug() {
        return global.TENANT ? global.TENANT.slug : 'default';
    }

    async function ensureExtras() {
        const v = Date.now();
        const tarefas = [];
        if (!global.PedidoZapOfflineCache) {
            tarefas.push(loadScript('js/offline-cache.js?v=' + v).catch((e) => console.warn(e)));
        }
        if (!global.PedidoZapHydrate) {
            tarefas.push(loadScript('js/store-hydrate.js?v=' + v).catch((e) => console.warn(e)));
        }
        if (!global.PedidoZapFrete) {
            tarefas.push(loadScript('js/frete.js?v=' + v).catch((e) => console.warn(e)));
        }
        if (tarefas.length) await Promise.all(tarefas);
    }

    function publishStore() {
        if (global.PedidoZapHydrate && global.PedidoZapHydrate.apply) {
            global.PedidoZapHydrate.apply(activeConfig, activeCardapio);
        }
        try {
            document.dispatchEvent(new CustomEvent('pedidozap-store'));
        } catch (e) { /* página sem document */ }
    }

    let activeConfig = {};
    let activeCardapio = DEFAULT_CARDAPIO;

    function bindingOrWindow(name) {
        try {
            const value = (0, eval)(name);
            if (value && typeof value === 'object') return value;
        } catch (e) { /* binding ausente */ }
        return (global[name] && typeof global[name] === 'object') ? global[name] : null;
    }

    function setGlobals(config, cardapio) {
        activeConfig = config || {};
        activeCardapio = cardapio || DEFAULT_CARDAPIO;
        try { global.APP_CONFIG = activeConfig; } catch (e) { /* const global somente leitura */ }
        try { global.CARDAPIO_DADOS = activeCardapio; } catch (e) { /* const global somente leitura */ }
    }

    function syncStoreState() {
        global.PedidoZapStore.ready = true;
        global.PedidoZapStore.config = activeConfig;
        global.PedidoZapStore.cardapio = activeCardapio;
        global.PedidoZapStore.tenant = getSlug();
    }

    function emitReady(source) {
        try {
            global.dispatchEvent(new CustomEvent('pedidozap:store-ready', {
                detail: {
                    tenant: getSlug(),
                    source: source || 'api'
                }
            }));
        } catch (e) {
            /* IE / ambientes antigos */
        }
    }

    function showLoading() {
        if (document.getElementById('preloader')) return;
        if (document.getElementById('pedidozap-store-loading')) return;
        const el = document.createElement('div');
        el.id = 'pedidozap-store-loading';
        el.setAttribute('aria-live', 'polite');
        el.style.cssText = 'position:fixed;inset:0;background:rgba(247,246,242,0.96);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;padding:18px;z-index:99998;font-family:Inter,system-ui,sans-serif;color:#14171f;transition:opacity 0.18s ease;';
        el.innerHTML = ''
            + '<div style="width:100%;max-width:560px;background:#fff;border:1px solid rgba(20,23,31,0.08);border-radius:18px;padding:20px;box-shadow:0 12px 32px rgba(15,23,42,0.08);">'
            + '  <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">'
            + '    <div style="width:20px;height:20px;border:2.5px solid #e5e7eb;border-top-color:#e85d04;border-radius:50%;animation:pz-spin .7s linear infinite;flex-shrink:0;"></div>'
            + '    <strong style="font-size:0.96rem;">Preparando painel e dados da loja...</strong>'
            + '  </div>'
            + '  <div style="display:grid;gap:10px;">'
            + '    <div style="height:16px;border-radius:8px;background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);background-size:200% 100%;animation:pz-shimmer 1.1s infinite linear;"></div>'
            + '    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">'
            + '      <div style="height:64px;border-radius:12px;background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);background-size:200% 100%;animation:pz-shimmer 1.1s infinite linear;"></div>'
            + '      <div style="height:64px;border-radius:12px;background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);background-size:200% 100%;animation:pz-shimmer 1.1s infinite linear;"></div>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        const style = document.createElement('style');
        style.textContent = '@keyframes pz-spin{to{transform:rotate(360deg)}}@keyframes pz-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}';
        document.head.appendChild(style);
        document.body.appendChild(el);
    }

    function hideLoading() {
        const el = document.getElementById('pedidozap-store-loading');
        if (!el) return;
        el.style.opacity = '0';
        setTimeout(() => { if (el.parentNode) el.remove(); }, 180);
    }

    async function loadStaticFallback() {
        const v = Date.now();
        try {
            await loadScript('js/config.js?v=' + v);
        } catch (e) {
            console.warn('store-loader: config.js indisponível', e);
        }
        try {
            await loadScript('js/dados_cardapio.js?v=' + v);
        } catch (e) {
            console.warn('store-loader: dados_cardapio.js indisponível', e);
        }

        const cfgEstatico = bindingOrWindow('APP_CONFIG') || {};
        const menuEstatico = bindingOrWindow('CARDAPIO_DADOS') || DEFAULT_CARDAPIO;
        if (!cfgEstatico.TENANT_SLUG) cfgEstatico.TENANT_SLUG = 'default';
        setGlobals(cfgEstatico, menuEstatico);
        publishStore();
        return { source: 'static' };
    }

    function hideSitePreloader() {
        if (global.PedidoZapUI && global.PedidoZapUI.hidePreloader) {
            global.PedidoZapUI.hidePreloader();
            return;
        }
        const preloader = document.getElementById('preloader');
        if (!preloader) return;
        preloader.classList.add('fade-out');
        setTimeout(() => {
            preloader.style.display = 'none';
            if (document.body) document.body.style.overflow = 'auto';
        }, 600);
    }

    async function fetchWithTimeout(url, timeoutMs) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        try {
            return await fetch(url, {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
                signal: controller.signal
            });
        } finally {
            clearTimeout(timer);
        }
    }

    async function loadFromApi(opts, slug) {
        const url = apiUrl('get_public_store.php?v=' + Date.now());
        let response;
        try {
            response = await fetchWithTimeout(url, 15000);
        } catch (err) {
            if (err && err.name === 'AbortError') {
                throw new Error('Tempo esgotado ao conectar ao servidor. Tente novamente.');
            }
            throw err;
        }

        const rawText = await response.text();
        let data = {};
        try {
            data = rawText ? JSON.parse(rawText) : {};
        } catch (parseErr) {
            console.error('Resposta inválida da API:', rawText.slice(0, 200));
            throw new Error('Servidor retornou resposta inválida. Verifique se o PHP está ativo.');
        }

        if (!response.ok || !data.success) {
            const msg = data.message || ('HTTP ' + response.status);

            if (opts.allowEmptyTenant && response.status === 404 && slug !== 'default') {
                const nome = slug.replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                setGlobals({
                    TENANT_SLUG: slug,
                    NOME_LOJA: 'Nome da Sua Loja',
                    SLOGAN: nome,
                    WHATSAPP_NUMBER: '',
                    ALTA_DEMANDA: false,
                    TEMPO_ESPERA: '40-60 min',
                    HORARIOS: {
                        SEMANA: { inicio: '18:00', fim: '23:00' },
                        FIM_DE_SEMANA: { inicio: '18:00', fim: '00:00' },
                        FECHADO: []
                    }
                }, DEFAULT_CARDAPIO);
                publishStore();
                return { source: 'empty-tenant' };
            }

            throw new Error(msg);
        }

        const hostLoja = (global.location.hostname || '').toLowerCase();
        const hospedagemTemporaria = hostLoja.endsWith('.up.railway.app') || hostLoja.endsWith('.railway.app');
        if (data.tenant && slug !== 'default' && data.tenant !== slug && !(hospedagemTemporaria && data.tenant === 'default')) {
            throw new Error('Resposta da API não corresponde ao estabelecimento solicitado.');
        }

        setGlobals(data.config || {}, data.cardapio || DEFAULT_CARDAPIO);
        global.PedidoZapStore.assinatura = data.assinatura || null;
        global.PedidoZapStore.lojaBloqueada = !!data.loja_bloqueada;
        global.PedidoZapStore.offline = !!data.offline;
        if (global.PedidoZapOfflineCache && !data.offline) {
            global.PedidoZapOfflineCache.salvarLoja(slug, data).catch(() => {});
            global.PedidoZapOfflineCache.mostrarFaixaOffline(false);
        }
        publishStore();
        return { source: data.offline ? 'offline-cache' : 'api', version: data.version || 0 };
    }

    let onlineSyncRegistrado = false;

    async function load(options) {
        const opts = options || {};
        const slug = getSlug();
        await ensureExtras();

        if (slug !== 'default' && opts.fallbackStatic) {
            opts.fallbackStatic = false;
        }

        try {
            const result = await loadFromApi(opts, slug);
            syncStoreState();
            emitReady(result.source);
            return result;
        } catch (err) {
            console.warn('PedidoZapStore: API indisponível para tenant "' + slug + '"', err);

            if (global.PedidoZapOfflineCache && typeof global.PedidoZapOfflineCache.lerLoja === 'function') {
                const offlineSnap = await global.PedidoZapOfflineCache.lerLoja(slug);
                if (offlineSnap && offlineSnap.config && offlineSnap.cardapio) {
                    setGlobals(offlineSnap.config, offlineSnap.cardapio);
                    global.PedidoZapStore.assinatura = offlineSnap.assinatura || null;
                    global.PedidoZapStore.lojaBloqueada = !!offlineSnap.loja_bloqueada;
                    global.PedidoZapStore.offline = true;
                    publishStore();
                    syncStoreState();
                    emitReady('offline-cache');
                    global.PedidoZapOfflineCache.mostrarFaixaOffline(true);
                    if (!onlineSyncRegistrado && typeof global.addEventListener === 'function') {
                        onlineSyncRegistrado = true;
                        global.addEventListener('online', () => {
                            load({ silent: true }).then((res) => {
                                if (res && res.source === 'api' && global.PedidoZapOfflineCache) {
                                    global.PedidoZapOfflineCache.mostrarFaixaOffline(false);
                                }
                            }).catch(() => {});
                        }, { once: true });
                    }
                    return { source: 'offline-cache', version: offlineSnap.version || 0 };
                }
            }

            if (opts.fallbackStatic === true && slug === 'default') {
                const fallback = await loadStaticFallback();
                syncStoreState();
                emitReady(fallback.source);
                return fallback;
            }

            if (opts.silent) {
                setGlobals({ TENANT_SLUG: slug }, DEFAULT_CARDAPIO);
                global.PedidoZapStore.ready = false;
                return { source: 'error', error: err.message };
            }

            throw err;
        }
    }

    function showFatalError(message, slug) {
        hideLoading();
        try {
            document.documentElement.setAttribute('data-pz-ready', '1');
        } catch (e) { /* noop */ }
        const overlay = document.createElement('div');
        overlay.setAttribute('role', 'alert');
        overlay.style.cssText = 'position:fixed;inset:0;background:#111827;color:#fff;display:flex;align-items:center;justify-content:center;padding:24px;z-index:99999;text-align:center;font-family:Roboto,sans-serif;';
        overlay.innerHTML = '<div><h2 style="margin:0 0 12px;">Loja indisponível</h2>' +
            '<p style="opacity:0.85;max-width:380px;margin:0 auto 8px;">' + (message || 'Não foi possível carregar o cardápio.') + '</p>' +
            (slug && slug !== 'default' ? '<p style="opacity:0.6;font-size:0.85em">Estabelecimento: <code>' + slug + '</code></p>' : '') +
            '</div>';
        document.body.appendChild(overlay);
    }

    function lerSnapshotCacheSincrono(slug) {
        try {
            const raw = localStorage.getItem('pz_offline_store_' + (slug || 'default'));
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            return (parsed && parsed.config && parsed.cardapio) ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    async function bootstrap(scriptPaths, options) {
        const opts = Object.assign({ fallbackStatic: true, showLoading: true }, options || {});
        const slug = getSlug();
        const arquivo = (global.location.pathname.split('/').pop() || 'index.html');
        const interno = /admin|painel|motoboy|superadmin|rastreio/.test(arquivo);

        const snapRapido = !interno ? lerSnapshotCacheSincrono(slug) : null;
        if (snapRapido) {
            if (snapRapido.loja_bloqueada) {
                showFatalError('Este estabelecimento está temporariamente indisponível. Para mais informações, contate o administrador.');
                hideLoading();
                hideSitePreloader();
                return;
            }
            setGlobals(snapRapido.config, snapRapido.cardapio);
            global.PedidoZapStore.assinatura = snapRapido.assinatura || null;
            global.PedidoZapStore.lojaBloqueada = !!snapRapido.loja_bloqueada;
            await ensureExtras();
            publishStore();
            syncStoreState();
            emitReady('fast-cache');
            load(Object.assign({}, opts, { silent: true })).then((res) => {
                if (res && res.source === 'api' && global.PedidoZapHydrate && global.PedidoZapHydrate.apply) {
                    global.PedidoZapHydrate.apply(activeConfig, activeCardapio);
                }
            }).catch(() => {});
        } else {
            if (opts.showLoading) showLoading();
            try {
                await load(opts);
                if (global.PedidoZapStore.lojaBloqueada && !interno) {
                    showFatalError('Este estabelecimento está temporariamente indisponível. Para mais informações, contate o administrador.');
                    hideLoading();
                    hideSitePreloader();
                    return;
                }
            } catch (err) {
                hideLoading();
                showFatalError(err.message, slug);
                hideSitePreloader();
                return;
            }
        }

        const paths = Array.isArray(scriptPaths) ? scriptPaths : [scriptPaths];
        const v = Date.now();
        try {
            await Promise.all(paths.map((path) => {
                const src = path.includes('?') ? path : path + '?v=' + v;
                return loadScript(src);
            }));
        } catch (scriptErr) {
            console.error(scriptErr);
            hideLoading();
            showFatalError('Erro ao carregar módulo da página.', slug);
            hideSitePreloader();
            return;
        }

        hideLoading();
        hideSitePreloader();
    }

    /** Fallback se store-loader falhar ao subir (deploy incompleto). */
    async function legacyBootstrap(scriptPaths) {
        const v = Date.now();
        try {
            await loadScript('js/config.js?v=' + v);
            await loadScript('js/dados_cardapio.js?v=' + v);
        } catch (e) {
            console.warn('legacyBootstrap: arquivos estáticos indisponíveis', e);
        }
        const paths = scriptPaths || ['js/preloaders.js', 'js/script.js'];
        for (const path of paths) {
            await loadScript(path + (path.includes('?') ? '' : '?v=' + v));
        }
        hideSitePreloader();
    }

    global.PedidoZapStore = {
        ready: false,
        tenant: 'default',
        config: {},
        cardapio: DEFAULT_CARDAPIO,
        load,
        bootstrap,
        legacyBootstrap
    };
})(typeof window !== 'undefined' ? window : globalThis);
