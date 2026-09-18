# Офлайн-каталог покрытий (Д0) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: используйте superpowers:subagent-driven-development
> (рекомендуется) или superpowers:executing-plans для реализации задача-за-задачей. Шаги —
> чекбоксы (`- [ ]`).

**Goal:** Калькуляторы `/tools` ищут покрытие и подставляют его значения полностью офлайн;
каталог покрытий кешируется на устройстве в IndexedDB и обновляется по ETag при онлайне.

**Architecture:** Тонкий authed-эндпоинт под `/cabinet` отдаёт весь `CoatingSuggestDTO`-список
одним ответом с ETag (`sha1` тела). Клиент кеширует список в IndexedDB и обновляет полной
заменой по `If-None-Match` (≤1000 покрытий → дельты не нужны). `async_typeahead` получает
опциональный источник данных `catalog` (local-first + сетевой фолбэк); калькуляторы не меняются.

**Tech Stack:** Symfony (Controller/QueryBus, `JsonResponse::setEtag`/`isNotModified`), Doctrine,
Stimulus (Hotwired) + Tagify, нативный IndexedDB (без внешней либы), Webpack Encore (yarn).

**Spec:** `docs/plans/field-reports-0-offline-catalog.md` (дизайн, зафиксированные развилки,
контекст серии field-reports).

## Global Constraints

- **PHP-хендлеры регистрируются через интерфейс** (`QueryHandlerInterface`), НЕ через
  `#[AsMessageHandler]`. Образец — существующий `SearchCoatingsQueryHandler`.
- **Контроллер тонкий** — читает запрос, дергает QueryBus, отдаёт `JsonResponse`. Без бизнес-логики.
- **Без `*Finder`/`*Fetcher`-обёрток** — метод `allForSuggest()` прямо на репозитории.
- **Меньше кода** — нативный IndexedDB, без `idb`/`localforage`. Один shape-нормализатор на
  suggest и catalog (ETag = хеш этого shape; два сериализатора = баг).
- **ETag = `sha1` тела ответа.** Никаких `updated_at`-токенов/дельт/tombstone'ов.
- **Каталог ≤1000 покрытий**, глобален для всех авторизованных, приватного нет.
- **Фронт не покрывается `phpunit`** — верификация JS/Twig = `cd app && yarn dev` + браузерный
  смоук (DevTools Offline). PHP-тесты трогать не для фронта.
- **Окружение тестов:** unit на хосте; functional — в контейнере с override
  `DATABASE_URL@manager_db`/`REDIS_HOST`; cs-fixer/phpstan — в контейнере `manager_php-fpm`
  (хост PHP 8.5 слишком новый). Финальный гейт: `./run check` (заложить чистку style/phpstan).
- **Без миграций** (только чтение существующих покрытий).
- **Коммиты/пуш — только по явному апруву разработчика.** Шаги «Commit» ниже — часть плана; при
  SDD-исполнении имплементер коммитит по задаче (для ревью/леджера), иначе правки живут в дереве.
- **Ветка** от `main`: `feat/offline-coating-catalog`.

---

### Task 1: Репозиторий — `allForSuggest()`

**Files:**
- Modify: `app/src/Coatings/Domain/Repository/CoatingRepositoryInterface.php` (добавить метод)
- Modify: `app/src/Coatings/Infrastructure/Repository/CoatingRepository.php` (реализация)
- Test: `app/tests/Functional/Coatings/Infrastructure/Repository/CoatingRepositoryAllForSuggestTest.php`

**Interfaces:**
- Consumes: существующая проекция `CoatingSuggestDTO` и способ её гидрации в `SearchCoatings`
  (тот же SELECT/hydration).
- Produces: `CoatingRepositoryInterface::allForSuggest(): array` → `list<CoatingSuggestDTO>`
  (весь каталог, без фильтра/пейджера, стабильный порядок — по `title` или `id`).

- [ ] **Step 1: Найти образец проекции.** Открыть `SearchCoatingsQueryHandler` и метод
  репозитория, который он зовёт; понять, как строится `CoatingSuggestDTO` (DQL/QueryBuilder,
  какие поля, как маппится `mixingRatio`). `allForSuggest()` должен давать ИДЕНТИЧНЫЙ shape,
  только без `WHERE`/`setMaxResults`.

