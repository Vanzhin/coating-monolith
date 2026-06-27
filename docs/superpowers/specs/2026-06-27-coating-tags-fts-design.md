# Семантические теги покрытий через FTS

**Статус:** draft
**Дата:** 2026-06-27

## Цель

Пользователь набирает в **основном** поисковом окне списка покрытий «для бетона» / «толерантное к подготовке» — система возвращает покрытия, к которым админ привязал соответствующие теги. Один поисковый бар покрывает и текстовый match по title/description, и теги. Расширенные численные фильтры (ТСП, мин Т нанесения, dft) — отдельная задача, в этот скоуп не входят.

Поддержка двух сценариев для админа:
1. Прикрутить уже существующий general-тег к покрытию.
2. Прямо в форме редактирования покрытия завести **новый** general-тег и сразу привязать.

## Контекст

Что есть в проекте:
- `CoatingTag(title, type)` агрегат, M2M `coatings_coating_coating_tag`. В проде 8 тегов: 3 `CoatingCoatType` (top/middle/primer), 4 `CoatingProtectionType`, 1 кривой (immersion внутри CoatingCoatType — мы его не трогаем).
- `UniqueTitleAndTypeCoatingTagSpecification` уже гарантирует уникальность пары `(title, type)`.
- FTS на Postgres:
  - Таблица `coatings_coating_search(coating_id, search_vector)`.
  - GIN-индекс на `search_vector`.
  - PL/pgSQL функция `coatings_coating_search_upsert()` пересоздаёт вектор из `title(A) + description(A)`.
  - Триггер `coatings_coating_search_upsert_trigger AFTER INSERT OR UPDATE OF title, description ON coatings_coating`.
  - Backfill миграцией для существующих coatings.
- `CoatingFinder::fullText(CoatingsFilter $filter)` — основной путь поиска: разбивает запрос на слова, делает префиксный `tsquery`, ранжирует по `ts_rank_cd`.
- `CoatingFinder::fuzzyTitle(...)` — резервный путь через `WORD_SIMILARITY` (pg_trgm), порог 0.4.
- `CoatingsFilter` — `$search`, `$manufacturerIds`, `$pager`. Тегов в фильтре нет (но фильтр по тегам нам и не нужен — теги попадают в `search_vector`).

Чего нет:
- Теги не участвуют в `search_vector`.
- Нет API/UI для создания тега «на лету» из формы покрытия.
- Type='general' как категория не существует.

## Решения, зафиксированные на brainstorming

| Вопрос | Решение |
|---|---|
| Где обрабатывается «для бетона» | В основном search-инпуте через FTS — теги добавляются в `search_vector` |
| Природа новых тегов | Используем существующий `type` поле + новое значение `'general'` |
| Создание тега на лету | Два разделённых API-эндпоинта: `suggest` (FTS-поиск похожих, никогда не создаёт) + `create` (явное создание general-тега, отдельный AJAX). Никакого resolveOrCreate в Coating handler'е. |
| Веса в FTS | title=A, description=A, tags=B (теги слабее, чтобы точное совпадение в title ранжировалось выше) |
| Куда вешать pivot-триггеры | На `coatings_coating_coating_tag` (INSERT/DELETE) и на `coatings_coating_tag` (UPDATE OF title) |

## Архитектура

```
[Юзер пишет «для бетона»]
       │
       ▼
GET /cabinet/coating/coating/list?search=для+бетона
       │
       ▼
CoatingFinder::fullText(CoatingsFilter $filter)
   ├─ применяет tsquery «для:* & бетона:*» к search_vector
   └─ ранжирует по ts_rank_cd
       │
       ▼
coatings_coating_search.search_vector
       ▲
       │   обновляется триггерами:
       │
   ┌───┴──────────────────────────────────┐
   │  AFTER INSERT/UPDATE title/desc      │
   │    ON coatings_coating               │  → rebuild(coating_id)
   ├──────────────────────────────────────┤
   │  AFTER INSERT/DELETE                 │
   │    ON coatings_coating_coating_tag   │  → rebuild(coating_id)
   ├──────────────────────────────────────┤
   │  AFTER UPDATE OF title               │
   │    ON coatings_coating_tag           │  → rebuild всех связанных coating_id
   └──────────────────────────────────────┘
```

