# Фильтр по отчётам: владелец / заказчик / подрядчик / проект

Ветка: `feat/report-search-filter` (от main). Один деплой.

## Задача

Прокачать фильтр списка отчётов (`/cabinet/report`). Добавить фасеты: **владелец, заказчик, подрядчик, проект** — вдобавок к существующим `status` + `search`. Заказчик и владелец — одиночный выбор, подрядчик и проект — мультивыбор (пользователь выбирает заказчика + несколько подрядчиков + несколько проектов).

## Модель доступа (зафиксировано)

Отчёты **owner-based** (`ReportAccessControl`): любой авторизованный заводит/владеет, видит свои; админ (`isManager`) — все.

- **Не-админ:** сервер принудительно подставляет его id как владельца (`ownerIds = [currentUserId]`), клиенту не доверяем. Фасет «владелец» ему НЕ показываем. Он фильтрует свои отчёты по `status` + `search`.
- **Админ:** видит все фасеты; владелец — одиночный выбор любого.

**Guard suggest остаётся `ROLE_ADMIN`** (осознанное решение владельца). Значит фасеты сущностей (владелец/заказчик/подрядчик/проект) в шаблоне — **только под `{% if is_granted('ROLE_ADMIN') %}`** (у не-админа типизация вернула бы 403). Новые by-ids ставим тоже `#[IsGranted('ROLE_ADMIN')]` для симметрии.

**Важно:** весь бэкенд пишем ролезависимости не зная — он универсальный. «Открыть обычным пользователям» позже = ровно два тумблера, без переделки логики:
1. Снять `#[IsGranted('ROLE_ADMIN')]` с suggest заказчика/проекта и с новых by-ids.
2. Убрать `{% if is_granted('ROLE_ADMIN') %}` вокруг фасетов в шаблоне.

## Ключевые решения (зафиксировано)

1. **Все четыре фасета внутри — `StringCollection`** (единый бэкенд, единый FE-контроллер). Одиночность владельца/заказчика — это UX-ограничение (кап на 1 чип в UI), а не отдельный тип. `IN (:ids)` с одним элементом = равенство. Это меняет существующее `ReportsFilter->ownerId` (скаляр) на `ownerIds` (StringCollection) — правка мелкая, owner-скоуп и так спец-кейс в хендлере. Обоснование: «Less code is better» + правило «id-списки — StringCollection» + один JS-контроллер на все фасеты.
2. **Хранение полей отчёта:**
   - `ownerId` — скалярная колонка `owner_id` (ULID, length 26) → `r.ownerId IN (:ownerIds)`.
   - `customer` / `contractor` / `project` — JSONB Reference-колонки (DBAL `reports_reference`, VO `{id,title}`) → `JSONB_GET_TEXT(r.<col>, 'id') IN (:ids)`. Функция `JSONB_GET_TEXT` уже используется в `findByFilter` (поиск по project.title) и зарегистрирована в `Reports/Infrastructure/Database/DQL/`.
3. **Семантика:** AND между фасетами, OR внутри мультивыбора (`IN`).
4. **UI:** общий шелл `components/entity_search.html.twig` (chip-row + кнопка «Все фильтры» с бейджем-счётчиком + offcanvas-драйвер), как в списке систем покрытий. Живые фасеты — в drawer; чипы гидрируются клиентом через by-ids (не резолвим тайтлы на сервере). Программные сабмиты — `requestSubmit()` (не `form.submit()`), иначе merge-хендлер `chip-facets` не сработает и фасеты перетрутся.
5. **FE-контроллер:** переиспользуем `coating_tags_controller.js` (Tagify: suggest + resolve-by-ids + preselected из URL, `allow-create=false`) для всех четырёх фасетов. Добавляем опциональное значение `maxTags` (default — без лимита); владельцу/заказчику ставим `max-tags-value="1"`.
6. **Карточки:** показываем заказчика/подрядчика (из Reference VO — бесплатно) + владельца для админа (батч-резолв email по `ownerId` страницы через существующий `GetUsersByIdsQuery`).

## Развилки к подтверждению на ревью плана

