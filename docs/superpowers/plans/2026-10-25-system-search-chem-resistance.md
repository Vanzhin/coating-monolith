# System Search: Chemical-Resistance Facet + Fuzzy Substance Lookup + Architecture Alignment — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дать поиску систем покрытий структурный фасет «стойкость к веществу(ам)» (все слои стойки, опц. температура), опечаткоустойчивый поиск веществ для пикера, и выровнять движок поиска систем под эталон покрытий — как фундамент для будущей фазы «подбор по письму».

**Architecture:** Фасет химстойкости — живой кросс-контекстный `EXISTS` в DBAL-SQL поиска систем (как существующий фасет «есть документы»), без проекции. Fuzzy веществ — pg_trgm в едином `SubstanceRepository::findByFilter`. Поиск систем консолидируется в `CoatingSystemRepository::findByFilter` (гидрированный `PaginationResult`, обогащение остаётся в Application), дублирующий suggest-ILIKE удаляется.

**Tech Stack:** PHP 8.x, Symfony, Doctrine ORM + DBAL, PostgreSQL (tsvector FTS + pg_trgm), Stimulus (`coating-tags`), Twig.

**Spec:** `docs/superpowers/specs/2026-10-25-system-search-chem-resistance-design.md`

## Global Constraints

- Домен решает инварианты; техника поиска (кросс-контекстный SQL в `chemical_resistance_*`) — инфраструктура. Домен `Coatings` про эти таблицы знает только в SQL поиска (прецедент — фасет `applyHasDocuments` → `certificates_document`).
- Один `findByFilter` на поиск/листинг; не плодить `suggest()/findByX()`. Лёгкий вывод `{id,title}` vs тяжёлый DTO — путь один, разница в весе.
- `id`-списки — `StringCollection`. Коллекции доменных объектов — не сырой `array`.
- Выбор сущности на фронте — Stimulus `coating-tags` (чипы, клиентская гидрация by-ids через `resolve-url`), как остальные мультивыбор-фасеты на странице систем. Не generic single-select typeahead, не серверные чипы `substance-multiselect`.
- Состояние фильтра — в URL (shareable); форма работает без JS; бэк — источник истины.
- «Стоек» = `chemical_resistance_is_suitable_grade(grade)` (R/LR) — единый источник с индексом покрытий. Не хардкодить грейды.
- Миграции идемпотентны: `CREATE EXTENSION IF NOT EXISTS pg_trgm`, `CREATE INDEX IF NOT EXISTS ... USING GIN (col gin_trgm_ops)`. Без `CONCURRENTLY` (транзакционная миграция). Базовый класс `Doctrine\Migrations\AbstractMigration`, namespace `App\Shared\Infrastructure\Database\Migrations`.
- Тесты: unit на хосте, functional (реальная БД) в контейнере с override `DATABASE_URL`/`REDIS_HOST` (см. корневой CLAUDE.md и `[[reference_test_run_env]]`). Тесты зеркалят `src/`. Фронтовые задачи (Twig/Stimulus) PHP-тестами не покрываются — верификация = `cd app && yarn dev` + браузер.
- Коммиты — одна строка, по формату проекта; коммитить разрешено по задаче (SDD-исполнители коммитят).

## Review Focus

- **Температура на форме поиска: пусто / 0 / отрицательное / не число** → должно стать `null` (фильтр по T отключён), НИКОГДА не 422. `PositiveNumber(0)` кидает `AppException` → mapper обязан строить VO только при значении > 0. → тест в Task 10.
- **Мусорные/невалидные UUID в `substanceIds[]`** → отфильтрованы в mapper (как `coatingIds` через `Uuid::isValid`), в SQL не доходят. → тест в Task 10.
- **Fuzzy веществ — ложные срабатывания** на коротком/общем вводе (порог должен отсекать «мастик»→«Литапрайм»-подобный шум). → тест в Task 1.
- **Регресс строкового suggest после консолидации**: система, найденная инфиксом по `cs.title` (а не стеммингом FTS; в т.ч. токены с дефисом/цифрой), обязана остаться в выдаче suggest. → тест в Task 6 (и проверка в Task 9).
- **Система без слоёв** не должна «вакуумно» пройти фасет вещества (`NOT EXISTS` над пустым множеством = true без guard). → тест в Task 5.

---

### Task 1: Fuzzy-поиск веществ в `SubstanceRepository::findByFilter`

