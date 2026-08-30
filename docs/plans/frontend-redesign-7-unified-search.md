# Деплой 7: единый компонент поиска для всех списков + доводка страницы «Химстойкость»

> **Для исполнителя:** пошагово. Это фронтовый рефактор (Twig/Stimulus/CSS) — верификация = сборка + браузер, PHP-тесты не трогаем (кроме тех, что уже есть). После каждого мигрированного списка — визуальная сверка, что поведение и вид не изменились.

**Цель:** у всех поисков (покрытия, системы, документы, химстойкость) — одинаковые ВИД и ПОВЕДЕНИЕ (как ищешь, выбираешь, сбрасываешь; как ведут себя чипы/фасеты; пустое состояние). Достигается вынесением общего «шелла» поиска в переиспользуемый компонент и переводом всех списков на него; страница «Химстойкость» доводится до общего паттерна (модалки вместо offcanvas, вердикт-пилюля по макету, поиск через тот же шелл).

**Архитектура:** сейчас шелл поиска скопирован инлайн в 3 шаблона (покрытия/системы/документы) — они уже выглядят одинаково, но дублируются; by-substance — самодельный и выбивается. Выносим шелл в `components/entity_search.html.twig` (embed с блоками `chips` и `drawer`), мигрируем на него все списки (чистый рефактор, поведение не меняется), затем строим by-substance-поиск на том же шелле в режиме мультивыбора веществ.

**Tech Stack:** Twig (embed/blocks), Stimulus (`chip-facets`, `range-filter`, `flip-card`, `coating-tags`, `async-typeahead`, `substance-multiselect`), Bootstrap 5.3, CSS-токены.

**Spec/эталон:** `docs/plans/frontend-redesign-design.md` (§6.4), макет `.superpowers/brainstorm/*/content/substance-search.html`.

## Global Constraints

- Не изобретать: шелл = ровно то, что уже общее у покрытий/систем/документов, вынесенное 1-в-1. Никаких новых визуальных классов сверх существующих.
- Миграция покрытий/систем/документов — ПОВЕДЕНИЕ И ВИД НЕ МЕНЯЮТСЯ (pure refactor). Любое отличие после миграции — баг.
- by-substance приводится к общему паттерну: поиск через шелл, вердикт-пилюля по макету, add/edit/delete оценки — модалки (у нас модалки для попапов, offcanvas — только навигация/фильтры), удаление — общий `delete_modal`.
- Состояние поиска — в URL (shareable), формы работают без JS (прогрессивное улучшение).
- Верификация — сборка `yarn dev` + браузер (обе темы, мобайл/десктоп, админ/не-админ). Существующие PHP-тесты by-substance должны остаться зелёными.

## Ключевая развилка (нужно решение до кода)

Механика ввода у покрытий/систем/документов — **свободный текст + отдельные фасеты-дропдауны**. У химстойкости фильтр — это **выбор веществ** (по смыслу нельзя искать покрытия свободным текстом «по веществу», надо выбрать вещество из справочника). Значит одно поведенческое отличие неизбежно:

- Вариант A (рекомендую): на by-substance строка поиска = typeahead веществ; выбранное вещество становится **чипом в том же chip-row**, что и активные фасеты у остальных (тот же вид, тот же × для снятия, авто-обновление выдачи). Свободного текста нет — только добавление веществ-чипов. Вид идентичен, механика — «печатаю → подсказки → выбрал → появился чип-фильтр → выдача обновилась», что согласуется с общим ментальным паттерном чипов.
- Вариант B: сделать typeahead-добавление-чипов ЕДИНОЙ механикой везде (и у покрытий фасеты выбирать через ту же строку). Это переписывает взаимодействие покрытий/систем — большой риск, выходит за рамки «вид одинаковый».

План ниже исходит из Варианта A. Если нужен B — план переписываем.

## Карта файлов

Новое:
- Create: `app/src/Shared/Infrastructure/Templates/components/entity_search.html.twig` — общий шелл (embed с блоками `chips`, `drawer`).
- (возможно) Create: `app/src/Shared/Infrastructure/Templates/components/_chip_row_skeleton.html.twig` — если удобнее вынести отдельно; решить на Task 1.

Правки (миграция на шелл, вид/поведение без изменений):
- Modify: `admin/certificate/document/index.html.twig` (Task 2, простейшие фасеты).
- Modify: `cabinet/coating/coating_system/list.html.twig` (Task 3).
- Modify: `admin/coating/coating/index.html.twig` (Task 4, самый сложный — каскад соответствия, range, tagify).

