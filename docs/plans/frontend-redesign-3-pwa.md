# Деплой 3 «PWA-каркас»: манифест, service worker, установка

> **Для исполнителя:** реализация пошаговая, с проверкой в браузере (DevTools → Application). «Тест» = сборка (`yarn dev`) + проверка манифеста/SW/офлайна/установки в Chrome. PHP-тесты не гоняем (фронт).

**Цель:** сделать приложение устанавливаемым PWA: корректный манифест, service worker с офлайн-оболочкой, кнопка «Установить как приложение», PWA-meta в head.

**Архитектура:** статический манифест (`public/icons/site.webmanifest`) с брендом/иконками; hand-written service worker `public/sw.js` (без Workbox — меньше зависимостей) с network-first для навигации и cache-first для хешированных ассетов + офлайн-fallback; Stimulus-контроллер перехватывает `beforeinstallprompt` и показывает кнопку установки.

**Tech Stack:** Web App Manifest, Service Worker API, Stimulus, Symfony (статика из `public/`, отдаётся caddy).

**Spec:** `docs/plans/frontend-redesign-design.md` (раздел 7 «PWA»).

## Global Constraints

- Строим поверх Деплоя 1–2. Никаких новых JS-зависимостей (Workbox не тянем) — SW пишем руками.
- SW и манифест — статические файлы в `app/public/` (caddy отдаёт их без Encore). `sw.js` — в корне `public/` (scope `/`).
- Иконки уже есть в `public/icons/` — не генерируем, только чиним пути в манифесте.
- Бренд в UI = `1helper` (env APP_NAME). Манифест статический → имя хардкодим `1helper` (при смене бренда — обновить манифест вручную; это один файл).
- Прогрессивно: без поддержки SW/установки — обычный сайт, без ошибок в консоли.
- Коммиты — по режиму исполнения.

## Карта файлов

- Modify: `app/public/icons/site.webmanifest` — заполнить name/short_name, починить пути иконок, добавить maskable, start_url, scope, id.
- Create: `app/public/sw.js` — service worker.
- Create: `app/public/offline.html` — офлайн-fallback (самодостаточная страница, без Encore-ассетов).
- Modify: `app/assets/app.js` — регистрация SW.
- Create: `app/assets/controllers/pwa_install_controller.js` — перехват beforeinstallprompt + показ кнопки.
- Create: `app/src/Shared/Infrastructure/Templates/_shell/_install_button.html.twig` — кнопка установки (скрыта до `beforeinstallprompt`).
- Modify: `app/src/Shared/Infrastructure/Templates/base.html.twig` — PWA-meta в head; кнопка установки в «Ещё».
- Modify: `app/src/Shared/Infrastructure/Templates/home/index.html.twig` — кнопка установки на лендинге.

---

## Task 1: Манифест + PWA-meta

**Files:**
- Modify: `app/public/icons/site.webmanifest`, `app/src/Shared/Infrastructure/Templates/base.html.twig`

- [ ] **Step 1: Переписать манифест.** Имя, короткое имя, починенные абсолютные пути иконок (`/icons/...`), maskable-purpose на 512, start_url/scope/id, theme/background под светлую тему.

```json
{
  "name": "1helper",
  "short_name": "1helper",
  "description": "Каталог покрытий, подбор по среде и сравнение",
  "id": "/",
  "start_url": "/",
  "scope": "/",
  "display": "standalone",
  "theme_color": "#ffffff",
  "background_color": "#ffffff",
  "icons": [
    { "src": "/icons/android-chrome-192x192.png", "sizes": "192x192", "type": "image/png", "purpose": "any" },
    { "src": "/icons/android-chrome-512x512.png", "sizes": "512x512", "type": "image/png", "purpose": "any" },
    { "src": "/icons/android-chrome-512x512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
  ]
}
```

- [ ] **Step 2: PWA-meta в `<head>` base.html.twig** (после `<meta name="view-transition">`):

```twig
    {# PWA: цвет темы (светлая/тёмная), iOS-standalone. Манифест — ниже в этом head. #}
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0d1117" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="1helper">
```

- [ ] **Step 3: Собрать и проверить манифест.** Run: `cd app && yarn dev`. В Chrome DevTools → Application → Manifest: имя «1helper», иконки загружаются (192/512, есть maskable), start_url `/`, display standalone. Ошибок нет.

- [ ] **Step 4: Коммит.**

