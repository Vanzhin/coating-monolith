# Деплой 2 «App-shell + навигация»: нижняя таб-панель, десктоп-сайдбар, «Ещё», View Transitions

> **Для исполнителя:** реализация пошаговая, с визуальной/поведенческой проверкой после каждой задачи. «Тест» фронтового деплоя = сборка ассетов (`yarn dev`) + проверка в браузере в обеих темах и на узком экране + грепы. PHP-тесты НЕ гоняем (правка чисто фронтовая), кроме случая, если задели PHP.

**Цель:** заменить навигацию-гамбургер на app-shell: нижняя таб-панель на мобиле, постоянный сайдбар на десктопе, offcanvas «Ещё» (аккаунт/тема/админ), native-переходы между страницами (View Transitions). Каркас общий (base.html.twig), поэтому меняем аккуратно и только навигацию.

**Архитектура:** единый источник пунктов навигации (Twig-partial) рендерится в двух раскладках — нижние табы (мобайл) и сайдбар (десктоп); активный пункт по текущему `_route`. Старый `#mainMenu` перепрофилируется в «Ещё» (аккаунт, тема, админ-справочники) и открывается вкладкой «Ещё». Фильтры на страницах (`#allFiltersOffcanvas`, `#chipFilter*`) — независимые offcanvas'ы, их НЕ трогаем.

**Tech Stack:** Symfony Twig, Bootstrap 5.3 (offcanvas/collapse), Stimulus, CSS (media-queries lg-брейкпоинт, safe-area), View Transitions API.

**Spec:** `docs/plans/frontend-redesign-design.md` (раздел 4 «App-shell и навигация»; макет `nav-options.html`, Вариант 2).

## Global Constraints

- Базис Деплоя 1 (токены + вынесенные стили/скрипты) уже на месте — строим поверх.
- Bottom tab bar и sidebar — взаимоисключающие по брейкпоинту `lg` (992px): табы `d-lg-none`, сайдбар `d-none d-lg-*`.
- Навигацию показываем ТОЛЬКО авторизованным (`app.user`). На публичном лендинге/входе — ни табов, ни сайдбара.
- Набор вкладок этого деплоя: **Покрытия · Системы · Документы · Ещё** (колба «Химстойкость» — Деплой 6). «Главная» — тап по логотипу (не вкладка).
- Фильтры-offcanvas на страницах не трогаем. Единственный трогаемый offcanvas — `#mainMenu` → становится «Ещё».
- Единый источник пунктов навигации — не дублировать список ссылок между табами и сайдбаром (правило «не дублируй HTML»).
- Тач-таргеты ≥44px, safe-area-inset снизу для таб-панели, `prefers-reduced-motion` для переходов.
- Коммиты — по режиму исполнения (SDD → по задаче; инлайн → пользователь).

## Карта файлов

- Создать `app/src/Shared/Infrastructure/Templates/_shell/_nav_data.html.twig` — единый источник: массив пунктов (label, icon, route, match-routes) + вычисление активного.
- Создать `app/src/Shared/Infrastructure/Templates/_shell/_bottom_nav.html.twig` — нижняя таб-панель (мобайл).
- Создать `app/src/Shared/Infrastructure/Templates/_shell/_sidebar.html.twig` — десктоп-сайдбар.
- Создать `app/assets/styles/components/app-shell.css` — стили таб-панели, сайдбара, offset контента, view-transitions.
- Создать `app/assets/controllers/view_transitions_controller.js` (если нужен JS-fallback) — опционально; базовый вариант чисто CSS.
- Модифицировать `app/src/Shared/Infrastructure/Templates/base.html.twig` — вставить bottom-nav + sidebar, обернуть контент в shell-layout, перепрофилировать `#mainMenu` в «Ещё», убрать floating-гамбургер, добавить view-transition meta.
- Модифицировать `app/assets/styles/app.css` — импорт `app-shell.css`.

---

## Task 1: Единый источник пунктов навигации + активный пункт

**Files:**
- Create: `app/src/Shared/Infrastructure/Templates/_shell/_nav_data.html.twig`

**Interfaces:**
- Produces: Twig-переменная `nav_items` — список map'ов `{ key, label, icon, route, active }`; `active` вычислен из текущего `_route`. Потребляется `_bottom_nav.html.twig` и `_sidebar.html.twig`.

