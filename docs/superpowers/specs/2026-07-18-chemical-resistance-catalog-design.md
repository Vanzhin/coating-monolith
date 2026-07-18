# Справочник химстойкости покрытий

## Задача

Добавить в приложение справочник химической стойкости покрытий и сделать так, чтобы поиск покрытий работал по названиям веществ и CAS-номерам. Основной кейс пользователя: «найти покрытие, которое выдержит вещество X — по русскому названию, английскому названию или CAS».

Исходные данные — три `.docx` от производителя Литатанк (Классик, Плюс, Стандарт), по ~1000 строк «Вещество / Оценка» в каждом. Оценки: `R | NR | LR | FS | NT`, опциональная макс. температура, опциональные ссылки на примечания.

## Решения

- Отдельный bounded context `ChemicalResistance` в `app/src/ChemicalResistance/`.
- Три агрегата: `Substance` (справочник веществ), `Assessment` (оценка coating↔substance), `Note` (справочник примечаний). Все — независимые агрегаты, связаны по id, без ORM-ассоциаций между агрегатами и без ORM-ассоциаций к `Coatings`.
- Поиск — через существующее `CoatingSearch.search_vector` (Postgres FTS), расширяемое триггером: канонические имена, алиасы и CAS всех «стойких» (`grade.isSuitable()`) веществ подмешиваются в вектор coating. Никаких новых фильтров в UI: единственная поисковая строка обслуживает и обычный текст, и вещества, и CAS.
- Русский — приоритетный язык `canonical_name`; оригинальное английское/норвежское/торговое написание живёт в `aliases`.
- Импорт из docx — на моей стороне: парсер + сид-миграции. Ручной проход по ~300 самым частым веществам с проставлением CAS и русских canonical.

## Терминология

- **Substance** — вещество как таковое (одна запись справочника).
- **Assessment** — оценка стойкости одного вещества для одного coating.
- **Note** — примечание, ссылка на которое стоит в assessment.
- **System note** — read-time-константа из legend docx («высоковязкие и твёрдые вещества — до +70°C…»), в БД не хранится.
- **Grade** — код оценки (`R`, `NR`, `LR`, `FS`, `NT`).
- **Suitable** — покрытие «подходит», если `grade ∈ {R, LR}`. Единый источник — `Grade::isSuitable()`.
- **CAS** — стандартный идентификатор CAS Registry Number, формат `NNNNNNN-NN-N` с checksum-цифрой.

---

## Домен

```
app/src/ChemicalResistance/Domain/
  Aggregate/
    Substance/
      Substance.php
      CasNumber.php
      Specification/
        UniqueSubstanceNameSpecification.php
        UniqueCasSpecification.php
    Assessment/
      Assessment.php
      Grade.php
      AssessmentTemperature.php
      Specification/
        UniqueCoatingSubstanceAssessmentSpecification.php
        AssessmentNotesConsistencyValidator.php
    Note/
      Note.php
  Service/
    SubstanceNameNormalizer.php
    SystemNotes.php
    SystemNote.php
    EffectiveAssessmentNotes.php
    NoteView.php
  Repository/
    SubstanceRepository.php   (interface)
    AssessmentRepository.php  (interface)
    NoteRepository.php        (interface)
```

### Substance

```php
final class Substance extends Aggregate {
    public readonly Uuid $id;
    private string $canonicalName;          // "Этиленгликоль" — приоритетно русский
    private string $canonicalNameKey;       // normalize(canonicalName), для UNIQUE
    private ?CasNumber $cas;                // nullable, UNIQUE если задан
    private StringCollection $aliases;      // ["Ethylene glycol", "1,2-Ethanediol", "1,2-Dihydroxyethane", ...]

    public function setCanonicalName(string $name): void;   // ↔ UniqueSubstanceNameSpecification
    public function setCas(?CasNumber $cas): void;          // ↔ UniqueCasSpecification
    public function addAlias(string $alias): void;          // no-op если normalize(alias) уже среди known()
    public function removeAlias(string $alias): void;
    public function hasName(string $probe): bool;           // normalize(probe) ∈ known()

    /** canonical + все aliases — то, что попадёт в FTS-вектор coating. */
    public function getSearchableNames(): StringCollection;

    private function known(): array;    // normalize(canonical) ∪ normalize(aliases)
}
```

