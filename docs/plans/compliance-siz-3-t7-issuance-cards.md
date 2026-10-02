# Деплой 3 / T7: карточки выдачи СИЗ — черновик → оформлен, пересчёт, генерация Excel

> Продолжение `compliance-siz-3-tracking-card.md` (T1–T6 сделаны). Здесь — переработка жизненного
> цикла документа под согласованную с экспертом модель + генерация заполненного Excel-бланка.
> Перекрёстные ссылки: `compliance-siz-2-requirements.md` (норма), `compliance-siz-4-dashboard.md` (дашборд/алерты).

> **СТАТУС (2026-10-01): ВЕСЬ T7 РЕАЛИЗОВАН (Фазы A–E), `./run check` зелёный, НЕ закоммичено. Правки модели
> по ходу: `DraftLine` УБРАН — черновик = МАРКЕР статуса (строки выдачи приходят из формы в `signDraft`);
> статусы «Черновик»/«Подписан»; документ = per-act (несколько во времени). Доступ владельца и «добавить
> позицию из нормы» в форме — ОТЛОЖЕНЫ (YAGNI/follow-up, см. память). Разделы ниже про
> `DraftLine`/`updateDraftLines`/хранение строк на документе — НЕАКТУАЛЬНЫ.**

**Цель:** админ кликает минимум — система сама считает, кому и что надо выдать, формирует черновики,
даёт скачать заполненный Excel, принимает скан и замораживает документ. Продление работает тем же
механизмом.

**Архитектура:** `RequirementDocument` из «одного на (профиль×требование)» становится **актом выдачи**
(их много во времени: первичка + каждое продление). Черновик («создан») хранит предложенные строки
выдачи (jsonb) и правится; «Сохранить» со сканом → факты `FulfillmentRecord` + заморозка документа +
пересчёт сроков. Сборка черновика — из проекции `TrackedObligation` по правилу «статус ≠ ок».
**Формирование черновика — единый гард-сервис, вызываемый из трёх источников:** (1) событийно
(`RequirementChanged` → `RecomputeOnRequirementChangedHandler` после rebuild), (2) кнопкой (ручной
одиночный/пакетный запуск), (3) позже планировщиком Д4 (срок по времени). Гарды «пусто → не создаём» и
«открытый черновик есть → пропускаем» одни и те же для всех источников — дублей/спама нет.
Excel — через Shared `TemplateRendering` (дорабатываем повтор строк в Xlsx-драйвере).

**Tech Stack:** как Д3 + `App\Shared\Infrastructure\Service\{TemplateRendering,XlsxTemplateRenderer}`,
`App\Shared\Domain\Templating\{TemplateFile,RenderData,RepeatValue,TextValue}`, `FileStorage`.

---

## Согласованная модель (источник правды для реализации)

**Флоу админа:**
1. Заводит правило должности (норму) — позиции + количество + периодичность. Всё остальное — система.
2. **«Сформировать карточки»** (на требовании — пакетом по всем сотрудникам должности; или на одном человеке):
   система создаёт **черновики** только тем и только с теми позициями, что реально надо выдать сейчас.
3. Открывает черновик, проверяет, при желании правит количества/даты, **добавляет/убирает позиции**,
   **скачивает заполненный Excel**, печатает, отдаёт на подпись.
4. Получает подписанное, **прикладывает скан**, жмёт **«Оформить»** → документ **заморожен** (финал),
   факты записаны, сроки пересчитаны.
5. Скан может приложить сам сотрудник в своих документах (владелец) — не только админ.

**Как считаем (единица — позиция у человека, не требование целиком):**
- `nextDueAt` по виду периодичности:
  - **Периодически N (мес./год):** `next = дата последней выдачи + N`.
  - **Однократно:** выдал → `next = null`, больше не краснеет.
  - **До износа без предела:** `next = null` (само не краснеет; меняем по факту — админ добавляет руками).
  - **До износа с пределом (новое):** предел-срок задан в норме (напр. 30 мес.); при выдаче в строку
    подставляется **крайняя дата** = дата выдачи + предел (правится, можно очистить). `next = крайняя дата`.
  - **По документам изготовителя:** `next` = дата, введённая при выдаче.
- Статус позиции (на чтении): `next=null` → без срока/ок; `today<next−14д` → ок; `next−14д≤today<next` →
  скоро; `today≥next` → просрочено; ни разу не выдана → «нужно выдать».
