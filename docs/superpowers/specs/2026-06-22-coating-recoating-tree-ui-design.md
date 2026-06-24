# Coating Recoating Interval Tree — UI редактирования

**Дата:** 2026-06-22
**Контекст:** домен `RecoatingIntervalTree` уже реализован (3-уровневое дерево «корень → среда → основа следующего ЛКМ»), но текущая форма редактирования покрытия не умеет работать с ветками и `CoatingDTOTransformer` молча теряет их при чтении. Нужно дать пользователю полноценный UI для просмотра и редактирования дерева.

## Цель

Заменить блок «Интервал перекрытия» в существующей форме покрытия (`/cabinet/coating/coating/{id}/edit` и `/create`) на полноценный редактор 3-уровневого дерева. Поддержать flat-случай (80%) без визуального усложнения и дать инструмент для редкого случая (20%) с per-environment и per-base исключениями.

## Не входит в scope

- Read-only отображение интервалов в списке покрытий и в отдельной карточке — не делаем (явно отложено).
- Изменения других температурных серий (`dryToTouch`, `fullCure`) — не трогаем; они остаются плоскими.
- Изменения JSON-формата хранения дерева в БД (`recoating_interval_tree` DBAL type) — не нужно.

## UX

### Структура

Внутри карточки «Интервал перекрытия» — Bootstrap nav-tabs:

```
[Общее●] [Атмосферная ×] [Погружение ×]    [+ Среда ▾]
```

- Вкладка «Общее» — несъёмная, обязательная. Это корневой `default` дерева.
- Вкладки сред (`Атмосферная`/`Погружение`/`Спец среды`) появляются только когда их добавили. Удаление — крестик на самой табе, без подтверждения.
- Псевдо-таб `[+ Среда ▾]` — кнопка с dropdown, в котором перечислены только ещё не добавленные среды.

Внутри каждого таба — одна таблица «Темп. / Min / Max» (как в текущей форме). Под таблицей — кнопки `[+ Точка]`. Только для табов среды снизу — `[+ Исключение для основы ЛКМ ▾]` с dropdown ещё не добавленных оснований.

Исключение по основе — отдельный блок с заголовком `Основа: EP` и кнопкой удаления, внутри — та же таблица «Темп. / Min / Max». Дальнейших вложений нет (лист дерева).

### Симметрия min/max

Один tab-набор управляет одновременно min- и max-деревом: добавление вкладки «Атмосферная» создаёт узел в обеих сериях. Внутри каждой ноды отображается одна таблица «Темп. / Min / Max», как в текущей форме, с зеркалированием температуры в hidden-инпут max-серии (паттерн `data-action="input->coating-form#syncTemperature"` уже работает).

Пустые ячейки Max во всех строках узла трактуются как «без верхней границы» для этого узла — поведение унаследовано от текущей формы.

### Дефолты

- При создании нового покрытия: одна вкладка «Общее» с одной строкой `+20°C / Min: 0 / Max: пусто`.
- При добавлении новой среды: пустая серия с одной строкой `+20°C / Min: 0 / Max: пусто`.
- При добавлении нового основания: то же.

### Локализация

В рамках этой задачи метки сред задаются прямо в Twig как литералы (или в new helper в шаблоне):
- `atmospheric` → «Атмосферная»
- `immersion` → «Погружение»
- `special` → «Спец среды»

Подписи оснований ЛКМ — из enum `CoatingBase` (`AK/AY/ESI/EP/PUR/FEVE/PAS/PS`), отображается `value` напрямую.

Полноценная локализация (через i18n-ключи и `trans`) — не входит в scope.

## Wire-формат

Подход — вложенные `name=` атрибуты. Symfony разворачивает их в nested array, без хидденов с JSON.

Пример payload'а для одной вкладки + одного исключения:

```
minRecoatingInterval[default][points][0][temperature_at] = 20
minRecoatingInterval[default][points][0][days]           = 0
minRecoatingInterval[default][points][0][hours]          = 4
minRecoatingInterval[default][points][0][minutes]        = 0

minRecoatingInterval[branches][atmospheric][default][points][0][temperature_at] = 20
minRecoatingInterval[branches][atmospheric][default][points][0][hours]          = 3
minRecoatingInterval[branches][atmospheric][branches][ep][default][points][0][temperature_at] = 20
minRecoatingInterval[branches][atmospheric][branches][ep][default][points][0][hours]          = 2

maxRecoatingInterval[default][points][0][temperature_at] = 20
maxRecoatingInterval[default][points][0][days]           = 7
maxRecoatingInterval[default][points][0][hours]          = 0
maxRecoatingInterval[default][points][0][minutes]        = 0
```

После `$request->getPayload()->all()` это валидный nested array; никакая дополнительная сериализация на клиенте не нужна.

