# Coating: контекстно-зависимый recoating interval

Дата: 2026-06-16
Ветка: `refactor/coating-vo`

## Контекст

Сейчас в `Coating` интервалы перекрытия моделируются как серии температура → минуты (`DryingTimeSeries`):

```php
DryingTimeSeries  $minRecoatingInterval,   // обязательно
?DryingTimeSeries $maxRecoatingInterval,   // null допустим
```

В реальности, по техкартам производителей, оба интервала зависят ещё от двух факторов:

- **тип среды эксплуатации** (атмосфера / погружение / специальные условия);
- **тип покрывающего слоя** — `CoatingBase` нанесённого сверху покрытия (EP над EP, EP над PUR и т. д.).

Производители заполняют эти комбинации фрагментарно. Большинство покрытий — только один глобальный интервал; уточнения по конкретной паре среда × топкоат встречаются у меньшинства.

Решение должно:

1. Хранить дерево уточнений (среда → топкоат → серия) для каждого покрытия.
2. Давать предсказуемый fallback на дефолт ближайшего родителя, когда конкретная комбинация в TDS не указана.
3. Расширяться вглубь без переделки самой структуры (готовность к появлению уровня «конкретная категория ISO 12944» или подвида среды).
4. Сохранять для администратора прежний путь ввода: при отсутствии уточнений форма редактирования покрытия выглядит как сейчас.
5. Защищать прикладной код от ошибок: невозможно подать строку вместо ключа, перепутать тип ключа или порядок уровней.

В этом спеке готовится только сам класс `Coating` (модель данных, контракт чтения, миграция). Расчёт систем покрытий и поправка времён по фактической толщине (`actualDft / tdsDft`) — отдельные задачи на потом.

## Решение

Ввести рекурсивный value object `RecoatingIntervalTree` — composite-узел с обязательным `default` и опциональными `children` по строковым ключам. Заменить им поля `*RecoatingInterval` в `Coating`. Семантика «у максимального интервала вообще нет ограничения» вынесена на уровень поля `Coating::$maxRecoatingInterval` (тип `?RecoatingIntervalTree`, `null` = unrestricted).

Сам узел — просто структура: хранит данные и сериализуется. Никаких step-классов, fluent-цепочек, валидации типа ключей внутри узла, специальных конструкторов или builder-API. Это сделано осознанно, по результатам обзора устоявшихся практик (Symfony Config, Akeneo, dunglas/doctrine-json-odm, Noback): тонкий composite-класс — самый близкий к реальной индустрии путь для embedded JSONB-VO с fallback.

Поиск с fallback живёт в `Coating` — как приватный helper плюс публичные типизированные shortcut-методы. Сигнатуры shortcut-методов фиксируют тип и порядок параметров — на этом уровне прикладной код получает полную compile-time защиту: невозможно перепутать `EnvironmentType` и `CoatingBase`, невозможно вызвать без какого-то аргумента, IDE/PHPStan ловят опечатки в case'ах.

Уровни сейчас: `EnvironmentType → CoatingBase`. Когда появятся «подкатегория ISO 12944» (C2…CX, Im1…Im4) или «подвид среды» (hi-temp, химическое воздействие) — добавится **ещё один shortcut-метод** в `Coating` с дополнительным параметром, и helper на один уровень глубже. Структура самого `RecoatingIntervalTree` не меняется, миграции данных не требуется.

## Компоненты

### `EnvironmentType` (enum)

`app/src/Coatings/Domain/Aggregate/Coating/EnvironmentType.php`

```php
enum EnvironmentType: string
{
    case Atmospheric = 'atmospheric';
    case Immersion   = 'immersion';
    case Special     = 'special';
}
```

Три значения сейчас — этого достаточно для всей текущей детализации. Подразделение `special` (hi-temp, химическое воздействие, контакт с питьевой водой и т. п.) добавится в будущем как ещё один уровень детей в дереве, без расширения этого enum.

### `RecoatingIntervalTree` (узел дерева)

`app/src/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTree.php`

```php
final class RecoatingIntervalTree implements \JsonSerializable
{
    /** @var array<string, RecoatingIntervalTree> */
    public readonly array $children;

    public function __construct(
        public readonly DryingTimeSeries $default,
        array $children = [],
    ) {
        foreach ($children as $key => $child) {
            if (!is_string($key) || $key === '' || !$child instanceof self) {
                throw new \InvalidArgumentException('RecoatingIntervalTree: bad child entry');
            }
        }
        $this->children = $children;
    }

    public function jsonSerialize(): array
    {
        return [
            'default'  => $this->default,
            'children' => array_map(static fn(self $c) => $c->jsonSerialize(), $this->children),
        ];
    }

    public static function fromArray(array $raw): self
    {
        $children = [];
        foreach ($raw['children'] ?? [] as $key => $childRaw) {
            $children[(string) $key] = self::fromArray($childRaw);
        }
        return new self(DryingTimeSeries::fromArray($raw['default']), $children);
    }
}
```

