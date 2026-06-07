# Extended Coating Search — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить фасет «производитель» поверх существующего FTS-поиска по покрытиям. Множественный выбор через чекбоксы; работает вместе с текстовым запросом (AND); расширенный блок раскрывается через Bootstrap collapse.

**Architecture:** Один SQL-запрос: FTS-условие и фасеты — `andWhere`-цепочка одного QueryBuilder. Фасеты вынесены в приватные методы `applyXxxFacet` для аккуратного роста. UI без JS — Bootstrap collapse + чекбоксы. Невалидные UUID молча игнорируются; невалидная длина search кидает `AppException`.

**Tech Stack:** PHP 8.3, Symfony, Doctrine ORM 3.1, PostgreSQL 17 (pg_trgm + FTS уже настроены), PHPUnit 9.5, Bootstrap 5 (collapse, формы).

**Spec:** `docs/superpowers/specs/2026-06-07-coating-extended-search-design.md`

**Note:** Пользователь сам управляет коммитами — шаги «git add / git commit» в этом плане **не выполняются**, после каждой задачи только запускаются тесты для подтверждения.

---

## File Structure

### Создаются

| Файл | Ответственность |
|---|---|
| `app/tests/Unit/Coatings/Domain/Repository/CoatingsFilterTest.php` | Тесты нормализации `manufacturerIds` (валидный UUID, мусор, дубликаты, пустой массив) |

### Модифицируются

| Файл | Что меняется |
|---|---|
| `app/src/Coatings/Domain/Repository/CoatingsFilter.php` | Добавляется `array $manufacturerIds` + `normalizeManufacturerIds()` |
| `app/src/Coatings/Infrastructure/Search/CoatingFinder.php` | `fullText`/`fuzzyTitle` принимают `CoatingsFilter` целиком; новые приватные методы `applyFtsClause`, `applyFacets`, `applyManufacturerFacet` |
| `app/src/Coatings/Infrastructure/Repository/CoatingRepository.php` | `findByFilter` упрощается (нормализация ушла в Filter), `normalizeSearch` удаляется |
| `app/src/Coatings/Infrastructure/Controller/Coating/SearchAction.php` | Читает `?manufacturerIds[]`, грузит производителей через `GetPagedManufacturersQuery`, использует флаг `$hasAnyCriterion` |
| `app/src/Shared/Infrastructure/Templates/admin/coating/coating/search.html.twig` | Collapse-блок «Расширенный поиск» с чекбоксами производителей, бэйджем и «Сбросить» |

---

## Conventions

**Запуск тестов** (через docker-compose):

```bash
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit/path/to/Test.php
```

**Очистка кеша Symfony:**

```bash
docker-compose exec -T manager_php-fpm php bin/console cache:clear --env=dev
```

**Smoke-тест поискового SQL через DQL** (без HTTP):

```bash
docker-compose exec -T manager_php-fpm php bin/console doctrine:query:dql "DQL_HERE" --hydrate=array
```

---

## Task 1: CoatingsFilter — `manufacturerIds`

**Files:**
- Modify: `app/src/Coatings/Domain/Repository/CoatingsFilter.php`
- Create: `app/tests/Unit/Coatings/Domain/Repository/CoatingsFilterTest.php`

- [ ] **Step 1: Написать тесты для нормализации `manufacturerIds`**

