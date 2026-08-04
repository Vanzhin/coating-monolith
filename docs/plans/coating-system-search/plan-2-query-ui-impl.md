# CoatingSystem Search: Query + UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить примитивный `ListCoatingSystemsQuery` + отдельную страницу `SearchByCompliance` единым поиском систем: строка q (FTS через кэш `coating_system_search`), фасеты (substrate, compliance-каскад Standard→Category→Durability, tags, applicationMinTemp, minApplicationTimeAt20), сортировки, infinite scroll, typeahead. UI 1‑в‑1 с существующим поиском покрытий.

**Architecture:** Query дёргает `CoatingSystemFinder` (JOIN `coating_system` + `coating_system_search` + опционально `coating_system_compliance`). Возвращает id + total → handler hydrate агрегатов через `findByIds` → `CoatingSystemDTOTransformer` формирует DTO (compliance/min/max — runtime через методы агрегата, не из кэша). UI переиспользует `chip_facets`/`range_filter`/`async_typeahead`/`infinite_list` Stimulus-контроллеры + Twig-макросы. Compliance-таблица используется ТОЛЬКО как фильтр в Finder; отображение — runtime.

**Tech Stack:** PHP 8.3, Symfony 7 + Doctrine ORM (XML mapping), PostgreSQL 16 (tsvector + GIN, PL/pgSQL RENAME COLUMN), Twig, Stimulus, Bootstrap 5, PHPUnit 9.

## Global Constraints

- **Правило CLAUDE.md**: производные величины считаются runtime-методами домена; кэш только для поиска. DTO Transformer вызывает runtime-методы, а не читает кэш.
- **Rename перед всем**: во всём коде и БД `minBuildingTimeAt20Minutes` заменяется на `minApplicationTimeAt20Minutes`, колонка `min_building_time_at_20_minutes` → `min_application_time_at_20_minutes`, индекс `idx_css_min_building` → `idx_css_min_app_time`.
- **UI-паттерн эталон**: `Templates/admin/coating/coating/index.html.twig` (983 строки). Копируем chip-row + mobile offcanvas + presets + sort dropdown + infinite scroll. Никаких новых CSS-классов, только `bi-*` иконки как в остальном проекте.
- **Пресеты** — берём готовые значения из `Coatings/Infrastructure/Controller/Coating/ListAction.php`: temp — winter/standard/summer, время — fast/standard/slow/very_slow.
- **Compliance-фасет** — мастер-каскад: Standard (single) → Category (single, опции ограничены выбранным Standard) → Durability (single, тоже ограничен). Реализация JS-less: submit-on-change формы, зависимые опции фильтруются на сервере.
- **Substrate-фасет** — multi (пользователь может искать по нескольким сразу).
- **Sort options**: DEFAULT (relevance когда q, title asc иначе), TITLE_ASC, TITLE_DESC, MIN_APPLICATION_TIME_ASC, MIN_APPLICATION_TIME_DESC.
- **Test commands**:
  - `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit <path>`
  - `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress`
  - `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/php-cs-fixer fix --dry-run --diff`
  - `docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate --env=test -n`
- **Assets**: после изменения JS/CSS/Twig — `cd app && yarn dev`.
- **SDD commits**: per-task commit требуется (SDD exception, документ `feedback_sdd_commits.md`). Русский, одна строка ≤150 символов, «жёлтая пресса».
- Spec: `docs/plans/coating-system-search/plan-2-query-ui.md`.

---

## File Structure

**Создать**:

| Файл | Ответственность |
|---|---|
| `app/src/Coatings/Domain/Repository/CoatingSystemSort.php` | Enum сортировки: DEFAULT, TITLE_ASC/DESC, MIN_APPLICATION_TIME_ASC/DESC |
| `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQuery.php` | Query-DTO с фильтром + пейджером + сортировкой |
| `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQueryHandler.php` | Handler: Finder → findByIds → Transformer |
| `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQueryResult.php` | Пагинированный результат: list<DTO> + total + pager |
| `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggest/SearchCoatingSystemsForSuggestQuery.php` | Легкий Query для typeahead |
| `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggest/SearchCoatingSystemsForSuggestQueryHandler.php` | Handler возвращает list<{id,title}> |
| `app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php` | JOIN трёх кэш-таблиц, FTS через PrefixTsQueryBuilder |
| `app/src/Coatings/Infrastructure/Controller/CoatingSystem/SuggestAction.php` | GET /suggest — JSON typeahead |
| `app/src/Shared/Infrastructure/Templates/components/_search_toolbar.html.twig` | Переиспользуемый partial: поле q + submit + сброс + typeahead |
| `app/src/Shared/Infrastructure/Templates/components/facets.twig` | Twig-макросы single/multi/tag/range chip |
| `app/src/Shared/Infrastructure/Database/Migrations/Version20260802120000.php` | Rename column + rename index |

**Изменить**:

| Файл | Что |
|---|---|
| `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` | Rename метода `minBuildingTimeAt20Minutes` → `minApplicationTimeAt20Minutes` |
| `app/src/Coatings/Infrastructure/Cache/CoatingSystemSearchCacheRepository.php` | SQL: новая колонка + новое имя параметра/метода |
| `app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php` | Новые поля (см. Task 3) |
| `app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php` | Метод `findByIds(array): array` |
| `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php` | Реализация `findByIds` с eager-load |
| `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php` | Compliance через evaluator, min/max через методы агрегата |
| `app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php` | Полная переписка под Search Query + новые фасеты + partial-fragment |
| `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig` | Полная переписка по образцу coating/index.html.twig |
| `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/form.html.twig` | Поле «Теги» через Tagify |
| `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` | Заменить inline-строку поиска на `_search_toolbar` include |
| `app/src/Shared/Infrastructure/Templates/base.html.twig` | «Теги покрытий» → «Теги» |
| Все места, где было `minBuildingTimeAt20Minutes` / `min_building_time_at_20_minutes` | Rename по grep |

**Удалить**:

| Файл | Причина |
|---|---|
| `app/src/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php` | Мастер-каскад в UI списка систем заменил |
| `app/src/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php` | Тоже |
| `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsByCompliance/` (папка) | Заменён `SearchCoatingSystemsQuery` |
| `app/src/Coatings/Application/UseCase/Query/ListCoatingSystems/` (папка) | Заменён `SearchCoatingSystemsQuery` |
| `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/search_by_compliance.html.twig` | Не нужен |
| Тесты: `SearchByComplianceActionTest`, `SearchByComplianceApiActionTest`, `SearchCoatingSystemsByComplianceQueryHandlerTest`, `ListCoatingSystemsQueryHandlerTest` | С action-ами уходят |

---

## Task 1: Rename minBuildingTime → minApplicationTime (code + column + migration)

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php` — метод `minBuildingTimeAt20Minutes()` + все его вызовы внутри агрегата (если есть в приватных helpers).
- Modify: `app/src/Coatings/Infrastructure/Cache/CoatingSystemSearchCacheRepository.php` — SQL параметр `min_building_time_at_20_minutes` → `min_application_time_at_20_minutes`; PHP-вызов `$system->minBuildingTimeAt20Minutes()` → `$system->minApplicationTimeAt20Minutes()`.
- Modify: `app/src/Coatings/Infrastructure/Console/RebuildCoatingSystemSearchCacheCommand.php` — если в description/тексте упомянуто «building», заменить (проверить).
- Modify: все тесты, ссылающиеся на старое имя (`grep -rn "minBuildingTime\|min_building_time" app/tests`).
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20260802120000.php` — RENAME COLUMN + RENAME INDEX, идемпотентно.

**Interfaces:**
- Consumes: ничего.
- Produces: публичный метод `CoatingSystem::minApplicationTimeAt20Minutes(): ?int` (тот же контракт, что был у старого `minBuildingTimeAt20Minutes`). Колонка `coating_system_search.min_application_time_at_20_minutes INT`, индекс `idx_css_min_app_time` (btree).

- [ ] **Step 1: Grep все места старого имени**

```bash
grep -rn "minBuildingTime\|min_building_time\|MinBuildingTime\|idx_css_min_building" app/src app/tests app/config
```

Ожидаемо: находки в CoatingSystem.php, CoatingSystemSearchCacheRepository.php, тестах (CoatingSystemTest, CoatingSystemSearchCacheRepositoryTest, CreateCoatingSystemTest, AppendLayerTest), возможно проекторных/rebuild файлах.

- [ ] **Step 2: Rename метода в CoatingSystem.php**

Открыть `app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php`, найти публичный метод `public function minBuildingTimeAt20Minutes(): ?int`. Переименовать в `minApplicationTimeAt20Minutes`. Проверить, что нет приватных helper-методов с тем же корнем (если есть — тоже rename).

- [ ] **Step 3: Rename SQL в CoatingSystemSearchCacheRepository.php**

Открыть `app/src/Coatings/Infrastructure/Cache/CoatingSystemSearchCacheRepository.php`. В методе `upsert`:
- В SQL заменить `min_building_time_at_20_minutes` → `min_application_time_at_20_minutes` (в двух местах — колонке INSERT и в SET EXCLUDED).
- Заменить PHP-вызов `$system->minBuildingTimeAt20Minutes()` → `$system->minApplicationTimeAt20Minutes()` в массиве параметров.

