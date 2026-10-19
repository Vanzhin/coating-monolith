# Soft-delete позиций требования Compliance — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Удаление позиции из требования СИЗ сделать мягким (всегда): позиция остаётся в jsonb с флагом `removed`; норм-контроль идёт по активным, резолв имени/типа/нормы и списание held — по всем (включая удалённые).

**Architecture:** На `RequirementItem` добавляется флаг `removed` (VO `readonly`, модификация через `withRemoved()`). `Requirement.getItems()` остаётся «все позиции», добавляется `activeItems()` (не-removed). Сохранение формы из replace превращается в merge: отсутствующие существующие позиции помечаются `removed`, не выкидываются. Активные потребители (форма, норма карточки стр.1, проектор норм-обязанностей) переключаются на `activeItems()`; потребители-«по всем» (резолв `obligationSpecOf`, `inventoryKeys` списания, `basisByLabel` факт-строк) остаются на `getItems()` — так held удалённой инвентарной позиции остаётся списываемым, а её имя/норма резолвятся.

**Tech Stack:** PHP 8.5, Symfony, DDD/Hexagonal, Doctrine (jsonb DBAL-тип `compliance_requirement_items`), PHPUnit.

**Spec:** `docs/plans/compliance-soft-delete-items-design.md`

## Global Constraints

- VO позиции — `final readonly`; любая «модификация» возвращает новый инстанс (`withRemoved()`), как `withChild`/`withoutChild`.
- Инварианты — в домене; `AppException` (рус. сообщение) на нарушение. Маппер/билдер — только shape, без бизнес-правил.
- Тесты зеркалят `src/`; у доменных VO — юнит round-trip; у Application-хендлеров — функциональные с реальной БД (в контейнере).
- Ветка: стек поверх `feat/compliance-periodic-item` (зависит от `inventoryKeys`/`carriesJournal`/`PeriodicItem` из него). Не мержена.
- **Миграция данных НЕ нужна:** `fromArray` дефолтит `removed=false`; старый jsonb без ключа читается как не-removed; `removed:true` пишется только при удалении; SQL по `removed` никто не читает.
- Гейт: `./run check` (style + phpstan L6 + unit + functional) зелёный. Functional — в контейнере (`docker compose -f docker-compose.test.yml run --rm test_php-cli ...`), test_db эфемерна → `doctrine:migrations:migrate -n` перед прогоном.

## Review Focus

- Удалённая инвентарная позиция с held>0 должна оставаться write-off-eligible (`writeOffEligibleFactsForRequirement`) — Task 3.
- Карточка/история показывают НАЗВАНИЕ удалённой-но-выданной позиции (не сырой ключ) — Task 3.
- Дашборд/проекция НЕ показывают удалённую позицию как активную обязанность (не краснит) — Task 3.
- Merge-дедуп: новая активная позиция с тем же названием, что у удалённого «надгробия», — разрешена (дедуп только среди активных) — Task 2.
- Back-compat jsonb: старая позиция без ключа `removed` читается как `removed=false` — Task 1.

---

## Task 1: Флаг `removed` на позиции требования

**Files:**
- Modify: `app/src/Compliance/Domain/ValueObject/Item/AbstractRequirementItem.php`
- Modify: `app/src/Compliance/Domain/ValueObject/Item/RequirementItemInterface.php`
- Modify: `app/src/Compliance/Domain/ValueObject/Item/MaterialItem.php`
- Modify: `app/src/Compliance/Domain/ValueObject/Item/PeriodicItem.php`
- Test: `app/tests/Unit/Compliance/Domain/ValueObject/Item/MaterialItemTest.php`, `.../PeriodicItemTest.php` (расширить существующие), `app/tests/Unit/Compliance/Infrastructure/Database/DBAL/RequirementItemsTypeTest.php` (если есть — round-trip removed; иначе добавить кейс в юнит VO).

**Interfaces:**
- Produces: `RequirementItemInterface::isRemoved(): bool`, `RequirementItemInterface::withRemoved(): self`; `AbstractRequirementItem` ctor-параметр `bool $removed = false`; jsonb-ключ `removed` (пишется только при `true`).

- [ ] **Step 1: Failing unit (MaterialItem removed round-trip + withRemoved)**

