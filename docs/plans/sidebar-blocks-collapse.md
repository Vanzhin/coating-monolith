# Десктоп-сайдбар: блоки-выделение + сворачивание до иконок — план

> **Для агентных воркеров:** РЕКОМЕНДУЕМЫЙ САБ-СКИЛЛ: superpowers:executing-plans (или subagent-driven-development). Шаги в чекбокс-синтаксисе (`- [ ]`).

**Goal:** Десктоп-сайдбар (`.app-sidebar`) чинит переполнение и приводится к общему виду приложения: секции — в блоки-карточки (как мобильная «Ещё»), список и «Профиль» скроллятся одной лентой, а сам сайдбар можно свернуть стрелкой в рельс из иконок с запоминанием состояния.

**Architecture:** Чистый фронт, без бэка и домена. Статический редизайн — в `_sidebar.html.twig` (разметка) + `app-shell.css` (стили). Поведение сворачивания — Stimulus-контроллер `sidebar-collapse` (ставит класс на `<html>`, персист в localStorage) + FOUC-safe init в `<head>` (зеркало тема-инициализации). Collapse — только десктоп (≥992px), где сайдбар видим.

**Tech Stack:** Symfony/Twig, Stimulus (авто-регистрация из `assets/controllers/`), Bootstrap 5.3 + токены `tokens.css`, Encore (`yarn dev`).

**Spec:** этот тред + интерактивный макет-эталон https://claude.ai/code/artifact/aa31a084-9a49-43c4-a86e-9c23af8bc597 (выбрано: блоки + стиль пунктов A). Решения из обсуждения: убрать вертикальную черту сайдбар↔тело; убрать черту над «Профилем»; список+профиль скроллятся вместе; стрелка вверху меню, клик сворачивает/разворачивает, стрелка переворачивается; шапка со стрелкой остаётся на месте при скролле.

## Global Constraints

- Верификация фронта — сборка + браузер, НЕ PHPUnit (правило проекта: PHP-тесты не трогают CSS/JS/Twig). После правок CSS/JS — `cd app && yarn dev`.
- Не изобретать стили/классы — копировать ближайший аналог. Блоки берём 1-в-1 с offcanvas `#mainMenu` (`base.html.twig:184-279`): карточка = `p-3 rounded-3 bg-body-tertiary`, заголовок секции внутри блока. Пункты оставляем текущие `.app-nav-item` (стиль A), НЕ переводим на bootstrap `list-group`.
- В Twig только разметка. CSS — в `app/assets/styles/components/app-shell.css`. JS — Stimulus-контроллер в `app/assets/controllers/`. Единственное исключение для `<script>` — critical-path FOUC-init в `<head>` `base.html.twig` (там уже живёт тема-init; дописываем туда же, маленьким блоком).
- Не дублировать HTML между Twig и JS: контроллер сворачивания только тогглит класс + персист, разметку НЕ генерит.
- Collapse применяется только на `≥992px`. На мобиле сайдбар скрыт (`d-none d-lg-flex`) — класс свёрнутости не должен ломать мобильную раскладку.
- Персист состояния — зеркало темы: ключ localStorage `sidebarCollapsed` (`'1'`/`'0'`), класс `sidebar-collapsed` на `document.documentElement` (ставится в `<head>` до применения CSS — как `data-bs-theme`).
- Коммиты — только по явному апруву пользователя (правило проекта). Шаги «Commit» в плане выполнять с этим условием.

## Review Focus

- **FOUC при перезагрузке со свёрнутым сайдбаром**: без init в `<head>` будет вспышка 240px→64px. Пины: init ставит `sidebar-collapsed` на `<html>` до применения CSS; проверка в Task 2 — reload в свёрнутом виде без вспышки ширины.
- **Свёрнутый класс на мобиле (<992px)**: сдвиг `.app-content` (`margin-left`) и ширина рельса должны срабатывать ТОЛЬКО в `@media (min-width:992px)`; иначе на мобиле контент уедет. Пины: Task 2 — визуальная проверка мобильной ширины в свёрнутом состоянии (класс стоит, раскладка цела).
- **Тултип в рельсе обрезается скролл-областью**: `.app-sidebar-scroll` имеет `overflow-y:auto` → горизонтальный вылет CSS-`::after` клипуется. Пины: Task 2 использует нативный `title` на пункте, не псевдоэлемент; проверка — подпись видна при наведении в рельсе целиком.
- **«Профиль» недостижим скроллом**: профиль — последний элемент внутри единой скролл-области, не прижат. Пины: Task 1 — при низком экране список докручивается до профиля.
- **Активный пункт в свёрнутом виде**: подсветка `active` держится на иконке без подписи. Пины: Task 2 — активный пункт в рельсе визуально выделен.

---

### Task 1: Статический редизайн сайдбара (блоки, скролл лентой, без черт)