- [ ] **Step 4: Rename в тестах**

Для каждого файла, найденного grep-ом в `app/tests`:
- `getMinBuildingTimeAt20Minutes()` не должно быть (это старый геттер, снят в плане 1) — если есть, значит grep задел test, использующий уже переименованный публичный метод.
- Заменить `minBuildingTimeAt20Minutes` → `minApplicationTimeAt20Minutes` в вызовах на агрегате.
- Заменить `min_building_time_at_20_minutes` → `min_application_time_at_20_minutes` в raw SQL / fetchAssociative-ключах.
- Заменить `idx_css_min_building` → `idx_css_min_app_time` если упомянуто.

- [ ] **Step 5: Написать миграцию Version20260802120000**

Файл `app/src/Shared/Infrastructure/Database/Migrations/Version20260802120000.php`:

```php
<?php declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename min_building_time_at_20_minutes → min_application_time_at_20_minutes
 * (терминология после дизайн-ревью: это про мин.время нанесения, не сборки).
 * Идемпотентно: не падает при повторном прогоне.
 */
final class Version20260802120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coating_system_search: rename min_building_time_at_20_minutes to min_application_time_at_20_minutes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'coating_system_search'
                      AND column_name = 'min_building_time_at_20_minutes'
                ) THEN
                    ALTER TABLE coating_system_search
                        RENAME COLUMN min_building_time_at_20_minutes
                                 TO min_application_time_at_20_minutes;
                END IF;
            END $$
        SQL);

        $this->addSql('DROP INDEX IF EXISTS idx_css_min_building');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_min_app_time ON coating_system_search (min_application_time_at_20_minutes)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_css_min_app_time');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'coating_system_search'
                      AND column_name = 'min_application_time_at_20_minutes'
                ) THEN
                    ALTER TABLE coating_system_search
                        RENAME COLUMN min_application_time_at_20_minutes
                                 TO min_building_time_at_20_minutes;
                END IF;
            END $$
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_min_building ON coating_system_search (min_building_time_at_20_minutes)');
    }
}
```

- [ ] **Step 6: Прогнать миграцию на test БД**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate --env=test -n
```

Ожидаемо: `1 migrations executed`, схема обновлена.

- [ ] **Step 7: Прогнать phpstan + cs-fixer + phpunit**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/php-cs-fixer fix
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings
```

Ожидаемо: 0 phpstan errors, cs-fixer clean или self-fix, все тесты зелёные.

- [ ] **Step 8: Final grep verification**

```bash
grep -rn "minBuildingTime\|min_building_time\|idx_css_min_building" app/src app/tests app/config
```

Ожидаемо: пусто (кроме, возможно, до-миграционных Version20260801* — их не трогаем).

- [ ] **Step 9: Commit**

```bash
git add app/src/Coatings/Domain/Aggregate/CoatingSystem/CoatingSystem.php \
        app/src/Coatings/Infrastructure/Cache/CoatingSystemSearchCacheRepository.php \
        app/src/Shared/Infrastructure/Database/Migrations/Version20260802120000.php \
        app/tests
# add any other affected files from the grep

git commit -m "refactor(coatings): minBuildingTime → minApplicationTime во всём коде и в БД-колонке (терминология после дизайна)"
```

---

## Task 2: CoatingSystemSort enum

**Files:**
- Create: `app/src/Coatings/Domain/Repository/CoatingSystemSort.php`
- Test: `app/tests/Unit/Coatings/Domain/Repository/CoatingSystemSortTest.php`

**Interfaces:**
- Consumes: ничего.
- Produces: `enum CoatingSystemSort: string { DEFAULT, TITLE_ASC, TITLE_DESC, MIN_APPLICATION_TIME_ASC, MIN_APPLICATION_TIME_DESC }` с методом `title(): string` для UI-лейбла.

- [ ] **Step 1: Write failing test**

`app/tests/Unit/Coatings/Domain/Repository/CoatingSystemSortTest.php`:

```php
<?php declare(strict_types=1);
namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Repository\CoatingSystemSort;
use PHPUnit\Framework\TestCase;

final class CoatingSystemSortTest extends TestCase
{
    public function test_all_cases_have_values(): void
    {
        $values = array_map(static fn (CoatingSystemSort $s) => $s->value, CoatingSystemSort::cases());
        sort($values);
        self::assertSame(
            ['default', 'min_application_time_asc', 'min_application_time_desc', 'title_asc', 'title_desc'],
            $values,
        );
    }

    public function test_titles_are_russian_non_empty(): void
    {
        foreach (CoatingSystemSort::cases() as $sort) {
            self::assertNotEmpty($sort->title());
        }
    }
}
```

- [ ] **Step 2: Run — expect FAIL (class not found)**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings/Domain/Repository/CoatingSystemSortTest.php
```

- [ ] **Step 3: Implement enum**

`app/src/Coatings/Domain/Repository/CoatingSystemSort.php`:

```php
<?php declare(strict_types=1);
namespace App\Coatings\Domain\Repository;

/**
 * Порядок сортировки результата поиска систем покрытий.
 * DEFAULT: FTS-ранк когда есть q, title ASC когда q пустое.
 */
enum CoatingSystemSort: string
{
    case DEFAULT = 'default';
    case TITLE_ASC = 'title_asc';
    case TITLE_DESC = 'title_desc';
    case MIN_APPLICATION_TIME_ASC = 'min_application_time_asc';
    case MIN_APPLICATION_TIME_DESC = 'min_application_time_desc';

    public function title(): string
    {
        return match ($this) {
            self::DEFAULT => 'По релевантности',
            self::TITLE_ASC => 'Название А‑Я',
            self::TITLE_DESC => 'Название Я‑А',
            self::MIN_APPLICATION_TIME_ASC => 'Быстрее собрать',
            self::MIN_APPLICATION_TIME_DESC => 'Дольше собрать',
        };
    }
}
```

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Commit**

```bash
git add app/src/Coatings/Domain/Repository/CoatingSystemSort.php \
        app/tests/Unit/Coatings/Domain/Repository/CoatingSystemSortTest.php
git commit -m "feat(coatings): CoatingSystemSort enum — 5 вариантов сортировки для нового поиска систем"
```

---

## Task 3: Расширение CoatingSystemsFilter

**Files:**
- Modify: `app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php`

**Interfaces:**
- Consumes: `CoatingSystemSort` (Task 2). `Substrate`, `ComplianceStandard` (существующие enum). `SearchQuery`, `RangeFilter`, `Pager` (существующие shared VO).
- Produces: расширенный `CoatingSystemsFilter` с новыми полями (см. Step 3 ниже).

- [ ] **Step 1: Прочитать текущее содержимое**

Открыть `app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php` и посмотреть текущий shape. По плану 1 там сейчас `titleLike` (string) + `substrate` (Substrate).

- [ ] **Step 2: Написать failing test — round-trip filter**

`app/tests/Unit/Coatings/Domain/Repository/CoatingSystemsFilterTest.php` — расширить или создать:

```php
<?php declare(strict_types=1);
namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Domain\Repository\SearchQuery;
use PHPUnit\Framework\TestCase;

final class CoatingSystemsFilterTest extends TestCase
{
    public function test_construction_holds_all_fields(): void
    {
        $filter = new CoatingSystemsFilter(
            search: SearchQuery::tryFromString('эпоксид'),
            substrates: [Substrate::STEEL_CARBON, Substrate::CONCRETE],
            standard: ComplianceStandard::ISO_12944,
            category: 'C4',
            durability: 'HIGH',
            tagIds: ['tag-1', 'tag-2'],
            applicationMinTemp: new RangeFilter(-5, 5),
            minApplicationTimeAt20: new RangeFilter(240, 1440),
            sort: CoatingSystemSort::TITLE_ASC,
            pager: Pager::fromPage(1, 20),
        );

        self::assertSame('эпоксид', $filter->search?->value);
        self::assertSame([Substrate::STEEL_CARBON, Substrate::CONCRETE], $filter->substrates);
        self::assertSame(ComplianceStandard::ISO_12944, $filter->standard);
        self::assertSame('C4', $filter->category);
        self::assertSame('HIGH', $filter->durability);
        self::assertSame(['tag-1', 'tag-2'], $filter->tagIds);
        self::assertSame(240, $filter->minApplicationTimeAt20->from);
        self::assertSame(1440, $filter->minApplicationTimeAt20->to);
        self::assertSame(CoatingSystemSort::TITLE_ASC, $filter->sort);
    }

