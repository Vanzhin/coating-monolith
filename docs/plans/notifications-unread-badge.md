# Деплой 1: инбокс уведомлений (бэк) + правильный бейдж-счётчик

Сосед: `docs/plans/notifications-inbox-ui.md` (Деплой 2 — раздел просмотра). Этот план самодостаточен.

## Зачем

Сейчас бейдж на иконке PWA считает `getNotifications()` (уведомления, висящие в трее ОС) — растёт бесконтрольно и не отражает «непрочитано» (вариант A, кривой). Нужен **настоящий счётчик непрочитанного**: уведомления пишутся в БД со статусом read/unread, бейдж = число непрочитанных, приходит с сервера в payload пуша, сбрасывается при открытии приложения (консистентно между устройствами).

Этот деплой — **только бэк + число**, без раздела просмотра (он в Деплое 2). После него: пуш → в БД строка (unread) + доставка с `badge=N`; открыл приложение → все прочитаны → бейдж гаснет.

## Ключевые решения (развилки)

1. **Агрегат `Notification` — в контексте `Notifications`** (сейчас там только Telegram-сервис; наполняем полноценным доменом Domain/Application/Infrastructure).
2. **Владелец — `string $ownerUlid`, не ORM-связь на `Users\User`.** Не сцепляем контексты: Notification хранит ulid владельца как значение. FK на `user_user(ulid)` добавляем на уровне БД в миграции (без ORM many-to-one). Фильтр по владельцу — по колонке `owner_id`.
3. **Контракт `NotifierInterface` НЕ трогаем** (по прошлому требованию). Счётчик unread попадает в payload пуша через порт: интерфейс `UnreadNotificationCounterInterface` в `Shared\Domain\Service`, реализация в `Notifications\Infrastructure`, `WebPushNotifier` его инжектит (dependency inversion — Shared не зависит от конкретики Notifications).
4. **«Пометить прочитанным» — DBAL `executeStatement`** (`UPDATE ... SET is_read=true, read_at=now() WHERE owner_id=:id AND is_read=false`). В проекте DQL-`update()` не используется нигде — идём по существующему паттерну bulk-мутации (как `CoatingSystemComplianceCacheRepository`).
5. **Продюсер:** `app:push:test` перевести на диспатч нового `SendNotificationCommand` (persist + доставка), чтобы инбокс наполнялся уже сейчас. Реальные события-триггеры (напр. «покрытие изменилось → владельцу») — отдельная будущая задача (упирается в отсутствие «владельца покрытия»).
6. **Поле уведомления — одно: `message` (текст).** Без `title`/`url`/`type`/JSON. Заголовок пуш-баннера остаётся константой «Уведомление» (деталь доставки, не хранится), а клик по уведомлению **всегда ведёт в раздел уведомлений пользователя** (`/cabinet/notifications`) — отдельного `url`-поля нет. Ссылку «вглубь» (на покрытие и т.п.) при необходимости вписываем **прямо в текст `message`**: в баннере она просто текст, а в разделе (Деплой 2) рендерится кликабельной через безопасный autolink (экранируем → оживляем только http(s); тексты авторские, не пользовательский ввод). Раздел появляется в Деплое 2; до него клик по баннеру открывает приложение. Храним только контент уведомления приложения — ничего про каналы/доставку.
7. **Кросс-девайс:** бейдж на устройстве обновляется при следующем пуше (payload несёт актуальный unread). Открыл на телефоне → на компе цифра подтянется со следующим пушем. Живой мгновенный синк всех устройств (отдельный «badge-update» пуш) — не делаем, YAGNI.

## Домен — `app/src/Notifications/Domain/`

### `Entity/Notification.php` (агрегат)
Зеркаль `Users\Domain\Entity\Channel`: наследует `App\Shared\Domain\Aggregate\Aggregate`, `id` — readonly `Uuid` извне, `getId()` = `$id->jsonSerialize()`.
```php
final class Notification extends Aggregate
{
    private bool $isRead = false;
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        private readonly Uuid $id,
        private readonly string $ownerUlid,
        private readonly string $message,
        private readonly \DateTimeImmutable $createdAt,
    ) {
        if ('' === $message) { throw new AppException('Пустое уведомление.'); }
    }

    public function markRead(): void
    {
        if ($this->isRead) { return; }        // идемпотентно
        $this->isRead = true;
        $this->readAt = new \DateTimeImmutable();
    }
    // getId(), getOwnerUlid(), getMessage(), getCreatedAt(), isRead(), getReadAt()
}
```
Инвариант «message не пуст» — в конструкторе (кидает `AppException`). Конструктор также поднимает `NotificationCreatedEvent` (для async-рассылки — см. Application). Юнит-тест: конструктор + `markRead` (идемпотентность) + поднятие события.

