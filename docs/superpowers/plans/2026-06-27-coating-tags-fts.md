# Coating Tags via FTS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Расширить FTS-поиск покрытий так, чтобы по запросу «для бетона» находились покрытия, к которым админ привязал general-теги. Плюс два разделённых API-эндпоинта (suggest для autocomplete, create для явного создания тега) и Tagify-инпут в форме покрытия.

**Architecture:** PostgreSQL-триггеры агрегируют `tags.title` в `search_vector` — PHP-код `CoatingFinder` не меняется. Создание нового тега — отдельный AJAX-эндпоинт, никакого `resolveOrCreate` в Coating command handler'е (тот строго требует существующий id). Frontend через Tagify-инпут связывается с двумя AJAX-эндпоинтами.

**Tech Stack:** PostgreSQL 17 (FTS, pg_trgm), Symfony 7, Doctrine ORM/DBAL, PHPUnit 9.6, Stimulus 3, `@yaireo/tagify` 4.x, Webpack Encore.

## Global Constraints

- Все PHP/Symfony команды запускаются из `app/`.
- Юнит-тесты: `vendor/bin/phpunit tests/Unit/<path>`. Функциональные: `vendor/bin/phpunit tests/Functional/<path>`.
- Asset rebuild после правок Twig/JS/CSS: `yarn dev` из `app/`. Hard-reload браузера (`Cmd+Shift+R`) для смены ассетов.
- DDD-правила CLAUDE.md: бизнес-инварианты в домене (`CoatingTag`), AppException — единственный канал бизнес-ошибок.
- Семантика: `CoatingTag::TYPE_GENERAL = 'general'`. Все другие type'ы (`CoatingCoatType`, `CoatingProtectionType`) остаются неизменными и не показываются в Tagify-инпуте.
- FTS-веса: `title=A`, `description=A`, `tags.title=B`. Язык словаря — `russian`.
- Никаких side-effects в `Create/UpdateCoatingCommandHandler` — теги должны существовать к моменту сабмита формы. Иначе — `AppException`.
- Pre-existing failing tests `tests/Functional/Users/Infrastructure/Controller/GetMeActionTest` и `GetUserActionTest` — известны, не относятся к этой задаче.

---

### Task 1: Domain — TYPE_GENERAL + завершить `CoatingTagRepository` TODO

**Files:**
- Modify: `app/src/Coatings/Domain/Aggregate/Coating/CoatingTag.php`
- Modify: `app/src/Coatings/Infrastructure/Repository/CoatingTagRepository.php`
- Test: `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTagTest.php` (создать если нет)

**Interfaces:**
- Consumes: ничего нового.
- Produces:
  - `CoatingTag::TYPE_GENERAL` — public const `'general'`.
  - `CoatingTagRepository::add(CoatingTag $tag): void` — persist + flush.
  - `CoatingTagRepository::findOneByTitleAndType(string $title, ?string $type): ?CoatingTag` — поиск по `(title, type)`.

- [ ] **Step 1: Написать unit-тест на TYPE_GENERAL const**

Создать (или дополнить) `app/tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTagTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use PHPUnit\Framework\TestCase;

final class CoatingTagTest extends TestCase
{
    public function testTypeGeneralConstantValue(): void
    {
        self::assertSame('general', CoatingTag::TYPE_GENERAL);
    }
}
```

Если файл уже существует — добавить ТОЛЬКО этот метод в конец класса.

