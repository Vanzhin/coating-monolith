# CoatingSystem — дизайн

Дата: 2026-07-26
Статус: спека одобрена, план реализации отдельно.

## Цель

Переписать текущий черновик `Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php`
в полноценный агрегат «система покрытий» — упорядоченный набор `Coating` с
метаданными на слой, инвариантами совместимости и вычисляемым соответствием
категориям коррозионной активности по ГОСТ 34667.5—2021 (ISO 12944-5:2019).

Итоговые сценарии:
- Автор составляет систему из существующих `Coating` в строгом порядке.
- Система знает, каким парам `(категория, срок службы)` она удовлетворяет —
  считается на лету из свойств системы + правил ГОСТ, кэшируется в отдельном
  индексе для быстрого поиска.
- Поиск: «дай мне все системы под `C3` со сроком `High`» → JOIN на индекс.

## Границы

- Живёт в существующем bounded context `Coatings/`. Новый bounded context не
  создаём.
- Внешние связи (Documents, Proposals) в этой итерации не трогаем — см. Backlog.

## Структура файлов

```
app/src/Coatings/
├── Domain/Aggregate/CoatingSystem/
│   ├── CoatingSystem.php               — корень агрегата (переписывается полностью)
│   ├── CoatingSystemLayer.php          — child-entity
│   ├── Substrate.php                   — enum типа подложки
│   ├── SurfacePreparation.php          — VO {grade, description, ?standard}
│   ├── ComplianceStandard.php          — enum ISO_12944 (и будущие стандарты)
│   ├── PrimerType.php                  — enum ZINC_RICH/OTHER
│   ├── Iso12944/
│   │   ├── IsoCorrosivityCategory.php  — enum C2..CX, IM1..IM3
│   │   └── IsoDurability.php           — enum LOW/MEDIUM/HIGH/VERY_HIGH
│   ├── ComplianceRule.php              — value object одного правила (обобщённый)
│   ├── ComplianceRuleBook.php          — const-справочник правил всех стандартов
│   ├── ComplianceEvaluator.php         — доменный сервис: система → list<match>
│   └── CoatingSystemChainValidator.php — доменный сервис: совместимость соседей
├── Domain/Repository/
│   └── CoatingSystemRepositoryInterface.php
├── Application/UseCase/
│   ├── Command/CreateCoatingSystem/…
│   ├── Command/UpdateCoatingSystemMetadata/…
│   ├── Command/RemoveCoatingSystem/…
│   ├── Command/AppendLayer/…
│   ├── Command/InsertLayerAt/…
│   ├── Command/RemoveLayerAt/…
│   ├── Command/MoveLayer/…
│   ├── Command/UpdateLayerDft/…
│   └── Query/{ListCoatingSystems,FindCoatingSystemById,SearchCoatingSystemsByCompliance}/…
├── Application/DTO/CoatingSystems/…
├── Infrastructure/Database/ORM/Aggregate/
│   ├── CoatingSystem.CoatingSystem.orm.xml
│   └── CoatingSystem.CoatingSystemLayer.orm.xml
├── Infrastructure/Database/DBAL/
│   └── SurfacePreparationType.php      — JSON DBAL type
├── Infrastructure/Repository/CoatingSystemRepository.php
├── Infrastructure/Projector/CoatingSystemComplianceProjector.php
│                                       — Doctrine listener, поддерживает индекс
├── Infrastructure/Controller/CoatingSystem/
│   ├── AddAction.php
│   ├── UpdateAction.php
│   ├── ListAction.php
│   ├── ViewAction.php
│   ├── RemoveAction.php
│   └── SearchByComplianceAction.php
├── Infrastructure/Api/CoatingSystem/
│   └── SearchByComplianceApiAction.php
└── Infrastructure/Mapper/CoatingSystemMapper.php
```

Удаляются как устаревшие черновики:
- `CoatingSystemSurface.php` (enum PFP/PROTECTIVE/MARINE/SPECIAL — концепт
  «назначение» не переносим).
- `CoatingSystemSurfaceTreatment.php` (enum Sa3/Sa2.5/… — заменяется свободным
  VO `SurfacePreparation`).

## Модель данных