- [ ] **Step 1: Создать `_nav_data.html.twig`.** Определяет пункты и активность по префиксу маршрута. Подключается через `{% include %}` перед рендером таб-панели/сайдбара (переменные видны в том же scope при `with context`), либо оформить как макрос. Реализация — set-массив:

```twig
{# Единый источник пунктов основной навигации. Активный пункт — по текущему
   маршруту (сопоставление по префиксу route-name). Используется и нижней
   таб-панелью, и десктоп-сайдбаром — список ссылок в одном месте (DRY).
   Колба «Химстойкость» добавится в Деплое 6. #}
{% set current_route = app.request.attributes.get('_route') %}
{% set nav_items = [
    { key: 'coatings',  label: 'Покрытия',  icon: 'bi-grid',
      route: 'app_cabinet_coating_coating_list', prefix: 'app_cabinet_coating_coating' },
    { key: 'systems',   label: 'Системы',   icon: 'bi-layers',
      route: 'app_cabinet_coating_system_list',  prefix: 'app_cabinet_coating_system' },
    { key: 'documents', label: 'Документы', icon: 'bi-file-earmark-text',
      route: 'app_cabinet_certificate_document_list', prefix: 'app_cabinet_certificate_document' },
] %}
{% set nav_items = nav_items|map(item => item|merge({
    active: current_route starts with item.prefix
})) %}
```

- [ ] **Step 2: Проверка на несуществующих маршрутах.** Убедиться, что `path()` для этих route существует (они уже используются в текущем `#mainMenu`, значит валидны). Прогон: `cd app && bin/console debug:router | grep -E "coating_coating_list|coating_system_list|certificate_document_list"` (в контейнере: `./run console debug:router`). Expected: все три маршрута присутствуют.

- [ ] **Step 3: Коммит.**

```bash
git add app/src/Shared/Infrastructure/Templates/_shell/_nav_data.html.twig
git commit -m "Единый источник пунктов навигации: список разделов и активный пункт по маршруту в одном месте"
```

---

## Task 2: Нижняя таб-панель (мобайл)

**Files:**
- Create: `app/src/Shared/Infrastructure/Templates/_shell/_bottom_nav.html.twig`
- Create/Modify: `app/assets/styles/components/app-shell.css`
- Modify: `app/assets/styles/app.css` (импорт), `app/src/Shared/Infrastructure/Templates/base.html.twig` (вставка + bottom-padding контента)

**Interfaces:**
- Consumes: `nav_items` из Task 1.
- Produces: разметка `.app-tabbar` (скрыта на `lg+`), пункт «Ещё» с `data-bs-toggle="offcanvas" data-bs-target="#mainMenu"`.

- [ ] **Step 1: Создать `_bottom_nav.html.twig`.**

```twig
{# Нижняя таб-панель (мобайл, d-lg-none). Пункты — из _nav_data. «Ещё» открывает
   offcanvas #mainMenu (аккаунт/тема/админ). Показывается только авторизованным. #}
{% include '_shell/_nav_data.html.twig' %}
<nav class="app-tabbar d-lg-none" aria-label="Основная навигация">
    {% for item in nav_items %}
        <a href="{{ path(item.route) }}"
           class="app-tab{{ item.active ? ' active' : '' }}"
           {{ item.active ? 'aria-current="page"' : '' }}>
            <i class="bi {{ item.icon }}"></i>
            <span>{{ item.label }}</span>
        </a>
    {% endfor %}
    <button type="button" class="app-tab" data-bs-toggle="offcanvas" data-bs-target="#mainMenu" aria-label="Ещё">
        <i class="bi bi-three-dots"></i>
        <span>Ещё</span>
    </button>
</nav>
```

- [ ] **Step 2: Стили таб-панели в `app-shell.css`.**

```css
/* ===== Нижняя таб-панель (мобайл) ===== */
.app-tabbar {
    position: fixed;
    left: 0; right: 0; bottom: 0;
    z-index: 1035;
    display: flex;
    background: color-mix(in srgb, var(--bs-body-bg) 86%, transparent);
    backdrop-filter: saturate(180%) blur(16px);
    -webkit-backdrop-filter: saturate(180%) blur(16px);
    border-top: 1px solid var(--bs-border-color);
    padding: .5rem .25rem calc(.5rem + env(safe-area-inset-bottom, 0px));
}
.app-tab {
    flex: 1;
    min-height: 44px;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
    background: none; border: 0;
    color: var(--bs-secondary-color);
    font-size: .65rem; font-weight: 600; text-decoration: none;
}
.app-tab i { font-size: 1.35rem; }
.app-tab.active { color: var(--bs-primary); }

/* Контент не должен прятаться под таб-панелью (мобайл). */
@media (max-width: 991.98px) {
    body.has-app-shell { padding-bottom: calc(64px + env(safe-area-inset-bottom, 0px)); }
}
```

