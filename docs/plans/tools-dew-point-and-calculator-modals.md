# Калькулятор точки росы + калькуляторы как контекстные модальные компоненты

Ветка: `feat/tools-dew-point-calculator` (от main). Один деплой.

## Задача

1. **Офлайн-калькулятор точки росы** (`/tools/dew-point`) паттерном mix/film/consumption (публичная страница, расчёт в браузере, в precache). **С вердиктом** ISO 8502-4: наносить можно, если t поверхности ≥ точка росы + 3 °C.
2. Калькуляторы — **переиспользуемые компоненты**: ядро каждого в partial; включается и как страница `/tools/*`, и в **модалку по значку** на любой странице.
3. На **странице заполнения отчёта** — значки-калькуляторы **контекстно у полей**: у поля мокрой плёнки (в каждом слое) и у поля точки росы.
4. **Калькулятор мокрой плёнки, открытый из слоя, засеивается покрытием этого слоя** (сухой остаток). Лениво: тянем контекст покрытия по id **только по клику** на значок, и только если в слое выбрано покрытие. 2-й слой → своё покрытие, 3-й → своё. Механизм общий (плёнка → VS, смешивание → ratio).

## Ключевые факты

- Формула точки росы — в домене `Coatings\Domain\Service\DewPointCalculator` (Магнус, Alduchov–Eskridge: `a=17.625`, `b=243.04`; запас `3.0`). Докблок разрешает дублировать на фронте ради офлайна (как FilmThicknessCalculator). Порт в JS теми же числами. `γ=ln(RH/100)+a·T/(b+T)`; `Td=b·γ/(a−γ)`; RH>0.
- Калькуляторы — чистый Stimulus. Данные покрытия для них уже отдаёт `app_cabinet_coating_coating_suggest` в item: `volumeSolid`, `mixingRatio{volume,mass}`, `pack`, `massDensity` (`CoatingSuggestDTO`). Контроллеры применяют это через `applyFromCoating(event)` (плёнка: `event.detail.item.volumeSolid`; смешивание: `event.detail.item.mixingRatio`).
- Слой хранит покрытие как `coating_ref` = hidden id + title (`coating-pick`), VS в контенте НЕ хранится → контекст берём по id при клике.
- Форма заполнения — генерик (`fill.html.twig` макрос `widget` по типам полей из block-definition). Значок — через признак на `Field` (config-first), не хардкодом в шаблоне.

## Развилки (согласовано)

- Точка росы — с вердиктом. Значки контекстно у полей (плёнка — в каждом слое, точка росы — у своего поля). Сеять плёнку из покрытия слоя — **лениво по клику, по id** (без предзагрузки; работает и на перезагрузке отчёта). Офлайн-mix «не работает» — следующей задачей.

---

## Файлы и задачи

### Задача 1. Компонентизация: partials ядра калькуляторов

Ядро = `<div data-controller="X-calculator">` (+hook покрытия +карточка +`<template>`), БЕЗ крошек/h1/intro/details.

**Create** `tools/_mix_calculator.html.twig`, `tools/_film_calculator.html.twig`, `tools/_consumption_calculator.html.twig` — вынести ядро из соответствующих страниц.
**Modify** `tools/mix.html.twig` / `film.html.twig` / `consumption.html.twig` — оставить крошки+h1+intro+`{% include 'tools/_X_calculator.html.twig' %}`+details. Рефактор поведенчески-нейтральный (страницы рендерятся как раньше). Hook покрытия (`{% if app.user %}`) переезжает в partial.

Плюс: в partial у калькуляторов, умеющих принимать покрытие (плёнка, смешивание, расход), на корневом `data-controller`-элементе добавить `data-action="calc-seed->X-calculator#applyFromCoating"` — чтобы лаунчер мог засеять их DOM-событием (см. Задача 6).

### Задача 2. Калькулятор точки росы (ядро + контроллер)

**Create** `tools/_dew_point_calculator.html.twig` — `<div data-controller="dew-point-calculator">`: ввод t воздуха / влажность (%) / t поверхности (опц.); вывод точка росы + вердикт-пилюля (скрыта без t поверхности) + запас. Классы карточек калькуляторов, новых стилей нет.
**Create** `app/assets/controllers/dew_point_calculator_controller.js` — Stimulus, чистый клиент; Магнус 1:1 с `DewPointCalculator`; вердикт `t_пов ≥ Td+3` → «Можно наносить · запас N °C» / иначе «Риск конденсата». Парс decimal — как в mix/film.

### Задача 3. Страница `/tools/dew-point`

**Create** `Controller/Tools/DewPointCalculatorAction.php` — `#[Route('/tools/dew-point', name: 'app_tools_dew')]`, тонкий, `render('tools/dew_point.html.twig')`.
**Create** `tools/dew_point.html.twig` — extends base; крошки+h1+intro+`{% include 'tools/_dew_point_calculator.html.twig' %}`+`<details>` (Магнус + ISO 8502-4).