**Files:**
- Modify: `app/src/ChemicalResistance/Infrastructure/Repository/SubstanceRepository.php`
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20261025000001.php` (trgm индекс)
- Test: `app/tests/Functional/ChemicalResistance/Infrastructure/Repository/SubstanceRepositoryFuzzyTest.php`

**Interfaces:**
- Consumes: `SubstancesFilter(?string $search, ?Pager $pager, ?string $cas)`; `PaginationResult(array $items /* list<SubstanceDTO> */, int $total)`; `SubstanceDTO(id, canonicalName, cas, aliases)`.
- Produces: `SubstanceRepository::findByFilter(SubstancesFilter): PaginationResult` — теперь при 0 ILIKE-хитов и непустом `search` (и `cas === null`) делает триграммный fallback по `canonical_name`. Контракт возврата не меняется.

- [ ] **Step 1: Написать падающий тест**

```php
// SubstanceRepositoryFuzzyTest — реальная БД, 2 вещества-фикстуры: «Этанол», «Ацетон».
public function test_fuzzy_recovers_single_letter_typo(): void {
    // «этонол» (опечатка) — точного ILIKE-хита нет
    $res = $this->repo->findByFilter(new SubstancesFilter(search: 'этонол'));
    self::assertSame('Этанол', $res->items[0]->canonicalName);
}
public function test_exact_substring_still_works_without_fuzzy(): void {
    $res = $this->repo->findByFilter(new SubstancesFilter(search: 'этан'));
    self::assertSame('Этанол', $res->items[0]->canonicalName);
}
public function test_garbage_input_returns_empty_not_noise(): void {
    $res = $this->repo->findByFilter(new SubstancesFilter(search: 'zzzzzz'));
    self::assertSame(0, $res->total);
}
```

- [ ] **Step 2: Прогнать — убедиться, что падает** (`test_fuzzy_recovers_single_letter_typo` FAIL: пусто).

- [ ] **Step 3: Реализовать fuzzy-fallback.** В `findByFilter`: оставить текущий ILIKE-путь как фазу 1; если `0 === $total && null !== $filter->search && null === $filter->cas` — фаза 2: `WHERE WORD_SIMILARITY(:search, s.canonical_name) > :threshold ORDER BY WORD_SIMILARITY(:search, s.canonical_name) DESC`, тот же `SubstanceDTO`-маппинг и `Pager`-пагинация. Порог — константа `FUZZY_SIMILARITY_THRESHOLD = 0.6` (как `CoatingFinder`), тюнить по тесту на шум.

- [ ] **Step 4: Миграция trgm-индекса.** `up()`: `CREATE EXTENSION IF NOT EXISTS pg_trgm;` + `CREATE INDEX IF NOT EXISTS ix_substance_canonical_trgm ON chemical_resistance_substance USING GIN (canonical_name gin_trgm_ops);`. `down()`: `DROP INDEX IF EXISTS ix_substance_canonical_trgm;`.

- [ ] **Step 5: Прогнать миграцию локально** (`cd app && bin/console doctrine:migrations:migrate -n`) и тесты — PASS.

- [ ] **Step 6: Commit** — `Поиск веществ ловит опечатки: триграммный fallback (этонол→Этанол) + GIN-индекс`.

---

### Task 2: Автокомплит веществ — через `findByFilter` + поле `title`

**Files:**
- Modify: `app/src/ChemicalResistance/Application/UseCase/Query/SubstanceAutocomplete/SubstanceAutocompleteQueryHandler.php`
- Modify: `app/src/ChemicalResistance/Infrastructure/Controller/Substance/SubstanceAutocompleteAction.php`
- Test: `app/tests/Functional/ChemicalResistance/Infrastructure/Controller/Substance/SubstanceAutocompleteActionTest.php` (расширить существующий, если есть)

**Interfaces:**
- Consumes: `SubstanceRepository::findByFilter` (Task 1, с fuzzy).
- Produces: endpoint `app_cabinet_chemical_resistance_substance_autocomplete` отдаёт тот же bare-array shape плюс **дополнительное** поле `title` (= `canonicalName`): `[{id, canonicalName, cas, title}]`. Существующие потребители (`substance-multiselect` на странице «по веществу») не ломаются — поле добавочное.

- [ ] **Step 1: Написать падающий тест**

```php
public function test_autocomplete_includes_title_and_is_fuzzy(): void {
    // фикстура «Этанол»
    $this->client->request('GET', '/cabinet/chemical-resistance/substance/autocomplete?q=этонол');
    $json = json_decode($this->client->getResponse()->getContent(), true);
    self::assertSame('Этанол', $json[0]['title']);          // title присутствует
    self::assertSame('Этанол', $json[0]['canonicalName']);  // старое поле цело
}
```

- [ ] **Step 2: Прогнать — FAIL** (нет `title` / «этонол» не находит).

- [ ] **Step 3: Делегировать хендлер в `findByFilter`.** `SubstanceAutocompleteQueryHandler::__invoke` строит `new SubstancesFilter(search: $q->q, pager: Pager::fromPage(1, $q->limit))`, зовёт `SubstanceRepository::findByFilter`, возвращает его `->items` (`list<SubstanceDTO>`). Внедрить `SubstanceRepositoryInterface` в хендлер вместо прямого `Connection`.

- [ ] **Step 4: Добавить `title` в сериализацию Action.** В `SubstanceAutocompleteAction` маппинг каждого DTO: `['id' => $d->id, 'canonicalName' => $d->canonicalName, 'cas' => $d->cas, 'title' => $d->canonicalName]`.

- [ ] **Step 5: Прогнать тест + существующие тесты страницы «по веществу»** — PASS (shape аддитивно расширен).

- [ ] **Step 6: Commit** — `Автокомплит веществ переехал на единый findByFilter (стал опечаткоустойчивым) + отдаёт title для чипов`.

---

### Task 3: Endpoint резолва веществ by-ids (гидрация чипов)

**Files:**
- Create: `app/src/ChemicalResistance/Application/UseCase/Query/GetSubstancesByIds/GetSubstancesByIdsQuery.php`
- Create: `.../GetSubstancesByIds/GetSubstancesByIdsQueryHandler.php`
- Create: `app/src/ChemicalResistance/Infrastructure/Controller/Substance/ByIdsAction.php`
- Test: `app/tests/Functional/ChemicalResistance/Infrastructure/Controller/Substance/ByIdsActionTest.php`

**Interfaces:**
- Consumes: `SubstanceRepositoryInterface::findAllByIds(array $ids): array /* list<Substance>, порядок сохранён */`; `StringCollection`.
- Produces: route `app_cabinet_chemical_resistance_substance_by_ids`, path `/cabinet/chemical-resistance/substance/by-ids`, GET, читает `ids[]` (UUID-валидация, `MAX_IDS=50`), ответ `{"items":[{"id","title"}]}` (title = canonicalName). Зеркало `Coatings/.../Controller/Coating/ByIdsAction.php`.

- [ ] **Step 1: Написать падающий тест**

```php
public function test_by_ids_returns_id_title_items(): void {
    // фикстура «Этанол» с известным $id
    $this->client->request('GET', '/cabinet/chemical-resistance/substance/by-ids?ids[]='.$id);
    $json = json_decode($this->client->getResponse()->getContent(), true);
    self::assertSame([['id' => $id, 'title' => 'Этанол']], $json['items']);
}
public function test_empty_ids_returns_empty_items(): void {
    $this->client->request('GET', '/cabinet/chemical-resistance/substance/by-ids');
    self::assertSame(['items' => []], json_decode($this->client->getResponse()->getContent(), true));
}
```

- [ ] **Step 2: Прогнать — FAIL** (роут не найден).

- [ ] **Step 3: Реализовать query+handler+action** по образцу `Coatings` ByIds: `GetSubstancesByIdsQuery(StringCollection $ids)` → handler зовёт `findAllByIds($ids->getList())` → возвращает `list<array{id,title}>` (title = `$substance->getCanonicalName()`); `ByIdsAction` фильтрует `ids[]` по `Uuid::isValid`, режет до 50, диспатчит, отдаёт `{items}`.

- [ ] **Step 4: Прогнать тест** — PASS.

- [ ] **Step 5: Commit** — `Резолв веществ by-ids для гидрации чипов (как у покрытий)`.

---

### Task 4: Поля фасета химстойкости в `CoatingSystemsFilter`

**Files:**
- Modify: `app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php`
- Test: `app/tests/Unit/Coatings/Domain/Repository/CoatingSystemsFilterTest.php`

**Interfaces:**
- Produces: два новых публичных поля на `CoatingSystemsFilter`:
  `public StringCollection $substanceIds = new StringCollection()` и
  `public ?PositiveNumber $substanceTemperature = null`.
  Импорт `use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;` (`StringCollection` уже импортирован). `PositiveNumber` гарантирует T > 0 (конструктор кидает `AppException` при `<= 0`).

- [ ] **Step 1: Написать падающий тест**

```php
public function test_defaults_are_empty_substances_and_null_temperature(): void {
    $f = new CoatingSystemsFilter();
    self::assertSame(0, $f->substanceIds->count());
    self::assertNull($f->substanceTemperature);
}
public function test_carries_substances_and_temperature(): void {
    $f = new CoatingSystemsFilter(
        substanceIds: new StringCollection('a', 'b'),
        substanceTemperature: new PositiveNumber(60),
    );
    self::assertSame(['a','b'], $f->substanceIds->getList());
    self::assertSame(60, $f->substanceTemperature->value());
}
```

- [ ] **Step 2: Прогнать — FAIL** (неизвестные именованные аргументы).

- [ ] **Step 3: Добавить поля** в конструктор (рядом с `coatingIds` — вещества; `substanceTemperature` — рядом с thermal-полями) + импорт. Это bag-of-fields, без логики.

- [ ] **Step 4: Прогнать тест** — PASS.

- [ ] **Step 5: Commit** — `Фильтр систем носит вещества + опц. температуру для фасета химстойкости`.

---

### Task 5: Матч «все слои стойки к веществу(ам)» в `CoatingSystemFinder`

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderSubstanceTest.php`

