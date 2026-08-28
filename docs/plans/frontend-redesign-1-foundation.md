# Деплой 1 «Фундамент»: дизайн-токены + вынос inline-стилей/скриптов

> **Для исполнителя:** реализация пошаговая, с визуальной проверкой после каждой задачи. Чекбоксы (`- [ ]`) — трекинг. Это фронтовый рефакторинг: «тест» = сборка ассетов + визуальная сверка в светлой/тёмной теме + зелёный существующий набор тестов, а не юнит-тест на перемещённый CSS.

**Цель:** дать всему фронту современный нейтральный вид через слой дизайн-токенов поверх Bootstrap и вынести inline-`<style>`/`<script>` из `base.html.twig` в ассеты/Stimulus — без смены каркаса и без визуальных регрессий.

**Архитектура:** новый CSS-слой переопределяет тему Bootstrap (`--bs-*`) + вводит собственные семантические токены; крупный inline-`<style>` из base переезжает в `assets/styles/`, inline-`<script>` — в Stimulus-контроллеры. Разметка шаблонов НЕ меняется (каркас/навигацию трогает Деплой 2).

**Tech Stack:** Symfony Twig, Webpack Encore, Bootstrap 5.3, Stimulus (Hotwired), CSS custom properties.

**Spec:** `docs/plans/frontend-redesign-design.md` (разделы 3 «Дизайн-токены», 8 «Компоненты и где живёт код», 9 п.1).

## Global Constraints

- Bootstrap НЕ выкидываем — только слой поверх (`--bs-*` override + свои токены). Порядок импортов в `app.css`: bootstrap → токены → компоненты (позже = выигрывает).
- Тёмная/светлая — существующий `data-bs-theme` на `<html>`; FOUC-safe theme-init `<script>` в `<head>` base.html.twig ОСТАЁТСЯ (единственное разрешённое исключение по CLAUDE.md).
- «В Twig только разметка»: после деплоя в base.html.twig не должно остаться `<style>` и прикладного `<script>` (кроме theme-init в head).
- Без визуальных регрессий: соотношение «зона `bg-body-tertiary` ↔ элемент `bg-body`» и читаемость в обеих темах сохранить. Токены вводить консервативно, сверять глазами.
- Работа без JS: вынос скриптов в Stimulus не должен ломать серверные fallback'и (форма/списки и так работают без JS; back-to-top/flash-dismiss/тема — прогрессивное улучшение).
- Коммиты: если исполняем через subagent-driven-development — коммит по задаче (исключение проекта для SDD). Если инлайн в текущей сессии — НЕ коммитить (коммитит пользователь). Шаги «Коммит» в задачах трактовать по режиму исполнения.
- После правок CSS/JS/Twig: `cd app && yarn dev`. Прогон тестов затронутых контекстов — по `reference_test_run_env` (unit на хосте, functional в контейнере).

## Карта файлов

- Создать `app/assets/styles/tokens.css` — слой токенов: override `--bs-*` (light/dark) + собственные семантические vars.
- Создать `app/assets/styles/components/shell.css` — извлечённые стили каркаса (header/footer/menu-toggle/back-to-top/flash/offcanvas/user-avatar/theme-toggle) из base.
- Создать `app/assets/styles/components/tables.css` — извлечённый блок `.table-rows` из base.
- Создать `app/assets/styles/components/refresh.css` — извлечённый блок «GitHub refresh» (типографика, .card/.list-group уточнения) + tagify-override из base. (Часть переедет в токены; здесь — то, что относится к конкретным компонентам.)
- Модифицировать `app/assets/styles/app.css` — добавить `@import` новых файлов в правильном порядке.
- Модифицировать `app/src/Shared/Infrastructure/Templates/base.html.twig` — удалить inline-`<style>` (строки ~42–420) и прикладной `<script>` (~623–692); навесить `data-controller` на существующие узлы.
- Создать Stimulus-контроллеры в `app/assets/controllers/`: `back_to_top_controller.js`, `flash_controller.js`, `theme_toggle_controller.js`, `offcanvas_autoclose_controller.js`. Инициализацию Bootstrap-tooltip перенести в `app/assets/bootstrap.js` (или отдельный маленький init).

