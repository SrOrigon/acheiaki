/**
 * Service Worker AcheIAki (PWA + Cardápio Offline)
 * - App Shell da vitrine e subpáginas de categorias com fallback offline
 * - API pública get_public_store.php em Network-First com fallback em cache por tenant
 * - Imagens de pratos/logos em Cache-First com atualização em segundo plano
 */
const CACHE_NAME = 'acheiaki-app-v13';
const SHELL_ASSETS = [
  'index.html',
  'categoria.html',
  'marketplace.html',
  'css/styles.css',
  'css/acheiaki-design.css',
  'css/ajuda-visivel.css',
  'css/localizacao-gate.css',
  'css/lgpd-consent.css',
  'js/tenant.js',
  'js/offline-cache.js',
  'js/realtime-client.js',
  'js/native-bridge.js',
  'js/store-loader.js',
  'js/store-hydrate.js',
  'js/frete.js',
  'js/boot-vitrine.js',
  'js/ajuda-loja.js',
  'js/preloaders.js',
  'js/script.js',
  'js/celulas-sync.js',
  'js/vitrine-agente.js',
  'js/localizacao-gate.js',
  'js/lgpd-consent.js',
  'js/modo-apresentacao.js',
  'assets/images/placeholder-logo.svg',
  'assets/images/acheiaki-icon.jpg',
  'assets/images/logo.jpeg'
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      Promise.allSettled(
        SHELL_ASSETS.map((asset) =>
          fetch(asset, { cache: 'reload' })
            .then((res) => (res && res.ok ? cache.put(asset, res) : null))
            .catch(() => null)
        )
      )
    )
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  const data = event && event.data;
  if (!data || data.type !== 'CACHE_STORE_ASSETS' || !Array.isArray(data.urls)) return;
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      Promise.allSettled(
        data.urls.slice(0, 120).map((u) => {
          try {
            const urlObj = new URL(u, self.location.origin);
            if (urlObj.origin !== self.location.origin) return null;
            return cache.match(urlObj.href).then((hit) => {
              if (hit) return null;
              return fetch(urlObj.href)
                .then((res) => (res && res.ok ? cache.put(urlObj.href, res) : null))
                .catch(() => null);
            });
          } catch (e) {
            return null;
          }
        })
      )
    )
  );
});

function normalizarChaveStoreApi(url) {
  const clean = new URL(url.href);
  clean.searchParams.delete('v');
  return clean.href;
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // API pública da loja: Network-First com fallback offline por tenant
  if (url.pathname.endsWith('/get_public_store.php') || url.pathname === '/get_public_store.php') {
    const cacheKey = normalizarChaveStoreApi(url);
    event.respondWith(
      fetch(req)
        .then((resp) => {
          if (resp && resp.ok) {
            const copy = resp.clone();
            caches.open(CACHE_NAME).then((c) => c.put(cacheKey, copy)).catch(() => {});
          }
          return resp;
        })
        .catch(() =>
          caches.open(CACHE_NAME).then((c) =>
            c.match(cacheKey).then((hit) => hit || new Response(JSON.stringify({ success: false, offline: true }), {
              status: 503,
              headers: { 'Content-Type': 'application/json' }
            }))
          )
        )
    );
    return;
  }

  // Nunca intercepta demais endpoints PHP (pedidos, escrita, login, admin)
  if (url.pathname.endsWith('.php')) return;

  // Navegação HTML (vitrine, /loja/<slug>/, categorias, marketplace)
  if (req.mode === 'navigate' || url.pathname.endsWith('.html')) {
    event.respondWith(
      fetch(req)
        .then((resp) => {
          if (resp && resp.ok) {
            const copy = resp.clone();
            caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
          }
          return resp;
        })
        .catch(() =>
          caches.open(CACHE_NAME).then((c) =>
            c.match(req, { ignoreSearch: true }).then((hit) => {
              if (hit) return hit;
              if (url.pathname.includes('categoria')) return c.match('categoria.html', { ignoreSearch: true });
              return c.match('index.html', { ignoreSearch: true });
            })
          )
        )
    );
    return;
  }

  // CSS & JS (inclusive carregados com ?v=timestamp): Network-First com fallback sem query
  if (/\.(js|css)$/i.test(url.pathname)) {
    const baseAsset = url.origin + url.pathname;
    event.respondWith(
      fetch(req)
        .then((resp) => {
          if (resp && resp.ok) {
            const copy = resp.clone();
            caches.open(CACHE_NAME).then((c) => c.put(baseAsset, copy)).catch(() => {});
          }
          return resp;
        })
        .catch(() =>
          caches.open(CACHE_NAME).then((c) =>
            c.match(baseAsset, { ignoreSearch: true }).then((hit) => hit || c.match(req, { ignoreSearch: true }))
          )
        )
    );
    return;
  }

  // Imagens e fontes: Cache-First com revalidação silenciosa
  if (/\.(jpg|jpeg|png|webp|svg|woff2|gif)$/i.test(url.pathname)) {
    event.respondWith(
      caches.open(CACHE_NAME).then((cache) =>
        cache.match(req, { ignoreSearch: true }).then((cached) => {
          const net = fetch(req).then((resp) => {
            if (resp && resp.ok) {
              cache.put(req, resp.clone()).catch(() => {});
            }
            return resp;
          }).catch(() => cached);
          return cached || net;
        })
      )
    );
  }
});

self.addEventListener('push', (event) => {
  let payload = { title: 'AcheIAki', body: 'Você tem uma atualização.', url: 'marketplace.html' };
  try {
    if (event.data) {
      const parsed = event.data.json();
      payload = Object.assign(payload, parsed || {});
    }
  } catch (e) {
    if (event.data) payload.body = event.data.text();
  }
  event.waitUntil(
    self.registration.showNotification(payload.title || payload.titulo || 'AcheIAki', {
      body: payload.body || payload.mensagem || '',
      icon: 'assets/images/acheiaki-icon.jpg',
      badge: 'assets/images/acheiaki-icon.jpg',
      data: { url: payload.url || 'marketplace.html' },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = (event.notification && event.notification.data && event.notification.data.url) || 'marketplace.html';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if ('focus' in client) {
          if (client.navigate) client.navigate(targetUrl).catch(() => {});
          return client.focus();
        }
      }
      if (self.clients.openWindow) return self.clients.openWindow(targetUrl);
      return null;
    })
  );
});
