/**
 * Ponte Nativa Capacitor (iOS & Android) + Fallback WakeLock / Fila GPS Offline para PWA.
 * Garante GPS em segundo plano para entregadores e Push nativo FCM/APNs nos apps das lojas.
 */
(function (global) {
    'use strict';

    let wakeLockSentinel = null;
    const GPS_QUEUE_KEY = 'aki_offline_gps_queue';

    function cap() {
        return global.Capacitor || null;
    }

    function ehNativo() {
        const c = cap();
        try {
            return !!(c && typeof c.isNativePlatform === 'function' && c.isNativePlatform());
        } catch (e) {
            return false;
        }
    }

    function plataforma() {
        const c = cap();
        try {
            if (c && typeof c.getPlatform === 'function') return c.getPlatform();
        } catch (e) {}
        return 'web';
    }

    async function ativarWakeLock() {
        try {
            if ('wakeLock' in global.navigator && !wakeLockSentinel) {
                wakeLockSentinel = await global.navigator.wakeLock.request('screen');
                wakeLockSentinel.addEventListener('release', () => {
                    wakeLockSentinel = null;
                });
            }
        } catch (e) { /* não suportado ou economia de bateria */ }
    }

    function liberarWakeLock() {
        try {
            if (wakeLockSentinel) {
                wakeLockSentinel.release();
                wakeLockSentinel = null;
            }
        } catch (e) {}
    }

    function enfileirarPontoOffline(ponto) {
        try {
            const lista = JSON.parse(localStorage.getItem(GPS_QUEUE_KEY) || '[]');
            const arr = Array.isArray(lista) ? lista : [];
            arr.push(Object.assign({ ts: Date.now() }, ponto));
            localStorage.setItem(GPS_QUEUE_KEY, JSON.stringify(arr.slice(-50)));
        } catch (e) {}
    }

    function drenarPontosOffline(senderFn) {
        if (typeof senderFn !== 'function') return;
        try {
            const lista = JSON.parse(localStorage.getItem(GPS_QUEUE_KEY) || '[]');
            if (!Array.isArray(lista) || !lista.length) return;
            localStorage.removeItem(GPS_QUEUE_KEY);
            const ultimo = lista[lista.length - 1];
            if (ultimo && typeof ultimo.lat === 'number' && typeof ultimo.lng === 'number') {
                senderFn(ultimo.lat, ultimo.lng);
            }
        } catch (e) {}
    }

    async function iniciarGpsBackground(onLocation, onError) {
        const c = cap();
        const bgGeo = c && c.Plugins && c.Plugins.BackgroundGeolocation;

        if (ehNativo() && bgGeo && typeof bgGeo.addWatcher === 'function') {
            try {
                const watcherId = await bgGeo.addWatcher(
                    {
                        backgroundMessage: 'Rastreamento de entrega ativo para o cliente no mapa.',
                        backgroundTitle: 'AcheIAki Entregador',
                        requestPermissions: true,
                        stale: false,
                        distanceFilter: 12,
                    },
                    (location, error) => {
                        if (error) {
                            if (typeof onError === 'function') onError(error);
                            return;
                        }
                        if (location && typeof onLocation === 'function') {
                            onLocation({
                                coords: {
                                    latitude: location.latitude,
                                    longitude: location.longitude,
                                    accuracy: location.accuracy || 15,
                                },
                                nativeBackground: true,
                            });
                        }
                    }
                );
                return { mode: 'capacitor-bg', id: watcherId };
            } catch (e) { /* fallback web */ }
        }

        await ativarWakeLock();
        if (!global.navigator || !global.navigator.geolocation) {
            if (typeof onError === 'function') onError({ code: 2 });
            return null;
        }
        const webWatchId = global.navigator.geolocation.watchPosition(
            (pos) => {
                if (typeof onLocation === 'function') onLocation(pos);
            },
            (err) => {
                if (typeof onError === 'function') onError(err);
            },
            { enableHighAccuracy: true, maximumAge: 8000, timeout: 20000 }
        );
        return { mode: 'web-wakelock', id: webWatchId };
    }

    async function pararGpsBackground(handle) {
        liberarWakeLock();
        if (!handle) return;
        const c = cap();
        const bgGeo = c && c.Plugins && c.Plugins.BackgroundGeolocation;
        if (handle.mode === 'capacitor-bg' && bgGeo && typeof bgGeo.removeWatcher === 'function') {
            try { await bgGeo.removeWatcher({ id: handle.id }); } catch (e) {}
            return;
        }
        if (handle.id != null && global.navigator && global.navigator.geolocation) {
            global.navigator.geolocation.clearWatch(handle.id);
        }
    }

    async function inicializarPushNativo(tenant, role) {
        const c = cap();
        const pushPlugin = c && c.Plugins && c.Plugins.PushNotifications;
        if (!ehNativo() || !pushPlugin) return false;
        try {
            const perm = await pushPlugin.requestPermissions();
            if (perm && perm.receive === 'granted') {
                await pushPlugin.register();
                pushPlugin.addListener('registration', (token) => {
                    fetch('push_api.php?action=subscribe&tenant=' + encodeURIComponent(tenant || 'default'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            tenant: tenant || 'default',
                            platform: plataforma(),
                            role: role || 'cliente',
                            fcm_token: token && token.value ? token.value : '',
                        }),
                    }).catch(() => {});
                });
                return true;
            }
        } catch (e) {}
        return false;
    }

    global.AcheIAkiNativo = {
        ehNativo,
        plataforma,
        iniciarGpsBackground,
        pararGpsBackground,
        enfileirarPontoOffline,
        drenarPontosOffline,
        inicializarPushNativo,
        ativarWakeLock,
    };
})(window);