В `MaterialItemTest`:
```php
public function test_removed_defaults_false_and_absent_from_json(): void
{
    $item = MaterialItem::fromArray(['id' => 'i1', 'label' => 'Каска', 'cadence' => ['kind' => 'one_time'], 'basis' => 'п.1', 'quantity' => ['amount' => 1.0, 'unit' => 'pcs']]);
    self::assertFalse($item->isRemoved());
    self::assertArrayNotHasKey('removed', $item->jsonSerialize());
}

public function test_with_removed_marks_removed_and_round_trips(): void
{
    $item = MaterialItem::fromArray(['id' => 'i1', 'label' => 'Каска', 'cadence' => ['kind' => 'one_time'], 'basis' => 'п.1', 'quantity' => ['amount' => 1.0, 'unit' => 'pcs']]);
    $removed = $item->withRemoved();
    self::assertTrue($removed->isRemoved());
    self::assertSame('Каска', $removed->label());
    self::assertSame(1.0, $removed->quantity()->amount);
    self::assertTrue($removed->jsonSerialize()['removed']);
    self::assertTrue(MaterialItem::fromArray($removed->jsonSerialize())->isRemoved());
}
```

- [ ] **Step 2: Прогнать — FAIL** (`isRemoved`/`withRemoved` не существуют).

Run (unit на хосте): `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/ValueObject/Item/MaterialItemTest.php`
Expected: FAIL (method not defined).

- [ ] **Step 3: Реализация.**

`RequirementItemInterface` — добавить в интерфейс:
```php
    public function isRemoved(): bool;

    public function withRemoved(): self;
```

`AbstractRequirementItem` — поле + ctor-параметр + предикат + сериализация + ридер. Текущий ctor:
```php
    public function __construct(string $id, string $label, private Cadence $cadence, string $basis)
```
→
```php
    public function __construct(string $id, string $label, private Cadence $cadence, string $basis, private bool $removed = false)
```
Добавить метод-предикат (рядом с `basis()`):
```php
    public function isRemoved(): bool
    {
        return $this->removed;
    }
```
`baseArray()` — дописать `removed` ТОЛЬКО если true (чтобы не раздувать jsonb и не ломать round-trip старых записей):
```php
    protected function baseArray(): array
    {
        $base = [
            'id' => $this->id,
            'label' => $this->label,
            'cadence' => $this->cadence->jsonSerialize(),
            'basis' => $this->basis,
        ];
        if ($this->removed) {
            $base['removed'] = true;
        }

        return $base;
    }
```
Добавить ридер (рядом с `readId`):
```php
    /** @param array<string, mixed> $data */
    protected static function readRemoved(array $data): bool
    {
        return true === ($data['removed'] ?? false);
    }
```
Обнови PHPDoc `baseArray` (`@return`) — добавить `removed?: true`.

`MaterialItem` — ctor пробрасывает removed, fromArray читает, + `withRemoved()`:
```php
    public function __construct(
        string $id,
        string $label,
        Cadence $cadence,
        string $basis,
        private Quantity $quantity,
        bool $removed = false,
    ) {
        parent::__construct($id, $label, $cadence, $basis, $removed);
    }
```
```php
    public function withRemoved(): self
    {
        return new self($this->id(), $this->label(), $this->cadence(), $this->basis(), $this->quantity, true);
    }
```
fromArray — последним аргументом `self::readRemoved($data)`:
```php
        return new self(
            self::readId($data),
            (string) ($data['label'] ?? ''),
            Cadence::fromArray(is_array($data['cadence'] ?? null) ? $data['cadence'] : []),
            (string) ($data['basis'] ?? ''),
            Quantity::fromArray(is_array($data['quantity'] ?? null) ? $data['quantity'] : []),
            self::readRemoved($data),
        );
```

`PeriodicItem` — ЗЕРКАЛЬНО (тот же ctor-параметр `bool $removed = false` → parent; `withRemoved()`; `fromArray` + `self::readRemoved($data)`). Код идентичен MaterialItem выше (замени `self`-тип — он `PeriodicItem`).

- [ ] **Step 4: Прогнать — PASS**; добавить зеркальные тесты в `PeriodicItemTest` (те же два кейса). Если есть `RequirementItemsTypeTest` — добавить кейс: массив с `removed:true` → `convertToDatabaseValue` → `convertToPHPValue` → `isRemoved()===true` (дискриминатор `type` + `removed` переживают round-trip). `cd app && vendor/bin/phpunit tests/Unit/Compliance` зелёный.