Химстойкость (Task 5-7):
- Modify: `cabinet/chemical_resistance/by_substance.html.twig` — поиск через шелл (режим мультивыбора), убрать `.subsearch`; add-форма → модалка.
- Modify: `cabinet/chemical_resistance/_resistant_cards.html.twig` — вердикт-пилюля по макету; правка/удаление оценки → модалка на карточке.
- Modify: `app/assets/styles/components/substance-search.css` — свести к тому, что реально нужно (чипы веществ в chip-row используют существующие классы; лишнее удалить). Вердикт-пилюля — в `entity-card.css` по макету.
- Delete: самодельные куски (offcanvas add/edit, btn-group-сегмент, `.subsearch`), если заменены общим шеллом/модалками.
- Modify (возможно): `substance_multiselect_controller.js` — адаптировать под chip-row шелла (добавление веществ-чипов), либо заменить общим механизмом фасетов.

---

## Task 1: Общий компонент-шелл `entity_search.html.twig`

**Files:** create `components/entity_search.html.twig`.

Вынести из текущего общего инлайна (сверить по документам — самый чистый) параметризуемый embed:

- Параметры: `form_id`, `action`, `search` = {name, value, placeholder, minlength, maxlength, endpoint_typeahead?} (реюз `_search_toolbar`), `reset_url`, `sort` = {options, current, default, param}, `active_count`, `drawer_id`, `has_search` (bool — можно скрыть строку, если у списка только фасеты).
- Структура: `_search_toolbar` (если `has_search`) → chip-row (`chip-row` / `chip-row-pinned` Сортировка + «Все фильтры» с badge `active_count` + `chip-scroll`) → блок `chips` (контент страницы) → шторка offcanvas-end (`drawer_id`) со своей формой, hidden-passthrough, скроллящимся телом и футером «Сбросить всё / Показать», тело = блок `drawer`.
- Блоки: `{% block chips %}` (per-facet чипы/дропдауны страницы), `{% block drawer %}` (секции шторки), опц. `{% block chip_row_extra %}`.

- [ ] **Step 1:** Создать компонент, вынеся скелет 1-в-1 из документов (Sort + «Все фильтры» + chip-scroll + drawer + footer). Пока без подключения к страницам.
- [ ] **Step 2:** Прогнать `lint:twig`. Коммит.

---

## Task 2: Мигрировать «Документы» на шелл (эталон-миграция)

**Files:** `admin/certificate/document/index.html.twig`.

- [ ] **Step 1:** Переписать `above_list` через `{% embed 'components/entity_search.html.twig' %}`: параметры (q без typeahead, sort, active_count, drawer `#allDocsFiltersOffcanvas`), блок `chips` = Вид/Статус дропдауны + issuer/testStandard active-remove-чипы, блок `drawer` = существующие секции (Вид, Статус, Организация select, Стандарт select, Покрытие coating-tags) + hidden q/sort.
- [ ] **Step 2:** Сборка + браузер: список документов, все фасеты, шторка, сортировка, сброс, пустое состояние — идентично прежнему. Особое внимание: passthrough hidden `sort`.
- [ ] **Step 3:** Коммит `Документы: поиск переехал на общий компонент entity_search (вид и поведение без изменений)`.

---

## Task 3: Мигрировать «Системы» на шелл

**Files:** `cabinet/coating/coating_system/list.html.twig`.

- [ ] **Step 1:** Обернуть в `entity_search` (q c typeahead `system_suggest`, sort, drawer `#allFiltersOffcanvas`, wrapper-контроллеры `entity-preview coating-preview-loader` оставить снаружи embed). Блок `chips` = все chip-row фасеты (substrates, environment, hasDocuments, standard, category cond, durability cond, range-фасеты, thermal, tags-stub) + мобильные per-facet offcanvas. Блок `drawer` = active-badge cloud + все секции + range_filter_card + coating-tags (coatings/tags).
- [ ] **Step 2:** Сборка + браузер: каскад Стандарт→Категория→Долговечность, range-флип-карточки, thermal-композит, теги/покрытия в шторке, мобильные шторки фасетов — всё как было.
- [ ] **Step 3:** Коммит.

---

## Task 4: Мигрировать «Покрытия» на шелл (самый сложный)

**Files:** `admin/coating/coating/index.html.twig`.

- [ ] **Step 1:** Обернуть в `entity_search` (q, sort, drawer `#allFiltersOffcanvas`, wrapper `compare-tray coating-preview-loader` снаружи). Блок `chips` = существующие range/thermal/base/mfg чипы + мобильные offcanvas. Блок `drawer` = существующие секции (range_filter_card×4, thermal, base, mfg, tags) + active-badge cloud.
- [ ] **Step 2:** Сборка + браузер: все фасеты покрытий, compare-tray, FAB, chip-facets гидрация, form.requestSubmit-поведение ([[reference_form_submit_bypasses_chipfacets]]) — идентично.
- [ ] **Step 3:** Коммит.

