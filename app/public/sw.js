/* 1helper service worker (без Workbox).
   Стратегия — network-first для ВСЕГО same-origin GET: всегда берём свежее из
   сети, кэш обновляем попутно и используем только как офлайн-fallback (навигации
   → закэшированная страница или /offline.html). Так исключаем протухание стилей
   в dev (Encore там не хеширует имена, cache-first отдавал бы старый app.css). */
const CACHE = 'app-v6';
// Раздел «Инструменты» офлайн-first: страницы предкэшируем, чтобы калькулятор открывался
// без сети даже на холодном кэше (JS/CSS-бандл подтянется network-first при первом заходе).
const PRECACHE = ['/offline.html', '/icons/android-chrome-192x192.png', '/tools', '/tools/mix', '/tools/film', '/tools/consumption'];

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

/* ===== Web Push ===== */
// Приходит пуш (даже без открытой вкладки) → показываем системное уведомление.
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(data.title || 'Уведомление', {
        body: data.body || '',
        icon: '/icons/android-chrome-192x192.png',
        data: { url: data.url || '/' },
    }));
});

// Клик по уведомлению → сфокусировать существующее окно PWA или открыть новое.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            for (const w of wins) {
                if (w.url.includes(url) && 'focus' in w) return w.focus();
            }
            return self.clients.openWindow(url);
        })
    );
});

// Подписка протухла/сменилась → переподписываемся тем же ключом и досылаем на бэк.
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil((async () => {
        try {
            const key = event.oldSubscription && event.oldSubscription.options
                ? event.oldSubscription.options.applicationServerKey
                : undefined;
            const sub = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
            await fetch('/cabinet/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(sub),
            });
        } catch (e) {
            // best-effort: если не вышло — подпишемся при следующем заходе через кнопку
        }
    })());
});