**Отличие от JSON-формата БД**: на форме точки хранятся в формате `days/hours/minutes`, а домен — в минутах. Конверсия в обе стороны делается в `CoatingMapper` (она там уже есть для других серий).

## Серверная часть

### Новый DTO

`app/src/Coatings/Application/DTO/Coatings/RecoatingIntervalNodeDTO.php`:

```php
final class RecoatingIntervalNodeDTO
{
    /** @var list<DryingTimePointDTO> */
    public array $default = [];

    /** @var array<string, RecoatingIntervalNodeDTO> */
    public array $branches = [];
}
```

Плоская рекурсивная структура без логики.

### CoatingDTO

Поля `minRecoatingInterval` и `maxRecoatingInterval` меняют тип:
- было: `list<DryingTimePointDTO>` (только корневой default)
- стало: `RecoatingIntervalNodeDTO`

### CoatingMapper

Два направления:

1. **`buildCoatingDtoFromInputData(array $inputData)`**:
   - Для `min`/`maxRecoatingInterval` рекурсивно строит `RecoatingIntervalNodeDTO` из nested array.
   - Существующий код для `dryToTouch`/`fullCure` остаётся плоским.
   - Если `maxRecoatingInterval` отсутствует или все его серии пустые — DTO остаётся с пустым `default` и пустыми `branches` (на следующем шаге это превратится в `null` дерево).

2. **`buildInputDataFromDto(CoatingDTO $dto)`**:
   - Обратное: из `RecoatingIntervalNodeDTO` собирает nested-array под формат шаблона. Учитывает преобразование минут в days/hours/minutes (уже есть в проекте).

### CreateCoatingCommandHandler / UpdateCoatingCommandHandler

Добавляется приватный хелпер, который рекурсивно собирает дерево и **пропускает узлы с пустым `default.points`**:

```php
/** Возвращает null, если весь узел (включая ветки) фактически пуст. */
private function buildRecoatingTree(RecoatingIntervalNodeDTO $node, ?string $key = null): ?RecoatingIntervalTree
{
    $children = [];
    foreach ($node->branches as $childKey => $childDto) {
        $childTree = $this->buildRecoatingTree($childDto, $childKey);
        if ($childTree !== null) {
            $children[] = $childTree;
        }
    }

    if ($node->default === [] && $children === []) {
        return null;
    }

    if ($node->default === []) {
        // у узла нет своих точек, но есть непустые потомки — это валидно?
        // НЕТ: дерево требует default на каждом узле. Считаем такой узел невалидным,
        // throw AppException на этапе сборки. (Если userhi нашёл такой сценарий —
        // значит он добавил вкладку, но не заполнил ни одной точки в её default-серии.)
        throw new AppException(sprintf(
            'Серия по умолчанию для узла "%s" не может быть пустой, если есть исключения.',
            $key ?? 'default',
        ));
    }

    $tree = new RecoatingIntervalTree(
        $this->buildDryingTimeSeries($node->default),
        $key ?? 'default',
    );
    foreach ($children as $childTree) {
        $tree = $tree->withChild($childTree);
    }
    return $tree;
}
```

Правила сборки:
- Если у пользователя в форме есть пустая вкладка среды/основы (default не заполнен, потомков нет) — она просто игнорируется.
- Если default пустой, но есть потомки — `AppException` (явная ошибка ввода).
- Если корневой default `min`-дерева пуст — `AppException` (мин. интервал обязателен).
- Если корневой default `max`-дерева пуст и нет потомков — `setMaxRecoatingInterval(null)` (нет верхней границы).

**Асимметрия max-tree допустима**: tabs UI всегда симметричны, но при сабмите max может оказаться плоским, если пользователь не заполнил max для конкретной ветки. find() в домене корректно отработает фоллбэк.

Доменная валидация (`CoatingRecoatingTreeValidator`) уже запускается из `Coating::setMinRecoatingInterval`/`setMaxRecoatingInterval`, ничего нового не добавляется.

### CoatingDTOTransformer

`fromEntity`:
- Вместо `pointsFromSeries($entity->getMinRecoatingInterval()->default)` — обходит дерево рекурсивно через `getChildren()` и `default`, возвращая `RecoatingIntervalNodeDTO`. То же для `max`.
- Это исправление существующего бага: сейчас исключения молча теряются при загрузке формы редактирования.

## Twig

Один новый partial `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_node.html.twig`. Параметры:

- `minNode` — узел min-дерева `{default: [...], branches: {...}}` из `buildInputDataFromDto`
- `maxNode` — параллельный узел max-дерева в той же структуре (нужен потому, что Min и Max рендерятся в одной таблице бок о бок)
- `level` — `'root' | 'env' | 'base'`
- `path` — массив ключей до текущего узла, начиная с пустого `[]` для корня (например `['atmospheric']`, `['atmospheric', 'ep']`)

