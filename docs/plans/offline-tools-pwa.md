# Офлайн для раздела «Инструменты» (/tools) — PWA

Ветка `feat/offline-tools-pwa` (от main). Один деплой.

## Задача
Калькуляторы `/tools` (mix/film/consumption + хаб) должны ОТКРЫВАТЬСЯ и СЧИТАТЬ без сети. Данные по
покрытиям офлайн не тянем (это норм) — но сама страница + JS-логика Stimulus обязаны работать.

## Корень бага (репорт 2026-09-15)
`enableVersioning(Encore.isProduction())` → в проде имена ассетов ХЕШИРУЮТСЯ и меняются каждый деплой.
Бандл разбит (`splitEntryChunks`+`enableSingleRuntimeChunk`): `runtime.js` + `vendors-….js` + `app.js`
+ `vendors-….css` + `app.css`. Старый `sw.js` предкэшировал только HTML `/tools`, а хешированный
JS/CSS в precache не попадал (статический `/build/app.js` в проде вообще 404). Офлайн-страница
открывалась, но Stimulus-калькулятор без бандла не заводился.

## Подход A — динамический sw.js (одобрено)
`/sw.js` отдаём контроллером, который подставляет актуальные URL ассетов из entrypoints. Имена
меняются на деплое → байты sw.js меняются → SW сам обновляется и перекэширует свежее. Хеши и
свежесть решены разом (в отличие от статического sw.js, который после деплоя держал бы мёртвые хеши).

## Компоненты
1. **`Shared/Infrastructure/Controller/SwAction`** — `#[Route('/sw.js', methods:['GET'])]`. Инжектит
   `Symfony\WebpackEncoreBundle\Asset\EntrypointLookupInterface`; `reset()` + `getJavaScriptFiles('app')`
   + `getCssFiles('app')` → массив URL ассетов. Рендерит `sw.js.twig` с `assets` + `cacheVersion`
   (`sha1(join assets)[:12]` — меняется с хешами → activate чистит старый кэш). Заголовки:
   `Content-Type: application/javascript`, `Cache-Control: no-cache` (браузер видит новую версию SW
   сразу после деплоя), `Service-Worker-Allowed: /`.
2. **`Templates/sw.js.twig`** — тело SW (перенос из public/sw.js), но:
   - `CACHE = 'app-{{ cacheVersion }}'`.
   - `PRECACHE` = страницы `path('app_tools_*')` + `/offline.html` + иконка + `{{ assets|json_encode|raw }}`.
   - install: индивидуальные `cache.add(...).catch()` (не all-or-nothing `addAll`), `credentials:'omit'`
     (анонимная оболочка в офлайне + публичные ассеты без кук).
   - **fetch-стратегия:**
     - **cache-first** для CACHE_FIRST-набора = ассеты бандла (иммутабельны/хешированы) + offline.html
       + иконка (identity-neutral): отдаём из кэша мгновенно, фолбэк в сеть.
     - **network-first** для всего остального, ВКЛЮЧАЯ страницы `/tools` — залогиненный онлайн видит
       свою оболочку; офлайн навигация фолбэчит на анонимный precache (`caches.match`), там же берутся
       ассеты (cache-first) → калькулятор работает. Приватное/HTML в рантайм-кэш не кладём.
   - Web Push (push/notificationclick/pushsubscriptionchange/бейдж) — переносим 1-в-1.
3. **Удалить `public/sw.js`** — иначе статик перекроет роут (веб-сервер отдаёт файл раньше Symfony).
   Регистрация в `app.js` (`/sw.js`) не меняется, scope остаётся корневым.

## Тесты
- Функциональный `SwActionTest`: GET `/sw.js` → 200, `Content-Type` js, тело содержит `CACHE`-строку,
  `/tools`, минимум один `/build/`-ассет, `addEventListener('install'|'fetch')`.
- SW-логика (офлайн) — браузерный смоук (PHP-тесты JS не гоняют): в DevTools Application → Offline
  открыть `/tools/film` на холодном кэше, убедиться что считает. Отметить в PR как ручную проверку.

## Не в этом деплое (бэклог)
Офлайн-UX (баннер «офлайн», «Ещё» помечает доступное офлайн, линки на калькуляторы из «Ещё»).

Связано: [[project_offline_support_backlog]], [[project_tools_section_design]], [[feedback_no_phpunit_on_frontend]].
