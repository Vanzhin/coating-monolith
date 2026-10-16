# Деплой «rekey label→itemId» — дизайн

**Дата:** 2026-10-15
**Контекст:** bounded context Compliance. Продолжает [compliance-journal-model-design.md](compliance-journal-model-design.md) (§51 — стабильный id позиции). Ветка-основа: `feat/compliance-journal-1-foundations` (D1 + журнальный деплой + UX, запушена, НЕ в main). Прод НЕ пуст.

## 1. Цель и зачем

Журнал фактов и проекция обязанностей Compliance идентифицируют обязанность строковым ключом `requirementId|lower(label)`. Это хрупко: переименование позиции нормы (регистр/пробелы/символы) рвёт связь факта с нормой, а символ `/` в наименовании ломал генерацию URL (прод-500, сейчас закрыт хотфиксом query-param). Спека §51: **факт ссылается на `requirementId + itemId`** (стабильный `RequirementItem::id`, забэкфилен в D1), переименование больше не рвёт связь.

**Успех:** факты и проекция ключуются по стабильному id позиции; переименование позиции нормы не рвёт связь факта с нормой; слэш/спецсимволы в наименовании безопасны по построению (ключ = uuid); списание и статусы не затронуты; прод-данные мигрированы без потери held/истории.

## 2. Решение (Подход 1 — rekey «на месте», ключ остаётся композитным)

`obligation_key` остаётся строковой колонкой, но её ЗНАЧЕНИЕ меняется с `requirementId|lower(label)` на `requirementId|itemId`:
- позиция нормы → `itemId` = `RequirementItem::id()`;
- персональная позиция (origin=personal, нет `RequirementItem`) → **единый подход**: ей генерится стабильный id, он хранится на обязанности и используется как суффикс ключа.

Композит сохранён намеренно: вся машинерия ключа (`keyOf`, `keyBelongsToRequirement`-префикс, `requirementIdOf`-парсинг до `|`, фильтрация фактов) работает без изменений; меняется только содержимое суффикса. Слэш-опасность исчезает (itemId — uuid). Цель §51 достигнута.

**Отвергнутые подходы:** expand/contract с отдельной FK-колонкой `item_id` (переписывание всех потребителей + requirementId всё равно нужен отдельно — переинженерия); чистый `itemId` без композита (ломает префикс-машинерию, нужен requirementId отдельной колонкой — больше изменений без выигрыша).

## 3. Дизайн по областям

### 3.1 Модель ключа
- `TrackedObligation`: новое поле `string $itemId`. Норма = `RequirementItem::id()`; персональная = её стабильный id. `key()` = `keyOf(requirementId, itemId)` (было `keyOf(requirementId, label)`). Сигнатура `keyOf(string,string)` без изменений.
- ORM: новая колонка `item_id` (`string(64)`) на `compliance_tracked_obligation`.
- `FulfillmentRecord.obligationKey` — структурно без изменений (строка), хранит `reqId|itemId`.

### 3.2 Путь выдачи (норма) — не меняется
Форма постит `items[i][obligationKey] = o.key`, где `o.key` = `ObligationRowDTO.key` = `TrackedObligation.key()`. Раз `key()` теперь `reqId|itemId`, форма автоматически шлёт itemId-ключ; `IssuanceLineMapper` и `RecordIssuanceCommandHandler` штампуют его на факт verbatim. Правок в этом пути нет.

### 3.3 Персональные позиции (origin=personal)
- `materializePersonalItems` (RecordIssuanceCommandHandler): вместо `keyOf(reqId, label)` — получить/сгенерить стабильный `itemId` персональной обязанности; факту ключ = `keyOf(reqId, itemId)`.
- `addPersonalObligation(...)` принимает и хранит `itemId` на обязанности.
- **Дедуп по label при добавлении сохраняем:** перед созданием ищем существующую персональную обязанность с тем же `lower(label)` в этом требовании → переиспользуем её `itemId` (повторное добавление того же наименования = та же позиция, как сейчас); иначе генерим новый `itemId`.
- Персональный `itemId` стабилен: персональные `TrackedObligation` **не пересоздаются** ребилдером (орфан-чистка трогает только ORIGIN_NORM), поэтому их id/itemId живут до явного prune.
- `pruneOrphanPersonalObligations` — матч фактов по новому ключу (`keyOf(reqId, itemId)`), механика та же.

### 3.4 Проекция / ребилдер
`ComplianceProjectionRebuilder.rebuildProfile`: заменить `keyOf($requirement->getId(), $item->label())` на `keyOf($requirement->getId(), $item->id())`; передать `$item->id()` в `TrackedObligation` как `itemId`. `desired`/`keysWithFacts`/орфан-чистка норм — по новому ключу; факты (после миграции) хранят itemId-ключ → матчатся. Персональные не пересоздаются.