**Interfaces:**
- Consumes: `CoatingSystemsFilter::$substanceIds`, `::$substanceTemperature` (Task 4); таблицы `coating_system_layer(system_id, coating_id)`, `chemical_resistance_assessment(coating_id, substance_id, grade, max_temperature_celsius)`; функция `chemical_resistance_is_suitable_grade(grade)`.
- Produces: private `applySubstanceResistance(QueryBuilder $qb, CoatingSystemsFilter $filter): void`, вызывается в `find()` в цепочке apply*. Семантика: система проходит, если непустая и у неё нет слоя без suitable-оценки по КАЖДОМУ веществу (AND); при заданной `substanceTemperature` — ещё `max_temperature_celsius >= T`.

- [ ] **Step 1: Написать падающий тест** (фикстуры: система S1 — все слои стойки к «этанолу»; S2 — один слой без оценки; S3 — слой с grade=NR; S4 — все стойки, но `max_temp` < 60; S5 — без слоёв)

```php
public function test_all_layers_suitable_matches(): void {
    $r = $this->finder->find($this->filter(substanceIds: [$ethanol]));
    self::assertContains($s1, $r->ids->getList());
}
public function test_one_layer_missing_or_unsuitable_excluded(): void {
    $ids = $this->finder->find($this->filter(substanceIds: [$ethanol]))->ids->getList();
    self::assertNotContains($s2, $ids);  // слой без оценки
    self::assertNotContains($s3, $ids);  // grade=NR
}
public function test_temperature_narrows(): void {
    $ids = $this->finder->find($this->filter(substanceIds: [$ethanol], temp: 60))->ids->getList();
    self::assertNotContains($s4, $ids);  // max_temp < 60
    self::assertContains($s1, $ids);
}
public function test_multiple_substances_are_anded(): void {
    $ids = $this->finder->find($this->filter(substanceIds: [$ethanol, $water]))->ids->getList();
    // S1 стоек к обоим → есть; система стойкая только к одному → нет
}
public function test_system_without_layers_not_vacuously_matched(): void {
    self::assertNotContains($s5, $this->finder->find($this->filter(substanceIds: [$ethanol]))->ids->getList());
}
public function test_no_substance_filter_leaves_results_unchanged(): void { /* фасет пуст → выдача как без него */ }
```

