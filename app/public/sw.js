/* 1helper service worker (без Workbox).
   Стратегия — network-first для same-origin GET: всегда берём свежее из сети,
   ответ используем как офлайн-fallback. В рантайме в кэш кладём ТОЛЬКО статику
   (см. isCacheablePath): HTML-страницы (в т.ч. авторизованная оболочка с email
   юзера), JSON и приватное туда НЕ попадают — иначе на общем устройстве офлайн
   отдал бы чужую идентичность/данные. Офлайн-оболочку страниц даёт PRECACHE,
   который тянется АНОНИМНО (без кук), поэтому без чьей-либо шапки. Смена версии
   кэша ниже вычищает старый (app-v6) кэш, куда приватное уже могло попасть. */
const CACHE = 'app-v7';
// Раздел «Инструменты» офлайн-first: страницы предкэшируем, чтобы калькулятор открывался
// без сети даже на холодном кэше (JS/CSS-бандл подтянется network-first при первом заходе).
const PRECACHE = ['/offline.html', '/icons/android-chrome-192x192.png', '/tools', '/tools/mix', '/tools/film', '/tools/consumption'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            // credentials:'omit' — тянем без кук: в офлайн-кэше должна лежать анонимная
            // оболочка (без email/ссылок в кабинет), кто бы ни был залогинен при установке.
            .then((c) => c.addAll(PRECACHE.map((u) => new Request(u, { credentials: 'omit' }))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

// Что безопасно и осмысленно держать в офлайн-кэше. Приватное и динамику не кэшируем.
function isCacheableResponse(res) {
    // Только успешный полный ответ своего origin: не 206 (Range) и не redirect/opaque —
    // Cache.put на таких падает.
    return res.status === 200 && res.type === 'basic';
}

function isCacheablePath(url) {
    // Кэшируем в рантайме ТОЛЬКО статику. HTML (в т.ч. авторизованная оболочка),
    // JSON и приватное не кэшируем; офлайн-страницы даёт анонимный PRECACHE.
    if (url.search) return false; // ?query — не плодим варианты одного ресурса
    return /\.(css|js|mjs|png|jpe?g|svg|gif|ico|woff2?|ttf|webp)$/.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;

    event.respondWith(
        fetch(req)
            .then((res) => {
                if (isCacheableResponse(res) && isCacheablePath(url)) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
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

// Бейдж на иконке PWA = число непрочитанных, приходит с сервера в payload.badge. Ставит SW на пуш,
// сбрасывает приложение при открытии (см. app.js). Где Badging API нет — тихо пропускаем.
async function applyBadge(badge) {
    if (typeof badge !== 'number' || !self.navigator || typeof self.navigator.setAppBadge !== 'function') {
        return;
    }
    try {
        if (badge > 0) {
            await self.navigator.setAppBadge(badge);
        } else if (typeof self.navigator.clearAppBadge === 'function') {
            await self.navigator.clearAppBadge();
        }
    } catch (e) {
        // бейдж не критичен
    }
}

// Сообщаем открытым вкладкам о новом уведомлении, чтобы обновить бейдж вживую (без перезагрузки).
// Слушает notifications_live_controller на <body>. unread — актуальное число непрочитанных.
async function notifyClients(unread) {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) {
        w.postMessage({ type: 'notification', unread: typeof unread === 'number' ? unread : null });
    }
}

// Приходит пуш (даже без открытой вкладки) → показываем системное уведомление + бейдж + вкладкам.
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    event.waitUntil((async () => {
        await self.registration.showNotification(data.title || 'Уведомление', {
            body: data.body || '',
            icon: '/icons/android-chrome-192x192.png',
            data: { url: data.url || '/' },
        });
        await applyBadge(data.badge);
        await notifyClients(data.badge);
    })());
});

// Клик по уведомлению → сфокусировать существующее окно PWA или открыть новое.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    // Уведомления ведут в раздел (пер-уведомление url нет): фокусируем открытое окно на нём либо открываем.
    const url = '/cabinet/notifications';
    event.waitUntil((async () => {
        const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const w of wins) {
            if (new URL(w.url).pathname === url && 'focus' in w) {
                return w.focus();
            }
        }
        return self.clients.openWindow(url);
    })());
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
