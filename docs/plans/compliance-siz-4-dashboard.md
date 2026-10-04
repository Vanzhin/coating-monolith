# Деплой 4: раздел «Соответствие» — дашборд картины + разрезы фильтром

**Цель:** отдельный раздел `/cabinet/compliance/dashboard` «Соответствие» — увидеть КАРТИНУ обеспеченности сотрудников по требованиям должностей; разрезы (по людям / по отделам / по требованиям) формируются фильтром. Переиспользует бэкенд Д3 (проекция + цикл документа + статус).

**Эталон раскладки:** согласованный макет `https://claude.ai/code/artifact/4711ebc6-c07d-4e35-bbff-bc83ad42659d` (сохранён в tool-results). Реализуем НАШИМИ компонентами/токенами (карта ниже), а не CSS макета.

**Эталон механики:** список Покрытий (`admin/coating/coating/index.html.twig` + `components/list_page.html.twig` + `components/entity_search.html.twig` + контроллер `chip-facets`) — фасеты, состояние фильтра в URL, гидрация чипов по id, пагинация.

## Global Constraints

- **Раздел generic — «Соответствие», НЕ «Учёт СИЗ».** СИЗ — лишь один тип (material). Никаких «СИЗ»/`Ppe*` в именах раздела, меню, заголовках. Тип показываем как «Выдача»/«Процедура» (material/nonmaterial).
- **Дизайн только из нашей системы.** Цвета — токены `tokens.css` (`--ok/--warn/--danger-strong` + `-subtle`, `--surface/--sunken/--accent-subtle`, `--bs-*`). Никаких новых цветов/hex. Компоненты — из карты ниже; своего изобретаем МИНИМУМ (только сегмент-полоса и stat-чип, оба на токенах).
- **Состояние фильтра — в URL** (shareable): `?lens=&status=&dept=&pos=&type=&only=&q=&profile=&page=`. Движок — контроллер `chip-facets` (мёрж полей формы поверх query).
- **Поиск/листинг — один `findByFilter(ComplianceDashboardFilter): PaginationResult`.** Не плодить `searchByX`. Агрегаты (KPI/отделы/требования) — отдельный read-query (это аналитика, не листинг).
- **Owner-скоуп как в Отчётах (согласовано).** Тот же дашборд для всех; авторизация — в Application-хендлере: не-админу принудительно подставляем `profileId` = ЕГО профиль, выбранные им фильтры людей (dept/pos/profile/q) игнорируем; админ — фильтр как есть, видит всех. Эталон: `GetPagedReportsQueryHandler` (`ownerIds = isManager() ? выбранные : StringCollection(currentUserId())`). Отличие: проекция ключуется `profileId`, а не userUlid → резолвим текущего юзера в profileId через Personnel (`GetProfileByUser(userUlid): ?ProfileDTO`; нет профиля → пустой результат). Пункт меню виден ВСЕМ авторизованным (каждому — свой скоуп).
- **Домен не трогаем.** `ComplianceStatus` (Green/Yellow/Red) — коарс-инвариант, остаётся. 4-статусный светофор дашборда (`ok/soon/overdue/missing`) — read-side bucket, выводится на чтении из тех же данных.
- **Тонкие per-action контроллеры.** Логика — в Query-handler; контроллер собирает фильтр и рендерит.
- Гейты — контейнерный `./run check`; тест-БД мигрировать при необходимости. Коммиты — по явному апруву (feedback_no_commits).

---

## Карта дизайна: макет → наш компонент (НЕ изобретать)

