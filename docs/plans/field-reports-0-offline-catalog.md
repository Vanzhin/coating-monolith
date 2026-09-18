# Field Reports — Деплой 0: офлайн-каталог покрытий

Самодостаточный срез. Часть серии «Полевые отчёты» (field-reports), но **шиппится
независимо** и приносит пользу сам по себе: калькуляторы `/tools`
(`mix`/`film`/`consumption`) начинают искать покрытие и подставлять его значения
**полностью офлайн**. Побочно готовит клиентский кеш каталога, который переиспользует
пикер отчётов в Деплое 3 (офлайн-first клиент).

Серия (перекрёстные ссылки):
- **Д0 (этот файл)** — офлайн-каталог покрытий (независимый).
- Д1 — костяк отчёта (домен блоков, агрегат `Report`, типы отчёта, generic-проектор →
  `RenderData`, рендер через существующий `TemplateRenderer`, owner-based доступ). *План отдельно.*
- Д2 — фото-пайплайн (съёмка, downscale, хранилище, `ImageValue` в слоты). *План отдельно.*
- Д3 — офлайн-first клиент + синк (IndexedDB-черновики, очередь фото-блобов, кеш каталога
  отсюда). *План отдельно.*
- Д4 — пользователь сам собирает блоки. *План отдельно.*

---

## 1. Цель и ценность

Пользователь на объекте без сети открывает калькулятор `/tools`, находит покрытие и
получает подстановку (`volumeSolid`, `pack`, `massDensity`, `mixingRatio`) — как онлайн.
Данные каталога лежат на устройстве в IndexedDB, наполняются, пока был онлайн, и
обновляются автоматически при следующем выходе в сеть.

Каталог маленький: покрытий **максимум ~1000**, лёгкий DTO ≈ 150–300 байт/строка → ~50 КБ
gzip на весь список. Поэтому — полная замена по ETag, без дельт.

## 2. Зафиксированные решения (не пере-обсуждать)

- **Проекция = существующий `CoatingSuggestDTO`.** Он уже несёт ровно то, что нужно и
  пикеру, и калькуляторам (`id`, `title`, `base`, `dftMin/Max`, `volumeSolid`, `pack`,
  `massDensity`, `mixingRatio`). Новых полей не заводим.
- **Эндпоинт под `/cabinet`** → наследует аутентификацию из `security.yaml`. Аноним каталог
  не тянет — как и подстановку из покрытия (аноним считает базово). Данные каталога
  глобальны (одинаковы для всех авторизованных), приватного в них нет.
- **Обновление — полная замена по ETag.** ETag = `sha1` тела ответа (весь список). Любое
  изменение (сухой остаток одного покрытия, добавление/удаление строки, смена формата
  проекции на деплое) меняет тело → меняет ETag → устройство перекачивает весь список.
  Дельты, `updated_at`-токены, tombstone'ы — **не нужны**. Свежесть = «до следующего
  онлайна»; для калькулятора приемлемо (само вылечится), для отчётов не важно (там снимок).
- **Клиент — local-first всегда.** Typeahead ищет в локальном сторе; сеть только как
  фолбэк, если стор пуст (ни разу не синкали). Быстрее и офлайн-безопасно, один путь.
  Цена — клиентский поиск проще серверного FTS (подстрочный/токенный матч вместо
  tsvector-ранжирования); для ≤1000 строк и выбора одного покрытия приемлемо.
- **IndexedDB-обёртка — рукописная**, ~40–60 строк, без внешней зависимости (правило
  «меньше кода / без лишних либ»). Пересмотрим в Д3, если стор черновиков окажется сложным.
- **`async_typeahead` не ломаем.** Добавляем опциональный источник данных через новое
  Stimulus-value; существующие потребители (surface treatments, документы) значение не
  ставят → их поведение не меняется.

## 3. Сервер

### 3.1 Read-query «весь каталог»
- `App\Coatings\Application\UseCase\Query\AllCoatingsForSuggest\`:
  - `AllCoatingsForSuggestQuery` — без параметров (маркер-запрос).
  - `AllCoatingsForSuggestQueryHandler implements QueryHandlerInterface` — регистрация
    через интерфейс (не `#[AsMessageHandler]`), см. проектное правило про хендлеры.
  - `AllCoatingsForSuggestQueryResult` — `/** @var CoatingSuggestDTO[] */ public array $coatings`.