- **Решение 1** (owner/customer как `StringCollection` с UI-капом на 1, а не как скаляры). Если хочешь буквально скаляр `ownerId`/`customerId` + отдельный одиночный контрол — скажи, будет чуть больше кода и два FE-механизма.
- Риск DQL: `JSONB_GET_TEXT(r.col,'id') IN (:ids)` с array-параметром — проверить на этапе Задачи 3; если DQL не проглотит `IN` с кастом-функцией слева, фолбэк — сырой DBAL `EXISTS`/`IN` с `ArrayParameterType::STRING` (как в `CoatingSystemFinder`).

## Эталоны для копирования

- Мультиселект-фасет FE: `app/assets/controllers/coating_tags_controller.js`; живая обвязка — `Templates/admin/coating/coating/audit_journal.html.twig` (фасеты «Актор»/«Покрытия»).
- Парс query → `StringCollection`: `Shared/Infrastructure/Helper/QueryParams::stringCollection(Request, key, ?isValid, unique)`; пример — `Coatings/.../AuditJournalAction.php`.
- Шелл фильтров: `Templates/components/entity_search.html.twig`; концентрат — `Templates/cabinet/coating/coating_system/list.html.twig` (chips lines 104-119, drawer-фасеты lines 482-510, `activeFacetsCount` lines 30-40).
- by-ids экшен: `Coatings/Infrastructure/Controller/Coating/ByIdsAction.php` (route + `Uuid::isValid` + `MAX_IDS` + `StringCollection` + `{items:[{id,title}]}`).
- Стили soft/borderless: `assets/styles/admin/coating-list.css` (`.facet-block`, `.chip-row .btn`, `.chip-count`, `.facet-pill`, `.entity-drawer-foot`).

---

## Файлы и задачи

### Задача 1. `ReportsFilter` — новые поля

**Modify** `app/src/Reports/Domain/Repository/ReportsFilter.php`
- Заменить `?string $ownerId = null` на `StringCollection $ownerIds = new StringCollection()`.
- Добавить `StringCollection $customerIds`, `StringCollection $contractorIds`, `StringCollection $projectIds` (все `= new StringCollection()`).
- Оставить `?Pager $pager`, `?ReportStatus $status`, `?string $search`.
- `use App\Shared\Domain\Aggregate\Collection\StringCollection;`

### Задача 2. `ListAction` — парс query + прокид в шаблон

**Modify** `app/src/Reports/Infrastructure/Controller/Report/ListAction.php`
- Инжект `QueryParams $queryParams` (Shared helper).
- Парс:
  - `ownerIds` → `$this->queryParams->stringCollection($request, 'ownerIds', [Ulid::class, 'isValid'], unique: true)`
  - `customerIds`, `contractorIds`, `projectIds` → `stringCollection($request, '<key>', [Uuid::class, 'isValid'], unique: true)`
  - `status`, `search`, `page` — как сейчас.
- Собрать `new ReportsFilter(pager, ownerIds, customerIds, contractorIds, projectIds, status, search)`.
- В `render` (обе ветки — полная и `partial=1`) прокинуть id-списки для preselect чипов: `ownerIds`, `customerIds`, `contractorIds`, `projectIds` (через `->getList()`), плюс существующие `search`, `status`, `types`.
- **Тонкий контроллер** (правило проекта): только парс + диспатч, без логики.

### Задача 3. Хендлер — owner-скоуп + резолв тайтлов владельцев

**Modify** `app/src/Reports/Application/UseCase/Query/GetPagedReports/GetPagedReportsQueryHandler.php`
- Owner-скоуп на `StringCollection`:
  ```php
  $ownerIds = $this->access->isManager()
      ? $query->filter->ownerIds
      : new StringCollection($this->access->currentUserId());
  ```
- Пересобрать фильтр с `$ownerIds` + пробросить `customerIds/contractorIds/projectIds/status/search/pager` как есть.
- `findByFilter($filter)` → список `Report`.
- **Резолв владельцев (только админ):** собрать `ownerId` со страницы, если `isManager()` — вызвать `GetUsersByIdsQuery` через QueryBus, построить `array<string,string>` id→email; иначе пустая карта.
- Прокинуть карту в `ReportListItemDTOTransformer::fromEntityList($reports, $ownerLabels)`.