---

## Task 5: «Химстойкость» — поиск через общий шелл (мультивыбор веществ)

**Files:** `by_substance.html.twig`, `substance_multiselect_controller.js`, `substance-search.css`.

- [ ] **Step 1:** Переписать `above_list` через `entity_search`: строка поиска = typeahead веществ (`substance_autocomplete` endpoint), блок `chips` = выбранные вещества как chip-row чипы (существующий стиль `btn btn-sm btn-primary` + `bi-x-lg`, как активные фасеты у покрытий) с × (снятие → пересабмит), сегмент «Только стойкие / + ограниченно» — как chip/кнопка в общем стиле (не самодельный btn-group). Без шторки-фильтров (у веществ фасетов нет) — `has_drawer: false`.
- [ ] **Step 2:** Адаптировать `substance-multiselect` под chip-row шелла (typeahead добавляет вещество-чип + hidden `substanceIds[]` + пересабмит; × снимает). Убрать `.subsearch` из CSS (осталось только то, что переиспользует общие классы).
- [ ] **Step 3:** Сборка + браузер: вид строки поиска и чипов идентичен покрытиям; добавление/снятие вещества; сегмент; пустое состояние; без JS работает GET.
- [ ] **Step 4:** Коммит.

---

## Task 6: «Химстойкость» — вердикт-пилюля по макету + карточка

**Files:** `_resistant_cards.html.twig`, `entity-card.css`.

- [ ] **Step 1:** Вердикт-пилюлю довести точно по макету substance-search.html: `.verdict` inline-flex, gap 5, font 11/700, padding 3/8, radius 7, `.v-ok` (ok-subtle/ok) с иконкой-галкой, `.v-warn` (warn-subtle/warn) с иконкой-треугольником; для NR — danger-вариант. Иконки — `bi-check`/`bi-exclamation-triangle` (наши bi-*). Позиция — над заголовком.
- [ ] **Step 2:** Сборка + браузер (обе темы): пилюля как в макете.
- [ ] **Step 3:** Коммит.

---

## Task 7: «Химстойкость» — add/edit/delete оценки через модалки

**Files:** `_resistant_cards.html.twig`, `by_substance.html.twig` (модалки), тонкие экшены (уже есть, редиректы).

- [ ] **Step 1:** «Добавить покрытие к веществу» — модалка (реюз modal-инфры проекта): автокомплит покрытия (`async-typeahead` на `coating_suggest`) + селект вещества (из выбранных) + селект грейда + температура → POST в `AddFromSubstanceAction`. Триггер — кнопка в общем стиле (как «+» в шапке списка или кнопка над списком).
- [ ] **Step 2:** Правка на карточке — модалка (на карточку — кнопка правки в стиле `edit_delete`/kebab как у остальных карточек): строка на каждое вещество (грейд+температура, Сохранить) → `UpdateFromSubstanceAction`; удаление → через общий `delete_modal` или отдельную модалку → `DeleteFromSubstanceAction`. Убрать offcanvas-правку.
- [ ] **Step 3:** Существующие функциональные тесты by-substance экшенов — прогнать, должны остаться зелёными (роуты/команды не меняются, меняется только UI-триггер).
- [ ] **Step 4:** Сборка + браузер (админ и не-админ): добавление/правка/удаление в модалках; не-админ кнопок не видит.
- [ ] **Step 5:** Коммит.

---

## Task 8: Финальный свип

- [ ] **Step 1:** `yarn dev`, `lint:twig` всех тронутых.
- [ ] **Step 2:** Все 4 списка (покрытия/системы/документы/химстойкость): вид и поведение поиска идентичны — обе темы, мобайл/десктоп, админ/не-админ.
- [ ] **Step 3:** PHP-тесты by-substance (query/render/actions/quick-create) — зелёные.

## Self-review (риски)

- Миграция покрытий/систем — высокий риск регрессий (каскад соответствия, range-флип, tagify, мобильные шторки, compare-tray, chip-facets гидрация). Митигируем: строго pure-refactor (вынос скелета, контент фасетов в блоки без изменений), по одному списку с браузер-сверкой; при малейшем отличии — откат шага.
- Развилка A/B (механика ввода на by-substance) — план на A; при B рамки сильно расширяются.
- «Один компонент» покрывает ШЕЛЛ; фасеты остаются per-page (иначе не бывает — они разные). Это и есть «одинаковый вид/поведение при разной начинке».
</content>
