// CSRF-патч fetch — ПЕРВЫМ, до Stimulus/bootstrap, чтобы X-CSRF-TOKEN стоял на первых же fetch.
import './csrf.js';
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
