# Периодическая позиция (унификация инструктаж+расходник) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Объединить инструктаж и расходник в один тип «периодическая позиция» (количество обязательно, инструктаж=1шт, held=последняя выдача, недовыдача→красный, не списывается), оставив инвентарь (ботинки) отдельным типом без изменений; `MaterialMode` убрать.

**Architecture:** `ComplianceType` получает `Periodic` (= бывш. `NonMaterial` ∪ `Material`/`Consumable`); новый `PeriodicItem` (обязательное количество) рядом с `MaterialItem` (теперь только инвентарь, без mode). Переход — expand/contract: сперва аддитивно вводим `Periodic` и ветвим поведение по типу, затем мигрируем прод-данные jsonb, затем удаляем `NonMaterialItem`/`MaterialMode`. `held` по типу считает проектор (периодическая = последняя выдача; инвентарь = Σ−списания); резолвер ветвит порядок бакета по типу (периодическая — дата первична).

**Tech Stack:** PHP 8.5, Symfony, Doctrine ORM (XML) + DBAL, PostgreSQL jsonb, PHPUnit (unit хост / functional в контейнере), read-модель `compliance_obligation_projection` + `ComplianceObligationProjector` + `ComplianceBucketResolver` (из прошлого деплоя).

**Spec:** `docs/plans/compliance-periodic-item-design.md`

## Global Constraints

- ДВА типа позиции: **Инвентарь** (`ComplianceType::Material`, семантика инвентаря, без mode; held накопительный Σ−списания; списывается) и **Периодическая** (`ComplianceType::Periodic` = инструктаж ∪ расходник; количество ОБЯЗАТЕЛЬНО >0, инструктаж=`{amount:1,unit:'pcs'}`; held=ПОСЛЕДНЯЯ выдача; недовыдача «последняя<норма→красный»; НЕ списывается).
- `Requirement` МОНОМОРФЕН — одно требование целиком инвентарное ИЛИ периодическое (`makeItem` по `Requirement.type`).
- `JournalKind`/`InstructionDetails`/выбор `recordInstruction` vs `recordIssuance` — ОРТОГОНАЛЬНЫ типу позиции, НЕ трогать (периодическое требование может нести `JournalKind`).
- Периодическая: **дата первична** — `nextDueAt<now → Overdue` важнее недовыдачи; в сроке недовыдал → `Missing`. Инвентарь — как сейчас (количество первично).
- Количество инструктажа (1 шт) на экране **ПРЯТАТЬ**; «на руках» периодической = последняя выдача.
- `mode`-колонку проекции оставить **nullable** (перестать писать), физический дроп — поздней миграцией; `MaterialMode` enum в коде удалить.
- Удаление `NonMaterialItem`/`MaterialMode`/consumable-отсева списания — ТОЛЬКО ПОСЛЕ миграции данных (иначе ломается живой расходник).
- Единица инструктажа — `Unit::Piece` (`'pcs'`). Прод НЕ пуст (3 требования), миграции идемпотентны. Гейт конца задачи — полный `./run check` (style+phpstan L6+unit+functional, functional в контейнере, test_db эфемерна — migrate перед прогоном). Коммиты имплементеров разрешены. Мерж/пуш — юзера. Без эмоджи, русские строки, тонкие контроллеры, копировать стиль аналога.

## Review Focus

- **Недовыдача НЕ копится (ключевой кейс):** периодическая, норма 1200; выдал 200 → Missing; доввыдал 1000 (итого в журнале 1200) → ВСЁ РАВНО Missing (held=последняя выдача=1000<1200), пока не выдашь ≥1200 одной выдачей. Пин в Task 2.
- **Инструктаж 1 шт:** прошёл (выдал 1) → не-Missing по количеству, статус по дате; не прошёл (0) → Missing. Пин в Task 2.
- **Срок первичен у периодической:** выдал норму вовремя, срок прошёл, не перевыдал → Overdue (не «Ok, потому что держит норму»). Пин в Task 2.
- **Смешанное material-требование при миграции:** требование `material` с инвентарными И расходными позициями — мономорфная миграция не разрулит; пред-проверка (ожидаем 0) + явный отчёт. Пин в Task 5.
- **Регресс инвентаря:** убрав mode, не сломать инвентарную ветку (накопительный held, списание, недовыдача). Пин в Task 6.