- [ ] **Step 2: Написать падающий тест.**

```php
// CoatingRepositoryAllForSuggestTest.php — functional, реальная БД
public function test_returns_all_coatings_as_suggest_dto(): void
{
    // фикстуры: минимум 2 покрытия (1-комп и 2-комп, чтобы покрыть mixingRatio null/не-null)
    $repo = self::getContainer()->get(CoatingRepositoryInterface::class);

    $all = $repo->allForSuggest();

    self::assertGreaterThanOrEqual(2, count($all));
    self::assertContainsOnlyInstancesOf(CoatingSuggestDTO::class, $all);
    $first = $all[0];
    self::assertNotSame('', $first->id);
    self::assertNotSame('', $first->title);
    self::assertGreaterThan(0, $first->volumeSolid);
}
```

- [ ] **Step 3: Запустить тест — убедиться, что падает.**
  Run (в контейнере): `vendor/bin/phpunit --filter test_returns_all_coatings_as_suggest_dto`
  Expected: FAIL — метода `allForSuggest` нет.

- [ ] **Step 4: Реализовать.** Добавить сигнатуру в интерфейс; в `CoatingRepository`
  скопировать проекцию из suggest-метода, убрать фильтр/пейджер, отсортировать детерминированно.

- [ ] **Step 5: Запустить — убедиться, что проходит.** Expected: PASS.

- [ ] **Step 6: Commit.**
```bash
git add app/src/Coatings/Domain/Repository/CoatingRepositoryInterface.php \
        app/src/Coatings/Infrastructure/Repository/CoatingRepository.php \
        app/tests/Functional/Coatings/Infrastructure/Repository/CoatingRepositoryAllForSuggestTest.php
git commit -m "Репозиторий покрытий отдаёт весь каталог одним списком для офлайн-кеша"
```

---

### Task 2: Query/Handler/Result — `AllCoatingsForSuggest`

**Files:**
- Create: `app/src/Coatings/Application/UseCase/Query/AllCoatingsForSuggest/AllCoatingsForSuggestQuery.php`
- Create: `.../AllCoatingsForSuggestQueryResult.php`
- Create: `.../AllCoatingsForSuggestQueryHandler.php`
- Test: `app/tests/Functional/Coatings/Application/UseCase/Query/AllCoatingsForSuggestQueryTest.php`

**Interfaces:**
- Consumes: `CoatingRepositoryInterface::allForSuggest()` (Task 1).
- Produces:
  - `new AllCoatingsForSuggestQuery()` — без параметров.
  - `AllCoatingsForSuggestQueryResult` с `/** @var list<CoatingSuggestDTO> */ public array $coatings`.
  - Хендлер `implements QueryHandlerInterface`, `__invoke(AllCoatingsForSuggestQuery): AllCoatingsForSuggestQueryResult`.

- [ ] **Step 1: Написать падающий тест** (через QueryBus, как в тестах `SearchCoatings`).

```php
public function test_query_returns_full_catalog(): void
{
    $bus = self::getContainer()->get(QueryBusInterface::class);

    $result = $bus->execute(new AllCoatingsForSuggestQuery());

    self::assertInstanceOf(AllCoatingsForSuggestQueryResult::class, $result);
    self::assertGreaterThanOrEqual(2, count($result->coatings));
    self::assertContainsOnlyInstancesOf(CoatingSuggestDTO::class, $result->coatings);
}
```

- [ ] **Step 2: Запустить — падает** (в контейнере). Expected: FAIL — классов нет / нет хендлера.

- [ ] **Step 3: Реализовать** три класса. Хендлер:
```php
final class AllCoatingsForSuggestQueryHandler implements QueryHandlerInterface
{
    public function __construct(private readonly CoatingRepositoryInterface $coatings) {}

    public function __invoke(AllCoatingsForSuggestQuery $query): AllCoatingsForSuggestQueryResult
    {
        return new AllCoatingsForSuggestQueryResult($this->coatings->allForSuggest());
    }
}
```
  (Result — конструктор принимает `array $coatings`; свойство readonly/public по образцу
  соседних `*QueryResult`.)

- [ ] **Step 4: Запустить — проходит.** Expected: PASS. Если хендлер не найден в runtime —
  проверить, что реализует `QueryHandlerInterface` (регистрация через него, см. Global Constraints).