---

## Task 1: Слой дизайн-токенов (консервативный override Bootstrap)

**Files:**
- Create: `app/assets/styles/tokens.css`
- Modify: `app/assets/styles/app.css` (добавить импорт после bootstrap)

**Interfaces:**
- Produces: CSS-переменные `--bs-*` (переопределённые) + собственные `--surface, --sunken, --accent-subtle, --diff-bg, --diff-bar, --diff-txt, --danger-subtle, --ok-subtle, --radius-*` для последующих компонентов/деплоев.

- [ ] **Step 1: Создать `tokens.css`.** Override делаем консервативно — сохраняем направление «tertiary — тонированная зона, body — элемент», меняем палитру/акцент/радиусы/типографику. Значения из спеки, раздел 3.

```css
/* Слой токенов поверх Bootstrap 5.3. Импортируется в app.css ПОСЛЕ bootstrap,
   поэтому переопределяет его тему. НЕ меняем разметку — только переменные. */

/* ── Светлая (Bootstrap ставит эти vars на :root и [data-bs-theme=light]) ── */
:root,
[data-bs-theme="light"] {
  --bs-body-bg: #ffffff;
  --bs-body-color: #14181f;
  --bs-secondary-color: #616b7a;         /* text-body-secondary */
  --bs-secondary-bg: #eceef2;
  --bs-tertiary-bg: #f2f4f7;             /* bg-body-tertiary — зоны */
  --bs-border-color: #e4e7ec;
  --bs-primary: #2f6feb;
  --bs-primary-rgb: 47, 111, 235;
  --bs-link-color: #2f6feb;
  --bs-link-color-rgb: 47, 111, 235;
  --bs-link-hover-color: #2456bd;
  --bs-border-radius: .75rem;
  --bs-border-radius-sm: .5rem;
  --bs-border-radius-lg: 1rem;

  /* собственные семантические токены (для новых компонентов и след. деплоев) */
  --surface: #ffffff;
  --sunken: #f0f2f5;
  --accent-subtle: #eaf1ff;
  --ok: #1a7f4b;  --ok-subtle: #e6f4ec;
  --warn: #8a6d1a; --warn-subtle: #fbf3d8;
  --danger-strong: #c0392b; --danger-subtle: #fdeceb;
  --diff-bg: #fff6e6; --diff-bar: #e0a83a; --diff-txt: #8a6d1a;
  --ease-emph: cubic-bezier(.22,.61,.36,1);
}

/* ── Тёмная ── */
[data-bs-theme="dark"] {
  --bs-body-bg: #0d1117;
  --bs-body-color: #e6edf3;
  --bs-secondary-color: #9aa5b1;
  --bs-secondary-bg: #1c222b;
  --bs-tertiary-bg: #161b22;
  --bs-border-color: #2a3038;
  --bs-primary: #4d8bf5;
  --bs-primary-rgb: 77, 139, 245;
  --bs-link-color: #4d8bf5;
  --bs-link-color-rgb: 77, 139, 245;
  --bs-link-hover-color: #7aa7ff;

  --surface: #161b22;
  --sunken: #1c222b;
  --accent-subtle: #17233b;
  --ok: #3fb950;  --ok-subtle: #132a1c;
  --warn: #d8b24a; --warn-subtle: #2a2413;
  --danger-strong: #f0857d; --danger-subtle: #2a1615;
  --diff-bg: #2a2413; --diff-bar: #d8b24a; --diff-txt: #e3c766;
}
```

- [ ] **Step 2: Подключить в `app.css`.** Добавить импорт сразу после bootstrap, до компонентных стилей:

```css
@import "~bootstrap";
@import "tokens.css";          /* ← новая строка: токены поверх bootstrap */
@import "bootstrap-icons.css";
/* ...остальные @import без изменений... */
```

- [ ] **Step 3: Собрать ассеты.** Run: `cd app && yarn dev`. Expected: сборка без ошибок.