### 3.5 Что НЕ меняется
- `requirementIdOf` / `keyBelongsToRequirement` / `removeIssuanceRecord` — ключ остаётся `requirementId|<suffix>`, requirementId — префикс до `|` (uuid без `|`).
- Списание (`WriteOffAct`/`WriteOffItem`) — завязано на `recordId` факта; rekey меняет только значение `obligation_key`, id фактов сохраняются. `addObligationsToWriteOffDraft` отбирает факты по ключу itemId-формата, консистентно с проекцией.
- Резолверы статуса/срока (`ComplianceStatusResolver`, `ObligationDueCalculator`) — ключ не трогают.

## 4. Миграция + ops

Одна идемпотентная миграция (шаблон `Version20261020120500`: чтение jsonb + guarded update, no-op `down()`):
1. `ALTER TABLE compliance_tracked_obligation ADD COLUMN IF NOT EXISTS item_id VARCHAR(64)`.
2. Персональным обязанностям (origin=personal) с пустым `item_id` — проставить сгенерённый uuid.
3. Построить карту `requirementId → {lower(label) → item.id}` из jsonb `compliance_requirement.items` (id забэкфилен D1).
4. Переписать `compliance_fulfillment_record.obligation_key`:
   - старый ключ парсим на `reqId` + `lower(label)` (префикс до `|` + остаток);
   - матч в карте нормы по `(reqId, lower(label))` → новый `reqId|itemId`;
   - иначе матч персональной обязанности (origin=personal в этом reqId с тем же label) → `reqId|<её itemId>`;
   - **иначе (переименовано/убрано из нормы, нет обязанности) → оставить старый label-ключ** (orphan: held/история/списание целы; это «убрано из нормы» §4.5 — вне контроля, но удерживается).
5. Норм-обязанностям `item_id` в SQL НЕ проставляем — после миграции прогнать `app:compliance:rebuild-projection` (пересоберёт норму с `item.id()`, персональные сохранит с их `item_id`).
6. Пред-проверка на проде перед прогоном: посчитать факты без матча с нормой/персональной (ожидаем ≈0 на 126 фактах / 3 требованиях); отчитаться юзеру.

**Деплой — один, migrate-first** (инит+миграции отдельным гейтом до свопа): запись в compliance редкая/админская, окно гонки «старый код штампует label-ключ» ничтожно; даже при попадании — факт с held не теряется, становится orphan (восстановимо). Expand/contract ради нуль-окна не оправдан.

## 5. Тесты

- `tests/Support/EnrollsComplianceTrait` (центральный): `key` резолвить через `itemId` позиции — грузим требование, берём `item.id()` по label; `issueCard` постит `reqId|itemId`. Чинит почти все функциональные compliance-тесты разом.
- `TrackedObligationTest`: `keyOf` с itemId; `requirementIdOf` остаётся (префикс), его кейсы валидны.
- `ProfileComplianceTest`, `RecordIssuanceTest`, `InstructionIssuanceTest`, `CardDownloadControllerTest`, `ProfileCompliancePersistenceTest`, `GetObligationHistoryQueryTest` — строят ключи через `keyOf(reqId, label)` → перевести на `itemId`.
- `/`-регресс-тесты (`IssueActionTest`, `ObligationHistoryActionTest`): остаются валидны (ключ теперь uuid, слэша в нём нет; query-param хотфикс безвреден). Добавить проверку, что переименование позиции нормы НЕ рвёт связь факта (новый тест: выдать → переименовать позицию → факт/held остаются на той же обязанности).
- Новый юнит-тест миграционного маппера label→itemId (если вынести логику бэкфилла в тестируемый сервис/хелпер).

## 6. Вне охвата (non-goals)

- Expand/contract с FK-колонкой.
- Перенос списания на itemId (оно на recordId — не нужно).
- Ретроактивное «склеивание» уже разошедшихся orphan-фактов с переименованными позициями (невозможно достоверно; будущие переименования безопасны).
- D5 (кросс-персон корзина), вынос проекции, Вариант B — отдельные деплои.

## 7. Риски

- **Orphan-факты от прошлых переименований:** факт, чей label уже не совпал с нормой, остаётся на label-ключе (не сольётся с текущей позицией). Пред-проверка покажет их число; на проде ожидаем ≈0.
- **Окно гонки деплоя:** мид-деплой выдача старым кодом → label-ключ → orphan. Ничтожно (редкая админская запись, migrate-first). Пост-проверкой при желании найти и перекинуть вручную.
- **Смешанный формат ключей после миграции** (itemId у актуальных, label у orphan) — код прозрачен к содержимому суффикса; `requirementIdOf`/префикс работают для обоих.

## 8. Декомпозиция

Один деплой, один план. Ветка — продолжение `feat/compliance-journal-1-foundations` (зависит от стабильного id из D1, который ещё не в main) либо форк от неё; решить при планировании. Детальный план задач — через writing-plans.
