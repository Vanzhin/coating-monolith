# Редизайн экрана «Сравнение покрытий» (§6.6)

**Статус:** план готов, реализация — следующий заход.
**Ветка:** `feat/frontend-redesign-foundation` (или отдельная `feat/frontend-redesign-compare` от неё).
**Связано:** дизайн-док `frontend-redesign-design.md` §6.6; переиспользуем паттерны из quickview (`preview.css` `.pv-matrix`/`.pv-drying`) и entity-карточки (`.ecard-mono`). Память: `project_compare_unification` (два компаратора; унификация с compare систем — вне этой задачи).

## Цель

Привести `/coatings/compare` к §6.6, **не трогая бэк** (контракт данных остаётся):
- Таблица «поле × покрытия»: первый столбец (поле) **липкий слева**, покрытия скроллятся вправо.
- Шапка столбца покрытия = мини-монограм (`.ecard-mono` по `base`) + короткое имя + крестик «убрать из сравнения».
- Различающиеся строки — **мягкий diff в токенах** (`--diff-bg` заливка + `--diff-bar` полоса слева + точка), вместо Bootstrap `table-warning`.
- Тумблер **«Только различия»** (скрывает совпадающие строки).
- Пустой столбец **«+ Добавить»** (пока покрытий < 4).
- Блок «Время высыхания» — на `.pv-matrix`/`.pv-drying` (как в превью), различающиеся **столбцы** — diff-токенами.
- Панель «Видимые поля» (чекбоксы) остаётся, рестайл под soft-дизайн.

## Текущее состояние (что переписываем)

`app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig` — легаси:
- Скалярная таблица `table table-sm table-rows`, различия — `table-warning`, подписи — `text-muted`.
- Сайдбар чекбоксов видимых полей (`compare-filter`), крестик убрать (`compare-remove`).
- Матрицы времени высыхания — снова `table table-sm` + `table-warning` по столбцам.
- Нет: липкого столбца, монограм-шапок, «только различия», «+ Добавить», diff-токенов.

## Контракт данных (готов, менять НЕ надо)

`CompareAction` (`Coatings/Infrastructure/Controller/Coating/CompareAction.php`), GET `?ids=a,b,c` (2..4), рендерит `compare.html.twig` с:
- `subjects` — list<CoatingDTO> (есть `id`, `title`, `base`, `manufacturer.title`, скалярные поля, `possibleColors`).
- `comparison` — результат `ObjectComparator` (`ComparisonConfig(FIELDS)`): `comparison.rows`, каждая строка — `.field`, `.values` (по subject), `.isDifferent`.
- `fields` — `CompareAction::FIELDS` (12 скалярных полей).
- `fieldLabels` — подписи (сейчас в шаблоне).
- `coating_compare_matrix(subjects)` (twig-функция `CoatingCompareMatrixBuilder`): секции `.label`, `.columns` (темпы), `.rows` (`.subject`, `.values[t]{minutes,is_calculated}`), `.diffColumns[t]`.

`MAX_ITEMS = 4`. Diff-токены уже в `tokens.css` (обе темы): `--diff-bg`, `--diff-bar`, `--diff-txt`.

## Развилки (решить в начале захода)

1. **«+ Добавить» столбец** — как добавлять покрытие:
   - (реком.) typeahead в шапке пустого столбца, переиспользуя `app_cabinet_coating_coating_suggest`; на выбор — redirect на `compare?ids=<старые>,<новый>`.
   - альтернатива: ссылка «выбрать в списке» (compare-tray).
2. **«Только различия»** — куда положить тумблер:
   - (реком.) расширить `compare_filter_controller.js` (у него уже есть строки-таргеты и localStorage) режимом `onlyDiff`, тумблер — в панель «Видимые поля».
   - альтернатива: отдельный мелкий контроллер.
3. **Мобилка** — §6.6 desktop-first. На узком: та же таблица с горизонтальным скроллом и липким первым столбцом; панель «Видимые поля» уходит под таблицу (уже `order-2`). Подтвердить, что этого достаточно.

## Файлы

