# Акт списания — кросс-персон агрегат (D5) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Вынести `WriteOffAct` из агрегата `ProfileCompliance` в самостоятельный кросс-персональный агрегат (один акт = одна организация × одно требование, позиции РАЗНЫХ людей), с fan-out применением при подписи, списком актов (фильтр, кросс-орг для админа) и миграцией прод-данных.

**Architecture:** Новый агрегат `WriteOffAct` со своим репозиторием/таблицей (без FK на `profile_compliance_id`); строки несут `profileId`+`recordId`+`labelSnapshot`. Наполнение корзины — с карточки выдачи человека (org-scoped). Подпись оркеструется в Application: грузит все затронутые `ProfileCompliance`, гасит факты (`applyWriteOff`), пересчёт по парам, одна транзакция. Каскад удаления факта — доменное событие `FulfillmentRecordRemoved` + синхронный хендлер. Листинг — `findByFilter(WriteOffActFilter): PaginationResult` (как Coatings). Порядок задач держит зелёные тесты: новый агрегат аддитивно → новые командные пути → репойнт контроллеров/UI и снятие старого с `ProfileCompliance` → data-миграция.

**Tech Stack:** PHP 8.5, Symfony Messenger (command/event bus, `doctrine_transaction` middleware), Doctrine ORM (XML mapping, custom JSON DBAL types), PHPUnit 11, функциональные тесты в контейнере `test_php-cli`.

**Spec:** `docs/plans/compliance-writeoff-crossperson-design.md`

## Global Constraints

- Акт — самостоятельный агрегат; строка = `{id, profileId, recordId, labelSnapshot, quantity, reason}`. Корзина уникальна по (requirementId, organizationId). Только инвентарные материальные held>0.
- Инварианты — в домене (неизменяемость подписанного, `quantity>0`, причины обязательны на подписи, непустая комиссия+скан на подписи). `qty ≤ held` — в Application-хендлере (held — свойство `ProfileCompliance`, не акта).
- Генерация id — в хендлере/мейкере (кандидат-id в домен), не в домене.
- Apply-on-sign синхронно, fan-out по людям, в ОДНОЙ транзакции (`doctrine_transaction` оборачивает команду). События пересчёта — по каждой паре (profileId, requirementId).
- Листинг — ОДИН `findByFilter`; состояние фильтра в URL; доступ админский (кросс-орг), `AccessGuard::isManager()`.
- Комиссия/представитель — свободный текст (Shared `Commission` VO), как сейчас. profileId — НЕ в этом деплое.
- Авторизация — `ComplianceAccessControl` в Application-хендлере, не `#[IsGranted]`. Контроллеры тонкие.
- Миграции идемпотентны, `down()` no-op. Прод НЕ пуст (3 акта / 8 позиций). `./run check` зелёный к концу каждой задачи (при пустой test_db — сперва migrate в контейнере).
- Стили не изобретать — листинг копировать с `admin/compliance/requirements/index.html.twig` + `components/list_page.html.twig`; страница акта — переверстать существующий `writeoff/act.html.twig` под группы по людям (разметку брать из текущей).

## Review Focus

- **Кросс-персон подпись** (акт с людьми A и B одной орг): гасит факты ОБОИХ, пересчёт обоих; не только первого. (Task 4.)
- **Разные организации не смешиваются**: добавление позиции человека другой орг в акт — новый черновик по своей паре, не в чужой. (Task 3.)
- **Частичное списание**: списать 1 из 10 → held=9, факт не удалён, можно дописать в другой акт позже. (Task 4.)
- **Миграция непустого прода**: 3 per-person акта → кросс-персон без потери recordId/реквизитов; совпавшие (req,орг) черновики слиты; скан-владелец переназначен на акт. (Task 8.)
- **Листинг кросс-орг**: админ видит акты всех организаций; фасет «Организация» фильтрует; пагинация `{items,page,hasMore}`. (Task 7.)

---

### Task 1: Агрегат `WriteOffAct` + `WriteOffActLine` (домен, самостоятельный)

