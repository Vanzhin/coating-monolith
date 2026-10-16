# Rekey label→itemId — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перевести идентичность обязанности в журнале фактов и проекции Compliance со строкового `requirementId|lower(label)` на стабильный `requirementId|itemId` (`RequirementItem::id`), чтобы переименование позиции не рвало связь факта с нормой.

**Architecture:** Ключ остаётся композитной строкой `requirementId|<suffix>`; меняется только суффикс: `lower(label)` → `itemId`. Норма берёт `itemId` из `RequirementItem::id()`; персональная позиция получает собственный стабильный `itemId` (дедуп по label при добавлении). Вся префикс-машинерия (`keyOf`, `keyBelongsToRequirement`, `requirementIdOf`) и списание (на `recordId`) не меняются. Прод-данные мигрируются отдельной data-миграцией; схема — отдельной (паттерн D1: Version…120000 схема, Version…120500 бэкфилл).

**Tech Stack:** PHP 8.5, Symfony, Doctrine ORM (XML mapping + JSON DBAL types), PHPUnit 11, функциональные тесты в контейнере `test_php-cli`.

**Spec:** `docs/plans/compliance-rekey-itemid-design.md`

## Global Constraints

- Ключ обязанности — композит `requirementId|<suffix>`; `requirementId` — uuid (символа `|` не содержит), остаётся префиксом до первого `|`. Не ломать `TrackedObligation::requirementIdOf/keyBelongsToRequirement`.
- Инварианты — в домене; маппер/хендлер только оркеструют (CLAUDE.md).
- Генерация id — в хендлере/мейкере, не в домене: доменные методы принимают кандидат-id (как `startOrGetWriteOffDraft(Uuid $candidateActId, …)`).
- Миграции идемпотентны (`ADD COLUMN IF NOT EXISTS`, guarded update, пропуск уже-мигрированного), `down()` — no-op.
- Списание не трогаем (завязано на `recordId`).
- Один деплой, migrate-first. Прод НЕ пуст (≈126 фактов / 3 требования / персональных ≈0).
- Тесты: функциональные гоняются в контейнере; при пустой test_db сперва `docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate -n`.
- `./run check` (style + phpstan level 6 + unit + functional) зелёный к концу каждой задачи.

## Review Focus

- **Наименование с `/`/пробелами/кириллицей** → ключ теперь uuid, слэш в ключе исчезает; страница и история не 500. (Task 1 — существующие `/`-регресс-тесты остаются зелёными; Task 3 — явная проверка.)
- **Переименование позиции нормы (тот же `item.id`, новый label)** → факт/held остаются на той же обязанности. (Task 3 — новый тест.)
- **Позиция убрана из нормы** → её факт становится orphan (held/история/списание целы, вне контроля), ключ при бэкфилле остаётся label-форматным. (Task 2 — тест бэкфилла с неудаляемым orphan.)
- **Повторное добавление персональной позиции с тем же label** → та же обязанность (дедуп по label), один itemId. (Task 1 — тест дедупа.)
- **Повторный прогон миграции** (идемпотентность) → ключи не перекашиваются, факты не перекидываются повторно. (Task 2 — тест двойного прогона.)

---

### Task 1: Доменный rekey на `itemId` (+ schema-миграция `item_id`)