- **Что попадает в черновик:** все позиции требования со статусом ≠ ок (ни разу / просрочено / скоро).
  Первый раз → все; продление → только подошедшие. Плюс админ добавляет любую позицию из нормы
  (досрочный износ) и убирает лишнюю. Позиции не в черновике — не трогаем.
- **Не плодим пустых:** перед созданием считаем набор; если пусто (всё ок) — черновик не создаём.
- **Один открытый черновик на (профиль×требование):** если открытый есть — «сформировать» его пропускает
  (не дублирует, не затирает). Подписанных актов — сколько угодно.
- **Сохранение (заморозка):** строки черновика → `FulfillmentRecord`; пересчёт `nextDueAt` этих позиций
  от новой даты; документ → «оформлен», скан приложен, правка закрыта. Непопавшие позиции не меняются.

## Развилки, заложенные в план (подтвердить при ревью плана)

- **A. Документ = акт выдачи (много на пару).** Снимаем UNIQUE `(profile_compliance_id, requirement_id)`
  в `compliance_requirement_document`; инвариант «один ОТКРЫТЫЙ черновик на пару» держим в домене.
  Переделываем заморозку: подписанный акт неизменяем сам по себе, но НЕ морозит будущие выдачи по
  требованию (иначе продление невозможно). *Решение принято с пользователем; тут — технические следствия.*
- **B. Excel с переменным числом позиций → дорабатываем Xlsx-драйвер (повтор строк).** Рекомендую:
  расширить `XlsxTemplateRenderer` поддержкой `RepeatValue` (клонирование строки в PhpSpreadsheet),
  по образцу `DocxTemplateRenderer`. Альтернатива — отдать карточку в .docx (движок уже умеет повтор),
  но пользователь просил Excel. *Это реальный объём в Shared — вынесен в Фазу C, можно шиповать отдельно.*

---

## Изменения модели домена

### 1. Cadence: «до износа» получает предел-срок
`src/Compliance/Domain/ValueObject/Cadence.php` — разрешить `number`+`unit` для `ByFact` как **предел**
(nullable). Пустой предел = чистое «до износа».
- Конструктор: для `ByFact` не обнулять `number`/`unit` (если заданы — хранить; требовать положительное
  number при наличии unit и наоборот — как у Periodic, но полностью опционально).
- `nextDueFrom(base)`: `ByFact` → если `number`+`unit` заданы → `unit->addTo(base, number)`, иначе `null`.
- `label()`: `ByFact` с пределом → «до износа (не более N мес./лет)»; без — «до износа».
- `jsonSerialize`/`fromArray` уже несут `{kind,number,unit}` → DBAL `CadenceType` менять НЕ надо.
- `ObligationDueCalculator::nextDue`: `ByFact` → `manualDueDate ?? cadence->nextDueFrom(lastFulfilledAt)`
  (крайняя дата, введённая при выдаче, приоритетнее расчётного предела; без обоих → null).

### 2. RequirementDocument: черновик со строками + акт
`src/Compliance/Domain/Aggregate/ProfileCompliance/RequirementDocument.php`:
- Хранит **предложенные строки** (jsonb) — список `DraftLine`-VO (новый): `obligationKey`, `label`,
  `?Quantity quantity`, `?Percent wearPercent`, `\DateTimeImmutable issueDate`, `?\DateTimeImmutable limitDate`.
  Нужны, чтобы Excel и «Оформить» были об одном и до заморозки правки сохранялись.
- Методы: `replaceLines(DraftLine ...$lines)` (только Formed), `lines(): array`, `isDraft(): bool`,
  `markSigned(string $scanFileId, now)` (как есть), `assertMutable()` (как есть).
- `DocumentStatus`: значения `Formed`/`Signed` оставляем, `title()` → «Создан» / «Оформлен».
- Новый VO `src/Compliance/Domain/Aggregate/ProfileCompliance/DraftLine.php` (`final readonly`,
  `jsonSerialize`/`fromArray`) + DBAL-тип `compliance_draft_lines` (по образцу `RequirementItemsType`).