### `Repository/NotificationRepositoryInterface.php`
```php
public function add(Notification $notification): void;
public function countUnread(string $ownerUlid): int;
public function markAllReadForOwner(string $ownerUlid): void;
```
(В Деплое 2 добавится `findByFilter(NotificationFilter): PaginationResult`.)

## Инфраструктура — `app/src/Notifications/Infrastructure/`

### `Database/ORM/Notification.orm.xml`
Каталог новый. Зеркаль `Channel.orm.xml`, но owner — обычное поле-строка, не relation:
```xml
<entity name="App\Notifications\Domain\Entity\Notification" table="notification">
    <id name="id" type="uuid"><generator strategy="NONE"/></id>
    <field name="ownerUlid" column="owner_id" length="26"/>
    <field name="message" type="text"/>
    <field name="isRead" column="is_read" type="boolean"/>
    <field name="createdAt" column="created_at" type="datetime_immutable"/>
    <field name="readAt" column="read_at" type="datetime_immutable" nullable="true"/>
</entity>
```

### `Repository/NotificationRepository.php`
`extends ServiceEntityRepository`. `add` = persist+flush. `countUnread` — count-паттерн (`->select('COUNT(n.id)')->where('n.ownerUlid = :o')->andWhere('n.isRead = false')->getSingleScalarResult()`). `markAllReadForOwner` — DBAL:
```php
$this->getEntityManager()->getConnection()->executeStatement(
    'UPDATE notification SET is_read = true, read_at = now() WHERE owner_id = :o AND is_read = false',
    ['o' => $ownerUlid],
);
```
Алиас интерфейс→реализация в `services.yaml` (как `CoatingSystemRepositoryInterface`).

### `UnreadNotificationCounter.php` (реализация порта)
`implements App\Shared\Domain\Service\UnreadNotificationCounterInterface` → делегирует в `NotificationRepositoryInterface::countUnread`.

## Shared — порт счётчика

### `app/src/Shared/Domain/Service/UnreadNotificationCounterInterface.php`
```php
interface UnreadNotificationCounterInterface { public function countForOwner(string $ownerUlid): int; }
```

### `WebPushNotifier` (правка)
Инжектим `UnreadNotificationCounterInterface`. В payload добавляем `badge`:
```php
$payload = json_encode([
    'title' => self::NOTIFICATION_TITLE,
    'body'  => $message,
    'badge' => $this->unreadCounter->countForOwner($channel->getOwner()->getUlid()),
], JSON_UNESCAPED_UNICODE);
```
(Остальные нотифаеры бейдж не трогают.)

## Application — `app/src/Notifications/Application/UseCase/`

### `Command/SendNotification/`
- `SendNotificationCommand extends Command` — `(string $ownerUlid, string $message)`.
- `SendNotificationCommandHandler implements CommandHandlerInterface` — **только создаёт и сохраняет**: `new Notification(UuidService::generateUuid(), ownerUlid, message, new \DateTimeImmutable())` → `notificationRepository->add()`. Result не нужен (void).
- **Рассылка — не в хендлере, а через доменное событие (async).** `Notification` в конструкторе поднимает `NotificationCreatedEvent(notificationId, ownerUlid, message)`; на сохранении `PublishDomainEventsOnFlushListener` публикует его, событие роутится на `async` (messenger.yaml) → `Notifications/Infrastructure/EventHandler/NotificationCreatedEventHandler` в воркере делает фан-аут: `channelRepository->findByOwnerAndType($ownerUlid, WEB_PUSH)` → на каждый `channelNotifierService->notify($channel, $message)`. I/O-доставка уходит из запроса.
- Известная развилка (из ревью): событие публикуется на `postFlush` до COMMIT транзакции команды (publish-before-commit) — редкая гонка счётчика / призрачный пуш; строгий фикс (dispatch-after-commit / outbox) — отдельная кросс-модульная задача.

### `Command/MarkNotificationsRead/`
- `MarkNotificationsReadCommand extends Command` — `(string $ownerUlid)`.
- Handler: `notificationRepository->markAllReadForOwner($ownerUlid)`.

## Инфраструктура: контроллер + консоль

### `Notifications/Infrastructure/Controller/MarkReadAction.php`
`#[Route('/cabinet/notifications/read', name: 'app_notifications_read', methods: ['POST'])]` — берёт `ownerUlid` из `AuthUserFetcherInterface::getAuthUserId()`, диспатчит `MarkNotificationsReadCommand`, отдаёт `JsonResponse(['ok'=>true])`. Регистрация — блок в `services.yaml`:
```yaml
App\Notifications\Infrastructure\Controller\:
    resource: '../src/Notifications/Infrastructure/Controller'
    tags: [ 'controller.service_arguments' ]
```

