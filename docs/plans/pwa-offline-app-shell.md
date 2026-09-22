# PWA офлайн: публичная оболочка `/app` как стартовый экран

Ветка: `feat/pwa-offline-app-shell` (от main; **отдельно** от `feat/report-search-filter`). Один деплой.

## Проблема (диагноз подтверждён по коду)

PWA установлена на домашний экран iPhone (iOS Safari, standalone). Манифест: `start_url: "/"`.
`HomePageAction`: `/` для залогиненного → **302 на `/cabinet`** (под `IS_AUTHENTICATED`, приватный HTML, SW принципиально его не кэширует). Офлайн `/` в precache нет.
Итог: при тапе по иконке офлайн iOS грузит `start_url=/` → сеть недоступна → приложение не открывает входную дверь и падает **до** того, как пользователь дойдёт до `/tools`. Сами калькуляторы `/tools` в precache есть, но до них не добраться.

Плюс платформа: iOS в standalone на холодном старте часто не поднимает service worker до первого запроса `start_url`; агрессивно вычищает SW-кэш после ~7 дней простоя; кэширует манифест (смена `start_url` подхватывается только при повторном добавлении на экран).

## Решение (вариант 2, согласовано)

Отдельный **публичный, никогда не редиректящий на сервере** маршрут `/app` — «оболочка приложения». PWA открывается на него; он в precache → офлайн открывается из кэша, плитки /tools доступны. Онлайн залогиненного **клиентским JS** (не серверным редиректом — тот сломал бы кэш) перебрасываем в `/cabinet`, чтобы привычка входа в кабинет не менялась.

Согласованные решения:
- Маршрут: **`/app`**.
- Онлайн + залогинен: **форвардить в `/cabinet`** (JS, `location.replace`).
- Наполнение сейчас: **только плитки `/tools`** (+ вход в кабинет). Задел под офлайн-каталог покрытий — на будущее, сейчас не делаем.

Почему чинит: входная дверь PWA перестаёт быть `/ → /cabinet` (приватная, некэшируемая). Дверь — публичный кэшированный экран, открывается офлайн всегда; из него доступны инструменты (офлайн) и кабинет (онлайн).

## Ограничения платформы (задокументировать, не «баг»)

- **Существующие сломанные установки** надо удалить с экрана и добавить заново — iOS кэширует манифест, новый `start_url` иначе не подхватится.
- После (пере)установки нужен **хотя бы один онлайн-запуск**, чтобы SW активировался и наполнил precache; только потом офлайн.
- iOS чистит неиспользуемые web-данные ~через 7 дней → офлайн не гарантирован после долгого простоя.
- Это потолок iOS-PWA, кодом не обходится. Браузерный смоук на реальном iPhone — обязательная часть задачи.

## Эталоны для копирования

- Плитки инструментов: `app/src/Shared/Infrastructure/Templates/tools/index.html.twig` (`tools-hub`/`tools-grid`/`tool-card`, иконки `bi-*`) — копировать 1-в-1, новых стилей не сочинять.
- Тонкий публичный контроллер-рендер: `ToolsIndexAction` (`/tools`, `render('tools/index.html.twig')`).
- Правило: **в Twig без `<script>`** — форвард только через Stimulus-контроллер (`app/assets/controllers/`), не инлайн.

---

## Файлы и задачи

### Задача 1. Контроллер `/app`

**Create** `app/src/Shared/Infrastructure/Controller/AppShellAction.php`
- `#[Route('/app', name: 'app_shell', methods: ['GET'])]`, тонкий, публичный (в `access_control` не добавляем — `/app` не матчит `^/api|^/cabinet|^/user`).
- Инжект `App\Shared\Infrastructure\Security\AuthUserFetcher`.
- **Никакого серверного редиректа** (в отличие от `HomePageAction`): всегда `render('app_shell/index.html.twig', ['authenticated' => $this->userFetcher->isAuthenticated()])`.

### Задача 2. Шаблон оболочки

**Create** `app/src/Shared/Infrastructure/Templates/app_shell/index.html.twig`
- `extends 'base.html.twig'`.
- Корневой контейнер с контроллером форварда:
  ```twig
  <div class="tools-hub"
       data-controller="app-shell"
       data-app-shell-authenticated-value="{{ authenticated ? 'true' : 'false' }}"
       data-app-shell-cabinet-url-value="{{ path('app_cabinet') }}">
  ```
