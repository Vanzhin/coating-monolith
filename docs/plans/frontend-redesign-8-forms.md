# Деплой 8A: редизайн форм — форма покрытия + общий каркас форм

> **Для исполнителя:** пошагово. Фронтовый редизайн (Twig/Stimulus/CSS) поверх существующей серверной логики форм — контроллеры/мапперы/команды НЕ трогаем (поведение и контракт полей сохраняются). Верификация: сборка + браузер + существующие контроллер-тесты форм (должны остаться зелёными). Дизайн-линия — soft/без рамок ([[feedback_soft_borderless_design]]).

**Цель:** привести формы редактирования сущностей к дизайн-макету (§6.5 спеки): секции-карточки с якорями, липкий app-bar (назад + заголовок + «Сохранить»), липкая нижняя панель сохранения, чипы-якоря (мобайл) + десктоп-TOC со scroll-spy, поля по дизайн-линии, ввод длительности через bottom-sheet со степперами, новое дерево «Интервал перекрытия» (аккордеон по средам). Плюс общий каркас, переиспользуемый простыми справочными формами.

**Spec:** `docs/plans/frontend-redesign-design.md` §6.5, §6.10. Макет: `.superpowers/brainstorm/*/content/form-coating.html`. Дерево перекрытия — вариант A (аккордеон), согласован визуально.

**Архитектура:** новый общий компонент-каркас `components/form_page.html.twig` (embed: app-bar + якоря + TOC + sticky-save + блок секций) + `form-scrollspy` Stimulus-контроллер (активный якорь/TOC) + CSS `components/form.css`. Форма покрытия и справочники переводятся на этот каркас. Ввод длительности: та же 3-режимная логика в `coating-form` контроллере, презентация меняется modal→bottom-sheet+степперы. Дерево перекрытия: `_recoating_node.html.twig` перестраивается с nav-pills табов на аккордеон-секции (среда = раскрывающаяся секция, материал = вложенная под-карточка).

**Tech Stack:** Twig (embed/blocks), Stimulus (`coating-form`, новый `form-scrollspy`, `coating-tags`, `coating-colors`, `dropdown-portal`), Bootstrap 5.3 (form-switch/form-control под токены, offcanvas-bottom как sheet), CSS-токены.

## Global Constraints

- Серверная логика форм — источник истины; контроллеры/мапперы/команды/имена полей (`tags[][id]`, `colors[i][...]`, `minRecoatingInterval[branches]...[points]`, `dryHeatExposure[...]` и т.д.) НЕ меняем. Меняем только разметку/стиль/презентацию ввода.
- Без JS форма обязана работать (серверная валидация/сабмит — fallback). Якоря/TOC/scroll-spy/sheet — прогрессивное улучшение.
- Дизайн-линия: без рамок, soft-оттенки; поля — Bootstrap `form-control`/`form-select`/`form-switch` под токенами (не кастомные `.fctl/.switch` из макета). Кнопки «Сохранить» — `btn-primary` (основное действие), «Отмена» — soft.
- Существующие контроллер-тесты форм (Coating Update/Add, System, SurfaceTreatment) — зелёные после редизайна.
- Тач-таргеты ≥44px, safe-area insets (нижняя панель), `prefers-reduced-motion`, контраст в обеих темах.

## Карта файлов

Новое:
- Create: `app/src/Shared/Infrastructure/Templates/components/form_page.html.twig` — каркас формы (embed).
- Create: `app/assets/controllers/form_scrollspy_controller.js` — активный якорь/TOC по скроллу.
- Create: `app/assets/styles/components/form.css` — секции-карточки, sticky app-bar/save, якоря, TOC, sheet, поля. Подключить в `app.css`.

Правки:
- Modify: `admin/coating/coating/form.html.twig` — на каркас `form_page`, секции с id, поля под дизайн-линию, длительность → sheet.
- Modify: `admin/coating/coating/_recoating_node.html.twig` — nav-pills табы → аккордеон (вариант A).
- Modify: `components/duration_input.html.twig` + `coating_form_controller.js` — триггер и разметка sheet вместо `#durationModal` (логика 3 режимов/расчёта — reuse).
- Modify: `cabinet/coating/surface_treatment/form.html.twig`, `admin/chemical_resistance/substance/form.html.twig`, `admin/coating/manufacturer/form.html.twig`, `admin/certificate/issuer/form.html.twig` (простые справочники) — на каркас `form_page`.
- Modify: `app/assets/styles/app.css` — `@import "components/form.css"`.