---

## File Structure

**Новое:**
- `app/src/Compliance/Domain/ValueObject/Item/PeriodicItem.php` — позиция с обязательным количеством.
- Миграция `app/src/Shared/Infrastructure/Database/Migrations/Version20261106121000.php` — jsonb requirements.

**Модифицируется:**
- `ComplianceType.php` — +`Periodic`, `makeItem`, `requiresQuantity`; `NonMaterial` удалить (Task 6).
- `MaterialItem.php` — убрать `mode()` (Task 6); `AbstractRequirementItem` — не трогаем.
- `RequirementItemBuilder.php` — сборка `PeriodicItem` из DTO (количество обязательно).
- `ComplianceObligationProjector.php` — held по типу (периодическая=последняя выдача).
- `ComplianceBucketResolver.php` + `ObligationView` + `ObligationProjection` — ветка порядка бакета по типу (периодическая дата-первична); `ObligationView` узнаёт тип.
- `ProfileCompliance.php` — `writeOffEligibleFactsForRequirement`/`consumableKeys` (убрать consumable-отсев, Task 6); `assertIssuable` (периодическая по последней выдаче).
- Форма: `Requirements/EditAction.php` + twig `requirement` форма + `SaveRequirementCommand`/`RequirementItemBuilder` — выбор инвентарь/периодическая, количество обязательно.
- Отображение: `ProfileComplianceDTOTransformer`, `PersonRowDTOTransformer`, `RequirementCardProjector`, `/issue` view (`GetIssuePageQueryHandler`) — прятать количество инструктажа, «на руках» периодической.
- Дашборд DTO (`RequirementGroupDTO`/`RequirementBreakdownDTO`/`PersonRowDTOTransformer`) — тип-фильтр с `Periodic`.
- Удаляется (Task 6): `NonMaterialItem.php`, `MaterialMode.php`.

---

## Task 1: `PeriodicItem` + `ComplianceType::Periodic` + сборка из формы

**Files:**
- Create: `app/src/Compliance/Domain/ValueObject/Item/PeriodicItem.php`
- Modify: `app/src/Compliance/Domain/Type/ComplianceType.php`
- Modify: `app/src/Compliance/Application/Service/RequirementItemBuilder.php`
- Test: `app/tests/Unit/Compliance/Domain/ValueObject/Item/PeriodicItemTest.php`, расширить `ComplianceTypeTest` (если есть) / `RequirementItemBuilderTest`.

**Interfaces:**
- Produces: `final readonly class PeriodicItem extends AbstractRequirementItem` с `quantity(): Quantity` (обязательное, инвариант `>0` в конструкторе), `type(): ComplianceType::Periodic`, `jsonSerialize()`/`fromArray()` (как `MaterialItem`, но БЕЗ mode). `ComplianceType::Periodic` (`'periodic'`, title «Периодическая»); `makeItem(Periodic)→PeriodicItem`; `requiresQuantity()` true для Material И Periodic.

- [ ] **Step 1: Failing unit-тест `PeriodicItem`**
```php
public function test_requires_positive_quantity(): void
{
    $this->expectException(AppException::class);
    new PeriodicItem('i1', 'Инструктаж', Cadence::fromArray(['kind'=>'periodic','number'=>1,'unit'=>'year']), 'п.6', new Quantity(0.0, Unit::Piece));
}
public function test_round_trips_through_array(): void
{
    $item = new PeriodicItem('i1', 'Крем', Cadence::fromArray(['kind'=>'periodic','number'=>1,'unit'=>'year']), 'п.9', new Quantity(1200.0, Unit::Milliliter));
    self::assertEquals($item, PeriodicItem::fromArray($item->jsonSerialize()));
    self::assertSame(ComplianceType::Periodic, $item->type());
}
```
- [ ] **Step 2: Прогнать — FAIL** (`cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/ValueObject/Item/PeriodicItemTest.php`).
- [ ] **Step 3:** Реализовать `PeriodicItem` (количество обязательно>0, инвариант `AppException` при ≤0; jsonSerialize = baseArray + quantity; fromArray). Добавить `ComplianceType::Periodic` + ветку `makeItem` + `requiresQuantity()`→true для Periodic. `RequirementItemBuilder`: ветка Periodic — собрать `PeriodicItem` с обязательным количеством (если тип Periodic и количества нет → `AppException`).
- [ ] **Step 4: Прогнать — PASS**; `./run check`.
- [ ] **Step 5: Commit** — `git commit -m "Compliance периодическая: PeriodicItem + ComplianceType::Periodic + сборка из формы (количество обязательно)"`

