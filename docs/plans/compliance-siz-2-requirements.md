# Деплой 2: ядро `Compliance` — типы обязанностей, cadence, требования по должности

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development или superpowers:executing-plans. Шаги — чекбоксы.
>
> Соседи: `compliance-siz-1-personnel.md` (нужен: `Position`), `-3-tracking-card.md`, `-4-dashboard-alerts.md`. Спека: `docs/plans/compliance-siz-design.md`. Самодостаточен.

**Цель:** новый bounded-context `Compliance` (generic-ядро) и его первый слой — **справочник норм**: тип обязанности (`ComplianceType`), cadence (включая однократно), количество/ед.изм, агрегат норм со строками `RequirementLine`, редактор. Учёта по человеку/выдачи и дашборда ещё нет (Д3/Д4).

## ФИНАЛЬНАЯ МОДЕЛЬ (пересмотр 2026-09-29, РЕАЛИЗОВАНО — приоритет над T1–T5 ниже, они УСТАРЕЛИ)

Модель переработана в диалоге с заказчиком. Ключевая идея: **тип — на требовании, не на позиции**; позиции однотипны; тип задаётся при создании и неизменяем.

**Агрегат `Requirement`** (`Domain/Aggregate/Requirement/Requirement.php`, extends `Aggregate`):
`{ readonly Uuid $id, string $name, readonly ComplianceType $type, StringCollection $positionIds, RequirementItemInterface[] $items, int $version }`.
- `name` — свободный текст, обязательное (заголовок документа-вьюхи, напр. «Личная карточка учёта выдачи СИЗ»).
- `type` — задан при создании, **не меняется** (нет `changeType`; смена типа на правке → `AppException` в хендлере: «создайте новое требование»).
- `positionIds` — много должностей, **без уникальности** (одна должность входит в сколько угодно требований любого типа; спека уникальности снята).
- `supports(item): bool` = `item->type() === $this->type`. Единственная точка входа позиций `accepted()` (из конструктора и `replaceItems`) держит однотипность через `supports` + запрет дублей по наименованию.

**`ComplianceType`** (`Domain/Type/`): `Material` | `NonMaterial` — два глобальных вида-поведения. `title()` — «Выдача»/«Процедура»; `requiresQuantity()` (Material→true); `makeItem(array): RequirementItemInterface` (единственная точка «тип → класс позиции»). Новый вид = новый case + ветка в `makeItem` + класс позиции; ядро (`Requirement`) не трогаем.

**Периодичность** (`Cadence` VO + `CadenceKind` + `PeriodUnit`, 4 вида — схлопнуто с 6 в диалоге): `Once` (однократно), `Periodic` (каждые N — число + единица `PeriodUnit` месяцы/годы, покрывает «раз в год»=1 год), `ByFact` (по факту/износ, авто-срок не считается), `ByManufacturerDoc` (конкретная дата задаётся при ВЫДАЧЕ — Д3, не в норме). `nextDueFrom` считает только `Periodic`. Форма: поля «Число»+«Единица периода» показываются только для `Periodic` (Stimulus `req-cadence-fields`, по строке). Подписи: «ежегодно», «каждые 2 года», «каждые 3 месяца», «по факту» и т.д. (`PeriodUnit::pluralFor`).

**Позиция — интерфейс + два класса** (`Domain/ValueObject/Item/`):
- `RequirementItemInterface extends \JsonSerializable`: `type(): ComplianceType`, `label()`, `cadence()`, `basis()`. **Тип позиция не хранит полем — заявляет через `type()` из класса.**
- `AbstractRequirementItem` — общие поля (`label`, `cadence`, `basis`) + валидация (непустые label/basis).
- `MaterialItem` — обязателен `Quantity` (без количества не собрать), `type()=Material`.
- `NonMaterialItem` — поля количества нет вообще, `type()=NonMaterial`.

**Хранение** (`Infrastructure/Database/DBAL/RequirementItemsType.php`, jsonb-тип `compliance_requirement_items`): агрегат держит **типизированные** `RequirementItemInterface[]`. Дискриминатор `type` — забота хранения, а не домена: `jsonSerialize()` позиции чистый (label/cadence/basis/quantity, без type), а DBAL-тип на записи добавляет `type` (из `$item->type()`), на чтении по нему собирает класс (`ComplianceType::from(...)->makeItem($row)`). Так полиморфный список round-trip-ится, домен не тащит персист-специфику. **Открытый вопрос (потом подумаем): можно ли убрать дискриминатор из json** (тип уже есть в колонке `type` требования) — упиралось в то, что слепой DBAL-тип не видит соседнюю колонку; варианты (postLoad+shadow / ленивый геттер) сочли переусложнением, оставили дискриминатор.