**Инварианты:**
- `canonicalNameKey` глобально уникален (`UniqueSubstanceNameSpecification`).
- `cas` уникален если не null (`UniqueCasSpecification`).
- Внутри одного Substance: `normalize(alias)` не должно совпадать с canonical и с другими алиасами того же вещества. Cross-substance уникальность алиасов **не требуется** — «Спирт» может быть алиасом и Метанола, и Этанола.

### VO `CasNumber`

```php
final readonly class CasNumber implements \Stringable {
    private function __construct(public string $value) {}   // "107-21-1"

    public static function fromString(string $raw): self;   // валидирует формат + checksum
    public function equals(self $other): bool;
    public function __toString(): string;
}
```

- Формат `\d{2,7}-\d{2}-\d`.
- Checksum по стандарту CAS: `Σ(digit_i × position_from_right) mod 10 == check_digit`.
- Невалидные — `AppException` с осмысленным сообщением на русском.

### Assessment

```php
final class Assessment extends Aggregate {
    public readonly Uuid $id;
    private Uuid $coatingId;                             // FK по значению
    private Uuid $substanceId;                           // FK по значению
    private Grade $grade;
    private AssessmentTemperature $maxTemperature;       // всегда есть, default 40
    private StringCollection $noteIds;                   // UUID-строки Note (в БД)

    public function setGrade(Grade $g): void;
    public function setMaxTemperature(AssessmentTemperature $t): void;
    public function setNoteIds(StringCollection $ids, NoteRepository $notes): void;
    // ↑ второй параметр валидирует существование каждого noteId
}
```

**Инварианты:**
- Пара `(coatingId, substanceId)` глобально уникальна (`UniqueCoatingSubstanceAssessmentSpecification` + UNIQUE в БД).
- Каждый `noteId` из `noteIds` существует в справочнике Note (`AssessmentNotesConsistencyValidator`).
- `noteIds` без дублей внутри списка.

### Grade

```php
enum Grade: string {
    case R = 'R';    // стойкое
    case NR = 'NR';  // не стойкое
    case LR = 'LR';  // ограниченно стойкое
    case FS = 'FS';  // требуется уточнение спецификации
    case NT = 'NT';  // не тестировалось

    public function isSuitable(): bool { return $this === self::R || $this === self::LR; }
}
```

`isSuitable()` — единственный источник правды «подходит ли под фильтр». Читают его и UI, и SQL-функция `chemical_resistance_is_suitable_grade` (см. миграции), синхронизация двух мест — обязательный тест.

### VO `AssessmentTemperature`

```php
final readonly class AssessmentTemperature {
    private function __construct(public int $celsius) {}

    public static function fromInt(int $t): self;   // диапазон 1..500
    public static function default(): self;         // 40
}
```

### Note

```php
final class Note extends Aggregate {
    public readonly Uuid $id;
    private string $title;         // "Изменение цвета покрытия"
    private string $description;   // длинная расшифровка

    public function setTitle(string $t): void;         // maxLength 200
    public function setDescription(string $d): void;   // maxLength 2000
}
```

Никакой привязки к coating или substance — глобальный справочник. Один Note может использоваться в любом количестве Assessments.

### SystemNotes (read-time constants)

```php
final readonly class SystemNote {
    public function __construct(public string $title, public string $description) {}
}

final class SystemNotes {
    /** @return list<SystemNote> */
    public static function all(): array {
        return [
            new SystemNote(
                'Высоковязкие и твёрдые вещества',
                'При этом высоковязкие и твёрдые вещества могут храниться в постоянном контакте с ЛКП с температурой до +70°C, если нет отдельных примечаний.',
            ),
        ];
    }
}
```

Хранение — в коде, не в БД. Меняются правкой класса и деплоем, никаких массовых update.

### EffectiveAssessmentNotes

```php
final class EffectiveAssessmentNotes {
    public function __construct(private NoteRepository $notes) {}

    /** @return list<NoteView> — system сверху, потом stored в порядке noteIds */
    public function of(Assessment $a): array;
}

final readonly class NoteView {
    // Обёртка для унификации: system-note и stored-note рендерятся одинаково.
    public static function system(SystemNote $n): self;
    public static function stored(Note $n): self;

    public string $title;
    public string $description;
    public bool $isSystem;    // для CSS-подсветки, если понадобится
}
```

Правило «что относится к каждой оценке» — в одном месте.

