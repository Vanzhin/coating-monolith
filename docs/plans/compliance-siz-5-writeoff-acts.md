# Деплой 5 (Compliance): списание/аннулирование позиций + акт списания СИЗ

> Отдельный деплой поверх Д3/T7 (карточки выдачи). Самодостаточный. Перекрёстные ссылки:
> `compliance-siz-3-t7-issuance-cards.md` (акт выдачи = `RequirementDocument`, статусы Черновик/Подписан, per-act),
> `compliance-siz-4-dashboard.md` (дашборд/алерты).

> **СТАТУС (2026-10-02): РЕАЛИЗОВАНО, `./run check` зелёный, НЕ закоммичено. Отложено: батч-акт на
> многих, частичное списание кол-ва, дефолт-комиссия, доступ владельца, list-rows для >2 членов.**
>
> **ПЕРЕСМОТР МОДЕЛИ (2026-10-02, по требованию заказчика): отдельной «страницы акта» НЕТ.** Списание встроено
> в ТУ ЖЕ карточку требования (`IssueAction`/`issue.html.twig`): пока открыт черновик — режим ОФОРМЛЕНИЕ; когда
> есть действующая (подписанная) карточка — режим СПИСАНИЕ (поля позиций заблокированы, показывают выданное,
> чекбокс на позицию + причина/дата + «Списать выбранное»). Списание теперь **по требованию** (`requirementId`),
> не по конкретному акту выдачи: `FulfillmentRecord.documentId` УБРАН, `WriteOffAct.sourceDocumentId`→`requirementId`.
> **Доменный инвариант:** `ProfileCompliance::writeOff` бросает `AppException`, если по требованию нет ни одной
> подписанной карточки — списать можно только из действующей, не из черновика. Снесены `Act\ShowAction`/
> `Act\WriteOffAction` и `admin/compliance/act/show.html.twig`; POST-списание — `Fulfillment\WriteOffAction`
> (`/person/{profileId}/requirement/{requirementId}/write-off`).

**Цель:** админ заходит в подписанный **акт выдачи**, списывает/аннулирует позицию (или несколько сразу);
материальная позиция → формируется **акт списания** (.docx, по образцу коллег), нематериальная → документ не
нужен. После списания позиция снова становится «нужно выдать» → по норме сам появляется черновик нового акта.

**Архитектура (минимум, переиспользуем существующее):** списание = пометка текущей выдачи позиции
«возвращённой» через УЖЕ существующие (но не используемые) поля `FulfillmentRecord.returnedAt/returnedQuantity`
+ причина (`note`/enum). `recomputeObligation` научить пропускать списанные факты → позиция теряет текущую
выдачу → `DraftFormationService` (Д3) сам заводит черновик. Акт списания — новый документ-артефакт с тем же
жизненным циклом и генерацией, что карточка выдачи (docx-движок + File-backbone). Комиссия — профили
организации сотрудника.

**Tech Stack:** как Д3 + docx-драйвер движка (`DocxTemplateRenderer`, повтор строк уже есть), File-backbone.

## Global Constraints
- Не усложнять: списание ложится на существующие `returnedAt`/recompute/`DraftFormationService`; новый код — только
  там, где без него нельзя (акт списания как документ, причина-enum, привязка факта к акту, UI).
- По конвенции проекта: домен-инварианты в агрегате; команды через `CommandHandlerInterface`; доступ —
  `ComplianceAccessControl` (пока `canManage`); поиск профилей — `findByFilter`; фронт — общий `typeahead`;
  docx-шаблон в `Infrastructure/Resources/templates`, путь — bind в services.yaml.
- Миграции идемпотентные (прод пуст — без бэкфилла, но деплой-гейт не должен падать).
- Коммиты — по явному апруву (feedback_no_commits).

---

