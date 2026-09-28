# Деплой 4: дашборд-светофор, ответственные, планировщик-алерт (`symfony/scheduler`)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development или superpowers:executing-plans. Шаги — чекбоксы.
>
> Соседи: `-1-personnel.md` (нужен: `Department`-дерево, `Profile`), `-2-requirements.md`, `-3-tracking-card.md` (нужен: `PersonCompliance`, `TrackedObligation`, `ComplianceStatus`). Спека: `docs/plans/compliance-siz-design.md`. Самодостаточен.

**Цель:** дашборд по людям со светофором (агрегатный цвет = худший статус обязанностей, группировка по пользователю и по отделу-дереву, фильтр по типу, фасеты, админ видит всех); двухуровневый ответственный (`FormResponsible` + `ResponsibilityResolver` с обходом дерева отделов); общий планировщик `symfony/scheduler` + ежедневная задача `NotifyDueMessage`, шлющая уведомление ответственному (+копия сотруднику).

**Архитектура:** дашборд — read-side query по индексам `TrackedObligation` (`subject_user_ulid`, `department_id`, `status`, `type`, `next_due_at`), без загрузки агрегатов. Ответственный резолвится: `FormResponsible(type) ?? начальник ближайшего предка-отдела`. Планировщик — `symfony/scheduler` как общий backbone: расписание в коде, гоняет существующий Messenger-воркер + отдельный supervisor-consumer scheduler-транспорта. Уведомления — `SendNotificationCommand` (Notifications не трогаем).

**Tech Stack:** как Д1–Д3 + `symfony/scheduler`, `App\Shared\Application\Command\SendNotificationCommand` (из Notifications), supervisor.

## Global Constraints

- (Все ограничения Д1–Д3: правила в домене, marker-interface хендлеры, тонкие контроллеры, `ComplianceAccessControl`, тесты зеркалят `src/`+функц. с реальной БД+`authenticateAsSystem()`, фронт сборка+браузер, стили копируем, `./run check`, миграции идемпотентные, коммиты по задаче/пуш по апруву.)
- Состояние фильтра дашборда — в URL (shareable); чипы восстанавливаются JS-гидрацией через by-ids эндпоинты, не резолвом на сервере (паттерн проекта).
- JSON-эндпоинты — под `/api` (для by-ids/suggest дашборда).
- Notifications context НЕ трогаем — только `commandBus->execute(new SendNotificationCommand($ulid, $msg))`.

## Review Focus

- **Человек без профиля/без отдела** при резолве ответственного → не падать; если начальник не найден до корня и нет form-override → лог/пропуск с понятным поведением (тест в T2).
- **Ежедневный повтор алерта** — один и тот же просроченный item не шлёт уведомление каждый день; дедуп по «оповещён», сброс после факта (тест в T4).
- **Группировка по отделу** для человека в подотделе — попадает в свой узел, агрегат по узлам сворачивается корректно (тест в T3).
- **Фильтр по типу** пересчитывает агрегатный цвет человека по подмножеству (СИЗ ок, лёгкий инструктаж Red → без фильтра Red, с фильтром «только СИЗ» Green) (тест в T3).
- **scheduler-воркер и авторизация** — задача дергает command-bus; принципал должен быть системным (иначе `ForbiddenException` в `SendNotification`/резолвере). Проверить/поставить (тест/ручная проверка в T5).

---

## T1. `FormResponsible` (переопределение ответственного по форме)

**Файлы (новые):**
- `app/src/Compliance/Domain/Aggregate/FormResponsible/FormResponsible.php` — `class FormResponsible extends Aggregate`: `Uuid $id`, `ComplianceType $type` (уникален — один ответственный на тип в v1), `string $userUlid`. Спека уникальности по type.
- Репозиторий `FormResponsibleRepositoryInterface` (`findByType(ComplianceType): ?FormResponsible`, `add`, `remove`) + реализация.
- ORM `FormResponsible.FormResponsible.orm.xml` (таблица `compliance_form_responsible`, `type` unique).
- Command `SetFormResponsibleCommand(type, userUlid)` + Handler (`canManage`) + `ClearFormResponsibleCommand`. Простой admin-экран назначения (тип→пользователь), typeahead по пользователям.

- [ ] Миграция: `CREATE TABLE IF NOT EXISTS compliance_form_responsible (id VARCHAR(36) PRIMARY KEY, type VARCHAR(50) NOT NULL, user_ulid VARCHAR(26) NOT NULL); CREATE UNIQUE INDEX IF NOT EXISTS uniq_compliance_form_responsible_type ON compliance_form_responsible (type);`
- [ ] Функц. тест: set/clear, повтор type → перезапись/AppException (зафиксировать: upsert по типу).
- [ ] Коммит.