| Элемент макета | Что берём (файл / класс / контроллер) |
|---|---|
| **KPI-полоска** (4 чипа: счётчик+подпись+левый цветной акцент, клик = фильтр по статусу) | Компонуем: `<a class="btn btn-sm ...">` + `.chip-count` (`admin/coating-list.css`) + левый акцент `box-shadow: inset 3px 0 0 var(--ok\|--warn\|--danger-strong)` (идиом из `admin/compare.css` `.cmp-row--diff`). Активный чип — `.btn-primary`/outline-toggle как фильтр-чипы в `cabinet/document/index.html.twig`. Клик → тоглит `?status=` |
| **Фильтр-бар (shell)** | `components/list_page.html.twig` (блоки `header_actions`/`above_list`/`list_content`/`below_list`) + `components/entity_search.html.twig` (`.chip-row`, кнопка «Все фильтры» с `.chip-count`, `.offcanvas.entity-drawer` с «Сбросить всё / Показать») |
| Поиск по ФИО | `components/_search_toolbar.html.twig` (`input[type=search].form-control`) |
| Отдел / тип — селекты | `<select class="form-select form-select-sm">` в `.facet-block` (как thermEnv в coating index) |
| Должность — выбор | `typeahead` (`assets/controllers/typeahead_controller.js`), разметка из `admin/compliance/requirements/_macros.html.twig` `position_row` (endpoint `app_cabinet_personnel_position_suggest`) |
| «только проблемы» — тоггл | `.form-check`/`.form-check-input` или пилюля `.facet-pill` (`coating-list.css`) |
| Чип человека (removable, из `?profile=`) | Идиом `reference-chips` (`assets/controllers/reference_chips_controller.js` + `reference_helpers.fetchTitlesByIds`) → нужен JSON `Personnel ByIds` (профиль→ФИО). Либо серверный чип `<a class="btn btn-sm btn-primary">…<i bi-x-lg>` с remove-URL |
| Счётчик результатов | пилюля «Найдено: N» (`<div class="d-inline-block p-2 px-3 rounded-3 bg-body-tertiary small">`) |
| Ссылка для уведомления (linkline) | текст пути с query (как в макете), моноширинный `text-body-secondary small` |
| **Разрез (люди/отделы/требования)** | Bootstrap `.btn-group` из `<a class="btn">`, активный = `.btn-primary` (идиом `cabinet/proposal/index.html.twig`) |
| **Строка человека (раскрытие)** | Нативный Bootstrap accordion (`.accordion-item/.accordion-button.collapsed/.accordion-collapse.collapse/.accordion-body`, идиом `cabinet/proposal/index.html.twig`, `cabinet/document/index.html.twig`) |
| Визуал строки | `ecard`-классы (`components/entity-card.css`): `.ecard-media`/`.ecard-title`/`.ecard-desc`/`.ecard-meta`/`.ecard-tags`+`.tag`; аватар-инициалы — `.ecard-mono` (+`.ecard-mono--sm`); левая цветная полоса — `inset 3px 0 0 var(--token)` |
| Деталь: таблица позиций | `.cmp-table` (`admin/compare.css`) / `components/tables.css`; колонки Позиция·Норма·Посл. выдача·След. срок·Статус |
| Действия в детали | уже готовые контроллеры цикла Д3: «Зафиксировать выдачу» → `app_cabinet_compliance_fulfillment_record`; «Сформировать документ»/«Приложить подписанную»/«Скачать скан» (form/attach/download); для nonmaterial — «Отметить прохождение» |
| Пилюли статуса | `.verdict.is-ok/is-warn/is-danger` (`entity-card.css`) + `statusMap` (как в `admin/compliance/person/show.html.twig`). 4-й (missing vs overdue): оба `is-danger`, различаем иконкой/подписью (missing `bi-x-circle` «не выдано» · overdue `bi-exclamation-triangle` «просрочено») |
| **Карточки отделов/требований** | контейнер `.ecard`/`.tool-card`; **сегмент-полоса охвата — собрать новую** из `<div>`-ов `width:%` на `--ok/--warn/--danger-strong`; легенда — идиом `.pv-matrix-legend`/`.lg-item` (`components/preview.css`) |
| Новый CSS | `assets/styles/admin/compliance-dashboard.css` (только stat-чип + сегмент-полоса), `@import` в `app.css`; **только токены** |

**Строим новое (нет готового):** сегмент-прогресс-полоса+легенда; lens `.btn-group`; stat-чип KPI (композиция); аватар — `.ecard-mono`.

---

## Backend

### T1. Read-side статус-бакет (4 состояния)