- [ ] **Step 2: Запустить тест, убедиться что падает**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith/app
vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTagTest.php
```

Expected: ERROR / FAIL — `CoatingTag::TYPE_GENERAL` not found.

- [ ] **Step 3: Добавить константу в `CoatingTag`**

Открыть `app/src/Coatings/Domain/Aggregate/Coating/CoatingTag.php`. Сразу после `class CoatingTag extends Aggregate {` добавить:

```php
    public const TYPE_GENERAL = 'general';

```

- [ ] **Step 4: Запустить тест — должен пройти**

```bash
vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating/CoatingTagTest.php
```

Expected: `OK`.

- [ ] **Step 5: Реализовать `CoatingTagRepository::add`**

Открыть `app/src/Coatings/Infrastructure/Repository/CoatingTagRepository.php`. Найти метод:

```php
    public function add(CoatingTag $coatingTag): void
    {
        // TODO: Implement add() method.
    }
```

Заменить на:

```php
    public function add(CoatingTag $coatingTag): void
    {
        $this->getEntityManager()->persist($coatingTag);
        $this->getEntityManager()->flush();
    }
```

- [ ] **Step 6: Реализовать `findOneByTitleAndType`**

В том же файле найти:

```php
    public function findOneByTitleAndType(string $title, ?string $type): ?CoatingTag
    {
        // TODO: Implement findOneByTitleAndType() method.
    }
```

Заменить на:

```php
    public function findOneByTitleAndType(string $title, ?string $type): ?CoatingTag
    {
        return $this->findOneBy(['title' => $title, 'type' => $type]);
    }
```

- [ ] **Step 7: Прогнать весь юнит-набор**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: всё зелёное.

- [ ] **Step 8: Commit checkpoint (user)**

User commits 2 modified files + 1 new/modified test. Suggested message: `feat(coating-tag): add TYPE_GENERAL const + impl repo add/findOneByTitleAndType`.

---

### Task 2: PostgreSQL migration — расширить FTS на теги

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version<YYYYMMDDHHMMSS>.php`

(Конкретный timestamp в имени файла выбирается на момент применения миграции, через `bin/console doctrine:migrations:generate`. В плане ниже — используем условный `Version20260627120000.php`. Implementer должен сгенерировать актуальное имя.)

**Interfaces:**
- Consumes: ничего из других задач.
- Produces: расширенный FTS-вектор в `coatings_coating_search.search_vector`, который агрегирует `coatings_coating.title` (A) + `description` (A) + `coatings_coating_tag.title` (B). Триггеры на 3 таблицах поддерживают актуальность.

- [ ] **Step 1: Сгенерировать пустой каркас миграции**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith/app
bin/console doctrine:migrations:generate
```

Скопировать путь к сгенерированному файлу. Открыть его.

- [ ] **Step 2: Заполнить миграцию**

В сгенерированном файле заменить тело класса (оставив namespace и базовые use'ы) на:

```php
final class Version<TIMESTAMP> extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend coatings_coating_search with tags.title (B); add triggers on pivot and coatings_coating_tag.';
    }

    public function up(Schema $schema): void
    {
        // 1. Новая «единая» rebuild-функция, агрегирует title + description + tags.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_rebuild(p_coating_id uuid)
            RETURNS void AS $$
            BEGIN
                INSERT INTO coatings_coating_search (coating_id, search_vector)
                SELECT
                    c.id,
                    setweight(to_tsvector('russian', coalesce(c.title, '')), 'A') ||
                    setweight(to_tsvector('russian', coalesce(c.description, '')), 'A') ||
                    setweight(to_tsvector('russian',
                        coalesce((
                            SELECT string_agg(t.title, ' ')
                            FROM coatings_coating_coating_tag ct
                            JOIN coatings_coating_tag t ON t.id = ct.coating_tag_id
                            WHERE ct.coating_id = c.id
                        ), '')
                    ), 'B')
                FROM coatings_coating c
                WHERE c.id = p_coating_id
                ON CONFLICT (coating_id) DO UPDATE SET search_vector = EXCLUDED.search_vector;
            END
            $$ LANGUAGE plpgsql
        SQL);

        // 2. Снять старый upsert и trigger; повесить новый, вызывающий rebuild.
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_search_upsert_trigger ON coatings_coating');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_upsert()');

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_trigger_coating()
            RETURNS trigger AS $$
            BEGIN
                PERFORM coatings_coating_search_rebuild(NEW.id);
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER coatings_coating_search_after_coating
            AFTER INSERT OR UPDATE OF title, description
            ON coatings_coating
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_trigger_coating()
        SQL);

        // 3. Триггер на pivot-таблицу.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_trigger_pivot()
            RETURNS trigger AS $$
            DECLARE
                affected_coating_id uuid;
            BEGIN
                affected_coating_id := COALESCE(NEW.coating_id, OLD.coating_id);
                PERFORM coatings_coating_search_rebuild(affected_coating_id);
                RETURN NULL;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER coatings_coating_search_after_pivot
            AFTER INSERT OR DELETE
            ON coatings_coating_coating_tag
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_trigger_pivot()
        SQL);

        // 4. Триггер на coatings_coating_tag (UPDATE title) — пересобрать все связанные coatings.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_trigger_tag()
            RETURNS trigger AS $$
            BEGIN
                PERFORM coatings_coating_search_rebuild(ct.coating_id)
                FROM coatings_coating_coating_tag ct
                WHERE ct.coating_tag_id = NEW.id;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER coatings_coating_search_after_tag
            AFTER UPDATE OF title
            ON coatings_coating_tag
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_trigger_tag()
        SQL);

        // 5. Backfill для существующих покрытий.
        $this->addSql(<<<'SQL'
            INSERT INTO coatings_coating_search (coating_id, search_vector)
            SELECT
                c.id,
                setweight(to_tsvector('russian', coalesce(c.title, '')), 'A') ||
                setweight(to_tsvector('russian', coalesce(c.description, '')), 'A') ||
                setweight(to_tsvector('russian',
                    coalesce((
                        SELECT string_agg(t.title, ' ')
                        FROM coatings_coating_coating_tag ct
                        JOIN coatings_coating_tag t ON t.id = ct.coating_tag_id
                        WHERE ct.coating_id = c.id
                    ), '')
                ), 'B')
            FROM coatings_coating c
            ON CONFLICT (coating_id) DO UPDATE SET search_vector = EXCLUDED.search_vector
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_search_after_tag ON coatings_coating_tag');
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_search_after_pivot ON coatings_coating_coating_tag');
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_search_after_coating ON coatings_coating');

        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_trigger_tag()');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_trigger_pivot()');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_trigger_coating()');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_rebuild(uuid)');

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_upsert()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO coatings_coating_search (coating_id, search_vector)
                VALUES (
                    NEW.id,
                    setweight(to_tsvector('russian', coalesce(NEW.title, '')), 'A') ||
                    setweight(to_tsvector('russian', coalesce(NEW.description, '')), 'A')
                )
                ON CONFLICT (coating_id) DO UPDATE
                SET search_vector = EXCLUDED.search_vector;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER coatings_coating_search_upsert_trigger
            AFTER INSERT OR UPDATE OF title, description
            ON coatings_coating
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_upsert()
        SQL);
    }
}
```

Заменить `<TIMESTAMP>` на актуальный timestamp в имени класса (Doctrine генерирует автоматически).

- [ ] **Step 3: Применить миграцию локально**

```bash
bin/console doctrine:migrations:migrate -n
```

Expected: `[OK] Successfully migrated to version: Version<TIMESTAMP>`.

- [ ] **Step 4: Smoke-чек: вектор содержит тег**

Если есть покрытие с тегом — проверь руками:
```bash
bin/console doctrine:query:sql "SELECT coating_id, search_vector FROM coatings_coating_search LIMIT 3"
```

В `search_vector` должны фигурировать B-веса для тегов (например `'top':2B`), если у покрытия есть тег. Если в локальной БД нет покрытий с тегами — этот шаг просто подтверждает, что миграция применилась.

- [ ] **Step 5: Прогнать функциональный набор для регресса**

```bash
vendor/bin/phpunit tests/Functional/Coatings
```

Expected: ничего не упало (миграция не должна сломать существующие тесты).

- [ ] **Step 6: Commit checkpoint (user)**

User commits 1 new migration file. Suggested message: `feat(coating-search): extend FTS vector with tag titles (B-weight) + triggers on pivot/tag`.

---

### Task 3: `CoatingTagFinder` сервис для suggest

**Files:**
- Create: `app/src/Coatings/Infrastructure/Search/CoatingTagFinder.php`
- Create: `app/src/Coatings/Application/UseCase/Query/SuggestTags/SuggestTagsQuery.php`
- Create: `app/src/Coatings/Application/UseCase/Query/SuggestTags/SuggestTagsQueryResult.php`
- Create: `app/src/Coatings/Application/UseCase/Query/SuggestTags/SuggestTagsQueryHandler.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Search/CoatingTagFinderTest.php`

**Interfaces:**
- Consumes: `Doctrine\ORM\EntityManagerInterface`, `App\Coatings\Domain\Aggregate\Coating\CoatingTag` (entity).
- Produces:
  - `CoatingTagFinder::suggest(string $query, ?string $type, int $limit = 10): array<CoatingTag>` — FTS-search + fuzzy-fallback по `coatings_coating_tag.title`. Фильтрует по `type` если задан.
  - `SuggestTagsQuery(public string $query, public ?string $type = null, public int $limit = 10)`.
  - `SuggestTagsQueryResult(public array $tags)` где `tags` — `list<CoatingTagDTO>`.
  - `SuggestTagsQueryHandler` — оборачивает Finder, конвертит entities в DTO через `CoatingTagDTOTransformer`.

- [ ] **Step 1: Написать функциональный тест для CoatingTagFinder**

Создать `app/tests/Functional/Coatings/Infrastructure/Search/CoatingTagFinderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Coatings\Infrastructure\Search\CoatingTagFinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CoatingTagFinderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingTagFinder $finder;
    private CoatingTagRepositoryInterface $repo;
    private CoatingTagSpecification $spec;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->finder = $container->get(CoatingTagFinder::class);
        $this->repo = $container->get(CoatingTagRepositoryInterface::class);
        $this->spec = $container->get(CoatingTagSpecification::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $tag = $this->repo->findOneById($id);
            if ($tag !== null) {
                $this->em->remove($tag);
            }
        }
        $this->em->flush();
        $this->em->clear();
        parent::tearDown();
    }

    public function testSuggestReturnsGeneralTagsByPrefix(): void
    {
        $forConcrete = $this->makeTag('Для бетона', CoatingTag::TYPE_GENERAL);
        $forSteel = $this->makeTag('Для стали', CoatingTag::TYPE_GENERAL);
        $topTag = $this->makeTag('top_test_unique', 'CoatingCoatType');

        $result = $this->finder->suggest('для', CoatingTag::TYPE_GENERAL);

        $titles = array_map(fn(CoatingTag $t) => $t->getTitle(), $result);
        self::assertContains('Для бетона', $titles);
        self::assertContains('Для стали', $titles);
        self::assertNotContains('top_test_unique', $titles, 'Не general — не должен попасть');
    }

    public function testSuggestFallsBackToFuzzyWhenFtsEmpty(): void
    {
        $this->makeTag('Для бетона', CoatingTag::TYPE_GENERAL);

        // 'бетано' — опечатка, FTS prefix не сматчится; fuzzy должен поймать.
        $result = $this->finder->suggest('бетано', CoatingTag::TYPE_GENERAL);

        $titles = array_map(fn(CoatingTag $t) => $t->getTitle(), $result);
        self::assertContains('Для бетона', $titles);
    }

    public function testSuggestEmptyQueryReturnsEmpty(): void
    {
        $this->makeTag('Для бетона', CoatingTag::TYPE_GENERAL);

        self::assertSame([], $this->finder->suggest('', CoatingTag::TYPE_GENERAL));
    }

    public function testSuggestRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeTag('Для теста ' . $i, CoatingTag::TYPE_GENERAL);
        }

        $result = $this->finder->suggest('для теста', CoatingTag::TYPE_GENERAL, limit: 2);

        self::assertCount(2, $result);
    }

    private function makeTag(string $title, ?string $type): CoatingTag
    {
        $tag = new CoatingTag($title, $this->spec, $type);
        $this->repo->add($tag);
        $this->createdIds[] = $tag->getId();
        return $tag;
    }
}
```

- [ ] **Step 2: Запустить тест, убедиться что падает**

```bash
vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Search/CoatingTagFinderTest.php
```

Expected: ERROR — `Class "App\Coatings\Infrastructure\Search\CoatingTagFinder" not found`.

- [ ] **Step 3: Создать `CoatingTagFinder`**

Создать `app/src/Coatings/Infrastructure/Search/CoatingTagFinder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Search-сервис для CoatingTag: prefix-FTS по title + fuzzy-fallback (pg_trgm).
 * Используется suggest-эндпоинтом для Tagify-autocomplete.
 */