- Внутри — плитки `/tools` (копия `tool-card` из `tools/index.html.twig`: смешивание/расход/толщина) + одна плитка входа:
  - залогинен → «Кабинет» → `path('app_cabinet')`;
  - аноним → «Войти» → `path('app_login')`.
  (`{% if authenticated %}` по серверному флагу.)
- Только существующие классы (`tools-hub`, `tools-grid`, `tool-card`, `tool-card-ic/title/desc`, `bi-*`). Новых CSS не заводить.
- Без `<script>`.

### Задача 3. Stimulus-форвард

**Create** `app/assets/controllers/app_shell_controller.js`
- `static values = { authenticated: Boolean, cabinetUrl: String }`.
- `connect()`: если `this.authenticatedValue && navigator.onLine` → `window.location.replace(this.cabinetUrlValue)`.
  - `replace` (не `assign`) — чтобы «назад» не возвращало на `/app` в цикл.
  - Онлайн network-first отдаёт свежую (авторизованную) страницу → флаг true → форвард. Офлайн отдаёт precache-версию (анонимную, credentials:'omit') → флаг false → остаёмся на хабе. Логика самосогласована.
- Регистрируется автоматически через `controllers.json`. После правки — `cd app && yarn dev`.

### Задача 4. Манифест: `start_url` → `/app`

**Modify** `app/public/icons/site.webmanifest`
- `"start_url": "/app"` (было `"/"`).
- `"scope": "/"` — оставить (SW и навигация по всему сайту).
- `"id"` — оставить `"/"` (идентичность приложения не трогаем; менять `id` заставило бы iOS считать это новым приложением).

### Задача 5. Precache `/app` в service worker

**Modify** `app/src/Shared/Infrastructure/Controller/SwAction.php`
- В массив `$pages` добавить `$this->generateUrl('app_shell')` (рядом с `app_tools_*`). Тогда `/app` попадёт в PRECACHE и network-first-фолбэк найдёт его офлайн.
- Стратегия не меняется: `/app` — страница, отдаётся network-first (в `cacheFirst` не кладём), офлайн-фолбэк на precache.

---

## Тесты

### Задача 6. Функциональный тест `/app`

**Create** `app/tests/Functional/Shared/Infrastructure/Controller/AppShellActionTest.php`
- Аноним: `GET /app` → 200 (НЕ редирект), в HTML есть плитки (ссылки на `app_tools_mix`/`consumption`/`film`) и кнопка «Войти» (`app_login`); `data-app-shell-authenticated-value="false"`.
- Залогинен (ROLE-любой, как в `ReportPagesTest`: создать юзера + `loginUser`): `GET /app` → **200, не редирект** (форвард клиентский, сервер не редиректит); `data-app-shell-authenticated-value="true"`; есть ссылка на `app_cabinet`.

### Задача 7. Расширить `SwActionTest`

**Modify** `app/tests/Functional/Shared/Infrastructure/Controller/SwActionTest.php`
- Ассерт: тело `GET /sw.js` содержит `/app` в PRECACHE (как уже проверяются `/tools`-страницы).

### Задача 8. Браузерный смоук (ручной, iPhone) — обязательный чек-лист

Задокументировать выполнение в этом плане при реализации:
1. Удалить старую PWA с домашнего экрана, открыть сайт в Safari, добавить на экран заново (подхватит `start_url=/app`).
2. Онлайн: запустить PWA с иконки → залогиненного форвардит в кабинет; разлогиненного оставляет на `/app` с плитками. Дать SW активироваться (подождать/перезапустить).
3. Включить авиарежим. Тап по иконке → `/app` открывается из кэша, плитки видны.
4. Открыть калькулятор (`/tools/mix`) офлайн → работает.
5. Если офлайн всё ещё пусто — DevTools/инспекция: SW `activated`? Cache Storage содержит `/app` и `/tools/*`?

## Гейты

`./run check` целиком (style/phpstan/unit/functional) + `lint:container` + `lint:twig`. После JS/Twig — `cd app && yarn dev`. Финал — ручной смоук (Задача 8): PHP-тесты офлайн-поведение iOS не покрывают.

## Порядок реализации

1 → 2 → 3 (оболочка + форвард) → 4 → 5 (манифест + precache) → 6 → 7 (тесты) → сборка ассетов → гейты → ручной смоук на iPhone.

## Заметки

- Веб-`/` (`HomePageAction`) не трогаем — обычные браузерные пользователи не затронуты, меняется только точка входа PWA.
- Реализовывать на отдельной ветке от main; на момент написания плана в дереве висят незакоммиченные правки `feat/report-search-filter` — их развести (закоммитить/смёржить) до старта этой задачи, чтобы не мешать.