```bash
git add app/public/icons/site.webmanifest app/src/Shared/Infrastructure/Templates/base.html.twig
git commit -m "PWA-манифест заполнен и починен: имя, иконки с правильными путями, maskable, theme-color по теме"
```

---

## Task 2: Service worker + офлайн

**Files:**
- Create: `app/public/sw.js`, `app/public/offline.html`
- Modify: `app/assets/app.js`

**Interfaces:**
- Produces: `/sw.js` (scope `/`), регистрируется из app.js.

- [ ] **Step 1: Офлайн-страница `public/offline.html`** — самодостаточная (инлайн-стили, без Encore-ассетов, т.к. они могут быть не в кэше).

```html
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Нет сети — 1helper</title>
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
    font-family:-apple-system,"Segoe UI",Roboto,sans-serif; background:#0d1117; color:#e6edf3; text-align:center; padding:24px; }
  .box { max-width:340px; }
  h1 { font-size:20px; font-weight:800; margin:0 0 8px; }
  p { color:#9aa5b1; font-size:14px; line-height:1.5; margin:0 0 20px; }
  button { background:#4d8bf5; color:#0d1117; border:0; border-radius:12px; padding:12px 20px; font-size:14px; font-weight:700; }
</style>
</head>
<body>
  <div class="box">
    <h1>Нет подключения</h1>
    <p>Похоже, пропала сеть. Проверьте соединение и попробуйте снова.</p>
    <button onclick="location.reload()">Повторить</button>
  </div>
</body>
</html>
```

- [ ] **Step 2: Service worker `public/sw.js`.** Network-first для навигаций (fallback → offline.html), cache-first для хешированных ассетов `/build/`, очистка старых кэшей по версии.

```js
/* 1helper service worker (без Workbox). Стратегии:
   - навигации: network-first, при офлайне → закэшированная страница или /offline.html;
   - /build/ (хешированные ассеты Encore): cache-first (иммутабельны);
   - остальное: network, fallback в кэш. */
const CACHE = 'app-v1';
const PRECACHE = ['/offline.html', '/icons/android-chrome-192x192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  // Хешированные ассеты — cache-first.
  if (url.pathname.startsWith('/build/')) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy));
        return res;
      }))
    );
    return;
  }

  // Навигации — network-first, офлайн-fallback.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy));
        return res;
      }).catch(() => caches.match(req).then((hit) => hit || caches.match('/offline.html')))
    );
    return;
  }

  // Остальное — network, fallback в кэш.
  event.respondWith(fetch(req).catch(() => caches.match(req)));
});
```

- [ ] **Step 3: Регистрация SW в `app.js`** (в конец файла):

```js
// Регистрация service worker (PWA). Прогрессивно: где не поддерживается — тихо пропускаем.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => { /* SW не критичен */ });
    });
}
```

- [ ] **Step 4: Собрать и проверить.** Run: `cd app && yarn dev`. В Chrome DevTools → Application → Service Workers: `/sw.js` активирован. Application → Cache Storage: `app-v1` содержит offline.html. Оффлайн-тест: DevTools → Network → Offline → перезагрузить страницу → показывается закэшированная или `/offline.html` (не браузерная ошибка).

- [ ] **Step 5: Коммит.**

```bash
git add app/public/sw.js app/public/offline.html app/assets/app.js
git commit -m "Service worker с офлайн-оболочкой: навигации network-first, ассеты cache-first, страница «Нет сети» вместо ошибки браузера"
```

---

## Task 3: Кнопка установки (beforeinstallprompt)

**Files:**
- Create: `app/assets/controllers/pwa_install_controller.js`, `app/src/Shared/Infrastructure/Templates/_shell/_install_button.html.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/base.html.twig` (в «Ещё»), `app/src/Shared/Infrastructure/Templates/home/index.html.twig` (на лендинге)

- [ ] **Step 1: Контроллер `pwa_install_controller.js`.** Кнопка скрыта, пока браузер не предложит установку; клик — системный prompt.