## T2. `GetResponsibleHeadQuery` (Personnel) + `ResponsibilityResolver` (Compliance)

**Файлы (новые):**
- `app/src/Personnel/Application/UseCase/Query/GetResponsibleHead/{GetResponsibleHeadQuery,Handler,Result}.php` — `GetResponsibleHeadQuery(string $subjectUserUlid)`. Handler: `Profile.findOneByUserUlid` → `departmentId` → `DepartmentRepository::findAncestors(departmentId)` (узел→корень) → первый с непустым `headUserUlid` → вернуть его. Нет — `null`. (Реализует обход дерева вверх; данные Personnel.)
- `app/src/Compliance/Application/Service/AccessControl/../ResponsibilityResolver.php` (в `Compliance/Application/Service/`): `resolve(string $subjectUserUlid, ComplianceType $type): ?string` = `FormResponsibleRepository::findByType(type)?->userUlid ?? queryBus->execute(new GetResponsibleHeadQuery(subjectUserUlid))->userUlid`. Кросс-контекст — через query-шину (не лезть в Personnel-репо напрямую).

- [ ] Юнит/функц. `GetResponsibleHead`: начальник на узле; начальник только у родителя (обход вверх находит); нет начальника нигде → null; нет профиля → null.
- [ ] Функц. `ResponsibilityResolver`: form-override выигрывает у начальника; без override → начальник отдела; ни того ни другого → null.
- [ ] Коммит.

## T3. Дашборд-светофор (read-side query + UI)

**Файлы (новые):**
- Query `app/src/Compliance/Application/UseCase/Query/GetComplianceDashboard/{Query,Handler,Result}.php` — вход: фильтр (`?ComplianceType $type`, `?departmentId`, `?positionId`, `?organizationId`, `?ComplianceStatus $status`, `?string $search` по ФИО, режим группировки `by_user|by_department`, пагинация). Handler строит **агрегатный запрос по `TrackedObligation`**: группировка по `subject_user_ulid`, `MIN`/худший `status` (маппинг severity), фильтр по `type` (пересчёт подмножества), джойн подписи ФИО/отдела из снапшота (денормализованные `department_id` + ФИО — либо через `Profile`-query batch по userUlids). Результат — список `PersonComplianceRowDTO {userUlid, fullName, positionTitle, departmentId, departmentTitle, worstStatus}`; при группировке `by_department` — вложенные группы по узлам дерева (сворачивание).
- DTO `PersonComplianceRowDTO`, `DashboardGroupDTO` (вложенные, без array-shape).
- Резолв цвета — из `ComplianceStatus` (Red/Yellow/Green → класс/оттенок). Дизайн плитки — минимальный (копировать entity-карточку медиа-C фронт-редизайна); финальный визуал прорабатывается отдельно.
- Контроллер `Infrastructure/Controller/Dashboard/{ListAction}.php` (тонкий) + by-ids/suggest эндпоинты под `/api` для гидрации чипов фасетов (отдел/должность/организация).
- Шаблон `cabinet/compliance/dashboard.html.twig` — плитки людей (ФИО + цвет), переключатель группировки (пользователь/отдел), фильтр по типу, фасеты+чипы (URL-state), поиск по ФИО. Стили/чипы — копировать существующий фильтр-UI (без новых классов/цветов; цвет светофора — из уже используемых оттенков success/warning/danger). Программный сабмит — `requestSubmit()` (не `form.submit()` — иначе фасеты перетрутся).
- Доступ: админ видит всех (просмотр — авторизованным; кнопки правки — под `canEdit`).

- [ ] Функц. тесты query: агрегатный худший статус на человека; фильтр по типу пересчитывает цвет (СИЗ Green + лёгкий Red → без фильтра Red, с «только СИЗ» Green); группировка by_department раскладывает по узлам; поиск по ФИО.
- [ ] `yarn dev` + браузер: дашборд, переключение группировок, фильтр по типу, цвета корректны, состояние в URL (перезагрузка сохраняет фильтр).
- [ ] Коммит.

## T4. Дедуп алертов на `TrackedObligation`

