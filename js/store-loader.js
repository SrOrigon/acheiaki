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
            script.onerror = () => reject(new Error('Falha ao carregar: ' + src));
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
        if (!global.PedidoZapHydrate) {
            try { await loadScript('js/store-hydrate.js?v=' + v); } catch (e) { console.warn(e); }
        }
        if (!global.PedidoZapFrete) {
            try { await loadScript('js/frete.js?v=' + v); } catch (e) { console.warn(e); }
        }
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
        if (document.getElementById('pedidozap-store-loading')) return;
        const el = document.createElement('div');
        el.id = 'pedidozap-store-loading';
        el.setAttribute('aria-live', 'polite');
        el.style.cssText = 'position:fixed;inset:0;background:rgba(255,255,255,0.92);display:flex;align-items:center;justify-content:center;z-index:99998;font-family:Roboto,sans-serif;color:#374151;';
        el.innerHTML = '<div style="text-align:center"><div style="width:40px;height:40px;border:3px solid #f97136;border-top-color:transparent;border-radius:50%;animation:pz-spin .8s linear infinite;margin:0 auto 12px"></div><p style="margin:0">Carregando cardápio...</p></div>';
        const style = document.createElement('style');
        style.textContent = '@keyframes pz-spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(style);
        document.body.appendChild(el);
    }

    function hideLoading() {
        const el = document.getElementById('pedidozap-store-loading');
        if (el) el.remove();
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
        const hospedagemTemporaria = hostLoja.endsWith('.hostingersite.com') || hostLoja.endsWith('.hostinger.com');
        if (data.tenant && slug !== 'default' && data.tenant !== slug && !(hospedagemTemporaria && data.tenant === 'default')) {
            throw new Error('Resposta da API não corresponde ao estabelecimento solicitado.');
        }

        setGlobals(data.config || {}, data.cardapio || DEFAULT_CARDAPIO);
        global.PedidoZapStore.assinatura = data.assinatura || null;
        global.PedidoZapStore.lojaBloqueada = !!data.loja_bloqueada;
        publishStore();
        return { source: 'api', version: data.version || 0 };
    }

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

    async function bootstrap(scriptPaths, options) {
        const opts = Object.assign({ fallbackStatic: true, showLoading: true }, options || {});
        const slug = getSlug();

        if (opts.showLoading) showLoading();

        try {
            await load(opts);
            const arquivo = (global.location.pathname.split('/').pop() || 'index.html');
            const interno = /admin|painel|motoboy|superadmin|rastreio/.test(arquivo);
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

        const paths = Array.isArray(scriptPaths) ? scriptPaths : [scriptPaths];
        const v = Date.now();
        for (const path of paths) {
            const src = path.includes('?') ? path : path + '?v=' + v;
            try {
                await loadScript(src);
            } catch (scriptErr) {
                console.error(scriptErr);
                hideLoading();
                showFatalError('Erro ao carregar módulo: ' + path, slug);
                hideSitePreloader();
                return;
            }
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