---

## Task 2: Проектор (held=последняя выдача) + резолвер (периодическая — дата первична)

**Files:**
- Modify: `app/src/Compliance/Application/Service/ComplianceObligationProjector.php`
- Modify: `app/src/Compliance/Domain/Service/ComplianceBucketResolver.php`, `app/src/Compliance/Domain/ValueObject/ObligationView.php`, `app/src/Compliance/Domain/ReadModel/ObligationProjection.php`
- Test: `app/tests/Unit/Compliance/Domain/Service/ComplianceBucketResolverTest.php` (периодическая-ветка), `app/tests/Functional/Compliance/Application/Service/ComplianceObligationProjectorTest.php` (held=последняя).

**Interfaces:**
- Consumes: `ComplianceType::Periodic` (Task 1).
- Produces: `ObligationView::isPeriodic(): bool` (реализует `ObligationProjection` по своей `type`-колонке; `FakeObligationView` в тестах). Проектор: для периодической строки `held` = количество САМОЙ СВЕЖЕЙ выдачи по ключу (max по `fulfilledAt`), не Σ; инвентарь — Σ−списания как сейчас. Резолвер `bucketFor`: если `isPeriodic()` — сперва дата (`nextDueAt<now→Overdue`, `≤now+60д→Soon`), ПОТОМ недовыдача (`held<норма→Missing`), ни разу не выдан→Missing; инвентарь — текущий порядок (недовыдача первична).

- [ ] **Step 1: Failing unit-тесты резолвера (периодическая)**
```php
// дата первична: держит норму, но срок прошёл → Overdue
$v = FakeObligationView::periodic(active:true, lastFulfilledAt:new \DateTimeImmutable('2025-01-01'), nextDueAt:new \DateTimeImmutable('2026-01-01'), norm:1200.0, held:1200.0);
self::assertSame(ComplianceBucket::Overdue, $r->bucketFor($v, new \DateTimeImmutable('2026-10-20')));
// недовыдача в сроке → Missing
$v2 = FakeObligationView::periodic(true, new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2027-06-01'), 1200.0, 200.0);
self::assertSame(ComplianceBucket::Missing, $r->bucketFor($v2, new \DateTimeImmutable('2026-10-20')));
// инструктаж 1 шт прошёл, в сроке → Ok
$v3 = FakeObligationView::periodic(true, new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2027-06-01'), 1.0, 1.0);
self::assertSame(ComplianceBucket::Ok, $r->bucketFor($v3, new \DateTimeImmutable('2026-10-20')));
```
- [ ] **Step 2: Прогнать — FAIL.**
- [ ] **Step 3:** `ObligationView::isPeriodic()` (+ в `ObligationProjection` по `type`). Резолвер — ветка периодической (дата перед количеством). Проектор — для периодической held = quantity последней выдачи (прочитай, как сейчас считается held/`computeFromFacts`; добавь ветку по типу позиции).
- [ ] **Step 4: Failing functional — доввыдача НЕ копится**
```php
// периодическое требование норма 1200; выдать 200 → Missing; доввыдать 1000 → всё ещё Missing (held=1000)
$ctx = $this->enrollPeriodic(norm: 1200.0, unit: Unit::Milliliter);
$this->issue($ctx, amount: 200.0); $projector->projectProfile($ctx->profileId);
self::assertSame(200.0, $this->row($ctx)->heldQuantity());        // последняя = 200
$this->issue($ctx, amount: 1000.0); $projector->projectProfile($ctx->profileId);
self::assertSame(1000.0, $this->row($ctx)->heldQuantity());       // последняя = 1000, НЕ 1200
```
(адаптируй под реальные методы `EnrollsComplianceTrait`; при нужде заведи периодическое требование в тесте.)
- [ ] **Step 5: Прогнать — PASS**; `./run check`.
- [ ] **Step 6: Commit** — `git commit -m "Compliance периодическая: held=последняя выдача в проекторе + резолвер (периодическая — срок первичен), доввыдача не копится"`