**Файлы (изменяемые/новые):**
- На `TrackedObligation` (из Д3) добавить `?\DateTimeImmutable $lastNotifiedAt` + метод `markNotified($now)`; `recalculate` при новой выдаче сбрасывает `lastNotifiedAt=null` (после факта можно снова алертить в следующий цикл). Миграция: `ADD COLUMN IF NOT EXISTS last_notified_at TIMESTAMP(0) NULL`.
- Правило «слать ли»: item в Red/Yellow И (`lastNotifiedAt IS NULL` ИЛИ прошёл период тишины, напр. `lastNotifiedAt < now - 7 дней`) — константа `NOTIFY_COOLDOWN_DAYS`. Зафиксировать: не чаще раза в 7 дней на item.
- Репозиторий `PersonComplianceRepository::findObligationsToNotify(\DateTimeImmutable $now): array` — по индексам `status IN (Red,Yellow)` (или `next_due_at <= now+14д`) + cooldown.

- [ ] Функц. тест: item Red без `lastNotifiedAt` → в выборке; после `markNotified` → вне выборки; спустя cooldown → снова; после факта (recalculate) → сброс.
- [ ] Коммит.

## T5. `symfony/scheduler` backbone + `NotifyDueMessage` + supervisor

**Файлы (новые/изменяемые):**
- Добавить зависимость `symfony/scheduler` (composer, версия под Symfony 8 — см. [[project_php85_symfony8_upgrade]]).
- `app/src/Shared/Infrastructure/Scheduler/AppSchedule.php` — `#[AsSchedule('default')]` `implements ScheduleProviderInterface`: `getSchedule()` возвращает `Schedule` с `RecurringMessage::every('1 day', new NotifyDueMessage())` (общий backbone — сюда добавляются будущие периодические задачи любого контекста). Транспорт scheduler — `scheduler_default` (авто).
- `app/src/Compliance/Application/Message/NotifyDueMessage.php` — `implements MessageInterface` (или подходящий marker для `message.bus`).
- `app/src/Compliance/Application/Message/NotifyDueMessageHandler.php` — `implements MessageHandlerInterface`: `findObligationsToNotify(now)` → на каждый: `ResponsibilityResolver::resolve(subject, type)` → если есть — `SendNotificationCommand(responsibleUlid, "Сотруднику {ФИО}: {label} — {просрочено/подходит срок до DATE}")`; **копия сотруднику**: `SendNotificationCommand(subjectUserUlid, ...)`; `markNotified`. Батч, сохранение пометок.
- `messenger.yaml` — при необходимости роутинг `NotifyDueMessage` (scheduler кладёт в транспорт; хендлер на `message.bus`/`async`). Проверить, как scheduler интегрируется с их buses (default_bus=command.bus): scheduler-сообщения идут через свой транспорт → consumer.
- `docker/supervisor/conf.d/scheduler-worker.conf` (новый, по образцу `messenger-worker.conf`): `php bin/console messenger:consume scheduler_default --time-limit=3600`.
- **Принципал воркера:** `ConsoleAuthenticationSubscriber` ставит `SystemUser` на `ConsoleEvents::COMMAND` (CLI). `messenger:consume` — это консольная команда, значит принципал ставится при старте воркера — проверить, что действует на всё время consume (если токен сбрасывается между сообщениями — добавить middleware/subscriber, ставящий `SystemUser` на каждое сообщение scheduler-транспорта). Иначе резолвер/SendNotification упрутся в `ForbiddenException`.

- [ ] Функц. тест `NotifyDueMessageHandler` (реальная БД, `authenticateAsSystem()`): просроченный item → создано уведомление ответственному + копия сотруднику; `lastNotifiedAt` проставлен; без ответственного → только копия сотруднику (или лог — зафиксировать).
- [ ] Ручная проверка: `bin/console messenger:consume scheduler_default -vv` в контейнере не падает на авторизации; уведомление создаётся.
- [ ] Коммит.

## Финал Д4

- [ ] `./run check` зелёный; тест-БД мигрирована; cleanup style/phpstan.
- [ ] `yarn dev` + браузер: дашборд-светофор (группировки/фильтр/цвета/URL), назначение ответственного по форме.
- [ ] Планировщик: consumer поднимается в supervisor, задача шлёт уведомления, дедуп работает (повторный прогон не дублирует).
- [ ] Нет `dd()`/`var_dump`/мёртвого кода; `.DS_Store` не в staged.
- [ ] Прод-развёртывание: добавить supervisor-программу `scheduler-worker`; проверить redis/messenger-транспорты (см. [[reference_prod_messenger_redis_ops]]); Redis-группы scheduler переживают деплой.
- [ ] Коммиты партиями (FormResponsible / резолвер+query / дашборд / дедуп / scheduler+supervisor). Пуш/мерж — по апруву.
- [ ] Открытые к пользователю: cooldown/пороги алертов (принято 14д/7д), финальный набор фасетов, поведение при отсутствии ответственного.
