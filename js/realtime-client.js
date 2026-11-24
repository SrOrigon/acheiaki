/**
 * Cliente de Tempo Real (SSE / WebSocket Pusher-Soketi) + Inscrição Web Push / FCM.
 * Conecta o servidor diretamente ao PedidoZapCelulas para eliminar atraso de polling.
 */
(function (global) {
    'use strict';

    const state = {
        es: null,
        tenant: 'default',
        lastSeq: 0,
        conectado: false,
        handlers: new Set(),
        pushAtivo: false,
    };

    function baseUrl(path) {
        if (global.location.pathname.indexOf('/loja/') !== -1) {
            return '../../' + path.replace(/^\/+/, '');
        }
        return path;
    }

    function detectarTenant() {
        const qs = new URLSearchParams(global.location.search || '');
        const q = (qs.get('tenant') || qs.get('loja') || '').trim();
        if (q) return q;
        const m = global.location.pathname.match(/\/loja\/([^/?#]+)/i);
        if (m && m[1]) return decodeURIComponent(m[1]);
        if (global.TENANT && global.TENANT.slug) return global.TENANT.slug;
        return 'default';
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = global.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function notificarBannerOuSistema(ev) {
        const p = ev && ev.payload ? ev.payload : {};
        const titulo = p.titulo || 'Atualização AcheIAki';
        const msg = p.mensagem || '';
        const url = p.url || '';

        if ('Notification' in global && global.Notification.permission === 'granted' && global.navigator && global.navigator.serviceWorker) {
            global.navigator.serviceWorker.ready.then((reg) => {
                if (reg && typeof reg.showNotification === 'function') {
                    reg.showNotification(titulo, {
                        body: msg,
                        icon: baseUrl('assets/images/acheiaki-icon.jpg'),
                        badge: baseUrl('assets/images/acheiaki-icon.jpg'),
                        data: { url: url },
                        tag: 'acheiaki-push-' + (p.pedido_id || Date.now()),
                    });
                }
            }).catch(() => {});
        }

        try {
            let toast = document.getElementById('aki-realtime-toast');
            if (!toast && document.body) {
                toast = document.createElement('div');
                toast.id = 'aki-realtime-toast';
                toast.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:99999;max-width:340px;background:#231915;color:#fff;padding:14px 16px;border-radius:14px;box-shadow:0 16px 36px rgba(0,0,0,0.28);border-left:4px solid #ea580c;font-family:inherit;font-size:0.9rem;cursor:pointer;transition:opacity .25s ease;';
                document.body.appendChild(toast);
            }
            if (toast) {
                toast.innerHTML = '<strong style="display:block;margin-bottom:4px;color:#fdba74;">🔔 ' + String(titulo).replace(/</g, '&lt;') + '</strong><span>' + String(msg).replace(/</g, '&lt;') + '</span>';
                toast.style.display = 'block';
                toast.style.opacity = '1';
                toast.onclick = function () {
                    if (url) global.location.href = url;
                    toast.style.display = 'none';
                };
                clearTimeout(toast._t);
                toast._t = setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => { toast.style.display = 'none'; }, 260);
                }, 6500);
            }
        } catch (e) { /* ignora ui */ }
    }

    function propagarEvento(ev) {
        if (!ev || !ev.event) return;
        const seq = Number(ev.seq || 0);
        if (seq > state.lastSeq) state.lastSeq = seq;

        if (ev.event === 'push_notice') {
            notificarBannerOuSistema(ev);
        }

        if (global.PedidoZapCelulas && typeof global.PedidoZapCelulas.emitir === 'function') {
            const payload = Object.assign({ tenant: ev.tenant || state.tenant, _fromServer: true }, ev.payload || {});
            global.PedidoZapCelulas.emitir(ev.event, payload);
        }

        state.handlers.forEach((fn) => {
            try { fn(ev); } catch (e) { /* isolado */ }
        });
    }

    function montarAuthQuery(tenant) {
        const qs = new URLSearchParams(global.location.search || '');
        const partes = [];
        try {
            const adminUser = sessionStorage.getItem('admin_user') || '';
            const adminPass = sessionStorage.getItem('admin_pass') || '';
            if (adminUser && adminPass) {
                partes.push('user=' + encodeURIComponent(adminUser));
                partes.push('pass=' + encodeURIComponent(adminPass));
            }
        } catch (e) {}

        let chaveK = qs.get('k') || '';
        if (!chaveK) {
            try {
                const kCoz = (global.TENANT && global.TENANT.storageKey) ? global.TENANT.storageKey('cozinha_k') : 'cozinha_k';
                const kEnt = (global.TENANT && global.TENANT.storageKey) ? global.TENANT.storageKey('entregador_chave') : 'entregador_chave';
                chaveK = sessionStorage.getItem(kCoz) || localStorage.getItem(kEnt) || '';
            } catch (e) {}
        }
        if (chaveK) partes.push('k=' + encodeURIComponent(chaveK));

        const pedId = qs.get('id') || localStorage.getItem('ultimoPedidoId') || '';
        const pedCod = qs.get('c') || localStorage.getItem('ultimoPedidoCodigo') || '';
        if (pedId && pedCod) {
            partes.push('order_id=' + encodeURIComponent(pedId));
            partes.push('c=' + encodeURIComponent(pedCod));
        }
        return partes.length ? ('&' + partes.join('&')) : '';
    }

    function conectarSSE(tenant) {
        state.tenant = tenant || detectarTenant();
        if (typeof global.EventSource === 'undefined') return false;
        if (state.es) {
            try { state.es.close(); } catch (e) {}
        }

        const url = baseUrl('realtime_events.php?tenant=' + encodeURIComponent(state.tenant) + '&since=' + encodeURIComponent(String(state.lastSeq)) + montarAuthQuery(state.tenant));
        const es = new global.EventSource(url);
        state.es = es;

        es.onopen = function () {
            state.conectado = true;
        };

        ['pedido_criado', 'pedido_status', 'pedido_gps', 'cardapio_atualizado', 'avaliacao_publicada', 'push_notice'].forEach((nome) => {
            es.addEventListener(nome, (msg) => {
                try {
                    const parsed = JSON.parse(msg.data);
                    propagarEvento(parsed);
                } catch (e) { /* ignore parse */ }
            });
        });

        es.onerror = function () {
            state.conectado = false;
        };

        return true;
    }

    async function ativarPush(opts) {
        const cfg = opts || {};
        const tenant = cfg.tenant || state.tenant || detectarTenant();
        if (!('Notification' in global)) {
            return { success: false, message: 'Notificações não suportadas neste navegador.' };
        }
        const perm = await global.Notification.requestPermission();
        if (perm !== 'granted') {
            return { success: false, message: 'Permissão de notificação recusada.' };
        }

        let subscriptionPayload = {
            tenant,
            platform: (global.AcheIAkiNativo && global.AcheIAkiNativo.ehNativo()) ? global.AcheIAkiNativo.plataforma() : 'web',
            role: cfg.role || 'cliente',
            telefone: cfg.telefone || '',
            pedido_id: cfg.pedidoId || '',
            c: cfg.codigo || (new URLSearchParams(global.location.search || '').get('c') || localStorage.getItem('ultimoPedidoCodigo') || ''),
            k: cfg.k || (new URLSearchParams(global.location.search || '').get('k') || ''),
            device_id: (localStorage.getItem('aki_device_id') || (() => {
                const id = 'dev_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
                try { localStorage.setItem('aki_device_id', id); } catch (e) {}
                return id;
            })()),
        };

        try {
            const rCfg = await fetch(baseUrl('push_api.php?action=vapid_public&tenant=' + encodeURIComponent(tenant)));
            const dCfg = await rCfg.json();
            const vapid = dCfg && dCfg.vapid_public_key ? String(dCfg.vapid_public_key) : '';
            if (vapid && global.navigator && global.navigator.serviceWorker && 'PushManager' in global) {
                const reg = await global.navigator.serviceWorker.ready;
                const sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapid),
                });
                const j = sub.toJSON();
                subscriptionPayload.endpoint = j.endpoint || '';
                subscriptionPayload.keys = j.keys || {};
            }
        } catch (e) { /* mantém inscrição local/realtime */ }

        try {
            const resp = await fetch(baseUrl('push_api.php?action=subscribe&tenant=' + encodeURIComponent(tenant)), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(subscriptionPayload),
            });
            const data = await resp.json();
            state.pushAtivo = !!(data && data.success);
            return data;
        } catch (err) {
            return { success: false, message: 'Erro de conexão ao registrar notificações.' };
        }
    }

    function escutar(fn) {
        if (typeof fn !== 'function') return () => {};
        state.handlers.add(fn);
        return () => state.handlers.delete(fn);
    }

    function estaConectado() {
        return state.conectado;
    }

    document.addEventListener('DOMContentLoaded', () => {
        conectarSSE(detectarTenant());
    });

    global.AcheIAkiRealtime = {
        conectar: conectarSSE,
        escutar,
        ativarPush,
        estaConectado,
    };
})(window);
