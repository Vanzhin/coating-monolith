# Личная карточка СИЗ: стр. 1 = норма, стр. 2+ = факт выдачи

**Цель:** `requirement_card.docx` (личная карточка СИЗ) становится двухчастным документом:
- **Стр. 1 — НОРМА:** весь перечень положенного по требованию (наименование + норм-количество + периодичность + основание), независимо от того, что выдано.
- **Стр. 2+ — ФАКТ:** что выдано ЭТИМ актом — наименование, модель/марка/артикул, дата выдачи, выданное количество, возврат, акт списания.

**Модель домена (зафиксировано с пользователем):** акт = слепок одной выдачи. Сформирован → в него больше не выдаём, новая выдача = новый акт (новый документ). Списание НЕ добавляет строк в факт-таблицу — оно **дозаполняет** колонки «возвращено / акт списания» у строк того акта, чьи позиции списали (джойн по `recordId` при перегенерации документа). Это не сквозной лог.

**Раньше было неверно:** стр. 1 брала позиции из акта-карточки (`$source`) с выданными кол-вами, а не из нормы. Исправляем источник стр. 1 на норму.

**Бинарь `requirement_card.docx` — зона пользователя.** Токены даю, пользователь правит шаблон сам. Миграция НЕ нужна (`compliance_fulfillment_record.note` уже существует, text, nullable).

**Спайка плейсхолдеров (отдано пользователю):**
- Стр. 1: `{{items.label}}`, `{{items.basis}}`, `{{items.quantity}}`, `{{items.unit_cadence}}` — остаются те же токены, но проектор меняет источник на норму.
- Стр. 2 (журнал, cloneRow, все в одной строке таблицы): `{{log.name}}` (наим.), `{{log.model}}` (модель/марка = note), `{{log.issue_date}}`, `{{log.issue_qty}}`, `{{log.return_date}}`, `{{log.return_qty}}`, `{{log.writeoff_act}}`. Колонки «лично/дозатор» и подписи — без токенов (пусто). Пустой журнал → строка-шаблон удаляется, ошибки нет.

## Review Focus
- NonMaterialItem (нематериальная норма, напр. инструктаж) без количества → стр. 1 `quantity` пустая строка, рендер не падает.
- Пустая норма (нет позиций) → строка стр. 1 удаляется (cloneRow).
- Источник стр. 2 = открытый черновик, ещё не подписан → факты есть, возвратов нет (пусто).
- Возвраты берём ТОЛЬКО из подписанных актов списания (черновик списания не показываем как возврат).
- Несколько списаний на одну запись → `return_qty` = сумма, `return_date`/`writeoff_act` = список через «; ».
- `note = null` → `{{log.model}}` пусто.

---

## Задача 1: Протянуть `note` (модель/марка/артикул) через выдачу