- [ ] **Step 5: Commit.**
```bash
git add app/src/Coatings/Application/UseCase/Query/AllCoatingsForSuggest \
        app/tests/Functional/Coatings/Application/UseCase/Query/AllCoatingsForSuggestQueryTest.php
git commit -m "Read-query «весь каталог покрытий» для офлайн-выгрузки на устройство"
```

---

### Task 3: Единый нормализатор shape + рефактор `SuggestAction`

**Files:**
- Create: `app/src/Coatings/Infrastructure/Api/CoatingSuggestNormalizer.php`
- Modify: `app/src/Coatings/Infrastructure/Controller/Coating/SuggestAction.php` (перевести `array_map` на нормализатор)
- Test: `app/tests/Unit/Coatings/Infrastructure/Api/CoatingSuggestNormalizerTest.php`

**Interfaces:**
- Produces: `CoatingSuggestNormalizer::toArray(CoatingSuggestDTO $dto): array` — тот же shape,
  что сейчас инлайнит `SuggestAction`:
  `{id,title,base,dftMin,dftMax,volumeSolid,pack,massDensity,mixingRatio:{volume,mass}|null}`.

- [ ] **Step 1: Проверить существующий слой.** Убедиться, что в `Coatings/Infrastructure` нет
  уже готового сериализатора этого DTO. Если есть — использовать/дополнить его, класс не плодить.

- [ ] **Step 2: Написать падающий unit-тест** (на хосте).

```php
public function test_maps_two_component_coating_with_mixing_ratio(): void
{
    $dto = new CoatingSuggestDTO(); // заполнить поля, mixingRatio = new MixingRatioDTO(...)
    // ...
    $arr = CoatingSuggestNormalizer::toArray($dto);

    self::assertSame($dto->id, $arr['id']);
    self::assertSame(['volume' => $dto->mixingRatio->volume, 'mass' => $dto->mixingRatio->mass], $arr['mixingRatio']);
}

public function test_maps_single_component_coating_null_ratio(): void
{
    $dto = new CoatingSuggestDTO(); // mixingRatio = null
    $arr = CoatingSuggestNormalizer::toArray($dto);
    self::assertNull($arr['mixingRatio']);
}
```

- [ ] **Step 3: Запустить — падает.** Run: `vendor/bin/phpunit tests/Unit/Coatings/Infrastructure/Api`
  Expected: FAIL — класса нет.

- [ ] **Step 4: Реализовать** `CoatingSuggestNormalizer::toArray()` — перенести тело `array_map`
  из `SuggestAction` дословно (ключи/типы/комментарии сохранить).

- [ ] **Step 5: Перевести `SuggestAction`** на `array_map([CoatingSuggestNormalizer::class, 'toArray'], ...)`.
  Поведение эндпоинта не меняется.

- [ ] **Step 6: Запустить unit + существующие тесты `SuggestAction`** (если есть) — всё зелёное.
  Expected: PASS, регрессий нет.

- [ ] **Step 7: Commit.**
```bash
git add app/src/Coatings/Infrastructure/Api/CoatingSuggestNormalizer.php \
        app/src/Coatings/Infrastructure/Controller/Coating/SuggestAction.php \
        app/tests/Unit/Coatings/Infrastructure/Api/CoatingSuggestNormalizerTest.php
git commit -m "Shape подсказки покрытия — в единый нормализатор (один источник для suggest и каталога)"
```

---

### Task 4: `CatalogAction` — эндпоинт каталога с ETag/304

**Files:**
- Create: `app/src/Coatings/Infrastructure/Controller/Coating/CatalogAction.php`
- Test: `app/tests/Functional/Coatings/Infrastructure/Controller/CatalogActionTest.php`

**Interfaces:**
- Consumes: `AllCoatingsForSuggestQuery` (Task 2), `CoatingSuggestNormalizer::toArray` (Task 3).
- Produces: `GET /cabinet/coating/coating/catalog`
  (`name: app_cabinet_coating_coating_catalog`) → `{"items":[...]}` + заголовок `ETag`; `304` на
  совпадающий `If-None-Match`; аноним → редирект на логин (как весь `/cabinet`).

- [ ] **Step 1: Найти образец функционального теста** для GET-эндпоинта под `/cabinet/coating`
  (например тест `ListAction`/`SuggestAction`), скопировать его bootstrap: базовый класс
  (`WebTestCase`-наследник), способ логина авторизованным юзером.