Префиксы name-атрибутов для конкретного узла вычисляются в шаблоне на основании `path`:
- min: `minRecoatingInterval[default]` для корня, `minRecoatingInterval[branches][atmospheric][default]` для env, `[branches][atmospheric][branches][ep][default]` для листа.
- max: то же с заменой корня.

Логика рендера:
- Всегда — заголовок (для env и base) + таблица «Темп / Min / Max» + кнопка `[+ Точка]`.
- Если `level == 'root'`: вкладки сред + кнопка `[+ Среда ▾]` + рекурсивные `{% include %}` для каждой ветки на уровень `'env'`.
- Если `level == 'env'`: список base-блоков + кнопка `[+ Исключение для основы ЛКМ ▾]` + рекурсивные `{% include %}` для каждой ветки на уровень `'base'`.
- Если `level == 'base'`: только таблица.

В основном `form.html.twig` блок «Интервал перекрытия» (строки 289-348) заменяется на один `{% include 'admin/coating/coating/_recoating_node.html.twig' with {...} %}`.

В шаблоне также живут скрытые `<template data-template="env">` и `<template data-template="base">` — болванки для динамического добавления через JS. В них используются плейсхолдеры `__ENV__` / `__BASE__` / `__INDEX__`, которые JS заменяет на реальные значения перед вставкой.

## Stimulus

Расширяем существующий контроллер `coating-form` (`assets/controllers/coating_form_controller.js`).

Новые actions:
- `addEnv({params: {env}})` — клонирует `template[data-template="env"]`, заменяет плейсхолдеры, инсертит таб и пейн перед кнопкой `[+ Среда]`, фокусирует новую таб, скрывает выбранную среду в dropdown.
- `removeEnv({params: {env}})` — удаляет таб и пейн, возвращает среду в dropdown.
- `addBase({params: {env, base}})` — клонирует `template[data-template="base"]`, заменяет плейсхолдеры, инсертит блок внутрь активного env-пейна.
- `removeBase({params: {env, base}})` — удаляет блок основания.

Существующие actions (`addRow`, `removeRow`, `syncTemperature`, `saveDuration`, `clearDuration`, `calculateDuration`) переиспользуются. Им нужно передавать корректный `data-series` через `data-params` или вычислять prefix относительно ближайшего родителя.

Индексы точек внутри одной серии перенумеровываются при добавлении/удалении — оставляем существующий механизм (он уже работает для flat-серий).

## Валидация

- **Клиент**: только HTML5 (`required`, `min`, `max`, `type="number"`). Никакой кастомной JS-валидации.
- **Сервер**:
  - Существующая `Validator::validate($inputData, $this->coatingMapper->getValidationCollectionCoating())` проверяет плоские поля (title, dftRange и т.п.) — не меняется.
  - Для дерева валидация делается через типы DTO и `DryingTimeSeries::__construct` (проверка дубликатов температуры, физического правила).
  - `CoatingRecoatingTreeValidator` в сеттерах `Coating` проверяет: ключ корня = `'default'`, ключи сред — из enum `EnvironmentType`, ключи оснований — из enum `CoatingBase`, листья оснований без потомков.
- **UX ошибок**: `addFlash` или `compact('error')` в `render` формы — как сейчас. Подсветки конкретного поля нет, выводится только сообщение поверх формы.

## Тестирование

- `CoatingMapperTest` (создаём, если нет): unit-тест на раунд-трип nested-array → `CoatingDTO` → nested-array. Покрыть случаи: чистый default, default + одна среда, default + среда + основа.
- `CoatingDTOTransformerTest`: при наличии исключений в дереве `fromEntity` возвращает корректное вложенное DTO (защита от регрессии текущего бага).
- Доменные тесты (`CoatingTest`, `RecoatingIntervalTreeTest`) уже покрывают саму структуру дерева.
- Functional/integration: один happy-path тест на `UpdateAction` — POST с веткой `atmospheric/ep`, проверка что `Coating::minRecoatingFor(Atmospheric, EP)` возвращает leaf-серию из формы.

## Затрагиваемые файлы

**Меняются:**
- `app/src/Coatings/Application/DTO/Coatings/CoatingDTO.php`
- `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php`
- `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php`
- `app/src/Coatings/Application/UseCase/Command/CreateCoating/CreateCoatingCommandHandler.php`
- `app/src/Coatings/Application/UseCase/Command/UpdateCoating/UpdateCoatingCommandHandler.php`
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig`
- `assets/controllers/coating_form_controller.js` (точное имя файла уточняется при имплементации — он обозначен как `data-controller="coating-form"` в форме)

**Создаются:**
- `app/src/Coatings/Application/DTO/Coatings/RecoatingIntervalNodeDTO.php`
- `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_recoating_node.html.twig`
- Тесты: `CoatingMapperTest` (если ещё нет), `CoatingDTOTransformerTest` (если ещё нет), functional-тест на `UpdateAction`.