- Репозиторий: `CoatingRepositoryInterface::allForSuggest(): array` (возвращает
  `CoatingSuggestDTO[]`), Doctrine-реализация в `Coatings/Infrastructure/Repository`.
  **Переиспользовать ту же проекцию (тот же SELECT/hydration в `CoatingSuggestDTO`), что и
  `SearchCoatings`, но без `WHERE`/пейджера** — чтобы shape DTO был байт-в-байт идентичен
  suggest. Не плодить `*Finder`-обёртку — метод прямо на репозитории.

### 3.2 Единый нормализатор shape (устранить дублирование)
Сейчас `SuggestAction` инлайнит `array_map` `CoatingSuggestDTO → array`. Поскольку ETag —
хеш этого shape, **два расходящихся сериализатора = баг**. Вынести shape в одно место:
- `App\Coatings\Infrastructure\Api\CoatingSuggestNormalizer::toArray(CoatingSuggestDTO): array`
  (или ближайший существующий слой сериализации Coatings, если он есть — проверить перед
  созданием нового класса).
- `SuggestAction` переключить на нормализатор (рефактор без смены поведения).
- `CatalogAction` использует его же.

### 3.3 CatalogAction
- `App\Coatings\Infrastructure\Controller\Coating\CatalogAction`, тонкий:
  `#[Route('/cabinet/coating/coating/catalog', name: 'app_cabinet_coating_coating_catalog', methods: ['GET'])]`.
- Логика:
  1. `$result = $queryBus->execute(new AllCoatingsForSuggestQuery());`
  2. `$items = array_map([CoatingSuggestNormalizer::class, 'toArray'], $result->coatings);`
  3. `$response = new JsonResponse(['items' => $items]);`
  4. `$response->setEtag(sha1((string) $response->getContent()));`
  5. `$response->headers->set('Cache-Control', 'no-cache');` // всегда ревалидируем
  6. `if ($response->isNotModified($request)) { return $response; }` // отдаст 304 без тела
  7. `return $response;`
- Форматов пагинации/`q` нет — отдаём весь список.

> Оптимизация на будущее (сейчас **YAGNI**): каталог глобален → можно кешировать
> тело+ETag на сервере и инвалидировать на запись покрытия, чтобы не строить список на
> каждый 304. Вводить только при реальной нагрузке.

## 4. Клиент

### 4.1 `app/assets/catalog_store.js` — модуль-обёртка над IndexedDB
Не Stimulus-контроллер, а импортируемый модуль. БД `app-offline`, версия 1, два object store:
- `coatings` — `keyPath: 'id'`, хранит item в shape эндпоинта.
- `meta` — `keyPath: 'key'`, запись `{ key: 'coatings', etag }`.

API:
- `getEtag(): Promise<string|null>`
- `getAll(): Promise<Array>`
- `search(query, limit = 50): Promise<Array>` — токенизировать запрос, матч по `title`
  (case-insensitive, все токены как подстроки). Ранжирование: сперва `title` начинается с
  запроса, потом «содержит». Вернуть до `limit`, каждый — как item + `value: title`
  (формат, который ждёт `async_typeahead`/Tagify).
- `replaceAll(items, etag): Promise<void>` — **атомарно в одной транзакции** над обоими
  store: `clear` coatings → bulk `put` всех items → `put` meta `{key:'coatings', etag}`.
- Мягкая деградация: если IndexedDB недоступен (приватный режим и т.п.) — API возвращает
  пусто/no-op, чтобы вызвать сетевой фолбэк.

> Схема БД (`app-offline`, v1) заложена так, чтобы Д3 добавил свои store (черновики,
> очередь фото) бампом версии, не ломая `coatings`.

### 4.2 `app/assets/controllers/catalog_sync_controller.js` — Stimulus, синк
- Values: `endpoint: String` (URL каталога через `path()` из шаблона).
- `connect()` и слушатель `window` `online` → `sync()`.
- `sync()`:
  1. Если `!navigator.onLine` — выходим.
  2. `etag = await store.getEtag();`
  3. `fetch(endpoint, { headers: etag ? {'If-None-Match': etag} : {} , credentials:'same-origin' })`.
  4. `304` → ничего. `200` → `await store.replaceAll((await res.json()).items, res.headers.get('ETag'))`.
  5. Любая сетевая ошибка — глотаем (офлайн — это норма).
  - Защита от параллельных запусков (флаг `_syncing`).
- Монтируется на страницах `/tools` (где есть пикер). В Д3 переедет/продублируется на
  оболочку приложения.