Атомарно: `TrackedObligation` получает `itemId`, `key()` переключается на него; ребилдер передаёт `item.id()`; персональные получают стабильный `itemId` с дедупом по label; `EnrollsComplianceTrait` и связанные тесты переводятся на itemId-ключи. Схема-миграция добавляет колонку. Зелёный достигается тем, что меняем ВСЕ стороны ключа разом.

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligation.php`
- Modify: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.TrackedObligation.orm.xml`
- Modify: `app/src/Compliance/Application/Service/ComplianceProjectionRebuilder.php:116-128`
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` (`addPersonalObligation` ~523-546)
- Modify: `app/src/Compliance/Application/UseCase/Command/RecordIssuance/RecordIssuanceCommandHandler.php` (`materializePersonalItems` ~74-95, убрать `hasObligation` если осиротел)
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20261020120800.php` (ADD COLUMN)
- Modify: `app/tests/Support/EnrollsComplianceTrait.php:60-86`
- Modify (tests на формат ключа): `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligationTest.php`, `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php`, `app/tests/Functional/Compliance/Application/UseCase/RecordIssuanceTest.php`, `app/tests/Functional/Compliance/Application/UseCase/InstructionIssuanceTest.php`, `app/tests/Functional/Compliance/Application/UseCase/GetObligationHistoryQueryTest.php`, `app/tests/Functional/Compliance/Infrastructure/Repository/ProfileCompliancePersistenceTest.php`, `app/tests/Functional/Compliance/Infrastructure/Controller/CardDownloadControllerTest.php`

**Interfaces:**
- Consumes: `RequirementItemInterface::id(): string`, `::label(): string` (D1, стабильный id в jsonb).
- Produces:
  - `TrackedObligation::__construct(Uuid $id, ProfileCompliance $pc, string $requirementId, string $itemId, string $requirementName, string $label, ComplianceType $type, Cadence $cadence, ?Quantity $quantity, string $departmentId, string $origin = self::ORIGIN_NORM, ?MaterialMode $mode = null)` — добавлен `string $itemId` после `$requirementId`.
  - `TrackedObligation::itemId(): string`.
  - `TrackedObligation::key(): string` → `keyOf($this->requirementId, $this->itemId)`.
  - `ProfileCompliance::addPersonalObligation(Uuid $candidateId, string $candidateItemId, string $requirementId, string $label, Cadence $cadence, ?Quantity $quantity): string` — дедуп по `lower(label)` среди персональных требования: нашли → вернуть её ключ (её itemId), кандидаты проигнорировать; иначе создать с кандидатами. Возвращает `keyOf(reqId, itemId)`.
  - `EnrollsComplianceTrait::enrollCompliance(...)` возвращает `key` = `keyOf(reqId, itemIdПозиции)`.

- [ ] **Step 1: Unit-тест itemId и key() в TrackedObligation**

В `TrackedObligationTest.php` заменить старые кейсы keyOf/key на itemId-основу:
```php
public function test_key_is_requirement_id_plus_item_id(): void
{
    $o = $this->obligation(requirementId: 'req-42', itemId: 'item-7', label: 'Перчатки');
    self::assertSame('req-42|item-7', $o->key());
    self::assertSame('item-7', $o->itemId());
}

public function test_requirement_id_is_prefix_before_pipe(): void
{
    self::assertSame('req-42', TrackedObligation::requirementIdOf('req-42|item-7'));
}

public function test_requirement_id_of_throws_without_separator(): void
{
    $this->expectException(\InvalidArgumentException::class);
    TrackedObligation::requirementIdOf('no-separator');
}
```
Фабрика в тесте (обнови под новый конструктор):
```php
private function obligation(string $requirementId = 'req-1', string $itemId = 'item-1', string $label = 'Перчатки'): TrackedObligation
{
    return new TrackedObligation(
        Uuid::v7(), $this->pc(), $requirementId, $itemId, 'Норма', $label,
        ComplianceType::Material, new Cadence(CadenceKind::Periodic, 1, CadenceUnit::Year),
        null, 'dep-1',
    );
}
```
(Если прежний тест строил `TrackedObligation` без `pc()` — используй существующий способ создания `ProfileCompliance`, что и в текущем тесте; важно лишь добавить `itemId` вторым строковым аргументом после requirementId.)