Принцип: **PostgreSQL делает агрегацию через триггеры; PHP-код FTS не меняется ни одной строкой**. Если в будущем понадобится подсосать в вектор ещё `manufacturer.title` или `base.title` — это правка одной SQL-функции без выкатки приложения.

## 1. Domain

`App\Coatings\Domain\Aggregate\Coating\CoatingTag`:
- Добавить публичную константу `public const TYPE_GENERAL = 'general'`.
- Конструктор и сеттеры не меняются — `type` уже nullable string. Существующий `UniqueTitleAndTypeCoatingTagSpecification` сам обеспечит уникальность пары `(title, 'general')`.

Никаких других domain-правок.

## 2. PostgreSQL: миграция

Новый файл `app/src/Shared/Infrastructure/Database/Migrations/Version<TIMESTAMP>.php` (имя сгенерится через `bin/console doctrine:migrations:diff` или вручную). Содержит:

### up()

```sql
-- 1. Новая «единая» rebuild-функция: принимает coating_id, агрегирует title+desc+tags.
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
$$ LANGUAGE plpgsql;

-- 2. Триггер на coatings_coating (заменяем старый upsert на rebuild).
DROP TRIGGER IF EXISTS coatings_coating_search_upsert_trigger ON coatings_coating;
DROP FUNCTION IF EXISTS coatings_coating_search_upsert();

CREATE OR REPLACE FUNCTION coatings_coating_search_trigger_coating()
RETURNS trigger AS $$
BEGIN
    PERFORM coatings_coating_search_rebuild(NEW.id);
    RETURN NEW;
END
$$ LANGUAGE plpgsql;

CREATE TRIGGER coatings_coating_search_after_coating
AFTER INSERT OR UPDATE OF title, description
ON coatings_coating
FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_trigger_coating();

-- 3. Триггер на pivot table.
CREATE OR REPLACE FUNCTION coatings_coating_search_trigger_pivot()
RETURNS trigger AS $$
DECLARE
    affected_coating_id uuid;
BEGIN
    -- При INSERT смотрим NEW.coating_id; при DELETE — OLD.coating_id.
    affected_coating_id := COALESCE(NEW.coating_id, OLD.coating_id);
    PERFORM coatings_coating_search_rebuild(affected_coating_id);
    RETURN NULL;
END
$$ LANGUAGE plpgsql;

CREATE TRIGGER coatings_coating_search_after_pivot
AFTER INSERT OR DELETE
ON coatings_coating_coating_tag
FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_trigger_pivot();

-- 4. Триггер на coatings_coating_tag (UPDATE title) — пересобрать вектор у всех связанных coating'ов.
CREATE OR REPLACE FUNCTION coatings_coating_search_trigger_tag()
RETURNS trigger AS $$
BEGIN
    PERFORM coatings_coating_search_rebuild(ct.coating_id)
    FROM coatings_coating_coating_tag ct
    WHERE ct.coating_tag_id = NEW.id;
    RETURN NEW;
END
$$ LANGUAGE plpgsql;

CREATE TRIGGER coatings_coating_search_after_tag
AFTER UPDATE OF title
ON coatings_coating_tag
FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_trigger_tag();

-- 5. Backfill.
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
ON CONFLICT (coating_id) DO UPDATE SET search_vector = EXCLUDED.search_vector;
```

### down()