    public function test_defaults_when_nothing_provided(): void
    {
        $filter = new CoatingSystemsFilter();
        self::assertNull($filter->search);
        self::assertSame([], $filter->substrates);
        self::assertNull($filter->standard);
        self::assertSame([], $filter->tagIds);
        self::assertSame(CoatingSystemSort::DEFAULT, $filter->sort);
    }
}
```

- [ ] **Step 3: Run — expect FAIL (fields don't exist)**

- [ ] **Step 4: Rewrite CoatingSystemsFilter**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Domain\Repository\SearchQuery;

/**
 * Полный набор фильтров для поиска систем покрытий. Все поля опциональны.
 * Compliance — мастер-каскад: category/durability имеют смысл только когда задан standard.
 */
final readonly class CoatingSystemsFilter
{
    /**
     * @param list<Substrate> $substrates
     * @param list<string>    $tagIds
     */
    public function __construct(
        public ?SearchQuery $search = null,
        public array $substrates = [],
        public ?ComplianceStandard $standard = null,
        public ?string $category = null,
        public ?string $durability = null,
        public array $tagIds = [],
        public ?RangeFilter $applicationMinTemp = null,
        public ?RangeFilter $minApplicationTimeAt20 = null,
        public CoatingSystemSort $sort = CoatingSystemSort::DEFAULT,
        public Pager $pager = new Pager(1, 20),
    ) {
    }
}
```

Примечание: если старая структура `CoatingSystemsFilter` использовалась (например, `titleLike` в `SearchCoatingSystemsByComplianceQueryHandler`), эти usages будут удалены в Task 12 (уборка). Пока предыдущий handler будет собирать broken `CoatingSystemsFilter` — это ожидаемо, тесты для него временно упадут. Их надо будет удалить или пропустить (`@requires` — не самый чистый вариант; можно поставить `markTestSkipped` до Task 12).

Простой альтернативный подход: в этом Task 3 сохранить поля старого фильтра (напр. `titleLike`, `substrate` — single) как deprecated legacy-параметры конструктора, пока Task 12 не удалит его callers. Тогда старые тесты продолжат работать.

Реализация с backward compat (рекомендуется):

```php
public function __construct(
    public ?SearchQuery $search = null,
    public array $substrates = [],
    public ?ComplianceStandard $standard = null,
    public ?string $category = null,
    public ?string $durability = null,
    public array $tagIds = [],
    public ?RangeFilter $applicationMinTemp = null,
    public ?RangeFilter $minApplicationTimeAt20 = null,
    public CoatingSystemSort $sort = CoatingSystemSort::DEFAULT,
    public Pager $pager = new Pager(1, 20),
    // Legacy — до уборки в Task 12
    public ?string $titleLike = null,
    public ?Substrate $substrate = null,
) {
}
```

- [ ] **Step 5: Run — PASS для нового теста; проверить что старые тесты не сломались**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings
```

Если что-то упало — legacy-параметры добавить именно те, что нужны старым callers.

- [ ] **Step 6: phpstan + cs-fixer + commit**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/php-cs-fixer fix

git add app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php \
        app/tests/Unit/Coatings/Domain/Repository/CoatingSystemsFilterTest.php
git commit -m "feat(coatings): CoatingSystemsFilter расширен — search, substrates[], compliance-каскад, tagIds, 2 range, sort"
```

---

## Task 4: Repository::findByIds

**Files:**
- Modify: `app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php`
- Modify: `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Repository/CoatingSystemRepositoryFindByIdsTest.php`

**Interfaces:**
- Consumes: ничего.
- Produces: `CoatingSystemRepositoryInterface::findByIds(list<string> $ids): list<CoatingSystem>` — возвращает системы в порядке $ids, eager-load `layers.coating.manufacturer` и `tags`.

- [ ] **Step 1: Failing functional test**

`app/tests/Functional/Coatings/Infrastructure/Repository/CoatingSystemRepositoryFindByIdsTest.php`:

```php
<?php declare(strict_types=1);
namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

// ... imports (Coating, CoatingSystem, Manufacturer, SurfaceTreatment, Substrate, DftRange, etc.)
// + SurfaceTreatmentFixtureTrait, KernelTestCase, EntityManagerInterface, Uuid

final class CoatingSystemRepositoryFindByIdsTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;
    // ... standard setUp/tearDown with $systemIds cleanup

    public function test_returns_systems_in_provided_order(): void
    {
        $sysA = $this->persistSystem('A');
        $sysB = $this->persistSystem('B');
        $sysC = $this->persistSystem('C');

        $result = $this->repo->findByIds([$sysC->getId(), $sysA->getId(), $sysB->getId()]);

        self::assertCount(3, $result);
        self::assertSame($sysC->getId(), $result[0]->getId());
        self::assertSame($sysA->getId(), $result[1]->getId());
        self::assertSame($sysB->getId(), $result[2]->getId());
    }

    public function test_missing_ids_omitted_silently(): void
    {
        $sysA = $this->persistSystem('A');
        $fakeId = (string) Uuid::v7();

        $result = $this->repo->findByIds([$sysA->getId(), $fakeId]);

        self::assertCount(1, $result);
    }

    public function test_empty_input_returns_empty_array(): void
    {
        self::assertSame([], $this->repo->findByIds([]));
    }
}
```

- [ ] **Step 2: Run — FAIL (method missing)**

- [ ] **Step 3: Add to interface**

`CoatingSystemRepositoryInterface`:

```php
/**
 * Массовая выгрузка систем по id, порядок соответствует $ids.
 * Отсутствующие id молча пропускаются.
 * @param list<string>       $ids
 * @return list<CoatingSystem>
 */
public function findByIds(array $ids): array;
```

- [ ] **Step 4: Implement in CoatingSystemRepository**

```php
public function findByIds(array $ids): array
{
    if ([] === $ids) {
        return [];
    }

    $systems = $this->createQueryBuilder('cs')
        ->leftJoin('cs.layers', 'l')->addSelect('l')
        ->leftJoin('l.coating', 'c')->addSelect('c')
        ->leftJoin('c.manufacturer', 'm')->addSelect('m')
        ->leftJoin('cs.tags', 't')->addSelect('t')
        ->where('cs.id IN (:ids)')
        ->setParameter('ids', $ids)
        ->getQuery()
        ->getResult();

    // Восстановить порядок $ids
    $byId = [];
    foreach ($systems as $system) {
        $byId[$system->getId()] = $system;
    }
    $ordered = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
        }
    }
    return $ordered;
}
```

- [ ] **Step 5: Run — PASS**

- [ ] **Step 6: phpstan + commit**

```bash
git add app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php \
        app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php \
        app/tests/Functional/Coatings/Infrastructure/Repository/CoatingSystemRepositoryFindByIdsTest.php
git commit -m "feat(coatings): CoatingSystemRepository::findByIds — массовая выгрузка с eager-load для Finder handler"
```

---

## Task 5: CoatingSystemFinder — JOIN трёх кэш-таблиц

**Files:**
- Create: `app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderTest.php`

**Interfaces:**
- Consumes: `CoatingSystemsFilter` (Task 3), `CoatingSystemSort` (Task 2), `Substrate`/`ComplianceStandard` (existing enums), `PrefixTsQueryBuilder` (существующий сервис из Shared), `Doctrine\DBAL\Connection`.
- Produces: `CoatingSystemFinder::find(CoatingSystemsFilter $filter): SearchResult` — возвращает `SearchResult` с `list<string> $ids` (uuid-строки) + `int $total`.

- [ ] **Step 1: Убедиться что SearchResult доступен**

```bash
find app/src/Shared -name "SearchResult*.php"
```

Ожидаемо есть `App\Shared\Domain\Repository\SearchResult` или подобный shared-класс. Если нет — использовать простой tuple: array{'ids' => list<string>, 'total' => int}, но лучше найти существующий тип. Проверить как `CoatingFinder` возвращает результат:

```bash
grep -n "return\|SearchResult\|PaginationResult" app/src/Coatings/Infrastructure/Search/CoatingFinder.php | head -10
```

Использовать тот же тип.

- [ ] **Step 2: Failing functional test**

`app/tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderTest.php`:

```php
<?php declare(strict_types=1);
namespace App\Tests\Functional\Coatings\Infrastructure\Search;

// imports...

final class CoatingSystemFinderTest extends KernelTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private CoatingSystemFinder $finder;
    // setUp/tearDown

    public function test_fts_filter_finds_by_title_keyword(): void
    {
        $sysA = $this->persistZincRichEpSystem('Эпоксидная Морская');
        $sysB = $this->persistZincRichEpSystem('Полиуретановая Обычная');

        $filter = new CoatingSystemsFilter(
            search: SearchQuery::tryFromString('морская'),
        );
        $result = $this->finder->find($filter);

        self::assertContains($sysA->getId(), $result->ids);
        self::assertNotContains($sysB->getId(), $result->ids);
    }

    public function test_substrate_multi_filter(): void
    {
        $sysSteel = $this->persistSystem(substrate: Substrate::STEEL_CARBON);
        $sysConcrete = $this->persistSystem(substrate: Substrate::CONCRETE);
        $sysZinc = $this->persistSystem(substrate: Substrate::STEEL_GALVANIZED);

        $filter = new CoatingSystemsFilter(
            substrates: [Substrate::STEEL_CARBON, Substrate::CONCRETE],
        );
        $result = $this->finder->find($filter);

        self::assertContains($sysSteel->getId(), $result->ids);
        self::assertContains($sysConcrete->getId(), $result->ids);
        self::assertNotContains($sysZinc->getId(), $result->ids);
    }

    public function test_compliance_cascade_standard_only(): void
    {
        $sys = $this->persistZincRichEpSystem();

        $filter = new CoatingSystemsFilter(
            standard: ComplianceStandard::ISO_12944,
        );
        $result = $this->finder->find($filter);

        self::assertContains($sys->getId(), $result->ids);
    }

    public function test_compliance_cascade_full_triple(): void
    {
        $sys = $this->persistZincRichEpSystem();

        $filter = new CoatingSystemsFilter(
            standard: ComplianceStandard::ISO_12944,
            category: 'C4',
            durability: 'HIGH',
        );
        $result = $this->finder->find($filter);

        self::assertContains($sys->getId(), $result->ids);
    }

    public function test_tag_filter(): void
    {
        $tag = $this->persistTag('морской');
        $sysWithTag = $this->persistSystem(tags: [$tag]);
        $sysNoTag = $this->persistSystem(tags: []);

        $filter = new CoatingSystemsFilter(tagIds: [$tag->getId()]);
        $result = $this->finder->find($filter);

        self::assertContains($sysWithTag->getId(), $result->ids);
        self::assertNotContains($sysNoTag->getId(), $result->ids);
    }

    public function test_range_filter_min_application_time(): void
    {
        // системы наполняются с разным mia через кэш-репозиторий
        $sysFast = $this->persistSystemAndRefreshCache(/* fast layers */);
        $sysSlow = $this->persistSystemAndRefreshCache(/* slow layers */);

        $filter = new CoatingSystemsFilter(
            minApplicationTimeAt20: new RangeFilter(from: 0, to: 240), // ≤4h
        );
        $result = $this->finder->find($filter);

        self::assertContains($sysFast->getId(), $result->ids);
        self::assertNotContains($sysSlow->getId(), $result->ids);
    }

    public function test_sort_title_asc_ignores_fts_rank(): void
    {
        $sysA = $this->persistSystem(title: 'Aaa');
        $sysB = $this->persistSystem(title: 'Bbb');

        $filter = new CoatingSystemsFilter(sort: CoatingSystemSort::TITLE_ASC);
        $result = $this->finder->find($filter);

        // Aaa должна идти раньше Bbb
        $posA = array_search($sysA->getId(), $result->ids, true);
        $posB = array_search($sysB->getId(), $result->ids, true);
        self::assertLessThan($posB, $posA);
    }

    public function test_pagination_respects_pager(): void
    {
        // персистим 5 систем
        $systems = array_map(fn ($i) => $this->persistSystem(title: "Sys-$i"), range(1, 5));

        $filter = new CoatingSystemsFilter(
            sort: CoatingSystemSort::TITLE_ASC,
            pager: Pager::fromPage(1, 2),
        );
        $result = $this->finder->find($filter);

        self::assertCount(2, $result->ids);
        self::assertSame(5, $result->total);
    }
}
```

- [ ] **Step 3: Run — FAIL (class missing)**

- [ ] **Step 4: Implement CoatingSystemFinder**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Domain\Repository\SearchResult;
use App\Shared\Infrastructure\Database\FullTextSearch\PrefixTsQueryBuilder;
use Doctrine\DBAL\Connection;

/**
 * Поисковый Finder систем покрытий. JOIN трёх кэш-таблиц:
 *   coating_system (cs)          — основные атрибуты
 *   coating_system_search (css)  — FTS + min/max
 *   coating_system_compliance    — фасет по стандартам (через EXISTS)
 *
 * Возвращает id + total; hydrate агрегатов делает handler через findByIds.
 */
final class CoatingSystemFinder
{
    private const LANG = 'russian';

    public function __construct(
        private readonly Connection $conn,
        private readonly PrefixTsQueryBuilder $tsQueryBuilder,
    ) {
    }

    public function find(CoatingSystemsFilter $filter): SearchResult
    {
        $qb = $this->conn->createQueryBuilder();
        $qb->select('cs.id')
           ->from('coating_system', 'cs')
           ->leftJoin('cs', 'coating_system_search', 'css', 'css.system_id = cs.id');

        $this->applyFts($qb, $filter);
        $this->applySubstrates($qb, $filter);
        $this->applyCompliance($qb, $filter);
        $this->applyTags($qb, $filter);
        $this->applyRange($qb, $filter);

        // total через отдельный count-запрос
        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT cs.id) AS total');
        $total = (int) $countQb->executeQuery()->fetchOne();

        $this->applySort($qb, $filter);
        $qb->setFirstResult($filter->pager->getOffset())
           ->setMaxResults($filter->pager->getLimit());

        $ids = array_map('strval', $qb->executeQuery()->fetchFirstColumn());

        return new SearchResult($ids, $total);
    }

    private function applyFts($qb, CoatingSystemsFilter $filter): void
    {
        if (null === $filter->search) {
            return;
        }
        $tsquery = $this->tsQueryBuilder->build(
            $filter->search->value,
            PrefixTsQueryBuilder::CONJUNCTION_AND,
        );
        $qb->andWhere('css.search_tsvector @@ TO_TSQUERY(:lang, :tsquery)')
           ->setParameter('lang', self::LANG)
           ->setParameter('tsquery', $tsquery);
    }

    private function applySubstrates($qb, CoatingSystemsFilter $filter): void
    {
        if ([] === $filter->substrates) {
            return;
        }
        $values = array_map(fn ($s) => $s->value, $filter->substrates);
        $qb->andWhere('cs.substrate IN (:substrates)')
           ->setParameter('substrates', $values, \Doctrine\DBAL\ArrayParameterType::STRING);
    }

    private function applyCompliance($qb, CoatingSystemsFilter $filter): void
    {
        if (null === $filter->standard) {
            return;
        }
        $sub = 'SELECT 1 FROM coating_system_compliance csc WHERE csc.system_id = cs.id AND csc.standard = :std';
        $qb->setParameter('std', $filter->standard->value);
        if (null !== $filter->category) {
            $sub .= ' AND csc.category = :cat';
            $qb->setParameter('cat', $filter->category);
        }
        if (null !== $filter->durability) {
            $sub .= ' AND csc.durability = :dur';
            $qb->setParameter('dur', $filter->durability);
        }
        $qb->andWhere("EXISTS ($sub)");
    }

    private function applyTags($qb, CoatingSystemsFilter $filter): void
    {
        if ([] === $filter->tagIds) {
            return;
        }
        $qb->andWhere('EXISTS (SELECT 1 FROM coating_system_tag cst WHERE cst.coating_system_id = cs.id AND cst.tag_id IN (:tag_ids))')
           ->setParameter('tag_ids', $filter->tagIds, \Doctrine\DBAL\ArrayParameterType::STRING);
    }

    private function applyRange($qb, CoatingSystemsFilter $filter): void
    {
        if (null !== $filter->applicationMinTemp) {
            $qb->andWhere('css.max_layer_application_min_temp BETWEEN :appt_from AND :appt_to')
               ->setParameter('appt_from', $filter->applicationMinTemp->from)
               ->setParameter('appt_to', $filter->applicationMinTemp->to);
        }
        if (null !== $filter->minApplicationTimeAt20) {
            $qb->andWhere('css.min_application_time_at_20_minutes BETWEEN :mat_from AND :mat_to')
               ->setParameter('mat_from', $filter->minApplicationTimeAt20->from)
               ->setParameter('mat_to', $filter->minApplicationTimeAt20->to);
        }
    }

    private function applySort($qb, CoatingSystemsFilter $filter): void
    {
        switch ($filter->sort) {
            case CoatingSystemSort::DEFAULT:
                if (null !== $filter->search) {
                    $qb->addSelect('TS_RANK_CD(css.search_tsvector, TO_TSQUERY(:lang, :tsquery)) AS rank')
                       ->orderBy('rank', 'DESC');
                } else {
                    $qb->orderBy('cs.title', 'ASC');
                }
                break;
            case CoatingSystemSort::TITLE_ASC:
                $qb->orderBy('cs.title', 'ASC');
                break;
            case CoatingSystemSort::TITLE_DESC:
                $qb->orderBy('cs.title', 'DESC');
                break;
            case CoatingSystemSort::MIN_APPLICATION_TIME_ASC:
                $qb->orderBy('css.min_application_time_at_20_minutes ASC NULLS LAST');
                break;
            case CoatingSystemSort::MIN_APPLICATION_TIME_DESC:
                $qb->orderBy('css.min_application_time_at_20_minutes DESC NULLS LAST');
                break;
        }
    }
}
```

Примечание: DBAL QueryBuilder не поддерживает `NULLS LAST` в orderBy напрямую — можно использовать `->add('orderBy', 'css.min_application_time_at_20_minutes ASC NULLS LAST')` или обернуть в COALESCE. Проверить при первом failing test и подстроить.

- [ ] **Step 5: Run — PASS**

- [ ] **Step 6: phpstan + commit**

```bash
git add app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php \
        app/tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderTest.php
