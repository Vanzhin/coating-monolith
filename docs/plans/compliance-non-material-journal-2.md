# Не материальный акт — журналы инструктажей. Деплой 2: захват полей инструктажа + авто-заполнение

> Парный план: [compliance-non-material-journal-1.md](compliance-non-material-journal-1.md) (Деплой 1 — шаблон на требовании + чистка списания). Этот деплой зависит от Д1 (поле шаблона уже есть, UI списания убран).

**Goal:** при оформлении не материального акта система захватывает структурные поля инструктажа (вид, инструктирующий, удостоверение, причина, перечень локальных актов, у пожарного — теор./практ. части) и САМА подставляет их в журнал-документ требования. Полный вариант (а).

**Architecture (вариант 2 — config-first схема):** у не материального требования — `JournalKind` (enum, выбирается в форме требования рядом с шаблоном). `JournalKind` код-первично **объявляет СХЕМУ полей** журнала (упорядоченный список `FieldSpec{key,label,kind,required,options}`). Данные акта хранит один generic VO `InstructionDetails` (упорядоченный `key→value`, плюс list-значения для перечней) в jsonb-поле `FulfillmentRecord.instructionDetails` (null для материальных СИЗ). Форма оформления и проектор идут по схеме обобщённо — новый журнал = новый case + список полей, без новых классов/веток. Повтор строк журнала уже обеспечен записями + `RepeatValue`.

**Tech Stack:** PHP 8.3+, Symfony, Doctrine ORM (XML) + кастомный DBAL jsonb-тип (`AbstractJsonObjectType`), движок шаблонов (`RenderData`/`RepeatValue`/`cloneRow`), PHPUnit. Гейты — `./run check`.

## Global Constraints

- Коммиты — только по явному апруву. VO — `final readonly`, инварианты → `AppException` (рус.).
- Хранение VO в jsonb — наследовать `App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType` (null-safe), регистрировать в `doctrine.yaml`. Builtin Doctrine/Symfony — первый выбор.
- Схема полей журнала — ТОЛЬКО в `JournalKind` (код), не в БД. Новый журнал/поле — правка enum.
- Миграции идемпотентные. Юнит на хосте, функц. в контейнере.
- Бинарные .docx-журналы (пожарный, ОТ) — зона пользователя: авторит по контракту плейсхолдеров из Task 5.

## Review Focus

- **Не материальный, но без journalKind:** оформляется как раньше (без инструктаж-полей) — не падает. Task 1/3.
- **Материальный не затронут:** `instructionDetails` всегда null для СИЗ; форма/проектор СИЗ без изменений. Task 2/3/4.
- **Round-trip jsonb:** `InstructionDetails` сохраняется и читается без потерь, включая list-поля (перечень локальных актов) и пустые значения. Task 2.
- **Схема ↔ форма ↔ документ:** поле, объявленное в `JournalKind`, появляется в форме и попадает в журнал под тем же ключом; снятое из схемы — исчезает везде. Task 3/4.
- **Валидация required:** обязательное поле схемы (напр. вид инструктажа) пустым не проходит подпись акта (AppException), опциональные — проходят. Task 3.

---

## Файловая структура

```
app/src/Compliance/
  Domain/Type/JournalKind.php                      — enum видов журналов + СХЕМА полей (FieldSpec) (НОВОЕ)
  Domain/Type/FieldSpec.php                         — описание поля схемы (key/label/kind/required/options) (НОВОЕ)
  Domain/ValueObject/Instruction/InstructionDetails.php — generic key→value VO (+ list-значения) (НОВОЕ)
  Domain/Aggregate/Requirement/Requirement.php      — +JournalKind $journalKind (nullable)
  Domain/Aggregate/ProfileCompliance/FulfillmentRecord.php — +?InstructionDetails $instructionDetails
  Infrastructure/Database/DBAL/InstructionDetailsType.php   — jsonb DBAL-тип (НОВОЕ)
  Infrastructure/Database/ORM/Aggregate/Requirement.Requirement.orm.xml       — +journal_kind
  Infrastructure/Database/ORM/Aggregate/ProfileCompliance.FulfillmentRecord.orm.xml — +instruction_details (jsonb)
  Application/UseCase/Command/SaveDraft/*            — проброс инструктаж-полей по строкам
  Application/.../IssuanceLine(+Mapper)              — поля строки + парсинг из формы
  Application/Service/RequirementCardProjector.php   — подстановка инструктаж-полей в журнал
app/config/packages/doctrine.yaml                    — регистрация instruction_details тип
app/config/services.yaml                             — journalKind в SaveRequirement (из Д1-формы)
app/src/Shared/Infrastructure/Database/Migrations/Version<ts>.php — +2 колонки
app/src/Shared/Infrastructure/Templates/admin/compliance/{requirements/form.html.twig, person/issue.html.twig} — селектор вида журнала + поля инструктажа по схеме
docs/plans/compliance-non-material-journal-templates.md — контракт плейсхолдеров для .docx (Task 5)
```