```sql
DROP TRIGGER IF EXISTS coatings_coating_search_after_tag ON coatings_coating_tag;
DROP TRIGGER IF EXISTS coatings_coating_search_after_pivot ON coatings_coating_coating_tag;
DROP TRIGGER IF EXISTS coatings_coating_search_after_coating ON coatings_coating;

DROP FUNCTION IF EXISTS coatings_coating_search_trigger_tag();
DROP FUNCTION IF EXISTS coatings_coating_search_trigger_pivot();
DROP FUNCTION IF EXISTS coatings_coating_search_trigger_coating();
DROP FUNCTION IF EXISTS coatings_coating_search_rebuild(uuid);

-- Восстанавливаем оригинальный upsert (без тегов).
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
$$ LANGUAGE plpgsql;

CREATE TRIGGER coatings_coating_search_upsert_trigger
AFTER INSERT OR UPDATE OF title, description
ON coatings_coating
FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_upsert();
```

## 3. Tagify в форме покрытия

### Два разделённых эндпоинта

**1. `GET /cabinet/coating/coating-tag/suggest?q=<query>&type=general`** — поиск похожих тегов для autocomplete.
- Возвращает JSON: `[{id: '…', title: 'Для бетона'}, …]`. До 10 элементов.
- Логика поиска по `coatings_coating_tag.title`:
  - **FTS path**: prefix-tsquery `<query>:* ...` против `to_tsvector('russian', title)`.
  - **Fuzzy fallback**: если FTS пусто — `WORD_SIMILARITY(query, title) > 0.4`.
  - В обоих случаях фильтр `type = 'general'`.
- **Никогда не создаёт** — это чистый read-side.
- Реализуется через новый `SuggestTagsAction` controller + новый `CoatingTagFinder` сервис (по аналогии с `CoatingFinder`).
- Доступ: `ROLE_ADMIN`.

**2. `POST /cabinet/coating/coating-tag`** — явное создание general-тега.
- Body: `{title: 'Для бетона'}`.
- Возвращает: `{id: '…', title: 'Для бетона'}` (201 Created) или 422 с AppException-сообщением при ошибке (например, тег с таким title+general уже существует).
- Логика — новый `CreateGeneralTagCommandHandler`:
  ```php
  $existing = $repo->findOneByTitleAndType($title, CoatingTag::TYPE_GENERAL);
  if ($existing !== null) {
      throw new AppException("Тег «$title» уже существует.");
  }
  $tag = new CoatingTag($title, $spec, CoatingTag::TYPE_GENERAL);
  $repo->add($tag);
  return $tag;
  ```
- Доступ: `ROLE_ADMIN`.

### Frontend (Stimulus + Tagify)

Сейчас в `form.html.twig` теги отображаются как multi-select. Заменяем на Tagify-инпут с двумя AJAX-эндпоинтами:

```twig
<input type="text" id="coating-tags-tagify"
       data-controller="coating-tags"
       data-coating-tags-existing-value="{{ existingTagsJson }}"
       data-coating-tags-suggest-url-value="{{ path('app_cabinet_coating_coating_tag_suggest') }}"
       data-coating-tags-create-url-value="{{ path('app_cabinet_coating_coating_tag_create') }}">
```

Stimulus-контроллер `coating_tags_controller.js`:
- При `connect()` инициализирует Tagify с `whitelist: []` и `enforceWhitelist: true`.
- На событие `input` Tagify — debounced AJAX `GET suggest?q=<input>&type=general`. Загружает результаты в `whitelist`, обновляет dropdown.
- Если в dropdown'е ничего нет (юзер ввёл слово, которого нет среди существующих) — Tagify показывает кастомный template «Создать "<input>"». Клик на него:
  - `POST create` с `{title: <input>}`.
  - При 201 — берёт `{id, title}` из ответа, добавляет тег как чип в Tagify, обновляет hidden inputs формы.
  - При 422 — показывает inline-ошибку под полем (например, «такой тег уже есть» с подсветкой кандидата в whitelist).
- При сабмите формы покрытия Tagify отдаёт массив `tags[N][id]=…` (всегда с id, не title — теги в этот момент уже существуют в БД).

### Backend (Coating form)

