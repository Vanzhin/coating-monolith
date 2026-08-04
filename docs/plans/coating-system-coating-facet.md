# План: фасет «Покрытия» в поиске систем

Финальное место после апрува — `docs/plans/coating-system-coating-facet.md` (по CLAUDE.md `docs/plans/`).

## Контекст

На странице `/cabinet/coating/coating-system/list` есть фасеты substrate, compliance-каскад, tags, два range'а. Нужен ещё один — **по покрытиям в составе системы**: пользователь выбирает одно или несколько покрытий (чип-мультивыбор с typeahead), в выдачу попадают системы, у которых хотя бы один слой — выбранное покрытие.

**Семантика — OR** (решение пользователя): система проходит, если содержит **хотя бы одно** из выбранных покрытий. Консистентно с уже существующими фасетами substrate и tags (`EXISTS … IN`).

Ближайший аналог — фасет «Теги»:
- фильтрация в `CoatingSystemFinder::applyTags` через `EXISTS (… coating_system_tag … tag_id IN (…))`;
- UI — Tagify-инпут `data-controller="coating-tags"` с suggest-URL и скрытыми инпутами `tagIds[]`.

Слои систем лежат в таблице `coating_system_layer` (ORM `CoatingSystem.CoatingSystemLayer.orm.xml`): FK `system_id` → система, FK `coating_id` → покрытие. Значит фильтр по покрытию — тот же `EXISTS`, но по `coating_system_layer`.

Typeahead-эндпоинт покрытий `app_cabinet_coating_coating_suggest` уже есть и открыт всем пользователям кабинета (отдаёт `{items:[{id,title,base,dftMin,dftMax}]}`).

**JS не пишем.** Контроллер `coating-tags` (`app/assets/controllers/coating_tags_controller.js`) уже generic: параметризован `suggestUrl`/`hiddenInputName`/`allowCreate`/`existing`, маппит suggest-ответ как `{value: item.title, id: item.id}` — coating-suggest ложится без правок. Переиспользуем как есть (решение пользователя — не переименовывать, несмотря на «tags» в имени).

**Кэш не трогаем.** Фильтр по «сырой» таблице слоёв — как теги по `coating_system_tag`. Ни `coating_system_search`, ни `coating_system_compliance` изменять не нужно.

## Изменения

### 1. `CoatingSystemsFilter` — новое поле

Файл: `app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php`

Добавить `public StringCollection $coatingIds = new StringCollection()` — как id-коллекции в сестринском `CoatingsFilter` (`manufacturerIds`/`tagIds` там уже `StringCollection`). Заодно перевести соседнее `$tagIds` на `StringCollection` (было `array`) — для консистентности. Импорт `App\Shared\Domain\Aggregate\Collection\StringCollection`.

### 2. `CoatingSystemFinder` — фильтрация

Файл: `app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php`

- В `find()` после `$this->applyTags($qb, $filter);` добавить `$this->applyCoatings($qb, $filter);`.
- Новый метод `applyCoatings` — калька с `applyTags`:

```php
private function applyCoatings(QueryBuilder $qb, CoatingSystemsFilter $filter): void
{
    if (0 === $filter->coatingIds->count()) {
        return;
    }

    $qb->andWhere('EXISTS (SELECT 1 FROM coating_system_layer csl WHERE csl.system_id = cs.id AND csl.coating_id IN (:coating_ids))')
        ->setParameter('coating_ids', $filter->coatingIds->getList(), ArrayParameterType::STRING);
}
```

OR-семантика — свойство `IN (…)`: система проходит, если у неё есть слой с любым покрытием из списка. `EXISTS` не размножает строки cs (в отличие от JOIN), `COUNT(DISTINCT cs.id)` и пагинация не ломаются.

### 3. `ListAction` — тонкий: только фильтр → query

Файл: `app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php`

Экшен только собирает данные из request и строит фильтр — никакого резолва/репозитория (см. [[feedback_thin_controller]]). Восстановление названий чипов делает JS-гидрация, не сервер (см. п.3b и [[feedback_filter_state_in_url]]).

- Дочитать `coatingIds[]` из query (валидные uuid) и собрать в `StringCollection`:
  ```php
  $coatingIds = new StringCollection(...array_values(array_filter(
      array_map('strval', (array) $request->query->all('coatingIds')),
      static fn (string $id) => Uuid::isValid($id),
  )));
  ```
  (Импорт `Symfony\Component\Uid\Uuid` и `StringCollection`.) `$tagIds` — тоже `StringCollection`.
- Прокинуть `coatingIds`/`tagIds` в `CoatingSystemsFilter`, выполнить один `SearchCoatingSystemsQuery`.
- В render: `'coatingIds' => $coatingIds->getList()`, `'tagIds' => $tagIds->getList()` (шаблонный `…|length` не меняется). `selectedCoatings`/резолв — НЕ добавляем.

### 3a. JSON-эндпоинт by-ids

Файл: `app/src/Coatings/Infrastructure/Controller/Coating/ByIdsAction.php` (per-action, рядом с `SuggestAction`).

`GET /cabinet/coating/coating/by-ids?ids[]=…` → `{items:[{id,title}]}`. Тонкий: читает ids (валидные uuid, cap 50) → `queryBus->execute(new GetCoatingsByIdsQuery($ids))` (готовый query) → мапит DTO в `{id,title}`. Открыт всем в кабинете (без `ROLE_ADMIN`, как suggest). Логика — в хендлере query.

### 3b. JS-гидрация чипов — `coating_tags_controller.js`