Новый агрегат БЕЗ ссылки на `ProfileCompliance`. Пока без ORM/репозитория — чистый домен + юнит-тесты инвариантов. Аддитивно (старый `WriteOffAct` в `ProfileCompliance` пока жив, не трогаем) — зелёное.

**Files:**
- Create: `app/src/Compliance/Domain/Aggregate/WriteOffAct/WriteOffAct.php`
- Create: `app/src/Compliance/Domain/Aggregate/WriteOffAct/WriteOffActLine.php`
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/WriteOffAct/WriteOffActTest.php`

**Interfaces (Produces):**
- `WriteOffAct::__construct(Uuid $id, string $requirementId, string $organizationId, \DateTimeImmutable $now)` — статус `DocumentStatus::Formed`.
- `addLinesForProfile(string $profileId, list<array{recordId:string, labelSnapshot:string, quantity:float}> $lines): void` — только Draft (`assertMutable`); дедуп по `recordId` (в пределах акта); `quantity>0` иначе `AppException`.
- `replacePortions(list<array{recordId:string, quantity:float, reason:?WriteOffReason}> $lines): void` — set-семантика правки (сохраняет `profileId`/`labelSnapshot` существующих строк по `recordId`).
- `saveDetails(string $actNumber, ?\DateTimeImmutable $actDate, Commission $commission, string $orderNumber, ?\DateTimeImmutable $orderDate, string $representativePosition, string $representativeFio, \DateTimeImmutable $now): void`.
- `sign(Commission $commission, string $actNumber, \DateTimeImmutable $actDate, string $scanFileId, \DateTimeImmutable $now): void` — `assertMutable`+`assertReasonsComplete`+непустая комиссия+непустой scanFileId; статус `Signed`, `signedAt`. НЕ применяет к фактам.
- `removeLinesByRecordIds(list<string> $recordIds): void`.
- `linesByProfile(): array<string, list<WriteOffActLine>>`; `profileIds(): list<string>`; `lines(): list<WriteOffActLine>`; `isDraft()/isSigned()/assertMutable()`; геттеры (`getId`, `requirementId`, `organizationId`, `status`, `commission`, реквизиты, `scanFileId`, `signedAt`).
- `WriteOffActLine`: `__construct(Uuid $id, string $profileId, string $recordId, string $labelSnapshot, float $quantity, ?WriteOffReason $reason)`; геттеры `getId/profileId/recordId/labelSnapshot/quantity/reason`.
- Consumes: `App\Shared\Domain\ValueObject\Commission`, `App\Compliance\Domain\Type\WriteOffReason`, `App\Compliance\Domain\Type\DocumentStatus`, `App\Shared\Infrastructure\Exception\AppException`.

- [ ] **Step 1: Failing unit-тест инвариантов**

`WriteOffActTest.php` (зеркаль существующий `ProfileCompliance/WriteOffActTest.php` по стилю, но для нового агрегата, БЕЗ `new ProfileCompliance`):
```php
public function test_add_lines_grouped_by_profile_and_deduped(): void
{
    $act = new WriteOffAct(Uuid::v7(), 'req-1', 'org-1', new \DateTimeImmutable());
    $act->addLinesForProfile('p1', [['recordId'=>'r1','labelSnapshot'=>'Перчатки','quantity'=>10.0]]);
    $act->addLinesForProfile('p1', [['recordId'=>'r1','labelSnapshot'=>'Перчатки','quantity'=>10.0]]); // дубль
    $act->addLinesForProfile('p2', [['recordId'=>'r2','labelSnapshot'=>'Каска','quantity'=>1.0]]);
    self::assertCount(2, $act->lines(), 'дубль по recordId не добавлен');
    self::assertSame(['p1','p2'], $act->profileIds());
}
public function test_sign_requires_reason_on_every_line(): void
{
    $act = new WriteOffAct(Uuid::v7(), 'req-1', 'org-1', new \DateTimeImmutable());
    $act->addLinesForProfile('p1', [['recordId'=>'r1','labelSnapshot'=>'Перчатки','quantity'=>10.0]]);
    $this->expectException(AppException::class);
    $act->sign(Commission::fromRows([['fio'=>'Петров']]), '39', new \DateTimeImmutable(), 'scan-1', new \DateTimeImmutable());
}
public function test_signed_act_is_immutable(): void
{
    $act = new WriteOffAct(Uuid::v7(), 'req-1', 'org-1', new \DateTimeImmutable());
    $act->addLinesForProfile('p1', [['recordId'=>'r1','labelSnapshot'=>'Перчатки','quantity'=>10.0]]);
    $act->replacePortions([['recordId'=>'r1','quantity'=>10.0,'reason'=>WriteOffReason::PhysicalWear]]);
    $act->sign(Commission::fromRows([['fio'=>'Петров']]), '39', new \DateTimeImmutable(), 'scan-1', new \DateTimeImmutable());
    $this->expectException(AppException::class);
    $act->addLinesForProfile('p2', [['recordId'=>'r2','labelSnapshot'=>'Каска','quantity'=>1.0]]); // Signed → assertMutable throws
}
```
(Подгони фикстуры/набор кейсов под существующий `ProfileCompliance/WriteOffActTest.php`: неизменяемость подписанного, непустая комиссия, причины на подписи, `quantity>0`.)

- [ ] **Step 2: Прогнать — FAIL** (классов нет).
Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/WriteOffAct/WriteOffActTest.php` → FAIL.