`CoatingTagDTO` остаётся без изменений — только `$id` (как сейчас). **Не добавляем `$title`**, потому что к моменту сабмита формы покрытия все теги уже существуют (либо были, либо созданы через отдельный create-эндпоинт). Это строгий контракт.

`CoatingMapper::buildCoatingDtoFromInputData` — читает `tags[][id]` точно как сейчас. Никаких правок.

`CreateCoatingCommandHandler` / `UpdateCoatingCommandHandler` — строго требуют существующий тег по id:
```php
$tag = $repo->findOneById($dto->id);
if ($tag === null) {
    throw new AppException("Тег с id $dto->id не найден.");
}
```
Никаких side-effects вроде создания тега из handler'а покрытия.

## 4. Зависимости

Tagify — `@yaireo/tagify`, лицензия MIT, ~25 KB gz. Добавить в `package.json`. Импортить в `coating_tags_controller.js`.

## 5. Тесты

### Unit

- `CoatingTagTest::testCanCreateWithGeneralType` — `new CoatingTag('Для бетона', $spec, CoatingTag::TYPE_GENERAL)` валиден.
- `CoatingTagTest::testRejectsDuplicateTitleWithinSameType` — оставить существующий тест если он есть.

### Functional

- `SuggestTagsActionTest::testReturnsGeneralTagsByFtsPrefix` — создать в БД теги «Для бетона», «Для стали», «Top» (тип CoatType). GET `?q=для&type=general` → возвращает первые два, не Top.
- `SuggestTagsActionTest::testFallsBackToFuzzyWhenFtsEmpty` — тег «Для бетона». GET `?q=бетано` (опечатка) → FTS пусто, fuzzy ловит, возвращает «Для бетона».
- `SuggestTagsActionTest::testEmptyQueryReturnsEmpty` — GET `?q=&type=general` → 400 или пустой массив.
- `CreateGeneralTagActionTest::testCreatesNewGeneralTag` — POST `{title: 'Для бетона'}` → 201, в БД появился `CoatingTag(title='Для бетона', type='general')`.
- `CreateGeneralTagActionTest::testRejectsDuplicate` — POST повторно с тем же title → 422 + AppException-сообщение.
- `CreateCoatingActionTest::testCreatesCoatingWithExistingTagId` — POST с `tags[0][id]=<existing-tag-id>` → coating сохранён, связь в pivot. Запрос FTS «для бетон» возвращает coating.
- `CreateCoatingActionTest::testRejectsUnknownTagId` — POST с `tags[0][id]=<random-uuid>` → 422 «тег не найден». Coating handler НЕ создаёт теги silently.

### Migration smoke

- `MigrationSearchVectorWithTagsTest` (новый, в `tests/Functional/Coatings/Infrastructure/Search/`): после миграции и backfill — coating с тегом «Для бетона» возвращается на запрос FTS «для бетон» через `CoatingFinder::fullText`.

## 6. Что НЕ входит

- **Расширенный поиск** — фасеты по ТСП, мин температуре, dft и т.д. — отдельная задача.
- **Удаление и переименование тегов из админки** — текущий CRUD тегов мы не трогаем (он есть и так).
- **Tag analytics** (сколько покрытий с каким тегом) — не нужно.
- **Синонимы тегов** («бетон» ≡ «железобетон» ≡ «ЖБИ») — не делаем. Postgres FTS со словарём `russian` нормализует слова до основы — хватит на 95% случаев.
- **Авто-предложение тегов на основе title/description покрытия** — не делаем.
- **Миграция существующих не-general тегов на новую UI** — оставляем как есть (отдельный select для CoatingCoatType / CoatingProtectionType, Tagify только для general).
- **Permission на создание тега** — любой `ROLE_ADMIN` (как и сейчас на форму покрытия).

## Открытые вопросы

Нет.

## Post-implementation findings (2026-06-27, после интерактивной отладки)