Два опциональных value: `preselectedIds: Array`, `resolveUrl: String`. На `connect`, если `existing` пуст и заданы оба — сразу проставить hidden-инпуты из id (фильтр переживает ресабмит до догрузки), затем async `fetch(resolveUrl?ids[]=…)` → `addTags({value:title,id})`. Обратно совместимо (дефолты пустые). Заодно `_fetchSuggest` научить понимать оба shape suggest-ответа: голый массив (тег-suggest) и `{items:[…]}` (coating-suggest) — иначе typeahead покрытий не работает.

### 4. Шаблон `list.html.twig` — секция фасета + индикаторы

Файл: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig`

- Флаг `{% set coatingsActive = coatingIds|length > 0 %}` рядом с `tagsActive`; `+ coatingIds|length` в счётчик; `or coatingsActive` в empty-state.
- Секция «Покрытия в составе» — копия блока «Теги», Tagify `coating-tags`, `suggest-url = app_cabinet_coating_coating_suggest`, `hidden-input-name = coatingIds[]`, `allow-create = false`, плюс `preselected-ids-value = {{ coatingIds|json_encode }}`, `resolve-url-value = path('app_cabinet_coating_coating_by_ids')`, `existing = []`.

Стили не сочиняем — 1-в-1 разметка блока «Теги».

### 4a. Compliance DTO (побочная чистка, по просьбе)

`CoatingSystemDTO::$compliance`: `list<array{standard,category,durability}>` → `list<ComplianceMatchDTO>` (новый голый DTO, см. [[feedback_no_array_shape_dto]]). Transformer мапит домен `ComplianceMatch` → `ComplianceMatchDTO`. Шаблоны/JS не тронуты — публичные поля сериализуются в тот же shape.

### 5. Ассеты

JS/CSS не менялись (`coating-tags` уже зарегистрирован в `controllers.json`). Пересборка не обязательна, но для консистентности после правки Twig прогнать `cd app && yarn dev`.

## Тесты

### `CoatingSystemFinderTest`

Файл: `app/tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderTest.php`

Сейчас `buildAndSaveSystem` создаёт новое покрытие внутри себя — для теста фасета нужен контроль над тем, какое покрытие в системе. Расширить хелпер: перегрузка/параметр, позволяющая передать готовое `Coating` (или вернуть созданное покрытие наружу), чтобы разные системы могли делить одно покрытие и/или иметь разные.

Новый тест OR-семантики `test_coating_filter_includes_systems_with_selected_coating`:
- покрытия A, B, C (persist);
- система S1 со слоем A, S2 со слоем B, S3 со слоями A+C;
- фильтр `coatingIds=[A]` → {S1, S3};
- фильтр `coatingIds=[A, B]` → {S1, S2, S3} (OR);
- фильтр `coatingIds=[C]` → {S3};
- `total` совпадает с числом id (проверка, что `EXISTS` не размножает строки).

### `ByIdsActionTest`

Файл: `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/ByIdsActionTest.php`

Пользователь без `ROLE_ADMIN` (заодно доказывает открытость). `?ids[]=<uuid>` → `{items:[{id,title}]}`; пустой ids → `[]`; невалидный id отсекается.

### Флаки-фикс (побочно)

`CoatingSystemFinderTest::test_total_is_independent_of_pagination` был флаки (случайный hex-суффикс + russian-FTS токенизировал нестабильно → поиск иногда матчил 0). Заменён на алфавитный маркер (`randomWord()`) — стабильно. Не связан с фасетом (проверено на HEAD и до-INNER-JOIN — падал 3-5/5).

## Команды

```bash
# тесты контекста
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Search/CoatingSystemFinderTest.php
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Coatings/Infrastructure/Controller/CoatingSystem/ListActionTest.php

# статика/стиль
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/php-cs-fixer fix --dry-run --diff

# ассеты
cd app && yarn dev
```

## Развилки (зафиксировано)

- **Семантика** — OR (хотя бы одно из выбранных). *(Изначально обсуждали AND, пользователь переключил на OR.)*
- **JS-контроллер** — переиспользуем `coating-tags` как есть, без переименования.
- **Кэш** — не участвует, фильтр по `coating_system_layer`.
- **Состояние в URL** — весь выбор в query-параметрах (shareable-ссылки); чипы восстанавливает JS-гидрация через by-ids эндпоинт, БЕЗ резолва названий на сервере (решение пользователя, см. [[feedback_filter_state_in_url]]). `selectedCoatings` в результате query — отменили.
- **Тип id-полей фильтра** — `StringCollection`, не `array` (как в `CoatingsFilter`). Переведены `coatingIds` и `tagIds`. Глобальная унификация — в бэклог.
- **Слои** — вложить `CoatingDTO` в `CoatingSystemLayerDTO` — вынесено в отдельный план (по решению пользователя).

## Затронутые файлы (итог)

Изменить:
- `app/src/Coatings/Domain/Repository/CoatingSystemsFilter.php` (+`tagIds` на StringCollection)
- `app/src/Coatings/Infrastructure/Search/CoatingSystemFinder.php`
- `app/src/Coatings/Infrastructure/Controller/CoatingSystem/ListAction.php`
- `app/src/Coatings/Application/DTO/CoatingSystems/CoatingSystemDTO.php` + `CoatingSystemDTOTransformer.php`
- `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/list.html.twig`
- `app/assets/controllers/coating_tags_controller.js`
- тесты: `CoatingSystemFinderTest`, `CoatingSystemsFilterTest`, `CoatingSystemDTOTransformerTest`

Создать:
- `app/src/Coatings/Infrastructure/Controller/Coating/ByIdsAction.php`
- `app/src/Coatings/Application/DTO/CoatingSystems/ComplianceMatchDTO.php`
- `app/tests/Functional/Coatings/Infrastructure/Controller/Coating/ByIdsActionTest.php`

Не трогаем: кэш-репозитории, миграции, ORM-маппинг, CSS.