### 3. Per-act: много документов на пару
`ProfileCompliance`:
- `openDraftFor(string $requirementId): ?RequirementDocument` — единственный Formed по требованию (инвариант).
- `signedDocumentsFor(string $requirementId): list<RequirementDocument>` — история (для показа/скачивания сканов).
- `documentFor(...)` — переименовать вызовы на `openDraftFor`/`signedDocumentsFor` по месту; старый
  «один на требование» больше не валиден.
- `formDraft(Uuid $id, string $requirementId, DraftLine[] $lines, now)`: если открытый черновик уже есть —
  `AppException` (пропуск делает Application, но домен страхует); создаёт Formed со строками.
- `signDraft(Uuid $documentId, ?string $scanFileId, ObligationDueCalculator $calc, now)`: скан пуст →
  `AppException`; `assertIssuable(lines)`; по строкам `recordFulfillment(...)`; `document->markSigned(...)`;
  `setActiveForRequirement(reqId, true)`.
- `deleteDraft(Uuid $documentId)`: удаляет Formed-документ (orphan-removal).

### 4. Заморозка — переосмыслить
Сейчас `assertRequirementMutable(reqId)` морозит `recordFulfillment`/`putObligation`/… если у требования
есть подписанный документ. В per-act это ломает продление. Новая семантика:
- Заморожен **конкретный подписанный документ** (`RequirementDocument::assertMutable` — остаётся).
- `recordFulfillment` вызывается ТОЛЬКО из `signDraft` (внутренне) → глобальную заморозку по требованию
  **снять**: `putObligation`/`removeObligationByKey`/`recordFulfillment`/`removeRecord` больше не зовут
  `assertRequirementMutable`.
- `ComplianceProjectionRebuilder`: убрать пропуск «требование с подписанным документом» — проекция всегда
  строится из текущей нормы, факты (`FulfillmentRecord`) сохраняются по ключу. История в фактах, не в морозе.

---

## Карта файлов

Создать:
- `src/Compliance/Domain/Aggregate/ProfileCompliance/DraftLine.php`
- `src/Compliance/Infrastructure/Database/DBAL/DraftLinesType.php` (+ регистрация в `doctrine.yaml`)
- `src/Compliance/Application/UseCase/Command/FormDraft/{FormDraftCommand,FormDraftCommandHandler}.php`
- `src/Compliance/Application/UseCase/Command/FormDraftsForRequirement/{Command,Handler}.php` (пакет)
- `src/Compliance/Application/UseCase/Command/UpdateDraft/{Command,Handler}.php`
- `src/Compliance/Application/UseCase/Command/SignDraft/{Command,Handler}.php`
- `src/Compliance/Application/UseCase/Command/DeleteDraft/{Command,Handler}.php`
- `src/Compliance/Application/Service/DraftLineAssembler.php` (сборка DraftLine[] из obligations по «статус ≠ ок»)
- `src/Compliance/Application/Service/RequirementCardProjector.php` (ProfileCompliance+Profile+doc → RenderData)
- `src/Compliance/Application/Service/RequirementCardReadiness.php` (TemplateRendering::validate)
- `src/Compliance/Infrastructure/Controller/Document/{FormDraftAction,FormDraftsForRequirementAction,`
  `UpdateDraftAction,SignDraftAction,DeleteDraftAction,DownloadCardAction}.php`
- `src/Shared/Infrastructure/Templates/documents/compliance/requirement_card_material.xlsx` (базовый шаблон)
- `src/Shared/Infrastructure/Templates/admin/compliance/person/draft.html.twig` (экран проверки — из record.html.twig)

Изменить:
- `src/Compliance/Domain/ValueObject/Cadence.php`, `src/Compliance/Domain/Service/ObligationDueCalculator.php`
- `src/Compliance/Domain/Aggregate/ProfileCompliance/{ProfileCompliance,RequirementDocument,DocumentStatus}.php`
- `src/Compliance/Infrastructure/Database/ORM/Aggregate/ProfileCompliance.RequirementDocument.orm.xml` (+ `lines`)
- `src/Compliance/Application/Service/ComplianceProjectionRebuilder.php` (снять пропуск подписанных)
- `src/Shared/Infrastructure/Service/XlsxTemplateRenderer.php` (повтор строк через RepeatValue)
- Норма: форма/валидатор/маппер требования — поле «не более N» для «до износа»
  (`Infrastructure/.../Requirement` + `req_*` twig/контроллер — уточнить по месту)
