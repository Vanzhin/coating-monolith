# Деплой 8B: редизайн формы системы + редактор слоёв (карточки, разрез, drag, степпер)

> **Для исполнителя:** пошагово. Фронтовый редизайн (Twig/Stimulus/CSS) поверх существующей серверной логики. Контроллеры/команды/имена полей НЕ меняем — только разметку/стиль/презентацию. Верификация: сборка + браузер + контроллер-тесты системы (Add/Update — зелёные). Дизайн-линия — soft/без рамок ([[feedback_soft_borderless_design]]), каркас форм из деплоя 8A ([[docs/plans/frontend-redesign-8-forms.md]]).

**Цель:** форму системы покрытий перевести на каркас `form_page`; редактор слоёв — с горизонтальных строк на карточки слоёв с визуальным разрезом сверху, ТСП-степпером, drag-перетаскиванием (за grip, плюс кнопки ↑/↓), удалением и чипом цвета. Соответствие ISO в редакторе не показываем (считается доменом на сервере — остаётся на карточке/превью сохранённой системы).

**Spec:** `docs/plans/frontend-redesign-design.md` §6.7. Макет: `.superpowers/brainstorm/*/content/system-editor.html`.

**Архитектура:** форма `coating_system/form.html.twig` оборачивается в `components/form_page.html.twig` (app-bar + якоря/TOC + липкое сохранение, как форма покрытия в 8A). Редактор слоёв `_layers_edit.html.twig` перерисовывается в карточки + разрез; логика в `coating_system_layers_edit_controller.js` (расширяем: степпер ТСП, pointer-drag, live-рендер разреза, renumber — как есть). Контроль цвета (`layer_color_controller.js`, Tagify) НЕ переписываем — только перестилизуем в компактный чип карточки (сохраняет колеруемость / create-модалку / регидрацию). ТСП-степпер и разрез — новый CSS в `components/system-editor.css`.

**Tech Stack:** Twig (embed/blocks/template), Stimulus (`coating-system-layers-edit`, `layer-color`, `async-typeahead`, `surface-treatment-modal`, `color-create-modal`), Bootstrap 5.3, Pointer Events API (drag без библиотеки), CSS-токены.

## Global Constraints

- Серверный контракт слоёв НЕ меняем: `layers[i][coatingId]`, `layers[i][dft]`, `layers[i][colorId]` в текущем DOM-порядке → `ReplaceLayersCommand` при submit. При add/remove/move/drag индексы `[i]` пересчитываются (`_renumber`).
- Мета слоя (производитель / основа / допустимый диапазон / Zn(R)) и hex цвета известны с сервера только для СУЩЕСТВУЮЩИХ слоёв. Для добавленных в браузере слой показывает лишь название + ТСП + выбранный цвет; полная мета появляется после сохранения (round-trip). Разрез для нового слоя — полоса выбранного цвета либо нейтральная заглушка.
- ISO-плашку в редакторе НЕ рендерим (нельзя пересчитать клиентом без дубля домена).
- Цвет: оставляем `layer-color` (Tagify) as-is по логике; меняем только его CSS-обёртку под чип карточки. Никакого нового color-picker'а.
- Дизайн-линия: карточки/чипы/степперы без рамок-линий — оттенок + тень; кнопки soft. Тач-таргеты ≥44px, `prefers-reduced-motion`, обе темы, safe-area (липкое сохранение — из каркаса 8A).
- Контроллер-тесты системы (`CoatingSystem/AddActionTest`, `UpdateActionTest`) — зелёные после редизайна (контракт полей цел).

## Карта файлов

Новое:
- Create: `app/assets/styles/components/system-editor.css` — разрез (`.xsec`/`.band`/`.substrate`), карточки слоёв (`.lcard`/`.lgrip`/`.lswatch`/`.ldft`/`.laddr`), чип цвета, «+ Добавить слой». Подключить в `app.css`.

Правки:
- Modify: `cabinet/coating/coating_system/form.html.twig` — на каркас `form_page`; секции с id/якорями; error-alert `.form-alert`; сохранить модалки (surface-treatment, color-create) как соседей формы под нужными контроллерами.
- Modify: `cabinet/coating/coating_system/_layers_edit.html.twig` — строки → карточки + разрез сверху + «+ Добавить слой»; `<template>` карточки обновить.
- Modify: `app/assets/controllers/coating_system_layers_edit_controller.js` — добавить `stepDft`, pointer-drag (`dragStart`/`dragMove`/`dragEnd`), live-рендер разреза (`_renderCrossSection` + MutationObserver на список), сохранить `append/remove/moveUp/moveDown/_renumber`.
- Modify: `app/assets/styles/...` — CSS чипа `layer-color` (Tagify) под карточку (в `system-editor.css` или рядом с существующими tagify-правилами).
- Modify: `app/assets/styles/app.css` — `@import "components/system-editor.css"`.