### SubstanceNameNormalizer

Одна статическая функция:

```php
final class SubstanceNameNormalizer {
    public static function normalize(string $raw): string {
        $s = \Normalizer::normalize($raw, \Normalizer::FORM_KC);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/\([ngn]\)/u', '', $s);           // (N), (G) — маркеры языка
        $s = preg_replace('/\*[^\s,()]*\*?/u', '', $s);      // *Shell, *TRADENAME Exxon
        $s = preg_replace('/[\s\-.,;\/\\\\]+/u', '', $s);    // пробелы/разделители
        return trim($s);
    }
}
```

Единственный источник правды. Используется в:
1. `Substance::setCanonicalName` — считает `canonicalNameKey` для UNIQUE.
2. `Substance::hasName` / `addAlias` — проверяет пересечения внутри Substance.
3. `SubstanceLookup` (Application) при импорте — ищет существующий Substance.

### Coatings-контекст — не меняется

Никаких новых полей у `Coating`, никаких ORM-relation к `ChemicalResistance`. Данные ChemicalResistance подгружаются read-side-запросами при рендере карточки/модалки.

---

## База данных и миграции

Три отдельных миграции:

1. **DDL** — таблицы, индексы, DBAL-типы.
2. **FTS-триггеры** — SQL-функции и триггеры, расширяющие `CoatingSearch.search_vector`.
3. **Сид-данные (3 штуки)** — по одной на docx: `..._seed_litatank_classic.php`, `..._seed_litatank_plus.php`, `..._seed_litatank_standart.php`.

### 1. DDL

```sql
CREATE TABLE chemical_resistance_substance (
    id UUID PRIMARY KEY,
    canonical_name VARCHAR(200) NOT NULL,
    canonical_name_key VARCHAR(200) NOT NULL,
    cas VARCHAR(15) NULL,
    aliases JSONB NOT NULL DEFAULT '[]'
);
CREATE UNIQUE INDEX uq_substance_canonical_key
    ON chemical_resistance_substance (canonical_name_key);
CREATE UNIQUE INDEX uq_substance_cas
    ON chemical_resistance_substance (cas) WHERE cas IS NOT NULL;
CREATE INDEX ix_substance_aliases_gin
    ON chemical_resistance_substance USING gin (aliases jsonb_path_ops);

CREATE TABLE chemical_resistance_note (
    id UUID PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL
);

CREATE TABLE chemical_resistance_assessment (
    id UUID PRIMARY KEY,
    coating_id UUID NOT NULL REFERENCES coatings_coating(id) ON DELETE CASCADE,
    substance_id UUID NOT NULL REFERENCES chemical_resistance_substance(id) ON DELETE RESTRICT,
    grade VARCHAR(2) NOT NULL,
    max_temperature_celsius SMALLINT NOT NULL DEFAULT 40,
    note_ids JSONB NOT NULL DEFAULT '[]'
);
CREATE UNIQUE INDEX uq_assessment_coating_substance
    ON chemical_resistance_assessment (coating_id, substance_id);
CREATE INDEX ix_assessment_coating ON chemical_resistance_assessment (coating_id);
CREATE INDEX ix_assessment_substance ON chemical_resistance_assessment (substance_id);
CREATE INDEX ix_assessment_coating_grade ON chemical_resistance_assessment (coating_id, grade);
```

`down()` — DROP таблиц в обратном порядке.

### 2. FTS-триггеры

