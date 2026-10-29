# Inquiries, план 7: эталон и градусник точности (робот+человек в одной оси)

Дата: 2026-10-29. Шаг 1 роадмапа `inquiries-accuracy-roadmap.md`. Без инфраструктуры (ни миграций, ни эмбеддингов, ни сайдкара). Следующий шаг — `inquiries-8-dynamic-examples.md` (динамические примеры на эмбеддингах), он опирается на эту форму данных.

## Зачем

Нельзя улучшать то, что не меряешь. Нужна приборная панель: по каждой оси подбора — насколько ответ модели совпал с тем, что в итоге одобрил инженер. Это страхует от регресса при правке промпта (п.3/п.4 роадмапа) и показывает слабые оси.

Решение (по итогу обсуждения): **не заводим отдельную таблицу эталона и НЕ перегоняем модель повторно.** Эталон копится прямо в запросе: при ручной правке инженером значение робота не затирается, а сохраняется рядом. Градусник — чтение+агрегация по одобренным запросам, ноль вызовов модели.

## Опора (что уже есть)

- `RecognizedParameters` — непрозрачный key-value + провенанс оси (`origin`: Model/Cache/Manual), `toArray()` — плоский вход `CoatingSystemsFilterInput`. Сейчас при ручной правке значение робота **затирается** (только origin флипается). Это и меняем.
- `ParameterStrategy` (Model/Cache/Manual). `Inquiry::editParameters()` зовёт `withManualEdits()`. `Approved` — терминальный статус.
- Форма входа фильтра — плоские примитивы: `string | int | bool | list<string> | null`, без вложенности (диапазоны расплющены в `*From`/`*To`).

## Форма данных (решено)

Одна ось = один объект `RecognizedValue`. `value` — натуральный примитив (не стрингуем), тип точный через алиас, НЕ `mixed`. Поэлементный провенанс списков не делаем (стринги/репарсинг/потеря удалений не окупаются — ось-уровневого хватит).

### Новый VO `RecognizedValue` (Domain/Aggregate/Inquiry)

```php
/** @phpstan-type RecognizedScalar = string|int|bool|list<string>|null */
final readonly class RecognizedValue
{
    private function __construct(
        public mixed $value,              // @phpstan RecognizedScalar — эффективное (показываем/ищем)
        public ParameterStrategy $origin, // кто дал эффективное
        public mixed $robotValue,         // @phpstan RecognizedScalar — что выдал робот изначально
    ) {}

    public static function recognized(mixed $value, ParameterStrategy $origin = ParameterStrategy::Model): self;
    public function editedByHuman(mixed $value): self; // origin=Manual, robotValue тащится прежний (робот не теряется и при повторной правке)
    public function wasEdited(): bool;                 // origin === Manual
}
```

Докблок на `value`/`robotValue`: «непрозрачный примитив фильтра; типами владеет `CoatingSystemsFilterInput`, здесь не трактуем».

### Рефактор `RecognizedParameters`

- Хранит `array<string, RecognizedValue>` вместо трёх параллельных карт.
- Именованный конструктор `recognized(array $values, ParameterStrategy $origin = Model)` — все оси от робота/кэша.
- `toArray()` — `array_map(fn($a) => $a->value)` (эффективные значения; вход поиска НЕ меняется).
- `originOf($key)`, `manuallyEditedKeys()` — через `RecognizedValue` (IGNORED_AXES фильтруется только в `manuallyEditedKeys`).
- `withManualEdits(array $newValues)` — не менялась → тот же `RecognizedValue`; перекрыли → `editedByHuman()` (робот внутри сохранён); ось, которой у робота не было → `recognized(null)->editedByHuman($value)`.
- НОВОЕ `corrections(): array<string, array{robot: mixed, human: mixed}>` — пары по правленым осям (топливо градусника).
- `jsonSerialize()` — `{value, origin}` + `robotValue` ТОЛЬКО если `wasEdited()`.
- `fromArray()` — толерантен: `Manual` → `recognized(robotValue)->editedByHuman(value)`; иначе `recognized(value, origin)`; старая плоская форма → `recognized(value, Model)`.
- **Миграции нет** — едет в том же jsonb; старые записи без `robotValue` = «не правили».

### Правка мест вызова

- `SystemSelectionProcessor`: `new RecognizedParameters($mapped->input->toArray())` → `RecognizedParameters::recognized(...)` (Model).
- Кэш-хит (когда появится) и тесты `CacheHit` → `RecognizedParameters::recognized($flat, ParameterStrategy::Cache)`.

## Градусник (Application + Console)

- `ExtractionEvalScorer` (Application/Service) — принимает одобренные `RecognizedParameters`, агрегирует по осям:
  - **совпало** — ось не правили (`!wasEdited`);
  - из `corrections()`: **пропустила** (robot пусто/null, human задан), **лишнее** (robot задан, human пусто), **разошлись** (оба заданы, различаются).
  - Выход: по оси `{всего, совпало, пропустила, лишнее, разошлись, точность%}` + итог.
- Загрузка одобренных — репозиторий `findByFilter(status=Approved)` (с `--limit`/`--type`).
- Консольная команда `Inquiries/Infrastructure/Console/` (напр. `inquiry:extraction-accuracy`) — зовёт query/scorer, печатает таблицу. Флаг `--json` — дамп пар «разошлись» по осям для разбора. Read-only, модель НЕ зовёт.

## Развилки (решены)

- **Форма значения** — один `RecognizedValue` на ось, точный union, без стрингования. (решено)
- **Без отдельной таблицы, без повторного прогона** — эталон в запросе, градусник читает сохранённого робота vs человека. (решено)
- **«разошлись» строго** — любое различие (массивы как множества). Температуры ±дельта — не сейчас (future, если шумит).
- **Вывод** — таблица в консоль + опц. `--json`. Историю точности по версиям промпта в файл/БД — не сейчас.

## Не входит

- Эмбеддинги / динамические примеры (план 8).
- Измерение **нестабильности** модели (прогон одного текста N раз) — требует перегона модели, относится к п.4 детерминизма, отдельно.
- Семантический кеш (план 5) — на плато точности.

## Тесты

- Unit `RecognizedValueTest`: `recognized`/`editedByHuman`/`wasEdited`; робот сохраняется при первой и повторной правке; `recognized(null)->editedByHuman()` даёт robotValue=null.
- Unit `RecognizedParametersTest` (+ существующий `ValueObjectRoundTripTest`): `toArray` отдаёт эффективные; `withManualEdits` не теряет робота и не трогает неправленые оси; `corrections()` — верные пары; `jsonSerialize`/`fromArray` round-trip с `robotValue`; толерантность к старой плоской форме; `recognized(..., Cache)`.
- Unit `ExtractionEvalScorerTest`: классификация совпало/пропустила/лишнее/разошлись по осям на собранных `RecognizedParameters`.
- Functional (по желанию): команда на нескольких Approved из БД даёт непустую таблицу.

## Порядок

1. `RecognizedValue` + рефактор `RecognizedParameters` + правка мест вызова + юнит-тесты (зелёный `./run check`).
2. `ExtractionEvalScorer` + query на Approved + консольная команда + тесты.
3. Прогнать команду на реальных одобренных (после накопления) — увидеть слабые оси; дальше роадмап п.4 (детерминизм) и п.2 (примеры, план 8).