Эта секция накопилась после того как пользователь протестировал готовую фичу в браузере. Изменения уже в основной ветке, но в исходной спеке не были предусмотрены — фиксирую тут, чтобы при будущих правках поиска у нас был ground truth.

### A. Отдельная search-таблица для тегов

Исходный план (Task 3) использовал runtime `TO_TSVECTOR(:lang, t.title)` прямо в `CoatingTagFinder::fullText`. Это работало, но шло против архитектуры `coatings_coating_search` для покрытий: там precomputed `tsvector` + GIN + триггеры.

Пользователь поднял вопрос: «а где отдельная таблица для поиска по тегам, как для покрытий?». Согласились выровнять.

Добавлено:
- **`coatings_coating_tag_search`** — `tag_id varchar(36) PK FK CASCADE`, `search_vector tsvector NOT NULL`, GIN-индекс. Миграция `Version20260627174800`.
- **`coatings_coating_tag_search_rebuild(varchar)`** — upsert функция.
- **Триггер AFTER INSERT OR UPDATE OF title ON coatings_coating_tag** — вызывает rebuild. На DELETE — CASCADE по FK.
- **GIN-trgm индекс на `coatings_coating_tag.title`** — ускоряет fuzzy WORD_SIMILARITY fallback.
- **`App\Coatings\Domain\Aggregate\Coating\CoatingTagSearch`** — read-only entity (зеркаль `CoatingSearch`).
- **ORM mapping** `Coating.CoatingTagSearch.orm.xml`.
- **`CoatingTagFinder::fullText`** переключён с runtime `TO_TSVECTOR` на `INNER JOIN CoatingTagSearch s WITH s.tagId = t.id` + `TS_MATCH(s.searchVector, ...)`.

Тип PK — `varchar(36)`, а не нативный `uuid`, потому что `CoatingTag.id` исторически хранится как varchar. Зеркаль `CoatingSearch.coating_id uuid` (там Coating.id — нативный uuid). Не унифицируем сейчас — это отдельный рефакторинг наследия.

Главный поиск coatings (`CoatingFinder.fullText`) с этой таблицей не объединяется. `coatings_coating_search` уже включает `tag.title` весом B (это сделано в коммите `0242ee4`). Tag-search обслуживает ТОЛЬКО Tagify-autocomplete в форме покрытия.

Тест `CoatingTagFinderTest::testSuggestFindsSuperByPrefixSupe` — фиксирует кейс «супе» → «супер»: русский Snowball-стеммер режет `-е` → лексема `суп`, indexed `супер` остаётся как `супер`; prefix-tsquery `суп:*` всё равно матчит лексему `супер`. Этот тест был критичен потому что пользователь сначала сообщил что «не находит», а оказалось — другой баг во фронте, а сам матч работает.

### B. N+1 на странице списка покрытий

`CoatingFinder::coatingQueryBuilder()` не делал eager-load `tags`. После того как карточки списка стали рендерить чипы тегов (`coating.tags`), на каждое покрытие лезла lazy-загрузка → N+1.