### Задача 4. Репозиторий — условия фасетов

**Modify** `app/src/Reports/Infrastructure/Repository/ReportRepository.php` (`findByFilter`)
- Заменить старое `if (null !== $filter->ownerId)` на:
  ```php
  if ($filter->ownerIds->count() > 0) {
      $qb->andWhere('r.ownerId IN (:ownerIds)')->setParameter('ownerIds', $filter->ownerIds->getList());
  }
  ```
- Добавить (по образцу, каждое под `count() > 0`):
  ```php
  $qb->andWhere("JSONB_GET_TEXT(r.customer, 'id') IN (:customerIds)")->setParameter('customerIds', $filter->customerIds->getList());
  $qb->andWhere("JSONB_GET_TEXT(r.contractor, 'id') IN (:contractorIds)")->setParameter('contractorIds', $filter->contractorIds->getList());
  $qb->andWhere("JSONB_GET_TEXT(r.project, 'id') IN (:projectIds)")->setParameter('projectIds', $filter->projectIds->getList());
  ```
- `status`, `search`, pager — без изменений.
- Проверить, что `JSONB_GET_TEXT(...) IN (:ids)` компилируется в DQL (см. риск в развилках); при проблеме — фолбэк на сырой DBAL с `ArrayParameterType::STRING`.

### Задача 5. by-ids эндпоинт заказчика/подрядчика (одна пара на оба)

`findByIds(StringCollection)` уже есть в `CounterpartyRepository`. Добавить чтение-квери + экшен.

**Create** `app/src/Reports/Application/UseCase/Query/GetCounterpartiesByIds/GetCounterpartiesByIdsQuery.php` — `public StringCollection $ids`.
**Create** `.../GetCounterpartiesByIdsQueryHandler.php` — `implements CommandHandlerInterface`? нет — read-side; регистрируется как query handler по образцу `GetCoatingsByIdsQuery`. Возвращает Result со списком `{id,title}` (переиспользовать существующий suggest-DTO контрагента, если он есть; иначе — вложенный DTO, без array-шейпов).
**Create** `.../GetCounterpartiesByIdsQueryResult.php`.
**Create** `app/src/Reports/Infrastructure/Controller/Counterparty/ByIdsAction.php`
- `#[Route(path: '/cabinet/reports/counterparty/by-ids', name: 'app_cabinet_reports_counterparty_by_ids', methods: ['GET'])]`
- `#[IsGranted('ROLE_ADMIN')]` (симметрия с suggest; убрать при «открытии»).
- Читать `ids` через `$request->query->all('ids')`, фильтр `Uuid::isValid`, кап `MAX_IDS = 50`, `StringCollection` → query → `JsonResponse(['items' => [...]])`. Пустой ids → `{items:[]}`.

### Задача 6. by-ids эндпоинт проекта

Аналогично Задаче 5 для `ProjectRepository::findByIds` (уже есть, fetch-join counterparty).
**Create** `GetProjectsByIdsQuery` / `...Handler` / `...Result`.
**Create** `app/src/Reports/Infrastructure/Controller/Project/ByIdsAction.php` — route `app_cabinet_reports_project_by_ids`, path `/cabinet/reports/project/by-ids`, `#[IsGranted('ROLE_ADMIN')]`, `{items:[{id,title}]}`.

### Задача 7. Карточный DTO + трансформер

**Modify** `app/src/Reports/Application/DTO/Reports/ReportListItemDTO.php`
- Добавить `?string $customerTitle`, `?string $contractorTitle`, `?string $ownerLabel` (nullable).

**Modify** `app/src/Reports/Application/DTO/Reports/ReportListItemDTOTransformer.php`
- `fromEntity(Report $r, ?string $ownerLabel)` — тянуть `customer?->title`, `contractor?->title`; `ownerLabel` из параметра.
- `fromEntityList(array $reports, array $ownerLabels)` — маппить, подставляя `$ownerLabels[$r->getOwnerId()] ?? null`.

### Задача 8. Шаблон списка — шелл фильтров