- [ ] **Step 2: Прогнать — FAIL**.

- [ ] **Step 3: Реализовать `applySubstanceResistance`.** Если `0 === $filter->substanceIds->count()` — return. Иначе guard непустой системы + по одному `NOT EXISTS` на вещество (уникальные имена параметров `:subst_0`, `:subst_1`, …), температура биндится один раз как `:subst_temp` (null-safe):

```sql
-- guard: у системы есть хотя бы один слой
AND EXISTS (SELECT 1 FROM coating_system_layer gl WHERE gl.system_id = cs.id)
-- на каждое вещество:
AND NOT EXISTS (
    SELECT 1 FROM coating_system_layer csl
    WHERE csl.system_id = cs.id
      AND NOT EXISTS (
          SELECT 1 FROM chemical_resistance_assessment a
          WHERE a.coating_id = csl.coating_id
            AND a.substance_id = :subst_0
            AND chemical_resistance_is_suitable_grade(a.grade)
            AND (:subst_temp IS NULL OR a.max_temperature_celsius >= :subst_temp)
      )
)
```
Температура: `:subst_temp = $filter->substanceTemperature?->value()` (int|float|null). Живой запрос, без проекции — опора на существующий `uq_assessment_coating_substance (coating_id, substance_id)` для скорости внутреннего lookup.