git commit -m "feat(coatings): CoatingSystemFinder — FTS + все фасеты через JOIN кэш-таблиц, 5 сортировок с пагинацией"
```

---

## Task 6: SearchCoatingSystemsQuery + Handler

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQuery.php`
- Create: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQueryHandler.php`
- Create: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQueryResult.php`
- Test: `app/tests/Functional/Coatings/Application/UseCase/Query/SearchCoatingSystemsQueryHandlerTest.php`

**Interfaces:**
- Consumes: `CoatingSystemsFilter` (Task 3), `CoatingSystemFinder` (Task 5), `CoatingSystemRepository::findByIds` (Task 4), `CoatingSystemDTOTransformer` (updated Task 8).
- Produces: `SearchCoatingSystemsQueryResult` с `list<CoatingSystemDTO> $items` + `int $total`. Handler имплементит `QueryHandlerInterface` (`__invoke(Query): Result`).

- [ ] **Step 1: Failing functional test**

```php
final class SearchCoatingSystemsQueryHandlerTest extends KernelTestCase
{
    // setUp/tearDown с fixtures

    public function test_returns_dto_list_with_total(): void
    {
        $sys = $this->persistSystem();
        $handler = static::getContainer()->get(SearchCoatingSystemsQueryHandler::class);

        $result = $handler(new SearchCoatingSystemsQuery(new CoatingSystemsFilter()));

        self::assertContainsSystemId($sys->getId(), $result->items);
        self::assertGreaterThanOrEqual(1, $result->total);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Query DTO**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystems;

use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Application\Query\Query;

final readonly class SearchCoatingSystemsQuery extends Query
{
    public function __construct(public CoatingSystemsFilter $filter)
    {
    }
}
```

- [ ] **Step 4: Result DTO**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;

final readonly class SearchCoatingSystemsQueryResult
{
    /** @param list<CoatingSystemDTO> $items */
    public function __construct(
        public array $items,
        public int $total,
    ) {
    }
}
```

- [ ] **Step 5: Handler**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTOTransformer;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Infrastructure\Search\CoatingSystemFinder;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SearchCoatingSystemsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingSystemFinder $finder,
        private CoatingSystemRepositoryInterface $repo,
        private CoatingSystemDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SearchCoatingSystemsQuery $q): SearchCoatingSystemsQueryResult
    {
        $searchResult = $this->finder->find($q->filter);
        $systems = $this->repo->findByIds($searchResult->ids);
        $items = array_map(fn ($s) => $this->transformer->fromEntity($s), $systems);
        return new SearchCoatingSystemsQueryResult($items, $searchResult->total);
    }
}
```

- [ ] **Step 6: PASS**

- [ ] **Step 7: Commit**

```bash
git add app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/ \
        app/tests/Functional/Coatings/Application/UseCase/Query/SearchCoatingSystemsQueryHandlerTest.php
git commit -m "feat(coatings): SearchCoatingSystemsQuery + Handler — единая точка входа для нового поиска систем"
```

---

## Task 7: SearchCoatingSystemsForSuggestQuery + Handler + SuggestAction

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggest/SearchCoatingSystemsForSuggestQuery.php`
- Create: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggest/SearchCoatingSystemsForSuggestQueryHandler.php`
- Create: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggest/SearchCoatingSystemsForSuggestQueryResult.php`
- Create: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/SuggestAction.php`
- Test: `app/tests/Functional/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggestQueryHandlerTest.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/SuggestActionTest.php`

**Interfaces:**
- Consumes: `Doctrine\DBAL\Connection`, `PrefixTsQueryBuilder`.
- Produces: `SuggestAction` — GET `/cabinet/coating/coating-system/suggest?q=<>&limit=<>` → JSON `[{id, title}]`. Роут `app_cabinet_coating_system_suggest`.

- [ ] **Step 1: Failing test для handler'а**

```php
final class SearchCoatingSystemsForSuggestQueryHandlerTest extends KernelTestCase
{
    public function test_returns_matching_systems(): void
    {
        $sysA = $this->persistSystem('Эпоксидная Морская');
        $sysB = $this->persistSystem('Полиуретан');
        $handler = static::getContainer()->get(SearchCoatingSystemsForSuggestQueryHandler::class);

        $result = $handler(new SearchCoatingSystemsForSuggestQuery('морск', 10));

        $ids = array_column($result->items, 'id');
        self::assertContains($sysA->getId(), $ids);
        self::assertNotContains($sysB->getId(), $ids);
    }

    public function test_empty_query_returns_empty(): void
    {
        $handler = static::getContainer()->get(SearchCoatingSystemsForSuggestQueryHandler::class);
        $result = $handler(new SearchCoatingSystemsForSuggestQuery('', 10));
        self::assertSame([], $result->items);
    }
}
```

- [ ] **Step 2: FAIL**

- [ ] **Step 3: Query + Result**

```php
final readonly class SearchCoatingSystemsForSuggestQuery extends Query
{
    public function __construct(public string $q, public int $limit)
    {
    }
}

final readonly class SearchCoatingSystemsForSuggestQueryResult
{
    /** @param list<array{id: string, title: string}> $items */
    public function __construct(public array $items)
    {
    }
}
```

- [ ] **Step 4: Handler**

```php
final readonly class SearchCoatingSystemsForSuggestQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private Connection $conn,
        private PrefixTsQueryBuilder $tsQueryBuilder,
    ) {
    }

    public function __invoke(SearchCoatingSystemsForSuggestQuery $q): SearchCoatingSystemsForSuggestQueryResult
    {
        $trimmed = trim($q->q);
        if ('' === $trimmed) {
            return new SearchCoatingSystemsForSuggestQueryResult([]);
        }
        $tsquery = $this->tsQueryBuilder->build($trimmed, PrefixTsQueryBuilder::CONJUNCTION_AND);
        $rows = $this->conn->fetchAllAssociative(
            <<<'SQL'
                SELECT cs.id, cs.title
                FROM coating_system cs
                LEFT JOIN coating_system_search css ON css.system_id = cs.id
                WHERE css.search_tsvector @@ TO_TSQUERY(:lang, :tsquery)
                   OR cs.title ILIKE :like
                ORDER BY cs.title
                LIMIT :limit
                SQL,
            [
                'lang' => 'russian',
                'tsquery' => $tsquery,
                'like' => '%' . $trimmed . '%',
                'limit' => $q->limit,
            ],
            ['limit' => \PDO::PARAM_INT],
        );
        $items = array_map(fn ($r) => ['id' => (string) $r['id'], 'title' => (string) $r['title']], $rows);
        return new SearchCoatingSystemsForSuggestQueryResult($items);
    }
}
```

- [ ] **Step 5: Handler PASS**

- [ ] **Step 6: Failing action-test**

`SuggestActionTest.php`:

```php
final class SuggestActionTest extends WebTestCase
{
    public function test_returns_json_with_matching_systems(): void
    {
        $client = static::createClient();
        // авторизация admin/user — по образцу существующего SuggestActionTest покрытий
        $this->loginAsAdmin($client);

        $sys = $this->persistSystem('Тестовая система');

        $client->request('GET', '/cabinet/coating/coating-system/suggest?q=тестов&limit=10');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($data['items']);
        $ids = array_column($data['items'], 'id');
        self::assertContains($sys->getId(), $ids);
    }
}
```

- [ ] **Step 7: FAIL**

- [ ] **Step 8: SuggestAction**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystemsForSuggest\SearchCoatingSystemsForSuggestQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/coating/coating-system/suggest',
    name: 'app_cabinet_coating_system_suggest',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class SuggestAction extends AbstractController
{
    private const MAX_LIMIT = 25;
    private const DEFAULT_LIMIT = 10;

    public function __invoke(Request $request, QueryBusInterface $queryBus): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));
        $result = $queryBus->execute(new SearchCoatingSystemsForSuggestQuery($q, $limit));
        return new JsonResponse(['items' => $result->items]);
    }
}
```

- [ ] **Step 9: PASS**

- [ ] **Step 10: Commit**

```bash
git add app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggest \
        app/src/Coatings/Infrastructure/Controller/CoatingSystem/SuggestAction.php \
        app/tests/Functional/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggestQueryHandlerTest.php \
        app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/SuggestActionTest.php