- [ ] **Step 4: Визуальная сверка (обязательно, обе темы).** Открыть в браузере ключевые страницы: главная кабинета, список покрытий, форма покрытия, быстрый просмотр. В светлой и тёмной. Проверить: (а) акцент стал синим `#2f6feb`/`#4d8bf5`; (б) радиусы карточек крупнее; (в) зоны `bg-body-tertiary` по-прежнему отличаются от элементов `bg-body` (контраст не инвертирован); (г) текст читаем, границы видны. Если контраст зон/элементов «схлопнулся» — подкрутить `--bs-tertiary-bg`/`--bs-secondary-bg` и пересобрать.

- [ ] **Step 5: Прогнать существующие тесты** (убедиться, что ничего не завязано на цвет/класс через снапшоты). Run: `cd app && vendor/bin/phpunit`. Expected: как минимум не хуже, чем до правки (CSS на PHP-тесты влиять не должен).

- [ ] **Step 6: Коммит** (по режиму — см. Global Constraints).

```bash
git add app/assets/styles/tokens.css app/assets/styles/app.css
git commit -m "Нейтральная дизайн-система: слой токенов поверх Bootstrap задал новый акцент, радиусы и палитру для светлой и тёмной"
```

---

## Task 2: Вынос inline-`<style>` из base.html.twig в ассеты

**Files:**
- Create: `app/assets/styles/components/shell.css`, `app/assets/styles/components/tables.css`, `app/assets/styles/components/refresh.css`
- Modify: `app/assets/styles/app.css`, `app/src/Shared/Infrastructure/Templates/base.html.twig`

**Interfaces:**
- Consumes: токены из Task 1 (можно заменить хардкод-цвета в перенесённых правилах на `var(--bs-*)`, где это 1-в-1; рискованные замены НЕ делать — просто переносить как есть).

- [ ] **Step 1: Перенести стили каркаса в `shell.css`.** Скопировать из `base.html.twig` (`<style>` ~строки 42–420) правила: `body`/`main`, `.blog-header*`, `.flash-messages*`, `.menu-toggle`, `.back-to-top`, `.duration-display-btn` (media), `.user-avatar*`, `.offcanvas-*`, `#mainMenu *`, `.user-email`, `.theme-toggle`. Перенести дословно (без изменения значений).

- [ ] **Step 2: Перенести `.table-rows`-блок в `tables.css`** (тот же блок, дословно).

- [ ] **Step 3: Перенести блок «GitHub refresh» + tagify-override в `refresh.css`** (типографика `body`/заголовки, `.blog-header-logo`, `.list-group`/`.list-group-item`, `.card`, flash-`.alert`, `.tagify*`). Дословно; НЕ дублировать то, что уже задано токенами (радиусы/цвета, которые теперь идут из `--bs-*`, можно опустить — но при сомнении оставить как есть, визуально сверим).

- [ ] **Step 4: Подключить в `app.css`** (после токенов и существующих компонентов):

```css
@import "components/shell.css";
@import "components/tables.css";
@import "components/refresh.css";
```

- [ ] **Step 5: Удалить весь `<style>...</style>` из `base.html.twig`** (~строки 42–420). Оставить `{% block stylesheets %}`/`{% block javascripts %}` и FOUC theme-init `<script>` в head без изменений.

- [ ] **Step 6: Собрать и сверить.** Run: `cd app && yarn dev`. Затем визуально сверить те же экраны — вид должен быть идентичен состоянию после Task 1 (мы только переместили правила, не меняли значения). Проверить header/footer, шторку `#mainMenu`, flash, back-to-top, `.table-rows` в сравнении/форме.

- [ ] **Step 7: Греп — убедиться, что `<style>` в base не осталось.** Run: `rtk grep -n "<style" src/Shared/Infrastructure/Templates/base.html.twig`. Expected: пусто.

- [ ] **Step 8: Коммит.**

```bash
git add app/assets/styles/components/shell.css app/assets/styles/components/tables.css app/assets/styles/components/refresh.css app/assets/styles/app.css app/src/Shared/Infrastructure/Templates/base.html.twig
git commit -m "Стили каркаса переехали из Twig в ассеты — base.html.twig очищен от inline-style по правилу «в Twig только разметка»"
```