- [ ] **Step 2: Прогнать — падает (нет itemId в конструкторе/геттере, key() по label)**

Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligationTest.php`
Expected: FAIL (unknown arg `$itemId` / no method `itemId()` / key() возвращает `req-42|перчатки`).

- [ ] **Step 3: TrackedObligation — поле, конструктор, геттер, key()**

Конструктор — добавить `string $itemId` после `$requirementId`, присвоить полю; добавить `private string $itemId;` в список полей; геттер; `key()`:
```php
private string $itemId;
// ...в конструкторе после $this->requirementId = $requirementId;
$this->itemId = $itemId;

public function itemId(): string
{
    return $this->itemId;
}

public function key(): string
{
    return self::keyOf($this->requirementId, $this->itemId);
}
```
PHPDoc класса: поправить «Идентичность строки — `key()` = `requirementId|label`» → «`requirementId|itemId` (стабильный id позиции; переименование не рвёт связь факта)».

- [ ] **Step 4: ORM — колонка item_id**

В `ProfileCompliance.TrackedObligation.orm.xml` после поля `requirement_id` добавить:
```xml
<field name="itemId" type="string" length="64" column="item_id"/>
```

- [ ] **Step 5: Schema-миграция — ADD COLUMN item_id**

Create `Version20261020120800.php` (номер = следующий за `Version20261020120700`):
```php
<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Database\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Rekey-деплой, схема: колонка item_id на проекции обязанностей (значение проставит data-миграция/ребилдер). */
final class Version20261020120800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'compliance_tracked_obligation.item_id (rekey label→itemId, схема)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation ADD COLUMN IF NOT EXISTS item_id VARCHAR(64)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation DROP COLUMN IF EXISTS item_id');
    }
}
```
(Колонка nullable на уровне БД — чтобы `ADD COLUMN` на непустом проде прошёл; значение проставит data-миграция Task 2 для существующих строк. ORM-маппинг — обычный non-null, т.к. весь код создаёт obligation только с itemId, а прод-строки заполнит Task 2 до свопа.)

- [ ] **Step 6: Прогнать миграции в test_db + unit-тест TrackedObligation — зелёный**

Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate -n`
Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/TrackedObligationTest.php`
Expected: PASS.

- [ ] **Step 7: Ребилдер — передать item.id()**

`ComplianceProjectionRebuilder.php` строки 116-128:
```php
foreach ($requirement->getItems() as $item) {
    $key = TrackedObligation::keyOf($requirement->getId(), $item->id());
    if (in_array($key, $excluded, true)) {
        continue;
    }
    $desired[$key] = true;
    $obligation = new TrackedObligation(
        Uuid::v7(), $profileCompliance, $requirement->getId(), $item->id(), $requirement->getName(),
        $item->label(), $item->type(), $item->cadence(),
        $item instanceof MaterialItem ? $item->quantity() : null,
        $departmentId, TrackedObligation::ORIGIN_NORM,
        $item instanceof MaterialItem ? $item->mode() : null,
    );
    $profileCompliance->putObligation($obligation);
}
```

- [ ] **Step 8: addPersonalObligation — стабильный itemId + дедуп по label**

`ProfileCompliance.php` `addPersonalObligation` переписать:
```php
public function addPersonalObligation(Uuid $candidateId, string $candidateItemId, string $requirementId, string $label, Cadence $cadence, ?Quantity $quantity): string
{
    $normalizedLabel = mb_strtolower(trim($label));
    $reference = null;
    foreach ($this->obligations as $obligation) {
        if (!TrackedObligation::keyBelongsToRequirement($obligation->key(), $requirementId)) {
            continue;
        }
        $reference ??= $obligation;
        // дедуп по label: та же персональная позиция уже есть → переиспользуем её itemId
        if (TrackedObligation::ORIGIN_PERSONAL === $obligation->origin() && mb_strtolower(trim($obligation->label())) === $normalizedLabel) {
            return $obligation->key();
        }
    }
    if (null === $reference) {
        throw new AppException('Нельзя добавить позицию в карточку без нормы по требованию.');
    }

    $this->putObligation(new TrackedObligation(
        $candidateId, $this, $requirementId, $candidateItemId, $reference->requirementName(),
        trim($label), $reference->type(), $cadence, $quantity, $reference->departmentId(),
        TrackedObligation::ORIGIN_PERSONAL,
    ));

    return TrackedObligation::keyOf($requirementId, $candidateItemId);
}
```

- [ ] **Step 9: materializePersonalItems — передать кандидат itemId, брать ключ из домена**

`RecordIssuanceCommandHandler.php` в `materializePersonalItems` заменить блок построения ключа/обязанности:
```php
$quantity = $this->mapper->quantityOf($row);
$due = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($row['manualDueDate'] ?? ''))) ?: null;
$key = $profileCompliance->addPersonalObligation(
    Uuid::v7(), Uuid::v7()->toRfc4122(), $command->requirementId, $label, new Cadence(CadenceKind::ByManufacturerDoc), $quantity,
);
$lines[] = new IssuanceLine(Uuid::v7(), $key, $documentDate, $quantity, null, $due, $this->mapper->note($row), $this->mapper->instructionDetails($row));
```
Удалить приватный `hasObligation()` и его вызов (дедуп теперь в домене). Убрать неиспользованные импорты (`TrackedObligation`, если больше не нужен в хендлере — проверить; `keyOf` там больше не зовём).

- [ ] **Step 10: EnrollsComplianceTrait — ключ через itemId позиции**

`EnrollsComplianceTrait.php`: после `SaveRequirementCommand` резолвить itemId по label и вернуть itemId-ключ. Добавить импорт `use App\Compliance\Domain\Repository\RequirementRepositoryInterface;`. В конце `enrollCompliance`:
```php
$requirement = static::getContainer()->get(RequirementRepositoryInterface::class)->findOneById($req->id);
\assert(null !== $requirement);
$itemId = '';
foreach ($requirement->getItems() as $item) {
    if ($item->label() === $itemLabel) {
        $itemId = $item->id();
        break;
    }
}
\assert('' !== $itemId);