Класс знает только про структуру: обязательный `default`, строковые ключи `children`, рекурсивная сериализация. Доменное значение ключей (что на корне ключи — это `EnvironmentType`, а на втором уровне — `CoatingBase`) живёт у потребителя — в `Coating` shortcut-методах. Это симметрично с тем, как сериализуется ассоциативный массив: тип ключа становится известен только в момент его использования.

Хелпера `DryingTimeSeries::fromArray()` сейчас нет — парсинг точек живёт прямо в `DryingTimeSeriesType::convertToPHPValue()`. В рамках этого изменения логика извлекается в `DryingTimeSeries::fromArray(array $rows): self`, существующий DBAL Type начинает её использовать, и `RecoatingIntervalTree::fromArray()` переиспользует то же самое. Формат входа сохраняется текущим — плоский массив точек: `[{temperature_at, time_in_minutes, is_calculated}, ...]`.

### Поля `Coating` (Domain/Aggregate/Coating/Coating.php)

До:

```php
DryingTimeSeries  $minRecoatingInterval,
?DryingTimeSeries $maxRecoatingInterval,
```

После:

```php
RecoatingIntervalTree  $minRecoatingInterval,
?RecoatingIntervalTree $maxRecoatingInterval,
```

`min`-поле обязательно — у каждого покрытия должен быть хотя бы глобальный минимум. `max`-поле опционально — `null` означает «производитель явно не ограничивает максимум, либо ограничение неактуально».

### Shortcut-методы и helper на `Coating`

Точка входа прикладного кода:

```php
public function minRecoatingFor(EnvironmentType $env, CoatingBase $topcoat): DryingTimeSeries
{
    return $this->descendRecoating($this->minRecoatingInterval, $env, $topcoat);
}

public function maxRecoatingFor(EnvironmentType $env, CoatingBase $topcoat): ?DryingTimeSeries
{
    return $this->maxRecoatingInterval === null
        ? null
        : $this->descendRecoating($this->maxRecoatingInterval, $env, $topcoat);
}

public function minRecoatingPointAt(EnvironmentType $env, CoatingBase $topcoat, int $temperature): ?TimeAtTemperature
{
    return $this->minRecoatingFor($env, $topcoat)->getPoint($temperature);
}

public function maxRecoatingPointAt(EnvironmentType $env, CoatingBase $topcoat, int $temperature): ?TimeAtTemperature
{
    return $this->maxRecoatingFor($env, $topcoat)?->getPoint($temperature);
}

private function descendRecoating(
    RecoatingIntervalTree $tree,
    EnvironmentType $env,
    CoatingBase $topcoat,
): DryingTimeSeries {
    $envNode     = $tree->children[$env->value] ?? null;
    $topcoatNode = $envNode?->children[$topcoat->value] ?? null;

    return $topcoatNode?->default
        ?? $envNode?->default
        ?? $tree->default;
}
```

Метод `getPoint($temperature)` остаётся без изменений и сам решает: точное попадание, интерполяция между соседними точками или `null` для запроса вне диапазона температур серии.

### Защита от ошибок

| Ошибка | Где ловится |
|---|---|
| `maxRecoatingFor('atmospheric', ...)` (строка вместо enum) | type system на параметре `EnvironmentType` |
| `maxRecoatingFor(CoatingBase::EP, EnvironmentType::Atmospheric)` (перепутали порядок) | type system на сигнатуре shortcut'а |
| Опечатка в `EnvironmentType::Atmospheri` в коде | IDE / PHPStan |
| Битый ключ в JSONB-данных | приходит как `null` в `$envNode` / `$topcoatNode` → fallback каскад вверх. Тихий fallback допустим: данные в БД пишутся через `jsonSerialize()` того же объекта; перевод приходит из формы или фикстур, валидируется на стороне ввода. |
| Невалидная структура в конструкторе (`children` с числовым ключом или не-`self`) | `__construct` бросает `InvalidArgumentException` |

Compile-time-защита прикладного кода — на уровне `Coating::*RecoatingFor()`. Дерево остаётся generic структурой; его контракт — структурная целостность, а не доменная типизация ключей.

### DBAL Type

`app/src/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeType.php`

По образцу существующего `DryingTimeSeriesType`. Колонка PostgreSQL — `JSONB`.

```php
public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
{
    if ($value === null) return null;
    if (!$value instanceof RecoatingIntervalTree) throw new \InvalidArgumentException(...);
    return parent::convertToDatabaseValue($value->jsonSerialize(), $platform);
}

public function convertToPHPValue($value, AbstractPlatform $platform): ?RecoatingIntervalTree
{
    if ($value === null) return null;
    $raw = parent::convertToPHPValue($value, $platform);
    if (!is_array($raw)) throw new \UnexpectedValueException(...);
    return RecoatingIntervalTree::fromArray($raw);
}
```