**Файлы (новые):**
- `src/Compliance/Application/ReadModel/ComplianceBucket.php` — `enum ComplianceBucket: string { Ok='ok'; Soon='soon'; Overdue='overdue'; Missing='missing'; }` + `label(ComplianceType): string` (missing: material→«не выдано», nonmaterial→«не пройдено»; soon→«подходит срок»; overdue→«просрочено»; ok→«в норме»), `severity(): int`, `worseOf()`.
- `src/Compliance/Application/ReadModel/ComplianceBucketResolver.php` — `bucketFor(bool $active, ?lastFulfilledAt, ?nextDueAt, now): ComplianceBucket`: `!active`→Missing (нет подписанного документа — не исполнено); `lastFulfilledAt===null`→Missing; `nextDueAt===null`→Ok; `nextDueAt<now`→Overdue; `<=now+14д`→Soon; иначе Ok. Согласовано с доменным `ComplianceStatusResolver` (Missing+Overdue = его Red).

- [ ] Юнит: все ветки + `worseOf` (Missing доминирует над Overdue над Soon над Ok? порядок серьёзности: overdue и missing = проблемы; для «худшего» severity Missing≈Overdue>Soon>Ok — уточнить: worst берём по severity, Missing и Overdue оба «bad», но различимы). Тест матрицы.
- [ ] Коммит.

### T2. Фильтр + read-DTO людей

**Файлы (новые):**
- `src/Compliance/Application/UseCase/Query/Dashboard/ComplianceDashboardFilter.php` — bag-of-fields: `?string $q`, `StringCollection $departmentIds`, `StringCollection $positionIds`, `?ComplianceType $type`, `bool $onlyProblems`, `?string $statusBucket` (ok/soon/overdue/missing), `?string $profileId`, `Pager $pager`. Инвариантов нет (голый bag).
- `src/Compliance/Application/DTO/Dashboard/PersonRowDTO.php` — `profileId`, `fullName`, `positionTitle`, `departmentTitle`, `initials`, `worstBucket` (string), `list<ObligationRowDTO> obligations` (переиспользуем Д3-DTO), `array<requirementId,RequirementDocumentDTO> documents`, `array{material:string,nonmaterial:string} breakdown` (сводка-чипы «Выдача: 2 просрочено · …»).
- В `ProfileComplianceRepositoryInterface` + реализация: `findByFilter(ComplianceDashboardFilter): PaginationResult` (paged `ProfileCompliance` + fetch-join детей; фильтры по денорм-полям `department_id`/`type`/`active`+`next_due_at` для `statusBucket`/`onlyProblems`; ФИО/должность/отдел — join к Personnel ЧЕРЕЗ денормализованные поля проекции или id-фильтр из Personnel-шины). ФИО/должность/отдел в проекции нет — резолвим пачкой через `GetProfilesByIds` (Personnel Application-query) при сборке DTO (не лезем в чужой агрегат).