return ['profileId' => $profile->id, 'requirementId' => $req->id, 'key' => TrackedObligation::keyOf($req->id, $itemId)];
```
`issueCard()` не меняется — он уже постит `['obligationKey' => $key, ...]`, где `$key` теперь itemId-формат.

- [ ] **Step 11: Перевести связанные тесты на itemId-ключи**

Везде, где тест конструирует ключ как `keyOf($reqId, 'label')` или строит `TrackedObligation`, перевести на itemId:
- `ProfileComplianceTest.php` — заменить хелперы ключей (`glovesKey()` и т.п.) на `keyOf($reqId, $itemId)` с явными itemId-константами в тесте; конструкторы `TrackedObligation` — добавить itemId-аргумент. Дедуп-кейс «по lower(label)» для персональных — проверять, что повторное `addPersonalObligation` с тем же label возвращает ТОТ ЖЕ ключ:
```php
public function test_personal_obligation_dedup_by_label_returns_same_key(): void
{
    $pc = $this->pcWithNorm($reqId); // существующий способ подготовки нормы в этом тесте
    $k1 = $pc->addPersonalObligation(Uuid::v7(), Uuid::v7()->toRfc4122(), $reqId, 'Фартук', $this->cadence(), null);
    $k2 = $pc->addPersonalObligation(Uuid::v7(), Uuid::v7()->toRfc4122(), $reqId, 'ФАРТУК', $this->cadence(), null);
    self::assertSame($k1, $k2, 'повторная персональная с тем же label (регистронезависимо) — та же обязанность');
}
```
- `RecordIssuanceTest.php`, `InstructionIssuanceTest.php`, `GetObligationHistoryQueryTest.php`, `ProfileCompliancePersistenceTest.php`, `CardDownloadControllerTest.php` — там, где строят `obligationKey` вручную через `keyOf($reqId, 'label')`, использовать ключ из `enrollCompliance()['key']` (он уже itemId-формат) либо резолвить itemId тем же приёмом. Для нематериального кейса в `RecordIssuanceTest`/`InstructionIssuanceTest`, где label не из enroll — резолвить itemId из требования по label.

- [ ] **Step 12: Прогнать весь Compliance — зелёный**

Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Unit/Compliance tests/Functional/Compliance`
Expected: PASS. Если падает `ProfileCompliancePersistenceTest` на длине колонки — не меняем (ключ теперь короче). 