### `CoatingSystem` (корень)

| Поле | Тип | Инвариант |
|---|---|---|
| `id` | `Uuid` | readonly |
| `title` | `string` | 1..100 символов, НЕ уникален |
| `description` | `string` | 0..2000 |
| `substrate` | `Substrate` (enum) | одно значение |
| `surfacePreparation` | `SurfacePreparation` (VO) | см. ниже |
| `layers` | `Collection<CoatingSystemLayer>` | ≥1; positions=1..N без дыр; каждая соседняя пара совместима |
| `createdAt` | `\DateTimeImmutable` | set в конструкторе |
| `updatedAt` | `\DateTimeImmutable` | set в конструкторе и в каждом сеттере/мутаторе слоёв |

Все поля кроме `layers` меняются через сеттеры с валидацией. Слои — только
через 5 методов: `appendLayer`, `insertLayerAt`, `removeLayerAt`, `moveLayer`,
`updateLayerDft`. Никакого доступа к `getLayers()->add()` снаружи.

Read-side helpers (используются доменным сервисом `IsoComplianceEvaluator`):
- `getLayers(): Collection<CoatingSystemLayer>` — отсортированная коллекция
  (Doctrine `#[OrderBy(['position' => 'ASC'])]`).
- `firstLayer(): CoatingSystemLayer` — эквивалент `getLayers()->first()`.
- `followupLayers(): iterable<CoatingSystemLayer>` — все слои кроме первого.

### `CoatingSystemLayer` (child-entity)

| Поле | Тип | Инвариант |
|---|---|---|
| `id` | `Uuid` | readonly |
| `system` | `CoatingSystem` | back-reference |
| `coating` | `Coating` | обязательно |
| `position` | `int` | 1..N, unique в рамках `system` |
| `dft` | `int` (мкм) | `coating.dftRange->contains(dft)` |

Один и тот же `Coating` может встречаться в системе несколько раз (например,
грунт + грунт + финиш).

### `Substrate` (enum)

```
STEEL_CARBON       — углеродистая сталь
STEEL_GALVANIZED   — оцинкованная (горячее)
STEEL_METALLIZED   — с термически напылённым металлом
CONCRETE           — бетон
ALUMINUM           — алюминий
```

Начальный набор; расширяется добавлением новых case.

### `SurfacePreparation` (VO, `final readonly`)

```php
public function __construct(
    public string $grade,            // "Sa 2 1/2", "Wa 2", "St 3", "Fl"
    public string $description,      // свободное описание
    public ?string $standard = null, // "ИСО 8501-1", "ИСО 8501-4", null если стандарт не указан
) {}
```

Валидация в конструкторе:
- `grade`: не пустой, ≤ 30.
- `description`: 0..500.
- `standard`: null или не пустой ≤ 50.

Структуру «допустимо ли grade для substrate» проект НЕ валидирует —
ответственность на авторе. Всё что нужно бизнесу — записать факт подготовки
как строку и, при необходимости, сослаться на стандарт.

### Мутирующие методы `CoatingSystem`

- `appendLayer(Coating $coating, int $dft): CoatingSystemLayer`
- `insertLayerAt(int $position, Coating $coating, int $dft): CoatingSystemLayer`
- `removeLayerAt(int $position): void`
- `moveLayer(int $fromPosition, int $toPosition): void`
- `updateLayerDft(int $position, int $dft): void`

После каждой мутации:
1. `assertPositionsAreDense()` — positions = 1..N.
2. `CoatingSystemChainValidator::validate($this)` — для каждой пары
   `(layers[i], layers[i+1])` бросает `AppException`, если
   `layers[i].coating.canBecoveredBy(layers[i+1].coating) === false`.
3. `updatedAt = new \DateTimeImmutable()`.
4. Domain event `CoatingSystemLayersChanged` — триггер для projector-а.

### Что меняется в `Coating`

Добавляется одно поле:
- `bool $isZincRich = false` — цинкнаполненная грунтовка (≥ 80 % Zn). Влияет
  только на расчёт соответствия ГОСТ; в остальной логике `Coating` ничего не
  ломает.