`app/tests/Unit/Coatings/Domain/Repository/CoatingsFilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

class CoatingsFilterTest extends TestCase
{
    private const VALID_UUID_A = '11111111-1111-4111-8111-111111111111';
    private const VALID_UUID_B = '22222222-2222-4222-8222-222222222222';

    public function testDefaultsAreEmptyAndNull(): void
    {
        $filter = new CoatingsFilter();
        $this->assertNull($filter->search);
        $this->assertSame([], $filter->manufacturerIds);
        $this->assertNull($filter->pager);
    }

    public function testManufacturerIdsAcceptValidUuids(): void
    {
        $filter = new CoatingsFilter(
            manufacturerIds: [self::VALID_UUID_A, self::VALID_UUID_B],
        );
        $this->assertSame([self::VALID_UUID_A, self::VALID_UUID_B], $filter->manufacturerIds);
    }

    public function testManufacturerIdsDropInvalidEntries(): void
    {
        $filter = new CoatingsFilter(
            manufacturerIds: [self::VALID_UUID_A, 'not-a-uuid', '', null, 123],
        );
        $this->assertSame([self::VALID_UUID_A], $filter->manufacturerIds);
    }

    public function testManufacturerIdsDeduplicate(): void
    {
        $filter = new CoatingsFilter(
            manufacturerIds: [self::VALID_UUID_A, self::VALID_UUID_A, self::VALID_UUID_B],
        );
        $this->assertSame([self::VALID_UUID_A, self::VALID_UUID_B], $filter->manufacturerIds);
    }

    public function testManufacturerIdsEmptyWhenAllInvalid(): void
    {
        $filter = new CoatingsFilter(manufacturerIds: ['x', 'y']);
        $this->assertSame([], $filter->manufacturerIds);
    }

    public function testSearchShortStillThrows(): void
    {
        $this->expectException(AppException::class);
        new CoatingsFilter(search: 'ва');
    }

    public function testValidSearchAndFacetTogether(): void
    {
        $filter = new CoatingsFilter(
            search: 'эпоксидная',
            manufacturerIds: [self::VALID_UUID_A],
        );
        $this->assertSame('эпоксидная', $filter->search);
        $this->assertSame([self::VALID_UUID_A], $filter->manufacturerIds);
    }
}
```

- [ ] **Step 2: Запустить — должно FAIL (`manufacturerIds` ещё не существует)**

```bash
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit/Coatings/Domain/Repository/CoatingsFilterTest.php
```

Ожидаемый вывод: ошибки про неизвестное свойство `manufacturerIds` или unknown named argument.

- [ ] **Step 3: Обновить `CoatingsFilter` — добавить `manufacturerIds` + нормализацию**

`app/src/Coatings/Domain/Repository/CoatingsFilter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

readonly class CoatingsFilter
{
    /**
     * Разрешённый диапазон длины поискового запроса.
     * Короче — бессмысленно (стеммер съест в стоп-слова, триграммы дадут мусор).
     * Длиннее — защита от случайного абзаца / DoS на FTS.
     */
    private const MIN_SEARCH_LENGTH = 3;
    private const MAX_SEARCH_LENGTH = 50;

    public ?string $search;

    /** @var list<string> Список UUID производителей. Пустой массив — фасет не применяется. */
    public array $manufacturerIds;

    public ?Pager $pager;

    public function __construct(
        ?string $search = null,
        array $manufacturerIds = [],
        ?Pager $pager = null,
    ) {
        $this->search = $this->normalizeSearch($search);
        $this->manufacturerIds = $this->normalizeManufacturerIds($manufacturerIds);
        $this->pager = $pager;
    }

    private function normalizeSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }
        $trimmed = trim($search);
        if ($trimmed === '') {
            return null;
        }
        $length = mb_strlen($trimmed);
        if ($length < self::MIN_SEARCH_LENGTH || $length > self::MAX_SEARCH_LENGTH) {
            throw new AppException(sprintf(
                'Длина поискового запроса должна быть от %d до %d символов.',
                self::MIN_SEARCH_LENGTH,
                self::MAX_SEARCH_LENGTH,
            ));
        }

        return $trimmed;
    }

    /**
     * Принимает что угодно из GET-параметра, оставляет только валидные UUID-строки.
     * Дубли удаляются, порядок сохраняется.
     *
     * @param array<int|string, mixed> $ids
     * @return list<string>
     */
    private function normalizeManufacturerIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_string($id) && Uuid::isValid($id)) {
                $clean[] = $id;
            }
        }

        return array_values(array_unique($clean));
    }
}
```

- [ ] **Step 4: Запустить тесты — должны пройти**

```bash
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit/Coatings/Domain/Repository/CoatingsFilterTest.php
```

Ожидаемый вывод: `OK (7 tests, ...)`.

---