---

## Task 3: Вынос inline-`<script>` из base.html.twig в Stimulus

**Files:**
- Create: `app/assets/controllers/back_to_top_controller.js`, `flash_controller.js`, `theme_toggle_controller.js`, `offcanvas_autoclose_controller.js`
- Modify: `app/assets/bootstrap.js` (tooltip-init), `app/src/Shared/Infrastructure/Templates/base.html.twig`

**Interfaces:**
- Consumes: существующая разметка base (кнопки `.back-to-top`, `#themeToggle`, `.flash-messages`, `#mainMenu`). Навешиваем `data-controller`/`data-action` вместо глобальных слушателей.

- [ ] **Step 1: `back_to_top_controller.js`** — показывает кнопку после скролла и скроллит вверх.

```js
import { Controller } from '@hotwired/stimulus';

// Кнопка «Наверх»: видима после 300px скролла, плавный скролл к верху.
export default class extends Controller {
  connect() {
    this.onScroll = this.onScroll.bind(this);
    window.addEventListener('scroll', this.onScroll, { passive: true });
    this.onScroll();
  }
  disconnect() { window.removeEventListener('scroll', this.onScroll); }
  onScroll() { this.element.style.display = window.scrollY > 300 ? 'flex' : 'none'; }
  top() { window.scrollTo({ top: 0, behavior: 'smooth' }); }
}
```

- [ ] **Step 2: `flash_controller.js`** — авто-скрытие flash: success 5с, прочие 10с (перенос логики `closeAfter`).

```js
import { Controller } from '@hotwired/stimulus';
import { Alert } from 'bootstrap';

// Авто-скрытие flash-тостов: success — 5с, warning/danger/info — 10с.
export default class extends Controller {
  connect() {
    this.element.querySelectorAll('.alert').forEach((alert) => {
      const delay = alert.classList.contains('alert-success') ? 5000 : 10000;
      setTimeout(() => Alert.getOrCreateInstance(alert).close(), delay);
    });
  }
}
```

- [ ] **Step 3: `theme_toggle_controller.js`** — переключение `data-bs-theme` + синхронизация иконки (перенос логики `#themeToggle`).

```js
import { Controller } from '@hotwired/stimulus';

// Переключатель темы. Актуальная тема ставится FOUC-safe скриптом в <head>;
// здесь — клик и синхронизация иконки солнце/луна.
export default class extends Controller {
  static targets = ['light', 'dark'];
  connect() { this.sync(); }
  sync() {
    const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    if (this.hasLightTarget) this.lightTarget.classList.toggle('d-none', dark);
    if (this.hasDarkTarget) this.darkTarget.classList.toggle('d-none', !dark);
  }
  toggle() {
    const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) { /* заблокирован — игнор */ }
    this.sync();
  }
}
```

- [ ] **Step 4: `offcanvas_autoclose_controller.js`** — закрывает шторку при клике на пункт-ссылку (перенос логики `#mainMenu .list-group-item`).

```js
import { Controller } from '@hotwired/stimulus';
import { Offcanvas } from 'bootstrap';

// Закрывает offcanvas при клике по пункту-ссылке навигации.
export default class extends Controller {
  close() { Offcanvas.getInstance(this.element)?.hide(); }
}
```

- [ ] **Step 5: Перенести tooltip-init в `bootstrap.js`** (глобальная инициализация вместо inline):

```js
// в конец app/assets/bootstrap.js
import { Tooltip } from 'bootstrap';
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new Tooltip(el));
});
```