**Modify** `app/src/Shared/Infrastructure/Templates/cabinet/report/index.html.twig`
- `{% embed 'components/entity_search.html.twig' %}` с `list_route = 'app_cabinet_report_list'`.
- `block chips`: активный `status` — server-side remove-`<a>` (merge-URL). Для фасетов-сущностей — не рендерим id-чипы на сервере, полагаемся на бейдж-счётчик «Все фильтры» + чипы в drawer.
- `block drawer`: внутри `{% if is_granted('ROLE_ADMIN') %}` — четыре `.facet-block` с `data-controller="coating-tags"`:
  - Владелец: `hidden-input-name-value="ownerIds[]"`, `max-tags-value="1"`, suggest=`app_cabinet_users_suggest`, resolve=`app_cabinet_users_by_ids`, `preselected-ids-value="{{ ownerIds|json_encode }}"`.
  - Заказчик: `customerIds[]`, `max-tags-value="1"`, suggest=`app_cabinet_reports_counterparty_suggest`, resolve=`app_cabinet_reports_counterparty_by_ids`.
  - Подрядчик: `contractorIds[]` (без maxTags), тот же suggest/resolve контрагента.
  - Проект: `projectIds[]`, suggest=`app_cabinet_reports_project_suggest`, resolve=`app_cabinet_reports_project_by_ids`.
  - Каждый — `allow-create-value="false"`, обёртка `data-coating-tags-group`.
- `status`-селект — доступен всем (вне `is_granted`).
- `activeFacetsCount` — сумма `ownerIds|length + customerIds|length + contractorIds|length + projectIds|length + (status ? 1 : 0)`.
- Сохранить infinite-scroll (`partial=1`): все query-параметры прокидываются в batch-fetch (URL-мерж).

### Задача 9. Карточка — показ заказчика/подрядчика/владельца

**Modify** `app/src/Shared/Infrastructure/Templates/cabinet/report/_report_cards_batch.html.twig`
- Рядом с проектом/системой вывести `item.customerTitle`, `item.contractorTitle` (если не пусто).
- `{% if item.ownerLabel %}` — строка владельца (появляется только у админа, т.к. трансформер кладёт label лишь при `isManager`).
- Стили — из существующих карточных классов, ничего нового не сочинять.

### Задача 10. FE-контроллер — опция maxTags

**Modify** `app/assets/controllers/coating_tags_controller.js`
- Добавить value `maxTags` (Number, default 0 = без лимита); прокинуть в Tagify `maxTags`. Обратная совместимость: существующие обвязки без атрибута работают как раньше.
- После правки — `cd app && yarn dev`.

---

## Тесты

- **Functional** `tests/Functional/Reports/...`:
  - `ReportRepository::findByFilter` — по каждому фасету отдельно (owner IN, customer/contractor/project через JSONB), комбинация (AND между, OR внутри), пустой фильтр = всё.
  - Хендлер `GetPagedReports` — не-админ форсится на свои (owner-скоуп игнорит присланные ownerIds); админ фильтрует по любым; резолв ownerLabel только у админа. Трейт `AuthenticatesActorTrait` (authenticateAsSystem / логин ROLE_ADMIN vs обычный).
  - by-ids экшены (Counterparty, Project) — валидные/битые ids, кап MAX_IDS, форма ответа `{items:[{id,title}]}`, 403 для не-админа (пока guard стоит).
- **Unit** — трансформер `ReportListItemDTOTransformer` (customer/contractor titles, ownerLabel из карты).
- Осторожно с DAMA/constraint-тестами (см. reference в памяти) — by-ids не триггерят FK, безопасно.

## Гейты

`./run check` целиком (style + phpstan L6 + unit + functional) + `bin/console lint:container`. После FE-правок — `cd app && yarn dev` и браузерная проверка (PHP-тесты фронт не покрывают).

## Порядок реализации

1 → 4 (бэкенд-контур: фильтр, парс, хендлер, запрос) → 5, 6 (by-ids) → 7 (DTO/трансформер) → 8, 9, 10 (FE) → тесты → гейты. Коммиты партиями по смыслу (домен/квери; by-ids; DTO; FE), по апруву.
