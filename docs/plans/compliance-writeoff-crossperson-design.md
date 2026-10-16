# Акт списания СИЗ — кросс-персональный агрегат (D5) — дизайн

**Дата:** 2026-10-16
**Контекст:** Compliance. Продолжает [compliance-journal-model-design.md](compliance-journal-model-design.md) (§3.5, §4.3, §9 Д5). Форк от `main` (журнал+rekey+due-soon уже влиты). Прод НЕ пуст (3 акта / 8 позиций списания на per-person FK).
**Мокап нового вида акта:** https://claude.ai/code/artifact/e64b51e8-09ba-4a61-85b0-faf1841d5e8b

## 1. Цель и зачем

Сейчас `WriteOffAct`/`WriteOffItem` — дети агрегата `ProfileCompliance` (FK `profile_compliance_id`), то есть акт списания привязан к ОДНОМУ человеку. По требованию экспертов (спека §3.5 решение 5) акт списания — документооборот, в одном акте списываются позиции у РАЗНЫХ людей одной организации по одному требованию. Выносим `WriteOffAct` в самостоятельный **кросс-персональный** агрегат.

**Успех:** один акт списания охватывает позиции нескольких людей (одна организация × одно требование); подпись синхронно гасит факты у всех затронутых людей; прод-данные мигрированы без потери ссылок на факты (`recordId`) и реквизитов; выдача (журнал, на человека) не затронута; списание инвентарных материальных позиций работает как раньше, но кросс-персон.

## 2. Зафиксированные решения (с заказчиком)

1. Делаем сразу ПОЛНЫЙ кросс-персон (без промежуточного per-person-этапа — он выкидышный). Размер глушим декомпозицией на SDD-задачи, не на деплои.
2. **Корзина = требование × организация.** Организация = `organizationId` контрагента из профиля человека (`ProfileDTO.organizationId`). Открытый черновик уникален по паре (requirementId, organizationId). Добавить в акт можно только профиль той же организации и того же требования.
3. **Строка акта** = `{id, profileId, recordId, labelSnapshot, quantity, reason}`. `labelSnapshot` — снимок наименования позиции на момент добавления (чтобы кросс-персон акт рендерился, не лазая в проекцию каждого человека). Частичное списание по количеству (аккумулятор на факте — `FulfillmentRecord::returnedQuantity`, как сейчас). Только инвентарные материальные позиции (held>0).
4. **Наполнение корзины — с карточки ВЫДАЧИ человека** (выдача не меняется, остаётся на человека): «Списать выбранные» (отмеченные held-позиции) и «Списать всё» (ВСЕ инвентарные held>0 позиции этого человека по требованию) → кладут линии в черновик (требование × орг человека), создавая если нет.
5. **Страница акта — линии сгруппированы по людям** (ФИО · должность → его позиции с held, кол-во, причина). «Списать всё» на странице акта = заполнить кол-во = held по всем линиям. Реквизиты/комиссия/скан/подпись — как сейчас.
6. **Apply-on-sign — синхронно, fan-out** (Вариант A): при подписи грузим все затронутые `ProfileCompliance`, гасим их факты, сохраняем, пересчёт по каждой паре (profileId, requirementId), одной транзакцией.
7. **Каскад при удалении факта** — через доменное событие `FulfillmentRecordRemoved(profileId, recordId)` + синхронный Application-хендлер, снимающий линии из черновиков и удаляющий опустевшие (кросс-агрегатно).
8. **Комиссия/представитель — остаются свободным текстом** (Shared `Commission` VO), как сейчас. Переход на `profileId`+снимок+typeahead — ОТДЕЛЬНЫЙ поздний follow-up, не в этом деплое.
9. **Миграция:** 3 акта/8 позиций с per-person FK в самостоятельную таблицу; совпавшие по (требование, орг) черновики — слить в один.
10. **.docx-шаблон акта** перепиливает заказчик сам, в последнюю очередь; проектор отдаёт данные сгруппированно по людям.
11. **Список актов списания** — отдельная фильтруемая страница (как Coatings, через `findByFilter`), **кросс-организационная для админа** (видит акты всех организаций; фасет «Организация» фильтрует). Создание актов — по-прежнему с карточек выдачи, не из списка.