Вне охвата: пересчёт ISO в редакторе; отдельный bottom-sheet выбора цвета (оставляем Tagify-дропдаун). Форма системы — единственная в этом деплое.

---

## Task 1: Форма системы на каркас `form_page`

**Files:** `cabinet/coating/coating_system/form.html.twig`.

Обернуть в `{% embed 'components/form_page.html.twig' %}` (как форма покрытия в 8A): app-bar (‹ назад · заголовок · Сохранить), липкая нижняя панель, секции-карточки `.fsec` с якорями. Секции: Описание (`sec-meta`), Подготовка поверхности (`sec-prep`), Слои (`sec-layers`), Теги (`sec-tags`). Внешний `data-controller="surface-treatment-modal"` + `<form id="coating-system-form">` + модалки (surfaceTreatmentModal, color_create_modal) — соседи внутри `{% block sections %}`, чтобы targets попадали в scope. Верхний алерт → `.form-alert`. Убрать инлайн-шапку и нижние кнопки (даёт каркас).

- [ ] **Step 1:** Обернуть в `form_page` c `anchors`; секции → `<div id class="... fsec" data-form-scrollspy-target="section">`; `<form id="coating-system-form">`; save_form_id → его id. `surface-treatment-modal`-обёртка вокруг формы+модалок внутри `sections`.
- [ ] **Step 2:** `error`-alert → `.form-alert` (защитно `error is defined and error`).
- [ ] **Step 3:** `lint:twig`; браузер: рендер, якоря/TOC, липкое сохранение, inline-создание подготовки поверхности (модалка), submit.
- [ ] **Step 4:** `CoatingSystem/AddActionTest`, `UpdateActionTest` — зелёные (рендер + submit). Коммит-точка.

---

## Task 2: Слои — карточки + ТСП-степпер + удаление + ↑/↓ + «Добавить слой»

**Files:** `_layers_edit.html.twig`, `coating_system_layers_edit_controller.js`, `system-editor.css`.

Заменить горизонтальные строки на карточки `.lcard`: grip-ручка (для drag, Task 4), цвет-свотч (Task 5), тело (название + Zn(R)-бейдж + мета «производитель · основа · допустимо N–M мкм»), ряд контролов (ТСП-степпер `[−][число][+] мкм`, чип цвета, кнопки ↑/↓), корзина-удаление. Скрытые `layers[i][coatingId]`/`[dft]` — как есть (`data-role`). «Добавить слой» — dashed-кнопка, по клику раскрывает inline-пикер (coating-typeahead + ТСП, существующий `append`). Обновить `<template>` карточки под новую разметку. Имена/`data-role`/`data-*-target` контракты сохранить.

ТСП-степпер: `stepDft(event)` (params `delta`, шаг 5 мкм, clamp по min/max инпута), инпут остаётся набираемым.

- [ ] **Step 1:** CSS `.lcard`/`.lgrip`/`.lbody`/`.lname`/`.lmeta`/`.lctrls`/`.ldft`/`.laddlayer` в `system-editor.css` (без рамок, тень; степпер как `.dur-pbtn`).
- [ ] **Step 2:** `_layers_edit.html.twig` — строки и `<template>` → карточки; «Добавить слой» dashed + скрытый inline-пикер (reveal по клику).
- [ ] **Step 3:** Контроллер — `stepDft`; `append` адаптировать под новую карточку (заполнение title/coatingId/dft/цвет как сейчас); `_renumber` — проверить селекторы (data-role не менялись).
- [ ] **Step 4:** `yarn dev` + браузер: добавить/удалить слой, ↑/↓, степпер ТСП; submit сохраняет порядок и значения (тест ниже).

---

## Task 3: Визуальный разрез (live) — ОТМЕНЁН

Разрез в окне редактора не нужен (решение пользователя) — убран вместе с большим свотчем `.lswatch`. Цвет слоя показывает чип `layer-color`. Ниже — исходное описание (не реализуем).



**Files:** `_layers_edit.html.twig` (контейнер разреза), `coating_system_layers_edit_controller.js` (`_renderCrossSection`), `system-editor.css`.

Над списком слоёв — карточка «Разрез»: стек полос `.band` (одна на слой, сверху вниз в порядке списка), цвет = hex выбранного цвета слоя (нейтральная заглушка если нет), высота ∝ ТСП (нормировка: `max(minBand, round(dft/total * stackPx))`), в полосе — название + `N мкм`; снизу — hatch-полоса `.substrate` (подложка). Заголовок разреза — суммарная ТСП. Live-перерисовка на любое изменение: `_renderCrossSection()` дёргается из `append/remove/move/drag/stepDft` + MutationObserver на `list` (ловит смену цвета через Tagify-чип и правку ТСП). Hex цвета читаем из свотча Tagify-чипа строки (или из `data`-кэша), название/ТСП — из полей карточки.