Миграция: `ALTER TABLE coating ADD COLUMN is_zinc_rich BOOLEAN NOT NULL DEFAULT FALSE`.
Форма редактирования Coating получает чекбокс.

## Механика расчёта соответствия

Одна система может удовлетворять правилам **разных стандартов** одновременно
(ISO 12944, ГОСТ 51164, SSPC-PA и т.п.). Индекс соответствия хранит по одной
строке на каждое подтверждённое соответствие, ключ — `(system_id, standard,
category, durability)`. Категории и сроки хранятся как строки (`VARCHAR`),
потому что у разных стандартов свои enum-ы; внутри доменного слоя каждый
стандарт использует свою типизированную номенклатуру.

Сейчас поддерживается один стандарт — **ISO 12944** (ГОСТ 34667.5—2021).
Добавление второго стандарта = новый enum категорий/сроков + новые записи в
`ComplianceRuleBook` с `standard: NEW_STANDARD`. Модель `ComplianceRule` не
меняется, пока структура правила у нового стандарта совпадает (среда × срок ×
требования к слоям). Иначе — переход в полноценный полиморфизм (см. Backlog).

### Enum-ы

Каждый enum со строковым value + методами `title(): string` (короткое
обозначение) и `description(): string` (человекочитаемое описание) — по
образцу существующего `CoatingBase::title()`.

**`ComplianceStandard`**:
```
ISO_12944 → title "ISO 12944" / description "ISO 12944 (ГОСТ 34667.5—2021)"
```

**`PrimerType`** — общий для всех стандартов:
```
ZINC_RICH → title "Zn(R)"     / description "Цинкнаполненная грунтовка (≥80% Zn)"
OTHER     → title "Прочие"    / description "Все прочие типы грунтовок"
```

**`IsoCorrosivityCategory`** — по ГОСТ 34667.2 (категории коррозионной
активности среды):
```
C1  → title "C1"  / description "Очень низкая"
C2  → title "C2"  / description "Низкая"
C3  → title "C3"  / description "Средняя"
C4  → title "C4"  / description "Высокая"
C5  → title "C5"  / description "Очень высокая"
CX  → title "CX"  / description "Экстремальная"
IM1 → title "Im1" / description "Погружение в пресную воду"
IM2 → title "Im2" / description "Погружение в морскую или слабоминерализованную воду"
IM3 → title "Im3" / description "Погружение в грунт"
```

C1 включён для полноты номенклатуры, хотя ГОСТ 34667.5 отмечает, что для C1
защита от коррозии не требуется (см. п. 5.1). Если появятся системы для C1
(декоративные) — enum это уже поддерживает.

**`IsoDurability`** — по ГОСТ 34667.1 (диапазоны долговечности защитной
системы):
```
LOW       → title "L"  / description "Низкая (менее 7 лет)"
MEDIUM    → title "M"  / description "Средняя (от 7 до 15 лет)"
HIGH      → title "H"  / description "Высокая (от 15 до 25 лет)"
VERY_HIGH → title "VH" / description "Очень высокая (более 25 лет)"
```

### `ComplianceRule` — универсальный VO правила

```php
final readonly class ComplianceRule {
    public function __construct(
        public ComplianceStandard $standard,
        public Substrate          $substrate,
        public string             $category,      // enum->value конкретного стандарта
        public string             $durability,    // enum->value конкретного стандарта
        public PrimerType         $primerType,
        public int                $mnoc,          // минимум слоёв
        public int                $ndft,          // минимальная суммарная толщина, мкм
        /** @var list<CoatingBase> */
        public array              $primerBinders, // допустимые связующие грунта
        /** @var list<CoatingBase> */
        public array              $otherBinders,  // допустимые связующие последующих слоёв
    ) {}
}
```

### Справочник правил — const в коде

`Coatings/Domain/Aggregate/CoatingSystem/ComplianceRuleBook.php`:

```php
final class ComplianceRuleBook {
    /** @return list<ComplianceRule> */
    public static function rules(): array {
        return [
            new ComplianceRule(
                standard:      ComplianceStandard::ISO_12944,
                substrate:     Substrate::STEEL_CARBON,
                category:      IsoCorrosivityCategory::C3->value,
                durability:    IsoDurability::HIGH->value,
                primerType:    PrimerType::ZINC_RICH,
                mnoc:          2,
                ndft:          160,
                primerBinders: [CoatingBase::EP, CoatingBase::PUR, CoatingBase::ESI],
                otherBinders:  [CoatingBase::EP, CoatingBase::PUR, CoatingBase::AK, CoatingBase::AY],
            ),
            // остальные правила таблиц B.2 (сталь+Sa 2½), B.3 (оцинкованная),
            // B.4 (термически напылённая), B.5 (другие поверхности) — по одному
            // ComplianceRule на каждую строку таблицы (~80 правил суммарно).
            // При добавлении нового стандарта его правила пишутся в этот же
            // массив со своим ComplianceStandard::NEW_STANDARD.
        ];
    }
}
```

Данные для ISO_12944 — из ГОСТ 34667.5—2021, таблицы B.2..B.5. Изменения
справочника = коммит + `bin/console coatings:system-compliance:rebuild`.

### `ComplianceEvaluator`

```php
final class ComplianceEvaluator {
    /** @return list<array{standard: ComplianceStandard, category: string, durability: string}> */
    public function evaluate(CoatingSystem $system): array {
        $primerType = $system->firstLayer()->getCoating()->isZincRich()
            ? PrimerType::ZINC_RICH : PrimerType::OTHER;
        $ndft = array_sum(array_map(fn($l) => $l->getDft(), $system->getLayers()->toArray()));
        $mnoc = $system->getLayers()->count();

        $result = [];
        foreach (ComplianceRuleBook::rules() as $rule) {
            if ($rule->substrate !== $system->getSubstrate())            continue;
            if ($rule->primerType !== $primerType)                       continue;
            if ($mnoc < $rule->mnoc)                                     continue;
            if ($ndft < $rule->ndft)                                     continue;
            if (!in_array($system->firstLayer()->getCoating()->getBase(),
                          $rule->primerBinders, true))                   continue;
            foreach ($system->followupLayers() as $layer) {
                if (!in_array($layer->getCoating()->getBase(),
                              $rule->otherBinders, true)) continue 2;
            }
            $result[] = [
                'standard'   => $rule->standard,
                'category'   => $rule->category,
                'durability' => $rule->durability,
            ];
        }

        return $result;
    }
}
```

Pure function — I/O нет, юнит-тестируется на реальных примерах из ГОСТ (ISO_12944).

### Индекс соответствия

Отдельная плоская таблица (не entity):

```sql
CREATE TABLE coating_system_compliance (
    system_id  UUID        NOT NULL REFERENCES coating_system(id) ON DELETE CASCADE,
    standard   VARCHAR(32) NOT NULL,   -- ComplianceStandard->value
    category   VARCHAR(16) NOT NULL,   -- enum->value конкретного стандарта
    durability VARCHAR(16) NOT NULL,   -- enum->value конкретного стандарта
    PRIMARY KEY (system_id, standard, category, durability)
);
CREATE INDEX ix_csc_search ON coating_system_compliance (standard, category, durability, system_id);
```

Одна система → N записей (по одной на каждую подтверждённую тройку `(standard,
category, durability)`).

Заполняется через Doctrine listener `CoatingSystemComplianceProjector`
(`Coatings/Infrastructure/Projector/`):
- на `postPersist(CoatingSystem)` — INSERT set из `evaluate()`.
- на `postUpdate(CoatingSystem)` — DELETE + INSERT set.
- на `preRemove(CoatingSystem)` — cascade из FK.

Массовый пересчёт после правки RuleBook (например при добавлении нового
стандарта или коррекции ISO-правил):
```
bin/console coatings:system-compliance:rebuild
```

### Поиск

```php
CoatingSystemRepositoryInterface::findByCompliance(
    ComplianceStandard $standard,
    string             $category,     // "C3", "class-2", ...
    string             $durability,   // "HIGH", "25y", ...
    ?Substrate         $substrate = null,
    int                $limit = 50,
    int                $offset = 0,
): array;   // list<CoatingSystem>
```

Реализация — простой JOIN на `coating_system_compliance` + опциональный WHERE
по substrate.