**Таблица** `compliance_requirement` (миграция `Version20260929150000`, переписана): `id UUID, name, type, position_ids JSONB (+GIN), items JSONB, version`. Прежняя `compliance_requirement_set` снесена в той же миграции.

**Файлы:** Command/Handler/Result `SaveRequirement`; билдер `RequirementItemBuilder` (flat-форма → shape → `type->makeItem`); Query `GetRequirement`/`ListRequirements` + DTO (`RequirementDTO`/`RequirementItemDTO`/`PositionRefDTO`) + `RequirementDTOTransformer`; репозиторий `RequirementRepositoryInterface`/`RequirementRepository` + `RequirementsFilter`; контроллеры `Requirements/{ListAction,EditAction}` (create+{id}/edit, тип залочен на правке); шаблоны `admin/compliance/requirements/{index,form,_macros}.html.twig`; Stimulus `req_type_fields_controller.js` (прячет поля количества по выбранному типу, включая `<template>`); `ComplianceAccessControl`/`PositionTitleResolver` без изменений.

**Гейты:** `./run check` зелёный (style/phpstan L6/unit 1166/functional 604). Round-trip гидрации полиморфных позиций из БД доказан `RequirementUseCasesTest::test_persists_and_hydrates_typed_polymorphic_items`.

**Статус:** написано, `./run check` зелёный, dev+test БД мигрированы. НЕ закоммичено (ждёт апрув). Открытые вопросы к заказчику: (а) дискриминатор в json (см. выше); (б) какие ещё поля у позиции понадобятся Д3 (износ/возврат — там).

---

### УСТАРЕВШЕЕ (модель до пересмотра — оставлено для истории, НЕ реализовывать)

**Архитектура:** обязанность — generic. От типа зависят спец-поля + флаг «полный/лёгкий» + применимые виды cadence + (позже) шаблон документа. Тип — code-enum `ComplianceType` (v1: `Ppe` полный; `SafetyBriefing`, `WorkplaceBriefing`, `Journal` — лёгкие; добавлять кейсы просто). `PositionRequirements` — агрегат на должность, строки — VO `RequirementLine` в jsonb (не запрашиваются построчно). Правила — в VO/агрегате, `AppException`.

**Tech Stack:** как Д1 (PHP/Symfony 8, Doctrine XML+JSONB DBAL, Messenger buses, Stimulus, Twig, PG).

## Global Constraints

- (Все ограничения Д1 действуют: VO `final readonly`+`AppException` RU; правила в домене; хендлеры через marker-interface; id из вне; тонкие per-action контроллеры; `PersonnelAccessControl`-стиль доступа → тут `ComplianceAccessControl`; тесты зеркалят `src/`, функц. с реальной БД + `authenticateAsSystem()`; фронт — сборка+браузер; стили копируем; гейты `./run check` в контейнере; миграции идемпотентные; коммиты по задаче, пуш по апруву.)
- Новый контекст `Compliance`: orm.mappings блок в `doctrine.yaml` (dir `src/Compliance/Infrastructure/Database/ORM/Aggregate`, prefix `App\Compliance\Domain\Aggregate`, alias `Compliance`); роут-блок в `routes.yaml`; dbal.types для VO.
- **Блок-систему Reports НЕ используем.** Поля обязанности фиксированы и типизированы.

## Review Focus

- **Cadence с невалидным N** (`N лет`/`N месяцев`/`до износа` с N≤0 или без N) → `AppException` (тест в T2).
- **`nextDueFrom` для однократной/по-документам** → возвращает `null`, не бросает и не считает мусорную дату (тест в T2).
- **`RequirementLine` типа `Ppe` без количества** (СИЗ обязано иметь кол-во) vs лёгкий тип с количеством (не требует) → правило «полный тип требует Quantity, лёгкий — нет» (тест в T4).
- **Дубликат строки в требованиях** (одинаковый label+type) → либо запрет, либо осознанно разрешено; зафиксировать (тест в T4).
- **Cadence, недопустимая для типа** (напр. `до износа` для инструктажа) — тип объявляет применимые виды; чужой вид → `AppException` (тест в T4).

---

## T1. `ComplianceType` (enum) + контракт типа