- [ ] **Step 3: Импорт в `app.css`** (после shell/refresh): `@import "components/app-shell.css";`

- [ ] **Step 4: Вставить в `base.html.twig`.** Перед `</body>`, только для авторизованных; на `<body>` добавить класс `has-app-shell` при `app.user`:

```twig
{# было: <body data-controller="back-to-top"> #}
<body data-controller="back-to-top"{% if app.user %} class="has-app-shell"{% endif %}>
...
{% if app.user %}
    {% include '_shell/_bottom_nav.html.twig' %}
{% endif %}
</body>
```

- [ ] **Step 5: Собрать.** Run: `cd app && yarn dev`. Expected: ок.

- [ ] **Step 6: Проверить на мобиле** (узкий экран, обе темы, залогинен): таб-панель внизу, 4 пункта, активный подсвечен на соответствующей странице, «Ещё» открывает `#mainMenu`, контент не прячется под панелью. На публичном лендинге (разлогинен) — панели нет.

- [ ] **Step 7: Коммит.**

```bash
git add app/src/Shared/Infrastructure/Templates/_shell/_bottom_nav.html.twig app/assets/styles/components/app-shell.css app/assets/styles/app.css app/src/Shared/Infrastructure/Templates/base.html.twig
git commit -m "Мобильная нижняя таб-панель заменила гамбургер: разделы под пальцем, «Ещё» открывает аккаунт и админку"
```

---

## Task 3: Десктоп-сайдбар

**Files:**
- Create: `app/src/Shared/Infrastructure/Templates/_shell/_sidebar.html.twig`
- Modify: `app/assets/styles/components/app-shell.css`, `app/src/Shared/Infrastructure/Templates/base.html.twig`

**Interfaces:**
- Consumes: `nav_items` из Task 1.
- Produces: `.app-sidebar` (виден только `lg+`), контент со смещением `.app-content` под сайдбар на `lg+`.

- [ ] **Step 1: Создать `_sidebar.html.twig`** — те же пункты + группа «Администрирование» (для админа) + аккаунт/тема снизу.

```twig
{# Десктоп-сайдбар (d-none d-lg-flex). Пункты — из _nav_data; ниже —
   группа «Администрирование» (только ROLE_ADMIN) и аккаунт/тема. #}
{% include '_shell/_nav_data.html.twig' %}
<aside class="app-sidebar d-none d-lg-flex">
    <a class="app-brand" href="{{ path('app_homepage') }}">{{ app_name }}</a>
    <nav class="app-sidebar-nav">
        {% for item in nav_items %}
            <a href="{{ path(item.route) }}" class="app-nav-item{{ item.active ? ' active' : '' }}">
                <i class="bi {{ item.icon }}"></i><span>{{ item.label }}</span>
            </a>
        {% endfor %}
        {% if is_granted('ROLE_ADMIN') %}
            <div class="app-nav-sep">Администрирование</div>
            <a href="{{ path('app_cabinet_surface_treatment_list') }}" class="app-nav-item"><i class="bi bi-chevron-bar-contract"></i><span>Подготовка поверхности</span></a>
            <a href="{{ path('app_cabinet_coating_manufacturer_list') }}" class="app-nav-item"><i class="bi bi-building"></i><span>Производители</span></a>
            <a href="{{ path('app_cabinet_chemical_resistance_substance_list') }}" class="app-nav-item"><i class="bi bi-eyedropper"></i><span>Химстойкость</span></a>
        {% endif %}
    </nav>
    <div class="app-sidebar-foot">
        <a href="{{ path('app_cabinet') }}" class="app-nav-item"><i class="bi bi-person-circle"></i><span>{{ app.user.email }}</span></a>
        <button type="button" class="app-nav-item app-theme" data-controller="theme-toggle" data-action="click->theme-toggle#toggle" aria-label="Переключить тему">
            <i class="bi bi-sun-fill" data-theme-toggle-target="light"></i>
            <i class="bi bi-moon-stars-fill d-none" data-theme-toggle-target="dark"></i>
            <span>Тема</span>
        </button>
        <a href="{{ path('app_logout') }}" class="app-nav-item text-danger"><i class="bi bi-box-arrow-right"></i><span>Выйти</span></a>
    </div>
</aside>
```