Вне охвата (отдельные под-деплои): **B** — редактор слоёв системы (§6.7, разрез/ISO/степпер/drag); **C** — flash-тосты (§6.10). Форма системы (`coating_system/form.html.twig`) — каркас применим, но слои — в B; в этом деплое форму системы можно перевести на каркас, оставив `_layers_edit` как есть (перевод слоёв — в B).

---

## Task 1: Каркас формы — `form_page.html.twig` + CSS + scroll-spy

**Files:** create `components/form_page.html.twig`, `components/form.css`, `form_scrollspy_controller.js`; `app.css`.

Каркас (embed), параметры: `title`, `back_url`, `save_form_id`, `anchors` (list<{id,label}>), `is_update` (для лейбла «Сохранить»/«Создать»). Структура:
- Липкий app-bar: `‹ назад` + заголовок + кнопка «Сохранить» (submit формы `save_form_id` через `form=save_form_id` или JS `requestSubmit`).
- Мобайл: горизонтальный ряд чипов-якорей (scroll в секцию по id), под app-bar.
- Десктоп (lg+): двухколоночный — липкий TOC слева (список `anchors` со scroll-spy active) + блок контента справа.
- Блок `{% block sections %}` — секции страницы (каждая `<section id=... class="fsec">`).
- Липкая нижняя панель «Отмена / Сохранить» (safe-area).
- Scroll-spy: `data-controller="form-scrollspy"`, targets — секции + ссылки якорей/TOC; активная по IntersectionObserver.

- [ ] **Step 1:** `form.css` — `.fsec` (секция-карточка: surface, rounded, без рамки, тень, отступы, `scroll-margin-top` под app-bar), `.form-appbar` (sticky top, blur), `.form-anchors` (chip-scroll мобайл), `.form-toc` (sticky, десктоп), `.form-savebar` (sticky bottom, safe-area), поля (`.form-switch` под токены, лейблы). Импорт в `app.css`.
- [ ] **Step 2:** `form_page.html.twig` — разметка каркаса с блоками `sections` (+ опц. `appbar_extra`).
- [ ] **Step 3:** `form_scrollspy_controller.js` — IntersectionObserver: активный якорь/TOC; клик по якорю — smooth-scroll (реюз нативного `scroll-margin-top`).
- [ ] **Step 4:** `lint:twig`, `yarn dev`. Коммит `Каркас форм: липкий app-bar, якоря/TOC со scroll-spy, липкое сохранение (components/form_page)`.

---

## Task 2: Дерево «Интервал перекрытия» → аккордеон (вариант A)

**Files:** `admin/coating/coating/_recoating_node.html.twig`, `coating_form_controller.js` (методы addEnv/removeEnv/addBase/removeBase — под новую разметку), `form.css`.

Перестроить с nav-pills табов на аккордеон: «Общее» — всегда открытая секция; каждая среда (атмосфера/погружение/спец.среды) — раскрывающаяся секция (`collapse`) со своими точками и кнопкой удаления среды; внутри среды — вложенные под-карточки материалов (ЛКМ) с точками + кнопка удаления материала. Имена полей (`...[branches][env][default][points]`, `[branches][env][branches][base]...`) и контроллер-контракт СОХРАНИТЬ — меняется только обёртка (табы → collapse-секции) и стиль. Точки — строки «температура → мин-чип / макс-чип» (чипы открывают sheet, Task 3).

- [ ] **Step 1:** Переписать `_recoating_node.html.twig`: root → аккордеон (Общее + секции сред + «+ среда»); env → секция с точками + материалы + «+ материал»; base → под-карточка с точками. `data-recoating-*` и имена полей как были.
- [ ] **Step 2:** Сверить `coating_form_controller.js` addEnv/removeEnv/addBase/removeBase — селекторы под новую разметку (аккордеон-секции вместо табов). При необходимости — обновить только селекторы/шаблоны вставки, логику индексации сохранить.
- [ ] **Step 3:** `yarn dev` + браузер: добавить/убрать среду и материал, точки; сабмит; проверить, что структура полей уходит на бэк как раньше (тест ниже). Коммит.

---

## Task 3: Ввод длительности → bottom-sheet со степперами

**Files:** `components/duration_input.html.twig`, `coating_form_controller.js`, `form.css`.

