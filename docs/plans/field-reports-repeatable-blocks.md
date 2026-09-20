# Повторяемые блоки/строки в движке шаблонов (repeatable rows)

Статус: РЕАЛИЗОВАНО (код+тесты). Осталось — расстановка плейсхолдеров в .docx-шаблонах (делает пользователь).
Один деплой. Смежные планы: `report-template-placeholders.md` (список плейсхолдеров).

Реализация: cloneRow (строка таблицы) + cloneBlock (абзац-список Word) — единый принцип по точечным
`{{group.sub}}`; таблица без маркеров → cloneRow, обёртка `{{group}}…{{/group}}` → cloneBlock. Проектор
отдаёт RepeatValue для ListRows И StringList (рядом с плоскими). Старые плоские плейсхолдеры не тронуты.

## Проблема

Проектор `ReportRenderDataProjector::formatList()` схлопывает `ListRows`-блок (комиссия, несоответствия,
приборы) в ОДНУ строку-текст «Орг — Должность — ФИО — Дата\nОрг2 — …». Это его собственное форматирование,
не управляемое шаблоном. В Акте нужна таблица (напр. подписи комиссии: столбцы организация/должность/ФИО/
дата), а ссылаться не на что — есть только слипшийся `{{commission_items}}`.

Правильный слой: проектор только маппит (отдаёт список наборов значений), рендерер только клонирует строку по
количеству и подставляет, раскладку (столбцы, границы, шапка) держит .docx. Механизм в PhpWord есть:
`cloneRowAndSetValues($anchor, $values)` (клон строки таблицы по количеству + заполнение `{{macro#i}}`),
`deleteRow($anchor)` (убрать строку-шаблон при пустом списке).

## Решения по развилкам (зафиксированы)

1. **Механизм** — `cloneRowAndSetValues` для строк таблицы. Пусто → `deleteRow`. (Не cloneBlock: маркеры
   блока вокруг `<w:tr>` в Word ставятся грязно; cloneRow заточен под таблицы.)
2. **Конвенция шаблона** — плейсхолдеры повторяемой строки в точечной нотации `{{list.sub}}`
   (напр. `{{commission.organization}}`, `{{commission.name}}`). Точка = «повторяемая группа», отличает от
   плоских `{{block_field}}`. Ограничение: в одной строке таблицы — плейсхолдеры ТОЛЬКО одной группы (cloneRow
   индексирует ВСЕ макросы строки). `?`-суффикс у повторяемых НЕ используем (они опциональны по природе).
3. **Имя группы** = ключ блока (`commission`, `process`, `instruments`). Ограничение: один повторяемый список
   на блок (сейчас так у всех). Подполя группы = ключи `itemFields`.
4. **Носитель данных** — новый `RepeatValue implements TemplateValue` со списком строк
   `list<array<string,string>>` (подключ → уже отформатированное значение). Кладётся под ключ = имя группы.
5. **Обратная совместимость** — проектор ПРОДОЛЖАЕТ отдавать и плоский `{{block_items}}` (`formatList`), и
   `RepeatValue` под ключом группы. Старые шаблоны с `{{commission_items}}` работают; новые используют таблицу.
   Никакой миграции данных.
6. **Пустой список** — `deleteRow(anchor)` (строка-шаблон удаляется, шапка таблицы остаётся).
7. **Скоуп** — только `ListRows` (комиссия/несоответствия/приборы). `StringList` (рекомендации/выводы)
   остаётся нумерованным текстом. Слои (`Layers`) НЕ трогаем (у них фикс-шаблоны 1–4; унификация — отдельно).

## Затрагиваемые файлы

- Создать `app/src/Shared/Domain/Templating/RepeatValue.php` — VO списка строк.
- Изменить `app/src/Shared/Domain/Templating/RenderData.php` — хранить/отдавать RepeatValue, `repeatGroups()`.
- Изменить `app/src/Shared/Infrastructure/Service/DocxTemplateRenderer.php` — распознать `{{list.sub}}`,
  ветка repeat (cloneRowAndSetValues/deleteRow), исключить точечные токены из flat missing-check.
- Изменить `app/src/Reports/Application/Service/ReportRenderDataProjector.php` — для `ListRows` дополнительно
  отдавать `RepeatValue` под ключом блока.
- Шаблон `app/src/Reports/Infrastructure/Resources/templates/trial_application_3layer.docx` — комиссию в
  таблицу с `{{commission.*}}` (ручная правка docx после кода).