## 3. Дизайн по областям

### 3.1 Новый агрегат `WriteOffAct` (`Compliance/Domain/Aggregate/WriteOffAct/`)
Корень `WriteOffAct` (отдельный от `ProfileCompliance`):
- Поля: `Uuid id`, `string requirementId`, `string organizationId`, `DocumentStatus status`, `WriteOffActLine[] lines`, `?Commission commission`, реквизиты (`actNumber`, `actDate`, `orderNumber`, `orderDate`, `representativePosition`, `representativeFio`), `?scanFileId`, `?signedAt`, `createdAt`, `updatedAt`, `int version` (оптимистичная блокировка).
- `WriteOffActLine` (entity агрегата): `{Uuid id, string profileId, string recordId, string labelSnapshot, float quantity, ?WriteOffReason reason}`.
- Методы: конструктор `(Uuid, requirementId, organizationId, now)`; `addLinesForProfile(string $profileId, list<array{recordId,labelSnapshot,quantity}> $lines)` (дедуп по recordId; только в Draft); `replacePortions(list<array{recordId,quantity,reason}>)` (set-семантика правки на странице акта); `saveDetails(...)` (реквизиты+комиссия); `sign(Commission, actNumber, actDate, scanFileId, now)` (валидирует причины/непустую комиссию/скан, замораживает в Signed — НЕ применяет к фактам, применение оркеструет Application); `removeLinesByRecordIds(list<string>): void`; `linesByProfile(): array<string,list<WriteOffActLine>>`; `profileIds(): list<string>`; `isDraft/isSigned/assertMutable`; геттеры.
- **Инвариант неизменяемости** и `qty ≤ held` — held живёт в `ProfileCompliance`, поэтому проверка `qty ≤ held` на этапе наполнения/правки делается в Application-хендлере (он держит и акт, и затронутый ProfileCompliance), доменно на акте остаётся `quantity > 0` и неизменяемость подписанного. (Это осознанно: held — не свойство акта.)

### 3.2 Репозиторий `WriteOffActRepositoryInterface` (+ Doctrine)
- `findOpenDraft(string $requirementId, string $organizationId): ?WriteOffAct`
- `findById(string $actId): ?WriteOffAct`
- `add(WriteOffAct $act): void`
- `remove(WriteOffAct $act): void`
- `findDraftsContainingRecord(string $recordId): list<WriteOffAct>` (для каскада удаления факта)
- `findByFilter(WriteOffActFilter $filter): PaginationResult` — ЕДИНЫЙ метод листинга/поиска (конвенция проекта, как `CoatingRepository::findByFilter`). Никаких `suggest()`/`findByX()` сверх этого.

### 3.3 Наполнение корзины (с карточки выдачи)
`AddToWriteOffDraftCommand(profileId, requirementId, obligationKeys|__all__)` → хендлер:
- `canManage` гейт; грузит `ProfileCompliance` человека; org = профиль человека (`GetProfileQuery.organizationId`).
- `findOpenDraft(requirementId, org)` или создать новый `WriteOffAct`.
- Отобрать held-факты человека по выбранным позициям (или все инвентарные held>0 при `__all__`); для каждого — линия `{profileId, recordId, labelSnapshot=obligationLabelOf(key), held, reason=null}`; `act.addLinesForProfile(profileId, …)` (дедуп по recordId).
- Инвариант: `requirementId` акта == требование; `organizationId` акта == орг профиля (иначе новый черновик по своей паре).
- save акта. Возврат на карточку.

### 3.4 Страница акта (`ShowAction`, кросс-персон)
- GET: грузит акт по `findById`; строит вью группами по `profileId` — для каждого ФИО (batch `GetProfilesByIds`), под ним линии с held (held берём из `ProfileCompliance` владельца по `recordId`), полями кол-ва/причины; реквизиты/комиссия/скан.
- POST `op=save`: `SaveWriteOffActCommand(actId, lines[], реквизиты, members)` → правка (replacePortions + saveDetails); `op=sign`: далее `SignWriteOffActCommand`.
- Маршрут по акту: `/cabinet/compliance/writeoff/{actId}` (убираем `{profileId}` из пути акта — акт больше не на человека; доступ — `canManage`).

