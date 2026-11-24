/**
 * PedidoZap — resolução de tenant no front-end (slug por URL ou query).
 */
(function (global) {
    function sanitizeTenantSlug(raw) {
        const slug = String(raw || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9_-]/g, '')
            .slice(0, 64);
        return slug || 'default';
    }

    function detectTenantSlug() {
        try {
            const params = new URLSearchParams(global.location.search);
            const fromQuery = params.get('tenant');
            if (fromQuery) return sanitizeTenantSlug(fromQuery);

            const pathMatch = global.location.pathname.match(/\/loja\/([a-z0-9_-]+)/i);
            if (pathMatch && pathMatch[1]) return sanitizeTenantSlug(pathMatch[1]);

            if (global.__PZ_TENANT_SLUG__) return sanitizeTenantSlug(global.__PZ_TENANT_SLUG__);

            const host = (global.location.hostname || '').toLowerCase();
            const plataforma = host.endsWith('.up.railway.app') || host.endsWith('.railway.app');
            if (plataforma) {
                const falso = sanitizeTenantSlug(host.split('.')[0]);
                const guardado = global.sessionStorage.getItem('pedidozap_tenant_slug');
                if (guardado === falso) global.sessionStorage.removeItem('pedidozap_tenant_slug');
            }
        } catch (e) {
            console.warn('TENANT: falha ao detectar slug', e);
        }

        const hostAtual = (global.location.hostname || '').toLowerCase();
        const naPlataforma = hostAtual.endsWith('.up.railway.app') || hostAtual.endsWith('.railway.app');
        if (naPlataforma) return 'default';

        const stored = global.sessionStorage.getItem('pedidozap_tenant_slug');
        return stored ? sanitizeTenantSlug(stored) : 'default';
    }

    const slug = detectTenantSlug();

    try {
        global.sessionStorage.setItem('pedidozap_tenant_slug', slug);
    } catch (e) {
        /* sessionStorage indisponível */
    }

    function apiPathRoot(path) {
        if (!path) return path;
        if (/^https?:\/\//i.test(path)) return path;
        if (path.charAt(0) === '/') return path;
        return '/' + String(path).replace(/^\.\//, '');
    }

    function appendTenantToUrl(url) {
        const alvo = apiPathRoot(url);
        if (!alvo || slug === 'default') return alvo;
        if (/[?&]tenant=/.test(alvo)) return alvo;
        const sep = alvo.includes('?') ? '&' : '?';
        return alvo + sep + 'tenant=' + encodeURIComponent(slug);
    }

    global.TENANT = {
        slug,
        isDefault: slug === 'default',
        storageKey(prefix) {
            return slug === 'default' ? prefix : prefix + '_' + slug;
        },
        apiUrl(path) {
            return appendTenantToUrl(path);
        },
        fetchJson(path, options) {
            return global.fetch(appendTenantToUrl(path), options);
        }
    };
})(typeof window !== 'undefined' ? window : globalThis);