final class CoatingTagFinder
{
    private const FTS_LANG = 'russian';
    private const FUZZY_SIMILARITY_THRESHOLD = 0.4;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<CoatingTag>
     */
    public function suggest(string $query, ?string $type, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $ftsResults = $this->fullText($query, $type, $limit);
        if ($ftsResults !== []) {
            return $ftsResults;
        }

        return $this->fuzzyTitle($query, $type, $limit);
    }

    /**
     * @return list<CoatingTag>
     */
    private function fullText(string $query, ?string $type, int $limit): array
    {
        $tsquery = $this->buildPrefixTsQuery($query);
        if ($tsquery === '') {
            return [];
        }

        $qb = $this->coatingTagQueryBuilder();
        $qb->andWhere("TS_MATCH(TO_TSVECTOR(:lang, t.title), TO_TSQUERY(:lang, :tsquery)) = TRUE")
            ->addSelect("TS_RANK_CD(TO_TSVECTOR(:lang, t.title), TO_TSQUERY(:lang, :tsquery)) AS HIDDEN fts_rank")
            ->orderBy('fts_rank', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('lang', self::FTS_LANG)
            ->setParameter('tsquery', $tsquery);

        $this->applyTypeFilter($qb, $type);

        return array_values($qb->getQuery()->getResult());
    }

    /**
     * @return list<CoatingTag>
     */
    private function fuzzyTitle(string $query, ?string $type, int $limit): array
    {
        $similarity = 'WORD_SIMILARITY(:search, t.title)';

        $qb = $this->coatingTagQueryBuilder();
        $qb->andWhere($similarity . ' > :threshold')
            ->addSelect($similarity . ' AS HIDDEN sim')
            ->orderBy('sim', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('search', $query)
            ->setParameter('threshold', self::FUZZY_SIMILARITY_THRESHOLD);

        $this->applyTypeFilter($qb, $type);

        return array_values($qb->getQuery()->getResult());
    }

    private function applyTypeFilter(QueryBuilder $qb, ?string $type): void
    {
        if ($type === null) {
            return;
        }
        $qb->andWhere('t.type = :type')->setParameter('type', $type);
    }

    private function coatingTagQueryBuilder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(CoatingTag::class, 't');
    }

    /**
     * Превращает пользовательский ввод в безопасный tsquery с префиксным сопоставлением.
     */
    private function buildPrefixTsQuery(string $query): string
    {
        $sanitized = preg_replace('/[&|!()<>:\'"\\\\*]/u', ' ', $query) ?? '';
        $words = preg_split('/[\s\-.,;]+/u', trim($sanitized), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '';
        }

        return implode(' & ', array_map(static fn(string $word) => $word . ':*', $words));
    }
}
```

- [ ] **Step 4: Запустить тест — должен пройти**

```bash
vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Search/CoatingTagFinderTest.php
```

Expected: `OK (4 tests, ≥4 assertions)`. Если падает — проверь, что `TS_MATCH` / `TO_TSVECTOR` / `WORD_SIMILARITY` зарегистрированы как DQL-функции (они уже использовались в `CoatingFinder`, должны быть в `config/packages/doctrine.yaml`). Если нет — добавить там в `dql.string_functions`.

- [ ] **Step 5: Создать `SuggestTagsQuery` + Result + Handler**

Создать `app/src/Coatings/Application/UseCase/Query/SuggestTags/SuggestTagsQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestTags;

use App\Shared\Application\Query\Query;

final readonly class SuggestTagsQuery extends Query
{
    public function __construct(
        public string $query,
        public ?string $type = null,
        public int $limit = 10,
    ) {
    }
}
```

Создать `app/src/Coatings/Application/UseCase/Query/SuggestTags/SuggestTagsQueryResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestTags;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;

final readonly class SuggestTagsQueryResult
{
    /** @param list<CoatingTagDTO> $tags */
    public function __construct(public array $tags)
    {
    }
}
```

Создать `app/src/Coatings/Application/UseCase/Query/SuggestTags/SuggestTagsQueryHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestTags;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTOTransformer;
use App\Coatings\Infrastructure\Search\CoatingTagFinder;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SuggestTagsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingTagFinder $finder,
        private CoatingTagDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestTagsQuery $query): SuggestTagsQueryResult
    {
        $tags = $this->finder->suggest($query->query, $query->type, $query->limit);
        $dtos = $this->transformer->fromEntityList($tags);

        return new SuggestTagsQueryResult($dtos);
    }
}
```

- [ ] **Step 6: Прогнать весь юнит + функциональный набор coatings**

```bash
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Functional/Coatings
```

Expected: всё зелёное.

- [ ] **Step 7: Commit checkpoint (user)**

User commits 5 new files (Finder + Query/Result/Handler + Test). Suggested message: `feat(coating-tag): add CoatingTagFinder (FTS+fuzzy) + SuggestTags query`.

---

### Task 4: `SuggestTagsAction` controller

**Files:**
- Create: `app/src/Coatings/Infrastructure/Controller/CoatingTag/SuggestTagsAction.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingTag/SuggestTagsActionTest.php`

**Interfaces:**
- Consumes: `SuggestTagsQuery` + `SuggestTagsQueryHandler` из Task 3 (через `QueryBus`).
- Produces: HTTP роут `GET /cabinet/coating/coating-tag/suggest?q=<query>&type=<type>` (name `app_cabinet_coating_coating_tag_suggest`). JSON-ответ `[{id: '...', title: '...'}, ...]`. Требует `ROLE_ADMIN`.

- [ ] **Step 1: Прочитать существующий controller pattern**

Read `app/src/Coatings/Infrastructure/Controller/Coating/CompareAction.php` или `ListAction.php` для понимания стиля контроллеров проекта.

- [ ] **Step 2: Создать SuggestTagsAction**

Создать `app/src/Coatings/Infrastructure/Controller/CoatingTag/SuggestTagsAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingTag;