### 3.5 Apply-on-sign (fan-out)
`SignWriteOffActCommandHandler`:
- грузит акт (`WriteOffActRepository`); `canManage`; промоут скана (`FileStorage::promote(WriteOffActScan, ownerId=actId)` — владелец скана теперь акт, не ProfileCompliance);
- `act.sign(commission, actNumber, actDate, scanFileId, now)` — валидирует/замораживает;
- `foreach act.linesByProfile() as profileId => lines`: грузит `ProfileCompliance` → `applyWriteOff(recordId→Σqty)` (гасит факты, `addReturnedQuantity`) → save;
- save акта; событие пересчёта на каждую пару (profileId, requirementId) — `WriteOffActSigned` расширяем до списка пар, либо шлём по одной в цикле; хендлер пересчёта (`ComplianceProjectionRebuilder`) как есть.
- Всё в одной транзакции (`doctrine_transaction` middleware команды). `OptimisticLockException` → мягко (как в текущих экшенах).

### 3.6 Каскад удаления факта (кросс-агрегат)
- `ProfileCompliance::removeIssuanceRecord` больше не трогает акты (их нет в агрегате) — вместо локального detach **эмитит доменное событие** `FulfillmentRecordRemoved(profileId, recordId)`.
- Синхронный Application-хендлер: `WriteOffActRepository::findDraftsContainingRecord(recordId)` → `act.removeLinesByRecordIds([recordId])`; если акт опустел — `remove` (собрать `scanFileId` на чистку, хотя у черновика скана обычно нет). Подписанные акты не трогаем (заморожены; факт с held под подписанным списанием и так не удаляется — инвариант сохранить).
- В одной транзакции с удалением факта.

### 3.7 Что уходит с `ProfileCompliance`
Уходят: коллекция `writeOffActs` + методы `startOrGetWriteOffDraft`, `addObligationsToWriteOffDraft`, `saveWriteOffAct`, `signWriteOffAct`, `openWriteOffDraftFor`, `writeOffActById`, `itemsOfWriteOffAct`, `deleteWriteOffDraft`, `detachRecordFromWriteOffActs`, `getWriteOffActs`.
Остаётся/добавляется: `applyWriteOff(array $recordIdToQty): void` (гасит свои факты); `removeIssuanceRecord` эмитит `FulfillmentRecordRemoved`. `heldOf`/`recordById`/`recordsForRequirement` остаются (нужны хендлерам наполнения/страницы акта).

### 3.8 Projector / UX
- `WriteOffActProjector::project(WriteOffAct $act, …)` → кросс-персон: группы по `profileId`, наименование из `labelSnapshot` линии (не per-person `recordById`), организация из акта. Отдаёт данные сгруппированно. **.docx-шаблон подгоняет заказчик, в последнюю очередь.**
- issue-карточка (`issue.html.twig`): «Списать выбранные»/«Списать всё» → новый `AddToWriteOffDraftCommand` (org-scoped); секция списания на карточке показывает ссылку на открытый черновик (req×орг).
- `writeoff/act.html.twig` — переверстать под группы по людям (как мокап).

### 3.9 Миграция (прод 3 акта / 8 позиций)
Идемпотентная (шаблон предыдущих data-миграций), `down()` no-op:
1. `compliance_write_off_act`: добавить `organization_id VARCHAR(64)`; снять FK `fk_write_off_act_profile` (значение `profile_compliance_id` использовать для бэкфилла, затем колонку убрать/оставить nullable до завершения).
2. `compliance_write_off_item`: добавить `profile_id VARCHAR(64)`, `label_snapshot VARCHAR(1000)`.
3. Бэкфилл: для каждого акта — owner profileId из `profile_compliance_id`→`profile_id` владельца; `organization_id` = орг владельца (через профиль); для каждой позиции — `profile_id` = owner, `label_snapshot` = наименование из журнала владельца (`obligationLabelOf(record.obligationKey)`).
4. **Слияние черновиков:** если ≥2 черновика совпали по (requirementId, organizationId) — перенести линии в один, остальные удалить. Пред-проверка на проде (на 3 актах вряд ли). Подписанные не сливаем.
5. Пост-проверка: нет актов без `organization_id`, нет позиций без `profile_id`.