- `_person_detail.html.twig`, `_people.html.twig` (действия/бейдж), шаблоны требования (кнопка пакета)
- `app/config/packages/doctrine.yaml` (новый DBAL-тип)

Удалить (мёртвый/заменённый):
- `Application/UseCase/Command/{FormRequirementDocument,FormMissingDocuments,AttachSignedScan,RemoveFulfillment}/**`
- `Infrastructure/Controller/Document/{FormAction,FormMissingAction,AttachScanAction}.php`
- `Infrastructure/Controller/Person/{RebuildAction,ShowAction}.php` (+ роуты); `RebuildProfileCompliance`
  оставить только если нужен — иначе удалить (консоль `app:compliance:rebuild-projection` остаётся).
- Старый `Fulfillment/RecordAction.php` + `RecordIssuance` command/handler — заменяются Draft-флоу
  (NextDueAction остаётся — пригодится в экране проверки для «крайней даты/след. срока»).

---

## Фазы и задачи

### Фаза A — Домен (черновик, per-act, cadence-предел, заморозка)
- **A1. Cadence + Calculator.** Предел для `ByFact`; `nextDueFrom`/`label`/`ObligationDueCalculator`.
  Тесты (unit): `ByFact` без предела → null; `ByFact(30,Month)` → base+30мес; label «до износа (не более 30 мес.)»;
  calculator приоритет `manualDueDate` над расчётным пределом.
- **A2. DraftLine VO + DBAL-тип + ORM.** `DraftLine` round-trip (`fromArray(jsonSerialize())`); DBAL-тип по
  образцу `RequirementItemsType`; колонка `lines` в ORM RequirementDocument; миграция (см. ниже).
- **A3. RequirementDocument: строки + статусы.** `replaceLines`/`lines`/`isDraft`; `DocumentStatus::title`.
  Unit: строки хранятся/читаются; `replaceLines` на Signed → `AppException`.
- **A4. ProfileCompliance: per-act + формирование/подпись/удаление черновика.** `openDraftFor`,
  `signedDocumentsFor`, `formDraft`, `signDraft`, `deleteDraft`; снять `assertRequirementMutable` из мутаторов
  проекции/журнала. Unit: два подписанных акта на пару сосуществуют; второй открытый черновик → `AppException`;
  `signDraft` пишет факты + active=true + пересчёт next; подписанный акт неизменяем, но новый черновик по тому
  же требованию создаётся; удаление черновика.
- **A5. Rebuilder.** Убрать пропуск подписанных; проекция из текущей нормы, факты сохранены. Функц.-тест:
  после подписи и смены нормы проекция корректна, история фактов цела.

### Фаза B — Application/контроллеры (сборка, правка, подпись, пакет)
- **B1. DraftLineAssembler.** Из `TrackedObligation[]` + `ComplianceBucketResolver` собрать DraftLine[] для
  статусов ≠ ок (количество=норма, issueDate=сегодня, limitDate для «до износа с пределом»=сегодня+предел).
  Unit: первичка → все; продление → только подошедшие; пустой набор → пустой список.
- **B1b. DraftFormationService (единый гард-сервис).** Метод `formForProfileRequirement(ProfileCompliance,
  requirementId, now): bool` — зовёт `assembler`; если набор пуст ИЛИ `openDraftFor(requirementId)` есть →
  `false` (пропуск); иначе `formDraft(...)` → `true`. Метод `formForProfile(ProfileCompliance, now): int` —
  цикл по requirementId всех obligations, суммирует созданные. Это ЕДИНАЯ точка создания черновика для всех
  источников (событие/кнопка/Д4). Unit: гарды (пусто/открытый есть), счётчик.
- **B1c. Событийный хук.** В `RecomputeOnRequirementChangedHandler` после `rebuilder->rebuildForRequirement`:
  по каждому затронутому профилю (`GetProfileIdsByPositionsQuery`) → `findByProfile` → `DraftFormationService
  ::formForProfileRequirement`. Так норму завели/поменяли → черновики появились сами (async-воркер). Функц.-тест:
  сохранение требования с назначенной должностью создаёт черновики сотрудникам; повторное сохранение не плодит.