### Задача 4. Плитки на /tools и /app + precache

**Modify** `tools/index.html.twig` и `app_shell/index.html.twig` — плитка точки росы (`app_tools_dew`, `bi-thermometer-snow`).
**Modify** `Controller/SwAction.php` — `$this->generateUrl('app_tools_dew')` в `$pages` (офлайн).

### Задача 5. Контекст покрытия по id (ленивый фетч)

**Create** `Controller/Coating/CalcContextAction.php` — `#[Route('/cabinet/coating/coating/{id}/calc-context', name: 'app_cabinet_coating_coating_calc_context', methods: ['GET'])]`. Отдаёт по id ту же форму, что item в suggest: `{id, title, volumeSolid, pack, massDensity, mixingRatio}`. Реюз `CoatingSuggestDTO` (или его transformer) — новый Query `GetCoatingCalcContext`/`...ByIds`, либо тонко через существующий поиск по id. Аноним/доступ — как у suggest (эндпоинт под кабинетом). Фетчится только по клику на значок.

### Задача 6. Признак `calculator` на Field + рендер значка + модалки + лаунчер

**Modify** `Field` VO (`Reports/Domain/Block/...Field`) — опциональный `?string $calculator` (`'dew-point'` | `'wet-film'`; при желании `'mix'`/`'consumption'` позже).
**Modify** нужные block-definition (`Reports/Domain/Block/Definition/*`) — пометить поле мокрой плёнки → `'wet-film'`, поле точки росы → `'dew-point'`.
**Modify** `fill.html.twig` (макрос `widget`) — если у поля есть `calculator`: рядом со значением рисовать значок-кнопку лаунчера (`data-controller="calc-launcher"`, `data-calc-launcher-calc-value`, `data-calc-launcher-modal-value`, `data-calc-launcher-context-url-value="{{ path('app_cabinet_coating_coating_calc_context', {id:'__ID__'}) }}"`). Модалки каждого типа калькулятора включить на странице по разу (`_calculator_modal.html.twig` с `{% include 'tools/_X_calculator.html.twig' %}`).
**Create** `components/_calculator_modal.html.twig` — модалка (id, title, partial) с `{% include partial %}` в теле (`modal-dialog-scrollable`).
**Create** `app/assets/controllers/calc_launcher_controller.js`:
- на клик открыть модалку (`bootstrap.Modal`).
- если calc — покрытие-зависимый (`wet-film`/`mix`): найти в своей строке слоя `coating_ref` hidden (`closest` до строки → hidden с id). Если id пуст → просто открыть калькулятор пустым (без фетча). Если есть → `GET context-url` (подставив id) → на корневом элементе калькулятора модалки `dispatchEvent(new CustomEvent('calc-seed', {detail:{item: context}}))` → его `applyFromCoating` применит (VS/ratio) → открыть модалку.
- `dew-point` — просто открыть (без покрытия).
Ленивость: фетч только тут, по клику, при наличии покрытия.

---

## Тесты

**Create** `DewPointCalculatorActionTest` — GET `/tools/dew-point` → 200, есть `data-controller="dew-point-calculator"` + поля.
**Modify** `SwActionTest` — `/tools/dew-point` в precache.
**Create/Modify** `ToolsPagesTest` (если нет) — GET `/tools/mix|film|consumption` → 200 после рефактора на partials.
**Create** `CalcContextActionTest` — GET `/cabinet/coating/coating/{id}/calc-context` (авторизованно) → 200, JSON с `volumeSolid`/`mixingRatio` для реального покрытия; несуществующий id → 404/пусто.
**Modify** `ReportPagesTest` — на fill-странице у поля мокрой плёнки есть значок лаунчера + модалки `#…CalcModal`.
Юнит `DewPointCalculator` — сверить контрольные точки (t=20/RH=60 → Td≈12.0; t=15/RH=80 → Td≈11.6); JS-эквивалент проверить в браузерном смоуке.
JS-расчёт и сеялка PHP-тестами не покрываются → браузерный смоук: точка росы/вердикт; офлайн `/tools/dew-point`; на заполнении — значок у поля плёнки открывает калькулятор с подставленным VS покрытия слоя; разные слои → разные покрытия.

## Гейты

`./run check` + `lint:container` + `lint:twig`. После JS/Twig — `cd app && yarn dev`. Финал — браузерный смоук (в т.ч. офлайн + per-layer подстановка).

## Порядок реализации

1 (partials+рефактор) → 2 (точка росы ядро) → 3 (страница) → 4 (плитки+precache) → 5 (context-endpoint) → 6 (Field.calculator + widget-значок + модалки + calc-launcher) → тесты → сборка → гейты → смоук.

## Заметки

- Офлайн-mix «не работает» — отдельная следующая задача.
- Только существующие `bi-*`/классы карточек калькуляторов, новых стилей не сочинять.