use App\Coatings\Application\UseCase\Query\SuggestTags\SuggestTagsQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/coating/coating-tag/suggest',
    name: 'app_cabinet_coating_coating_tag_suggest',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class SuggestTagsAction extends AbstractController
{
    private const MAX_LIMIT = 25;
    private const DEFAULT_LIMIT = 10;

    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $type = $request->query->get('type');
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

        if ($q === '') {
            return new JsonResponse([]);
        }

        /** @var \App\Coatings\Application\UseCase\Query\SuggestTags\SuggestTagsQueryResult $result */
        $result = $this->queryBus->execute(new SuggestTagsQuery($q, $type ?: null, $limit));

        $payload = array_map(
            static fn($dto) => ['id' => $dto->id, 'title' => $dto->title],
            $result->tags,
        );

        return new JsonResponse($payload);
    }
}
```

- [ ] **Step 3: Написать функциональный тест**

Создать `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingTag/SuggestTagsActionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingTag;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SuggestTagsActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    /** @var list<string> */
    private array $createdTagIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'suggest_tags_' . $suffix . '@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);
        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setAccessible(true);
        $rolesRef->setValue($user, ['ROLE_ADMIN']);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $repo = static::getContainer()->get(CoatingTagRepositoryInterface::class);
            foreach ($this->createdTagIds as $id) {
                $tag = $repo->findOneById($id);
                if ($tag !== null) {
                    $em->remove($tag);
                }
            }
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if ($user !== null) {
                $em->remove($user);
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, "tearDown cleanup error: " . $e->getMessage() . "\n");
        }
        parent::tearDown();
    }

    public function testReturnsGeneralTagsByPrefix(): void
    {
        $this->makeTag('Для бетона test', CoatingTag::TYPE_GENERAL);
        $this->makeTag('Для стали test', CoatingTag::TYPE_GENERAL);

        $this->client->request('GET', '/cabinet/coating/coating-tag/suggest?q=для&type=general');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $titles = array_column($payload, 'title');
        self::assertContains('Для бетона test', $titles);
        self::assertContains('Для стали test', $titles);
    }

    public function testEmptyQueryReturnsEmptyArray(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-tag/suggest?q=&type=general');

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testEachItemHasIdAndTitle(): void
    {
        $tag = $this->makeTag('Уникальный тег xyz', CoatingTag::TYPE_GENERAL);

        $this->client->request('GET', '/cabinet/coating/coating-tag/suggest?q=уникальный&type=general');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload);
        $first = $payload[0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('title', $first);
    }

    private function makeTag(string $title, ?string $type): CoatingTag
    {
        $container = $this->client->getContainer();
        $spec = $container->get(CoatingTagSpecification::class);
        $tag = new CoatingTag($title, $spec, $type);
        $container->get(CoatingTagRepositoryInterface::class)->add($tag);
        $this->createdTagIds[] = $tag->getId();
        return $tag;
    }
}
```

- [ ] **Step 4: Запустить тест**

```bash
vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingTag/SuggestTagsActionTest.php
```

Expected: `OK (3 tests, ≥3 assertions)`.

- [ ] **Step 5: Smoke-чек руками (опционально)**

```bash
bin/console debug:router | grep coating_tag_suggest
```

Expected: видно роут `app_cabinet_coating_coating_tag_suggest` `GET ANY /cabinet/coating/coating-tag/suggest`.

- [ ] **Step 6: Прогнать весь функциональный набор coatings**

```bash
vendor/bin/phpunit tests/Functional/Coatings
```

Expected: всё зелёное.

- [ ] **Step 7: Commit checkpoint (user)**

User commits 2 new files (controller + test). Suggested message: `feat(coating-tag): add SuggestTagsAction GET /cabinet/coating/coating-tag/suggest`.

---

### Task 5: `CreateGeneralTag` command/handler + controller

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Command/CreateGeneralTag/CreateGeneralTagCommand.php`
- Create: `app/src/Coatings/Application/UseCase/Command/CreateGeneralTag/CreateGeneralTagCommandResult.php`
- Create: `app/src/Coatings/Application/UseCase/Command/CreateGeneralTag/CreateGeneralTagCommandHandler.php`
- Create: `app/src/Coatings/Infrastructure/Controller/CoatingTag/CreateGeneralTagAction.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingTag/CreateGeneralTagActionTest.php`

