/* 1helper service worker (без Workbox).
   Стратегия — network-first для ВСЕГО same-origin GET: всегда берём свежее из
   сети, кэш обновляем попутно и используем только как офлайн-fallback (навигации
   → закэшированная страница или /offline.html). Так исключаем протухание стилей
   в dev (Encore там не хеширует имена, cache-first отдавал бы старый app.css). */
const CACHE = 'app-v2';
const PRECACHE = ['/offline.html', '/icons/android-chrome-192x192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;

    event.respondWith(
        fetch(req)
            .then((res) => {
                // Кэшируем свежую копию (для офлайна). Только успешные ответы.
                if (res && res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(req, copy));
                }
                return res;
            })
            .catch(() => caches.match(req).then((hit) => {
                if (hit) return hit;
                if (req.mode === 'navigate') return caches.match('/offline.html');
                return Response.error();
            }))
    );
});
