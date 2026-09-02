/*
 * Глобальная CSRF-обвязка для fetch.
 *
 * Сервер (CsrfRequestSubscriber) требует валидный CSRF-токен на всех не-GET запросах
 * cookie-firewall. Здесь один раз патчим window.fetch, чтобы КАЖДЫЙ same-origin
 * не-GET fetch автоматически нёс заголовок X-CSRF-TOKEN. Так ни один JS-контроллер
 * не нужно править по отдельности, и новые тоже защищены по умолчанию.
 *
 * Токен и имя заголовка берём из <meta> в base.html.twig. Импортируется ПЕРВЫМ в app.js
 * (до bootstrap.js и Stimulus), чтобы патч успел встать до первых fetch'ей контроллеров.
 */
const SAFE_METHODS = { GET: true, HEAD: true, OPTIONS: true, TRACE: true };

function meta(name) {
    const el = document.querySelector(`meta[name="${name}"]`);
    return el ? el.getAttribute('content') : null;
}

const token = meta('csrf-token');
const headerName = meta('csrf-header') || 'X-CSRF-TOKEN';

if (token && typeof window.fetch === 'function') {
    const originalFetch = window.fetch.bind(window);

    window.fetch = function (input, init) {
        const opts = init ? { ...init } : {};
        const method = (opts.method
            || (typeof input === 'object' && input && input.method)
            || 'GET').toUpperCase();

        const url = typeof input === 'string' ? input : (input && input.url) || '';
        const sameOrigin = !/^https?:\/\//i.test(url) || url.startsWith(window.location.origin);

        if (!SAFE_METHODS[method] && sameOrigin) {
            const headers = new Headers(
                opts.headers || (typeof input === 'object' && input ? input.headers : undefined) || {},
            );
            if (!headers.has(headerName)) {
                headers.set(headerName, token);
            }
            opts.headers = headers;
            return originalFetch(input, opts);
        }

        return originalFetch(input, init);
    };
}