## Task 2: CoatingFinder — `applyFacets` + `applyManufacturerFacet`

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Search/CoatingFinder.php`

- [ ] **Step 1: Переписать `CoatingFinder` целиком**

`app/src/Coatings/Infrastructure/Search/CoatingFinder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingSearch;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Сервис read-side для поиска покрытий.
 * Принимает CoatingsFilter целиком: FTS-условие и фасеты строятся в одном QueryBuilder.
 */
final class CoatingFinder
{
    private const FTS_LANG = 'russian';
    private const FUZZY_SIMILARITY_THRESHOLD = 0.4;
    private const FUZZY_LIMIT = 10;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function fullText(CoatingsFilter $filter): PaginationResult
    {
        $qb = $this->coatingQueryBuilder();
        $this->applyFtsClause($qb, $filter);
        $this->applyFacets($qb, $filter);
        $this->applyPaging($qb, $filter->pager);

        return $this->paginate($qb, false);
    }

    public function fuzzyTitle(CoatingsFilter $filter): PaginationResult
    {
        if ($filter->search === null) {
            return new PaginationResult([], 0);
        }

        $similarity = 'GREATEST(WORD_SIMILARITY(:search, cc.title), WORD_SIMILARITY(:search, cc.description))';

        $qb = $this->coatingQueryBuilder();
        $qb->andWhere($similarity . ' > :threshold')
            ->addSelect($similarity . ' AS HIDDEN sim')
            ->orderBy('sim', 'DESC')
            ->setMaxResults(self::FUZZY_LIMIT)
            ->setParameter('search', $filter->search)
            ->setParameter('threshold', self::FUZZY_SIMILARITY_THRESHOLD);

        $this->applyFacets($qb, $filter);

        return $this->paginate($qb, false);
    }

    private function applyFtsClause(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        if ($filter->search === null) {
            $qb->orderBy('cc.title', 'ASC');
            return;
        }

        $tsquery = $this->buildPrefixTsQuery($filter->search);
        if ($tsquery === '') {
            $qb->andWhere('1 = 0');
            return;
        }

        $qb->innerJoin(CoatingSearch::class, 's', 'WITH', 's.coatingId = cc.id')
            ->andWhere('TS_MATCH(s.searchVector, TO_TSQUERY(:lang, :tsquery)) = TRUE')
            ->addSelect('TS_RANK_CD(s.searchVector, TO_TSQUERY(:lang, :tsquery)) AS HIDDEN fts_rank')
            ->orderBy('fts_rank', 'DESC')
            ->setParameter('lang', self::FTS_LANG)
            ->setParameter('tsquery', $tsquery);
    }

    private function applyFacets(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        $this->applyManufacturerFacet($qb, $filter);
    }

    private function applyManufacturerFacet(QueryBuilder $qb, CoatingsFilter $filter): void
    {
        if ($filter->manufacturerIds === []) {
            return;
        }
        $qb->andWhere('cc.manufacturer IN (:manufacturerIds)')
            ->setParameter('manufacturerIds', $filter->manufacturerIds);
    }