### 4.3 `async_typeahead_controller.js` — seam источника данных
- Новое value: `source: { type: String, default: '' }`.
- В `_onInput`/`_fetchPage`: если `this.sourceValue === 'catalog'` — вместо сетевого
  `fetch` звать `catalogStore.search(query, ...)` и вернуть `{ items, page: 1, hasMore: false }`
  (весь матч одной страницей, клиент фильтрует все ≤1000).
- **Local-first фолбэк:** если `source==='catalog'`, стор вернул 0 **и** `navigator.onLine` —
  один раз сходить в сеть на `endpointValue` (старый путь), чтобы «холодный» юзер тоже искал.
- Событие `select` и его `detail.item` — **без изменений** (тот же shape), поэтому
  калькуляторы (`applyFromCoating`) не трогаем.
- Существующие потребители `source` не задают → путь строго прежний.

### 4.4 Шаблоны калькуляторов
В `tools/mix.html.twig`, `tools/film.html.twig`, `tools/consumption.html.twig` на `<div
data-controller="async-typeahead" …>`:
- добавить `data-async-typeahead-source-value="catalog"`;
- `endpoint-value` оставить (сетевой фолбэк для холодного стора);
- на той же (или родительской) секции повесить `data-controller="catalog-sync"` +
  `data-catalog-sync-endpoint-value="{{ path('app_cabinet_coating_coating_catalog') }}"`.

## 5. Тесты

- **Функциональный (сервер), с реальной БД** — `tests/Functional` (или зеркало пути
  Coatings), логин авторизованным юзером:
  - `GET .../catalog` → 200, `items` — полный список, число = числу покрытий в фикстуре;
  - shape строки идентичен `suggest` (тот же нормализатор);
  - ответ несёт заголовок `ETag`;
  - повтор с `If-None-Match: <тот ETag>` → **304** без тела;
  - аноним (без логина) → редирект на логин / 401 (как остальной `/cabinet`).
- **Round-trip нормализатора** (юнит, если вынесли в отдельный класс): `CoatingSuggestDTO →
  toArray` даёт ожидаемые ключи/типы.
- **JS — `phpunit` не трогает** (правило «не гонять phpunit на фронте»). Верификация:
  - `cd app && yarn dev` (после правок JS/Twig);
  - браузерный смоук: залогиниться, открыть калькулятор (стор наполнился), в DevTools
    включить Offline → поиск покрытия и подстановка работают из стора; проверить, что
    правка `volumeSolid` в БД + возврат онлайн обновляет значение на устройстве (200, не 304).

## 6. Файлы

**Сервер (Coatings):**
- `Application/UseCase/Query/AllCoatingsForSuggest/{Query,QueryHandler,QueryResult}.php` (новое)
- `Domain/Repository/CoatingRepositoryInterface.php` — `allForSuggest(): array` (+ реализация
  в `Infrastructure/Repository/…`)
- `Infrastructure/Api/CoatingSuggestNormalizer.php` (новое; или в существующий слой)
- `Infrastructure/Controller/Coating/SuggestAction.php` — перевод на нормализатор (рефактор)
- `Infrastructure/Controller/Coating/CatalogAction.php` (новое; роут атрибутом)

**Клиент (`app/assets`):**
- `catalog_store.js` (новый модуль)
- `controllers/catalog_sync_controller.js` (новый)
- `controllers/async_typeahead_controller.js` — seam `source`
- `Shared/Infrastructure/Templates/tools/{mix,film,consumption}.html.twig` — атрибуты

**Без миграций** (только чтение существующих покрытий). Stimulus-контроллеры
регистрируются автоматически.

## 7. Риски и заметки

- **Стоимость ETag на масштабе:** строим+хешируем список на каждый запрос, включая 304. На
  ≤1000 строк — пара мс; при росте нагрузки — серверный кеш тела (см. §3.3).
- **Качество локального поиска** ниже серверного FTS (нет ранжирования tsvector). Принято
  осознанно для выбора одного покрытия из ≤1000.
- **IndexedDB недоступен** (приватный режим, старый вебвью) → стор no-op → сетевой фолбэк.
- **SW не мешает:** рантайм-кеш SW не кладёт JSON и пропускает `?query`; каталог-эндпоинт
  проходит network-first насквозь, офлайн-фетч падает и ловится в JS → читаем IndexedDB.

## 8. Статус

Спека написана. **Не закоммичена** (git ведёт разработчик). Реализацию начинать по TDD,
пошагово, ветка от `main` (предложено `feat/offline-coating-catalog`). После апрува спеки —
переход к плану реализации (writing-plans).
