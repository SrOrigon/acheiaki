/**
 * Service Worker leve AcheIAki (PWA)
 * Estratégia Network-First para páginas/APIs (dados sempre frescos) e Cache-First para ícones/fontes estáticas.
 */
const CACHE_NAME = 'acheiaki-app-v2';
const STATIC_ASSETS = [
  'assets/images/placeholder-logo.svg',
  'css/acheiaki-design.css?v=1'
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).catch(() => {})
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Nunca intercepta endpoints PHP/API
  if (url.pathname.endsWith('.php')) return;

  if (/\.(jpg|jpeg|png|webp|svg|woff2)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then((cached) => {
        if (cached) return cached;
        return fetch(req).then((resp) => {
          if (resp && resp.ok) {
            const copy = resp.clone();
            caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
          }
          return resp;
        });
      })
    );
  }
});