Фикс:
```php
->select('cc', 't')
->from(Coating::class, 'cc')
->leftJoin('cc.tags', 't')
```
+ переключение `paginate()` на `fetchJoinCollection: true` — Doctrine разбивает на 2-фазный план (DISTINCT id-subquery + data-query с join'ами), что корректно считает LIMIT/OFFSET при to-many join'е.

Manufacturer на странице списка пока остаётся lazy — отложили (тег один на один coating потенциально N тегов, manufacturer всегда один → impact меньше).

### C. Tag chips на карточках списка

`admin/coating/coating/index.html.twig`: под description в каждой карточке списка добавлен ряд badge-чипов с tag.title (`badge text-bg-light border fw-normal`, `d-flex flex-wrap gap-1`). В preview-модалке чипы уже были (line 137-143 шаблона), не трогали.

### D. Tagify v4.38 — внутренности, на которые ушёл день отладки

При тестировании autocomplete'а в браузере всплыли неочевидные особенности Tagify. Фиксирую их здесь — это переиспользуется при любой следующей задаче с suggest-input'ом:

1. **Auto-`dropdown.show` ПЕРЕД триггером `'input'`-event**. В `Tagify.onInput` вызов `this.dropdown[i?"show":"hide"](s)` идёт ДО `this.trigger("input",a)`. Значит твой `tagify.on('input', ...)` обработчик — слишком поздно для первого решения «открывать ли dropdown». На первом символе whitelist пустой → `show` уходит в early-exit. После обновления whitelist в обработчике надо явно звать `dropdown.show(query)`.

2. **`dropdown.show(t)` early-exit на пустом whitelist'е**: `r && !o && !templates.dropdownItemNoMatch` — если whitelist пустой, режим не mix, и нет noMatch-шаблона → молча выходит, ничего не рендерит и не скрывает. Поэтому первый keystroke у нас не показывал dropdown.

3. **`refilter(t)` ЗАКРЫВАЕТ dropdown** если фильтрация дала 0: `this.suggestedListItems.length || this.dropdown.hide()`. Для нашего сценария «обновить whitelist на лету» использовать НЕ `refilter`, а `show(query)`.

4. **`mappedValue` на item игнорируется дефолтным рендером**. `getMappedValue(item)` читает имя поля из `dropdown.mapValueTo`. Без него — возвращает `item.value`. Чтобы synthetic-плейсхолдеры показывали кастомный текст («Идёт поиск…», «+ Создать «X»»), нужно `dropdown.mapValueTo: 'mappedValue'`.

5. **`isTagDuplicate` в `filterListItems`** режет items, чьё value совпадает с уже-добавленным чипом. Synthetic items (loading/create) имеют `value=query` и могут коллидить с реальными тегами. Лечится `dropdown.includeSelectedTags: true`.

6. **`filterListItems` строит фильтр** как `searchText = concat(item[k] for k in searchKeys)`, проверяет что КАЖДОЕ слово запроса (split по пробелу) есть substring'ом в searchText. Поэтому `searchBy: query` на item — надёжный способ заставить Tagify-фильтр пропустить fuzzy-результаты сервера (типа «супер» по запросу «сап»), которые не substring title.

7. **Dropdown-методы байндятся к tagify в конструкторе** (`function x(){this.dropdown[t] = this._dropdown[t].bind(this)}`). То есть `tagify.dropdown.show(x)` уже имеет правильный `this` — `.call(tagify, x)` лишний (но не вредит).

### E. UX-правила tag-input'а

После итераций с пользователем зафиксировались:
- **Loading-плейсхолдер «Идёт поиск…» сразу после первого символа** (synthetic item с `__loading: true`, `mappedValue`). Без него пользователь не понимает, что что-то происходит.
- **«+ Создать «X»» всегда показывается** если ни один найденный тег не совпадает точно (а не только когда whitelist пустой, как в исходном `dropdownItemNoMatch`).
- **Создание тега ТОЛЬКО по явному клику** на «+ Создать «X»». `addTagOn: []`, `addTagOnBlur: false` — Enter / Tab / Blur НЕ создают тег. Раньше дефолтные триггеры случайно создавали обрывки слов («супе» вместо ожидаемого «супер»).
- **Запрос suggest без `type`-фильтра** — ищем по всем тегам (general + CoatType + ProtectionType). Создание остаётся только `type=general`.
- **Race-protection** через `_fetchSeq` — старый ответ дропаем, если уже инициирован новый запрос.

### F. Бэклог (deferred)

- F6 (из финального ревью): functional tests на rename/unbind tag → проверить что search_vector обновляется триггерами.
- F9 (из ревью): GIN-trgm индекс на `coatings_coating_tag.title` — теперь добавлен в `Version20260627174800`, можно снять с бэклога.
- N+1 на manufacturer в списке покрытий — пока живёт.
- Возможный unified fuzzy-порог для CoatingFinder vs CoatingTagFinder (сейчас оба 0.4).