- [ ] **Step 13: ./run check + commit**

Run: `./run check` (при необходимости `./run check style:fix`).
```bash
git add app/src/Compliance app/src/Shared/Infrastructure/Database/Migrations/Version20261020120800.php app/tests/Support/EnrollsComplianceTrait.php app/tests/Unit/Compliance app/tests/Functional/Compliance
git commit -m "Compliance rekey: обязанность и факты ключуются по стабильному itemId вместо label — переименование позиции не рвёт связь"
```

---

### Task 2: Data-миграция (бэкфилл item_id + rekey фактов) + тест

Прод-данные: проставить `item_id` всем строкам проекции (норма — по карте label→id из jsonb; персональные/непонятные — сгенерить), переписать `compliance_fulfillment_record.obligation_key` на itemId-формат (не сматчившиеся — оставить). Идемпотентно. Покрыть функциональным тестом, который сеет label-ключи сырым SQL и проверяет перекладку.

**Files:**
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20261020120900.php`
- Create: `app/tests/Functional/Compliance/Infrastructure/Database/RekeyBackfillMigrationTest.php`

**Interfaces:**
- Consumes: таблицы `compliance_requirement(id, items jsonb)`, `compliance_tracked_obligation(id, profile_compliance_id, requirement_id, label, origin, item_id)`, `compliance_fulfillment_record(id, profile_compliance_id, obligation_key)`.
- Produces: data-миграция `Version20261020120900`; факты и проекция в itemId-формате.

- [ ] **Step 1: Тест бэкфилла — падает**

`RekeyBackfillMigrationTest.php` (KernelTestCase, в контейнере): через `Connection` (DBAL) засеять требование с одной позицией (jsonb items с известным `id`+`label`), профиль-compliance, одну tracked_obligation (origin='norm', item_id=NULL, label), один fulfillment_record c `obligation_key = reqId|lower(label)`; затем вызвать `up()` миграции (инстанцировать `Version20261020120900` с `$this->connection`), прочитать и проверить:
```php
public function test_norm_fact_and_obligation_rekeyed_to_item_id(): void
{
    [$reqId, $itemId, $label, $pcId, $recordId] = $this->seedLabelKeyed('Перчатки');
    $this->runMigration();

    $factKey = $this->conn->fetchOne('SELECT obligation_key FROM compliance_fulfillment_record WHERE id = ?', [$recordId]);
    self::assertSame($reqId.'|'.$itemId, $factKey, 'факт переключён на itemId');
    $obItemId = $this->conn->fetchOne('SELECT item_id FROM compliance_tracked_obligation WHERE profile_compliance_id = ? AND requirement_id = ?', [$pcId, $reqId]);
    self::assertSame($itemId, $obItemId, 'проекции проставлен item_id');
}

public function test_orphan_fact_label_not_in_norm_is_left_untouched(): void
{
    [$reqId, , , , $recordId] = $this->seedLabelKeyed('Перчатки', factLabel: 'снятаяпозиция');
    $this->runMigration();
    $factKey = $this->conn->fetchOne('SELECT obligation_key FROM compliance_fulfillment_record WHERE id = ?', [$recordId]);
    self::assertSame($reqId.'|снятаяпозиция', $factKey, 'факт без совпадения с нормой остаётся на label-ключе (orphan)');
}

