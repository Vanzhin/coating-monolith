# Подбор систем: точный минимум по маркировке — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Подбор систем матчит соответствие стандарту **точно** по обеим осям (минимально достаточная система), а не «покрывает вверх».

**Architecture:** Снимаем «≥»-раскрытие запроса в `CoatingSystemFinder::applyCompliance` — матч становится равенством (`csc.category = :cat AND csc.durability = :dur`). Проекция уже хранит только потолочные пары (ISO `strongestOnly`, СП сильнейшая степень на условие), поэтому «потолок == запрос» и есть минимум — данные/оценщики/свёртка не трогаются. Ставшие мёртвыми `expand*` и enum-хелперы `atOrAbove*` удаляются вместе с их тестами.

**Tech Stack:** PHP 8.x, Doctrine DBAL QueryBuilder, PHPUnit, PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-10-26-coating-system-exact-minimum-match-design.md`

## Global Constraints

- Функциональные тесты финдера гоняются **в контейнере** (override `DATABASE_URL`@manager_db/`REDIS_HOST`), юнит — на хосте. См. `reference_test_run_env`.
- Перед финишем — `./run check` (style/phpstan), не только тесты. См. `feedback_sdd_run_check_gates`.
- Мёртвый код убирать вместе с вызовами; грепать по проекту (CLAUDE.md).
- Проекцию `coating_system_compliance`, оценщики, `strongestOnly`, `rank()`/`family()` enum-ов **не трогать**.
- Не коммитить без явной команды пользователя (`feedback_no_commits`); для SDD-имплементеров коммит по задаче разрешён (`feedback_sdd_commits`).

## Review Focus

- **Переспек по категории больше не матчится:** запрос C3-HIGH не должен возвращать систему с потолком C4-HIGH/C5-VH/CX-H. Покрыто тестом Task 1.
- **Переспек по долговечности больше не матчится:** запрос C3-HIGH не должен возвращать систему с потолком C3-VERY_HIGH. Покрыто тестом Task 1.
- **CX/Im4 без второй оси:** залипший `durability` в URL не должен отсеивать CX-строку (долговечность не применяется). Покрыто существующим `test_compliance_cx_filter_ignores_durability_axis`.
- **СП степень — тоже точно:** запрос СП со степенью MEDIUM не должен возвращать систему с потолком STRONG по тому же условию. Покрыто тестом Task 1.
- **Запрос только категории (без долговечности):** возвращает любую долговечность этой точной категории, не пусто. Покрыто тестом Task 1.

---

### Task 1: Точный матч соответствия в финдере

**Files:**
- Modify: `app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php:124-156` (`applyCompliance`)
- Test: `app/tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderTest.php`

**Interfaces:**
- Consumes: `CoatingSystemsFilter` (поля `standard`, `category`, `durability`), `ComplianceFacetsRegistry::facetsFor()`, `StandardFacets::hasSecondaryAxis()`.
- Produces: поведение `CoatingSystemFinder::find()` — выдача только точных совпадений по осям.

- [ ] **Step 1: Переписать функциональный тест на точную семантику.** В `CoatingSystemFinderTest.php` заменить `test_compliance_filter_matches_stronger_categories_and_durabilities` (строки 252-284) на `test_compliance_filter_matches_only_exact_category_and_durability`. Вставлять строки напрямую в `coating_system_compliance` (как в соседних тестах). Для фильтра `standard=ISO_12944, category='C3', durability='HIGH'`:
  - `C3`/`HIGH` → **assertContains** (точный минимум);
  - `C4`/`HIGH` → **assertNotContains** (переспек по категории);
  - `C3`/`VERY_HIGH` → **assertNotContains** (переспек по долговечности);
  - `C2`/`LOW` → **assertNotContains** (слабее);
  - `Im2`/`VERY_HIGH` → **assertNotContains** (другая семья).

- [ ] **Step 2: Добавить функциональный тест для СП.** `test_compliance_sp28_filter_matches_only_exact_degree`: вставить две СП-строки с одним и тем же условием (`SpExploitation` case `->value`) — одну со степенью `SpAggressivity::MEDIUM->value`, другую `SpAggressivity::STRONG->value`. Фильтр `standard=SP_28, category=<MEDIUM->value>, durability=<то же условие>`: MEDIUM-строка → assertContains, STRONG-строка → assertNotContains.

- [ ] **Step 3: Добавить тест «только категория без долговечности».** `test_compliance_filter_category_only_matches_any_durability`: две строки `C3`/`HIGH` и `C3`/`LOW`; фильтр `standard=ISO_12944, category='C3'` (durability null) — обе → assertContains.

- [ ] **Step 4: Прогнать тесты — убедиться, что падают.** Run (в контейнере): `vendor/bin/phpunit --filter 'test_compliance_filter_matches_only_exact_category_and_durability|test_compliance_sp28_filter_matches_only_exact_degree|test_compliance_filter_category_only_matches_any_durability'`. Expected: FAIL (текущий код раскрывает «вверх» → возвращает переспек).

- [ ] **Step 5: Переписать `applyCompliance` на равенство.** В `CoatingSystemFinder::applyCompliance` заменить:
  - `csc.category IN (:csc_category_list)` + `expandPrimary(...)` → `csc.category = :csc_category` + `$filter->category`;
  - `csc.durability IN (:csc_durability_list)` + `expandSecondary(...)` → `csc.durability = :csc_durability` + `$filter->durability`.
  Guard второй оси (`null === $filter->category || $facets->hasSecondaryAxis($filter->category)`) и ветку `null === $facets → 1 = 0` оставить. Комментарий 140-141 заменить на суть: точное совпадение = минимально достаточная система (проекция хранит потолок).

- [ ] **Step 6: Прогнать тесты — зелёные.** Run: тот же `--filter`, плюс весь `CoatingSystemFinderTest`. Expected: PASS (включая существующие `test_compliance_cx_filter_ignores_durability_axis` и тест с фильтром C4/HIGH — они под точный матч проходят без изменений).

- [ ] **Step 7: Commit** (если выбран SDD-режим с коммитами).

```bash
git add app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php app/tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderTest.php
git commit -m "Подбор систем отдаёт минимально достаточную: матч по маркировке стал точным, переспек C4/CX/VH больше не лезет в C3-HIGH"
```

---

### Task 2: Убрать мёртвое «≥»-раскрытие

**Files:**
- Modify: `app/src/Coatings/Domain/Compliance/Facet/StandardFacets.php` (убрать `expandPrimary`/`expandSecondary` из интерфейса + докблок)
- Modify: `app/src/Coatings/Domain/Compliance/Iso12944/Iso12944Facets.php:64-72` (убрать обе реализации)
- Modify: `app/src/Coatings/Domain/Compliance/Sp28/Sp28Facets.php:62-71` (убрать обе реализации)
- Modify: `app/src/Coatings/Domain/Compliance/Iso12944/IsoCorrosivityCategory.php:72` (убрать `atOrAboveInFamily()`)
- Modify: `app/src/Coatings/Domain/Compliance/Iso12944/IsoDurability.php:51` (убрать `atOrAbove()`)
- Modify: `app/src/Coatings/Domain/Compliance/Sp28/SpAggressivity.php:56` (убрать `atOrAbove()`)
- Test: `Iso12944FacetsTest`, `Sp28FacetsTest`, `IsoDurabilityTest`, `IsoCorrosivityCategoryTest` (+ тест `SpAggressivity`, если есть) — снять ассерты удалённых методов

**Interfaces:**
- Consumes: ничего нового (Task 1 уже убрал единственный боевой вызов `expand*`).
- Produces: `StandardFacets` без `expand*`; enum-ы без `atOrAbove*`.

- [ ] **Step 1: Удалить мёртвые методы вместе с их ассертами** (удаление мёртвого кода — отдельного red-теста нет, правим код и его обслуживающие тесты разом, суммарно сборка остаётся зелёной). Убрать:
  - `expandPrimary`/`expandSecondary` из `StandardFacets` (и кусок докблока про «≥-раскрытие»), из `Iso12944Facets`, `Sp28Facets`;
  - `IsoCorrosivityCategory::atOrAboveInFamily()`, `IsoDurability::atOrAbove()`, `SpAggressivity::atOrAbove()`;
  - ассерты этих методов в тестах: `IsoDurabilityTest` (строки 14-17), `IsoCorrosivityCategoryTest` (14-22), `Iso12944FacetsTest`/`Sp28FacetsTest` (ассерты `expand*`), тест `SpAggressivity` если есть. Оставшиеся ассерты (`rank`, `family`, `title`, `badgeLabel`, `hasSecondaryAxis`, `primaryOptions`/`secondaryOptions`) не трогать.
  - Поправить комментарий в `test_compliance_cx_filter_ignores_durability_axis` (строки 296-298) — он ссылается на `expandSecondary`.

- [ ] **Step 2: Грепнуть, что мёртвых ссылок не осталось.** Run: `rtk grep -rn "expandPrimary\|expandSecondary\|atOrAboveInFamily\|atOrAbove" app`. Expected: пусто.

- [ ] **Step 3: Прогнать затронутые тесты + гейт.** Run (хост): `vendor/bin/phpunit tests/Unit/Coatings/Domain/Compliance`; (контейнер) `vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Search`; затем `./run check`. Expected: PASS, гейт чистый.

- [ ] **Step 4: Commit** (если выбран SDD-режим с коммитами).

```bash
git add app/src/Coatings/Domain/Compliance app/tests/Unit/Coatings/Domain/Compliance app/tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderTest.php
git commit -m "Выпилил мёртвое ≥-раскрытие маркировки: подбор точный, expand*/atOrAbove* больше не нужны"
```

---

## Вне плана (следующий заход)

UX-водопад: после выбора стандарта сразу открывать и степень/категорию, и долговечность/условия (сейчас долговечность гейтится до выбора категории). Отдельная фронт-задача.