- [ ] **Step 4: Прогнать тесты** — PASS.

- [ ] **Step 5: Commit** — `Фасет: системы, где ВСЕ слои стойки к веществу (опц. до температуры T)`.

---

### Task 6: Строковый поиск систем не сужается — инфикс `ILIKE` в `applyFts`

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php` (`applyFts`)
- Test: `app/tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderFtsTest.php`

**Interfaces:**
- Produces: `applyFts` матчит `(css.search_tsvector @@ TO_TSQUERY(...)) OR cs.title ILIKE :fts_like`, где `:fts_like = '%'.$filter->search->value.'%'`. Если tsquery пустой — только `cs.title ILIKE :fts_like` (вместо прежнего `1 = 0`). Цель — не потерять инфикс-хиты suggest при консолидации (Task 9) и закрыть дыру FTS по дефис/цифра-токенам.

- [ ] **Step 1: Написать падающий тест**

```php
public function test_infix_title_match_found(): void {
    // система с title «...-аналоговый...», запрос «налог» (инфикс, стемминг FTS бы промахнулся)
    $ids = $this->finder->find($this->filterSearch('налог'))->ids->getList();
    self::assertContains($sysWithInfix, $ids);
}
public function test_fts_stemmed_match_still_found(): void {
    $ids = $this->finder->find($this->filterSearch('эпоксидн'))->ids->getList();
    self::assertContains($epoxySys, $ids);  // регресс FTS не допущен
}
```

- [ ] **Step 2: Прогнать — FAIL** (инфикс не находится).

- [ ] **Step 3: Переписать `applyFts`** на OR-условие FTS + `cs.title ILIKE :fts_like`; сохранить `TS_RANK_CD`-сортировку (fuzzy/инфикс-строки без ранга идут после по `cs.title`). INNER join к `css` оставить (sync-шина гарантирует строку).

- [ ] **Step 4: Прогнать тесты** — PASS.

- [ ] **Step 5: Commit** — `Строковый поиск систем ловит инфикс по названию (и дефис/цифра-токены) — не у́же прежнего suggest`.

---

### Task 7: Единая точка `CoatingSystemRepository::findByFilter`

**Files:**
- Modify: `app/src/Coatings/Domain/Repository/CoatingSystemRepositoryInterface.php`
- Modify: `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Repository/CoatingSystemRepositoryFindByFilterTest.php`

**Interfaces:**
- Consumes: `CoatingSystemFinder::find(CoatingSystemsFilter): SearchResult`; `findByIds(StringCollection): array`.
- Produces: `CoatingSystemRepositoryInterface::findByFilter(CoatingSystemsFilter $filter): PaginationResult` — внутри `find()` → `findByIds($result->ids)` → `new PaginationResult($systems, $result->total)`. Порядок сущностей сохранён (как делает `findByIds`). Гидрированные сущности; обогащение остаётся в Application.

- [ ] **Step 1: Написать падающий тест**

```php
public function test_find_by_filter_returns_hydrated_entities_in_finder_order(): void {
    $res = $this->repo->findByFilter(new CoatingSystemsFilter(search: SearchQuery::tryFromString('эпокси')));
    self::assertContainsOnlyInstancesOf(CoatingSystem::class, $res->items);
    self::assertIsInt($res->total);
}
```

- [ ] **Step 2: Прогнать — FAIL** (метода нет).

- [ ] **Step 3: Добавить метод** в интерфейс и реализацию. Внедрить `CoatingSystemFinder` в конструктор репозитория (autowire). Тело — три строки (find → findByIds → PaginationResult).

- [ ] **Step 4: Прогнать тест** — PASS.

- [ ] **Step 5: Commit** — `Поиск систем свёлся в один CoatingSystemRepository::findByFilter (как у покрытий)`.

---

### Task 8: Листинг-хендлер систем через `findByFilter`

**Files:**
- Modify: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQueryHandler.php`
- Test: `app/tests/Functional/Coatings/Application/UseCase/Query/SearchCoatingSystemsQueryHandlerTest.php` (расширить существующий)

