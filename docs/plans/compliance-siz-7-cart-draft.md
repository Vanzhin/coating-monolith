# Учёт СИЗ Д7 — «акт = корзина»: черновик-носитель строк, held только по закрытым актам

> Реализация по правилам проекта: Plan → Code, по шагам, TDD. Коммиты только по явному апруву (feedback_no_commits). Гейты в контейнере (`./run check`), тест-БД пересоздать+migrate, dev-БД дельтой. Бинарные `.docx/.xlsx` НЕ трогаем.

**Один деплой** (прод по Compliance пустой — бэкфилла нет). Соседние планы: `compliance-siz-2-requirements.md`, `compliance-siz-3-tracking-card.md`, `compliance-siz-6-quantity-writeoff.md`.

## Цель

Сделать акт выдачи «корзиной»: черновик (`RequirementDocument` в статусе `Formed`) хранит свои строки прямо как `FulfillmentRecord` (дети акта, FK + cascade) и несёт № карточки / ответственного на самом документе. `held` (и сроки, и светофор) считается **только по фактам закрытых — подписанных — актов**. Бланк и проектор читают данные из открытого черновика-корзины, поэтому «Скачать бланк» отдаёт заполненный файл ещё до подписи. Акт списания уже устроен как корзина (`WriteOffAct` ↔ `WriteOffItem`, FK cascade) — выравниваем под ту же модель и словарь.

Решает исходную проблему: «сохранить нельзя без скана, а бланк качается пустым» (курица-яйцо). Теперь: положил в корзину → сохранил черновик (№/ответственный/строки, без скана) → скачал заполненный бланк → распечатал → подпись → скан → «Оформить» (подпись).

## Архитектура

- **Обязательство (что должен)** = `TrackedObligation` — проекция нормы на человека (существует и при пустой корзине; тогда позиция Red/недовыдано). Источник «списка покупок» и кэш `heldQuantity`/дат.
- **Корзина (что кладёшь)** = черновик `RequirementDocument` (`Formed`) + его `FulfillmentRecord`. № и ответственный — колонки документа (уже есть: `act_number`, `responsible_fio`).
- **Гейт «выдано»** = статус документа записи. Факт считается в held/сроки, только если его акт `Signed`. До подписи корзина на учёт не влияет. Переходно (до Task 5, пока `document_id` не обязателен) гейт мягкий: запись без документа (прямой факт/тестовый) считается выданной, запись на `Formed`-акте (корзина) — нет. После Task 5 у каждой записи есть акт, и гейт = `document->isSigned()`.
- **build наполняет корзину дефицитом** (идемпотентно). «Что положить» — это дефицит, он уже считается (норма − на руках). По каждой позиции build доводит корзину до нормы, добавляя только недостающее:
  `добавить = max(0, норма − held − уже_в_корзине)`. Нет черновика и есть дефицит → создаёт и кладёт. Есть черновик → **дополняет** недостающим, существующие строки и количества НЕ трогает, ничего не удаляет (норма упала — лишнее остаётся, видно в форме). Повторный прогон без смены нормы → 0 к добавлению (дублей нет). № и ответственного build не пишет — это только пользовательский ввод.
- **Один примитив наполнения корзины.** `saveDraft` пишет строки пользователя (замена его версии), build — дефицит (аддитивный топ-ап). Обе операции кладут в одну корзину (`records` на `Formed`-документе).
- **Транзакция** — не добавляем руками: `command.bus` обёрнут `doctrine_transaction`, вся мутация идёт в одном агрегате `ProfileCompliance` с каскадным persist → один flush → один commit (документ + записи + обязанности атомарно). Нетранзакционный момент — промоут скана в хранилище файлов (существующее поведение, вне scope).
- **Инвариант порядка при подписи:** сначала `markSigned` (документ → `Signed`), ПОТОМ `recompute` — иначе гейт не увидит акт подписанным и held не поднимется.

## Решения по хранению (минимальная правка, без смены ownership)

- Flat-коллекцию `records` на `ProfileCompliance` (owning, `orphan-removal`) **оставляем** — все существующие аксессоры (`recordById`, `recordsForRequirement`, `getRecords`, `removeRecord`) работают без изменений.
- `FulfillmentRecord.documentId`: `nullable → NOT NULL`, остаётся скаляром (не вводим Doctrine-ассоциацию — лишняя сложность с двумя родителями). На уровне БД добавляем FK `document_id → compliance_requirement_document(id) ON DELETE CASCADE` как ограничение целостности.
- Удаление черновика чистит его строки через flat-коллекцию (`orphan-removal`) в домене — не полагаемся на БД-cascade для Doctrine UoW; FK cascade — страховка целостности.
- Гейт «signed» централизуем в одном предикате агрегата, применяем в двух точках суммирования held: `heldOf` и `recomputeObligation`.