- [ ] **Step 3: Реализовать `WriteOffActLine` и `WriteOffAct`** по интерфейсам выше. Логику `sign`/`assertReasonsComplete`/`assertMutable`/`replacePortions` взять из текущего `ProfileCompliance/WriteOffAct.php:57-165` (адаптировать: строки несут `profileId`/`labelSnapshot`; dedup в `addLinesForProfile`). `WriteOffReason`/`DocumentStatus` — существующие (`app/src/Compliance/Domain/Type/`).

- [ ] **Step 4: Прогнать — PASS.**

- [ ] **Step 5: Commit** — `git add app/src/Compliance/Domain/Aggregate/WriteOffAct app/tests/Unit/Compliance/Domain/Aggregate/WriteOffAct && git commit -m "D5: самостоятельный агрегат WriteOffAct с кросс-персон строками (домен+инварианты)"`

---

### Task 2: ORM + репозиторий + schema-миграция нового агрегата

Самостоятельная персистентность нового агрегата. Таблицы НОВЫЕ (`compliance_writeoff_act` + `compliance_writeoff_line`) — не трогаем старые `compliance_write_off_act`/`_item` до миграции данных (Task 8); аддитивно, старый путь жив → зелёное.

**Files:**
- Create ORM: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/WriteOffAct.WriteOffAct.orm.xml`, `WriteOffAct.WriteOffActLine.orm.xml`
- Create: `app/src/Compliance/Domain/Repository/WriteOffActRepositoryInterface.php`
- Create: `app/src/Compliance/Infrastructure/Repository/WriteOffActRepository.php`
- Create migration: `app/src/Shared/Infrastructure/Database/Migrations/Version20261022120000.php` (CREATE TABLE new tables; номер = следующий свободный после `Version20261020120900`)
- Create DBAL (если нужно для commission на новом агрегате): переиспользовать существующий `compliance_writeoff_commission` тип (WriteOffCommissionType) — он VO-based, годится.
- Test: `app/tests/Functional/Compliance/Infrastructure/Repository/WriteOffActRepositoryTest.php`

**Interfaces (Produces):**
- `WriteOffActRepositoryInterface`: `findOpenDraft(string $requirementId, string $organizationId): ?WriteOffAct`; `findById(string $actId): ?WriteOffAct`; `add(WriteOffAct $act): void`; `remove(WriteOffAct $act): void`; `findDraftsContainingRecord(string $recordId): list<WriteOffAct>`. (`findByFilter` НЕ здесь — его добавляет Task 7 вместе с `WriteOffActFilter`, чтобы не было метода-заглушки.)
- ORM: оба — entity с `version` (optimistic lock) на корне; `WriteOffActLine` — `one-to-many` child, `orphan-removal=true`, cascade persist/remove; корень БЕЗ связи на `ProfileCompliance`; колонки акта: `requirement_id`, `organization_id`, `status`, `commission`(jsonb тип), `act_number`, `act_date`, `order_number`, `order_date`, `representative_position`, `representative_fio`, `scan_file_id`, `signed_at`, `created_at`, `updated_at`, `version`; строки: `profile_id`, `record_id`, `label_snapshot`, `quantity`, `reason`.

- [ ] **Step 1: Failing persistence-тест** — `WriteOffActRepositoryTest`: создать акт с 2 строками (p1/p2), `add`, `clear`, `findById` → 2 строки, `profileIds()==['p1','p2']`; `findOpenDraft(req,org)` находит черновик; подписанный — не находится как draft.
Run → FAIL.

- [ ] **Step 2: Миграция + ORM + репозиторий.** Миграция `up()`: `CREATE TABLE IF NOT EXISTS compliance_writeoff_act (...)` + `compliance_writeoff_line (...)` с индексами (`idx` на organization_id, requirement_id, status; на line — act_id, record_id). `down()`: `DROP TABLE IF EXISTS` обеих. Репозиторий — `ServiceEntityRepository`, методы DQL (findOpenDraft: `WHERE requirementId=:r AND organizationId=:o AND status=:draft`, fetch-join lines). Применить миграции в test_db.

- [ ] **Step 3: Прогнать — PASS. Step 4: `./run check`. Step 5: Commit** — "D5: ORM+репозиторий+таблицы нового агрегата WriteOffAct".

---

### Task 3: Наполнение корзины с карточки выдачи (org-scoped) + `ProfileCompliance::applyWriteOff`

Новый путь «Списать выбранные/всё» → новый агрегат. Старый `addObligationsToWriteOffDraft` на `ProfileCompliance` пока жив (контроллер переключим в Task 6). Аддитивно.

**Files:**
- Modify: `app/src/Compliance/Application/UseCase/Command/AddToWriteOffDraft/AddToWriteOffDraftCommand.php` (+ флаг `all` или отдельная команда «Списать всё»; проще — `obligationKeys: list<string>`, а «всё» контроллер соберёт все ключи; НО спека: «Списать всё» = все инвентарные held>0 — собрать их должен хендлер, т.к. это доменное знание held. Решение: команда получает `?list $obligationKeys` (null = всё инвентарное held>0)).
- Rewrite handler: `AddToWriteOffDraftCommandHandler` — на новый агрегат + `WriteOffActRepository`.
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` — добавить `applyWriteOff(array<string,float> $recordIdToQty): void` (гасит свои факты по recordId, `addReturnedQuantity`); НЕ удалять старые write-off методы (Task 6).
- Test: `app/tests/Functional/Compliance/Application/UseCase/AddToWriteOffDraftTest.php` (переписать на новый агрегат).

