# Список актов СИЗ + карточка по акту (Модель B: акт = слепок)

**Цель:** человек-уровневый список **актов выдачи** с фильтром, модалка превью — read-only с одной кнопкой «Акты», карточка скачивается по конкретному акту выдачи (слепок). Это завершает двухчастную карточку (#107) и чинит баг «на бланке только последний акт».

**Модель (согласовано):** акт выдачи = слепок одной выдачи; в него не дозаписываем, новая выдача = новый документ. Карточка-бланк конкретного акта показывает норму (стр. 1) + факт ЭТОГО акта (стр. 2). Списание начинается И просматривается ТОЛЬКО изнутри акта выдачи (существующий флоу «Списать» + секция «Акты списания» на странице issue) — акты списания привязаны к материальной позиции, поэтому в общий список НЕ тащим (отдельная задача позже). Список = только акты выдачи, запрос по одной таблице `requirement_document` (без UNION).

**Один деплой.** Миграций нет (всё read-side по существующим таблицам актов). Ветка — продолжение `feat/compliance-card-norm-and-facts` (#107 придержан, вольём всё вместе).

## Контекст (что уже есть)
- Акты выдачи = `RequirementDocument` (child `ProfileCompliance`): requirementId, status (Formed/Signed), actNumber, даты, scanFileId.
- Акты списания = `WriteOffAct` (child): requirementId, status (Draft/Signed), actNumber, actDate, scanFileId.
- Карточка: `DownloadCardAction` (`/person/{profileId}/requirement/{requirementId}/card`) → `RequirementCardProjector`, берёт `$source = openDraftFor ?? latestSigned` (отсюда баг «последний акт»).
- Не-админ → свой профиль: `ComplianceDashboardScope::restrictProfileIds(ComplianceDashboardFilter)` (переиспользуем паттерн).
- Списание: `GoToWriteOffAction` (`writeoff_open`) → `StartWriteOffAct` → `writeoff_show`. Не трогаем.
- `issue` остаётся экраном создания/заполнения/списания.

## Review Focus
- Не-админ не должен видеть чужие акты даже прямым URL (`?profile=<чужой>`): scope перетирает профиль на сервере, не на фронте.
- Пустой список (нет актов) и пустой результат фильтра — валидная страница, не 404/500.
- Пагинация — стабильная сортировка (по дате DESC, tie-break по id), корректный total.
- Карточка по `documentId`: чужой/несуществующий документ → 404; документ другого профиля → 404 (не чужой слепок).
- Черновик выдачи в списке → действие «Продолжить» (→ issue), не «скачать карточку» вслепую.

---

## Задача 1: Карточка по конкретному акту (per-document)

**Файлы:**
- Modify: `app/src/Compliance/Application/Service/RequirementCardProjector.php` — `project(..., ?string $documentId = null)`: если задан — `$source` = документ с этим id (проверка принадлежности профилю+требованию), иначе прежнее `openDraftFor ?? latestSigned`.
- Modify: `app/src/Compliance/Infrastructure/Controller/Document/DownloadCardAction.php` — добавить маршрут по документу `/person/{profileId}/document/{documentId}/card` (name `app_cabinet_compliance_document_card_download`) ИЛИ опциональный `{documentId}` в текущем. Резолв документа из `ProfileCompliance`, 404 если не его.
- Test: `CardDownloadControllerTest` — два подписанных акта → карточка по documentId акта №1 = только его позиции (не последний).

**Шаги:** тест (две выдачи разными актами, карточка по первому → его позиции) → projector принимает documentId → контроллер per-document.

## Задача 2: Чтение списка актов выдачи (фильтр + репозиторий + query)

**Файлы:**
- Create: `app/src/Compliance/Application/UseCase/Query/Acts/ComplianceActsFilter.php` — bag-of-fields: `profileIds: StringCollection`, `positionIds: StringCollection`, `q: ?string` (ФИО), `requirementId: ?string`, `status: ?string` (draft|signed), `dateFrom/dateTo: ?DateTimeImmutable`, `page/limit` (Pager). (Типа нет — только выдачи.)
- Create: `app/src/Compliance/Application/DTO/Acts/ComplianceActRowDTO.php` — `documentId, profileId, personFio, requirementId, requirementName, actNumber, date, status` (без array-shape).
- Create: `app/src/Compliance/Domain/Repository/ComplianceActRepositoryInterface.php` — `findByFilter(ComplianceActsFilter): PaginationResult`.
- Create: `app/src/Compliance/Infrastructure/Repository/ComplianceActRepository.php` — запрос по ОДНОЙ таблице `requirement_document` ⨝ `profile_compliance` (→ profileId), фильтры по scope-профилям/требованию/статусу/датам, ORDER BY date DESC, id; `COUNT` для total. Без UNION.
- Create: `app/src/Compliance/Application/UseCase/Query/Acts/GetComplianceActs{Query,QueryHandler,QueryResult}.php` — применить scope (перетереть профиль не-админу через `ComplianceDashboardScope` / parallel-метод), вызвать репозиторий, батч-резолв ФИО (`GetProfilesByIds`) и имён требований.

**Шаги:** функц.-тест (2 профиля, у каждого акты выдачи) → админ видит оба, не-админ — только свой даже с `?profile=чужой`; фильтр по статусу/требованию сужает; пагинация.

## Задача 3: Страница списка актов

**Файлы:**
- Create: `app/src/Compliance/Infrastructure/Controller/Acts/ListActsAction.php` — тонкий: собрать `ComplianceActsFilter` из query, диспатч `GetComplianceActsQuery`, рендер. Route `app_cabinet_compliance_acts` (`/cabinet/compliance/acts`).
- Create: `app/src/Shared/Infrastructure/Templates/admin/compliance/acts/index.html.twig` — фильтр (профиль/должность/ФИО через typeahead, требование, статус, период) + таблица (человек · требование · № · дата · статус · действия) + пагинация. Состояние фильтра — в URL. Копировать разметку ближайшего аналога (дашборд/requirements list).
- Действия строки: подписан → «Открыть» (issue, подписанный режим — там же акты списания по позициям) + «Скачать карточку» (per-document) + «Скан»; черновик → «Продолжить» (issue).
- «Новый документ» (новая выдача): при сужении на одного человека — выбор требования → `form_draft` → `issue`. (Для материального требования человека без черновика.)

**Шаги:** контроллер тонкий (вся логика в handler) → шаблон по аналогу → фасеты в URL, typeahead по профилю/должности.

## Задача 4: Навигация — модалка read-only + кнопка «Акты»

**Файлы:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/dashboard/_person_detail.html.twig` — убрать блок `{% if canEdit %}…{% endif %}` с тремя кнопками (Оформить/Открыть-списать/Сформировать черновик); вместо — одна кнопка «Акты» (на человека, вверху) → `app_cabinet_compliance_acts` c `?profile={profileId}`. Статусы-бейджи + таблица позиций остаются read-only.
- Проверить: ничего больше не ссылается на убранные кнопки; тесты дашборда (`test_dashboard_person_and_issue_pages_render`) поправить под новую разметку.

**Шаги:** правка шаблона → кнопка «Акты» → прогон/правка теста рендера дашборда.

## Реализация (как сделано)
- Фильтр/сортировка/интерфейс — в `Domain/Repository` (канон Coatings): `ComplianceActsFilter` + `ComplianceActsSort` (enum, поле фильтра) + `ComplianceActRepositoryInterface::findByFilter(ComplianceActsFilter): PaginationResult`. Реализация `ComplianceActRepository` — ORM по `RequirementDocument` ⨝ `ProfileCompliance`, дата = `COALESCE(signedAt, createdAt)` через HIDDEN-алиас, Paginator.
- Сортировка — в SQL репозитория по колонкам документа: дата акта (дефолт, свежие сверху), № акта, статус (кликабельные колонки, `ComplianceActsSort` — поле фильтра). По ФИО/требованию НЕ сортируем — это кросс-контекст (Personnel/Requirement), денормализовать ФИО решили не делать.
- Фильтр/поиск по сотруднику — по `profileId`, не по ФИО: выбор в typeahead → `profile[]=<id>` → scope → `WHERE pc.profileId IN`. Текстовый поиск по ФИО (`q`) делегируется Personnel (`GetProfileIdsByFilter` → profileIds → scope). ФИО в строке запроса не нужно.
- Owner-скоуп — `ComplianceDashboardScope::restrictProfileIds(profileIds, positionIds, q)` (обобщён: принимает поля, не весь dashboard-фильтр); оба dashboard-хендлера обновлены.
- Список без пагинации — компонент `components/infinite_list.html.twig` + batch-partial `acts/_batch.html.twig`, контроллер отдаёт батч на `?partial=1`.
- Карточка по акту — `DownloadCardAction` второй маршрут `.../document/{documentId}/card`; `RequirementCardProjector::project(..., ?documentId)` резолвит ровно этот документ (404-safe: чужой/не того требования → null).
- Модалка `_person_detail` — read-only (кнопки действий убраны), одна кнопка «Акты» в шапке `_preview` → `/compliance/acts?profile={id}`.

## Гейты
- `./run check` зелёный (style/phpstan L6/unit/functional).
- `cd app && yarn dev` если тронем JS/CSS (фильтр/typeahead — вероятно да).
- Коммиты по апруву, одной строкой ≤150, по-русски, без тела/Co-Authored-By. Всё в ветке `feat/compliance-card-norm-and-facts` (вольём вместе с #107).

## Follow-up (отдельная задача)
- Кнопка «Открыть» у подписанного акта ведёт на `/requirement/{id}/issue` — это страница ПО ТРЕБОВАНИЮ (одна на все акты требования, режим списания), поэтому у всех подписанных актов одного требования ведёт в одно место. Нужна страница просмотра КОНКРЕТНОГО акта по `documentId` (его позиции/скан/карточка/списания по нему). Сейчас такой страницы нет — завести отдельным шагом.

## Открытые мелочи (уточнить при реализации, не блок)
- «Новый документ» при нескольких требованиях у человека — пикер требования (typeahead/селект его требований).
- Нужна ли колонка «отдел» в списке (как в дашборде) — добавим, если попросишь.