```sql
-- Единственный источник правды «стойкое» в БД.
CREATE OR REPLACE FUNCTION chemical_resistance_is_suitable_grade(g VARCHAR)
RETURNS BOOLEAN LANGUAGE SQL IMMUTABLE AS $$
    SELECT g = 'R' OR g = 'LR';
$$;

-- Собирает все searchable-имена suitable-substance по одному coating.
CREATE OR REPLACE FUNCTION chemical_resistance_suitable_substance_names(cid UUID)
RETURNS TEXT LANGUAGE SQL STABLE AS $$
    SELECT string_agg(
        sub.canonical_name
        || ' ' || COALESCE(sub.cas, '')
        || ' ' || COALESCE(
            (SELECT string_agg(value, ' ')
             FROM jsonb_array_elements_text(sub.aliases) AS value),
            ''
        ),
        ' '
    )
    FROM chemical_resistance_assessment a
    JOIN chemical_resistance_substance sub ON sub.id = a.substance_id
    WHERE a.coating_id = cid
      AND chemical_resistance_is_suitable_grade(a.grade);
$$;

-- Расширяем существующую функцию пересчёта searchVector coatings_coating_search:
-- добавляется сегмент вес D = suitable_substance_names(coating.id).
--
-- Реализация: правится существующая функция; вес D = to_tsvector('russian', ...) c setweight(..., 'D').

-- Триггер: изменение assessment → пересчёт для затронутого coating (OLD и NEW).
CREATE TRIGGER trg_recalc_search_on_assessment
    AFTER INSERT OR UPDATE OR DELETE ON chemical_resistance_assessment
    FOR EACH ROW EXECUTE FUNCTION recalc_coating_search_vector_for_assessment_row();

-- Триггер: изменение canonical_name/aliases/cas у Substance → пересчёт для всех
-- coating, у которых есть assessment на этот substance. Прицельно на колонки —
-- изменение любых будущих полей Substance не будет триггерить лишние пересчёты.
CREATE TRIGGER trg_recalc_search_on_substance_update
    AFTER UPDATE OF canonical_name, aliases, cas ON chemical_resistance_substance
    FOR EACH ROW EXECUTE FUNCTION recalc_coating_search_vectors_for_substance();

-- INSERT/DELETE substance триггеры не нужны: substance без assessments в вектор
-- ничего не добавляет, а DELETE substance блокирован ON DELETE RESTRICT если
-- есть assessments — сначала удалятся они и сработает первый триггер.
```

### 3. Сид-миграции (три штуки, по одной на docx)

Каждая:
1. Читает JSON из `app/src/ChemicalResistance/Infrastructure/Database/Seed/litatank_{coating}.json`. Файл — вывод моего парсера, ручную разметку CAS/русских canonical проставляю я, попадает в git.
2. Находит coating по точному title (`SELECT id FROM coatings_coating WHERE title = :coating_title`). Если coating не заведён — падение с осмысленной ошибкой.
3. Идемпотентный upsert Substance по `canonical_name_key`. При совпадении — не пересоздавать, только merge aliases (без потери уже существующих).
4. Идемпотентный INSERT Note.
5. Идемпотентный upsert Assessment по `(coating_id, substance_id)`.
6. **Batch-режим FTS**: перед вставками — `SET LOCAL chemical_resistance.suppress_search_recalc = 'on'` (сессионная переменная, которую проверяет функция триггера первой строкой и делает early return). После всех вставок — один явный `UPDATE coatings_coating_search SET search_vector = ... WHERE coating_id = :coating_id`. Иначе 1000 assessments = 1000 пересчётов = медленный сид. Локальный флаг безопаснее, чем `session_replication_role = replica` — он не глушит вообще все триггеры, только наш.
7. `down()` — удаляет assessments и notes этого coating; substances не трогает (могут использоваться другими).

Формат JSON:
```json
{
  "coating_title": "Литатанк Классик",
  "notes": [
    {"placeholder_label": "Прим. 1", "title": "Изменение цвета покрытия", "description": "…"},
    {"placeholder_label": "Прим. 2", "title": "Жидкости, используемые в пищу", "description": "…"}
  ],
  "substances": [
    {"canonical": "Этиленгликоль", "cas": "107-21-1",
     "aliases": ["Ethylene glycol", "1,2-Ethanediol", "1,2-Dihydroxyethane", "1,2-Etandiol"]},
    {"canonical": "Вода", "cas": "7732-18-5", "aliases": ["Water", "Aqua", "H2O"]}
  ],
  "assessments": [
    {"substance": "Этиленгликоль", "grade": "R", "max_temperature": null, "notes": []},
    {"substance": "Вода", "grade": "R", "max_temperature": null, "notes": ["Прим. 1"]}
  ]
}
```

Ссылки в `assessments[].notes` — это `placeholder_label` из блока `notes` того же файла (per-coating scope). Ссылки в `assessments[].substance` — это canonical из блока `substances` того же файла.

### Doctrine

- ORM XML: `app/src/ChemicalResistance/Infrastructure/Database/ORM/{Substance,Assessment,Note}.orm.xml`.
- Кастомные DBAL-типы, регистрируемые в `app/config/packages/doctrine.yaml`:
  - `cas_number` → `App\ChemicalResistance\Infrastructure\Database\DBAL\CasNumberType`.
  - `string_collection` → `App\Shared\Infrastructure\Database\DBAL\StringCollectionType` (общий, в Shared — переиспользуется для `aliases` и для `note_ids`).
