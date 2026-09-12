# Деплой 2 — раздел «Инструменты» + калькулятор смешивания (ручной)

Часть арки — см. `docs/plans/tools-mixing-calculator-overview.md`. Ветка
`feat/tools-mixing-calculator` (стеком поверх Деплоя 1). Только презентация + клиентский JS —
серверных вычислений и Application/Domain-слоя здесь нет (калькулятор считает в браузере).

## Что делаем

- Хаб `/tools`: сетка калькуляторов (Смешивание — живой, Расход материала и Толщина мокрой
  плёнки — «скоро», без отдельных страниц).
- Страница `/tools/mix`: калькулятор смешивания, ручной ввод, офлайн, SEO. Автоподстановка из
  покрытия и состояния из/без пропорции — Деплой 3 (сейчас: аноним видит приманку, любой
  вводит пропорцию руками).
- Входы: пункт «Инструменты» в сайдбаре + шторке «Ещё» (не в таб-бар); ссылка в герое лендинга.
- Офлайн: страницы раздела в `PRECACHE` sw.js. SEO: title/meta/H1/описание/`<details>`/sitemap.

## Стиль (жёстко)

Дизайн-система приложения, **монохромные `bi-*`, без цветных иконок/эмодзи**. Переиспользуем
`bg-body-tertiary`, `--sunken`, `.kv-grid/.kvi`, `.btn-soft-*`/`.btn-primary`, `.land-*`,
оболочку `_shell/*`. Новый CSS — минимум, только под строки калькулятора; в
`assets/styles/components/tools.css` + `@import` в `app.css`.

## Шаги (по TDD/по шагам, показываю результат после каждого)

### Шаг 1. Роуты + тонкие контроллеры + хаб
- `src/Shared/Infrastructure/Controller/Tools/ToolsIndexAction.php` — `#[Route('/tools',
  name: 'app_tools_index', methods: ['GET'])]`, `__invoke` рендерит `tools/index.html.twig`.
  Публичный (без auth-проверок, без редиректа авторизованных). Per-action контроллер.
- `src/Shared/Infrastructure/Controller/Tools/MixCalculatorAction.php` — `#[Route('/tools/mix',
  name: 'app_tools_mix', methods: ['GET'])]` → `tools/mix.html.twig`.
- Роуты подхватит существующий resource-loader (`config/routes.yaml`, `Shared/.../Controller/`).
- `Templates/tools/index.html.twig`: extends base; `block title` (SEO); H1 «Инструменты» +
  lead; сетка карточек. Смешивание — ссылка на `app_tools_mix`; Расход/WFT — карточки «скоро»
  (не ссылки). Иконки `bi-*` монохром (напр. `bi-droplet-half`/`bi-bucket`/`bi-rulers` —
  подобрать существующие BI).
- Функц. смоук-тест: `tests/Functional/.../Tools/ToolsPagesTest::test_tools_index_public_200`.

### Шаг 2. Страница калькулятора (разметка + SEO)
- `Templates/tools/mix.html.twig`: extends base; `block title` = «Калькулятор смешивания
  компонентов — 1helper»; meta description; хлебные крошки (Инструменты › Смешивание); H1;
  короткий видимый intro; `{% if not app.user %}` — приманка «Мы, кажется, не знакомы…»
  [Войти → `app_login`]; карточка калькулятора (строки компонентов: имя + «часть» input +
  «кол-во» plate; строка «Итого»; «＋ добавить компонент»); `<details>` «Как рассчитать
  смешивание» с длинным SEO-текстом.
- Смоук-тест: `GET /tools/mix` 200, содержит H1 и `<details>`.

### Шаг 3. Клиентский расчёт (Stimulus) + CSS
- `assets/controllers/mix_calculator_controller.js`: держит parts[] и опорное «кол-во».
  «C-логика»: ввод количества в любую строку/«Итого» делает её опорной → `scale =
  amount/part` (или `total/sum(parts)`), остальные = `part*scale`. Без единиц. Добавление/
  удаление компонента (мин. 2). Targets: rows, part-inputs, qty-fields, total.
- `assets/styles/components/tools.css`: строки калькулятора, обводка опорного поля
  (`--bs-primary`), плитки количеств (стиль `.kvi`/`--sunken`). `@import` в `app.css`.
- Верификация: `cd app && yarn dev`; браузер (десктоп+мобайл): ввод в любую ячейку
  пересчитывает; добавление 3-го компонента; опорное поле обведено.

### Шаг 4. Навигация + вход с лендинга
- `Templates/_shell/_sidebar.html.twig` — пункт «Инструменты» (`bi-calculator`,
  `app_tools_index`), active при `app_tools*`. Отдельным пунктом (не через `nav_items`).
- `base.html.twig` `#mainMenu` (шторка «Ещё») — `list-group-item` «Инструменты». **НЕ**
  трогаем `_bottom_nav`/`nav_items` (иначе появится в таб-баре).
- `Templates/home/index.html.twig` — в `.land-hero` после `.land-feats` акцентная ссылка
  «Инструменты — калькуляторы без входа →» на `app_tools_index` (видна на десктопе и мобиле).
  Класс — существующий или лёгкий, `bi-calculator` монохром.

### Шаг 5. Офлайн
- `public/sw.js` — добавить `'/tools'` и `'/tools/mix'` в `PRECACHE`. JS/CSS-бандл
  кэшируется network-first при первом заходе. Проверить: офлайн-режим в DevTools → страница
  открывается и калькулятор считает.

### Шаг 6. SEO-обвязка
- В `base.html.twig` — блок `meta description` (если нет — добавить `{% block meta_description %}`
  и вывод `<meta name="description">`), заполнить на страницах раздела.
- `sitemap.xml`: если в проекте есть генерация sitemap — добавить `/tools`, `/tools/mix`; если
  нет — зафиксировать как SEO-follow-up (не блокер деплоя). Проверить, что `robots` не
  запрещает `/tools`.

### Шаг 7. Верификация
`./run check` (style/phpstan/unit/functional) + `yarn dev` + браузер десктоп/мобайл.

## Зависимости — проверить в коде на месте (память могла устареть)
- `base.html.twig`: формат `block title`, наличие блока meta description, структура `#mainMenu`,
  include install-button.
- `_shell/_sidebar.html.twig`: классы пункта (`.app-nav-item`), разделители групп.
- Роуты `app_login`, `app_sign_up` (есть в `home/index.html.twig`).
- Наличие/генерация `sitemap.xml`, содержимое `robots`.
- Именование Encore-ассетов (для precache — по отчёту разведки плоские `app.js`/`app.css`).

## Файлы
- Новые: `Controller/Tools/ToolsIndexAction.php`, `Controller/Tools/MixCalculatorAction.php`,
  `Templates/tools/index.html.twig`, `Templates/tools/mix.html.twig`,
  `assets/controllers/mix_calculator_controller.js`, `assets/styles/components/tools.css`,
  `tests/Functional/.../Tools/ToolsPagesTest.php`.
- Правки: `assets/styles/app.css` (@import), `public/sw.js` (PRECACHE),
  `Templates/_shell/_sidebar.html.twig`, `base.html.twig` (#mainMenu + meta desc),
  `Templates/home/index.html.twig` (ссылка в герой).

## Отложено (Деплой 3 / отдельные задачи)
- Поиск по покрытиям + автоподстановка `MixingRatio` + состояния из/без пропорции + Объём/Масса.
- Калькуляторы расхода и толщины мокрой плёнки.