```js
import { Controller } from '@hotwired/stimulus';

/* Кнопка «Установить как приложение». Скрыта (d-none), пока браузер не выстрелит
   beforeinstallprompt (не поддерживается / уже установлено — остаётся скрытой). */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.deferred = null;
        this.onPrompt = (e) => { e.preventDefault(); this.deferred = e; this.show(); };
        this.onInstalled = () => this.hide();
        window.addEventListener('beforeinstallprompt', this.onPrompt);
        window.addEventListener('appinstalled', this.onInstalled);
    }

    disconnect() {
        window.removeEventListener('beforeinstallprompt', this.onPrompt);
        window.removeEventListener('appinstalled', this.onInstalled);
    }

    show() { if (this.hasButtonTarget) this.buttonTarget.classList.remove('d-none'); }
    hide() { if (this.hasButtonTarget) this.buttonTarget.classList.add('d-none'); }

    async install() {
        if (!this.deferred) return;
        this.deferred.prompt();
        await this.deferred.userChoice;
        this.deferred = null;
        this.hide();
    }
}
```

- [ ] **Step 2: Partial `_shell/_install_button.html.twig`** — переиспользуемая кнопка (класс-модификатор через параметр `class`).

```twig
{# Кнопка установки PWA. Скрыта до beforeinstallprompt (см. pwa-install контроллер).
   Параметр class — доп. классы под контекст (лендинг / «Ещё»). #}
<div data-controller="pwa-install">
    <button type="button"
            class="btn btn-outline-secondary {{ class|default('') }} d-none"
            data-pwa-install-target="button"
            data-action="click->pwa-install#install">
        <i class="bi bi-download me-2"></i>Установить как приложение
    </button>
</div>
```

- [ ] **Step 3: Вставить в «Ещё»** (base.html.twig, в offcanvas-body после зоны «Тема»):

```twig
        {# Установка приложения (появляется, когда браузер это предлагает). #}
        <div class="p-3 rounded-3 bg-body-tertiary">
            {% include '_shell/_install_button.html.twig' with { class: 'w-100' } %}
        </div>
```

- [ ] **Step 4: Вставить на лендинг** (home/index.html.twig, в блок CTA после кнопок «Войти/Регистрация»):

```twig
            {% include '_shell/_install_button.html.twig' with { class: 'btn-sm' } %}
```

- [ ] **Step 5: Собрать и проверить.** Run: `cd app && yarn dev`. В Chrome (desktop, поддерживает beforeinstallprompt): кнопка «Установить как приложение» появляется (в «Ещё» и на лендинге), клик открывает системный диалог установки. После установки — кнопка скрывается. В браузере без поддержки — кнопки нет (остаётся d-none), ошибок в консоли нет.

- [ ] **Step 6: Коммит.**

```bash
git add app/assets/controllers/pwa_install_controller.js app/src/Shared/Infrastructure/Templates/_shell/_install_button.html.twig app/src/Shared/Infrastructure/Templates/base.html.twig app/src/Shared/Infrastructure/Templates/home/index.html.twig
git commit -m "Кнопка «Установить как приложение»: появляется по beforeinstallprompt на лендинге и в «Ещё», ставит PWA системным диалогом"
```

---

## Task 4: Финальная верификация деплоя

- [ ] **Step 1: Сборка.** Run: `cd app && yarn dev`. Без ошибок.
- [ ] **Step 2: Lighthouse (Chrome DevTools → Lighthouse → PWA / Installable).** Приложение проходит «installable» (манифест + SW + иконки). Разобрать замечания, если есть.
- [ ] **Step 3: Установка и запуск.** Установить приложение (кнопка или адресная строка) → запускается в standalone (без адресной строки), иконка на домашнем/десктопе, splash. Навигация внутри работает.
- [ ] **Step 4: Офлайн.** Оффлайн-режим → навигация показывает закэшированное / `/offline.html`, не браузерную ошибку.
- [ ] **Step 5: Обе темы.** theme-color меняется по системной теме; UI консистентен.

## Self-review (покрытие спеки, раздел 7)

- Манифест (name/short_name/display/theme-bg/иконки/пути) → Task 1. Service worker (кэш оболочки + офлайн-fallback, network-first данные / cache-first статика) → Task 2. Установка (beforeinstallprompt + кнопка на лендинге и в «Ещё») → Task 3. View Transitions — уже в Деплое 2.
- Вне деплоя: maskable/splash тонкая настройка под конкретные ассеты — если Lighthouse потребует доп. размеры иконок, вынести в follow-up (нужны новые PNG).

## Открытые точки

- Точность maskable-иконки: текущая 512 может обрезаться в maskable-safe-zone. Если критично — сгенерировать отдельную maskable-иконку с полями (follow-up, нужен ассет).
- iOS splash (`apple-touch-startup-image`) — множество размеров; пока опускаем (iOS покажет белый/по background_color). Добавить при необходимости отдельным follow-up.