**Interfaces:**
- Consumes: `CoatingTagRepositoryInterface::add` и `findOneByTitleAndType` (Task 1); `CoatingTagSpecification` (DI).
- Produces:
  - `CreateGeneralTagCommand(public string $title)`.
  - `CreateGeneralTagCommandResult(public string $id, public string $title)`.
  - `CreateGeneralTagCommandHandler` — проверяет, что (title, general) ещё нет; создаёт `new CoatingTag($title, $spec, CoatingTag::TYPE_GENERAL)`; вызывает `repo->add()`.
  - HTTP роут `POST /cabinet/coating/coating-tag` (name `app_cabinet_coating_coating_tag_create`). JSON body `{title: "..."}`. JSON ответ `{id: "...", title: "..."}` со статусом 201 при успехе, 422 при дубликате.

- [ ] **Step 1: Создать Command + Result**

Создать `app/src/Coatings/Application/UseCase/Command/CreateGeneralTag/CreateGeneralTagCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateGeneralTag;

use App\Shared\Application\Command\Command;

final readonly class CreateGeneralTagCommand extends Command
{
    public function __construct(public string $title)
    {
    }
}
```

Создать `app/src/Coatings/Application/UseCase/Command/CreateGeneralTag/CreateGeneralTagCommandResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateGeneralTag;

final readonly class CreateGeneralTagCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
```

- [ ] **Step 2: Создать Handler**