### 3.10 Список актов списания (листинг + фильтр, кросс-организационный для админа)
Отдельная страница-список актов списания — точка входа «найти/открыть акт» (создаются акты по-прежнему с карточек выдачи, не из списка). По конвенции проекта (как Coatings), ОДИН метод `findByFilter`.
- **`WriteOffActFilter`** (bag-of-fields, без логики; инварианты полей — в узких VO): `?organizationId`, `?requirementId`, `?DocumentStatus status` (черновик/оформлен), `?string actNumber`, `?DateRange actDate` (опц.), `Pager` (page/limit). Состояние фильтра — в URL (query-параметры; чипы гидрируются по id, как в Coatings).
- **Query** `GetPagedWriteOffActsQueryHandler` → `repository->findByFilter` → `PaginationResult {items: WriteOffActListItemDTO[], page, hasMore}` (лёгкий DTO: id, статус, № акта, дата, требование(имя), организация(имя), число позиций, число людей). Пагинация `Pager::fromPage($page,$limit)`.
- **Доступ — кросс-организационный для админа:** список показывает акты ВСЕХ организаций (менеджер/система — `AccessGuard::isManager()`); фасет «Организация» фильтрует. (Если позже понадобится орг-скоуп для не-админа — добавить скоуп по орг; сейчас списание — админская операция, поэтому кросс-орг список для админа.)
- **Страница** — копия ближайшего admin-листинга (`components/list_page.html.twig` + `ecard-grid`, как `admin/compliance/requirements/index.html.twig`): карточки актов кликабельны → страница акта (`writeoff_show`); фильтр-панель с фасетами (организация/требование/статус) в URL. Никаких новых стилей — разметка 1-в-1 с аналогом.
- **Роут:** `/cabinet/compliance/writeoff` (список), `/cabinet/compliance/writeoff/{actId}` (акт). Тонкий `ListAction` собирает `WriteOffActFilter` из query и диспатчит query-хендлер.

## 4. Вне охвата (non-goals)
- Комиссия/представитель → `profileId`+снимок+typeahead (отдельный поздний follow-up).
- Переверстка .docx-шаблона акта (заказчик, в конце; проектор-данные готовим).
- Изменения выдачи/журнала (остаётся на человека, не трогаем).
- Вынос проекции (сделан отдельно), Вариант B, card_number NOT NULL.

## 5. Риски
- **Кросс-агрегатная транзакция apply-on-sign**: несколько `ProfileCompliance` + акт в одной транзакции; оптимистичная блокировка на каждом — параллельная подпись/правка словит `OptimisticLockException` (мягкая обработка).
- **Каскад через событие**: удаление факта и правка акта в разных агрегатах; синхронный хендлер в одной транзакции обязателен, иначе рассинхрон (черновик с линией на удалённый факт).
- **Миграция слияния черновиков**: если на проде есть совпавшие (req,орг) черновики — слить корректно; пред-проверка количеством.
- **Скан-владелец сменился** (был ProfileCompliance, стал акт) — промоут/доступ/чистка переназначить на акт; существующие сканы подписанных актов — бэкфилл владельца при миграции.

## 6. Декомпозиция
Один деплой, один план (детально через writing-plans). Ориентир задач: новый агрегат+репозиторий+ORM+миграция; наполнение корзины (org-scoped add + «Списать всё»); apply-on-sign fan-out; каскад-событие; страница акта группами по людям + projector; **список актов (`WriteOffActFilter`+`findByFilter`+фильтр-страница, кросс-орг)**; снятие write-off с ProfileCompliance; переписать тесты. Ветка — форк от `main`.