- Тесты: `tests/Unit/Shared/.../RenderDataTest`, `DocxTemplateRenderer` (functional/unit смоук с cloneRow),
  `ReportRenderDataProjectorTest` (RepeatValue), плюс docx-смоук отчёта.

## Контракт RepeatValue / RenderData

```php
final readonly class RepeatValue implements TemplateValue
{
    /** @param list<array<string,string>> $rows подключ → готовое значение */
    public function __construct(public array $rows) {}
}
```

RenderData: рядом с плоскими значениями держит группы. `get(name)` для группы вернёт RepeatValue;
добавить `repeatGroupNames(): list<string>`. Плоские и группы не пересекаются по ключам (группа = имя блока,
плоские = `block_field`; коллизии нет, т.к. у плоского всегда есть суффикс `_field`).

## Разбор и рендер (DocxTemplateRenderer)

- В `parse()`: токен вида `^([a-z0-9_]+)\.([a-z0-9_]+)$` → член группы `$1`, подключ `$2`. Не кладём в
  `values` (flat). Собираем `repeatRows: array<group, {subs: list<string>, anchor: string}>` (anchor —
  первый встреченный токен группы, полное имя `group.sub`).
- В `validate()`: точечные токены не участвуют в flat missing. Группа валидна всегда (список может быть пуст).
- В `render()`: сначала flat-заливка (как сейчас), затем по каждой группе:
  - `data.get(group)` — RepeatValue? да → `rows`. Пусто/нет → `deleteRow(anchor)`.
  - иначе `cloneRowAndSetValues(anchor, mapped)`, где `mapped[i]` = `{"group.sub" => rows[i][sub] ?? ''}` по
    ВСЕМ подключам группы (пустые подставляем '', чтобы не осталось `{{group.sub#i}}`).

## Проекция (ReportRenderDataProjector)

Для поля типа `ListRows`: помимо текущего `put(block_field, formatList(...))` — собрать
`RepeatValue` по строкам: для каждой строки `row` → `[subKey => formatSubValue(sub, row[subKey])]` по всем
`itemFields`; положить под ключ = ключ блока. Пустой список — группу не кладём (рендерер сделает deleteRow).

---

## Задачи (по шагам)

### Task 1: RepeatValue VO + RenderData
- Создать `RepeatValue` (implements `TemplateValue`).
- RenderData: принимать RepeatValue, `has()/get()` работают, добавить `repeatGroupNames()`.
- Тест: RenderData возвращает RepeatValue по ключу, `repeatGroupNames()` перечисляет группы.

### Task 2: Проектор отдаёт RepeatValue для ListRows
- В `project()` для `ListRows` дополнительно класть RepeatValue под ключ блока (не ломая flat `_items`).
- Тест `ReportRenderDataProjectorTest`: commission → RepeatValue с нужными строками/подключами; пустой список
  → группы нет; плоский `commission_items` по-прежнему есть.

### Task 3: DocxTemplateRenderer — распознавание групп
- `parse()`: точечные токены → repeatRows (group/subs/anchor), исключить из flat values.
- `validate()`: точечные токены не в missing; группы не влияют на partial.
- Тест: `variables()`/`validate()` на шаблоне с `{{commission.name}}` не помечает его missing.

### Task 4: DocxTemplateRenderer — рендер повтора
- В `render()` после flat: по группам `cloneRowAndSetValues`/`deleteRow`.
- Тест (unit-смоук с мини-docx: таблица с одной строкой `{{c.a}}|{{c.b}}`): 2 строки данных → 2 строки в
  выводе с значениями; пустой список → строки нет, шапка есть.

### Task 5: Шаблон 3layer — комиссия таблицей
- Ручная правка `trial_application_3layer.docx`: блок комиссии → таблица (столбцы по членам), строка с
  `{{commission.organization}}`, `{{commission.position}}`, `{{commission.name}}`, `{{commission.date}}`.
- Смоук рендера отчёта: комиссия из N членов → N строк.

### Task 6: Гейты
- `./run check` эквивалент: cs-fixer, phpstan (src/Reports + src/Shared), phpunit (Unit+Functional Reports,
  Shared Templating). Ассеты не трогаем.

## Тест-стратегия

- Юнит: RepeatValue/RenderData; проектор RepeatValue; парсер точечных токенов.
- Смоук docx: программный шаблон (PhpWord) с таблицей-строкой `{{c.a}}|{{c.b}}` → cloneRow работает, пусто →
  deleteRow. Гонять в контейнере (host PHP 8.5 не даёт phpword).
- Регрессия: существующие docx-смоуки зелёные (flat-путь не сломан).