- `grade` хранится как VARCHAR(2), конверсия — через `Grade::from()` в фабрике агрегата (кастомный тип избыточен).
- `max_temperature_celsius` хранится как SMALLINT, конверсия через фабрику: `AssessmentTemperature::fromInt($celsius)`.

---

## Поиск

Расширяется существующая FTS-инфраструктура (`CoatingSearch.search_vector`, обновляется триггером). Схема весов:

| Сегмент | Вес | Источник |
|---|---|---|
| A | title coating | как сейчас |
| B | description coating | как сейчас |
| C | tag names | как сейчас |
| **D** | **canonical + aliases + CAS всех suitable-substance** | **новый** |

`TS_RANK_CD` даёт совпадениям в title приоритет над substance-match, поэтому `«водостойк»` → сначала coating с этим словом в title/description, а не 40 coating с substance «Вода».

Никаких изменений в `CoatingFinder` не требуется — весь механизм фильтров/фасетов/пейджинга/fuzzy как есть.

### Match highlighting (карточка в списке)

`ListCoatingsQueryHandler` (Coatings-контекст) вызывает read-query `MatchSubstancesForSearch(coatingIds, searchWords)` из ChemicalResistance-контекста. Тот возвращает map `coatingId → SubstanceMatchDTO[]`. Результат кладётся в `CoatingDTO.matchedSubstances`. Twig рендерит бейджи `✓ Стойкое к: …` только когда список непустой.

Match вычисляется на PHP-стороне: `Substance::hasName($word)` для каждого searchWord × substance каждого assessment у suitable-grade. Дёшево (уже ограничили по coatingIds).

---

## UI

### Карточка в списке результатов

Под title/description — inline-строка с бейджами совпавших substance (появляется только при наличии match):

```
Литатанк Классик
Двухкомпонентное эпоксидное покрытие для внутренней…
✓ Стойкое к: Этиленгликоль · Этанол                      [+3 ещё]
[тег] [тег] [тег]
```

- До 3 бейджей + свёртка «+N ещё».
- Бейдж = маленький `.badge` в success-цвете с opacity (тот же паттерн, что у неактивных тегов).
- Клик по бейджу → открывает модалку coating с deep-link `?substance=<uuid>`.

### Модалка coating — новая секция «Химическая стойкость»

Вставляется в `_coating_cards_batch.html.twig` после секции «Время высыхания» как ещё один `<div class="p-3 mb-3 rounded-3 bg-body-tertiary">`.

Компоновка:
```
Химическая стойкость
  ✓ Совместимо: 823   ⚠ Ограниченно: 42   ✗ Нет: 173
  [поиск по веществу ______________]

  ┌───────────────────┬─────┬───────┬─────────────┐
  │ Вещество          │Оцен.│Макс.T│Примечания   │
  ├───────────────────┼─────┼───────┼─────────────┤
  │ Этиленгликоль     │  R  │  40°C │ —           │
  │   CAS 107-21-1    │     │       │             │
  │ Вода              │  R  │  40°C │ ⓘ Прим. 1   │
  └───────────────────┴─────┴───────┴─────────────┘
  [Показать все 1038]

Общие условия
  ⓘ При этом высоковязкие и твёрдые вещества…    ← SystemNotes
```

- **Summary-строка**: три счётчика (R, LR, NR/FS/NT).
- **Живой поиск** — Stimulus-контроллер, partial-endpoint (тот же паттерн, что у infinite-list). Debounce 200мс. Иначе поиск по 50 загруженным строкам был бы кривой.
- **Строка вещества**: canonical первой линией, под ним мелким серым CAS (если есть) и первый alias.
- **Grade-бейдж**: цвет по семантике: `text-bg-success` (R), `text-bg-warning` (LR), `text-bg-danger` (NR), `text-bg-secondary` (FS/NT).
- **Макс. T**: значение из assessment.
- **Примечания**: `ⓘ` чипы, hover-tooltip показывает title + description (bootstrap tooltip).
- **Deep-link подсветка**: `?substance=<uuid>` → страница таблицы содержащая эту строку → `table-warning` + `scrollIntoView`.
- **Общие условия** внизу — `SystemNotes::all()` в компактном виде, один раз на модалку.