public function test_migration_is_idempotent(): void
{
    [$reqId, $itemId, , , $recordId] = $this->seedLabelKeyed('Перчатки');
    $this->runMigration();
    $this->runMigration(); // второй прогон
    $factKey = $this->conn->fetchOne('SELECT obligation_key FROM compliance_fulfillment_record WHERE id = ?', [$recordId]);
    self::assertSame($reqId.'|'.$itemId, $factKey, 'повторный прогон не ломает уже переключенный ключ');
}
```
Инфраструктура теста (конкретно):
```php
protected function setUp(): void
{
    self::bootKernel();
    $this->conn = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
}

private function runMigration(): void
{
    // Миграция в up() работает напрямую через $this->connection (не addSql), поэтому вызываем up() прямо.
    $m = new \App\Shared\Infrastructure\Database\Migrations\Version20261020120900($this->conn, new \Psr\Log\NullLogger());
    $m->up(new \Doctrine\DBAL\Schema\Schema());
}
```
`seedLabelKeyed(string $label, ?string $factLabel = null)` сырым SQL через `$this->conn->executeStatement(...)` вставляет: строку `compliance_requirement` с `items` jsonb `[{"id": "<uuid>", "type":"material", "label": "<label>", ...}]` (минимально валидный элемент для `RequirementItemsType`); `personnel_profile`/`compliance_profile_compliance` (минимум колонок, NOT NULL заполнить); `compliance_tracked_obligation` (origin='norm', item_id=NULL, label=$label); `compliance_fulfillment_record` (obligation_key = `reqId.'|'.mb_strtolower($factLabel ?? $label)`). Возвращает `[$reqId, $itemId, $label, $pcId, $recordId]`. Чистка — rollback/`DELETE` в tearDown ЛИБО полагаться на DAMA-транзакцию, как в соседних функциональных тестах (посмотреть базовый класс).

- [ ] **Step 2: Прогнать — падает (нет миграции)**

Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Compliance/Infrastructure/Database/RekeyBackfillMigrationTest.php`
Expected: FAIL (класс `Version20261020120900` не найден).

- [ ] **Step 3: Написать data-миграцию**

Create `Version20261020120900.php`:
```php
<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Database\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Rekey-деплой, бэкфилл: проекции проставляем item_id (норма — по карте lower(label)→item.id из jsonb,
 * иначе генерим), факты переключаем obligation_key с reqId|lower(label) на reqId|itemId; не сматчившиеся
 * факты (позиция переименована/убрана из нормы) оставляем на label-ключе (orphan). Идемпотентно.
 */
final class Version20261020120900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rekey label→itemId: бэкфилл item_id проекции + перекладка obligation_key фактов';
    }

    public function up(Schema $schema): void
    {
        // 1) Карта reqId => [lower(label) => itemId] из jsonb нормы (id забэкфилен Version20261020120500).
        $map = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, items FROM compliance_requirement') as $row) {
            $items = json_decode((string) $row['items'], true) ?: [];
            foreach ($items as $item) {
                if (isset($item['id'], $item['label'])) {
                    $map[(string) $row['id']][mb_strtolower(trim((string) $item['label']))] = (string) $item['id'];
                }
            }
        }

        // 2) item_id для строк проекции: норма по карте; иначе (персональные/непонятные) — сгенерить.
        foreach ($this->connection->fetchAllAssociative("SELECT id, requirement_id, label, item_id FROM compliance_tracked_obligation") as $ob) {
            if (null !== $ob['item_id'] && '' !== $ob['item_id']) {
                continue; // идемпотентность
            }
            $label = mb_strtolower(trim((string) $ob['label']));
            $itemId = $map[(string) $ob['requirement_id']][$label] ?? Uuid::v7()->toRfc4122();
            $this->connection->executeStatement(
                'UPDATE compliance_tracked_obligation SET item_id = :iid WHERE id = :id',
                ['iid' => $itemId, 'id' => $ob['id']],
            );
        }

        // 3) Перекладка ключей фактов. Для каждого факта: reqId|lower(label) → reqId|itemId.
        //    Матч: сперва норма по карте; иначе персональная обязанность того же профиля по label; иначе оставить.
        foreach ($this->connection->fetchAllAssociative('SELECT id, profile_compliance_id, obligation_key FROM compliance_fulfillment_record') as $f) {
            $key = (string) $f['obligation_key'];
            $sep = strpos($key, '|');
            if (false === $sep) {
                continue;
            }
            $reqId = substr($key, 0, $sep);
            $labelPart = substr($key, $sep + 1); // уже lower(trim) из keyOf
            // уже перекладывали? (суффикс совпадает с каким-то itemId этого требования) — пропустить
            if (in_array($labelPart, array_values($map[$reqId] ?? []), true)) {
                continue;
            }
            $itemId = $map[$reqId][$labelPart] ?? null;
            if (null === $itemId) {
                $itemId = $this->connection->fetchOne(
                    "SELECT item_id FROM compliance_tracked_obligation WHERE profile_compliance_id = :pc AND requirement_id = :req AND origin = 'personal' AND lower(trim(label)) = :lbl",
                    ['pc' => $f['profile_compliance_id'], 'req' => $reqId, 'lbl' => $labelPart],
                ) ?: null;
            }
            if (null === $itemId) {
                continue; // orphan: позиция переименована/убрана — оставляем label-ключ
            }
            $this->connection->executeStatement(
                'UPDATE compliance_fulfillment_record SET obligation_key = :nk WHERE id = :id',
                ['nk' => $reqId.'|'.$itemId, 'id' => $f['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // no-op: обратный маппинг itemId→label недетерминирован.
    }
}
```