Регистрация типа `recoating_interval_tree` в `config/packages/doctrine.yaml` рядом с `drying_time_series`.

Маппинг полей `Coating`:
- `min_recoating_interval`: тип `recoating_interval_tree`, NOT NULL;
- `max_recoating_interval`: тип `recoating_interval_tree`, NULLABLE.

## Сценарии поиска

Пример заполненного дерева для одного покрытия (`maxRecoatingInterval`):

```
root.default = 14 дней
└─ children:
    ├─ "atmospheric"
    │   default = 7 дней
    │   children:
    │     ├─ "EP"  → default = 30 дней (children: [])
    │     └─ "PUR" → default = 3 дня   (children: [])
    └─ "immersion"
        default = 24 часа
        children: []
```

Сборка такого дерева в коде:

```php
$maxTree = new RecoatingIntervalTree(
    default: $globalDefault,
    children: [
        EnvironmentType::Atmospheric->value => new RecoatingIntervalTree(
            default: $atmDefault,
            children: [
                CoatingBase::EP->value  => new RecoatingIntervalTree($epSeries),
                CoatingBase::PUR->value => new RecoatingIntervalTree($purSeries),
            ],
        ),
        EnvironmentType::Immersion->value => new RecoatingIntervalTree($immDefault),
    ],
);
```

Trace `descendRecoating` на разных запросах:

| Запрос | `$envNode` | `$topcoatNode` | Результат |
|---|---|---|---|
| `Atmospheric, PUR` | atmosphericNode | purNode | `purNode->default` = **3 дня** |
| `Atmospheric, FEVE` | atmosphericNode | `null` (FEVE нет) | `atmosphericNode->default` = **7 дней** |
| `Immersion, EP` | immersionNode | `null` (children пуст) | `immersionNode->default` = **24 часа** |
| `Special, EP` | `null` (special нет) | `null` | `tree->default` = **14 дней** |
| `Atmospheric, PUR` при `maxRecoatingInterval === null` | — | — | **`null`** (ранний выход, семантика unrestricted) |

Шаг доступа к `children` не выбрасывает ошибку, если ключа нет — он даёт `null`, и каскад `??` поднимается на ближайший заполненный default. Это и есть физический смысл «производитель не уточнил эту комбинацию, берём более общее значение».

## Миграция данных

Текущие колонки `min_recoating_interval` и `max_recoating_interval` уже JSONB и хранят сериализованный `DryingTimeSeries` как плоский массив точек: `[{"temperature_at":20,"time_in_minutes":14400,"is_calculated":false}, ...]`. Превращаем содержимое в дерево с единственным глобальным default и пустыми children:

```sql
UPDATE coatings
SET
  min_recoating_interval = jsonb_build_object(
    'default',  min_recoating_interval,
    'children', '{}'::jsonb
  ),
  max_recoating_interval = CASE
    WHEN max_recoating_interval IS NULL THEN NULL
    ELSE jsonb_build_object(
      'default',  max_recoating_interval,
      'children', '{}'::jsonb
    )
  END;
```

Внутри узла поле `default` содержит ровно тот же плоский массив точек, что лежал в колонке до миграции, — формат `DryingTimeSeries` не меняется.

Поведение неотличимо от текущего: любой `maxRecoatingFor(...)` падает в `tree->default`. Overrides появляются по мере поступления данных из конкретных TDS.

Миграция в одну транзакцию, обратимая зеркальным SQL (распаковка `default` обратно в корень).

## Что НЕ делается в этом спеке

- Поправка времён по фактической DFT (`actualDft / tdsDft`). Линейное масштабирование применяется к `dryToTouch`, `fullCure`, `minRecoatingInterval`; к `maxRecoatingInterval` — нет. Это часть будущего расчёта `CoatingSystem`.
- Уровень «конкретная категория ISO 12944» (C1…CX, Im1…Im4) внутри `EnvironmentType`. Добавится ещё одним shortcut-методом на `Coating` и одной дополнительной строкой каскада в helper.
- Подразделение `special` (hi-temp, химическое воздействие, питьевая вода и т. п.) — аналогично, добавится новым shortcut-методом.
- UI редактирования overrides. На этом этапе форма редактирования покрытия по-прежнему показывает один блок ввода серии — он соответствует `default` корневого узла. Ввод детальных уточнений делается через сидинг/команды/тесты, пока не появится первый реальный кейс. UI с прогрессивным аккордеоном (`CollectionType` + Symfony UX) — отдельный спек.
- API сборки `CoatingSystem`, поиск совместимых покрытий по среде, сравнение покрытий по recoating-таблицам — отдельные задачи, использующие готовое `Coating::maxRecoatingFor/minRecoatingFor`.