- [ ] **Step 2: Написать падающие тесты.**

```php
public function test_returns_full_catalog_with_etag_for_authenticated_user(): void
{
    $client = static::createClient();
    $this->loginAsUser($client); // хелпер из образца
    $client->request('GET', '/cabinet/coating/coating/catalog');

    self::assertResponseIsSuccessful();
    self::assertNotEmpty($client->getResponse()->headers->get('ETag'));
    $data = json_decode($client->getResponse()->getContent(), true);
    self::assertArrayHasKey('items', $data);
    self::assertGreaterThanOrEqual(2, count($data['items']));
    self::assertArrayHasKey('mixingRatio', $data['items'][0]);
}

public function test_returns_304_on_matching_if_none_match(): void
{
    $client = static::createClient();
    $this->loginAsUser($client);
    $client->request('GET', '/cabinet/coating/coating/catalog');
    $etag = $client->getResponse()->headers->get('ETag');

    $client->request('GET', '/cabinet/coating/coating/catalog', server: ['HTTP_IF_NONE_MATCH' => $etag]);

    self::assertSame(304, $client->getResponse()->getStatusCode());
}

public function test_anonymous_is_denied(): void
{
    $client = static::createClient();
    $client->request('GET', '/cabinet/coating/coating/catalog');
    self::assertResponseStatusCodeSame(302); // редирект на логин (как остальной /cabinet)
}
```

- [ ] **Step 3: Запустить — падают** (в контейнере). Expected: FAIL — роут не найден (404/500).

- [ ] **Step 4: Реализовать `CatalogAction`.**

```php
#[Route('/cabinet/coating/coating/catalog', name: 'app_cabinet_coating_coating_catalog', methods: ['GET'])]
final class CatalogAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus) {}

    public function __invoke(Request $request): Response
    {
        /** @var AllCoatingsForSuggestQueryResult $result */
        $result = $this->queryBus->execute(new AllCoatingsForSuggestQuery());
        $items = array_map([CoatingSuggestNormalizer::class, 'toArray'], $result->coatings);

        $response = new JsonResponse(['items' => $items]);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->headers->set('Cache-Control', 'no-cache');
        if ($response->isNotModified($request)) {
            return $response; // 304, тело очищено Symfony
        }

        return $response;
    }
}
```

- [ ] **Step 5: Запустить — проходят.** Expected: PASS (три теста).

- [ ] **Step 6: Commit.**
```bash
git add app/src/Coatings/Infrastructure/Controller/Coating/CatalogAction.php \
        app/tests/Functional/Coatings/Infrastructure/Controller/CatalogActionTest.php
git commit -m "Эндпоинт каталога покрытий: весь список + ETag, 304 для устройства с актуальным кешем"
```

---

### Task 5: Клиентский стор — `catalog_store.js` (IndexedDB)

**Files:**
- Create: `app/assets/catalog_store.js`

**Interfaces:**
- Produces (ES-модуль, экспортирует объект/функции):
  - `getEtag(): Promise<string|null>`
  - `getAll(): Promise<Array>`
  - `search(query, limit=50): Promise<Array>` — элементы в shape эндпоинта + поле `value:title`.
  - `replaceAll(items, etag): Promise<void>` — атомарно (одна транзакция над `coatings`+`meta`).
- БД `app-offline` v1, object stores: `coatings` (`keyPath:'id'`), `meta` (`keyPath:'key'`).

> JS-тест-раннера в проекте нет → верификация сборкой + браузером (Task 8). Здесь только реализация.

- [ ] **Step 1: Реализовать модуль.** Открытие БД с `onupgradeneeded` (создать оба store, если
  нет). `replaceAll` в одной `readwrite`-транзакции: `coatings.clear()` → `put` каждого item →
  `meta.put({key:'coatings', etag})`. `search`: `getAll()` из `coatings`, токенизировать запрос
  (split по пробелам, lower-case), оставить те, где `title.toLowerCase()` содержит все токены;
  ранжирование — сперва `startsWith(query)`, потом остальные; вернуть до `limit`, каждый —
  `{...item, value: item.title}`. Мягкая деградация: любой сбой открытия БД → методы возвращают
  `null`/`[]`/no-op (чтобы typeahead ушёл в сетевой фолбэк).