## Согласованная модель (ответы заказчика)
1. Админ заходит в **акт выдачи** (подписанный `RequirementDocument`) и списывает позицию(и) из него.
2. Акт списания — **документ** со своим циклом (черновик → подписан-скан), как карточка выдачи.
3. **Один акт списания = один человек**, позиций может быть несколько (из одного акта выдачи, за одно действие).
4. **Комиссия** — выбор из профилей организации, к которой приписан профиль сотрудника (должность/ФИО из профиля);
   дефолтные значения — позже.
5. **Причина** списания — enum (физ. износ / окончание нормативного срока / …).

## Техническое следствие (п.1): привязка факта к акту выдачи
Сейчас `FulfillmentRecord` не знает, каким актом выдан (ключ только `obligationKey`). Чтобы «зайти в акт и
списать его позиции», добавляем `FulfillmentRecord.documentId` (uuid акта выдачи), проставляется в `signDraft`.
«Позиции акта X» = не-списанные факты с `documentId = X`.

---

## Файлы

Создать:
- `src/Compliance/Domain/Type/WriteOffReason.php` — enum (`PhysicalWear`='физический износ', `TermExpired`=
  'окончание нормативного срока эксплуатации', …) + `title()`.
- `src/Compliance/Domain/Aggregate/ProfileCompliance/WriteOffAct.php` — дочерняя сущность (как `RequirementDocument`):
  `Uuid id`, root, `string sourceDocumentId` (акт выдачи), `DocumentStatus status` (черновик/подписан),
  `WriteOffReason reason`, `?string scanFileId`, `?signedAt`, `createdAt/updatedAt`, строки списания (jsonb) —
  снимок {label, quantity, issueDate} по каждой списанной позиции (для docx и истории). + ORM xml + DBAL-тип
  строк (по образцу — но лучше VO-обёртка `WriteOffLines`, как `DryingTimeSeries`/`AbstractJsonObjectType`).
- `src/Compliance/Domain/ValueObject/WriteOffCommission.php` — VO комиссии: представитель ОТ и ПБ +
  члены[] (каждый: должность + ФИО, снимок из профиля). Хранить снимком на акте (не ссылками — должности/ФИО
  на момент списания). jsonb.
- Команды: `Command/WriteOffPositions/{Command,Handler}` (profileId, sourceDocumentId, obligationKeys[], reason,
  date), `Command/SignWriteOffAct/{Command,Handler}` (profileId, writeOffActId, commission, stagedFileId).
- `Application/Service/WriteOffActProjector.php` — RenderData из `WriteOffAct` + профиль + комиссия (плоская
  таблица: группировка по человеку одна, строки позиций; шапка — №/дата/приказ/комиссия).
- Контроллеры: `Infrastructure/Controller/Act/ShowAction.php` (GET — страница акта выдачи: его позиции + форма
  списания с мультивыбором), `Act/WriteOffAction.php` (POST → WriteOffPositions), `WriteOffAct/SignAction.php`
  (GET форма подписания акта списания + POST SignWriteOffAct), `WriteOffAct/DownloadAction.php` (GET .docx),
  `WriteOffAct/DownloadScanAction.php` (GET скан).
- `src/Compliance/Infrastructure/Resources/templates/writeoff_act.docx` — шаблон (из `Акт списания СИЗ.DOCX`
  коллег, проставить `{{плейсхолдеры}}` + повтор `{{items.*}}`: `act_number`, `act_date`, `order_number`,
  `order_date`, `employee_fio`, комиссия `ot_pb_rep`/`members` (повтор), `items.{name,qty,issue_date,reason}`,
  подписи).
- Шаблоны: `admin/compliance/act/show.html.twig` (акт выдачи + списание), `.../writeoff/sign.html.twig`.
- Конфиг комиссии — ПОКА нет (выбор при подписании); дефолты — follow-up.

Изменить:
- `FulfillmentRecord.php` + ORM — добавить `documentId` (uuid, nullable) + `markReturned(returnedAt, ?quantity, note)`
  (сейчас returnedAt только в конструкторе). + `isReturned(): bool`.