- [ ] **Step 1:** Разметка контейнера разреза (пустой, заполняет контроллер) + `.xsec`/`.band`/`.substrate` CSS.
- [ ] **Step 2:** `_renderCrossSection()` — собирает слои из `rowTargets` (title, dft, colorHex), строит полосы, пишет сумму в заголовок. Дебаунс.
- [ ] **Step 3:** Подписки: вызвать из мутаций + `MutationObserver(list, {childList, subtree, attributes})` дебаунсированно; `disconnect` — снять обсервер.
- [ ] **Step 4:** Браузер: правка ТСП/цвета/порядка — разрез и сумма меняются вживую; новый слой без цвета — заглушка.

---

## Task 4: Перетаскивание слоёв (pointer-drag за grip) + ↑/↓

**Files:** `coating_system_layers_edit_controller.js`, `system-editor.css`.

Drag на Pointer Events (без библиотек): `pointerdown` на `.lgrip` → `setPointerCapture`, помечаем строку `.lcard--dragging`; `pointermove` → определяем строку под курсором (по вертикали среди `rowTargets`) и переставляем перетаскиваемую до/после неё (`insertBefore`); `pointerup`/`pointercancel` → снять класс, `_renumber()` + `_renderCrossSection()`. Кнопки ↑/↓ и `moveUp/moveDown` — оставить (доступность/fallback). Уважать `prefers-reduced-motion` (без анимаций перестановки при reduce). Тач: `touch-action: none` на grip, чтобы жест не скроллил.

- [ ] **Step 1:** CSS: `.lgrip { cursor: grab; touch-action: none }`, `.lcard--dragging { opacity/shadow }`, `.lcard--drop-before/after` индикатор (опц.).
- [ ] **Step 2:** Контроллер: `dragStart/dragMove/dragEnd` + утилита «строка под Y». Действия на `.lgrip`: `pointerdown->coating-system-layers-edit#dragStart`.
- [ ] **Step 3:** Браузер (мышь + тач-эмуляция): перетащить слой, порядок и разрез обновляются; ↑/↓ работают; submit сохраняет порядок.

---

## Task 5: Чип цвета в карточке (Tagify, только CSS)

**Files:** `system-editor.css` (или рядом с tagify-правилами), при необходимости мелкие правки `_layers_edit.html.twig` обёртки `data-layer-color-group`.

`layer-color` (Tagify) логику НЕ трогаем. Перестилизовать его контейнер под компактный чип карточки: свотч + название + шеврон, без жёсткой рамки; в пустом состоянии — «Цвет…» плейсхолдер. Дропдаун Tagify (уже со свотчами/RAL) — как есть. Убедиться, что `.layer-color--filled` и свотч-класс рендерятся; ширина/высота под ряд контролов карточки.

- [ ] **Step 1:** CSS чипа: контейнер `.tagify` внутри `[data-layer-color-group]` карточки — высота ~34px, радиус, soft-фон, без рамки; тег-чип со свотчем компактный.
- [ ] **Step 2:** Браузер: выбрать цвет из палитры покрытия; колеруемое покрытие — глобальный поиск + «Создать» (модалка) работают; выбранный цвет уходит в разрез и в `layers[i][colorId]`.

---

## Task 6: Верификация 8B

- [ ] **Step 1:** `yarn dev`, `lint:twig` тронутых; PHP не трогаем (только Twig/JS/CSS).
- [ ] **Step 2:** Свеп: форма системы — обе темы, мобайл/десктоп; карточки, разрез, степпер, drag, ↑/↓, цвет, «Добавить слой», inline-создание подготовки; без JS — серверный submit слоёв (скрытые поля рендерятся Twig'ом) работает.
- [ ] **Step 3:** `CoatingSystem/AddActionTest` + `UpdateActionTest` — зелёные. Если GET-рендер не покрыт — throwaway smoke на маркеры (`.lcard`, разрез, `coating-system-form`), затем откат.

## Self-review / риски

- Live-разрез завязан на чтение hex из Tagify-чипа — при смене API Tagify хрупко; изолировать чтение цвета в одну функцию с фолбэком.
- Pointer-drag: следить за `setPointerCapture`/`releasePointerCapture`, `touch-action:none` только на grip (не на всей карточке — иначе не скроллить список), и за тем, чтобы drag не конфликтовал с кликами по степперу/цвету.
- `_renumber` дергает `layer-color-field-value` — при перестройке карточек убедиться, что скрытый `colorId` следует за индексом (как сейчас).
- Форма системы «заморожена» при наличии документов (доменное правило) — редактор слоёв в этом состоянии может быть скрыт/недоступен; проверить, что каркас не ломает этот путь (рендер read-only, если так было).
- Мета/цвет новых слоёв неизвестны до сохранения — не выдавать пустую мету за реальную; явная подпись «новый слой».