**Interfaces:**
- Consumes: Task 1/2 агрегат+репо; `GetProfileQuery` (organizationId); `ProfileCompliance::recordsForRequirement/heldOf/obligationLabelOf`.
- Produces: `ProfileCompliance::applyWriteOff(array $recordIdToQty): void`.
- Handler flow: `canManage`; грузим `ProfileCompliance` + профиль (org); `WriteOffActRepository::findOpenDraft(requirementId, org)` ?: `new WriteOffAct(Uuid::v7(), requirementId, org, now)`; собрать целевые held-факты (по `obligationKeys` или ВСЕ инвентарные held>0 если null); `act.addLinesForProfile(profileId, [{recordId, labelSnapshot=obligationLabelOf(key), quantity=heldAmount}])`; `repo->add(act)`.

- [ ] **Step 1: Failing тест** — два человека одной орг по одному требованию: add каждого → ОДИН черновик (findOpenDraft тот же), 2 профиля в акте, строки с правильными held; «всё» (null keys) берёт все инвентарные held>0. Человек ДРУГОЙ орг → отдельный черновик.
- [ ] **Step 2: FAIL. Step 3: реализовать. Step 4: PASS. Step 5: `./run check`. Commit** — "D5: наполнение кросс-персон корзины с карточки выдачи (org-scoped) + ProfileCompliance.applyWriteOff".