- [ ] **Step 2: Стили сайдбара + offset в `app-shell.css`.**

```css
/* ===== Десктоп-сайдбар ===== */
.app-sidebar {
    position: fixed; top: 0; left: 0; bottom: 0; width: 240px; z-index: 1035;
    flex-direction: column; padding: 1rem .75rem;
    background: var(--bs-body-bg); border-right: 1px solid var(--bs-border-color);
}
.app-brand { font-weight: 700; font-size: 1.15rem; letter-spacing: -.02em; color: var(--bs-body-color); text-decoration: none; padding: .25rem .5rem 1rem; }
.app-sidebar-nav { display: flex; flex-direction: column; gap: 2px; }
.app-sidebar-foot { margin-top: auto; display: flex; flex-direction: column; gap: 2px; padding-top: .75rem; border-top: 1px solid var(--bs-border-color); }
.app-nav-item {
    display: flex; align-items: center; gap: .7rem; padding: .5rem .65rem; border-radius: .625rem;
    color: var(--bs-secondary-color); font-weight: 500; font-size: .9rem; text-decoration: none;
    background: none; border: 0; text-align: left; width: 100%;
}
.app-nav-item i { font-size: 1.05rem; width: 1.2rem; }
.app-nav-item span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.app-nav-item:hover { background: var(--bs-tertiary-bg); color: var(--bs-body-color); }
.app-nav-item.active { background: var(--accent-subtle); color: var(--bs-primary); font-weight: 650; }
.app-nav-sep { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color); opacity: .7; padding: 1rem .65rem .35rem; }

/* Смещение контента под сайдбар на lg+. */
@media (min-width: 992px) {
    body.has-app-shell .app-content { margin-left: 240px; }
    body.has-app-shell .app-header-mobile { display: none; }  /* шапку-карточку прячем на десктопе — есть сайдбар */
}
```

- [ ] **Step 3: Вставить сайдбар и обернуть контент в base.html.twig.** Сайдбар — сразу после `<body>`; существующие `header/main/footer` обернуть в `.app-content` (чтобы применить offset). Класс `app-header-mobile` навесить на `<header>` (прячется на десктопе).

- [ ] **Step 4: Собрать.** Run: `cd app && yarn dev`.

- [ ] **Step 5: Проверить на десктопе** (≥992px, обе темы): сайдбар слева, активный пункт подсвечен, контент смещён и не заезжает под сайдбар; таб-панель скрыта. На мобиле — наоборот (сайдбар скрыт, табы видны). Переключение темы из сайдбара работает.

- [ ] **Step 6: Коммит.**

```bash
git add app/src/Shared/Infrastructure/Templates/_shell/_sidebar.html.twig app/assets/styles/components/app-shell.css app/src/Shared/Infrastructure/Templates/base.html.twig
git commit -m "Десктоп получил постоянный сайдбар с навигацией и админ-группой вместо выезжающей шторки"
```

---

## Task 4: `#mainMenu` → «Ещё» + уборка гамбургера

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/base.html.twig`

**Interfaces:**
- Consumes: `#mainMenu` открывается вкладкой «Ещё» (Task 2) на мобиле.

- [ ] **Step 1: Переименовать и почистить `#mainMenu`.** Заголовок «Меню» → «Ещё». Убрать из него ПЕРВИЧНУЮ навигацию (группы «Покрытия»/«Документы» со ссылками Покрытия/Системы/Документы) — они теперь в табах/сайдбаре. Оставить: аккаунт (ЛК/выход или вход/регистрация), тему, группу «Администрирование» (для админа). Это делает «Ещё» вторичным меню, как в макете (Вариант 2).

- [ ] **Step 2: Убрать floating-гамбургер** (`button.menu-toggle`, ~строки 60–66) и относящийся к нему блок в `header`. Шапку-карточку с логотипом оставить на мобиле (`.app-header-mobile`), на десктопе она скрыта (Task 3 Step 2). Логотип — тап = главная (уже `href app_homepage`).
   - `.menu-toggle` стили в `shell.css` больше не используются — удалить их из `shell.css`.