- Переписать: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/compare.html.twig`.
- Создать: `app/assets/styles/admin/compare.css` (липкий столбец, diff-полоса/точка, ширины столбцов, «+ Добавить», монограм-шапка). `@import` в `app/assets/styles/app.css`.
- Правка: `app/assets/controllers/compare_filter_controller.js` (+ режим «только различия»).
- Возможно создать: `app/assets/controllers/compare_add_controller.js` (typeahead «+ Добавить») — либо переиспользовать `coating-tags`/`suggest`.
- НЕ трогаем: `CompareAction`, `ObjectComparator`, `CoatingCompareMatrixBuilder`, DTO.

## Задачи (по шагам)

### Задача 1 — CSS-каркас `compare.css`
- [ ] Создать `app/assets/styles/admin/compare.css`; добавить `@import "admin/compare.css";` в `app.css`.
- [ ] Липкий первый столбец: `.cmp-table` — обёртка `overflow-x:auto`; `.cmp-table th.cmp-field, td.cmp-field { position: sticky; left: 0; z-index: 2; background: var(--surface); }`.
- [ ] Diff-строка: `.cmp-row--diff { background: var(--diff-bg); }` + полоса слева через `box-shadow: inset 3px 0 0 var(--diff-bar)` на липкой ячейке + точка `.cmp-row--diff .cmp-field::after { content:""; …; background: var(--diff-bar); }`.
- [ ] Монограм-шапка: переиспользовать `.ecard-mono` (уменьшить до ~32px), крестик — `.btn-close`.
- [ ] Сборка `cd app && yarn dev`, визуальная проверка каркаса.

### Задача 2 — Скалярная таблица на новый вид
- [ ] Переписать `<table>` скалярного сравнения: `th.cmp-field` (липкий) + столбцы-покрытия с монограм-шапкой (`.ecard-mono` по `subject.base` + короткое имя + `compare-remove` крестик).
- [ ] Строки: `data-compare-filter-target="row" data-field="…"`; на `row.isDifferent` — класс `cmp-row--diff` (вместо `table-warning`).
- [ ] Сохранить форматтер `format_value` и подписи `fieldLabels`.
- [ ] Проверка: `./run console lint:twig …/compare.html.twig`, `CompareActionTest` зелёный.

### Задача 3 — Тумблер «Только различия»
- [ ] В `compare_filter_controller.js` добавить value/target `onlyDiff`; при включении скрывать строки без `cmp-row--diff` (пересечение с видимостью полей). Состояние — в localStorage рядом с полями.
- [ ] Тумблер (soft-switch) в панель «Видимые поля».
- [ ] Проверка в браузере: тумблер прячет совпадающие строки, поля-чекбоксы работают совместно.

### Задача 4 — Столбец «+ Добавить» (≤4)
- [ ] Показывать пустой столбец с typeahead только если `subjects|length < 4`.
- [ ] По выбору покрытия — redirect на `compare?ids=<current>,<newId>` (переиспользовать `app_cabinet_coating_coating_suggest`; либо `coating-tags` в режиме single-select без create).
- [ ] Проверка: добавление 3-го/4-го покрытия, при 4 столбец скрыт.

### Задача 5 — Матрицы времени высыхания на `.pv-matrix`/`.pv-drying`
- [ ] Заменить `table table-sm table-warning` на `.pv-drying` панель + `.pv-matrix` (как в `_coating_preview`): липкий столбец-подпись покрытия, зебра, `diffColumns` — diff-токенами (заливка столбца `--diff-bg`, шапка `--diff-bar`).
- [ ] Легенда/сноска — как в превью (курсив = интерполяция, «—» = вне диапазона).
- [ ] Проверка: `CoatingCompareMatrixBuilderTest` не затронут (бэк тот же); визуально — обе темы.

### Задача 6 — Панель «Видимые поля» + мобилка
- [ ] Рестайл чекбоксов под soft-дизайн (без жёстких рамок; при желании — `.facet-pill`-стиль тумблеров полей).
- [ ] Проверить `order-2`/`order-1` на <lg: панель под таблицей, таблица со скроллом и липким столбцом сверху.

### Задача 7 — Верификация
- [ ] `cd app && yarn dev`.
- [ ] `./run console lint:twig` на compare.
- [ ] `CompareActionTest` (+ при необходимости дописать ассерты на новую разметку: `cmp-field`, `cmp-row--diff`, тумблер «только различия», монограм-шапка).
- [ ] Браузер: 2/3/4 покрытия, обе темы, узкий экран (липкий столбец + скролл), «только различия», «+ Добавить», убрать столбец.

## Проверка/ограничения

- Без JS страница обязана рендериться (таблица + серверные данные); тумблер/скрытие полей/«+Добавить» — прогрессивное улучшение.
- Diff — только токенами (`--diff-*`), никаких `table-warning`. Никаких новых цветов вне токенов.
- Разметку матрицы копируем из `_coating_preview`/`preview.css` (не дублировать стили — переиспользовать `.pv-matrix`/`.pv-drying`).
- Тач-таргеты ≥44px, контраст в обеих темах.