- [ ] **Step 2: Проверка сборки.** Run: `cd app && yarn dev`. Expected: сборка без ошибок
  (импорт синтаксически валиден; сам модуль подключит Task 7).

- [ ] **Step 3: Commit.**
```bash
git add app/assets/catalog_store.js
git commit -m "IndexedDB-стор каталога покрытий: атомарная полная замена + локальный поиск"
```

---

### Task 6: Stimulus-контроллер синка — `catalog_sync_controller.js`

**Files:**
- Create: `app/assets/controllers/catalog_sync_controller.js`

**Interfaces:**
- Consumes: `catalog_store.js` (`getEtag`, `replaceAll`).
- Produces: контроллер `catalog-sync`, value `endpoint: String`. Синкает на `connect` и на
  `window`-событие `online`.

- [ ] **Step 1: Реализовать.**
```js
import { Controller } from '@hotwired/stimulus';
import * as store from '../catalog_store.js';

export default class extends Controller {
    static values = { endpoint: String };

    connect() {
        this._onOnline = () => this.sync();
        window.addEventListener('online', this._onOnline);
        this.sync();
    }
    disconnect() { window.removeEventListener('online', this._onOnline); }

    async sync() {
        if (this._syncing || !navigator.onLine || !this.endpointValue) return;
        this._syncing = true;
        try {
            const etag = await store.getEtag();
            const res = await fetch(this.endpointValue, {
                headers: etag ? { 'If-None-Match': etag } : {},
                credentials: 'same-origin',
            });
            if (res.status === 200) {
                const json = await res.json();
                await store.replaceAll(json.items ?? [], res.headers.get('ETag'));
            }
            // 304 и сетевые ошибки — ничего не делаем (офлайн это норма)
        } catch { /* офлайн */ }
        finally { this._syncing = false; }
    }
}
```

- [ ] **Step 2: Проверка сборки.** Run: `cd app && yarn dev`. Expected: без ошибок; контроллер
  зарегистрирован автоматически (соглашение имён Stimulus).

- [ ] **Step 3: Commit.**
```bash
git add app/assets/controllers/catalog_sync_controller.js
git commit -m "Синк каталога на устройство: полная замена по ETag на online/boot"
```

---

### Task 7: Seam источника данных в `async_typeahead_controller.js`

**Files:**
- Modify: `app/assets/controllers/async_typeahead_controller.js`

**Interfaces:**
- Consumes: `catalog_store.js` (`search`).
- Produces: новое value `source: { type: String, default: '' }`. При `source==='catalog'` поиск
  идёт в стор (local-first); сетевой фолбэк на `endpointValue` только если стор пуст И `onLine`.
  Событие `select`/`detail.item` — без изменений.

- [ ] **Step 1: Добавить value и импорт.** В `static values` добавить `source: { type: String,
  default: '' }`. Вверху: `import * as catalogStore from '../catalog_store.js';`.

- [ ] **Step 2: Развилка в `_fetchPage`** (или в `_onInput` перед сетью):
```js
async _fetchPage(query, page) {
    if (this.sourceValue === 'catalog') {
        const items = await catalogStore.search(query, 50);
        if (items.length > 0 || !navigator.onLine) {
            return { items, page: 1, hasMore: false };
        }
        // холодный стор + онлайн → падаем в старый сетевой путь ниже
    }
    // ...существующий сетевой fetch без изменений...
}
```
  Убедиться, что при `source==='catalog'` пагинация не включается (`hasMore:false`) и что
  существующие потребители (значение не задано, `sourceValue===''`) идут строго прежним путём.

- [ ] **Step 3: Проверка сборки + регресс существующих typeahead'ов.** Run: `cd app && yarn dev`.
  Затем браузер: открыть страницу, где `async-typeahead` без `source` (surface treatments или
  документы) — поиск по сети работает как раньше.

- [ ] **Step 4: Commit.**
```bash
git add app/assets/controllers/async_typeahead_controller.js
git commit -m "async-typeahead: опциональный источник catalog (local-first) без слома сетевых потребителей"
```

---