---

### Task 4: Save + Sign (apply-on-sign fan-out)

**Files:**
- Rewrite handlers: `SaveWriteOffActCommandHandler`, `SignWriteOffActCommandHandler` — на новый агрегат + репо; sign — fan-out.
- Modify commands if signature changes (drop `profileId` from act commands — акт больше не на человека; ключ = `writeOffActId`).
- Modify: `app/src/Compliance/Domain/Event/WriteOffActSigned.php` — нести список пар `{profileId, requirementId}` (или оставить один requirementId + список profileIds; требование одно у акта → `requirementId` + `list<string> profileIds`). Обновить `RecomputeOnWriteOffActSignedHandler` на fan-out (цикл по profileIds).
- Test: `app/tests/Functional/Compliance/Application/UseCase/WriteOffFlowTest.php` (переписать: кросс-персон).

**Interfaces/flow:**
- `SignWriteOffActCommandHandler`: `canManage`; `act = repo->findById`; промоут скана (`FileStorage::promote(WriteOffActScan, ownerId=act.getId())`); `act.sign(commission, actNumber, actDate, scanFileId, now)`; `foreach act.linesByProfile() as $pid=>$lines { $pc=repo->findByProfile($pid); $pc->applyWriteOff(recordId→Σqty); pcRepo->add($pc);} `; `writeOffActRepo->add(act)`; emit `WriteOffActSigned(requirementId, profileIds)`; qty≤held проверить перед apply (в `SaveWriteOffActCommandHandler` при replacePortions — грузим затронутые ProfileCompliance, валидируем `qty ≤ heldOf`). Одна транзакция (middleware).
- `SaveWriteOffActCommandHandler`: `act=findById`; `act.replacePortions(lines)`; `act.saveDetails(...)`; валидация `qty ≤ held` по каждому `profileId`/`recordId` (грузим соответствующие `ProfileCompliance`).

- [ ] **Step 1: Failing тест (кросс-персон подпись)** — акт с p1(перчатки 10) + p2(каска 1), причины проставлены, комиссия+скан → sign → у p1 held перчаток 0, у p2 held каски 0 (оба факта погашены); частичное (списать 1 из 10) → held 9. Пересчёт обоих.
- [ ] Steps 2-5: FAIL → реализовать → PASS → `./run check` → commit "D5: подпись акта списания применяется fan-out ко всем людям акта в одной транзакции".

---

### Task 5: Каскад удаления факта (доменное событие) — defensive

`removeIssuanceRecord` сейчас без прод-вызова (нет UI-удаления факта), но при экстракции его локальный detach write-off станет мёртвым/сломанным → заменяем на событие.

**Files:**
- Create: `app/src/Compliance/Domain/Event/FulfillmentRecordRemoved.php` (`{string profileId, string recordId}`).
- Modify: `ProfileCompliance::removeIssuanceRecord` — убрать `detachRecordFromWriteOffActs` (Task 6 удалит метод); вернуть/накопить событие (паттерн как `WriteOffActSigned` — эмит из хендлера; но здесь нет хендлера-вызова. Решение: ввести команду/хендлер удаления факта НЕ в этом деплое — а сейчас: метод `removeIssuanceRecord` возвращает `recordId`, а эмит события делает тот, кто его вызовет. Поскольку вызова нет — создать `FulfillmentRecordRemovedHandler`, который по событию чистит черновики, и ПОКРЫТЬ тестом, дергающим хендлер напрямую).
- Create: `app/src/Compliance/Application/Event/DetachRemovedRecordFromWriteOffsHandler.php` — on `FulfillmentRecordRemoved`: `WriteOffActRepository::findDraftsContainingRecord(recordId)` → `act.removeLinesByRecordIds([recordId])`; пустой черновик → `repo->remove(act)`.
- Test: `app/tests/Functional/Compliance/Application/Event/DetachRemovedRecordFromWriteOffsTest.php`.