Чисто CSS+Twig, без JS. Deliverable: сайдбар выглядит как на макете в развёрнутом состоянии.

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/_shell/_sidebar.html.twig` (переструктурировать)
- Modify: `app/assets/styles/components/app-shell.css` (строки ~55-81; РЕВИЗИЯ ранее внесённого скролл-фикса `.app-sidebar-nav`)

**Interfaces:**
- Produces (разметка, на неё опирается Task 2):
  - `.app-sidebar` → `.app-sidebar-head` (шапка: `.app-brand` + место под стрелку из Task 2) + `.app-sidebar-scroll` (единая скролл-область) → внутри `.app-sidebar-nav` (блоки секций) и `.app-sidebar-foot` (блок «Профиль»).
  - Блок секции: `<div class="menu-block">` с опциональным `<div class="block-title">…</div>` внутри; пункты — прежние `<a class="app-nav-item">`.

- [ ] **Step 1: Переструктурировать `_sidebar.html.twig`**

Разметка:
```twig
<aside class="app-sidebar d-none d-lg-flex">
    <div class="app-sidebar-head">
        <a class="app-brand" href="{{ path('app_homepage') }}">{{ app_name }}</a>
        {# стрелка-свёртка добавляется в Task 2 #}
    </div>
    <div class="app-sidebar-scroll">
        <nav class="app-sidebar-nav" aria-label="Основная навигация">
            <div class="menu-block">
                {# nav_items + Отчёты + Инструменты — как сейчас, но обёрнуты в блок #}
                {% for item in nav_items %}
                    <a href="{{ path(item.route) }}" class="app-nav-item{{ item.active ? ' active' : '' }}" ...>
                        <i class="bi {{ item.icon }}"></i><span>{{ item.label }}</span>
                    </a>
                {% endfor %}
                {# Отчёты, Инструменты — как в текущем файле #}
            </div>
            {% if is_granted('ROLE_ADMIN') %}
                <div class="menu-block">
                    <div class="block-title">Администрирование</div>
                    {# Подготовка поверхности / Производители / Вещества #}
                </div>
                <div class="menu-block">
                    <div class="block-title">Персонал</div>
                    {# Персонал / Нормы / Соответствие #}
                </div>
            {% endif %}
        </nav>
        <div class="app-sidebar-foot">
            <div class="menu-block">
                <a href="{{ path('app_cabinet_profile') }}" class="app-nav-item">
                    <i class="bi bi-person-circle"></i><span>{{ app.user.email }}</span>
                    {% include '_shell/_notifications_badge.html.twig' with {class: 'ms-auto'} %}
                </a>
            </div>
        </div>
    </div>
</aside>
```
Перенести существующие пункты/роуты/`active`-логику 1-в-1, меняется только обёртка и расположение заголовков (бывшие `.app-nav-sep` → `.block-title` внутри блока).

- [ ] **Step 2: Правка `app-shell.css`**

- `.app-sidebar`: убрать `border-right`. Паддинг верхний уменьшить (шапка даст свой отступ). Контейнер остаётся `position:fixed; top:0; left:0; bottom:0; width:240px; flex-direction:column`.
- Новое `.app-sidebar-head { flex:0 0 auto; display:flex; align-items:center; gap:.5rem; }` — шапка не скроллится.
- Новое `.app-sidebar-scroll { flex:1 1 auto; min-height:0; overflow-y:auto; display:flex; flex-direction:column; gap:.6rem; }` — единая скролл-область (это и есть корректный скролл-фикс; старое `overflow-y:auto` с `.app-sidebar-nav` удалить).
- `.app-sidebar-nav { display:flex; flex-direction:column; gap:2px; }` — вернуть без `flex/overflow/min-height`.
- `.app-sidebar-foot { display:flex; flex-direction:column; gap:2px; }` — убрать `margin-top:auto`, `border-top`, `padding-top`.
- Новое `.menu-block { padding:.65rem .75rem; border-radius:var(--bs-border-radius-lg); background:var(--bs-tertiary-bg); display:flex; flex-direction:column; gap:2px; }`.
- Новое `.block-title { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--bs-secondary-color); opacity:.75; padding:.1rem .4rem .35rem; }`.
- Удалить старое правило `.app-nav-sep` (заменено `.block-title`), `.app-nav-item*` оставить как есть (стиль A).

- [ ] **Step 3: Сборка**

Run: `cd app && yarn dev`
Expected: `webpack compiled successfully`, без ошибок.

- [ ] **Step 4: Браузерная проверка (десктоп ≥992px, под ROLE_ADMIN)**

Открыть любую кабинетную страницу. Ожидаемо: секции в тинтованных блоках с заголовками внутри; между сайдбаром и контентом нет вертикальной черты; над «Профилем» нет черты; при нехватке высоты список докручивается до «Профиля» (профиль не прижат, скроллится вместе с лентой). Сверить с макетом-эталоном (блоки + стиль A, развёрнуто).

- [ ] **Step 5: Commit** (по апруву)

```
git add app/src/Shared/Infrastructure/Templates/_shell/_sidebar.html.twig app/assets/styles/components/app-shell.css
git commit -m "Десктоп-меню причёсано: секции в блоки-карточки, список и профиль скроллятся одной лентой, лишние черты убраны"
```

---

### Task 2: Сворачивание до иконок (стрелка, персист, FOUC-safe)

Deliverable: стрелка вверху меню сворачивает/разворачивает сайдбар в рельс 64px, состояние помнится между страницами, без вспышки при загрузке.

**Files:**
- Create: `app/assets/controllers/sidebar_collapse_controller.js`
- Modify: `app/src/Shared/Infrastructure/Templates/_shell/_sidebar.html.twig` (стрелка в `.app-sidebar-head`; `title` на пунктах)
- Modify: `app/assets/styles/components/app-shell.css` (свёрнутое состояние + сдвиг контента)
- Modify: `app/src/Shared/Infrastructure/Templates/base.html.twig` (FOUC-init в `<head>`, строки ~23-34)

**Interfaces:**
- Consumes из Task 1: структура `.app-sidebar-head` / `.app-sidebar-scroll` / `.menu-block` / `.app-nav-item`.
- Produces: класс `sidebar-collapsed` на `document.documentElement`; localStorage-ключ `sidebarCollapsed` (`'1'`/`'0'`).

- [ ] **Step 1: Stimulus-контроллер `sidebar_collapse_controller.js`**

Поведение (зеркало `theme_toggle_controller.js`): `toggle()` переключает класс `sidebar-collapsed` на `document.documentElement`, пишет в `localStorage.setItem('sidebarCollapsed', on ? '1' : '0')` в try/catch. Разметку не трогает (переворот стрелки и скрытие подписей — чистый CSS по классу).

- [ ] **Step 2: Стрелка в шапке + `title` на пунктах (`_sidebar.html.twig`)**

В `.app-sidebar-head` добавить `<aside>`-уровневый `data-controller="sidebar-collapse"` (или на `.app-sidebar`), и кнопку:
```twig
<button type="button" class="app-sidebar-toggle" data-action="sidebar-collapse#toggle" aria-label="Свернуть меню">
    <i class="bi bi-chevron-left"></i>
</button>
```
Каждому `.app-nav-item` добавить `title="{{ label }}"` (нативный тултип для рельса — не обрезается скролл-областью).

- [ ] **Step 3: CSS свёрнутого состояния (`app-shell.css`)**

- `.app-sidebar-toggle` — стиль из ближайшего аналога (иконочная кнопка без рамки, `bg-body-tertiary` на ховер), `margin-left:auto` (прижать вправо в шапке). Иконка `bi-chevron-left`, `transition: transform .22s`.
- `html.sidebar-collapsed .app-sidebar { width:64px; }`
- `html.sidebar-collapsed .app-brand span/текст` → спрятать (оставить лого-марку/иконку); `.app-nav-item span` → `display:none`; `.block-title` → `display:none`; `.app-nav-item` → `justify-content:center; gap:0`.
- `html.sidebar-collapsed .app-sidebar-toggle i { transform: rotate(180deg); }` — стрелка перевёрнута.
- Сдвиг контента ТОЛЬКО на десктопе:
```css
@media (min-width: 992px) {
    html.sidebar-collapsed body.has-app-shell .app-content { margin-left: 64px; }
}
```
(существующее правило `margin-left:240px` оставить как дефолт.)

- [ ] **Step 4: FOUC-safe init в `<head>` (`base.html.twig`)**

В существующий critical-path `<script>` (где тема) дописать:
```js
try { if (localStorage.getItem('sidebarCollapsed') === '1') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}
```

- [ ] **Step 5: Сборка**

Run: `cd app && yarn dev`
Expected: `webpack compiled successfully`.

- [ ] **Step 6: Браузерная проверка**

Десктоп ≥992px: клик по стрелке → сайдбар 64px, подписи скрыты, остались иконки, стрелка перевёрнута, контент сдвинулся под 64px. Наведение на иконку → нативный `title` с подписью (не обрезан). Активный пункт выделен в рельсе. Перезагрузка в свёрнутом виде → без вспышки 240→64. Мобайл <992px: класс `sidebar-collapsed` стоит, но раскладка контента цела (сдвиг не применяется), сайдбар скрыт.

- [ ] **Step 7: Commit** (по апруву)

```
git add app/assets/controllers/sidebar_collapse_controller.js app/src/Shared/Infrastructure/Templates/_shell/_sidebar.html.twig app/assets/styles/components/app-shell.css app/src/Shared/Infrastructure/Templates/base.html.twig
git commit -m "Десктоп-меню сворачивается стрелкой в рельс из иконок — состояние помнится, без вспышки при загрузке"
```