---

## Task 3: Форма требования — инвентарь/периодическая, количество обязательно

**Files:**
- Modify: `app/src/Compliance/Infrastructure/Controller/Requirements/EditAction.php` + twig-форма требования + `app/src/Compliance/Application/UseCase/Command/SaveRequirement/SaveRequirementCommand.php` + `RequirementItemBuilder.php` (валидация количества периодической) + `RequirementDTOTransformer`/`RequirementItemDTO` (отдать тип/количество в форму).
- Test: `app/tests/Functional/Compliance/.../SaveRequirementTest.php` (создать периодическое требование; инструктаж дефолт 1шт), контроллер-тест формы.

**Interfaces:**
- Consumes: `ComplianceType::Periodic`, `PeriodicItem`, `RequirementItemBuilder` (Task 1).
- Produces: форма создаёт требование типа Инвентарь (`material`) или Периодическая (`periodic`); у периодической количество обязательно (инструктаж — дефолт `{1, pcs}`); UI режима Inventoried/Consumable убран.

- [ ] **Step 1: Failing functional** — `SaveRequirementCommand(type: 'periodic', items: [{label, cadence, basis, quantity: {amount:1, unit:'pcs'}}])` создаёт требование с `PeriodicItem`; попытка periodic без количества → `AppException`.
- [ ] **Step 2: Прогнать — FAIL.**
- [ ] **Step 3:** Провести `periodic` через команду/билдер; форма (twig) — выбор типа инвентарь/периодическая, поле количества обязательное у периодической (дефолт 1 шт для инструктажа); убрать выбор режима. Копировать разметку существующей формы требования, новых стилей не вводить.
- [ ] **Step 4: Прогнать — PASS**; `cd app && yarn dev` (трогали twig); `./run check`.
- [ ] **Step 5: Commit** — `git commit -m "Compliance периодическая: форма требования инвентарь/периодическая, количество обязательно, режим убран"`

---

## Task 4: Отображение — прятать количество инструктажа, «на руках» периодической

**Files:**
- Modify: `app/src/Compliance/Application/UseCase/Query/GetIssuePage/GetIssuePageQueryHandler.php`, `ProfileComplianceDTOTransformer.php`, `RequirementCardProjector.php`, соответствующие twig-партиалы `/issue`/карточки.
- Test: расширить `IssueActionTest`/`GetProfileComplianceQueryTest` — у инструктажа (периодическая, 1 шт) количество НЕ показано; у расходной периодической «на руках» = последняя выдача.

**Interfaces:**
- Consumes: `ObligationProjection` (тип, held, quantity-норма).
- Produces: предикат «прятать количество» = периодическая И единица `pcs` И норма `1` (инструктаж); иначе показываем «на руках» = held (последняя выдача).

- [ ] **Step 1: Failing/обновлённый тест** — рендер `/issue`: периодическая-крем показывает «на руках N мл» (последняя выдача); периодическая-инструктаж (1 шт) количество/«на руках» не показывает (только выдано/след.срок).
- [ ] **Step 2: Прогнать — FAIL/адаптировать.**
- [ ] **Step 3:** Ввести предикат hide-quantity (периодическая+pcs+1) в view-builder/DTO; для остальных периодических показывать held. Копировать разметку аналога.
- [ ] **Step 4: Прогнать — PASS**; `yarn dev` если twig; `./run check`.
- [ ] **Step 5: Commit** — `git commit -m "Compliance периодическая: количество инструктажа прячем, «на руках» = последняя выдача"`

---

## Task 4b: `/issue` — ввод количества для периодического расходника (закрыть регрессию)