- **B2. FormDraft (один) + FormDraftsForRequirement (пакет) — ручной запуск того же сервиса.** FormDraft:
  `canManage`; `findByProfile` (нет — создать/`rebuildForProfile`); `DraftFormationService::formForProfile
  Requirement`. Пакет: `GetProfileIdsByPositionsQuery(requirement.positionIds)` → цикл; вернуть счётчики
  (создано/пропущено). Функц.-тесты: идемпотентность, «всё ок → 0».
- **B3. UpdateDraft + DeleteDraft.** UpdateDraft: `canManage`/владелец; заменить строки открытого черновика
  (добавить/убрать позицию из нормы, поправить qty/date/wear/limit). DeleteDraft: удалить открытый черновик.
  Shape-парсинг (кол-во/даты/проценты) — в хендлере; бизнес-проверки — домен. Функц.-тесты.
- **B4. SignDraft.** `canManage` ИЛИ владелец профиля (скан вносит сотрудник — см. доступ ниже);
  `assertIssuable` ДО промоута скана; `promote(SignedCard, owner=profileCompliance.id)`; `signDraft` в try/catch
  с откатом файла. Функц.-тесты: мало нормы → ошибка, файл не сгорел; нет скана → ошибка; успех → Signed+факты.
- **B5. Контроллеры + роуты** (per-action, тонкие): FormDraft/FormDraftsForRequirement/UpdateDraft/
  SignDraft/DeleteDraft/DownloadCard. JSON-роуты (next-due уже есть) — помним про `/api` (перенос позже,
  см. feedback_json_routes_under_api). HTTP-смоук рендера экрана проверки.

### Фаза C — Генерация Excel (Shared + проектор)
- **C1. XlsxTemplateRenderer: повтор строк.** Поддержать `RepeatValue`: найти строку-якорь с `{{group.sub}}`,
  клонировать её N раз (`insertNewRowBefore`+копирование стилей), подставить `{{group.sub}}` построчно; плоские
  `{{key}}` как есть. `variables()`/`validate()` учесть повтор-группы. Unit (на хосте): плоский + повтор +
  пустой повтор; стили/формат ячеек сохраняются. Не ломать существующие xlsx-тесты.
- **C2. Базовый шаблон `requirement_card_material.xlsx`.** Идентичность (`{{employee_fio}}`,
  `{{position_title}}`, `{{org_title}}`, `{{department_title}}`, `{{personnel_number?}}`) + таблица позиций
  (`{{items.label}}`, `{{items.basis}}`, `{{items.unit}}`, `{{items.cadence}}`, `{{items.quantity}}`,
  `{{items.issue_date}}`, `{{items.limit_date?}}`) + подписи. Плейсхолдеры задокументировать в
  `docs/plans/report-template-placeholders.md` (раздел «Карточка СИЗ»). Пользователь заменит своим бланком,
  ключи те же.
- **C3. RequirementCardProjector + Readiness.** Из `ProfileCompliance`(открытый черновик) + `ProfileDTO`
  (через `GetProfileByIdsQuery`/`GetProfileQuery`) собрать `RenderData`: скаляры `TextValue`, позиции
  `RepeatValue` из DraftLine[]. Readiness = `TemplateRendering::validate` → список недостающего (не 500).
  Unit/функц.: карточка рендерится из черновика; при неполных данных — осмысленный список, не падение.
- **C4. DownloadCardAction.** GET: взять открытый черновик → `projector` → `rendering->render` → стрим
  xlsx (`Content-Disposition attachment`, имя «Карточка_ФИО_требование.xlsx»). Доступ — `canManage`/владелец.

### Фаза D — UI
- **D1. Экран проверки черновика** `person/draft.html.twig` (из `record.html.twig`): предзаполнен строками
  черновика; правка qty/date/wear/limit (подсветка/next-due — уже готовый `norm-fill`); **добавить позицию**
  из нормы (пикер оставшихся — общий `typeahead` или список), **убрать**; кнопки «Сохранить черновик»
  (UpdateDraft), «Скачать Excel» (DownloadCard), file-upload скана (`staged-file`), «Оформить» (SignDraft),
  «Удалить черновик» (DeleteDraft). `fieldset[disabled]`+баннер, если уже Signed (как edit-lock отчёта).
- **D2. Карточка человека** `_person_detail.html.twig`: по требованию показать статус документа
  (нет / создан / оформлен) и действия под `canEdit`/владелец: «Сформировать черновик» (если есть
  подошедшие и нет открытого), «Открыть черновик», «Скачать скан» (по каждому подписанному акту — история),
  бейдж «ждёт оформления» у открытых черновиков. Стили — копия идиомы списка покрытий, без новых классов.