Создать `app/src/Coatings/Application/UseCase/Command/CreateGeneralTag/CreateGeneralTagCommandHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateGeneralTag;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class CreateGeneralTagCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingTagRepositoryInterface $repository,
        private CoatingTagSpecification $specification,
    ) {
    }

    public function __invoke(CreateGeneralTagCommand $command): CreateGeneralTagCommandResult
    {
        $title = trim($command->title);
        if ($title === '') {
            throw new AppException('Название тега не может быть пустым.');
        }

        $existing = $this->repository->findOneByTitleAndType($title, CoatingTag::TYPE_GENERAL);
        if ($existing !== null) {
            throw new AppException(sprintf('Тег «%s» уже существует.', $title));
        }

        $tag = new CoatingTag($title, $this->specification, CoatingTag::TYPE_GENERAL);
        $this->repository->add($tag);

        return new CreateGeneralTagCommandResult($tag->getId(), $tag->getTitle());
    }
}
```

- [ ] **Step 3: Создать controller**

Создать `app/src/Coatings/Infrastructure/Controller/CoatingTag/CreateGeneralTagAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingTag;

use App\Coatings\Application\UseCase\Command\CreateGeneralTag\CreateGeneralTagCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/coating/coating-tag',
    name: 'app_cabinet_coating_coating_tag_create',
    methods: ['POST'],
)]
#[IsGranted('ROLE_ADMIN')]
final class CreateGeneralTagAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $title = is_array($payload) ? (string) ($payload['title'] ?? '') : '';

        try {
            /** @var \App\Coatings\Application\UseCase\Command\CreateGeneralTag\CreateGeneralTagCommandResult $result */
            $result = $this->commandBus->execute(new CreateGeneralTagCommand($title));
        } catch (AppException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            ['id' => $result->id, 'title' => $result->title],
            Response::HTTP_CREATED,
        );
    }
}
```

- [ ] **Step 4: Написать функциональный тест**

Создать `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingTag/CreateGeneralTagActionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingTag;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CreateGeneralTagActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    /** @var list<string> */
    private array $titlesToCleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'create_tag_' . $suffix . '@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);
        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setAccessible(true);
        $rolesRef->setValue($user, ['ROLE_ADMIN']);

        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $repo = static::getContainer()->get(CoatingTagRepositoryInterface::class);
            foreach ($this->titlesToCleanup as $title) {
                $tag = $repo->findOneByTitleAndType($title, CoatingTag::TYPE_GENERAL);
                if ($tag !== null) {
                    $em->remove($tag);
                }
            }
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if ($user !== null) {
                $em->remove($user);
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, "tearDown cleanup error: " . $e->getMessage() . "\n");
        }
        parent::tearDown();
    }

    public function testCreatesNewGeneralTag(): void
    {
        $title = 'Для бетона unique-' . uniqid('', false);
        $this->titlesToCleanup[] = $title;

        $this->client->request(
            'POST',
            '/cabinet/coating/coating-tag',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => $title]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $payload);
        self::assertSame($title, $payload['title']);

        $repo = static::getContainer()->get(CoatingTagRepositoryInterface::class);
        $tag = $repo->findOneByTitleAndType($title, CoatingTag::TYPE_GENERAL);
        self::assertNotNull($tag);
        self::assertSame(CoatingTag::TYPE_GENERAL, $tag->getType());
    }

    public function testRejectsDuplicate(): void
    {
        $title = 'Дубль-' . uniqid('', false);
        $this->titlesToCleanup[] = $title;

        // Первый раз — успешно.
        $this->client->request(
            'POST',
            '/cabinet/coating/coating-tag',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => $title]),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Второй раз — 422.
        $this->client->request(
            'POST',
            '/cabinet/coating/coating-tag',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => $title]),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $payload);
        self::assertStringContainsString('уже существует', $payload['error']);
    }

    public function testRejectsEmptyTitle(): void
    {
        $this->client->request(
            'POST',
            '/cabinet/coating/coating-tag',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => '   ']),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
```

- [ ] **Step 5: Запустить функциональный тест**

```bash
vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingTag/CreateGeneralTagActionTest.php
```

Expected: `OK (3 tests, ≥3 assertions)`.

- [ ] **Step 6: Прогнать всё**

```bash
vendor/bin/phpunit tests/Functional/Coatings
vendor/bin/phpunit tests/Unit
```

Expected: всё зелёное.

- [ ] **Step 7: Commit checkpoint (user)**

User commits 5 new files (Command/Result/Handler + Controller + Test). Suggested message: `feat(coating-tag): add CreateGeneralTagAction POST /cabinet/coating/coating-tag`.

---

### Task 6: Tagify в форме покрытия + e2e FTS-тест

**Files:**
- Modify: `app/package.json`
- Modify: `app/assets/app.js` (если там import css)
- Create: `app/assets/controllers/coating_tags_controller.js`
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig` (заменить блок tags)
- Test: `app/tests/Functional/Coatings/Infrastructure/Search/CoatingFinderFtsTagTest.php`

**Interfaces:**
- Consumes: `app_cabinet_coating_coating_tag_suggest` (Task 4) и `app_cabinet_coating_coating_tag_create` (Task 5) роуты.
- Produces: Tagify-инпут в форме покрытия. При сабмите формы шлёт hidden inputs `tags[N][id]=...` (только id, без title) — совместимо с существующим `CoatingMapper` без правок mapper'а.

- [ ] **Step 1: Установить Tagify**

```bash
cd /Users/nikolay_vanzhin/PhpstormProjects/coating-monolith/app
yarn add @yaireo/tagify
```

Expected: пакет добавлен в `package.json`.

- [ ] **Step 2: Импортить Tagify CSS**

Открыть `app/assets/app.js`. Добавить в начало (или туда, где импортятся CSS-стили):

```js
import '@yaireo/tagify/dist/tagify.css';
```

- [ ] **Step 3: Создать Stimulus-контроллер**

Создать `app/assets/controllers/coating_tags_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Tagify-инпут для general-тегов покрытия.
 * Использует два AJAX-эндпоинта:
 *   - GET suggest для autocomplete по существующим тегам.
 *   - POST create для явного создания нового general-тега.
 *
 * При сабмите формы Tagify отдаёт массив скрытых input'ов `tags[N][id]=...`.
 * Title в форму не уходит — теги к этому моменту уже существуют в БД.
 */