### `SendTestWebPush` (правка) — продюсер
Вместо прямой отправки: резолвит `ownerUlid` по email и диспатчит `SendNotificationCommand($ownerUlid, $message)`. Так тест наполняет инбокс и идёт по боевому пути; доставка — асинхронно (воркер), поэтому команда рапортует «уведомление создано, доставка async». Отчёт FCM не печатаем (ушёл в notify) — диагностика 410/201 своё отжила, доставка проверена.

## Фронт

### `app/public/sw.js` — push-хендлер
Бейдж из payload (число с сервера), fallback на трей убираем:
```js
if (self.navigator && typeof self.navigator.setAppBadge === 'function' && typeof data.badge === 'number') {
    if (data.badge > 0) self.navigator.setAppBadge(data.badge).catch(()=>{});
    else self.navigator.clearAppBadge?.().catch(()=>{});
}
```
Удалить `refreshAppBadge()` (трей-счётчик, вариант A) и его вызовы в `push`/`notificationclick`.

### `app/assets/app.js` — при открытии приложения
Заменить «гасим бейдж + чистим трей» на: `POST /cabinet/notifications/read` (пометить прочитанным на сервере) → `navigator.clearAppBadge()`. Триггеры — `load` и `visibilitychange`→visible (как сейчас), но теперь бьём в бэк.

## Миграция

`app/src/Shared/Infrastructure/Database/Migrations/Version{ts}.php` — зеркаль `Version20250920160547` (FK на ulid) + идемпотентный стиль `Version20260801150000`:
```sql
CREATE TABLE IF NOT EXISTS notification (
    id UUID NOT NULL, owner_id VARCHAR(26) NOT NULL, message TEXT NOT NULL,
    is_read BOOLEAN NOT NULL, created_at TIMESTAMP(0) NOT NULL,
    read_at TIMESTAMP(0) DEFAULT NULL, PRIMARY KEY(id)
);
CREATE INDEX IF NOT EXISTS idx_notification_owner_unread ON notification (owner_id, is_read);
COMMENT ON COLUMN notification.id IS '(DC2Type:uuid)';
COMMENT ON COLUMN notification.created_at IS '(DC2Type:datetime_immutable)';
COMMENT ON COLUMN notification.read_at IS '(DC2Type:datetime_immutable)';
ALTER TABLE notification ADD CONSTRAINT FK_notification_owner FOREIGN KEY (owner_id) REFERENCES user_user (ulid) NOT DEFERRABLE INITIALLY IMMEDIATE;
```
`down()` — симметричный DROP CONSTRAINT + DROP TABLE (IF EXISTS).

## doctrine.yaml — новый маппинг

Добавить в `doctrine.orm.mappings`:
```yaml
Notifications:
    is_bundle: false
    type: xml
    dir: '%kernel.project_dir%/src/Notifications/Infrastructure/Database/ORM'
    prefix: 'App\Notifications\Domain\Entity'
    alias: Notifications
```

## Тесты

- Unit `tests/Unit/Notifications/Domain/Entity/NotificationTest.php`: конструктор (пустой message → AppException), `markRead` идемпотентно (двойной вызов — один `readAt`).
- Functional `tests/Functional/Notifications/Application/UseCase/Command/SendNotification/SendNotificationCommandHandlerTest.php`: вызов создаёт unread-строку; `countUnread` растёт; повторный — +1.
- Functional `.../MarkNotificationsRead/...Test.php`: после mark-read `countUnread` = 0.
- `WebPushNotifierTest`: payload содержит `badge` (мок счётчика).
- Фронт (sw.js/app.js) — PHP-тесты не трогают; проверка вручную (телефон: пуш → бейдж N; открыл → 0).

## Порядок реализации (по шагам, апрув после каждого)

1. Домен: `Notification` + `NotificationRepositoryInterface` + unit-тест.
2. Инфра: ORM XML + миграция + `NotificationRepository` + doctrine.yaml + алиас; накатить миграцию на dev.
3. Shared-порт `UnreadNotificationCounterInterface` + реализация в Notifications.
4. Application: `SendNotification` + `MarkNotificationsRead` (+ functional-тесты).
5. `WebPushNotifier`: `badge` в payload (+ тест).
6. `MarkReadAction` + services.yaml.
7. `SendTestWebPush` → на `SendNotificationCommand`.
8. Фронт: sw.js (badge из payload) + app.js (POST read + clearAppBadge); `yarn dev`.
9. `./run check` зелёный; ручная проверка на телефоне.

## Верификация

Телефон (Android/iOS-16.4 PWA): подписка → `app:push:test` (persist+доставка) → бейдж «1»; ещё раз → «2»; открыл PWA → `POST read` → бейдж гаснет, в БД `is_read=true`. `countUnread` в БД сходится.