**Контекст (добавлено по ходу, с заказчиком):** на `/issue` поле ввода «Кол-во»/«Ед.» (`issue.html.twig:199-225`) и в макросе `personal_row` (строка ~23-30) обёрнуто `{% if o.type == 'material' %}` / `{% if requirementType == 'material' %}`. Раньше расходник был `material` → поле было; после смены типа на `periodic` поле пропало → периодический расходник (крем 1200 мл) нельзя выдать с количеством → held=0 → вечно Missing (регрессия). Инструктаж (periodic, 1 шт) ввода количества не требует.

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig` (norm-строки + макрос `personal_row`), `app/src/Compliance/Application/UseCase/Query/GetIssuePage/GetIssuePageQueryHandler.php` / `IssueRowDTO` (+ флаг `showAmountInput`).
- Test: `IssueActionTest` — рендер `/issue` периодического расходника содержит поле `name="items[i][amount]"` + единицу нормы; инструктаж — не содержит; материал — как было.

**Interfaces:**
- Consumes: `ObligationProjection::isInstructionLike()` (Task 4), тип/единица/норма строки проекции.
- Produces: `IssueRowDTO::$showAmountInput` (bool) = `(type===material ИЛИ type===periodic) И НЕ isInstructionLike` (материал и периодический-расходник — да; инструктаж — нет; нематериальное — нет). Единица — фиксирована нормой (disabled select + hidden input), как у материала.

- [ ] **Step 1: Failing functional** — GET `/issue` для периодического расходника (крем, норма 1200 мл): HTML содержит `name="items[0][amount]"` и hidden `items[0][unit]` = норменная единица; для инструктажа (1 шт) — `amount`-инпута НЕТ; материал — без регресса.
- [ ] **Step 2: Прогнать — FAIL.**
- [ ] **Step 3:** Ввести `IssueRowDTO::$showAmountInput` (в ОДНОМ месте — `GetIssuePageQueryHandler`, предикат по типу+isInstructionLike). В twig: блок «Кол-во»/«Ед.» гейтить на `o.showAmountInput` (вместо `o.type=='material'`); «% износа» ОСТАВИТЬ под `o.type=='material'` (у расходника износа нет); единица — существующая ветка «норма задала единицу» (disabled select + hidden `unit`). Тот же гейт в макросе `personal_row` (по строке: `row.showAmountInput`/передать флаг). Инструктажная ветка (`type != 'material' and instructionGroups`) остаётся для periodic-с-журналом.
- [ ] **Step 4: Прогнать — PASS**; `cd app && yarn dev`; `./run check`.
- [ ] **Step 5: Commit** — `git commit -m "Compliance периодическая: на /issue вернули ввод количества для расходника (регрессия после смены типа), единица по норме"`

---

## Task 5: Миграция данных jsonb + пред-проверка смешанных

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20261106121000.php`
- Test: `app/tests/Functional/Compliance/Infrastructure/Database/PeriodicItemMigrationTest.php`

**Interfaces:**
- Consumes: `ComplianceType::Periodic`, `PeriodicItem` (Task 1).
- Produces: прод-требования приведены — `non_material`→`periodic` (+ каждой позиции `quantity={amount:1,unit:'pcs'}`); `material` все-consumable → `periodic` (позиции сохраняют quantity, убрать `mode`); `material` inventoried → остаётся `material` (убрать `mode` у позиций). Идемпотентно (`IF`-guard по уже-периодическим/отсутствию mode), `down()` no-op.

- [ ] **Step 1: Failing functional-тест миграции** — сеем через `EnrollsComplianceTrait`/raw jsonb три требования (инструктаж non_material; расходник material+mode=consumable; инвентарь material+mode=inventoried) → миграция → ассерт: инструктаж `type=periodic` + `quantity {1,pcs}`; расходник `type=periodic` без `mode`; инвентарь `type=material` без `mode`; повторный прогон — no-op.
- [ ] **Step 2: Прогнать — FAIL.**
- [ ] **Step 3:** Миграция (чтение/запись jsonb `compliance_requirement.items`+`type`, шаблон guarded-update как `Version20261020120500`). **Пред-проверка СМЕШАННЫХ:** если `material`-требование содержит И inventoried-, И consumable-позиции — миграция НЕ разруливает мономорфно: логировать/бросить с перечнем id (ожидаем 0 на проде; отчёт юзеру). После миграции кода деплоя — `app:compliance:rebuild-projection` (periodic held=последняя).
- [ ] **Step 4: Прогнать — PASS**; `./run check`.
- [ ] **Step 5: Commit** — `git commit -m "Compliance периодическая: миграция требований non_material/consumable→periodic (+1шт инструктажу), mode убран; пред-проверка смешанных"`