    /**
     * Превращает пользовательский ввод в безопасный tsquery с префиксным сопоставлением.
     * «быстросох эпоксидн» -> «быстросох:* & эпоксидн:*».
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

    private function coatingQueryBuilder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('cc')
            ->from(Coating::class, 'cc');
    }

    private function applyPaging(QueryBuilder $qb, ?Pager $pager): void
    {
        if ($pager === null) {
            return;
        }
        $qb->setMaxResults($pager->getLimit());
        $qb->setFirstResult($pager->getOffset());
    }

    private function paginate(QueryBuilder $qb, bool $fetchJoinCollection = false): PaginationResult
    {
        $paginator = new Paginator($qb->getQuery(), $fetchJoinCollection);

        return new PaginationResult(
            iterator_to_array($paginator->getIterator()),
            $paginator->count(),
        );
    }
}
```

- [ ] **Step 2: Прогнать существующие unit-тесты — все должны проходить**

```bash
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit
```

Ожидаемый: `OK` без падений (фильтр-тесты + остальные unit-тесты).

---

## Task 3: CoatingRepository — упрощение `findByFilter`

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Repository/CoatingRepository.php`

- [ ] **Step 1: Заменить файл целиком**

`app/src/Coatings/Infrastructure/Repository/CoatingRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Infrastructure\Search\CoatingFinder;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CoatingRepository extends ServiceEntityRepository implements CoatingRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly CoatingFinder $finder,
    ) {
        parent::__construct($registry, Coating::class);
    }

    public function add(Coating $coating): void
    {
        $this->getEntityManager()->persist($coating);
        $this->getEntityManager()->flush();
    }

    public function remove(Coating $coating): void
    {
        $this->getEntityManager()->remove($coating);
        $this->getEntityManager()->flush();
    }

    public function findByFilter(CoatingsFilter $filter): PaginationResult
    {
        $result = $this->finder->fullText($filter);
        if ($result->total === 0 && $filter->search !== null) {
            return $this->finder->fuzzyTitle($filter);
        }

        return $result;
    }

    public function findOneById(string $id): ?Coating
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findOneByTitle(string $title): ?Coating
    {
        return $this->findOneBy(['title' => $title]);
    }
}
```

- [ ] **Step 2: Прогнать unit-тесты — всё должно проходить**

```bash
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit
```

Ожидаемый: `OK`.

- [ ] **Step 3: Smoke через DQL без фасета — поиск работает как раньше**

```bash
docker-compose exec -T manager_php-fpm php bin/console cache:clear --env=dev
docker-compose exec -T manager_php-fpm php bin/console doctrine:query:dql \
  "SELECT c.title FROM App\\Coatings\\Domain\\Aggregate\\Coating\\Coating c \
   INNER JOIN App\\Coatings\\Domain\\Aggregate\\Coating\\CoatingSearch s \
   WITH s.coatingId = c.id \
   WHERE TS_MATCH(s.searchVector, TO_TSQUERY('russian', 'эпоксид:*')) = TRUE" \
  --hydrate=array
```

Ожидаемый: возвращаются 4 покрытия (ИЗОЛЭП-mastic, ЛИТАПРАЙМ Экспресс, ПРОМЕТЕЙ РР 950, ПРОМЕТЕЙ РР 800).

---

## Task 4: SearchAction — чтение `manufacturerIds[]`, список производителей

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Controller/Coating/SearchAction.php`

- [ ] **Step 1: Заменить файл целиком**

`app/src/Coatings/Infrastructure/Controller/Coating/SearchAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/search', name: 'app_cabinet_coating_coating_search', methods: ['GET'])]
class SearchAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $search = $request->query->get('search');
        $manufacturerIds = $request->query->all('manufacturerIds');
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int) $request->query->get('limit') : null;
        $pager = Pager::fromPage($page, $limit);

        // Список производителей для select — отдельный запрос; на admin-странице
        // с ~10-100 производителями обходимся без кеша.
        $manufacturersResult = $this->queryBus->execute(
            new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
        );

        // Без критериев — показываем форму без выполнения поиска.
        $hasAnyCriterion = ($search !== null && trim($search) !== '') || $manufacturerIds !== [];

        $result = null;
        $error = null;
        if ($hasAnyCriterion) {
            try {
                $filter = new CoatingsFilter(
                    search: $search,
                    manufacturerIds: $manufacturerIds,
                    pager: $pager,
                );
                $result = $this->queryBus->execute(new GetPagedCoatingsQuery($filter));
            } catch (AppException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/coating/coating/search.html.twig', [
            'search' => $search ?? '',
            'selectedManufacturerIds' => $manufacturerIds,
            'manufacturers' => $manufacturersResult->manufacturers,
            'result' => $result,
            'error' => $error,
        ]);
    }
}
```

- [ ] **Step 2: Очистить кеш**

```bash
docker-compose exec -T manager_php-fpm php bin/console cache:clear --env=dev
```

Ожидаемый: `Cache for the "dev" environment (debug=true) was successfully cleared.`

- [ ] **Step 3: Smoke через curl — три сценария**

```bash
# 1) Пустая форма — без параметров
curl -s -L -c /tmp/c.txt -b /tmp/c.txt -o /dev/null -w "empty: %{http_code}\n" \
  "http://localhost:6878/cabinet/coating/search"

# 2) Только текст
curl -s -L -c /tmp/c.txt -b /tmp/c.txt -o /dev/null -w "text-only: %{http_code}\n" \
  "http://localhost:6878/cabinet/coating/search?search=%D1%8D%D0%BF%D0%BE%D0%BA%D1%81%D0%B8%D0%B4"

# 3) Только фасет (любой UUID-плейсхолдер; вернёт пусто, главное — 200)
curl -s -L -c /tmp/c.txt -b /tmp/c.txt -o /dev/null -w "facet-only: %{http_code}\n" \
  "http://localhost:6878/cabinet/coating/search?manufacturerIds%5B%5D=11111111-1111-4111-8111-111111111111"
```

Ожидаемый: все три — 200. Без exceptions в `docker-compose logs --tail=20 manager_php-fpm`.

---

## Task 5: search.html.twig — collapse-блок, чекбоксы

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/search.html.twig`

- [ ] **Step 1: Заменить шаблон целиком**

`app/src/Shared/Infrastructure/Templates/admin/coating/coating/search.html.twig`:

```twig
{% extends '/cabinet/index.html.twig' %}

{% block title %}{{ parent() }} | Поиск покрытий{% endblock %}

{% block content %}
    <div class="col-lg-10 mx-auto p-4 py-md-5">
        <div class="d-flex justify-content-between align-items-center">
            <h2 class="text-body-emphasis">Поиск покрытий</h2>
            <a href="{{ path('app_cabinet_coating_coating_list') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        <form method="get" action="{{ path('app_cabinet_coating_coating_search') }}" class="my-3">
            <div class="input-group input-group-lg mb-3">
                <input type="search" name="search" value="{{ search }}"
                       class="form-control"
                       placeholder="По названию и описанию (3-50 символов)"
                       minlength="3" maxlength="50" autocomplete="off">
                <button type="submit" class="btn btn-primary">Найти</button>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <button class="btn btn-outline-secondary btn-sm" type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#advancedFilter"
                        aria-expanded="{{ selectedManufacturerIds ? 'true' : 'false' }}"
                        aria-controls="advancedFilter">
                    <i class="bi bi-funnel"></i> Расширенный поиск
                    {% if selectedManufacturerIds|length > 0 %}
                        <span class="badge bg-primary ms-1">{{ selectedManufacturerIds|length }}</span>
                    {% endif %}
                </button>

                {% if search or selectedManufacturerIds %}
                    <a href="{{ path('app_cabinet_coating_coating_search') }}" class="text-muted small">
                        Сбросить
                    </a>
                {% endif %}
            </div>

            <div class="collapse {% if selectedManufacturerIds %} show {% endif %}" id="advancedFilter">
                <div class="card card-body">
                    <label class="form-label">Производитель</label>
                    <div class="row">
                        {% for m in manufacturers %}
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox"
                                           class="form-check-input"
                                           name="manufacturerIds[]"
                                           value="{{ m.id }}"
                                           id="manufacturer-{{ m.id }}"
                                           {% if m.id in selectedManufacturerIds %} checked {% endif %}>
                                    <label class="form-check-label" for="manufacturer-{{ m.id }}">
                                        {{ m.title }}
                                    </label>
                                </div>
                            </div>
                        {% endfor %}
                    </div>
                </div>
            </div>
        </form>

        {% if error %}
            <div class="alert alert-warning" role="alert">{{ error }}</div>
        {% endif %}

        {% if result is not null %}
            {% if result.coatings|length > 0 %}
                <div class="text-muted mb-2">Найдено: {{ result.coatings|length }}</div>
                <div class="list-group w-100">
                    {% for coating in result.coatings %}
                        <a href="{{ path('app_cabinet_coating_coating_update', {id: coating.id}) }}"
                           class="list-group-item list-group-item-action">
                            <h6 class="mb-0">{{ coating.title }}</h6>
                            <p class="mb-0 opacity-75">{{ coating.description }}</p>
                        </a>
                    {% endfor %}
                </div>
            {% else %}
                <div class="alert alert-info" role="alert">
                    {% if search %}По запросу «{{ search }}» ничего не найдено.{% else %}Ничего не найдено.{% endif %}
                </div>
            {% endif %}
        {% endif %}
    </div>
{% endblock %}
```

- [ ] **Step 2: Сбросить кеш Twig**

```bash
docker-compose exec -T manager_php-fpm php bin/console cache:clear --env=dev
```

Ожидаемый: cleared OK.

- [ ] **Step 3: Smoke ручной — открыть в браузере с логином**

Не автоматизируется через curl (страница за auth). Открой:

```
http://localhost:6878/cabinet/coating/search
```

Чек-лист визуально:
1. Видна форма поиска с input-кой и кнопкой «Найти».
2. Под формой — кнопка `[Расширенный поиск]` без бэйджа, блок свёрнут.
3. Нажми кнопку → блок раскрылся, видны чекбоксы производителей в две колонки.
4. Отметь одного, нажми «Найти» → перезагрузка. На кнопке появился бэйдж `[Расширенный поиск 1]`. Чекбокс остался отмечен.
5. Появилась ссылка «Сбросить» справа.
6. В списке покрытий — только этого производителя.
7. Введи в поиск «эпоксид» + оставь чекбокс → найдены покрытия этого производителя со словом «эпоксид».
8. Жми «Сбросить» → URL `?` чист, чекбокс снят, форма пустая.

---

## Task 6: Финальный прогон тестов

**Files:** ничего не меняется — только проверка.

- [ ] **Step 1: Все unit-тесты**

```bash
docker-compose exec -T manager_php-fpm vendor/bin/phpunit tests/Unit
```

Ожидаемый: `OK` (все тесты, включая 7 новых из `CoatingsFilterTest`).

- [ ] **Step 2: Schema validate**

```bash
docker-compose exec -T manager_php-fpm php bin/console doctrine:schema:validate
```

Ожидаемый: те же FAIL для `Manufacturer`/`CoatingTag` (legacy), Coating-маппинг валиден.

- [ ] **Step 3: Проверка DQL с фасетом через консоль**

```bash
docker-compose exec -T manager_php-fpm php bin/console doctrine:query:dql \
  "SELECT c.title FROM App\\Coatings\\Domain\\Aggregate\\Coating\\Coating c \
   WHERE c.manufacturer IN ('REPLACE_WITH_REAL_MANUFACTURER_UUID')" \
  --hydrate=array
```

(Подставь реальный UUID любого `coatings_manufacturer.id` — список можно получить через `psql`:
`docker-compose exec manager_db psql -U root -d database_app -c "SELECT id, title FROM coatings_manufacturer LIMIT 5"`)

Ожидаемый: список покрытий этого производителя.

---

## Self-Review (выполнено)

**Spec coverage:**
- `manufacturerIds` нормализация — Task 1
- `applyFtsClause`, `applyFacets`, `applyManufacturerFacet` в Finder — Task 2
- `fullText`/`fuzzyTitle` принимают `CoatingsFilter` — Task 2
- Упрощённый `findByFilter` без `normalizeSearch` — Task 3
- `$hasAnyCriterion` и список производителей в `SearchAction` — Task 4
- Multi-value `?manufacturerIds[]` — Task 4
- Collapse-блок, чекбоксы, бэйдж, «Сбросить», auto-open при applied — Task 5
- Сохранение `search` и `selectedManufacturerIds` в форме — Task 5

**Placeholders:** сканировал — нет «TBD/TODO/implement later». Каждый шаг содержит конкретный код или конкретную команду.

**Type consistency:**
- `manufacturerIds` — `array<int|string, mixed>` на входе конструктора, `list<string>` после нормализации, `list<string>` в Finder. Согласовано.
- `Coating::manufacturer` — Doctrine many-to-one. `cc.manufacturer IN (:ids)` сравнивает по primary key Manufacturer (uuid). Согласовано с типом `Manufacturer.id` (`Symfony\Component\Uid\Uuid` — но Doctrine принимает string-uuid в setParameter).
- `Symfony\Component\Uid\Uuid::isValid()` — статический метод, возвращает bool. Используется только в `CoatingsFilter::normalizeManufacturerIds`.
- В шаблоне `m.id` приходит из `ManufacturerDTO` где `id` это `string` — совпадает с тем, что лежит в `selectedManufacturerIds`.