**Файлы (новые):**
- `app/src/Compliance/Domain/Type/ComplianceType.php` — `enum ComplianceType: string`. Кейсы v1: `Ppe='ppe'` (СИЗ), `SafetyBriefing='safety_briefing'`, `WorkplaceBriefing='workplace_briefing'`, `Journal='journal'`. Методы: `title(): string` (RU-подпись), `isFull(): bool` (только `Ppe` → true — полный тип с кол-вом/документом/спец-полями; остальные лёгкие), `requiresQuantity(): bool` (= `isFull`), `allowedCadenceKinds(): array<CadenceKind>` (для `Ppe` — все; для `WorkplaceBriefing` — `Once`; для `SafetyBriefing` — периодические+Once; для `Journal` — периодические).
- `app/src/Compliance/Domain/Type/CadenceKind.php` — `enum CadenceKind: string { Once='once'; Annual='annual'; EveryNYears='every_n_years'; EveryNMonths='every_n_months'; UntilWear='until_wear'; ByManufacturerDoc='by_manufacturer_doc'; }` + `title(): string`, `requiresNumber(): bool` (EveryNYears/EveryNMonths/UntilWear → true).

- [ ] Юнит `ComplianceTypeTest`: `isFull`/`requiresQuantity` для каждого кейса, `allowedCadenceKinds` содержит ожидаемое.
- [ ] Коммит.

## T2. VO `Cadence`

**Файл (новый):** `app/src/Compliance/Domain/ValueObject/Cadence.php` — `final readonly class Cadence implements \JsonSerializable`.
- Поля: `CadenceKind $kind`, `?int $number` (для N-видов и `до износа` — макс месяцев).
- Конструктор: если `kind->requiresNumber()` — `number` обязателен и `>0` иначе `AppException('Укажите положительное число для периодичности «…».')`; иначе `number` игнор/null.
- `nextDueFrom(\DateTimeImmutable $base): ?\DateTimeImmutable`:
  - `Annual` → `+1 year`; `EveryNYears` → `+{n} years`; `EveryNMonths` → `+{n} months`; `UntilWear` → `+{n} months` (плановый предел; фактический износ фиксируется отдельно); `Once`/`ByManufacturerDoc` → `null`.
- `isPeriodic(): bool` (не Once/ByManufacturerDoc), `label(): string` (RU: «раз в год», «раз в {n} года/лет», «раз в {n} мес», «до износа (≤{n} мес)», «однократно», «по документам изготовителя»).
- `static fromArray`, `jsonSerialize {kind,number}`.

- [ ] Юнит `CadenceTest`: `Annual.nextDueFrom(2024-01-31)` = 2025-01-31; `EveryNYears(2)` +2 года; `EveryNMonths(30)` +30 мес; `UntilWear(30)` +30 мес; `Once.nextDueFrom` = null; `ByManufacturerDoc` = null; `EveryNYears` без number → AppException; N=0 → AppException; round-trip.
- [ ] Коммит.

## T3. VO `Quantity` (+ ед.изм enum)

**Файлы (новые):**
- `app/src/Compliance/Domain/ValueObject/Unit.php` — `enum Unit: string { Piece='pcs'; Pair='pair'; Set='set'; Milliliter='ml'; Gram='g'; }` + `title(): string` (RU: «шт.», «пара», «комплект», «мл», «г»). (Набор из образцов; расширяемо.)
- `app/src/Compliance/Domain/ValueObject/Quantity.php` — `final readonly class Quantity implements \JsonSerializable`: `__construct(float $amount, Unit $unit)`; `amount>0` иначе `AppException('Количество должно быть положительным.')`. `label(): string` («144 шт.»), `fromArray`, `jsonSerialize {amount,unit}`.

- [ ] Юнит `QuantityTest`: валидное, amount≤0 → AppException, label, round-trip; `UnitTest.title`.
- [ ] Коммит.

## T4. Агрегат `PositionRequirements` + `RequirementLine`

**Файлы (новые):**
- `app/src/Compliance/Domain/ValueObject/RequirementLine.php` — `final readonly class RequirementLine implements \JsonSerializable`: `string $label`, `ComplianceType $type`, `Cadence $cadence`, `?string $basis`, `?Quantity $quantity`. Инварианты в конструкторе: label непусто; `type->requiresQuantity()` ⇒ `quantity !== null` (иначе `AppException('Для СИЗ укажите количество.')`), лёгкий тип ⇒ quantity должно быть null (иначе `AppException`); `cadence->kind` ∈ `type->allowedCadenceKinds()` иначе `AppException('Периодичность «…» недопустима для типа «…».')`. `fromArray`, `jsonSerialize`.
- `app/src/Compliance/Domain/Aggregate/PositionRequirements/PositionRequirements.php` — `class PositionRequirements extends Aggregate`. Поля: `Uuid $id`, `string $positionId`, `RequirementLine[] $lines` (в jsonb), `int $version`. Один агрегат на `positionId` (спека уникальности `UniquePositionRequirementsSpecification`). Методы: `replaceLines(RequirementLine ...$lines)` (вариадик — тип виден в сигнатуре; запрет дублей label+type внутри → `AppException('Дублирующая строка требований.')`), `getLines()`. id из вне.
- `app/src/Compliance/Domain/Aggregate/PositionRequirements/Specification/{...,UniquePositionRequirementsSpecification}.php`.
- `app/src/Compliance/Domain/Repository/PositionRequirementsRepositoryInterface.php` — `add`, `findByPositionId(string): ?PositionRequirements`, `findByPositionIds(StringCollection): array` (для Д3-снапшота).
- `app/src/Compliance/Infrastructure/Repository/PositionRequirementsRepository.php`.
- `app/src/Compliance/Infrastructure/Database/DBAL/RequirementLinesType.php` — DBAL-тип `compliance_requirement_lines` (jsonb-массив `RequirementLine`), наследует `AbstractJsonObjectType` (или сериализует список). Регистрация в `doctrine.yaml`.
- ORM `PositionRequirements.PositionRequirements.orm.xml` — таблица `compliance_position_requirements`, `position_id` unique, `lines` type `compliance_requirement_lines`, `version` version="true".