## Global Constraints

- Домен решает (инварианты в агрегате/VO), Application/Infrastructure оркеструют. Авторизация — `ComplianceAccessControl::canManage()` в хендлере, не в контроллере.
- `AppException` с русским сообщением → 422 в форму.
- Миграция идемпотентная (`IF [NOT] EXISTS`, проверки состояния). Прод пуст — бэкфилла нет, но типы FK выровнять (см. Task 5).
- Тесты зеркалят `src/`. Доменные инварианты — unit; хендлеры — функциональные с реальной БД.
- Один `findByFilter`/аксессор; не плодим методы репозитория.

## Review Focus

- Черновая строка не должна течь в held/светофор/сроки ни в одной точке чтения — проверить `heldOf`, `recomputeObligation`, дашборд-бакет, `assertIssuable`.
- `assertIssuable` при подписи: `held(подписанное) + выдаётся ≥ норма` — строки текущего акта не должны считаться дважды (они и есть «выдаётся», а как Formed-записи в held не входят).
- Удаление черновика — его строки удаляются (нет сирот в `compliance_fulfillment_record`).
- Порядок `markSigned` → `recompute` (иначе held не поднимется при оформлении).
- FK-типы `document_id` (varchar) vs `compliance_requirement_document.id` (uuid) — миграция должна их согласовать, иначе констрейнт не создастся.

---

## Task 1: Гейт «held только по подписанным актам» в домене

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` (`heldOf`, `recomputeObligation`, новый приватный предикат)
- Test: `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/ProfileComplianceTest.php`

**Interfaces:**
- Produces: `private function isIssued(FulfillmentRecord $record): bool` — запись принадлежит подписанному документу.

- [ ] **Шаг 1. Падающий тест: черновая строка не входит в held.**

```php
public function test_draft_record_does_not_count_toward_held(): void
{
    $pc = $this->enrolledWithGlovesNorm(); // норма 2 пары
    $draftId = Uuid::v4();
    $pc->formDraft($draftId, $this->reqId(), new \DateTimeImmutable('2026-01-01'));
    // строка кладётся в корзину (документ ещё Formed)
    $pc->saveDraft((string) $draftId, '', '', [
        new IssuanceLine(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), new Quantity(2.0, Unit::Pair), null, null),
    ], new \DateTimeImmutable('2026-01-10'));

    self::assertSame(0.0, $pc->heldOf($this->glovesKey()), 'корзина (Formed) в held не входит');
    self::assertSame(ComplianceStatus::Red, $pc->worstStatus($this->statusResolver(), null, new \DateTimeImmutable('2026-01-11')));
}
```

- [ ] **Шаг 2. Запустить — упадёт** (`replaceDraftLines` нет; held считает все записи).

- [ ] **Шаг 3. Реализация гейта.**

```php
private function isIssued(FulfillmentRecord $record): bool
{
    $document = $this->documentById((string) $record->documentId());

    return null !== $document && $document->isSigned();
}
```

В `heldOf` и `recomputeObligation` — суммировать/выбирать свежий факт только для `$this->isIssued($record)`:

```php
// heldOf
foreach ($this->records as $record) {
    if ($record->obligationKey() === $obligationKey && $this->isIssued($record)) {
        $sum += $record->heldAmount();
    }
}
// recomputeObligation — тем же условием обернуть блок held + выбор $latest (строки 560-568)
```

- [ ] **Шаг 4. Тест проходит.**

- [ ] **Шаг 5. Тест: после подписи та же строка входит в held и зеленит светофор** (готовит почву для Task 3 — пока через прямой `markSigned` документа + `recomputeRequirement`).

---

## Task 2: Корзина — единый `saveDraft` (строки + реквизиты на черновике)

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php`
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/RequirementDocument.php` (`saveDraftDetails`)
- Test: `ProfileComplianceTest.php`, `app/tests/Unit/Compliance/Domain/Aggregate/ProfileCompliance/RequirementDocumentTest.php`

**Interfaces:**
- Produces:
  - `RequirementDocument::saveDraftDetails(string $actNumber, string $responsibleFio, \DateTimeImmutable $now): void` — пишет № и ответственного, пока документ `Formed` (`assertMutable`); пустые допускаются (черновик, не финал).
  - `ProfileCompliance::saveDraft(string $documentId, string $actNumber, string $responsibleFio, array $lines, \DateTimeImmutable $now): void` — ОДИН метод: заменяет строки корзины черновика (удаляет из `$this->records` все с `documentId` этого акта, добавляет новые `FulfillmentRecord` c этим `documentId`, акт остаётся `Formed`) + пишет реквизиты через `document->saveDraftDetails`. Без скана, без подписи. **Калькулятор не нужен и `recompute` не зовём**: черновые записи гейт «signed» в held/даты не пускает, замена строк корзины на held/сроки не влияет → пересчёт был бы no-op. Переиспользуется подписью (Task 3).

- [ ] **Шаг 1. Падающий тест `RequirementDocumentTest`: `saveDraftDetails` пишет реквизиты на Formed и запрещён на Signed.**

```php
public function test_save_draft_details_sets_fields_while_formed(): void
{
    $doc = new RequirementDocument(Uuid::v4(), $this->pc(), 'req', new \DateTimeImmutable());
    $doc->saveDraftDetails('К-1', 'Петров П. П.', new \DateTimeImmutable());
    self::assertSame('К-1', $doc->actNumber());
    self::assertSame('Петров П. П.', $doc->responsibleFio());
}