Из-за объёма (до 1000+ assessments на coating) — грузим первую страницу (30–50 строк) вместе с модалкой, остальное — partial-endpoint по клику «Показать все».

### Админка

Стандартный CRUD в existing admin-layout, симметрично с `CoatingTag` / `Manufacturer`:
- «Справочник веществ» — список Substance, форма create/update (canonical, CAS, аliases), поиск. Удаление блокируется если есть Assessments.
- «Справочник примечаний по химстойкости» — список Note, форма create/update.
- «Химстойкость покрытия» — открывается со страницы редактирования coating кнопкой «Химстойкость →». Отдельная страница с таблицей assessments coating, inline-редактирование строк, добавление новой оценки через autocomplete по substance.

### Отдельная страница вещества (не в этой спеке, для v2)

`/cabinet/chemical-resistance/substance/{id}` — карточка Substance + обратные списки coating'ов с оценкой R/LR/NR. Домен для этого готов сейчас, страница добавится отдельной задачей.

---

## Application-слой

```
app/src/ChemicalResistance/Application/
  DTO/
    SubstanceDTO.php
    AssessmentDTO.php
    AssessmentRowDTO.php     # read-side: строка таблицы модалки (canonical, cas, aliases, grade, maxTemp, notes)
    NoteDTO.php
    SubstanceMatchDTO.php    # для CoatingDTO.matchedSubstances
  UseCase/
    Command/
      Substance/
        CreateSubstance/     # canonicalName, cas?, aliases[]
        UpdateSubstance/
        DeleteSubstance/     # блокируется если есть Assessments (ON DELETE RESTRICT)
      Assessment/
        CreateAssessment/
        UpdateAssessment/
        DeleteAssessment/
      Note/
        CreateNote/
        UpdateNote/
        DeleteNote/          # блокируется если есть Assessments, ссылающиеся на этот note
    Query/
      ListCoatingAssessments/   # (coatingId, page?, pageSize?, search?, highlightSubstanceId?)
      SubstanceAutocomplete/    # (query, limit=10) — LIKE canonical + jsonb aliases + exact CAS
      MatchSubstancesForSearch/ # (coatingIds[], searchWords[]) → map<coatingId, SubstanceMatchDTO[]>
  Service/
    SubstanceLookup.php               # findOrCreateByName(raw, ?CasNumber): Substance
    ChemicalResistanceImporter.php    # (DocxParseResult, coatingId, options) → ImportReport
```

- Handler'ы стандартные `__invoke(Command): Result`, бросают `AppException` при нарушении инвариантов. CommandBus мапит в 422.
- `SubstanceLookup::findOrCreateByName` — единственный вход в справочник при импорте. Нормализует, ищет по `canonical_name_key`, при находке добавляет alias если написание отличается; при отсутствии — создаёт новый Substance с оригиналом как canonical.
- `ChemicalResistanceImporter` — оркестратор, зовёт CreateNote / SubstanceLookup / CreateOrUpdateAssessment, возвращает отчёт со счётчиками и списком конфликтов.

---

## Infrastructure

```
app/src/ChemicalResistance/Infrastructure/
  Controller/
    Substance/{Create,Update,Delete,List}Action.php
    Note/{Create,Update,Delete,List}Action.php
    Assessment/{Update,Delete}Action.php
    Coating/AssessmentsPageAction.php       # страница «Химстойкость покрытия»
    Coating/AssessmentsPartialAction.php    # partial для модалки (пагинация, поиск, deep-link)
    Import/ImportAction.php                 # UI-загрузка docx (опционально в v1)
  Database/
    ORM/
      Substance.orm.xml
      Assessment.orm.xml
      Note.orm.xml
    DBAL/
      CasNumberType.php
    Seed/
      litatank_classic.json
      litatank_plus.json
      litatank_standart.json
  Repository/
    DoctrineSubstanceRepository.php
    DoctrineAssessmentRepository.php
    DoctrineNoteRepository.php
  Docx/
    DocxAssessmentParser.php         # docx → DocxParseResult
    DocxParseResult.php
    AssessmentRow.php                # { name, grade, maxTemp?, noteLabels[] }
    GradeCellParser.php              # парсит "R, Прим. 1, 70ºC" → { grade, maxTemp, noteLabels }
  Command/
    ImportChemicalResistanceCommand.php    # bin/console coatings:chemical-resistance:import
```