- [ ] Миграция: `CREATE TABLE IF NOT EXISTS compliance_position_requirements (id VARCHAR(36) PRIMARY KEY, position_id VARCHAR(36) NOT NULL, lines JSONB NOT NULL, version INT NOT NULL DEFAULT 1); CREATE UNIQUE INDEX IF NOT EXISTS uniq_compliance_posreq_position ON compliance_position_requirements (position_id);`
- [ ] Юнит `RequirementLineTest`: Ppe без quantity → AppException; лёгкий с quantity → AppException; cadence не из allowed → AppException; валидная строка; round-trip.
- [ ] Функц. тест агрегата/хендлера (T5): replaceLines с дублем → AppException; сохранение/чтение по positionId.
- [ ] Коммит.

## T5. Команды/хендлеры + редактор требований по должности (UI)

**Файлы (новые):**
- `app/src/Compliance/Application/Service/RequirementLineBuilder.php` — собирает `RequirementLine[]` из плоского входа формы (shape→VO; НЕ носитель бизнес-правил, правила в VO/агрегате). Вариадик/`StringCollection` где уместно.
- Command `SavePositionRequirementsCommand(positionId, lines[])` + Handler (`implements CommandHandlerInterface`, `ComplianceAccessControl::canManage()`): найти-или-создать `PositionRequirements` по positionId → `replaceLines(...builder->build(...))` → сохранить. AppException из домена ловится контроллером.
- Query `GetPositionRequirements(positionId): PositionRequirementsDTO` (строки как вложенные DTO, без array-shape).
- Контроллер `app/src/Compliance/Infrastructure/Controller/Requirements/{EditAction,SaveAction}.php` — тонкие. `EditAction` GET: должность (из URL/typeahead) → форма-таблица строк. `SaveAction` POST: `content` строк → `SavePositionRequirementsCommand`; на `AppException` — inline-рендер с сообщением+сохранённым вводом (паттерн `FillAction` Reports).
- Роут-блок `compliance` в `routes.yaml` (если ещё не добавлен). Алиасы репозиториев в `services.yaml`.
- `ComplianceAccessControl` — `app/src/Compliance/Application/Service/AccessControl/ComplianceAccessControl.php` (`canManage(): bool` над `AccessGuard::isManager()`).
- Шаблон редактора требований — табличный UI строк (наименование, тип-select, cadence-select+число, ед.изм-select+кол-во, основание). UX AJAX-строк копировать с ближайшего аналога (редактор слоёв системы покрытий / list-rows отчёта). Поле «кол-во» показывать только для типа `Ppe` (JS по выбору типа); поле «число cadence» — только для видов, где `requiresNumber`. Валидность — на сервере (домен), JS — только UX-подсказка.

- [ ] Функц. тесты: save валидных требований (СИЗ+инструктаж), Ppe без кол-ва → AppException, недопустимая cadence → AppException, дубль → AppException; чтение через query.
- [ ] `yarn dev` + браузер: собрать норму должности из образца (пара СИЗ-строк с «пар в год»/«шт. на 2 года» + однократный инструктаж на рабочем месте), сохранить, перечитать.
- [ ] Коммит.

## Финал Д2

- [ ] `./run check` зелёный; тест-БД мигрирована; cleanup style/phpstan.
- [ ] `yarn dev` + браузерный смоук редактора требований.
- [ ] Нет `dd()`/`var_dump`/мёртвого кода; `.DS_Store` не в staged.
- [ ] Коммиты партиями (типы+cadence / Quantity / агрегат+DBAL / редактор). Пуш/мерж — по апруву.
- [ ] Открытый вопрос к пользователю (не блокирует): финальная таксономия лёгких типов и набор `Unit` — подтвердить/расширить перечень из образцов.