public function test_save_draft_details_forbidden_after_sign(): void
{
    $doc = new RequirementDocument(Uuid::v4(), $this->pc(), 'req', new \DateTimeImmutable());
    $doc->markSigned('scan', 'К-1', 'Петров П. П.', new \DateTimeImmutable());
    $this->expectException(AppException::class);
    $doc->saveDraftDetails('К-2', 'Иванов', new \DateTimeImmutable());
}
```

- [ ] **Шаг 2. Запустить — упадёт.**

- [ ] **Шаг 3. `RequirementDocument::saveDraftDetails`:**

```php
/** Сохранить реквизиты черновика (№/ответственный), пока не подписан. Пустые допустимы — это черновик. */
public function saveDraftDetails(string $actNumber, string $responsibleFio, \DateTimeImmutable $now): void
{
    $this->assertMutable();
    $this->actNumber = '' === trim($actNumber) ? null : trim($actNumber);
    $this->responsibleFio = '' === trim($responsibleFio) ? null : trim($responsibleFio);
    $this->updatedAt = $now;
}
```

- [ ] **Шаг 4. Тест проходит.**

- [ ] **Шаг 5. Падающий тест `ProfileComplianceTest`: `saveDraft` кладёт строки в корзину, held не растёт, реквизиты на документе; повторный `saveDraft` заменяет строки (не плодит).**

- [ ] **Шаг 6. `ProfileCompliance::saveDraft`:**

```php
/** Сохранить черновик целиком: строки корзины (замена) + реквизиты. Акт остаётся Formed — в held не входит. */
public function saveDraft(string $documentId, string $actNumber, string $responsibleFio, array $lines, \DateTimeImmutable $now): void
{
    $document = $this->documentById($documentId) ?? throw new AppException('Черновик не найден.');
    $document->assertMutable();
    foreach ($this->records as $record) {
        if ($record->documentId() === $documentId) {
            $this->records->removeElement($record); // orphan-removal удалит строку
        }
    }
    foreach ($lines as $line) {
        $this->records->add(new FulfillmentRecord(
            $line->recordId, $this, $line->obligationKey, $line->fulfilledAt,
            $line->quantity, $line->wearPercent, null, $line->manualDueDate, documentId: $documentId,
        ));
    }
    $document->saveDraftDetails($actNumber, $responsibleFio, $now);
}
```

- [ ] **Шаг 7. Тесты проходят.**

---

## Task 3: Подпись из корзины — порядок `markSigned` → `recompute`

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` (`signDraft`/`recordAndSign`)
- Test: `ProfileComplianceTest.php`, `app/tests/Functional/Compliance/Application/UseCase/RecordIssuanceTest.php`

**Interfaces:**
- Changed: `recordAndSign` больше НЕ создаёт записи пофактно до подписи. Теперь: `replaceDraftLines` (строки формы → корзина, Formed, held не трогает) → `assertIssuable` → `markSigned` → `setActiveForRequirement(true)` → `recomputeRequirement` (ПОСЛЕ подписи — строки проходят гейт, held поднимается, даты встают).