**Interfaces:**
- Consumes: `CoatingSystemRepository::findByFilter` (Task 7).
- Produces: тот же `SearchCoatingSystemsQueryResult(array $items, int $total)`; обогащение (compliance-кэш, сертификаты-шлюз, `CoatingSystemDTOTransformer`) без изменений.

- [ ] **Step 1: Обновить/добавить тест** — существующий функциональный тест листинга должен остаться зелёным; добавить проверку, что результат по фильтру с веществом сужается (связка Task 5↔8).

- [ ] **Step 2: Прогнать — FAIL/зелёный baseline**.

- [ ] **Step 3: Переписать `__invoke`**: `$page = $this->repo->findByFilter($q->filter);` затем `$ids = new StringCollection(...array_map(fn ($s) => $s->getId(), $page->items));`, далее прежние `complianceCache->findBySystemIds($ids->getList())`, `certificates->countBySystemIds($ids)`, `->downloadUrlsBySystemIds($ids)`, тот же `transformer->fromEntity(...)`, `return new SearchCoatingSystemsQueryResult($items, $page->total);`. Убрать прямую зависимость от `CoatingSystemFinder` в хендлере.

- [ ] **Step 4: Прогнать тесты** — PASS.

- [ ] **Step 5: Commit** — `Листинг систем ходит через findByFilter; обогащение осталось в Application`.

---

### Task 9: Консолидация suggest систем — убить дублирующий ILIKE-путь

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/SuggestAction.php`
- Rework: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystems/` (добавить лёгкий suggest-вариант) ИЛИ новый `GetCoatingSystemSuggestionsQuery*`
- Delete: `app/src/Coatings/Application/UseCase/Query/SearchCoatingSystemsForSuggest/` (Query + Result + Handler)
- Test: `app/tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/SuggestActionTest.php` (расширить существующий)

**Interfaces:**
- Consumes: `CoatingSystemRepository::findByFilter`; `CoatingSystemTitleDTO` (`app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemTitleDTO.php`) для лёгкого `{id,title}`.
- Produces: `SuggestAction` отдаёт прежний JSON `{"items":[{"id","title"}]}`, но через `findByFilter` (та же широта, что листинг). Старый suggest-хендлер с `OR cs.title ILIKE` удалён (инфикс теперь в `applyFts`, Task 6).

- [ ] **Step 1: Обновить тест**

```php
public function test_suggest_returns_items_id_title(): void {
    $this->client->request('GET', '/cabinet/coating/coating-system/suggest?q=эпокси');
    $json = json_decode($this->client->getResponse()->getContent(), true);
    self::assertArrayHasKey('items', $json);
    self::assertArrayHasKey('title', $json['items'][0]);
}
public function test_suggest_still_matches_infix_title(): void {
    // регресс-гард из Review Focus: инфикс-хит остаётся после удаления ILIKE-хендлера
}
```

- [ ] **Step 2: Прогнать — baseline/FAIL**.

- [ ] **Step 3: Переключить suggest на `findByFilter`.** Диспатч query, который строит `CoatingSystemsFilter(search: SearchQuery::tryFromString($q), pager: Pager::fromPage(1, $limit))`, зовёт `findByFilter`, маппит `->items` в `{id: $s->getId(), title: $s->getTitle()}` (через `CoatingSystemTitleDTO`). Удалить каталог `SearchCoatingSystemsForSuggest/` и ссылки на него.

- [ ] **Step 4: Грепнуть проект** на `SearchCoatingSystemsForSuggest` — не осталось вызовов. Прогнать тесты — PASS.

- [ ] **Step 5: Commit** — `Suggest систем ходит через единый findByFilter; дублирующий ILIKE-хендлер удалён`.

---