Заменить триггер+модалку `#durationModal` на bottom-sheet (offcanvas-bottom): сегмент «Значение / Без ограничения / Нет данных» + степперы Дни/Часы/Минуты (−/+). Существующая логика (`onKindChange`, `saveDuration`, `clearDuration`, `calculateDuration`) переиспользуется — меняются только целевые элементы (sheet вместо modal) и степперы вместо голых number-инпутов (степпер пишет в тот же number-инпут). Скрытые поля `[days][hours][minutes][kind]` и чип-триггер — как есть.

- [ ] **Step 1:** Разметка sheet (один общий на форму, как `#durationModal` был общий) + степперы; `duration_input` чип-триггер открывает sheet (data-action на контроллер).
- [ ] **Step 2:** `coating_form_controller.js` — навесить открытие sheet, степперы (−/+ к number-инпуту), reuse save/clear/calculate. Убрать мёртвую modal-разметку.
- [ ] **Step 3:** `yarn dev` + браузер: ввод значения/без ограничения/нет данных, «Рассчитать», применение в чип. Коммит.

---

## Task 4: Форма покрытия на каркас + поля под дизайн-линию

**Files:** `admin/coating/coating/form.html.twig`.

Перевести 7–8 секций на `form_page` (каждая `<section id>` + в `anchors`): Описание, Классификация, Внешний вид, Состав и упаковка, ТСП, Нанесение, Пределы эксплуатации, Время отверждения. Поля — Bootstrap `form-control/form-select`, чекбоксы `isZincRich/isTintable` → `form-switch` под токены. Теги/цвета/деревья — как есть (их контроллеры не трогаем). Сохранение/отмена — в липкой панели каркаса (убрать инлайновые кнопки). Верхний алерт ошибки — редизайн (danger-карточка с иконкой + «Не удалось сохранить» + сообщение) — см. §6.10 (пофайловые серверные ошибки — follow-up, требуют изменений бэка).

- [ ] **Step 1:** Обернуть в `form_page` с `anchors`; секции → `<section id class="fsec">`; убрать нелипкие шапку/кнопки.
- [ ] **Step 2:** Поля под дизайн-линию (switch/контролы), верхний алерт-редизайн.
- [ ] **Step 3:** `yarn dev` + браузер (обе темы, мобайл/десктоп): якоря/TOC/scroll-spy, липкое сохранение, все секции, длительность-sheet, дерево-аккордеон.
- [ ] **Step 4:** Прогнать контроллер-тесты покрытия (`Coating/UpdateAction*`, `Add*`) — зелёные (контракт полей цел). Коммит.

---

## Task 5: Простые справочные формы на каркас

**Files:** `surface_treatment/form.html.twig`, `substance/form.html.twig`, `manufacturer/form.html.twig`, `issuer/form.html.twig`.

Перевести на `form_page` (1–2 секции, липкое сохранение, поля под линию). Простые — без sheet/дерева.

- [ ] **Step 1:** Каждую форму — на каркас (секции + якоря + липкое сохранение). Верхний алерт-редизайн.
- [ ] **Step 2:** `yarn dev` + браузер + контроллер-тесты соответствующих сущностей — зелёные. Коммит.

---

## Task 6: Финальная проверка A

- [ ] **Step 1:** `yarn dev`, `lint:twig` всех тронутых, `phpstan`/`cs-fixer` если менялся PHP (в этом деплое PHP не трогаем — только Twig/JS/CSS).
- [ ] **Step 2:** Свип: форма покрытия и справочники — обе темы, мобайл/десктоп; без JS (серверный сабмит/валидация работают); тач-таргеты, safe-area.
- [ ] **Step 3:** Контроллер-тесты форм (покрытие/система/справочники) — зелёные.

## Self-review / риски

- Форма покрытия — самый сложный шаблон (581 строка) + дерево перекрытия + длительность. Строго: серверный контракт полей не меняем, редизайн — обёртка/стиль/презентация. Любое изменение имён полей → регресс сабмита; ловим контроллер-тестами.
- Дерево-аккордеон: реструктуризация табов→collapse затрагивает `coating-form` селекторы add/remove env/base — аккуратно, по одному, с браузер-проверкой.
- Пофайловые серверные ошибки (§6.10) требуют изменений бэка (сейчас один `error`-стринг) — вынесено в follow-up, в A только редизайн верхнего алерта + клиентские UX-состояния.
- Форма системы: каркас применим сразу, слои (§6.7) — отдельный под-деплой B (разрез/ISO/степпер/drag).
- Flash-тосты (§6.10) — под-деплой C.
</content>