### Task 8: Подключить калькуляторы + браузерный офлайн-смоук

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/tools/mix.html.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/tools/film.html.twig`
- Modify: `app/src/Shared/Infrastructure/Templates/tools/consumption.html.twig`

**Interfaces:**
- Consumes: value `source` (Task 7), контроллер `catalog-sync` (Task 6), роут
  `app_cabinet_coating_coating_catalog` (Task 4).

- [ ] **Step 1: Правка трёх шаблонов.** На `<div data-controller="async-typeahead" …>`:
  - добавить `data-async-typeahead-source-value="catalog"` (endpoint-value оставить — сетевой фолбэк);
  - на тот же div добавить контроллер синка (Stimulus поддерживает несколько):
    `data-controller="async-typeahead catalog-sync"` +
    `data-catalog-sync-endpoint-value="{{ path('app_cabinet_coating_coating_catalog') }}"`.
  Изменения идентичны в трёх файлах.

- [ ] **Step 2: Сборка.** Run: `cd app && yarn dev`. Expected: без ошибок.

- [ ] **Step 3: Браузерный смоук (онлайн-наполнение).** Залогиниться, открыть `/tools/mix`.
  В DevTools → Application → IndexedDB → `app-offline` → `coatings`: стор наполнен, в `meta`
  есть `etag`. Поиск покрытия работает, выбор подставляет соотношение.

- [ ] **Step 4: Браузерный смоук (офлайн).** DevTools → Network → Offline. Перезагрузить/остаться
  на `/tools/consumption`: поиск покрытия из стора работает, подстановка (`volumeSolid`/`pack`/
  `massDensity`) считает офлайн. Повторить на `/tools/film`.

- [ ] **Step 5: Смоук обновления.** Онлайн; изменить `volumeSolid` покрытия в БД (или через
  админку); перезагрузить страницу калькулятора (синк на boot) → ответ 200 (не 304), в IndexedDB
  новое значение, подстановка обновилась.

- [ ] **Step 6: Commit.**
```bash
git add app/src/Shared/Infrastructure/Templates/tools/mix.html.twig \
        app/src/Shared/Infrastructure/Templates/tools/film.html.twig \
        app/src/Shared/Infrastructure/Templates/tools/consumption.html.twig
git commit -m "Калькуляторы /tools ищут покрытие и подставляют значения офлайн из кеша каталога"
```

---

### Task 9: Финальный гейт

- [ ] **Step 1: Тесты затронутого контекста** (в контейнере):
  `vendor/bin/phpunit tests/Unit/Coatings tests/Functional/Coatings`. Expected: зелёные.
- [ ] **Step 2: Style/phpstan** (в контейнере `manager_php-fpm`): `./run check` (или прямые
  cs-fixer/phpstan). Починить замечания по новым файлам — имплементеры часто пропускают эти гейты.
- [ ] **Step 3: Убедиться, что нет мусора** — `dd()`/`var_dump`/закомментированных блоков/`.DS_Store`.
- [ ] **Step 4: Пересобрать ассеты начисто** — `cd app && yarn dev`.
- [ ] **Step 5:** Итог разработчику: что сделано, что проверено (тесты + офлайн-смоук). Пуш — по апруву.

---

## Self-Review (проверка плана против спеки)

- **Покрытие спеки:** §3.1 → Task 1+2; §3.2 нормализатор → Task 3; §3.3 CatalogAction/ETag →
  Task 4; §4.1 стор → Task 5; §4.2 синк → Task 6; §4.3 seam → Task 7; §4.4 шаблоны → Task 8;
  §5 тесты → в задачах + Task 9; §6 файлы — все перечислены. Пробелов нет.
- **Плейсхолдеры:** код-снипы конкретны; тесты — с реальными ассертами; для JS (нет раннера)
  верификация сборкой+браузером явно, а не «напиши тесты».
- **Согласованность типов:** `allForSuggest(): list<CoatingSuggestDTO>` (Task 1) ↔ потребляется
  в Task 2; `AllCoatingsForSuggestQueryResult::$coatings` ↔ читается в Task 4; `toArray()` shape
  (Task 3) ↔ используется suggest и catalog; `catalog_store` API (`getEtag/getAll/search/
  replaceAll`) ↔ вызовы в Task 6/7 совпадают; value `source` (Task 7) ↔ атрибут в Task 8;
  `catalog-sync endpoint` value ↔ `path(...)` в Task 8. Консистентно.