- **D3. Кнопка пакета на требовании** (`admin/compliance/requirements/...`): «Сформировать карточки всем
  по должности» → FormDraftsForRequirement; flash «создано N, пропущено M». Под `canEdit`.
- **D4. (Опц.) Бейдж в разделе** «N карточек ждут печати/оформления» — счётчик открытых черновиков в скоупе
  доступа (владелец/админ). Если дёшево — сделать; иначе отложить в Д4.

### Фаза E — Доступ, чистка, миграция
- **E1. Доступ «скан вносит сотрудник».** `ComplianceAccessControl`: добавить `canEditOwnProfile(profileId)`
  (`isManager() || currentUserId == owner-профиля`). SignDraft/UpdateDraft/DownloadCard — админ ИЛИ владелец
  профиля; FormDraft(s)/DeleteDraft — `canManage` (админ). `ComplianceFileAccessControl::canView` уже true;
  скачивание скана — владелец/админ. Функц.-тесты доступа (трейт `AuthenticatesActorTrait`).
- **E2. Удаление мёртвого кода** (см. «Удалить»). Грепнуть использования каждого роута/класса перед удалением;
  убрать роуты, шаблонные ссылки. Прогнать `./run check`.
- **E3. Миграция** `Version2026100112xxxx.php` (идемпотентная): `ALTER TABLE compliance_requirement_document
  ADD COLUMN IF NOT EXISTS lines JSONB NOT NULL DEFAULT '[]'`; `DROP INDEX IF EXISTS
  uniq_requirement_document_profile_requirement` (снятие «один на пару»). Накатить на dev+test.

---

## Тесты (зеркалят src/)
- Unit: `CadenceTest` (предел), `ObligationDueCalculatorTest` (ByFact-предел/manualDue), `DraftLineTest`
  (round-trip), `RequirementDocumentTest` (строки/заморозка), `ProfileComplianceTest` (per-act/formDraft/
  signDraft/deleteDraft/снятая заморозка), `XlsxTemplateRendererTest` (повтор строк), `DraftLineAssemblerTest`.
- Функц. (реальная БД): FormDraft/FormDraftsForRequirement (идемпотентность, «всё ок → 0», пакет по должности),
  UpdateDraft/DeleteDraft, SignDraft (мало нормы/нет скана/успех, откат файла), DownloadCard (стрим),
  Rebuilder (после подписи + смена нормы), доступ (владелец vs админ vs чужой), HTTP-смоук экрана проверки.
- Гейты: `./run check` (style/phpstan/unit/functional) в контейнере; тест-БД мигрировать. Фронт (twig/JS/CSS)
  PHP-тестами не покрываем — сборка `yarn dev` + браузер.

## Чего события НЕ покрывают (явно)
- **Новый сотрудник / смена должности.** В Personnel событий нет → Compliance не пересчитывается (дыра из Д3).
  Чтобы новому сотруднику черновик приходил сам, нужно лёгкое событие Personnel `ProfileSaved`(profileId)
  (кидать в Save-хендлере профиля) + обработчик в Compliance → `rebuildForProfile` + `DraftFormationService
  ::formForProfile`. **РАЗВИЛКА: делаем в этом T7 (рекоменд.) или отдельной задачей?** Без него «за админа»
  работает только по норме, по новым людям — нет.
- **Срок продления по времени** (ничего не менялось, просто наступила дата) — не событие. Ловит только
  планировщик Д4 (раз в сутки `today ≥ nextDue` → `DraftFormationService`). До Д4 — ручная кнопка.

## Открытые вопросы (не блок)
- Шаблон NonMaterial-документа (процедуры/инструктажи) — позже, отдельно.
- «До износа + крайняя дата при выдаче» функционально перекрывает «по документам изготовителя» — позже можно
  схлопнуть в один вид периодичности. Сейчас не трогаем.
- Перенос JSON-роутов под `/api` — общий бэклог (feedback_json_routes_under_api).

## Коммиты
По явному апруву пользователя (feedback_no_commits). Партиями по смыслу: A (домен), C (движок+шаблон),
B (команды/контроллеры), D (UI), E (доступ/чистка/миграция).