## Use-cases и слои приложения

### Commands (CQRS write-side)

Каждый со своим handler в `Application/UseCase/Command/{Name}/`.

**На систему целиком:**
- `CreateCoatingSystem(title, description, substrate, surfacePreparation, initialLayers[])`
- `UpdateCoatingSystemMetadata(id, title, description, substrate, surfacePreparation)`
- `RemoveCoatingSystem(id)`

**На слои:**
- `AppendLayer(systemId, coatingId, dft)`
- `InsertLayerAt(systemId, position, coatingId, dft)`
- `RemoveLayerAt(systemId, position)`
- `MoveLayer(systemId, from, to)`
- `UpdateLayerDft(systemId, position, dft)`

Handlers — тонкие: достать агрегат, вызвать метод домена, `flush`. Все
инварианты — в агрегате.

### Queries (read-side)

- `FindCoatingSystemById($id)` → `CoatingSystemDTO`.
- `ListCoatingSystems(CoatingSystemsFilter, CoatingSystemSort, page)` → `list<CoatingSystemDTO>`.
- `SearchCoatingSystemsByCompliance(standard, category, durability, ?substrate)` → `list<CoatingSystemDTO>`.

Query handlers возвращают DTO через `CoatingSystemDTOTransformer::fromEntity`.

### Controllers (per-action, по образцу `Coatings/Controller/Coating/`)

`Coatings/Infrastructure/Controller/CoatingSystem/`:
- `AddAction.php`       — GET/POST `/cabinet/coating/coating-system/add`
- `UpdateAction.php`    — GET/POST `/cabinet/coating/coating-system/{id}/update`
- `ListAction.php`      — GET `/cabinet/coating/coating-system/list`
- `ViewAction.php`      — GET `/cabinet/coating/coating-system/{id}`
- `RemoveAction.php`    — POST `/cabinet/coating/coating-system/{id}/remove`
- `SearchByComplianceAction.php` — GET `/cabinet/coating/coating-system/search-by-compliance?standard=ISO_12944&category=C3&durability=HIGH[&substrate=STEEL_CARBON]`

### API (для будущих клиентов, минимум)

- `Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php`
  — GET `/api/coating-systems/by-compliance?standard=ISO_12944&category=C3&durability=HIGH` → JSON.

### UI

Форма Add/Update — Stimulus-контроллер `coating_system_form_controller.js`:
- Управление слоями: add/insert/remove/move (кнопки ↑↓ на слое; drag-n-drop —
  в отдельной итерации).
- Мгновенная подсказка совместимости при добавлении слоя (fetch на server-side
  check либо offline через передачу matrix совместимости в data-attribute).
- Расчётные `(C, D)` — показывать в правой панели, обновлять при сохранении
  (не live, чтобы не гонять расчёт на каждый чих).

`ViewAction`: слева таблица слоёв (position, coating.title, coating.base, dft),
справа плашки соответствия сгруппированно по стандартам — «По ISO 12944:
C3-M, C3-H, C4-L, Im1-M | По ГОСТ 51164: class-2 25y» (когда появится второй
стандарт).

## Тестирование

**Unit** (`tests/Unit/Coatings/Domain/Aggregate/CoatingSystem/`):
- `ComplianceEvaluatorTest` — 20+ кейсов из ГОСТ 34667.5 (типовые системы
  C3.02, C4.01 и т.п. дают правильные множества троек `(standard, category,
  durability)`).
- `CoatingSystemChainValidatorTest` — совместимость соседей.
- `SurfacePreparationTest` — валидация полей.
- `CoatingSystemTest` — positions dense, все методы мутации слоёв, dft ∈
  range, `updatedAt` обновляется.
- `CoatingSystemLayerTest` — конструктор.

**Functional** (`tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/`):
- `AddActionTest`, `UpdateActionTest`, `ListActionTest`, `ViewActionTest`,
  `RemoveActionTest`, `SearchByComplianceActionTest` — реальная БД, проверяют
  что compliance index обновляется корректно (после create система появляется
  в результатах поиска; после update — индекс пересчитан; после remove —
  cascade удалил).

