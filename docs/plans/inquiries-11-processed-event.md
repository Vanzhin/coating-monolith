# Inquiries, план 11: событие «запрос обработан» + подписка

Дата: 2026-10-29. Доменное событие по завершении обработки запроса + подписное уведомление через СУЩЕСТВУЮЩУЮ систему ([[project_notifications_subscriptions]]). Парная задача — `inquiries-10-mail-adapter.md` (почтой пришёл — уведомили, когда готово). См. [[project_inquiries_accuracy_and_mail]].

## Решения (зафиксированы)

- **Одно событие** `InquiryProcessed` с ИСХОДОМ в payload (recognized | failed) — не два отдельных. Одна подписка, в тексте уведомления — распознан или провален.
- **Получатель — владелец запроса** (Owner-резолвер). У запроса с почты владелец = резолвнутый отправитель (план 10); у веб-запроса — его автор.
- **В рамках существующей системы уведомлений** (generic тип×канал, резолверы, NotificationDispatcher). Новый тип — code-first в `NotificationType`.

## Опора (как устроено сейчас)

- `NotificationType` (enum, code-first): новый тип = новый case + метаданные (`kind`/`category`/`label`/`channels`/`resolver`/`visibleToAdminOnly`). Настраиваемые попадают в экран настроек автоматически (`NotificationType::configurable()`).
- `NotifiableEvent` (агрегатное событие отдаёт `notificationType()`), `OwnedNotification` (`ownerUlid()`) — Owner-резолвер возвращает `[ownerUlid()]`. Образец — `UserActivatedNotification`.
- Доменные события раздаются `PublishDomainEventsOnFlushListener` → EventBus → NotificationDispatcher подхватывает `NotifiableEvent`. То есть достаточно агрегату поднять событие — дальше само.
- Сейчас `Inquiry` поднимает только `InquirySubmitted` (в конструкторе и `requestReprocessing`).

## Что меняется

БЕЗ шва-переходника (в отличие от `UserActivated`): там переходник РЕЗОЛВИТ админа-адресата, у нас адресат = владелец, он уже на агрегате — резолвить нечего. deptrac прямой путь разрешает (Domain→Domain), `NotificationDispatcher` ловит `NotifiableEvent` по интерфейсу, агрегатные события публикуются на flush. Поэтому событие — одно, раздаётся напрямую.

- **Событие `InquiryProcessed`** (`Inquiries/Domain/Event`) `implements NotifiableEvent, OwnedNotification, InquiryProcessedData`: `inquiryId`, `ownerId`, `outcome` (enum `InquiryProcessingOutcome`: Recognized|Failed), опц. `failureReason`. `notificationType()` → `NotificationType::InquiryProcessed`; `ownerUlid()` → `ownerId`; геттеры исхода/повода — для рендера.
- **`InquiryProcessedData`** (`Notifications/Domain/Event`) — контракт данных для рендера (как `UserActivatedData`): `outcome()`, `inquiryId()`, `failureReason()`. Реализует его само событие.
- **Агрегат поднимает `InquiryProcessed`** (`$this->raise(...)`, публикуется на flush):
  - в `recognize()` → Recognized — АСИНХРОННЫЙ путь воркера (и кэш-хит).
  - в `fail()` → Failed.
  - НЕ в `editParameters()` (ручная правка идёт через `transitionTo` напрямую, не через `recognize()`) — иначе каждый твик слал бы «обработан». Развязка: уведомляем о МАШИННОЙ обработке.
- Переходника и второго класса НЕТ.
- **`NotificationType::InquiryProcessed = 'inquiry.processed'`**: `kind` Configurable (opt-in), `category` — новый `NotificationCategory::Inquiries` (или ближайшая существующая — решить при реализации), `label` «Запрос на подбор обработан», `channels` [Inbox, Email], `resolver` Owner, `visibleToAdminOnly` false (владелец подписывается на свои).
- **Рендер текста** (`MessageRenderer` для типа) — различает исход: «Запрос распознан — можно смотреть подбор» / «Запрос не удалось обработать: <повод>». Ссылка на карточку `app_cabinet_inquiry_show`.
- Экран настроек подхватит тип автоматически (configurable).

## Развилки (решены / к решению)

- Одно событие + исход. (решено)
- Получатель — Owner. (решено)
- `category` — новый `Inquiries` против переиспользования существующей: решить при реализации (вероятно новый case).
- Нужен ли `ownerUlid` гарантированно ULID: владелец запроса — id пользователя (ULID). Для старых/системных запросов без «настоящего» владельца событие либо не шлём, либо резолвер отдаёт пусто — подстраховать (не падать на не-ULID).

## Не входит

- Доставка результата подбора заказчику по почте — отдельно.
- Дайджест/антифлуд — работает общий механизм прохода (вне этой задачи).

## Тесты

- Агрегат (unit): `recognize()` поднимает `InquiryProcessed(recognized)`; `fail()` — `(failed)`; `editParameters()` — НЕ поднимает (развязка от ручной правки); `ownerUlid` = владелец.
- `NotificationType::InquiryProcessed` (unit) — метаданные (resolver Owner, configurable, каналы).
- Доставка (functional): событие → Owner-резолвер → адресат = владелец; с подпиской канал приходит, без — нет (как у `ComplianceDueSoon`).
- Рендер текста для обоих исходов.

## Порядок (один деплой; после или параллельно плану 10)

1. Событие `InquiryProcessed` + подъём в `recognize()`/`fail()` + unit.
2. `NotificationType` case + метаданные (+ `NotificationCategory` при нужде).
3. Рендер текста + ссылка на карточку.
4. Functional на доставку владельцу + гейты.