- [ ] **Step 6: Навесить контроллеры в `base.html.twig` и удалить inline-`<script>`.**
  - `.back-to-top`: `data-controller="back-to-top" data-action="click->back-to-top#top"` (убрать `onclick="scrollToTop()"`).
  - `.flash-messages`: `data-controller="flash"`.
  - `#themeToggle`: `data-controller="theme-toggle" data-action="click->theme-toggle#toggle"`, а иконкам добавить `data-theme-toggle-target="light"` / `="dark"` (вместо `data-theme-icon`).
  - `#mainMenu`: `data-controller="offcanvas-autoclose"`, ссылкам-пунктам `data-action="click->offcanvas-autoclose#close"`.
  - Footer «Наверх» ссылка (`onclick="scrollToTop()..."`): заменить на `data-action="click->back-to-top#top"` — но эта ссылка вне `.back-to-top`; проще сделать её обычным `href="#"` с отдельным мелким обработчиком ИЛИ указать на кнопку. Вариант: обернуть общий скролл в тот же контроллер, повесив `data-controller="back-to-top"` и на футер-ссылку тоже, или оставить `href="#top"`-якорь. Выбрать якорный `href="#"` + `data-action` на back-to-top контроллер, повесив контроллер на `<body>`.
  - Удалить весь `<script>...</script>` (~строки 623–692).

- [ ] **Step 7: Собрать.** Run: `cd app && yarn dev`. Expected: сборка ок, контроллеры зарегистрированы (Stimulus авто-регистрация из `controllers/`).

- [ ] **Step 8: Проверить поведение вручную** (в браузере): (а) переключение темы работает и иконка меняется; (б) flash-тост исчезает сам (success ~5с); (в) кнопка «Наверх» появляется при скролле и скроллит; (г) шторка `#mainMenu` закрывается по клику на пункт; (д) tooltips показываются. Проверить в обеих темах.

- [ ] **Step 9: Греп — в base не осталось прикладного `<script>`** (только theme-init в head). Run: `rtk grep -n "<script" src/Shared/Infrastructure/Templates/base.html.twig`. Expected: единственное совпадение — FOUC theme-init в head.

- [ ] **Step 10: Коммит.**

```bash
git add app/assets/controllers/ app/assets/bootstrap.js app/src/Shared/Infrastructure/Templates/base.html.twig
git commit -m "Логика шапки уехала из Twig в Stimulus: тема, flash-автоскрытие, «наверх», закрытие шторки — base без inline-скриптов"
```

---

## Task 4: Финальная верификация деплоя

**Files:** —

- [ ] **Step 1: Полная сборка.** Run: `cd app && yarn dev`. Expected: без ошибок/ворнингов сборки.
- [ ] **Step 2: Прогон тестов.** Run: `cd app && vendor/bin/phpunit` (unit на хосте) + functional-контексты по `reference_test_run_env`. Expected: зелёно, регрессий нет.
- [ ] **Step 3: Визуальный свип** по чек-листу в обеих темах: главная кабинета, список покрытий (карточки/чипы/трей), форма покрытия, быстрый просмотр (модалка), сравнение (`.table-rows`), шторка меню, header/footer, flash. Убедиться: вид современнее (акцент/радиусы/типографика), но каркас и раскладка те же, контрасты не инвертированы.
- [ ] **Step 4: Грепы чистоты.** `rtk grep -n "<style\|onclick=\|scrollToTop" src/Shared/Infrastructure/Templates/base.html.twig`. Expected: только theme-init в head; никаких `<style>`, `onclick`, глобального `scrollToTop`.
- [ ] **Step 5: Проверить `.gitignore`** — добавить `.superpowers/` если ещё нет (чтобы макеты брейншторма не попадали в коммиты). (По апруву пользователя.)

## Self-review (покрытие спеки)

- Раздел 3 спеки (токены) → Task 1. Раздел 8 (вынос inline, где живёт код) → Task 2 (стили) + Task 3 (скрипты). Раздел 9 п.1 (фундамент, без регрессий) → все задачи + Task 4.
- Каркас/навигация (нижняя панель, app-shell), PWA, новая карточка, экраны — вне этого деплоя (Деплой 2+). Здесь только палитра/типографика/радиусы + гигиена inline-кода.
- Плейсхолдеров нет; имена контроллеров и таргетов согласованы между Task 3 и разметкой base.

## Открытые точки

- Значения `--bs-tertiary-bg`/`--bs-secondary-bg` — подобрать окончательно на Step 4 Task 1 по факту контраста зон/элементов в обеих темах.
- Футер-ссылка «Наверх»: финальный способ привязки к back-to-top контроллеру решить на Step 6 Task 3 (контроллер на `<body>` + `data-action`).