- [ ] **Шаг 1. Падающий тест: оформление поднимает held и зеленит только после подписи, и ровно один раз.**

```php
public function test_sign_from_cart_raises_held_after_sign(): void
{
    $pc = $this->enrolledWithGlovesNorm();
    $draftId = Uuid::v4();
    $pc->formDraft($draftId, $this->reqId(), new \DateTimeImmutable('2026-01-01'));
    $lines = [new IssuanceLine(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), new Quantity(2.0, Unit::Pair), null, null)];

    $pc->signDraft((string) $draftId, 'scan-file', $lines, $this->calc, new \DateTimeImmutable('2026-01-10'), 'К-1', 'Петров П. П.');

    self::assertSame(2.0, $pc->heldOf($this->glovesKey()));
    self::assertSame(ComplianceStatus::Green, $pc->worstStatus($this->statusResolver(), null, new \DateTimeImmutable('2026-01-11')));
}
```

- [ ] **Шаг 2. Запустить — упадёт** (старый `recordAndSign` создаёт записи до `markSigned`; гейт их не увидит подписанными → held 0).

- [ ] **Шаг 3. Переписать `recordAndSign`:**

```php
private function recordAndSign(RequirementDocument $document, array $lines, ?string $scanFileId, ObligationDueCalculator $calculator, \DateTimeImmutable $now, string $actNumber, string $responsibleFio): void
{
    if (null === $scanFileId || '' === trim($scanFileId)) {
        throw new AppException('Приложите скан подписанной карточки — без него нельзя оформить.');
    }
    $this->replaceDraftLines($document->getId(), $lines, $calculator); // строки формы = корзина, ещё Formed
    $this->assertIssuable($lines);                                      // held(подписанное) + выдаётся ≥ норма
    $document->markSigned($scanFileId, $actNumber, $responsibleFio, $now); // сначала подпись
    $this->setActiveForRequirement($document->requirementId(), true);
    $this->recomputeRequirement($document->requirementId(), $calculator); // потом пересчёт — строки прошли гейт
}
```

- [ ] **Шаг 4. Тесты проходят** (unit + `RecordIssuanceTest` остаётся зелёным — поведение «после подписи» то же).

- [ ] **Шаг 5. Тест: удаление черновика уносит его строки корзины (нет сирот).**

```php
public function test_delete_draft_removes_its_cart_lines(): void
{
    $pc = $this->enrolledWithGlovesNorm();
    $draftId = Uuid::v4();
    $pc->formDraft($draftId, $this->reqId(), new \DateTimeImmutable('2026-01-01'));
    $pc->replaceDraftLines((string) $draftId, [new IssuanceLine(Uuid::v4(), $this->glovesKey(), new \DateTimeImmutable('2026-01-10'), new Quantity(2.0, Unit::Pair), null, null)], $this->calc);
    $pc->deleteDraft((string) $draftId);
    self::assertCount(0, $pc->getRecords(), 'строки корзины удалились вместе с черновиком');
}
```

- [ ] **Шаг 6. Дополнить `deleteDraft`** — удалить строки корзины акта из `$this->records` (orphan-removal) до/после снятия документа:

```php
public function deleteDraft(string $documentId): void
{
    $document = $this->documentById($documentId);
    if (null === $document) { return; }
    $document->assertMutable();
    foreach ($this->records as $record) {
        if ($record->documentId() === $documentId) {
            $this->records->removeElement($record);
        }
    }
    $this->documents->removeElement($document);
}
```

- [ ] **Шаг 7. Тест проходит.**

---

## Task 3.5: Дефицит-топап — build наполняет/дополняет корзину нормой

«Что положить» = дефицит (норма − на руках), уже считается. Выносим построчный расчёт в домен и наполняем им корзину при формировании: один примитив с `saveDraft`, идемпотентно, аддитивно.