- [ ] **Step 5: Commit**
```bash
git add app/src/Compliance/Domain/ValueObject/Item app/tests/Unit/Compliance
git commit -m "Compliance soft-delete: флаг removed на позиции требования (withRemoved, round-trip, back-compat)"
```

---

## Task 2: `Requirement.activeItems()` + merge-сохранение (мягкое удаление отсутствующих)

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/Requirement/Requirement.php`
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/Requirement/RequirementTest.php`

**Interfaces:**
- Consumes: `RequirementItemInterface::isRemoved()`/`withRemoved()` (Task 1).
- Produces: `Requirement::activeItems(): RequirementItemInterface[]` (не-removed); `Requirement::mergeItems(RequirementItemInterface ...$submitted): void` (замена `replaceItems`). `getItems()` остаётся = ВСЕ позиции.

- [ ] **Step 1: Failing unit (merge soft-removes absent, keeps tombstone, activeItems excludes it)**

В `RequirementTest` (материальное требование «Выдача» принимает mixed — используй существующие хелперы создания item'ов этого теста):
```php
public function test_merge_soft_removes_absent_item_and_active_excludes_it(): void
{
    $gloves = $this->materialItem('g1', 'Перчатки');
    $boots  = $this->materialItem('b1', 'Ботинки');
    $req = new Requirement(Uuid::v7(), 'Карточка', ComplianceType::Material, new StringCollection('pos1'), $gloves, $boots);

    // пере-сохранение формы прислало только перчатки → ботинки должны стать removed, не исчезнуть
    $req->mergeItems($this->materialItem('g1', 'Перчатки'));

    self::assertCount(2, $req->getItems());                       // надгробие сохранено
    self::assertCount(1, $req->activeItems());                    // активна одна
    self::assertSame('Перчатки', $req->activeItems()[0]->label());
    $bootsNow = array_values(array_filter($req->getItems(), static fn ($i) => 'b1' === $i->id()))[0];
    self::assertTrue($bootsNow->isRemoved());
}

public function test_merge_allows_new_active_item_with_same_label_as_removed_tombstone(): void
{
    $socks = $this->materialItem('s1', 'Носки');
    $req = new Requirement(Uuid::v7(), 'Карточка', ComplianceType::Material, new StringCollection('pos1'), $socks);
    $req->mergeItems(); // убрали носки → надгробие
    $req->mergeItems($this->materialItem('s2', 'Носки')); // новая активная с тем же названием — дедуп не падает
    self::assertCount(1, $req->activeItems());
    self::assertSame('Носки', $req->activeItems()[0]->label());
}
```
(Если в `RequirementTest` нет хелпера `materialItem($id,$label)` — добавь приватный: `MaterialItem::fromArray(['id'=>$id,'label'=>$label,'cadence'=>['kind'=>'one_time'],'basis'=>'п.1','quantity'=>['amount'=>1.0,'unit'=>'pcs']])`.)

- [ ] **Step 2: Прогнать — FAIL** (`mergeItems`/`activeItems` нет). `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/Requirement/RequirementTest.php`

- [ ] **Step 3: Реализация.**

`activeItems()` (рядом с `getItems()`):
```php
    /** Активные позиции (не удалённые) — норм-контроль. @return RequirementItemInterface[] */
    public function activeItems(): array
    {
        return array_values(array_filter($this->items, static fn (RequirementItemInterface $i): bool => !$i->isRemoved()));
    }
```
Заменить `replaceItems` на `mergeItems` (merge + soft-remove отсутствующих; существующие надгробия сохраняем):
```php
    /**
     * Сохранение состава из формы: присланные позиции обновляют/добавляют; существующие, которых в форме нет,
     * помечаются removed (мягкое удаление — не выкидываем, held по ним списывается, имя резолвится). Дедуп
     * по названию и проверка типа — среди присланных (активных), через {@see accepted()}.
     */
    public function mergeItems(RequirementItemInterface ...$submitted): void
    {
        $accepted = $this->accepted($submitted);
        $submittedById = [];
        foreach ($accepted as $item) {
            $submittedById[$item->id()] = $item;
        }

        $merged = [];
        $seenIds = [];
        foreach ($this->items as $existing) {
            $seenIds[$existing->id()] = true;
            if (isset($submittedById[$existing->id()])) {
                $merged[] = $submittedById[$existing->id()];       // обновлённая версия
            } elseif ($existing->isRemoved()) {
                $merged[] = $existing;                             // уже надгробие — как есть
            } else {
                $merged[] = $existing->withRemoved();              // мягко удаляем
            }
        }
        foreach ($accepted as $item) {
            if (!isset($seenIds[$item->id()])) {
                $merged[] = $item;                                 // новая позиция
            }
        }
        $this->items = $merged;
    }
```
`accepted()`/`supports()` — НЕ трогаем (валидируют присланные активные; надгробий среди `$submitted` нет, значит дедуп по названию — только активные). `getItems()` — без изменений (все). Удали старый `replaceItems` (его единственный вызов переедет на `mergeItems` в Task 3).

- [ ] **Step 4: Прогнать — PASS**. `cd app && vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/Requirement/RequirementTest.php`

- [ ] **Step 5: Commit**
```bash
git add app/src/Compliance/Domain/Aggregate/Requirement/Requirement.php app/tests/Unit/Compliance/Domain/Aggregate/Requirement/RequirementTest.php
git commit -m "Compliance soft-delete: Requirement.activeItems() + merge-сохранение (отсутствующие позиции мягко удаляются)"
```

---

## Task 3: Развод потребителей (активные vs все) + поведение end-to-end

**Files:**
- Modify: `app/src/Compliance/Application/UseCase/Command/SaveRequirement/SaveRequirementCommandHandler.php:69` (`replaceItems` → `mergeItems`)
- Modify: `app/src/Compliance/Application/DTO/Requirement/RequirementDTOTransformer.php:29` (`getItems()` → `activeItems()`)
- Modify: `app/src/Compliance/Application/Service/ComplianceObligationProjector.php:136` (`getItems()` → `activeItems()`)
- Modify: `app/src/Compliance/Application/Service/RequirementCardProjector.php:94` (`normItems`, `getItems()` → `activeItems()`)
- Test: `app/tests/Functional/Compliance/...` — SaveRequirement round-trip (soft-remove), дашборд/проекция без removed, карточка-норма без removed, held удалённого инвентаря списывается + резолвится имя.

**Interfaces:**
- Consumes: `Requirement::activeItems()`/`mergeItems()` (Task 2).
- НЕ трогаем (остаются на `getItems()` = все): `RequirementCardProjector:125` (`basisByLabel` факт-строк), `ProfileCompliance:159` (`inventoryKeys` списания), `ProfileCompliance:187` (`obligationSpecOf` резолв). Именно они дают: имя/тип/норму удалённой позиции в факт-логе и write-off-eligibility её held.

- [ ] **Step 1: Failing functional — удалённая-но-выданная позиция: списываема + названа, не контролируется.**

В новый/существующий functional-тест (рядом с `IssueActionTest`/`GetProfileComplianceQueryTest`, через `EnrollsComplianceTrait` + `AuthenticatesActorTrait`):
```php
public function test_removed_but_issued_material_item_stays_writeoffable_named_and_uncontrolled(): void
{
    // норма из двух материальных позиций; обе выданы человеку
    ['profileId' => $p, 'requirementId' => $r] = $this->enrollWithTwoMaterialItems('Перчатки', 'Ботинки'); // хелпер теста: 2 MaterialItem, карта заведена, обе выданы
    $glovesKey = $this->obligationKeyByLabel($r, 'Перчатки');

    // убрали «Перчатки» из нормы (пере-сохранили требование без них) → мягкое удаление
    $this->saveRequirementWithoutItem($r, 'Перчатки');

    // 1) held перчаток всё ещё списываем (eligible по всем Material, не по активной норме)
    $compliance = $this->profileComplianceRepository->findByProfile($p);
    $requirement = $this->requirementRepository->findOneById($r);
    $eligible = $compliance->writeOffEligibleFactsForRequirement($requirement, null);
    self::assertNotEmpty(array_filter($eligible, static fn ($f) => $f->obligationKey() === $glovesKey), 'held удалённой инвентарной позиции остаётся списываемым');

    // 2) имя резолвится (не сырой ключ)
    self::assertSame('Перчатки', $compliance->obligationLabelOf($requirement, $glovesKey));

    // 3) дашборд/проекция не держат её активной обязанностью
    $this->rebuildProjection($p, $r);
    $active = array_filter($this->projectionRepository->findForProfiles(new StringCollection($p)), static fn ($o) => $o->requirementId() === $r && $o->label() === 'Перчатки' && $o->isActive());
    self::assertEmpty($active, 'удалённая позиция не активная обязанность');
}
```
(Имена хелперов — под инфраструктуру теста; суть: завести норму из 2 материальных, выдать обе, пере-сохранить требование без одной, проверить три инварианта.)

- [ ] **Step 2: Прогнать — FAIL** (пока `SaveRequirement` делает replace/hard-remove — позиция исчезает из `getItems()`, eligible/label рушатся). В контейнере: `docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate -n` затем `… vendor/bin/phpunit <path>`.

- [ ] **Step 3: Реализация — 4 точечные замены.**

`SaveRequirementCommandHandler.php:69`:
```php
            $requirement->replaceItems(...$items);
```
→
```php
            $requirement->mergeItems(...$items);
```
`RequirementDTOTransformer.php:29`:
```php
        $dto->items = array_map($this->itemDto(...), $requirement->getItems());
```
→
```php
        $dto->items = array_map($this->itemDto(...), $requirement->activeItems());
```
`ComplianceObligationProjector.php:136` (цикл норм-строк):
```php
            foreach ($requirement->getItems() as $item) {
```
→
```php
            foreach ($requirement->activeItems() as $item) {
```
`RequirementCardProjector.php:94` (`normItems`, стр.1):
```php
        foreach ($requirement->getItems() as $item) {
```
→
```php
        foreach ($requirement->activeItems() as $item) {
```
(`RequirementCardProjector:125` `basisByLabel`, `ProfileCompliance:159` `inventoryKeys`, `ProfileCompliance:187` `obligationSpecOf` — ОСТАВИТЬ `getItems()`. Это даёт имя/тип/норму и списываемость удалённой позиции.)

- [ ] **Step 4: Прогнать — PASS**; `cd app && yarn dev` (если какой-то twig затронут — здесь не трогаем, но форма рендерит активные автоматически через DTO); полный `./run check` зелёный.

- [ ] **Step 5: Commit**
```bash
git add app/src/Compliance app/tests/Functional/Compliance
git commit -m "Compliance soft-delete: норм-контроль по активным (форма/проектор/карточка-норма), held и имя удалённой позиции — по всем (резолв+списание)"
```

---

## Deploy / ops

Миграции данных НЕТ (back-compat: отсутствие `removed` = false). Прод-деплой этого слоя дополнительных шагов не требует сверх общих (migrate кода + rebuild-projection по общим правилам контекста). Стек поверх `feat/compliance-periodic-item` — мержится после него.

## Self-review notes

- Покрытие спеки: §Модель → T1 (removed VO) + T2 (activeItems/merge); §Развод потребителей → T3 (3 активных переключены, 3 «по всем» оставлены); §Что чинится (имя-в-карточке, eligible held) → T3 functional; §Миграция → не нужна (обоснование в Global Constraints). Рулинги 1-5: (1) карточка стр.1 активные — T3; (2) форма активные — T3; (3) жёстко-удалённые не воскрешаем — вне кода (нет шага); (4) миграция — не нужна; (5) всегда soft — T2 (`mergeItems` всегда помечает, без ветки held>0).
- Типы согласованы: `removed:bool`, `isRemoved():bool`, `withRemoved():self`, `activeItems():array`, `mergeItems(...$submitted)` — одинаково в T1→T2→T3.
- Review Focus: eligible-held→T3 step1; имя-не-ключ→T3 step1; дашборд-не-краснит→T3 step1; дедуп-с-надгробием→T2 step1; back-compat jsonb→T1 step1.
- Открытый вопрос из спеки «проекция держит removed+held active=false» — СНЯТ: списание берёт eligible из агрегата (`writeOffEligibleFactsForRequirement`), не из проекции, поэтому строка проекции для removed не нужна; удалённая позиция просто не попадает в активные норм-строки.