### Task 10: Mapper + view factory — вещества, температура, параметр `search`

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapper.php`
- Modify: `app/src/Coatings/Infrastructure/View/CoatingSystemListViewFactory.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Mapper/CoatingSystemListRequestMapperTest.php` (или unit, если `QueryParams` подменяем)

**Interfaces:**
- Consumes: `CoatingSystemsFilter` (Task 4); `QueryParams::stringCollection(Request, key, isValid)`, `::nullableInt`.
- Produces: mapper читает `substanceIds[]` (UUID-фильтр), `substanceTemp` (положительное-или-null), `search` с fallback на `q`; view factory эхо-возвращает `substanceIds` (`->getList()`) и `substanceTemperature` для гидрации чипов/поля.

- [ ] **Step 1: Написать падающие тесты**

```php
public function test_substance_ids_filtered_by_uuid(): void {
    $f = $this->map(['substanceIds' => ['a0000000-...-valid', 'garbage']]);
    self::assertSame(['a0000000-...-valid'], $f->substanceIds->getList());
}
public function test_temperature_zero_or_blank_or_nonnumeric_becomes_null(): void {
    foreach (['', '0', '-5', 'abc'] as $t) {
        self::assertNull($this->map(['substanceTemp' => $t])->substanceTemperature);  // НЕ 422
    }
}
public function test_positive_temperature_becomes_positive_number(): void {
    self::assertSame(60, $this->map(['substanceTemp' => '60'])->substanceTemperature->value());
}
public function test_q_fallback_feeds_search(): void {
    self::assertSame('эпокси', (string) $this->map(['q' => 'эпокси'])->search->value);
}
```

- [ ] **Step 2: Прогнать — FAIL**.

- [ ] **Step 3: Реализовать в mapper.** `substanceIds` → `$this->query->stringCollection($request, 'substanceIds', fn ($id) => Uuid::isValid($id))`. Температура: прочитать `nullableInt($request, 'substanceTemp')`; строить `new PositiveNumber($t)` **только если** `null !== $t && $t > 0`, иначе `null` (гард против `AppException` из VO — см. Review Focus). Free-text: `$raw = $request->query->get('search') ?? $request->query->get('q'); SearchQuery::tryFromString($raw)`. View factory: добавить `substanceIds` и `substanceTemperature?->value()` в массив вывода.

- [ ] **Step 4: Прогнать тесты** — PASS.

- [ ] **Step 5: Commit** — `Поиск систем: фасет веществ + температура из запроса, параметр search с q-fallback (старые ссылки живы)`.

---

### Task 11: UI-фасет «Стойкость к веществу» на странице систем

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig`
- (при необходимости) Modify: `app/src/Shared/Infrastructure/Templates/components/entity_search.html.twig` — только параметр `search_name`
- Verify: `cd app && yarn dev` + браузер (обе темы, мобайл/десктоп, без JS)

**Interfaces:**
- Consumes: endpoints `app_cabinet_chemical_resistance_substance_autocomplete` (Task 2, suggest), `app_cabinet_chemical_resistance_substance_by_ids` (Task 3, resolve); контроллер `coating-tags`; view-данные `substanceIds`, `substanceTemperature` (Task 10).
- Produces: фасет-блок в шторке фильтров (как «Покрытия в составе») + числовое поле температуры; `entity_search` `search_name: 'search'`.

- [ ] **Step 1: Добавить блок фасета** по образцу «Покрытия в составе» (строки 482–496 шаблона):

```twig
{# === Секция: Стойкость к веществу === #}
<div id="facet-substances" class="facet-block p-3 mb-2 rounded-3 bg-body-tertiary" data-coating-tags-group>
    <h6 class="fw-semibold mb-2">Стойкость к веществу</h6>
    <input type="text"
           data-controller="coating-tags"
           data-coating-tags-suggest-url-value="{{ path('app_cabinet_chemical_resistance_substance_autocomplete') }}"
           data-coating-tags-hidden-input-name-value="substanceIds[]"
           data-coating-tags-allow-create-value="false"
           data-coating-tags-preselected-ids-value="{{ substanceIds|json_encode }}"
           data-coating-tags-resolve-url-value="{{ path('app_cabinet_chemical_resistance_substance_by_ids') }}"
           class="form-control form-control-sm"
           placeholder="Начните вводить вещество...">
    <input type="number" min="1" name="substanceTemp" value="{{ substanceTemperature }}"
           class="form-control form-control-sm mt-2" placeholder="Температура, °C (опц.)">
</div>
```

- [ ] **Step 2: Параметр строки.** В embed `entity_search` сменить `search_name: 'q'` → `search_name: 'search'` (mapper принимает и `q` — Task 10). Проверить, что `search_value`, suggest-typeahead и hidden-passthrough ссылаются на новый параметр консистентно.

- [ ] **Step 3: `cd app && yarn dev`**, затем браузер: ввод вещества → подсказки (вкл. опечатку «этоно»→«этанол»), чип с ×, гидрация чипов из URL, число T, активные-счётчик фасетов, сброс; без JS — GET с `substanceIds[]`+`substanceTemp` отрабатывает сервером. Обе темы, мобайл/десктоп.