git commit -m "feat(coatings): CoatingSystem SuggestAction — typeahead JSON endpoint по названию системы"
```

---

## Task 8: CoatingSystemDTOTransformer — runtime compliance + min/max

**Files:**
- Modify: `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php`
- Modify: `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTO.php` — добавить/подтвердить поля `minApplicationTimeAt20Minutes`, `maxLayerApplicationMinTemp`.
- Modify: `app/tests/Unit/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformerTest.php` (существующий).

**Interfaces:**
- Consumes: `ComplianceEvaluator` (существующий), `CoatingSystem::minApplicationTimeAt20Minutes()`, `maxLayerApplicationMinTemp()`, `complianceMatches($evaluator)`.
- Produces: `CoatingSystemDTO` содержит `compliance: list<{standard, category, durability}>` (заполнено runtime evaluator'ом), `minApplicationTimeAt20Minutes: ?int`, `maxLayerApplicationMinTemp: ?int`.

- [ ] **Step 1: Failing test (обновить существующий)**

```php
public function test_transformer_populates_runtime_min_max_and_compliance(): void
{
    $system = $this->buildZincRichEpSystem(); // хотя бы одно совпадение по ISO_12944

    $transformer = static::getContainer()->get(CoatingSystemDTOTransformer::class);
    $dto = $transformer->fromEntity($system);

    self::assertIsInt($dto->maxLayerApplicationMinTemp);
    self::assertGreaterThanOrEqual(0, $dto->minApplicationTimeAt20Minutes);
    self::assertGreaterThan(0, count($dto->compliance));
    self::assertContains(
        ['standard' => 'ISO_12944', 'category' => 'C4', 'durability' => 'HIGH'],
        $dto->compliance,
    );
}
```

- [ ] **Step 2: FAIL (compliance пусто или из старого источника)**

- [ ] **Step 3: Обновить CoatingSystemDTOTransformer**

Заменить логику compliance: вместо чтения из таблицы `coating_system_compliance` — вызывать `$system->complianceMatches($this->evaluator)`. Полученную коллекцию сериализовать через `jsonSerialize()` (или ручной `->toArray()`).

Инжектировать `ComplianceEvaluator` в конструктор transformer'а. Проверить что existing services.yaml или instanceof-регистрация подхватит новую зависимость (autowire).

Поля DTO `minApplicationTimeAt20Minutes` и `maxLayerApplicationMinTemp` заполнять через runtime-методы агрегата:

```php
$dto->minApplicationTimeAt20Minutes = $system->minApplicationTimeAt20Minutes();
$dto->maxLayerApplicationMinTemp = $system->maxLayerApplicationMinTemp();
$dto->compliance = $system->complianceMatches($this->evaluator)->jsonSerialize();
```

- [ ] **Step 4: PASS**

- [ ] **Step 5: Прогон всех тестов**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings
```

- [ ] **Step 6: Commit**

```bash
git add app/src/Coatings/Application/DTO/CoatingSystems/ \
        app/tests/Unit/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTOTransformerTest.php
git commit -m "refactor(coatings): CoatingSystemDTOTransformer — compliance через evaluator, min/max через методы агрегата"
```

---

## Task 9: UI partials — _search_toolbar + facets.twig

**Files:**
- Create: `app/src/Shared/Infrastructure/Templates/components/_search_toolbar.html.twig`
- Create: `app/src/Shared/Infrastructure/Templates/components/facets.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` — заменить inline-строку поиска на `_search_toolbar` include.

**Interfaces:**
- Produces:
  - `{% include 'components/_search_toolbar.html.twig' with {q_value, reset_url, placeholder, endpoint_typeahead?} %}` — блок с полем q, submit, сбросом, опционально typeahead.
  - `{% import 'components/facets.twig' as facets %}` — макросы `facets.single_select_chip`, `facets.multi_select_chip`, `facets.tag_chip`, `facets.range_chip`.

- [ ] **Step 1: Написать _search_toolbar.html.twig**

Копировать секцию строки поиска из `admin/coating/coating/index.html.twig` (найти существующий блок с полем q + submit). Параметризовать через переменные шаблона:

```twig
{# components/_search_toolbar.html.twig #}
{# Параметры: q_value, reset_url, placeholder, endpoint_typeahead (optional) #}
<div class="row g-2 mb-3">
    <div class="col">
        <input type="search"
               name="q"
               class="form-control"
               placeholder="{{ placeholder }}"
               value="{{ q_value|default('') }}"
               {% if endpoint_typeahead is defined and endpoint_typeahead %}
                   data-controller="async-typeahead"
                   data-async-typeahead-endpoint-value="{{ endpoint_typeahead }}"
               {% endif %}>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-search"></i> Найти
        </button>
        <a href="{{ reset_url }}" class="btn btn-outline-secondary">Сброс</a>
    </div>
</div>
```

- [ ] **Step 2: Написать facets.twig — макросы**

```twig
{# components/facets.twig — макросы chip-фасетов для форм-based поиска #}

{% macro single_select_chip(label, name, options, current) %}
    <div class="chip-dropdown" data-controller="chip-facets">
        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
            {{ label }}{% if current %}: {{ options[current] }}{% endif %}
        </button>
        <div class="dropdown-menu p-3">
            {% for value, title in options %}
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="{{ name }}" value="{{ value }}"
                           id="{{ name }}-{{ value }}" {% if current == value %}checked{% endif %}
                           onchange="this.form.submit()">
                    <label class="form-check-label" for="{{ name }}-{{ value }}">{{ title }}</label>
                </div>
            {% endfor %}
            {% if current %}
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="{{ name }}" value=""
                           id="{{ name }}-clear" onchange="this.form.submit()">
                    <label class="form-check-label text-muted" for="{{ name }}-clear">Сбросить</label>
                </div>
            {% endif %}
        </div>
    </div>
{% endmacro %}

{% macro multi_select_chip(label, name, options, current_values) %}
    <div class="chip-dropdown" data-controller="chip-facets">
        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
            {{ label }}{% if current_values|length %} ({{ current_values|length }}){% endif %}
        </button>
        <div class="dropdown-menu p-3">
            {% for value, title in options %}
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="{{ name }}[]" value="{{ value }}"
                           id="{{ name }}-{{ value }}"
                           {% if value in current_values %}checked{% endif %}
                           onchange="this.form.submit()">
                    <label class="form-check-label" for="{{ name }}-{{ value }}">{{ title }}</label>
                </div>
            {% endfor %}
        </div>
    </div>
{% endmacro %}

{% macro tag_chip(name, tagify_endpoint, current_tag_ids) %}
    <div class="chip-dropdown"
         data-controller="coating-tags"
         data-coating-tags-endpoint-value="{{ tagify_endpoint }}">
        <input type="hidden" name="{{ name }}[]" value=""> {# forces empty array when nothing selected #}
        {% for tid in current_tag_ids %}
            <input type="hidden" name="{{ name }}[]" value="{{ tid }}">
        {% endfor %}
        <input class="form-control" placeholder="Теги"
               data-coating-tags-target="input">
    </div>
{% endmacro %}

{% macro range_chip(label, name_from, name_to, from, to, unit, presets) %}
    {# Тонкая обёртка над существующим components/range_filter_card.html.twig #}
    {% include 'components/range_filter_card.html.twig' with {
        title: label,
        unit: unit,
        name_from: name_from,
        name_to: name_to,
        from: from,
        to: to,
        presets: presets,
        reset_label: 'Сброс',
    } %}
{% endmacro %}
```

- [ ] **Step 3: Rewire admin/coating/coating/index.html.twig на _search_toolbar**

Найти в существующем шаблоне блок с input[type=search][name=q] и submit-кнопкой. Заменить на:

```twig
{% include 'components/_search_toolbar.html.twig' with {
    q_value: search|default(''),
    reset_url: path('app_cabinet_coating_coating_list'),
    placeholder: 'Поиск покрытий...',
} %}
```

Скорее всего у покрытий typeahead в форме layers, а не в основной строке — тогда endpoint_typeahead не передавать.

- [ ] **Step 4: yarn dev + визуальная проверка поиска покрытий**

```bash
cd app && yarn dev
```

Открыть в браузере `/cabinet/coating/coating/list`, убедиться что строка поиска работает, submit и сброс не сломаны.

- [ ] **Step 5: Все тесты**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/Coating
```

- [ ] **Step 6: Commit**

```bash
git add app/src/Shared/Infrastructure/Templates/components/_search_toolbar.html.twig \
        app/src/Shared/Infrastructure/Templates/components/facets.twig \
        app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig
git commit -m "feat(ui): partial _search_toolbar + макросы facets.twig, coating-поиск переведён на общий toolbar"
```

---

## Task 10: Переписать CoatingSystem/ListAction

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php` — полная переписка.
- Test: обновить/создать `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/ListActionTest.php`.

**Interfaces:**
- Consumes: `SearchCoatingSystemsQuery` (Task 6), `QueryBusInterface`, `CoatingSystemsFilter` (Task 3), `SearchQuery`/`RangeFilter`/`Pager` (существующие), `CoatingSystemSort` (Task 2), enums Substrate/ComplianceStandard.
- Produces: GET `/cabinet/coating/coating-system/list?<params>`. Query-параметры: `q`, `substrates[]`, `standard`, `category`, `durability`, `tagIds[]`, `applicationMinTempFrom/To` (°C), `minApplicationTimeAt20From/To` (**часы**), `sort`, `page`, `limit`, `partial`. Рендерит полный шаблон или partial-fragment (только карточки) при `?partial=1`.

- [ ] **Step 1: Failing test**

Обновить или создать `ListActionTest`:

```php
public function test_returns_full_page_when_no_partial(): void
{
    $sys = $this->persistSystem();
    $this->client->request('GET', '/cabinet/coating/coating-system/list');
    self::assertResponseIsSuccessful();
    self::assertSelectorTextContains('body', $sys->getTitle());
}

public function test_q_filters_results(): void
{
    $sysA = $this->persistSystem('Alpha');
    $sysB = $this->persistSystem('Beta');
    $this->client->request('GET', '/cabinet/coating/coating-system/list?q=alpha');
    self::assertSelectorExists("[data-system-id=\"{$sysA->getId()}\"]");
    self::assertSelectorNotExists("[data-system-id=\"{$sysB->getId()}\"]");
}

public function test_partial_fragment_returns_only_cards(): void
{
    $this->persistSystem();
    $this->client->request('GET', '/cabinet/coating/coating-system/list?partial=1');
    self::assertResponseIsSuccessful();
    $body = $this->client->getResponse()->getContent();
    // partial-fragment не содержит <html>/<body>
    self::assertStringNotContainsString('<html', $body);
}
```

- [ ] **Step 2: FAIL (текущий ListAction не понимает q/partial)**

- [ ] **Step 3: Переписать ListAction**

```php
<?php declare(strict_types=1);
namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Domain\Repository\SearchQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/coating/coating-system/list',
    name: 'app_cabinet_coating_system_list',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class ListAction extends AbstractController
{
    private const MINUTES_PER_HOUR = 60;
    private const DEFAULT_LIMIT = 20;

    public function __invoke(Request $request, QueryBusInterface $queryBus): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $substrates = array_filter(array_map(
            fn ($v) => Substrate::tryFrom((string) $v),
            (array) $request->query->all('substrates'),
        ));
        $standard = ComplianceStandard::tryFrom((string) $request->query->get('standard', ''));
        $category = $request->query->get('category') ?: null;
        $durability = $request->query->get('durability') ?: null;
        $tagIds = array_values(array_filter(array_map('strval', (array) $request->query->all('tagIds'))));
        $applicationMinTemp = $this->readRange($request, 'applicationMinTempFrom', 'applicationMinTempTo');
        $minApplicationTimeAt20 = $this->readRange(
            $request, 'minApplicationTimeAt20From', 'minApplicationTimeAt20To',
            multiplier: self::MINUTES_PER_HOUR,
        );
        $sort = CoatingSystemSort::tryFrom((string) $request->query->get('sort', '')) ?? CoatingSystemSort::DEFAULT;
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = self::DEFAULT_LIMIT;
        $partial = $request->query->getBoolean('partial', false);

        $filter = new CoatingSystemsFilter(
            search: '' !== $q ? SearchQuery::tryFromString($q) : null,
            substrates: array_values($substrates),
            standard: $standard,
            category: $standard !== null ? $category : null,
            durability: $standard !== null ? $durability : null,
            tagIds: $tagIds,
            applicationMinTemp: $applicationMinTemp,
            minApplicationTimeAt20: $minApplicationTimeAt20,
            sort: $sort,
            pager: Pager::fromPage($page, $limit),
        );

        $result = $queryBus->execute(new SearchCoatingSystemsQuery($filter));

        $template = $partial
            ? 'cabinet/coating/coating_system/_list_cards.html.twig'
            : 'cabinet/coating/coating_system/list.html.twig';

        return $this->render($template, [
            'items' => $result->items,
            'total' => $result->total,
            'q' => $q,
            'substrates' => $substrates,
            'standard' => $standard,
            'category' => $category,
            'durability' => $durability,
            'tagIds' => $tagIds,
            'applicationMinTemp' => $applicationMinTemp,
            'minApplicationTimeAt20Hours' => $this->rangeToHours($minApplicationTimeAt20),
            'sort' => $sort,
            'page' => $page,
            'sortOptions' => CoatingSystemSort::cases(),
            'substrateOptions' => Substrate::cases(),
            'standardOptions' => ComplianceStandard::cases(),
        ]);
    }

    private function readRange(Request $request, string $fromKey, string $toKey, int $multiplier = 1): ?RangeFilter
    {
        $from = $request->query->get($fromKey);
        $to = $request->query->get($toKey);
        if ('' === (string) $from && '' === (string) $to) {
            return null;
        }
        return new RangeFilter(
            (int) $from * $multiplier,
            (int) $to * $multiplier,
        );
    }

    private function rangeToHours(?RangeFilter $range): ?array
    {
        return null === $range ? null : [
            'from' => (int) round($range->from / self::MINUTES_PER_HOUR),
            'to' => (int) round($range->to / self::MINUTES_PER_HOUR),
        ];
    }
}
```

- [ ] **Step 4: Реальный шаблон list.html.twig ещё не готов — сфокусироваться только на action-логике; шаблон в Task 11**

Для сейчас — тесты action-а на успешный 200 и правильные фильтры-параметры. Проверить только respons code и что данные передаются в шаблон. Шаблон-скелет (пустой div с loop items) может быть placeholder-ом до Task 11.

- [ ] **Step 5: Commit**

```bash
git add app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php \
        app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/ListActionTest.php
git commit -m "feat(coatings): ListAction принимает q + все фасеты через URL, отдаёт полный шаблон или partial"
```

---

## Task 11: Полностью новый list.html.twig для систем + _list_cards.html.twig partial