**Files:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` (`topUpDraftFromNorm`)
- Modify: `app/src/Compliance/Application/Service/DraftFormationService.php` (создать → наполнить/дополнить)
- Test: `ProfileComplianceTest.php`, `app/tests/Functional/Compliance/Application/UseCase/DraftFlowTest.php`

**Interfaces:**
- Produces: `ProfileCompliance::topUpDraftFromNorm(string $documentId, string $requirementId, \DateTimeImmutable $now): void` — по каждой материальной обязанности требования добавляет в корзину строку `max(0, норма − held − уже_в_корзине)` (если > 0); нематериальную (процедура/журнал) — добавляет строку присутствия, если её в корзине ещё нет. Не трогает существующие строки, ничего не удаляет. Идемпотентно.

- [ ] **Шаг 1. Падающий тест: топ-ап доводит корзину до нормы аддитивно.**

```php
public function test_topup_adds_only_missing_to_reach_norm(): void
{
    $pc = $this->enrolledWithGlovesNorm(); // норма 10
    $draftId = Uuid::v4();
    $pc->formDraft($draftId, $this->reqId(), new \DateTimeImmutable('2026-01-01'));

    // норма 10, на руках 0, корзина пуста → топ-ап кладёт 10
    $pc->topUpDraftFromNorm((string) $draftId, $this->reqId(), new \DateTimeImmutable('2026-01-01'));
    self::assertSame(10.0, $this->cartSum($pc, $draftId, $this->glovesKey()));

    // повтор без изменений → ничего не добавил (идемпотентно)
    $pc->topUpDraftFromNorm((string) $draftId, $this->reqId(), new \DateTimeImmutable('2026-01-01'));
    self::assertSame(10.0, $this->cartSum($pc, $draftId, $this->glovesKey()));
}

