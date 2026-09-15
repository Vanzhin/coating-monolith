import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.css';
import '@yaireo/tagify/dist/tagify.css';

// inline-скрипты в шаблонах зовут глобальный bootstrap.* (Tooltip/Modal/Offcanvas/Alert/Collapse) —
// пробрасываем модуль в window, иначе ReferenceError: bootstrap is not defined.
window.bootstrap = require('bootstrap');

// Глобальная инициализация tooltip'ов (перенесено из inline-<script> base.html.twig).
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new window.bootstrap.Tooltip(el));
});

// Регистрация service worker (PWA). Прогрессивно: где не поддерживается — тихо пропускаем.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => { /* SW не критичен */ });
    });
}

// Бейдж на иконке PWA (ставит sw.js из payload = число непрочитанных). Когда приложение открыто/
// на переднем плане — считаем уведомления увиденными: помечаем прочитанным на сервере (сброс
// счётчика, в т.ч. между устройствами) и гасим бейдж. POST только для залогиненного — маркер
// has-app-shell на <body> ставит base.html.twig под {% if app.user %}.
let lastReadPostAt = 0;
function markNotificationsSeen() {
    if (navigator.clearAppBadge) {
        navigator.clearAppBadge().catch(() => { /* бейдж не критичен */ });
    }
    // POST throttl'им: visibilitychange частит (alt-tab), а сброс идемпотентен — не чаще раза в 30с.
    const now = Date.now();
    if (now - lastReadPostAt < 30000) {
        return;
    }
    lastReadPostAt = now;
    if (document.body.classList.contains('has-app-shell')) {
        fetch('/cabinet/notifications/read', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).catch(() => { /* не критично */ });
    }
}
window.addEventListener('load', markNotificationsSeen);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        markNotificationsSeen();
    }
});