- [ ] Steps: failing тест (черновик с линией на recordId → событие → линия снята / пустой акт удалён; подписанный акт не трогается) → реализовать → PASS → `./run check` → commit "D5: каскад удаления факта на write-off через событие FulfillmentRecordRemoved (defensive)".

**Примечание ревью:** путь удаления факта в UI отсутствует — хендлер защитный, покрывается прямым тестом.

---

### Task 6: Снять write-off с `ProfileCompliance` + репойнт контроллеров/роутов/UI

Переключаем все точки на новый агрегат и удаляем старое. Этот таск делает старый путь мёртвым и всё зелёным уже на новом.

**Files:**
- Modify: `ProfileCompliance.php` — удалить `writeOffActs` коллекцию + методы `startOrGetWriteOffDraft/addObligationsToWriteOffDraft/saveWriteOffAct/signWriteOffAct/openWriteOffDraftFor/writeOffActById/itemsOfWriteOffAct/deleteWriteOffDraft/detachRecordFromWriteOffActs/getWriteOffActs`. Оставить `applyWriteOff` (Task 3) + эмит `FulfillmentRecordRemoved` в `removeIssuanceRecord`.
- Modify ORM `ProfileCompliance.ProfileCompliance.orm.xml` — убрать `one-to-many writeOffActs`.
- Delete старые классы `ProfileCompliance/WriteOffAct.php`, `WriteOffItem.php` + их ORM + DBAL если не нужны.
- Modify controllers: `WriteOffAct/ShowAction` (route `/cabinet/compliance/writeoff/{actId}` без profileId; на новый агрегат — см. Task 8 для вёрстки, здесь — переключение resolve на `WriteOffActRepository`), `DownloadAction`, `DeleteAction`, `DownloadScanAction`, `Fulfillment/GoToWriteOffAction`, `Fulfillment/AddToWriteOffAction` — на новые команды/репо; обновить все `path('app_cabinet_compliance_writeoff_*', …)` (убрать profileId где надо).
- Modify `issue.html.twig` — секция списания + «Списать выбранные/всё» ссылки/формы на новые роуты.
- Modify tests: `Unit/.../ProfileCompliance/WriteOffActTest.php`, `WriteOffItemTest.php` — удалить/перенести (заменены Task 1).

- [ ] Steps: прогнать весь `tests/Unit/Compliance tests/Functional/Compliance` → чинить до зелёного; `./run check`; commit "D5: write-off снят с ProfileCompliance, контроллеры/роуты/карточка выдачи — на самостоятельный агрегат".

---

### Task 7: Список актов списания (`findByFilter`, фильтр, кросс-орг)

**Files:**
- Create: `app/src/Compliance/Domain/Repository/WriteOffActFilter.php` (readonly bag-of-fields: `?string organizationId, ?string requirementId, ?DocumentStatus status, ?string actNumber, Pager $pager`).
- Modify: `WriteOffActRepositoryInterface` + `WriteOffActRepository` — добавить `findByFilter(WriteOffActFilter): PaginationResult`.
- Create: `GetPagedWriteOffActsQuery` + `GetPagedWriteOffActsQueryHandler` + `WriteOffActListItemDTO` (id, status, actNumber, actDate, requirementName, organizationTitle, lineCount, profileCount).
- Create: `app/src/Compliance/Infrastructure/Controller/WriteOffAct/ListAction.php` (GET `/cabinet/compliance/writeoff`, тонкий: собрать `WriteOffActFilter` из query → query-хендлер → рендер).
- Create template: `app/src/Shared/Infrastructure/Templates/admin/compliance/writeoff/index.html.twig` — КОПИЯ структуры `admin/compliance/requirements/index.html.twig` (`list_page.html.twig` + `ecard-grid`), карточки → `writeoff_show`; фильтр-панель (организация/требование/статус) в URL.
- Test: `app/tests/Functional/Compliance/Application/UseCase/GetPagedWriteOffActsTest.php` + `app/tests/Functional/Compliance/Infrastructure/Controller/WriteOffActListActionTest.php`.