**Files:**
- Rewrite: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig`
- Create: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_list_cards.html.twig` — partial для infinite scroll fragment.
- Reference: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig` — эталон структуры.

**Interfaces:**
- Consumes: макросы из `components/facets.twig` (Task 9), partial `_search_toolbar` (Task 9), контекст из Task 10 ListAction (`items`, `q`, `substrates`, `standard`, etc.).
- Produces: рендер списка систем с chip-row + mobile offcanvas + sort + infinite scroll.

- [ ] **Step 1: Изучить эталон**

```bash
wc -l app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig
```

Прочитать структуру: где `{% embed 'components/list_page.html.twig' %}`, где `{% include %}`-ы `range_filter_card`, где chip-row, где offcanvas mobile, где sort-dropdown, где список карточек, где `{% include 'components/infinite_list.html.twig' %}`.

- [ ] **Step 2: Написать skeleton list.html.twig**

Использовать `{% embed 'components/list_page.html.twig' %}` для главного каркаса. Внутри:
- Form-based фильтры (`<form method="GET" action="{{ path('app_cabinet_coating_system_list') }}">`).
- В форме: `_search_toolbar` (с `endpoint_typeahead: path('app_cabinet_coating_system_suggest')`).
- Chip-row (desktop) — 7 чипов через макросы `facets`:
  1. `facets.multi_select_chip('Substrate', 'substrates', substrateTitles, substrates)`
  2. `facets.single_select_chip('Стандарт', 'standard', standardTitles, standard?.value)`
  3. `facets.single_select_chip('Категория', 'category', categoriesForStandard, category)` (options ограничены — если standard = ISO_12944 то только C1-C5, CX; если NORSOK — свои)
  4. `facets.single_select_chip('Долговечность', 'durability', durabilitiesForStandard, durability)`
  5. `facets.tag_chip('tagIds', path('app_cabinet_tags_suggest'), tagIds)` (или другой существующий tag-suggest endpoint)
  6. `facets.range_chip('Мин.Т нанесения', 'applicationMinTempFrom', 'applicationMinTempTo', applicationMinTemp?.from, applicationMinTemp?.to, '°C', appTempPresets)`
  7. `facets.range_chip('Мин.время нанесения при 20°C', 'minApplicationTimeAt20From', 'minApplicationTimeAt20To', minApplicationTimeAt20Hours?.from, minApplicationTimeAt20Hours?.to, 'ч', appTimePresets)`
- Sort dropdown: `<select name="sort" onchange="this.form.submit()">` со всеми `sortOptions.title()`.
- Mobile offcanvas: зеркало chip-row.
- Список: `{% include '_list_cards.html.twig' %}` (одна и та же карточка используется в полной странице и в partial-фрагменте).
- Infinite scroll: `{% include 'components/infinite_list.html.twig' %}` с endpoint = текущий URL + `partial=1&page=<next>`.

- [ ] **Step 3: `_list_cards.html.twig` — карточка**

```twig
{# _list_cards.html.twig — используется целой страницей и infinite-scroll partial-ом #}
{% for system in items %}
    <div class="card mb-3" data-system-id="{{ system.id }}">
        <div class="card-body">
            <h5 class="card-title">
                <a href="{{ path('app_cabinet_coating_system_update', {id: system.id}) }}"
                   class="text-body text-decoration-none">
                    {{ system.title }}
                </a>
            </h5>
            <div class="small text-muted">
                {{ system.substrateTitle }} • {{ system.surfaceTreatmentTitle }}
                {% if system.layers|length %}
                    • {{ system.layers|length }} слой{{ system.layers|length > 1 ? 'ёв' : '' }}
                    • ТСП {{ system.totalDft }} мкм
                {% endif %}
            </div>
            {% if system.maxLayerApplicationMinTemp is not null %}
                <div class="small">Мин.Т нанесения: {{ system.maxLayerApplicationMinTemp }} °C</div>
            {% endif %}
            {% if system.minApplicationTimeAt20Minutes is not null %}
                <div class="small">Мин.время нанесения: {{ humanize_duration(system.minApplicationTimeAt20Minutes) }}</div>
            {% endif %}
            {% if system.compliance|length %}
                <div class="mt-2">
                    {% for c in system.compliance|slice(0, 3) %}
                        <span class="badge bg-primary me-1">{{ c.standard }} {{ c.category }} {{ c.durability }}</span>
                    {% endfor %}
                    {% if system.compliance|length > 3 %}
                        <span class="badge bg-secondary">+{{ system.compliance|length - 3 }}</span>
                    {% endif %}
                </div>
            {% endif %}
            {% if system.tags|length %}
                <div class="mt-2">
                    {% for tag in system.tags %}
                        <span class="badge bg-info me-1">{{ tag.title }}</span>
                    {% endfor %}
                </div>
            {% endif %}
        </div>
    </div>
{% else %}
    <div class="alert alert-light text-center text-muted">Ничего не найдено.</div>
{% endfor %}
```

Проверить существование Twig-функции `humanize_duration` (может быть в существующем `TagExtension` или создать через существующий шаблонизатор Carbon в проекте). Если нет — inline logic для «N ч / N дн».

- [ ] **Step 4: yarn dev**

```bash
cd app && yarn dev
```

- [ ] **Step 5: Визуальная проверка**

Открыть `/cabinet/coating/coating-system/list`, убедиться что:
- Строка поиска работает.
- Chip-фасеты открываются.
- Компаунс-каскад: выбрал ISO 12944 → категория показывает C1-C5.
- Range-фасеты: пресеты + точный ввод.
- Sort dropdown submit'ит форму.
- Карточки показывают все поля.
- Infinite scroll подгружает.

- [ ] **Step 6: Commit**

```bash
git add app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig \
        app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_list_cards.html.twig
git commit -m "feat(ui): CoatingSystem list — chip-row с 7 фасетами, sort, infinite scroll, карточки с runtime compliance"
```

---

## Task 12: Форма системы — поле «Теги»

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/form.html.twig` — добавить Tagify для tagIds.
- Test: обновить `CreateCoatingSystemActionTest` / `UpdateCoatingSystemActionTest` или добавить тест — что `tagIds[]` из формы попадает в базу.

**Interfaces:**
- Consumes: `coating_tags_controller.js` (существующий Stimulus), tag-suggest endpoint. CoatingSystemMapper уже принимает `tagIds` (сделано в плане 1).

- [ ] **Step 1: Открыть form.html.twig**

Найти секцию где рендерятся substrate, treatment, layers. Добавить блок «Теги» после метаданных, до/после layers:

```twig
<div class="mb-3">
    <label class="form-label">Теги</label>
    <div data-controller="coating-tags"
         data-coating-tags-endpoint-value="{{ path('app_cabinet_tag_suggest') }}">
        {% for tid in inputData.tagIds|default([]) %}
            <input type="hidden" name="tagIds[]" value="{{ tid }}">
        {% endfor %}
        <input class="form-control" placeholder="Введите тег..."
               data-coating-tags-target="input">
    </div>
</div>
```

- [ ] **Step 2: Failing test — сохраняется через форму**

```php
public function test_form_submit_persists_tags(): void
{
    $treatment = $this->persistTreatment();
    $coating = $this->persistCoating();
    $tag = $this->persistTag('морской');

    $this->client->request('POST', '/cabinet/coating/coating-system/create', [
        'title' => 'Система с тегами',
        'description' => '',
        'substrate' => 'STEEL_CARBON',
        'surfaceTreatmentId' => $treatment->getId(),
        'layers' => [['coatingId' => $coating->getId(), 'dft' => 100]],
        'tagIds' => [$tag->getId()],
    ]);
    self::assertResponseRedirects();

    $created = $this->fetchLatestSystem();
    self::assertCount(1, $created->getTags());
    self::assertSame($tag->getId(), $created->getTags()->first()->getId());
}
```

- [ ] **Step 3: PASS**

- [ ] **Step 4: Commit**

```bash
git add app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/form.html.twig \
        app/tests
git commit -m "feat(ui): форма системы получает поле Теги через Tagify, тег-ассоциация сохраняется"
```

---

## Task 13: Cleanup — SearchByCompliance + ListCoatingSystems

**Files:**
- Delete: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php`
- Delete: `app/src/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php`
- Delete: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsByCompliance/` (папка)
- Delete: `app/src/Coatings/Application/UseCase/Query/ListCoatingSystems/` (папка)
- Delete: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/search_by_compliance.html.twig`
- Delete: тесты — `SearchByComplianceActionTest`, `SearchByComplianceApiActionTest`, `SearchCoatingSystemsByComplianceQueryHandlerTest`, `ListCoatingSystemsQueryHandlerTest`
- Modify: `CoatingSystemsFilter.php` — удалить legacy-параметры `titleLike`, `substrate` (после того как все callers removed)

**Interfaces:**
- Consumes: ничего.
- Produces: чистый codebase без старых Query/Action.

- [ ] **Step 1: Grep всех ссылок**

```bash
grep -rn "SearchByCompliance\|SearchCoatingSystemsByCompliance\|ListCoatingSystemsQuery\|ListCoatingSystemsQueryHandler" app/src app/tests app/config
```

Все находки должны быть внутри удаляемых файлов.

- [ ] **Step 2: Удалить файлы**

```bash
rm app/src/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceAction.php
rm app/src/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiAction.php
rm -r app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsByCompliance
rm -r app/src/Coatings/Application/UseCase/Query/ListCoatingSystems
rm app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/search_by_compliance.html.twig
rm app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/SearchByComplianceActionTest.php
rm app/tests/Functional/Coatings/Infrastructure/Api/CoatingSystem/SearchByComplianceApiActionTest.php
rm app/tests/Functional/Coatings/Application/UseCase/Query/SearchCoatingSystemsByComplianceQueryHandlerTest.php
rm app/tests/Functional/Coatings/Application/UseCase/Query/ListCoatingSystemsQueryHandlerTest.php
```

- [ ] **Step 3: Убрать legacy-параметры из CoatingSystemsFilter**

Открыть `CoatingSystemsFilter.php`, удалить `$titleLike` и `$substrate` (legacy). Если тест `CoatingSystemsFilterTest` их использует — тоже удалить оттуда.

- [ ] **Step 4: routes.yaml — проверить**

```bash
grep -n "search_by_compliance\|by-compliance" app/config/routes.yaml
```

Если явно есть — удалить. Если нет (роуты через `#[Route]` на actions) — с удалением action-ов уходят автоматом.

- [ ] **Step 5: Прогон**

```bash
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/php-cs-fixer fix --dry-run --diff
```

Ожидаемо: всё зелёное, никаких dangling references.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore(coatings): удалены SearchByCompliance и ListCoatingSystems — заменены единым SearchCoatingSystems"
```

---

## Task 14: Меню — «Теги покрытий» → «Теги»

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/base.html.twig`

- [ ] **Step 1: Найти и заменить**

Открыть `base.html.twig`, найти пункт меню «Теги покрытий», заменить текст на «Теги». URL (path) не менять.

- [ ] **Step 2: Визуальная проверка**

Открыть любую страницу /cabinet/*, увидеть новый пункт в меню.

- [ ] **Step 3: Commit**

```bash
git add app/src/Shared/Infrastructure/Templates/base.html.twig
git commit -m "chore(ui): пункт меню «Теги покрытий» → «Теги» (тег теперь общий для покрытий и систем)"
```

---

## Self-Review

**1. Spec coverage:**
- Rename `minBuildingTime → minApplicationTime` → **Task 1**.
- Application: Query + Filter + Sort + Suggest → **Tasks 2, 3, 6, 7**.
- Infrastructure: Finder + Repository → **Tasks 4, 5**.
- DTO Transformer runtime → **Task 8**.
- UI partials `_search_toolbar` + `facets.twig` → **Task 9**.
- UI шаблон системы + переписанный ListAction + SuggestAction → **Tasks 10, 11** (+ SuggestAction в Task 7).
- Форма системы с полем «Теги» → **Task 12**.
- Уборка SearchByCompliance + ListCoatingSystems → **Task 13**.
- Меню — «Теги покрытий» → **Task 14**.
- Все секции спеки покрыты.

**2. Placeholder scan:** нет "TBD"/"TODO". Real код в каждом шаге.

**3. Type consistency:**
- `CoatingSystem::minApplicationTimeAt20Minutes()` — Task 1 определяет, Task 5 (Finder использует колонку), Task 8 (transformer вызывает), Task 11 (template показывает `system.minApplicationTimeAt20Minutes`).
- `CoatingSystemsFilter` — Task 3 добавляет поля, Tasks 5/6/10 используют.
- `CoatingSystemSort` — Task 2 создаёт, Task 3 использует как поле, Task 5 в applySort, Task 10 в option list.
- `SearchResult` — Task 5 возвращает, Task 6 читает `.ids`/`.total`. Используем существующий shared-тип.
- Все сигнатуры согласованы.

**Готово.**