---

## Task 1: JournalKind + FieldSpec (схема полей) + поле на требовании

**Files:** Create `Domain/Type/FieldSpec.php`, `Domain/Type/JournalKind.php`; Modify `Requirement.php` (+`?JournalKind $journalKind`, геттер/сеттер), ORM + миграция (`journal_kind VARCHAR(32) NULL`); Test `app/tests/Unit/Compliance/Domain/Type/JournalKindTest.php`.

**Interfaces:**
- `FieldSpec` (`final readonly`): `string $key, string $label, FieldKind $kind` (enum Text/Date/Select/TextList), `bool $required`, `list<string> $options` (для Select). 
- `JournalKind: string` cases `FireSafety='fire_safety'`, `WorkplaceOt='workplace_ot'`. Методы: `label(): string`; `fields(): list<FieldSpec>` — схема:
  - **FireSafety:** instruction_kind (Select: Первичный/Повторный/Внеплановый/Целевой, required), instructor_fio (Text, required), instructor_doc (Text «№ протокола/удостоверения, дата», required), practice_date (Date), practice_instructor_fio (Text), practice_instructor_doc (Text). Подписи — на бумаге, не храним.
  - **WorkplaceOt:** instruction_kind (Select, required), reason (Text «причина — для внепланового/целевого»), instructor_fio (Text, required), instructor_doc (Text «удостоверение/рег.№, дата», required), local_acts (TextList «перечень локальных актов»), birth_date (Date). *Открытый вопрос (решить при авторинге .docx): дата рождения — поле журнала здесь ИЛИ добавить в Personnel\Profile (чище, но трогает другой контекст). По умолчанию — поле схемы.*
- `Requirement::journalKind(): ?JournalKind` / `setJournalKind(?JournalKind)`.

- [ ] Step 1: тест — `JournalKind::FireSafety->fields()` содержит `instruction_kind` (required, 4 опции); `WorkplaceOt->fields()` содержит `local_acts` (TextList). FAIL.
- [ ] Step 2: реализация + поле на Requirement + ORM + миграция (test_db мигрировать).
- [ ] Step 3: тест → PASS.
- [ ] Step 4: Commit: «Compliance: виды журналов инструктажа со схемой полей (config-first) + journalKind на требовании».

## Task 2: InstructionDetails VO + jsonb DBAL-тип + поле на FulfillmentRecord

**Files:** Create `Domain/ValueObject/Instruction/InstructionDetails.php`, `Infrastructure/Database/DBAL/InstructionDetailsType.php`; Modify `FulfillmentRecord.php` (+`?InstructionDetails $instructionDetails`, геттер; проброс в ctor), `ProfileCompliance.FulfillmentRecord.orm.xml` (+`<field name="instructionDetails" column="instruction_details" type="compliance_instruction_details" nullable="true"/>`), `doctrine.yaml` (тип), миграция (`instruction_details JSONB NULL`); Test `app/tests/Unit/Compliance/Domain/ValueObject/Instruction/InstructionDetailsTest.php` + round-trip.

**Interfaces:**
- `InstructionDetails` (`final readonly`, `JsonSerializable`): хранит `array<string, string|list<string>> $values` (ключи = `FieldSpec.key`); `fromArray`, `jsonSerialize`, `get(string $key): string|list<string>|null`, `has(...)`. Пустой набор допустим. Без бизнес-инвариантов (валидацию required делает подпись акта по схеме — Task 3).
- `InstructionDetailsType extends AbstractJsonObjectType`: `convertToPHPValue` → `InstructionDetails::fromArray`, `convertToDatabaseValue` → `parent` (JsonSerializable). Имя типа `compliance_instruction_details`.

- [ ] Step 1: тест — `InstructionDetails::fromArray(['instruction_kind'=>'Повторный','local_acts'=>['A','B']])` → `get` возвращает значения; round-trip через `jsonSerialize`→`fromArray` идемпотентен; пустой VO ок. FAIL.
- [ ] Step 2: реализация VO + DBAL-тип + поле на записи + ORM + doctrine.yaml + миграция.
- [ ] Step 3: тест → PASS; функц. round-trip через репозиторий (сохранить запись с деталями, прочитать).
- [ ] Step 4: Commit: «Compliance: структурные поля инструктажа на факте (InstructionDetails, jsonb)».