**Interfaces:** `findByFilter` — DQL по `compliance_writeoff_act` join line (count), фильтры по organizationId/requirementId/status/actNumber, `Pager::fromPage`; join для organizationTitle/requirementName (через denormalized? — организация/требование имена: requirementName можно хранить снимком на акте ИЛИ резолвить query-шиной; решение: резолвить в хендлере батчем, в DTO). Доступ: `canManage()` (кросс-орг — без фильтра по орг по умолчанию).

- [ ] Steps: failing тест (сеем 3 акта двух орг → findByFilter без фильтра = все 3 (админ); фильтр по organizationId = только её; пагинация hasMore) → реализовать (filter+repo+query+DTO+action+template, копируя листинг-аналог) → PASS → `./run check` + `cd app && yarn dev` (если CSS/JS; тут только twig — не нужно) → commit "D5: список актов списания с фильтром, кросс-организационный для админа (findByFilter, как Coatings)".

---

### Task 8: Проектор кросс-персон + data-миграция прод-актов

**Files:**
- Modify: `app/src/Compliance/Application/Service/WriteOffActProjector.php` — на новый агрегат: группы по `profileId` (ФИО батчем), наименование из `line.labelSnapshot`, организация из акта. Отдаёт данные сгруппированно. (.docx-шаблон — заказчик, НЕ в плане; проектор-данные готовим + сохранить обратную совместимость ключей шаблона где возможно.)
- Modify: `app/src/Compliance/Infrastructure/Controller/WriteOffAct/ShowAction.php` + `writeoff/act.html.twig` — страница акта группами по людям (вёрстка как мокап/из текущего шаблона; группы `linesByProfile`).
- Create migration: `app/src/Shared/Infrastructure/Database/Migrations/Version20261022130000.php` — data-миграция: перенос `compliance_write_off_act`/`_item` (старые per-person) → `compliance_writeoff_act`/`_line` (новые): для каждого старого акта — `organization_id` из профиля владельца (`profile_compliance_id`→profileId→org), строки получают `profile_id`=owner, `label_snapshot` из журнала владельца; слить совпавшие по (requirementId, organizationId) ЧЕРНОВИКИ в один; перенести scanFileId (владелец скана → акт). Идемпотентно (пропуск уже перенесённых по id), `down()` no-op. Старые таблицы оставить до подтверждения, дроп — отдельной поздней миграцией.
- Test: `app/tests/Functional/Compliance/Infrastructure/Database/WriteOffCrossPersonMigrationTest.php` — сеем старый per-person акт (raw/через старый путь на отдельном коммите? старого пути уже нет — сеять raw SQL в старые таблицы) → миграция → новый акт с organization_id, строки с profile_id/label_snapshot; два старых черновика одной (req,орг) → один новый.

- [ ] Steps: failing тест миграции → реализовать проектор+страницу+миграцию → PASS → `./run check` → commit "D5: проектор/страница акта группами по людям + прод-миграция 3 актов в кросс-персон (слияние совпавших черновиков)".

---

## Deploy / ops (после мержа)

1. Прод migrate-first: `Version20261022120000` (новые таблицы) + `Version20261022130000` (перенос данных) в гейте ДО свопа.
2. Пост-проверки: `SELECT count(*) FROM compliance_writeoff_act WHERE organization_id IS NULL` = 0; `SELECT count(*) FROM compliance_writeoff_line WHERE profile_id IS NULL OR label_snapshot IS NULL` = 0; число новых актов ≥ число старых-подписанных (черновики могли слиться).
3. После подтверждения — отдельной поздней миграцией дропнуть старые `compliance_write_off_act`/`_item`.
4. .docx-шаблон акта — заказчик обновляет под кросс-персон (проектор уже отдаёт группами).