- [ ] **Step 3: Собрать и проверить.** Run: `cd app && yarn dev`. Проверить: на мобиле «Ещё» открывает шторку без дублей навигации (только аккаунт/тема/админ); гамбургера нет; на десктопе шапка-карточка скрыта, навигация — сайдбар.

- [ ] **Step 4: Греп — `menu-toggle` не осталось.** Run: `rtk grep -rn "menu-toggle" src app/assets/styles`. Expected: пусто.

- [ ] **Step 5: Коммит.**

```bash
git add app/src/Shared/Infrastructure/Templates/base.html.twig app/assets/styles/components/shell.css
git commit -m "Шторка меню стала разделом «Ещё» (аккаунт/тема/админ), плавающий гамбургер убран — навигация теперь в табах и сайдбаре"
```

---

## Task 5: Native-переходы (View Transitions API)

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/base.html.twig` (meta), `app/assets/styles/components/app-shell.css` (opt-in + reduced-motion)

- [ ] **Step 1: Включить cross-document view transitions.** В `<head>` base добавить:

```twig
<meta name="view-transition" content="same-origin">
```

- [ ] **Step 2: CSS opt-in + уважение reduced-motion в `app-shell.css`.**

```css
@view-transition { navigation: auto; }
@media (prefers-reduced-motion: reduce) {
    @view-transition { navigation: none; }
}
```

- [ ] **Step 3: Собрать и проверить.** Run: `cd app && yarn dev`. В Chrome (поддержка cross-document VT) переходы между списком/страницами — плавные (крос-фейд). В браузере без поддержки — обычный переход (без ошибок). При включённом «уменьшить движение» — без анимации.

- [ ] **Step 4: Коммит.**

```bash
git add app/src/Shared/Infrastructure/Templates/base.html.twig app/assets/styles/components/app-shell.css
git commit -m "Плавные переходы между экранами через View Transitions API — прогрессивно, с уважением reduced-motion"
```

---

## Task 6: Финальная верификация деплоя

- [ ] **Step 1: Полная сборка.** Run: `cd app && yarn dev`. Expected: без ошибок.
- [ ] **Step 2: Свип по устройствам/темам.** Мобайл (узко): табы внизу, активный пункт, «Ещё» = аккаунт/тема/админ, контент не под панелью, лендинг без табов. Десктоп (≥992px): сайдбар, активный пункт, offset контента, шапка-карточка скрыта, табы скрыты. Обе темы. Переходы плавные в Chrome.
- [ ] **Step 3: Регрессии навигации.** Пройти по всем разделам (Покрытия/Системы/Документы/ЛК/выход) с мобилы и десктопа — ссылки ведут туда же, что раньше. Фильтры на списках (шторки `#allFiltersOffcanvas`/`#chipFilter*`) открываются как прежде (не задеты).
- [ ] **Step 4: Грепы.** `rtk grep -rn "menu-toggle\|mainMenuLabel\">Меню" src` — старых артефактов нет. `nav_items` источник один (`_nav_data`).

## Self-review (покрытие спеки)

- Раздел 4 спеки: нижняя таб-панель (Вариант 2, минус «Химстойкость» до Деплоя 6) → Task 2; десктоп-сайдбар + группа «Администрирование» → Task 3; «Ещё» = аккаунт/тема/админ → Task 4; «Главная» по логотипу → сохранено (логотип href app_homepage); View Transitions → Task 5.
- DRY навигации → Task 1 (единый `_nav_data`).
- Фильтры-offcanvas не затронуты (отдельные ID, per-page) — подтверждено разведкой.
- Вне деплоя: новая entity-карточка, bottom-sheet, экран «Химстойкость» (там же появится 5-я вкладка), формы/сравнение/редактор — последующие деплои.

## Открытые точки

- Нужен ли на мобиле верхний app-bar с контекстным заголовком экрана, или достаточно шапки-карточки с логотипом — решить на Task 3/4 по факту (сейчас оставляем шапку-карточку на мобиле, контекстные large-title придут с редизайном экранов в след. деплоях).
- Точный брейкпоинт сайдбара (`lg` 992px vs `xl`) — подтвердить визуально на Task 3.