- [ ] **Step 4: Прогнать тест бэкфилла — зелёный**

Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli bin/console doctrine:migrations:migrate -n` (применить схему+новую миграцию к test_db)
Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Compliance/Infrastructure/Database/RekeyBackfillMigrationTest.php`
Expected: PASS (3 кейса).

- [ ] **Step 5: ./run check + commit**

Run: `./run check`
```bash
git add app/src/Shared/Infrastructure/Database/Migrations/Version20261020120900.php app/tests/Functional/Compliance/Infrastructure/Database/RekeyBackfillMigrationTest.php
git commit -m "Compliance rekey: прод-бэкфилл — item_id в проекцию, перекладка obligation_key фактов на itemId (orphan остаётся на label)"
```

---

### Task 3: Регресс — переименование позиции не рвёт связь факта

Главная цель деплоя: после переименования позиции нормы (тот же `item.id`, новый `label`) выданный факт остаётся привязан к той же обязанности (held/история сохраняются). Плюс явная проверка безопасности `/` в наименовании.

**Files:**
- Create: `app/tests/Functional/Compliance/Application/UseCase/RenameDoesNotBreakFactLinkTest.php`

**Interfaces:**
- Consumes: `EnrollsComplianceTrait` (норма с позицией), `SaveRequirementCommand` (переименование позиции с СОХРАНЕНИЕМ её id), `ComplianceProjectionRebuilder`, `ProfileComplianceRepositoryInterface::findByProfile()->heldOf($key)`.

- [ ] **Step 1: Тест — падал бы на label-ключе, проходит на itemId**