### CLI-импорт

```
bin/console coatings:chemical-resistance:import \
    /path/to/file.docx \
    --coating-title="Литатанк Классик" \      # обязательно
    [--dry-run]                                # разобрать и напечатать отчёт, не писать в БД
    [--overwrite]                              # для существующих assessment — перезаписать
    [--default-max-temp=40]                    # если в legend не удалось распарсить
```

Отчёт:
```
Parsed 1017 assessments, 4 notes, default max temp: 40°C
Substances:  created 892, reused 125, aliases added 34
Assessments: created 1017, updated 0, conflicts 1 (see below)
Notes:       created 4

Conflicts:
  - "ALKYL (C9+) BENZENES": grade R (row 45) vs R;50°C (row 46) → kept row 45
```

Команда живёт постоянно — используется для доливки новых docx после исходной миграции.

### Парсер docx

- Извлечение — либо через `phpoffice/phpword` (если уже есть в composer), либо через самописный ZIP+XML extractor (docx = zip с `word/document.xml`; regexp `<w:t[^>]*>([^<]*)</w:t>` даёт текст, разметку таблиц — через `<w:tbl><w:tr><w:tc>`).
- Разбор таблицы: строки где первая ячейка — число, вторая — вещество, третья — оценка.
- Разбор legend (хвост docx): регэксп `/^Примечание\s*(\d+)\.\s*(.+)$/` даёт title, следующие параграфы до следующего Примечания — description.
- Многострочные ячейки (`"1,2,\n3,Propane\n Triol"`) — склеиваются пробелами, whitespace нормализуется.
- `GradeCellParser` — режет ячейку оценки по запятой, каждый элемент классифицирует regexp'ами:
  - `^R|NR|LR|FS|NT$` → grade
  - `^\d+\s*[°º]?[CСcс]$` → maxTemp (учитывает и `°` U+00B0, и `º` U+00BA — оба встречаются в файлах)
  - `^Прим\.\s*\d+(,\s*\d+)*$` → note-refs (может быть несколько: «Прим. 1,4»)
  Порядок и повторы допустимы, дубли note-refs дедуплицируются.

Юнит-тесты — на fixture-docx (минимальный трёхстрочный docx в `tests/Fixtures/`).

---

## Тесты

**Unit — Domain** (`tests/Unit/ChemicalResistance/Domain/...`, зеркалит `src/`):
- `SubstanceNameNormalizerTest` — 20+ кейсов, включая примеры из docx.
- `CasNumberTest` — валидные (7732-18-5, 107-21-1, 67-56-1, 64-17-5), невалидные checksum, битые форматы.
- `AssessmentTemperatureTest` — границы 1..500, `::default() === 40`.
- `GradeTest` — `isSuitable`: R, LR → true; NR, FS, NT → false.
- `SubstanceTest` — addAlias идемпотентен, hasName находит через canonical/alias, setCanonicalName пересчитывает key.
- `AssessmentTest` — setNoteIds валидирует существование, default maxTemp = 40.
- `NoteTest` — maxLength title/description.
- `SystemNotesTest` — константа стабильна, содержит высоковязкие/твёрдые.
- `EffectiveAssessmentNotesTest` — system первым, stored в порядке noteIds.

**Unit — Infrastructure**:
- `GradeCellParserTest` — все форматы из трёх docx.
- `DocxAssessmentParserTest` — фикстура-docx, парсинг возвращает ожидаемую структуру.

**Functional** (реальная БД):
- `ChemicalResistanceImporterTest` — импорт fixture-docx: Substance-и с дубликат-именами склеены (1 substance с 3 aliases), Assessments проставлены, повторный импорт идемпотентен.
- `SearchIntegrationTest` — сценарии:
  - создать coating с assessment R «Вода» → FTS «вода» находит coating;
  - FTS «7732-18-5» находит тот же coating;
  - изменение canonical у Substance триггерит пересчёт search_vector;
  - изменение assessment.grade с R на NR исключает coating из поиска по substance.
- `Grade::isSuitable() ↔ chemical_resistance_is_suitable_grade`: тест синхронизации (для каждого case enum вызывает SQL-функцию и сверяет результат).
- Handler-ы CRUD (`CreateAssessmentHandlerTest`, etc.) — happy path + инварианты.