export default class extends Controller {
    static values = {
        existing: { type: Array, default: [] },
        suggestUrl: { type: String, default: '/cabinet/coating/coating-tag/suggest' },
        createUrl: { type: String, default: '/cabinet/coating/coating-tag' },
    };

    connect() {
        this.tagify = new Tagify(this.element, {
            enforceWhitelist: true,
            whitelist: [],
            dropdown: {
                enabled: 1,
                maxItems: 10,
                closeOnSelect: true,
                searchKeys: ['value'],
            },
            templates: {
                dropdownItemNoMatch: (data) => this._noMatchTemplate(data),
            },
            tagTextProp: 'value',
        });

        this._debounceTimer = null;

        // Заполняем initial-значения, если есть.
        if (this.existingValue.length) {
            this.tagify.addTags(this.existingValue.map(t => ({ value: t.title, id: t.id })));
        }

        this.tagify.on('input', this._onInput.bind(this));
        this.tagify.on('change', this._renderHiddenInputs.bind(this));
        this.tagify.DOM.scope.addEventListener('click', this._onDropdownClick.bind(this));

        this._renderHiddenInputs();
    }

    disconnect() {
        if (this.tagify) {
            this.tagify.destroy();
        }
        if (this._debounceTimer) {
            clearTimeout(this._debounceTimer);
        }
    }

    _onInput(e) {
        const query = e.detail.value || '';
        if (this._debounceTimer) clearTimeout(this._debounceTimer);
        this._debounceTimer = setTimeout(() => this._fetchSuggest(query), 250);
    }

    async _fetchSuggest(query) {
        if (!query) {
            this.tagify.whitelist = [];
            this.tagify.dropdown.refilter.call(this.tagify, query);
            return;
        }
        try {
            const url = new URL(this.suggestUrlValue, window.location.origin);
            url.searchParams.set('q', query);
            url.searchParams.set('type', 'general');
            const resp = await fetch(url, { credentials: 'same-origin' });
            if (!resp.ok) return;
            const items = await resp.json();
            this.tagify.whitelist = items.map(t => ({ value: t.title, id: t.id }));
            this.tagify.dropdown.refilter.call(this.tagify, query);
        } catch (e) {
            // Сетевые ошибки — молча, тег создать всё ещё можно через клик «Создать».
        }
    }