**Файлы:**
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/IssuanceLine.php` — добавить `?string $note = null` (последний параметр конструктора, public readonly).
- Modify: `app/src/Compliance/Domain/Aggregate/ProfileCompliance/ProfileCompliance.php` — в `saveDraft` передавать `$line->note` в `FulfillmentRecord` вместо захардкоженного `null` (покрывает и `signDraft` через `recordAndSign`).
- Modify: `app/src/Compliance/Infrastructure/Mapper/IssuanceLineMapper.php` — `fromInput` читает `$row['note']` (trim, пусто → null) → в `IssuanceLine`.
- Modify: `app/src/Compliance/Application/UseCase/Command/SaveDraft/SaveDraftCommand.php` — phpstan-типы `IssuanceInput` и `PersonalInput`: добавить `note?: string`.
- Modify: `app/src/Compliance/Application/UseCase/Command/SaveDraft/SaveDraftCommandHandler.php` — в `materializePersonalItems` прокинуть `note` из `$row['note']` в `IssuanceLine` персональной позиции.
- Modify: `app/src/Compliance/Infrastructure/Controller/Fulfillment/IssueAction.php` — (а) в разбор POST добавить `note` в items и personalItems; (б) в гидрацию корзины добавить `note` per-key (как date/wear/unit), чтобы при перерисовке не терялся → `savedNote`.
- Modify: `app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig` — текстовое поле «Модель / марка / артикул» на позицию (норм-строки и персональные), value из `o.savedNote`/`s.note` при ошибке/перерисовке.

**Шаги (TDD):**
1. Юнит/функц.: сохранить черновик с `note` на позиции → запись имеет note → при перерисовке `savedNote` присутствует. (round-trip)
2. `IssuanceLine`: добавить поле → тест, что `saveDraft` кладёт note в `FulfillmentRecord::note()`.
3. Mapper: `fromInput` читает note → тест round-trip формы.
4. Форма: input + гидрация (визуальная верификация + `yarn dev`).

## Задача 2: Проектор — стр. 1 норма, стр. 2 факт

**Файлы:**
- Modify: `app/src/Compliance/Application/Service/RequirementCardProjector.php`.
- Test: `app/tests/Functional/Compliance/Infrastructure/Controller/CardDownloadControllerTest.php` (или соседний функц.-тест проектора).

**Стр. 1 — `items` из нормы:**
- Источник = `$requirement->getItems()` (ВСЕ позиции требования), не записи акта.
- Поля строки: `label`, `basis`, `quantity` (норм-кол-во: `MaterialItem::quantity()->amount`, для `NonMaterialItem` — пусто), `unit` (норм ед.изм или пусто), `cadence`/`unit_cadence` (из `item->cadence()`).
- Убрать из стр.-1 item'ов `issue_date`/`limit_date` (шаблон их не использует).

**Стр. 2 — `log` (RepeatValue) из ЭТОГО акта:**
- Источник = записи `$source` (`openDraftFor($reqId) ?? latestSigned`), как сейчас у `items` — но в новый список `log`.
- Поля строки:
  - `name` = label обязанности записи (как сейчас резолвится).
  - `model` = `$record->note() ?? ''`.
  - `issue_date` = `fulfilledAt` (д.м.г).
  - `issue_qty` = кол-во записи (trimmed) или пусто.
  - `return_qty` = сумма `WriteOffItem::quantity()` по этой записи из ПОДПИСАННЫХ актов списания.
  - `return_date` = даты этих актов списания (д.м.г, список через «; »), пусто если нет.
  - `writeoff_act` = «№ {actNumber} от {д.м.г}» (список через «; »), пусто если нет.
- Джойн возвратов: пройти `getWriteOffActs()`, только `isSigned()`, собрать `returnsByRecordId[recordId][] = {qty, actNumber, actDate}` (через `act->items()`/`itemsOfWriteOffAct`).
- Сортировка строк журнала — по наименованию (стабильно), в рамках одного акта истории нет.
- Значения: добавить `'items' => RepeatValue(нормаRows)` (переопределён) и `'log' => RepeatValue(фактRows)`.
- `card_number`/`responsible_fio`/шапка сотрудника — без изменений (из `$source`).

**Шаги (TDD):**
1. Функц.-тест: требование с 2 норм-позициями, выдан 1 акт (1 позиция, с note) → стр. 1 = обе норм-позиции с норм-кол-вами; стр. 2 = 1 строка факта с model=note, issue_date/qty, возвраты пусты.
2. Добавить частичное списание (подписанный акт) на запись → стр. 2 этой строки: return_qty=сумма, return_date, writeoff_act заполнены.
3. Переписать проектор под тесты.

## Задача 3: Токены шаблона (зона пользователя)
- Отдать пользователю финальные токены стр. 2 (журнал) — уже отданы.
- Стр. 1 токены те же (`{{items.*}}`), источник сменился — пользователю менять шаблон стр. 1 не нужно (кроме смысла колонки «количество» = теперь норма).
- Пользователь правит `requirement_card.docx`, возвращает — кладём в репо.

## Гейты
- `./run check` (style/phpstan L6/unit/functional) зелёный.
- `cd app && yarn dev` после правки формы (JS/Twig).
- Коммиты — только по явному апруву, одной строкой ≤150, по-русски, без тела/Co-Authored-By.
- Ветка новая от текущего HEAD.