**Реализация (согласовано, in-PHP at scale):** масштаб — сотрудники (сотни), поэтому НЕ пишем SQL-CASE-агрегаты по времени. Хендлер загружает набор проекций (fetch-join детей) и считает бакеты/худший/KPI/разрезы/сорт/пагинацию **в PHP** (`ComplianceBucketResolver`). Разделение источников:
- **Personnel** владеет идентичностью и своими фильтрами: `GetProfileByUserUlid` (owner-скоуп, есть), `GetProfileIdsByFilter(ProfilesFilter): StringCollection` (позиция+поиск ФИО → profileId'ы; НОВЫЙ, репо `findIdsByFilter`), `GetProfilesByIds(StringCollection): list<ProfileDTO>` (обогащение имён страницы; НОВЫЙ, репо `findByIds` есть).
- **Compliance-проекция** владеет статусом: репо `findForDashboard(?StringCollection $restrictProfileIds, StringCollection $departmentIds): list<ProfileCompliance>` — грузит проекции (fetch-join obligations+documents), фильтр по `profile_id IN` (если задан) и по денорм `department_id` (отдел). Пагинация/сорт/бакет-фильтры — в PHP-хендлере.
- Хендлер `GetPagedComplianceQueryHandler`: owner-скоуп → restrictProfileIds; позиция/поиск → Personnel `GetProfileIdsByFilter` (пересечь); load `findForDashboard`; PHP: бакет каждой обязанности + худший на человека, фильтры type/status/onlyProblems, сорт (худший↓, ФИО), пагинация; обогащение имён `GetProfilesByIds`.

- [ ] Функц.: `findByFilter` — пагинация, фильтр по отделу/типу/статусу/«только проблемы», поиск по ФИО (через Personnel), профиль по id.
- [ ] Коммит.

**Скоуп в хендлере (owner-scope как Отчёты):** `findByFilter` вызывается из `GetPagedComplianceQueryHandler`, который до вызова репозитория форсит фильтр: `isManager()` → фильтр как есть; иначе → новый фильтр с `profileId` = профиль текущего юзера (Personnel `GetProfileByUser(currentUserId())`), остальные people-поля (departmentIds/positionIds/q) обнуляются. Нет профиля → пустой `PaginationResult`.

### T3. Overview-агрегат (KPI + разрезы отдел/требование)

**Файлы (новые):**
- `src/Compliance/Application/UseCase/Query/Dashboard/GetComplianceOverviewQuery.php` (тот же фильтр без пагинации) + `...Result` + `...Handler`.
- `...Result`: `array{ok:int,soon:int,overdue:int,missing:int} kpi`; `list<DeptBreakdownDTO>` (`title`, счётчики бакетов, `total`); `list<RequirementBreakdownDTO>` (`name`, `type`, счётчики, `coverage`). Счёт по ХУДШЕМУ бакету человека в разрезе (как макет: `worst(obl)` на человека в отделе/требовании).
- Репозиторий: агрегатные SQL по денорм-полям (`department_id`, `requirement_id`, `active`, `next_due_at`) с `now` в параметре — индексные, без загрузки агрегатов. Бакет в SQL через CASE (active/next_due_at/last_fulfilled_at). KPI — по худшему на человека (GROUP BY profile → худший → COUNT по бакету).
- **Скоуп тот же:** overview-хендлер форсит `profileId` не-админу (Personnel `GetProfileByUser`) до агрегата — юзер видит KPI/разрезы только по себе (тривиально), админ — по всем/по фильтру.

- [ ] Функц.: KPI совпадает со счётом строк; разрезы отдел/требование по тем же данным; фильтр влияет на агрегаты.
- [ ] Коммит.

### T4. Personnel: опции фильтров + гидрация чипа

**Файлы:**
- Опции отделов/должностей для селектов — существующие Personnel suggest/list (`app_cabinet_personnel_position_suggest`, аналог для отделов). Проверить наличие department-suggest; если нет — не плодить, взять список отделов через существующий query.
- `Personnel ByIds` JSON для гидрации чипа человека из `?profile=`: `app_cabinet_personnel_profile_by_ids` → `{items:[{id,title}]}` (title=ФИО). Если есть — переиспользовать; нет — добавить тонкий Action + `GetProfilesByIds`.
- `GetProfileByUser(string $userUlid): ?ProfileDTO` (Personnel Application-query) — для owner-скоупа (текущий юзер → его profileId). Если нет — добавить (тонкий, через `ProfilesFilter`/репозиторий по `userUlid`).

- [ ] Функц.: by-ids отдаёт ФИО по profileId; `GetProfileByUser` находит профиль по userUlid (и null, если нет).
- [ ] Коммит.

---

## Frontend

### T5. Дашборд-страница (shell + фильтры + KPI + URL)

**Файлы (новые):**
- `src/Compliance/Infrastructure/Controller/Dashboard/IndexAction.php` — `#[Route('/cabinet/compliance/dashboard', name:'app_cabinet_compliance_dashboard', methods:['GET'])]`. Тонкий: маппит query→`ComplianceDashboardFilter` (ViewFactory/RequestMapper по образцу Coatings `ListAction`+`CoatingListViewFactory`), диспатчит `findByFilter` (если lens=people) + `GetComplianceOverview` (всегда), рендерит.
- `Templates/admin/compliance/dashboard/index.html.twig` — `{% embed 'components/list_page.html.twig' %}` + `entity_search`; шапка: заголовок «Соответствие», подзаголовок; KPI-полоска (4 stat-чипа, клик тоглит `?status=`); lens `.btn-group` (люди/отделы/требования, `?lens=`); фильтр-бар (отдел select · должность typeahead · тип select · «только проблемы» toggle · поиск ФИО); чип человека (reference-chips) + счётчик + linkline. Всё поле фильтра — в форме под `chip-facets` (мёрж в URL).
- `Templates/admin/compliance/dashboard/_people.html.twig` — accordion строк (ecard-визуал + `.ecard-mono` + левая полоса статуса + сводка-чипы `breakdown` + chevron); `.accordion-body` = `_person_detail`.
- `Templates/admin/compliance/dashboard/_person_detail.html.twig` — группы требований (reqh: имя + тег типа + статус документа + действия) + `.cmp-table` (Позиция·Норма·Посл.·Срок·Статус-пилюля). Действия — цикл Д3 (см. карту). **Это ПЕРЕИСПОЛЬЗОВАНИЕ контента бывшей `person/show.html.twig`.**
- `Templates/admin/compliance/dashboard/_aggregate.html.twig` — карточки разрезов (отдел/требование): `.ecard`/`.tool-card` + сегмент-полоса + легенда + счётчики.
- `assets/styles/admin/compliance-dashboard.css` — только: stat-чип (левый акцент+счётчик), сегмент-полоса (`.seg-bar>i` width:% на токенах), мелочь раскладки KPI-грид. `@import` в `app.css`. Никаких новых цветов.

- [ ] `yarn dev` + браузер: KPI-картина; переключение разрезов; фильтры пишутся в URL; шаринг-ссылка воспроизводит фильтр; чип человека гидрируется из `?profile=`.
- [ ] Коммит.

### T6. Инлайн-деталь: действия цикла из строки

- Кнопки детали ведут в уже готовые контроллеры Д3 (form/attach-scan/download/record) с `profileId`+`requirementId`; после действия redirect обратно на дашборд с сохранённым фильтром (referer/URL-состояние). У подписанного требования — замок + «Скачать скан», «Зафиксировать выдачу» скрыт (как в Д3 show).
- staged-file загрузка скана — контроллер `staged-file` (уже есть).

- [ ] Браузер: из строки человека сформировать документ → приложить скан → статус строки/KPI пересчитались; фильтр в URL сохранился после действия.
- [ ] Коммит.

### T7. Убрать неправильный вход + переименования

- Удалить пункт «Учёт СИЗ» из троеточия `admin/personnel/profile/index.html.twig` (СИЗ не место в HR-справочнике).
- `_shell/_sidebar.html.twig` (+ мобильный `_bottom_nav`): добавить пункт **«Соответствие»** (`app_cabinet_compliance_dashboard`, иконка напр. `bi-shield-check`) в ОБЩУЮ навигацию (виден всем авторизованным, как «Отчёты»), НЕ в админ-группу — юзер видит своё (owner-скоуп в хендлере). «Нормы»/«Персонал» остаются админскими.
- Бывшая `person/show.html.twig` + `ShowAction`: контент переехал в `_person_detail`. Решить на реализации — оставить `ShowAction` как прямой вход по `profileId` (редирект на `dashboard?profile=<id>`) или удалить. Ссылки на `app_cabinet_compliance_person_show` (уведомления, если есть) перенаправить на `dashboard?profile=`.
- Проверить, что нигде не осталось «Учёт СИЗ»/`Ppe*` в новом коде.

- [ ] `./run check` зелёный; браузерный смоук всех разрезов.
- [ ] Коммит.

## Финал Д4

- [ ] `./run check` зелёный; тест-БД мигрирована (миграций, скорее всего, нет — только денорм-индексы, если понадобятся для фильтров).
- [ ] Браузер: картина читается за 3 секунды; deeplink `?profile=` из уведомления открывает человека; все разрезы; действия цикла из строки; тёмная тема.
- [ ] Нет `dd()`/мёртвого кода; нет «СИЗ»-хардкода в имени раздела.
- [ ] Пуш/мерж — по апруву.

## Развилки — ЗАКРЫТЫ (согласовано)

1. **Данные строки (ФИО/должность/отдел).** ✔ Резолвим пачкой через Personnel (`GetProfilesByIds`); фильтр по ФИО/должности — Personnel отдаёт profileId, ими сужаем проекцию. Не лезем в чужой агрегат.
2. **Denorm.** ✔ Обходимся `department_id` в проекции + Personnel для ФИО; доп. колонок не заводим, пока не упрёмся.
3. **Судьба `person/show`.** ✔ Редирект `→ dashboard?profile=<id>` (deeplink сохраняем), не удаляем.
4. **Доступ.** ✔ Owner-скоуп как в Отчётах: админ — вся картина/фильтр; не-админ — принудительно свой `profileId` (Personnel `GetProfileByUser`), остальные people-фильтры игнорируются. Пункт меню виден всем авторизованным. Мутации цикла — админ (гейт есть).