## Task 3: Захват полей инструктажа при оформлении

**Files:** Modify `IssuanceLine` (+`?InstructionDetails $instructionDetails`), его mapper (парсинг `instruction[<lineKey>][<fieldKey>]` из формы по схеме journalKind требования), `SaveDraftCommand`/handler (проброс), `ProfileCompliance::saveDraft`/`recordAndSign` (проброс в `FulfillmentRecord`), валидация required при подписи (по `journalKind->fields()` → если required-поле пусто → `AppException`); `issue.html.twig` — для не материальной строки рендерит поля по `journalKind->fields()` (Text/Date/Select/TextList). Test `app/tests/Functional/Compliance/Application/UseCase/InstructionIssuanceTest.php`.

**Interfaces:**
- Consumes: `requirement->journalKind()?->fields()`, `InstructionDetails` (Task 2).
- Produces: подписанный не материальный акт несёт `InstructionDetails` на записи; required-поля схемы провалидированы.

- [ ] Step 1: тест — оформить+подписать не материальный акт (journalKind=WorkplaceOt) с полями (вид, инструктирующий, локальные акты) → запись содержит `InstructionDetails` с этими значениями; пустое required (вид) → AppException; материальный СИЗ-акт без изменений (instructionDetails null). FAIL.
- [ ] Step 2: реализация (форма идёт по схеме; mapper собирает; домен валидирует required при подписи).
- [ ] Step 3: тест → PASS; `cd app && yarn dev` (менялся issue.html.twig — только Twig, вероятно без сборки; при JS — собрать).
- [ ] Step 4: Commit: «Compliance: оформление не материального акта с полями инструктажа (по схеме журнала)».

## Task 4: Авто-заполнение журнала из InstructionDetails

**Files:** Modify `RequirementCardProjector.php` (для не материального источника добавляет в строки `factLog` ключи инструктажа из `record->instructionDetails()` + топ-левел значения при необходимости; TextList (локальные акты) → склейка через перенос строки/«; » в одну ячейку, либо отдельный `RepeatValue` если в шаблоне под это region); Test — расширить `CardDownloadControllerTest` (не материальное требование со своим шаблоном → скачанный журнал содержит значения инструктажа).

**Interfaces:**
- Consumes: `record->instructionDetails()`, `requirement->journalKind()?->fields()`.
- Produces: `factLog`-строки журнала несут ключи `instruction_kind`, `instructor_fio`, `instructor_doc`, `reason`, `local_acts`, `practice_*` и т.д. (плоско, под `{{log.<key>}}`).

- [ ] Step 1: тест — журнал ОТ после оформления содержит вид/инструктирующего/локальные акты в нужных ячейках (проверка по тексту сгенерированного docx). FAIL.
- [ ] Step 2: реализация (обобщённо по схеме: кладём все `FieldSpec.key` записи в строку лога; list → строка).
- [ ] Step 3: тест → PASS.
- [ ] Step 4: Commit: «Compliance: журнал инструктажа заполняется полями из акта автоматически».

## Task 5: Контракт плейсхолдеров + .docx-журналы (зона пользователя)

**Files:** Create `docs/plans/compliance-non-material-journal-templates.md` — для каждого `JournalKind` список доступных плейсхолдеров: топ-левел (`{{employee_fio}}`, `{{position_title}}`, `{{department_title}}`, `{{document_date}}`, `{{card_number}}`, `{{responsible_fio}}`) + строка-регион `{{log}}…{{/log}}` с ключами (`{{log.instruction_kind}}`, `{{log.instructor_fio}}`, `{{log.instructor_doc}}`, `{{log.reason}}`, `{{log.local_acts}}`, `{{log.practice_date}}`, …). Пользователь по контракту авторит `requirement_card_fire.docx` и `requirement_card_ot.docx`, грузит их в соответствующие требования (форма Д1).

- [ ] Step 1: написать контракт-док.
- [ ] Step 2: пользователь авторит два .docx и загружает в требования → ручной смоук скачивания (оба журнала заполнены).
- [ ] Step 3: Commit (контракт-док): «Compliance: контракт плейсхолдеров журналов инструктажа».

## Финал Деплоя 2

- `./run check` зелёный; ручной смоук обоих журналов (пожарный + ОТ) с заполненными полями.
- Деплой-заметка: миграции `journal_kind` + `instruction_details` на проде.
- Открытый вопрос к закрытию при авторинге: дата рождения (поле схемы vs Personnel\Profile).