    _noMatchTemplate(data) {
        const title = (data.value || '').replace(/[<>&"']/g, '');
        return `
            <div class="tagify__dropdown__item tagify-create-item" data-create-title="${title}">
                + Создать «${title}»
            </div>
        `;
    }

    async _onDropdownClick(event) {
        const createItem = event.target.closest('.tagify-create-item');
        if (!createItem) return;
        event.preventDefault();
        const title = createItem.dataset.createTitle;
        if (!title) return;

        try {
            const resp = await fetch(this.createUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title }),
            });
            if (resp.status === 201) {
                const created = await resp.json();
                this.tagify.addTags([{ value: created.title, id: created.id }]);
                this.tagify.dropdown.hide.call(this.tagify);
            } else if (resp.status === 422) {
                const err = await resp.json();
                alert(err.error || 'Ошибка создания тега.');
            } else {
                alert('Не удалось создать тег.');
            }
        } catch (e) {
            alert('Сетевая ошибка при создании тега.');
        }
    }

    _renderHiddenInputs() {
        // Удаляем старые скрытые inputs.
        const formGroup = this.element.closest('[data-coating-tags-group]') || this.element.parentElement;
        formGroup.querySelectorAll('input.coating-tag-hidden').forEach(el => el.remove());

        // Создаём по одному hidden на каждый chip.
        const values = this.tagify.value || [];
        values.forEach((v, i) => {
            if (!v.id) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `tags[${i}][id]`;
            input.value = v.id;
            input.className = 'coating-tag-hidden';
            formGroup.appendChild(input);
        });
    }
}
```

- [ ] **Step 4: Заменить блок тегов в `form.html.twig`**

Найти в `app/src/Shared/Infrastructure/Templates/admin/coating/coating/form.html.twig` блок, где сейчас рендерится `<select>` тегов (поиск по `name="tags"` или `tags[`). Заменить на:

```twig
                    <div class="mb-3" data-coating-tags-group>
                        <label class="form-label">Теги</label>
                        <input type="text"
                               data-controller="coating-tags"
                               data-coating-tags-existing-value="{{ existingTagsJson|default('[]') }}"
                               data-coating-tags-suggest-url-value="{{ path('app_cabinet_coating_coating_tag_suggest') }}"
                               data-coating-tags-create-url-value="{{ path('app_cabinet_coating_coating_tag_create') }}"
                               class="form-control"
                               placeholder="Начните вводить тег...">
                        <div class="form-text">Привязанные general-теги. Начните вводить — autocomplete покажет похожие; если ничего не найдено — кликните «Создать "..."».</div>
                    </div>
```

В контроллере, который рендерит форму (создания/редактирования покрытия), нужно передать в шаблон `existingTagsJson` — JSON-массив `{id, title}` уже привязанных к покрытию general-тегов. Найти контроллер:

```bash
grep -rn "form.html.twig" src/Coatings/Infrastructure/Controller/Coating/
```

В соответствующих action (CreateAction / UpdateAction) добавить в render-params:

```php
'existingTagsJson' => json_encode(array_map(
    static fn($tagDto) => ['id' => $tagDto->id, 'title' => $tagDto->title],
    array_filter($coatingDto->tags ?? [], static fn($t) => $t->type === \App\Coatings\Domain\Aggregate\Coating\CoatingTag::TYPE_GENERAL),
), JSON_UNESCAPED_UNICODE),
```

(Если в форме НЕ только general-теги, а ещё и CoatType/ProtectionType — оставь существующий select для них как есть, Tagify используется ТОЛЬКО для general).

- [ ] **Step 5: Пересобрать ассеты**

```bash
yarn dev
```

Expected: `webpack compiled successfully`. Если падает — проверь импорт `@yaireo/tagify` и `tagify.css`.

- [ ] **Step 6: Написать e2e функциональный тест на FTS-поиск coatings по тегу**

Создать `app/tests/Functional/Coatings/Infrastructure/Search/CoatingFinderFtsTagTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Coatings\Infrastructure\Search\CoatingFinder;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingFinderFtsTagTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingFinder $finder;
    private string $coatingId;
    private string $manufacturerId;
    private string $tagId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->finder = $container->get(CoatingFinder::class);

        $suffix = uniqid('', true);

        // Manufacturer
        $manufacturer = new Manufacturer('TestMfg_' . $suffix, $container->get(ManufacturerSpecification::class));
        $this->em->persist($manufacturer);

        // General tag
        $tag = new CoatingTag('Для бетона FTS_' . $suffix, $container->get(CoatingTagSpecification::class), CoatingTag::TYPE_GENERAL);
        $container->get(CoatingTagRepositoryInterface::class)->add($tag);

        // Coating без бетона в title/desc — должно матчиться только за счёт тега.
        $coating = new Coating(
            UuidService::generateUuid(),
            'NeutralTitle_' . $suffix,
            'Описание не содержит бетона.',
            50,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 240))),
            null,
            1.0,
            null,
            $manufacturer,
            $container->get(CoatingSpecification::class),
        );
        $coating->replaceTags([$tag]);
        $container->get(CoatingRepositoryInterface::class)->add($coating);
        $this->em->flush();

        $this->coatingId = $coating->getId();
        $this->manufacturerId = $manufacturer->getId();
        $this->tagId = $tag->getId();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $coating = $em->find(Coating::class, Uuid::fromString($this->coatingId));
            if ($coating !== null) { $em->remove($coating); }
            $tag = static::getContainer()->get(CoatingTagRepositoryInterface::class)->findOneById($this->tagId);
            if ($tag !== null) { $em->remove($tag); }
            $manufacturer = $em->find(Manufacturer::class, Uuid::fromString($this->manufacturerId));
            if ($manufacturer !== null) { $em->remove($manufacturer); }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, "tearDown cleanup error: " . $e->getMessage() . "\n");
        }
        parent::tearDown();
    }

    public function testFtsFindsCoatingByTagTitle(): void
    {
        // Поиск по слову «бетона» — title/desc не содержат, но тег содержит → должен найти.
        $filter = new CoatingsFilter('бетона', \App\Shared\Domain\Repository\Pager::fromPage(1, 50));
        $result = $this->finder->fullText($filter);

        $ids = array_map(fn(Coating $c) => $c->getId(), $result->items);
        self::assertContains(
            $this->coatingId,
            $ids,
            'Покрытие должно находиться по тексту тега, даже если title/description нейтральны.',
        );
    }
}
```

- [ ] **Step 7: Запустить e2e тест**

```bash
vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Search/CoatingFinderFtsTagTest.php
```

Expected: `OK (1 test, ≥1 assertion)`. Если падает на «не найдено» — проверь что миграция Task 2 применилась (`bin/console doctrine:migrations:status`) и что триггер пересоздаёт вектор при INSERT в pivot. Если падает на construction сoating'а — проверь сигнатуру `Coating::__construct` (могла измениться с момента написания плана).

- [ ] **Step 8: Прогнать всё**

```bash
vendor/bin/phpunit tests/Functional/Coatings
vendor/bin/phpunit tests/Unit
yarn dev
```

Expected: всё зелёное. webpack успешен.

- [ ] **Step 9: Manual smoke check (опционально)**

1. Hard-reload форму редактирования покрытия.
2. Кликнуть в Tagify-инпут «Теги», начать писать «для» → должны появиться существующие general-теги в dropdown.
3. Ввести новое слово, которого нет → в dropdown'е появляется «+ Создать "..."». Клик → AJAX → чип добавлен.
4. Сохранить покрытие. Проверить, что тег привязан (в БД pivot row создан).
5. На list-странице ввести в основной search-bar текст тега → покрытие появляется в результатах.

- [ ] **Step 10: Commit checkpoint (user)**

User commits: package.json/yarn.lock (tagify dep), assets/app.js (импорт css), новый Stimulus-контроллер, form.html.twig (Tagify-блок), новый e2e тест. Suggested message: `feat(coating-tag): Tagify form input + e2e FTS coverage for tag-based search`.

---

## Done

После Task 6: user пишет «для бетона» в основном поисковом окне списка покрытий — находит покрытия с соответствующим general-тегом. Админ через Tagify в форме покрытия выбирает существующий тег или создаёт новый через явный AJAX-вызов. Никакого `resolveOrCreate` в Coating handler'е.

Следующие итерации (вне scope):
- Расширенный поиск с фасетами (ТСП, мин Т нанесения, dft).
- Управление general-тегами в админке (rename, delete, merge).
- Семантические synonyms (если PG-словаря russian станет недостаточно).