- [ ] **Step 4: `lint:twig`** тронутых шаблонов.

- [ ] **Step 5: Commit** — `Страница систем: фасет «Стойкость к веществу» (чипы+опечатки) и поле температуры; строка на параметре search`.

---

### Task 12 (опционально, по спеке «как у покрытий»): триграммный fuzzy-fallback по названию системы

Строковый поиск систем после Task 6 уже не сужается (инфикс). Этот таск добавляет полноценный fuzzy-fallback по `cs.title` (опечатки в НАЗВАНИИ системы), зеркаля двухфазность `CoatingRepository::findByFilter`. Ценность ниже, чем fuzzy веществ; задача самодостаточна и может быть отложена.

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php` (вынести применение фасетов в общий private; добавить `fuzzyByTitle(CoatingSystemsFilter): SearchResult`)
- Modify: `app/src/Coatings/Infrastructure/Repository/CoatingSystemRepository.php` (`findByFilter` — двухфазность)
- Create: миграция trgm-индекса на `coating_system(title)`
- Test: `CoatingSystemRepositoryFindByFilterTest` — кейс опечатки

- [ ] **Step 1:** Тест: `findByFilter` с однословным `search`-опечаткой по названию системы (0 FTS/инфикс-хитов) возвращает систему через fuzzy.
- [ ] **Step 2:** Прогнать — FAIL.
- [ ] **Step 3:** `findByFilter`: `$r = finder.find(); if ($r->total > 0) hydrate($r); elseif ($filter->search?->hasSingleWord()) hydrate(finder.fuzzyByTitle($filter)); else hydrate($r)`. `fuzzyByTitle` строит тот же QB с фасетами, но WHERE = `WORD_SIMILARITY(:q, cs.title) > 0.6`, ORDER BY similarity.
- [ ] **Step 4:** Миграция: `CREATE INDEX IF NOT EXISTS ix_coating_system_title_trgm ON coating_system USING GIN (title gin_trgm_ops)`.
- [ ] **Step 5:** Прогнать тесты + миграцию — PASS.
- [ ] **Step 6: Commit** — `Опечатки в названии системы: триграммный fallback (как у покрытий)`.

---

## Self-Review

**1. Spec coverage.** Фасет химстойкости (поле + матч) → Task 4,5. Температура в матче → Task 5. Fuzzy веществ, единый метод → Task 1,2. Пикер «этоно→этанол» → Task 2 (+ UI Task 11). by-ids чипов → Task 3. Выравнивание (findByFilter, убить suggest-ILIKE, `search`+`q`-fallback, не сузить) → Task 6,7,8,9,10. UI по образцу `coating-tags` → Task 11. Строковый индекс не режем → не трогаем `CoatingSystemSearchCacheRepository`. Подложка — существующий фасет, не трогаем. Trigram «как у покрытий» для систем → Task 12 (опц.). НЕ здесь: LLM/письмо, объединение страниц, текст по описаниям покрытий, аддитивный OR — ни один таск их не вводит.

**2. Step scan.** Код-шаги несут сигнатуру/SQL, который исполнитель не выведет (all-layers `EXISTS`, fuzzy-fallback, OR-инфикс); тела тривиальных методов (findByFilter-обёртка, by-ids) описаны словами, не транскриптом.

**3. Type consistency.** `findByFilter(CoatingSystemsFilter): PaginationResult` (Task 7) ↔ потребление в Task 8/9. `SubstanceRepository::findByFilter: PaginationResult<SubstanceDTO>` ↔ Task 2. `substanceIds: StringCollection`, `substanceTemperature: ?PositiveNumber` одинаково в Task 4/5/10. by-ids shape `{items:[{id,title}]}` одинаков у Task 3 и ожиданий `coating-tags` resolve-url (Task 11). Suggest shape `{items:[{id,title}]}` ↔ Task 9/11.

**4. Review Focus.** Все пять пунктов имеют владельца-таск с тестом: температура-null (10), UUID-фильтр (10), fuzzy-шум (1), suggest-инфикс-регресс (6/9), пустая система (5).

**5. Proportion.** План сопоставим со спекой; код-блоки — только там, где решают (SQL матча/fts, ключевые тест-ассерты). Остальное — сигнатуры и ссылки на Interfaces соседних тасков.