- `ProfileCompliance.php`:
  - `recordAndSign`/`signDraft` — проставлять `documentId` факту (id акта выдачи).
  - `recomputeObligation` — пропускать `isReturned()` факты при выборе последнего → позиция «освобождается».
  - `writeOff(string $sourceDocumentId, StringCollection $obligationKeys, WriteOffReason $reason, \DateTimeImmutable $at, ObligationDueCalculator $calc): void` — по каждому ключу найти не-списанный факт этого акта, `markReturned`, recompute. Если среди списываемых есть материальные — создать `WriteOffAct` (черновик) со снимком строк; нематериальные — без акта.
  - `signWriteOffAct(writeOffActId, WriteOffCommission, scanFileId, now)` — пометить акт списания подписанным.
  - геттеры `writeOffActs()`, `factsOfDocument(sourceDocumentId): list<FulfillmentRecord>` (позиции акта выдачи, не-списанные).
- `ComplianceProjectionRebuilder` — не трогаем (возврат живёт в фактах, recompute сам учтёт).
- Read-model карточки/дашборда — при желании показать «списано»/акт списания в истории (минимально).
- services.yaml — bind пути `writeoff_act.docx`.
- Миграция — `FulfillmentRecord.document_id` колонка + таблица `compliance_write_off_act`.

## Поток (как это выглядит)
1. В карточке человека у подписанного акта выдачи — «Открыть акт» → `Act/ShowAction`: список выданных позиций акта.
2. Админ отмечает позиции (мультивыбор) + причину (enum) → «Списать».
3. `WriteOffPositions`: факты помечаются returned; материальные → создаётся `WriteOffAct` (черновик). Позиции
   освобождаются → `DraftFormationService` заводит черновик нового акта выдачи по норме (как продление).
4. Для акта списания: «Скачать .docx» (из черновика) → печать → подпись комиссией → приложить скан + выбрать
   комиссию (профили организации) → «Подписать» (`SignWriteOffAct`) → акт списания заморожен, скан прикреплён,
   виден в карточке.
5. Нематериальные списанные позиции — просто освободились (черновик), акта нет.

## Задачи (TDD, по шагам)
- **T1.** `WriteOffReason` enum + unit.
- **T2.** `FulfillmentRecord.documentId` + `markReturned`/`isReturned` + ORM + миграция колонки; `signDraft`
  проставляет documentId. Unit (round-trip, markReturned идемпотентность) + функц. (выдача пишет documentId).
- **T3.** `recomputeObligation` пропускает returned; `ProfileCompliance::writeOff` (материальные→WriteOffAct,
  нематериальные→только возврат) + `factsOfDocument`. Unit: списал позицию → освободилась → bucket «нужно
  выдать»; материальная → WriteOffAct создан (черновик) со снимком; нематериальная → акта нет.
- **T4.** `WriteOffAct` сущность + `WriteOffLines`/`WriteOffCommission` VO + DBAL + ORM + миграция таблицы.
- **T5.** Команды `WriteOffPositions` + `SignWriteOffAct` (+ промоут скана, File-backbone) + функц.-тесты;
  после списания — формирование черновика (DraftFormationService).
- **T6.** docx-шаблон `writeoff_act.docx` + `WriteOffActProjector` + `DownloadAction` (стрим docx) + функц.
  (рендерится, плейсхолдеры подставлены, повтор позиций).
- **T7.** UI: `Act/ShowAction` (позиции акта + форма списания мультивыбор+причина), `WriteOffAct/SignAction`
  (комиссия = `typeahead` по профилям организации + скан), кнопки в карточке человека; HTTP-смоук.
- **T8.** Доступ (`canManage` пока), чистка, `./run check`, миграция dev/test-БД.

## Открытые / отложенные
- **Дефолтные значения комиссии** (приказ №/дата, фикс-состав) — конфиг организации, follow-up.
- Батч-акт списания на многих людей (как исходные примеры) — пока один человек/действие; батч — потом.
- Доступ владельца — вместе с кабинетом сотрудника (см. T7-план).
- «Частичное» списание количества (returnedQuantity < выданного) — пока списываем позицию целиком; частичное — потом.