---

## Порядок выкатки

1. **Домен + DDL-миграция** — таблицы, индексы, DBAL-типы, все VO/агрегаты, unit-тесты домена. Без сида, без импорта.
2. **Application CRUD** — command/query handlers, functional-тесты. Пока без UI, можно позвать через интеграционные тесты.
3. **Парсер docx + импорт-команда** — DocxAssessmentParser, GradeCellParser, ChemicalResistanceImporter, `bin/console ...:import` с `--dry-run`. Тесты парсера на fixture.
4. **Я парсю 3 docx** → JSON'ы в `Seed/`. Ручная разметка русских canonical + CAS для топ-300. Проверяю import'ом с `--dry-run`.
5. **FTS-триггер** — SQL-функции и триггеры в отдельной миграции. Тест `SearchIntegrationTest`. Проверить перформанс пересчёта на реальном датасете (изменение одного alias у substance с 3 coating-ссылками должно занимать <100мс).
6. **Сид-миграции** (3 штуки) — читают JSON, пишут в БД в batch-режиме (триггеры отключены на время сида, один UPDATE search_vector в конце). `bin/console doctrine:migrations:migrate` на dev проходит чисто.
7. **Read-side для UI** — `ListCoatingAssessmentsQuery`, `MatchSubstancesForSearch`. Расширение `CoatingDTO` полем `matchedSubstances`. Partial-endpoint для страничной подгрузки таблицы стойкости.
8. **UI карточки списка** — бейджи `✓ Стойкое к: …` + Stimulus-контроллер «+N ещё» / клик.
9. **UI модалки coating** — новая секция «Химическая стойкость»: summary + таблица (первая страница) + партиал-поиск + deep-link подсветка + «Общие условия».
10. **UI админки справочников** — стандартный CRUD для Substance и Note; страница «Химстойкость покрытия» с inline-редактированием assessment.
11. **Финал** — прогон `phpunit`, `yarn dev`, ручная проверка поиска «вода» / «107-21-1» / «Литатанк Классик» / «этанол». Убрать `.DS_Store`.

Шаги 1–6 полезны и без UI: поиск «вода» в текущем интерфейсе уже подхватит через триггер. Шаги 7–11 — витрина.

---

## Риски

- **Производительность FTS-триггера при массовом импорте.** 1000 assessments × 3 coating = 3000 пересчётов searchVector. Митигация: batch-режим в сид-миграции и в импорт-команде — на время bulk-операции триггер отключается / игнорируется через сессионный флаг, в конце — один явный `UPDATE coatings_coating_search SET search_vector = ... WHERE coating_id IN (...)`.
- **Ложные срабатывания триггера на UPDATE Substance.** Митигация: триггер объявляется `AFTER UPDATE OF canonical_name, aliases, cas` — только эти колонки триггерят пересчёт. Изменение любых будущих полей Substance (например, `internal_note` для админов) не будет вызывать лишних пересчётов.
- **Многострочные ячейки docx.** Парсер должен склеивать текст ячейки пробелами и нормализовать whitespace.
- **Конфликты grade у дубликатов имён внутри одного docx.** Единичны (0/1/1 в наших трёх файлах). Импорт логирует warning и берёт первое значение; админ правит вручную если важно.
- **Синхронизация `Grade::isSuitable()` ↔ SQL-функции `chemical_resistance_is_suitable_grade`.** Дублирование правила в двух местах (PHP и SQL). Митигация: обязательный тест, проходящий по всем case enum и сверяющий с результатом SQL-функции.
- **CAS-номера, добавленные мной вручную в сид, могут быть неверными.** Митигация: добавляю только те, где на 100% уверен из знаний (топ-100 распространённых веществ). Остальные — null. Никакого угадывания.

## Не в scope

- Отдельный чип-фильтр «Стойкое к» — пока не нужен, кейс покрывается основной строкой поиска.
- Отдельная страница вещества `/cabinet/chemical-resistance/substance/{id}` с обратным списком coating'ов — домен готов, страница добавится отдельной задачей когда понадобится.
- Слияние Substance / MergeSubstance-команда — для v2, если админам захочется чистить справочник.
- Учёт температуры перевозимого груза при фильтрации — не требуется в v1.
- Классификация substance по агрегатному состоянию (для правила «высоковязкие/твёрдые до +70°C») — v2 при необходимости.