```php
public function test_renaming_norm_item_keeps_fact_held_linked(): void
{
    ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance('Перчатки');
    $this->issueCard($p, $r, $k); // на руках 10 по позиции

    // Переименовать позицию, СОХРАНИВ её стабильный id (round-trip правки нормы).
    $requirement = static::getContainer()->get(RequirementRepositoryInterface::class)->findOneById($r);
    $itemId = $requirement->getItems()[0]->id();
    $this->commandBus->execute(new SaveRequirementCommand(
        $r, 'Личная карточка учёта выдачи СИЗ', 'material', $requirement->getPositionIds()->getList(),
        [['id' => $itemId, 'label' => 'Перчатки трикотажные', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5']],
    ));
    static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($p));
    static::getContainer()->get(EntityManagerInterface::class)->clear();

    // Ключ не изменился (тот же itemId), held сохранился на той же обязанности.
    $pc = static::getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
    self::assertNotNull($pc);
    $newKey = TrackedObligation::keyOf($r, $itemId);
    self::assertSame($k, $newKey, 'ключ стабилен к переименованию (тот же itemId)');
    self::assertEqualsWithDelta(10.0, $pc->heldOf($newKey), 1e-9, 'held факта остался привязан после переименования');
}
```
(Проверить сигнатуру `SaveRequirementCommand` в коде — если id позиции передаётся иным полем/в ином порядке, подставить фактическое. Ключ: `RequirementItemBuilder` сохраняет переданный `id`, см. D1.)

- [ ] **Step 2: Прогнать — зелёный (на itemId-ключе связь держится)**

Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Compliance/Application/UseCase/RenameDoesNotBreakFactLinkTest.php`
Expected: PASS. (Санити: если временно откатить key() на label — тест упал бы, held потерялся бы.)

- [ ] **Step 3: Прогнать существующие `/`-регресс-тесты — остаются зелёными**

Run: `docker compose -f docker-compose.test.yml run --rm test_php-cli vendor/bin/phpunit tests/Functional/Compliance/Infrastructure/Controller/IssueActionTest.php tests/Functional/Compliance/Infrastructure/Controller/ObligationHistoryActionTest.php`
Expected: PASS (ключ теперь uuid, слэша в нём нет; query-param хотфикс безвреден).

- [ ] **Step 4: ./run check + commit**

Run: `./run check`
```bash
git add app/tests/Functional/Compliance/Application/UseCase/RenameDoesNotBreakFactLinkTest.php
git commit -m "Compliance rekey: регресс — переименование позиции нормы не рвёт связь факта с нормой (held/история целы)"
```

---

## Deploy / ops (после мержа)

1. Прод, ПОСЛЕ миграции — подсчёт orphan-фактов (суффикс ключа остался не-uuid = не сматчился с нормой/персональной, оставлен на label):
   ```sql
   SELECT count(*) FROM compliance_fulfillment_record
   WHERE split_part(obligation_key, '|', 2) !~ '^[0-9a-f]{8}-[0-9a-f]{4}-';
   ```
   Ожидаем ≈0. Если >0 — сверить вручную (переименованные/убранные из нормы позиции), решить с заказчиком (их held/история целы, просто вне контроля).
1a. Прод, в том же гейте — проверка гонки окна деплоя (старый код вставил персональную обязанность с `item_id = NULL` ДО свопа кода):
   ```sql
   SELECT count(*) FROM compliance_tracked_obligation WHERE item_id IS NULL;
   ```
   Ожидаем 0. Если >0 — это персональная обязанность, созданная старым кодом внутри migrate-гейта (до свопа); повторный прогон `app:compliance:rebuild-projection` её не пересоздаст (ребилдер трогает только ORIGIN_NORM). Руками: сгенерить uuid, проставить его как `item_id` этой строке, затем перегнать rekey фактов для профиля (повторно применить логику Task 2 п.3 для этого `profile_compliance_id`) — либо проще переиздать позицию (re-issue) через UI после свопа кода.
2. Деплой migrate-first: миграции `Version20261020120800` (схема) + `Version20261020120900` (бэкфилл) в гейте ДО свопа кода.
3. После миграций (в том же гейте) — `app:compliance:rebuild-projection` (пересобрать норм-обязанности с item.id(), освежить held/даты).
4. Свопнуть код.
5. Пост-проверка: открыть карточку выдачи с позицией, где label содержал `/` — страница/история без 500; held совпадает с до-деплоя.