---

## Task 6: Снятие `NonMaterialItem`/`MaterialMode` + consumable-отсева (ПОСЛЕ миграции)

**Files:**
- Delete: `app/src/Compliance/Domain/ValueObject/Item/NonMaterialItem.php`, `app/src/Compliance/Domain/Type/MaterialMode.php`
- Modify: `ComplianceType.php` (убрать `NonMaterial` case + ветку makeItem), `MaterialItem.php` (убрать `mode()`/импорт `MaterialMode`), `ProfileCompliance.php` (`consumableKeys`/`writeOffEligibleFactsForRequirement` — убрать consumable-отсев, списание структурно только `MaterialItem`), проектор (перестать писать `mode` в проекцию), дашборд DTO (тип-фильтр без `NonMaterial`).
- Test: грепом 0 ссылок на `NonMaterialItem`/`MaterialMode`/`NonMaterial`; регресс-тесты инвентаря (списание/накопительный held) зелёные; списание периодической — структурно не попадает.

**Interfaces:**
- Consumes: всё из Task 1-5; данные уже мигрированы (нет `non_material`/`mode=consumable`).
- Produces: код без `NonMaterialItem`/`MaterialMode`; списание — только инвентарь; `mode`-колонка проекции остаётся nullable, перестаёт писаться.

- [ ] **Step 1: Failing/guard-тест** — списание: периодическое требование (бывш. расходник) НЕ попадает в корзину (`writeOffEligibleFactsForRequirement`); инвентарь попадает. Грепом убедиться (в Step 3) 0 `NonMaterial`/`MaterialMode`.
- [ ] **Step 2: Прогнать — FAIL** (пока есть consumable-ветка / классы).
- [ ] **Step 3:** Удалить `NonMaterialItem`/`MaterialMode`; `ComplianceType` без `NonMaterial`; `MaterialItem` без `mode`; `consumableKeys`-отсев убрать (списание = `MaterialItem` структурно); проектор не пишет `mode`; дашборд-фильтр обновить. Грепнуть `NonMaterial`/`MaterialMode`/`mode=` → 0 в `app/src` (кроме исторических миграций/нулябл-колонки).
- [ ] **Step 4: Прогнать — PASS**; `./run check`.
- [ ] **Step 5: Commit** — `git commit -m "Compliance периодическая: NonMaterialItem/MaterialMode сняты, списание структурно только инвентарь (contract)"`

---

## Deploy / ops (после мержа)

1. Migrate-first: `Version20261106121000` (jsonb требований) в гейте ДО свопа.
2. В migrate-first гейте ДО свопа — `app:compliance:rebuild-projection` (periodic held=последняя выдача; норма у инструктажей 1 шт).
3. Пред-проверка смешанных `material`-требований (инвентарь+расходник в одном) — ожидаем 0 на 3 требованиях; если есть — разнести вручную до прогона.
4. `mode`-колонка проекции остаётся nullable; физический дроп — отдельной поздней миграцией.

## Self-review notes

- Покрытие §спеки: §2 типы→T1; §3.1 классы/ComplianceType→T1/T6; §3.2 held/резолвер→T2; §3.3 списание→T6; §3.4 выдача→T2 (assertIssuable периодической)/T3; §3.5 форма→T3; §3.6 отображение/mode-колонка→T4/T6; §4 миграция→T5; §4.5 rebuild→ops. Пробелов нет.
- Порядок green: аддитив (T1-T4: Periodic рядом с NonMaterial/mode) → миграция данных (T5) → снятие старого (T6). Удаление `NonMaterial`/`MaterialMode` только в T6, после T5 — живой расходник не ломается.
- Типы: `ComplianceType::Periodic`/`PeriodicItem`/`requiresQuantity` (T1) → `isPeriodic` (T2) → форма (T3) → миграция (T5) → удаление `NonMaterial`/`MaterialMode` (T6). Согласовано.
- Review Focus: доввыдача-не-копится→T2 step4; инструктаж 1шт→T2 step1; срок-первичен→T2 step1; смешанные→T5; регресс инвентаря→T6.