public function test_topup_supplements_after_norm_increase(): void
{
    $pc = $this->enrolledWithGlovesNorm(); // норма 10
    $draftId = Uuid::v4();
    $pc->formDraft($draftId, $this->reqId(), new \DateTimeImmutable('2026-01-01'));
    $pc->topUpDraftFromNorm((string) $draftId, $this->reqId(), new \DateTimeImmutable('2026-01-01')); // корзина 10

    $this->raiseGlovesNormTo($pc, 20.0); // норма 10 → 20
    $pc->topUpDraftFromNorm((string) $draftId, $this->reqId(), new \DateTimeImmutable('2026-01-02'));
    self::assertSame(20.0, $this->cartSum($pc, $draftId, $this->glovesKey()), 'дополнил недостающие 10 → 20');
}
```

- [ ] **Шаг 2. Запустить — упадёт** (`topUpDraftFromNorm` нет).

- [ ] **Шаг 3. `topUpDraftFromNorm`:**

```php
public function topUpDraftFromNorm(string $documentId, string $requirementId, \DateTimeImmutable $now): void
{
    $document = $this->documentById($documentId) ?? throw new AppException('Черновик не найден.');
    $document->assertMutable();
    foreach ($this->obligations as $obligation) {
        if ($obligation->requirementId() !== $requirementId) {
            continue;
        }
        $key = $obligation->key();
        $norm = $obligation->quantity();
        if (null === $norm) { // нематериальная — строка присутствия, если ещё нет
            if (!$this->cartHasKey($documentId, $key)) {
                $this->addCartLine($documentId, $key, null, $now);
            }
            continue;
        }
        $missing = $norm->amount - $this->heldOf($key) - $this->cartSumFor($documentId, $key);
        if ($missing > 1e-9) {
            $this->addCartLine($documentId, $key, new Quantity($missing, $norm->unit), $now);
        }
    }
}
```

(`addCartLine`/`cartSumFor`/`cartHasKey` — приватные хелперы над `$this->records` с фильтром по `documentId`.)

- [ ] **Шаг 4. Тесты проходят.**

- [ ] **Шаг 5. `DraftFormationService.formForProfileRequirement`:** вместо «есть открытый → пропустить» — создать черновик, если его нет и есть дефицит; затем `topUpDraftFromNorm` (и для вновь созданного, и для существующего). Гард `hasDue` остаётся как условие «вообще есть что класть». Сохранение — у зовущего.

- [ ] **Шаг 6. Функциональный тест в `DraftFlowTest`:** событие нормы при существующем черновике дополняет корзину, не плодя черновик.

---

## Task 4: Одна команда `SaveDraft` — сохранить (без скана) / оформить (со сканом)

Единая команда вместо двух. `SignDraftCommand` уже несёт nullable `stagedFileId` — переименовываем в `SaveDraftCommand` и ветвим хендлер по наличию скана. Бэкбон — доменный `saveDraft` (Task 2), финализация — доменный `signDraft` (Task 3).

**Files:**
- Rename: `app/src/Compliance/Application/UseCase/Command/SignDraft/*` → `.../Command/SaveDraft/SaveDraftCommand.php` + `SaveDraftCommandHandler.php` (обновить namespace + ссылки)
- Modify: все использования `SignDraftCommand` (контроллер `IssueAction`, тесты `DraftFlowTest`, `RecordIssuanceTest`, `CardDownloadControllerTest`) → `SaveDraftCommand`
- Test: `app/tests/Functional/Compliance/Application/UseCase/DraftFlowTest.php` (+ новый кейс «сохранить без скана»)

**Interfaces:**
- Produces: `SaveDraftCommand(string $profileId, string $documentId, string $documentDate, array $items, string $actNumber, string $responsibleFio, ?string $stagedFileId = null, array $personalItems = [])` (та же сигнатура, что у текущего `SignDraftCommand`).
- Handler ветвление:
  ```php
  if (!$this->access->canManage()) { throw new ForbiddenException(); }
  $pc = $this->repository->findByProfile($command->profileId) ?? throw new AppException('Учёт не создан.', Response::HTTP_NOT_FOUND);
  $lines = $this->buildLines($pc, $command->documentId, $command->items, $command->personalItems, $command->documentDate);
  if (null !== $command->stagedFileId && '' !== $command->stagedFileId) {
      $scan = $this->promote($command->stagedFileId);                 // «Оформить» (подпись)
      $pc->signDraft($command->documentId, $scan, $lines, $this->calculator, $now, $command->actNumber, $command->responsibleFio);
  } else {
      $pc->saveDraft($command->documentId, $command->actNumber, $command->responsibleFio, $lines, $now); // «Сохранить черновик»
  }
  $this->repository->add($pc);
  ```

- [ ] **Шаг 1. Падающий тест: `SaveDraft` без скана кладёт строки+реквизиты, held не растёт; со сканом — поднимает held.**

```php
public function test_save_without_scan_keeps_cart_unissued(): void
{
    ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
    $docId = $this->formDraftAndGetId($p, $r);

    $this->commandBus->execute(new SaveDraftCommand(
        $p, $docId, '2026-03-01',
        [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']],
        'К-1', 'Петров П. П.', // без stagedFileId
    ));

    $this->em()->clear();
    $pc = $this->reload($p);
    self::assertSame(0.0, $pc->heldOf($k), 'сохранено, но не выдано (скан не приложен)');
    self::assertSame('К-1', $pc->openDraftFor($r)?->actNumber());
}
```

- [ ] **Шаг 2. Запустить — упадёт** (нет `SaveDraftCommand` / хендлер не ветвится).

- [ ] **Шаг 3. Переименовать команду/хендлер, добавить ветвление по `stagedFileId`.** Общий сборщик `buildLines` (из `items`+`personalItems`, материализует персональные обязанности — как сейчас в `SignDraft`). Регистрация через `implements CommandHandlerInterface`.

- [ ] **Шаг 4.** Обновить все ссылки `SignDraftCommand` → `SaveDraftCommand`, прогнать функциональные (существующие сценарии «со сканом» остаются зелёными — поведение подписи не изменилось).

- [ ] **Шаг 5. Тест: сохранение на подписанном акте → `AppException` (`assertMutable`).**

---

## Task 5: ORM + миграция — `document_id` NOT NULL + FK cascade

**Files:**
- Modify: `app/src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.FulfillmentRecord.orm.xml`
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/FulfillmentRecord.php` (сделать `documentId` обязательным)
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version20261010120000.php`
- Fix tests: все вызовы `recordFulfillment(...)` без `documentId` (unit + функциональные хелперы) — провести через акт/передать id (см. Review Focus)

- [ ] **Шаг 1. ORM:** у `FulfillmentRecord.document_id` убрать `nullable="true"`.

- [ ] **Шаг 2. Домен:** в `FulfillmentRecord::__construct` сделать `string $documentId` обязательным (убрать `= null`), геттер `documentId(): string`. Поправить вызовы (`replaceDraftLines`, `recordAndSign` уже передают; тестовые `recordFulfillment` — передать id акта).

- [ ] **Шаг 3. Миграция (идемпотентная).** Согласовать типы FK (document_id varchar(36) vs `compliance_requirement_document.id` uuid). Шаги в `up()`:
  - если есть строки без `document_id` (прод пуст, но на всякий) — не трогаем, просто проверка;
  - `ALTER TABLE compliance_fulfillment_record ALTER COLUMN document_id SET NOT NULL` (под проверкой `IS NULL`-строк нет);
  - привести тип `document_id` к типу `id` родителя (если `id` — `uuid`: `ALTER COLUMN document_id TYPE uuid USING document_id::uuid`);
  - добавить FK `ADD CONSTRAINT fk_cfr_document FOREIGN KEY (document_id) REFERENCES compliance_requirement_document (id) ON DELETE CASCADE` под проверкой отсутствия констрейнта (`information_schema.table_constraints`).
  - Перед написанием — свериться с фактическими типами: `\d compliance_fulfillment_record` и `\d compliance_requirement_document` на dev-БД.

- [ ] **Шаг 4.** Пересоздать тест-БД + migrate, прогнать функциональные.

---

## Task 6: Проектор/бланк читают корзину

**Files:**
- Modify: `app/src/Compliance/Application/Service/RequirementCardProjector.php`
- Test: `app/tests/Functional/Compliance/Infrastructure/Controller/CardDownloadControllerTest.php`

- [ ] **Шаг 1. Падающий тест: бланк непод­писанной карточки с сохранённым черновиком содержит № и ответственного из черновика.**

```php
public function test_blank_uses_saved_draft_details(): void
{
    ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
    $docId = $this->formDraft($p, $r);
    $this->commandBus->execute(new SaveDraftCommand($p, $docId, 'К-777', 'Сидоров С. С.', '2026-03-01', [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']]));

    $this->client->request('GET', sprintf('/cabinet/compliance/person/%s/requirement/%s/card', $p, $r));
    $text = $this->docxText((string) $this->client->getResponse()->getContent());
    self::assertStringContainsString('К-777', $text);
    self::assertStringContainsString('Сидоров', $text);
}
```

- [ ] **Шаг 2. Запустить — упадёт** (проектор берёт № только с последнего подписанного).

- [ ] **Шаг 3. Проектор:** `card_number`/`responsible_fio` — из открытого черновика (`openDraftFor`), иначе с последнего подписанного (`latestSigned`):

```php
$open = $profileCompliance->openDraftFor($requirement->getId());
$latest = $this->latestSigned($profileCompliance, $requirement->getId());
$source = $open ?? $latest;
$values['card_number'] = new TextValue($source?->actNumber() ?? '');
$values['responsible_fio'] = new TextValue($source?->responsibleFio() ?? '');
```

- [ ] **Шаг 4. Тест проходит.** (`items` в бланке остаются нормой — строки корзины на перечень нормы не влияют.)

---

## Task 7: Фронт оформления — «Сохранить черновик» + «Скачать бланк» заполнен

**Files:**
- Modify: `app/src/Compliance/Infrastructure/Controller/Fulfillment/IssueAction.php` (роут/обработка POST `save` vs `sign`)
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig`
- Test: `CardDownloadControllerTest::test_dashboard_person_and_issue_pages_render` (не падает)

- [ ] **Шаг 1.** В форме оформления две кнопки сабмита: `action=save` → `SaveDraftCommand` (без скана), `action=sign` → `SignDraftCommand` (как было). Контроллер по `inputData['action']` диспатчит нужную команду; оба — тонко, через шину. «Скачать бланк» (GET `/card`) гидрирует из сохранённого черновика (Task 6), поэтому порядок UX: заполнил → «Сохранить черновик» → «Скачать бланк» → печать/подпись/скан → «Оформить».
- [ ] **Шаг 2.** Разметку кнопок копировать из ближайшего аналога (`btn-soft-success`/`btn-outline-secondary`), без новых классов. Скан и `assertIssuable` — требования только для «Оформить», не для «Сохранить черновик» (подсказать текстом).
- [ ] **Шаг 3.** Прогнать рендер-тест страницы оформления.

---

## Task 8: Выровнять акт списания под тот же словарь «корзины»

**Files:**
- Review/Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffAct.php`, `ProfileCompliance.php` (методы списания), `app/src/Compliance/Infrastructure/Controller/WriteOffAct/*`
- Test: существующие write-off тесты зелёные

`WriteOffAct` ↔ `WriteOffItem` уже корзина (FK cascade, draft→sign). Проверить и при необходимости выровнять:
- [ ] **Шаг 1.** Есть ли «сохранить корзину списания без подписи» (добавить/убрать порцию на черновике акта) и «удалить черновик акта списания» — по аналогии с выдачей. Если нет и это нужно для UX — добавить командами тонко (домен уже позволяет: `WriteOffItem` orphan-removal). Если флоу уже закрыт — только сверить словарь/тексты, код не трогать.
- [ ] **Шаг 2.** Подтвердить: порции списания применяются к held (`addReturnedQuantity`) ТОЛЬКО при подписи акта списания (черновые порции held не трогают) — консистентно с гейтом выдачи. Тест, если не покрыто.
- [ ] **Шаг 3.** Прогнать write-off тесты.

---

## Task 9: Гейты и завершение

- [ ] Пересоздать тест-БД + migrate; `./run check` (style/phpstan/unit/functional) зелёный; `./run check style:fix` при необходимости.
- [ ] dev-БД: применить миграцию дельтой (`./run console doctrine:migrations:migrate -n`), `cache:clear`.
- [ ] Ручной смоук в браузере: заполнил оформление → «Сохранить черновик» → «Скачать бланк» (заполнен № + ответственный) → «Оформить» со сканом → светофор зеленеет, held верный.
- [ ] Коммит — ТОЛЬКО по явному апруву: «поля черновика (saveDraft) + корзина выдачи» одной партией, «held-гейт по подписи + ORM/миграция» другой, «проектор/бланк + фронт» третьей (ориентир, финальная разбивка — по факту).

---

## Итог реализации + рулинги (2026-10-04)

Реализовано автономно, `./run check` зелёный (640 тестов, phpstan L6, cs-fixer). НЕ закоммичено.

- **Task 1 (гейт held по подписи):** `ProfileCompliance::isIssued()` — факт в held/сроки только если его акт `Signed`; запись без документа = прямой факт (считается). Применён в `heldOf` + `recomputeObligation`.
- **Task 2 (saveDraft):** один метод `ProfileCompliance::saveDraft(doc, №, отв, lines, now)` (замена строк корзины + `RequirementDocument::saveDraftDetails`), без скана/подписи/калькулятора.
- **Task 3 (подпись из корзины):** `recordAndSign` переиспользует `saveDraft`, порядок `assertIssuable → saveDraft → markSigned → recompute`. `deleteDraft` уносит строки корзины.
- **Task 3.5 (дефицит-топап):** `topUpDraftFromNorm` (идемпотентно, аддитивно, `max(0, норма − held − в_корзине)`); `DraftFormationService` создаёт черновик и наполняет/дополняет корзину.
- **Task 4 (одна команда):** `SignDraftCommand` → `SaveDraftCommand`; хендлер ветвит по `stagedFileId` (нет → saveDraft, есть → sign). `IssueAction` по кнопке `action=save|sign`.
- **Task 6 (проектор/бланк):** №/ответственный из открытого черновика ?? последнего подписанного.
- **Task 7 (фронт):** кнопка «Сохранить черновик» (`action=save`) рядом со «Скачать бланк» + подсказка потока.

**Рулинги (решено по ходу, можно пересмотреть):**
1. **`replaceDraftLines` убран** — лишний метод; наполнение корзины внутри `saveDraft`, `recordAndSign` зовёт `saveDraft`. Пересчёт в `saveDraft` не нужен (корзина гейтом в held не входит).
2. **Task 5 отменён — `document_id` остался NULLABLE** (не делал NOT NULL+FK). Причины: (а) типы рассинхрон (document_id varchar vs id uuid) — FK требует конверсии колонки + риск Doctrine string↔uuid; (б) NOT NULL ломает законные прямые-факт фикстуры (тест консолидации строит «два акта ниже нормы» — состояние, которое доменный инвариант `assertIssuable` вообще запрещает, т.е. достижимо только прямым конструированием). Мягкий гейт `isIssued` уже даёт корректное поведение; обязательность соблюдается во всех реальных путях домена; сироты чистит `deleteDraft`. Цена ошибки: БД не форсит инвариант (писатель один — домен).
3. **Task 8 (акт списания = корзина) — изменений не потребовалось:** `saveWriteOffAct` (порции на черновик, held не трогает) / `signWriteOffAct` (гасит held только при подписи) / `deleteWriteOffDraft` (каскад) уже зеркалят модель выдачи.
4. **Генерация id дочерних записей в домене** (`topUpDraftFromNorm` → `Uuid::v7()`) — по прецеденту `WriteOffAct::replacePortions`.