**Integration** (`tests/Functional/Coatings/Infrastructure/Projector/`):
- `CoatingSystemComplianceProjectorTest` — при persist/update/remove системы
  index пересчитывается / удаляется.

## Миграции

Одной миграцией `VersionYYYYMMDDHHMMSS`:
```sql
-- coating.is_zinc_rich
ALTER TABLE coating ADD COLUMN is_zinc_rich BOOLEAN NOT NULL DEFAULT FALSE;

-- coating_system
CREATE TABLE coating_system (
    id                       UUID PRIMARY KEY,
    title                    VARCHAR(100) NOT NULL,
    description              VARCHAR(2000) NOT NULL DEFAULT '',
    substrate                VARCHAR(32) NOT NULL,
    surface_preparation_grade       VARCHAR(30)  NOT NULL,
    surface_preparation_description VARCHAR(500) NOT NULL DEFAULT '',
    surface_preparation_standard    VARCHAR(50),
    created_at               TIMESTAMPTZ NOT NULL,
    updated_at               TIMESTAMPTZ NOT NULL
);

-- coating_system_layer
CREATE TABLE coating_system_layer (
    id          UUID PRIMARY KEY,
    system_id   UUID NOT NULL REFERENCES coating_system(id) ON DELETE CASCADE,
    coating_id  UUID NOT NULL REFERENCES coating(id) ON DELETE RESTRICT,
    position    INT NOT NULL CHECK (position >= 1),
    dft         INT NOT NULL CHECK (dft >= 1),
    UNIQUE (system_id, position)
);
CREATE INDEX ix_csl_system ON coating_system_layer (system_id, position);

-- coating_system_compliance
CREATE TABLE coating_system_compliance (
    system_id  UUID        NOT NULL REFERENCES coating_system(id) ON DELETE CASCADE,
    standard   VARCHAR(32) NOT NULL,
    category   VARCHAR(16) NOT NULL,
    durability VARCHAR(16) NOT NULL,
    PRIMARY KEY (system_id, standard, category, durability)
);
CREATE INDEX ix_csc_search ON coating_system_compliance (standard, category, durability, system_id);
```

Миграция идемпотентная (`IF NOT EXISTS` где применимо).

## Backlog (осознанно откладываем)

- **Полиморфизм по стандартам**. Пока `ComplianceRule` — единая структура для
  всех стандартов; работает пока правило = `(среда × срок × требования к слоям)`.
  Если появится стандарт с принципиально другой формой правила (например,
  химические классы стойкости с реагентами, где нет `durability`), переходить к
  `interface ComplianceRule` + реализации per-standard.

- **Сертификаты**. Отдельная entity `CoatingSystemCertificate(system,
  documentId, ?category, ?durability, ?extraScope)` — подтверждение испытаний
  для конкретной среды. Связь с bounded context `Documents` слабая (по UUID
  документа). Проработать когда понадобится.
- **Snapshot vs live-ссылка `Coating` в слое**. Сейчас слой держит живую
  ссылку — если у Coating изменят `base` или `isZincRich`, у существующих
  систем цепочка может стать невалидной и compliance-индекс — устареть.
  Варианты: (a) event на изменение Coating → пересчёт систем-потребителей;
  (b) снапшот `base`/`isZincRich`/`dftRange` в момент добавления слоя.
  Обсуждать когда столкнёмся с реальным разъездом.
- **Существующий `CoatingSystemSurface` enum** (PFP/PROTECTIVE/MARINE/
  SPECIAL). В новом агрегате не используется. Пока удаляется вместе со
  старым черновиком — если понадобится «назначение», введём как отдельный
  enum/тег на новой сущности.
- **Расширение `Substrate`** (WOOD, GRP, PLASTIC…) — по мере добавления
  реальных систем.
- **Полнотекстовый поиск** по `title`/`description` — по образцу существующего
  Coating (tsvector, `buildPrefixTsQuery`). Когда список систем перестанет
  помещаться на страницу.
- **Использование в Proposals** — snapshot системы в предложении vs
  live-ссылка. Отдельная задача при интеграции с Proposals.
- **UI drag-n-drop** для порядка слоёв. Первая итерация — кнопки ↑↓.