## Расширение в будущем

Добавление уровня «конкретная категория ISO 12944» (например, `Atmospheric → C5 → EP`):

1. `RecoatingIntervalTree` не меняется. Узел `atmospheric` получает в `children` ещё один уровень — `'c5' → RecoatingIntervalTree(default, children: { 'EP': ... })`. Сериализация в JSONB остаётся той же формы.
2. В `Coating` появляется новый shortcut-метод с дополнительным параметром:
    ```php
    public function maxRecoatingForCategory(
        EnvironmentType $env,
        CoatingSystemCorrosiveCategory $cat,
        CoatingBase $topcoat,
    ): ?DryingTimeSeries
    {
        if ($this->maxRecoatingInterval === null) return null;
        return $this->descendRecoatingByCategory($this->maxRecoatingInterval, $env, $cat, $topcoat);
    }

    private function descendRecoatingByCategory(
        RecoatingIntervalTree $tree,
        EnvironmentType $env,
        CoatingSystemCorrosiveCategory $cat,
        CoatingBase $topcoat,
    ): DryingTimeSeries {
        $envNode     = $tree->children[$env->value] ?? null;
        $catNode     = $envNode?->children[$cat->value] ?? null;
        $topcoatNode = $catNode?->children[$topcoat->value] ?? null;

        return $topcoatNode?->default
            ?? $catNode?->default
            ?? $envNode?->default
            ?? $tree->default;
    }
    ```
3. Старые покрытия без категории остаются валидными: цепочка `maxRecoatingFor($env, $topcoat)` продолжает работать как раньше — дерево такого покрытия не имеет уровня категории, helper не запросит её.
4. Прикладной код выбирает, какой shortcut звать в зависимости от того, какой контекст у него есть (известна ли категория).

Сильная сторона выбранной структуры: разные ветки дерева могут иметь разную глубину. Atmospheric может содержать категории, Immersion — нет, без какой-либо рассинхронизации в структуре или в потребителе.

## Тесты

| Файл | Покрытие |
|---|---|
| `tests/Unit/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTreeTest.php` | конструктор (валидация ключей и типа значений); пустые children; вложенное дерево; `jsonSerialize()` + `fromArray()` round-trip; повреждённый JSON через `fromArray` бросает ясную ошибку |
| `tests/Unit/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeTypeTest.php` | DBAL roundtrip JSONB ↔ объект (включая NULL для max-колонки) |
| `tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingBaseTest.php` | расширение: `minRecoatingFor`, `maxRecoatingFor`, `*PointAt` на покрытии с пустыми children, с overrides только по среде, с overrides по топкоату; правильный fallback каскад |
| `tests/Unit/Coatings/Domain/Aggregate/Coating/DryingTimeSeriesTest.php` | дополнить тестом `fromArray()` (обратная сериализация плоского массива точек) |

Существующие тесты `DryingTimeSeriesTest`, `CoatingBaseTest` не должны падать от изменений в типах полей: задача переходного этапа — собрать дерево из единственного default и проверить, что shortcut-методы возвращают ту же серию.

## Сводный список файлов

Новые:
- `app/src/Coatings/Domain/Aggregate/Coating/EnvironmentType.php`
- `app/src/Coatings/Domain/Aggregate/Coating/RecoatingIntervalTree.php`
- `app/src/Coatings/Infrastructure/Database/DBAL/RecoatingIntervalTreeType.php`
- Doctrine migration в `app/migrations/`

Изменяемые:
- `app/src/Coatings/Domain/Aggregate/Coating/Coating.php` — типы полей, четыре shortcut-метода, приватный helper `descendRecoating`.
- `app/src/Coatings/Domain/Aggregate/Coating/DryingTimeSeries.php` — добавить `public static function fromArray(array $raw): self`.
- `app/src/Coatings/Infrastructure/Database/DBAL/DryingTimeSeriesType.php` — переключить парсинг на `DryingTimeSeries::fromArray()`.
- `app/src/Coatings/Application/UseCase/Command/*Coating/*Handler.php` — собирать `RecoatingIntervalTree` из входных данных (на старте — единственный default).
- `app/src/Coatings/Application/DTO/Coatings/CoatingDTOTransformer.php` — отдавать дерево в DTO (на старте — соответствует прежнему формату `DryingTimeSeries` при пустых children).
- `app/src/Coatings/Infrastructure/Mapper/CoatingMapper.php` — построение `RecoatingIntervalTree` из input-массива.
- `app/config/packages/doctrine.yaml` — регистрация типа `recoating_interval_tree`.

Тестовые (новые / расширяемые) — перечислены выше.
