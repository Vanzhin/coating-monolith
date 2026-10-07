# Подписки на события + уведомления — План реализации (Фаза 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** общая система подписок — пользователь подписывается на типы событий и выбирает каналы (inbox/web-push/email); события адресные, доставка по подпискам, системные типы принудительны.

**Architecture:** расширяем контекст `App\Notifications`. Каталог типов — enum в коде (kind System/Configurable, category, разрешённые каналы, ключ резолвера). Уведомляющие доменные события реализуют маркер `NotifiableEvent` + контракт стратегии адресации; единый async-хендлер `NotificationDispatcher` резолвит адресатов (порты в Users/Personnel), выбирает каналы (системные → все с адресом; настраиваемые → подписки), рендерит текст и доставляет (inbox = запись `Notification`; push/email = существующий `ChannelNotifierService`). Авто-пуш из `Notification` расцепляется — единая точка фан-аута диспетчер.

**Tech Stack:** PHP 8.3+, Symfony, Doctrine ORM (XML-маппинг), Symfony Messenger (event.bus async через Redis, воркер `manager_supervisor`), PHPUnit. Гейты — `./run check` (cs-fixer / phpstan level 6 / unit / functional).

**Spec:** `docs/plans/notifications-subscriptions-design.md`

## Global Constraints

- Коммиты — ТОЛЬКО по явному апруву разработчика (git ведёт пользователь вручную). Шаги «Commit» в задачах — это точки, где спросить разрешение; без «да» не коммитить.
- Телеграм НЕ трогаем вообще (ни канал, ни код).
- Хендлеры регистрируются через `implements CommandHandlerInterface`/`QueryHandlerInterface`/`EventHandlerInterface` (НЕ `#[AsMessageHandler]`). Интерфейсы репозиториев бьются алиасом в `app/config/services.yaml`.
- Доменные инварианты кидают `App\Shared\Infrastructure\Exception\AppException` (рус. сообщение). VO — `final readonly`.
- Поиск/листинг — через один `findByFilter({Ctx}Filter): PaginationResult` (не плодить `findByX`).
- `when@test` для async закомментирован → в тестах `async`-транспорт реальный (события кладутся в очередь, НЕ обрабатываются в процессе). Доставку/диспетчер в функц.-тестах зовём НАПРЯМУЮ (хендлер как сервис), не через ожидание воркера.
- Кросс-контекст — только через Application-порты/queries, не лезть в чужие репозитории/агрегаты напрямую.
- Миграции идемпотентные (`IF NOT EXISTS`). Юнит на хосте, функц. в контейнере (`./run check`).
- enum-значения событий/каналов короткие (идут в БД/URL).

## Review Focus

- **Адресность (анти-утечка):** событие `SubjectNotification`, где адресат-не-админ не является субъектом → НЕ получает чужое уведомление (Task 5 тест OwnerResolver + Task 5/8 тест SubjectSupervisors: чужой сотрудник не в списке).
- **Системные принудительны:** пользователь без подписок получает системный тип по всем каналам с адресом; toggle системного → ошибка; системный скрыт из настроек (Task 6, Task 10).
- **Настраиваемые opt-in:** нет строки подписки → не доставлено (ни inbox, ни push); подписка только push → push без inbox-записи (Task 6, Task 8).
- **Расцепление авто-пуша:** после Task 9 создание `Notification` само НЕ пушит; push идёт только через диспетчер по подписке (Task 9 тест: создать Notification напрямую → нет web-push вызова).
- **Нет адреса канала:** адресат подписан на email, но у него нет EMAIL-канала → доставка в этот канал тихо пропускается, остальные идут (Task 8 тест).

---

## Файловая структура (новое, если не указано иное)

```
app/src/Notifications/
  Domain/
    Type/NotificationType.php            — каталог-enum (cases + метаданные)
    Type/NotificationKind.php            — System | Configurable (+ isSystem)
    Type/NotificationCategory.php        — тематика для UI
    Type/NotificationChannel.php         — Inbox | WebPush | Email (+ маппинг в Users\ChannelType)
    Type/ResolverKey.php                 — Owner | SubjectSupervisors | Broadcast
    Event/NotifiableEvent.php            — interface extends EventInterface { notificationType() }
    Event/OwnedNotification.php          — interface { ownerUlid(): string }
    Event/SubjectNotification.php        — interface { subjectProfileId(): string }
    Entity/Subscription.php              — агрегат (user×type×channel→enabled)
    Repository/SubscriptionRepositoryInterface.php
    Service/NotificationAudienceProviderInterface.php   — adminUlids()/allUserUlids() (порт → Users)
    Service/SubjectContextProviderInterface.php         — userUlidOfProfile()/departmentHeadUlidOfProfile() (порт → Personnel)
    Service/RecipientResolverInterface.php
    Service/MessageRendererInterface.php
  Application/
    Service/Resolver/{OwnerResolver,SubjectSupervisorsResolver,BroadcastResolver,ResolverRegistry}.php
    Service/Render/{ComplianceDueSoonRenderer,...,MessageRendererRegistry}.php
    Service/ChannelGate.php              — активные каналы адресата для типа
    Service/SubscriptionSettingsService.php
    EventHandler/NotificationDispatcher.php             — EventHandler на NotifiableEvent
    UseCase/... (экран настроек — тонкие query/command при необходимости)
  Infrastructure/
    Repository/SubscriptionRepository.php
    Database/ORM/Notification.Subscription.orm.xml
    Service/UsersNotificationAudienceProvider.php       — impl порта (в Users? см. ниже)
    Controller/Settings/{ShowSettingsAction,SaveSettingsAction}.php
app/src/Users/Infrastructure/Service/UsersAudienceProvider.php   — реализует NotificationAudienceProviderInterface
app/src/Personnel/Infrastructure/Service/PersonnelSubjectContextProvider.php — реализует SubjectContextProviderInterface
app/src/Shared/Infrastructure/Templates/cabinet/notifications/settings.html.twig
app/src/Shared/Infrastructure/Database/Migrations/Version<ts>.php — notification_subscription
```

Порты-реализации живут в контексте-владельце данных (Users/Personnel), чтобы Notifications не лез в их репозитории.

---

## Task 1: Каталог типов — enum + метаданные

**Files:**
- Create: `app/src/Notifications/Domain/Type/NotificationKind.php`
- Create: `app/src/Notifications/Domain/Type/NotificationCategory.php`
- Create: `app/src/Notifications/Domain/Type/NotificationChannel.php`
- Create: `app/src/Notifications/Domain/Type/ResolverKey.php`
- Create: `app/src/Notifications/Domain/Type/NotificationType.php`
- Test: `app/tests/Unit/Notifications/Domain/Type/NotificationTypeTest.php`

**Interfaces:**
- Produces: `NotificationType` (backed enum) с методами `label(): string`, `category(): NotificationCategory`, `kind(): NotificationKind`, `channels(): list<NotificationChannel>`, `resolver(): ResolverKey`, `visibleToAdminOnly(): bool`. `NotificationKind::isSystem(): bool`. `NotificationChannel::toUsersChannelType(): ?ChannelType` (Inbox → null). Статический `NotificationType::configurable(): list<NotificationType>`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\Notifications\Domain\Type;

use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationKind;
use App\Notifications\Domain\Type\NotificationType;
use App\Notifications\Domain\Type\ResolverKey;
use App\Users\Domain\Entity\ChannelType;
use PHPUnit\Framework\TestCase;

final class NotificationTypeTest extends TestCase
{
    public function test_configurable_type_metadata(): void
    {
        $t = NotificationType::ComplianceDueSoon;
        self::assertSame('compliance.due_soon', $t->value);
        self::assertSame(NotificationKind::Configurable, $t->kind());
        self::assertFalse($t->kind()->isSystem());
        self::assertSame(ResolverKey::SubjectSupervisors, $t->resolver());
        self::assertContains(NotificationChannel::Inbox, $t->channels());
        self::assertContains(NotificationChannel::Email, $t->channels());
        self::assertContains($t, NotificationType::configurable());
    }

    public function test_system_type_is_mandatory_and_hidden(): void
    {
        $t = NotificationType::UserActivated;
        self::assertTrue($t->kind()->isSystem());
        self::assertNotContains($t, NotificationType::configurable(), 'системные не в списке настраиваемых');
    }

    public function test_channel_maps_to_users_channel_type(): void
    {
        self::assertSame(ChannelType::WEB_PUSH, NotificationChannel::WebPush->toUsersChannelType());
        self::assertSame(ChannelType::EMAIL, NotificationChannel::Email->toUsersChannelType());
        self::assertNull(NotificationChannel::Inbox->toUsersChannelType());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Notifications/Domain/Type/NotificationTypeTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Write minimal implementation**

`NotificationKind.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Type;

enum NotificationKind: string
{
    case System = 'system';
    case Configurable = 'configurable';

    public function isSystem(): bool
    {
        return self::System === $this;
    }
}
```

`NotificationCategory.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Type;

enum NotificationCategory: string
{
    case Compliance = 'compliance';
    case Security = 'security';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Compliance => 'Учёт СИЗ',
            self::Security => 'Безопасность',
            self::System => 'Системные',
        };
    }
}
```

`NotificationChannel.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Type;

use App\Users\Domain\Entity\ChannelType;

enum NotificationChannel: string
{
    case Inbox = 'inbox';
    case WebPush = 'web_push';
    case Email = 'email';

    /** Канал доставки Users (null для inbox — это запись Notification, не внешний канал). */
    public function toUsersChannelType(): ?ChannelType
    {
        return match ($this) {
            self::Inbox => null,
            self::WebPush => ChannelType::WEB_PUSH,
            self::Email => ChannelType::EMAIL,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'В приложении',
            self::WebPush => 'Push',
            self::Email => 'Почта',
        };
    }
}
```

`ResolverKey.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Type;

enum ResolverKey: string
{
    case Owner = 'owner';
    case SubjectSupervisors = 'subject_supervisors';
    case Broadcast = 'broadcast';
}
```

`NotificationType.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Type;

/**
 * Каталог типов уведомлений (code-first). Новый тип = новый case + его метаданные здесь.
 * kind: System (принудительный, скрыт из настроек) | Configurable (opt-in).
 */
enum NotificationType: string
{
    case ComplianceDueSoon = 'compliance.due_soon'; // настраиваемый пилот
    case UserActivated = 'user.activated';          // системный (новый пользователь → админу)

    public function kind(): NotificationKind
    {
        return match ($this) {
            self::UserActivated => NotificationKind::System,
            self::ComplianceDueSoon => NotificationKind::Configurable,
        };
    }

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::ComplianceDueSoon => NotificationCategory::Compliance,
            self::UserActivated => NotificationCategory::System,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ComplianceDueSoon => 'СИЗ: подходит срок выдачи',
            self::UserActivated => 'Новый пользователь',
        };
    }

    /** @return list<NotificationChannel> */
    public function channels(): array
    {
        return match ($this) {
            self::ComplianceDueSoon => [NotificationChannel::Inbox, NotificationChannel::WebPush, NotificationChannel::Email],
            self::UserActivated => [NotificationChannel::Inbox, NotificationChannel::WebPush],
        };
    }

    public function resolver(): ResolverKey
    {
        return match ($this) {
            self::ComplianceDueSoon => ResolverKey::SubjectSupervisors,
            self::UserActivated => ResolverKey::Owner,
        };
    }

    /** Виден в настройках только админам (пока роли = админ/обычный). */
    public function visibleToAdminOnly(): bool
    {
        return match ($this) {
            self::ComplianceDueSoon => true,
            self::UserActivated => true,
        };
    }

    /** @return list<self> только настраиваемые (для экрана настроек). */
    public static function configurable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => !$t->kind()->isSystem()));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Notifications/Domain/Type/NotificationTypeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit** (по апруву)

```bash
git add app/src/Notifications/Domain/Type app/tests/Unit/Notifications/Domain/Type
git commit -m "Уведомления: каталог типов событий (enum) — системные/настраиваемые, каналы, стратегия резолва"
```

---

## Task 2: Контракты уведомляющих событий

**Files:**
- Create: `app/src/Notifications/Domain/Event/NotifiableEvent.php`
- Create: `app/src/Notifications/Domain/Event/OwnedNotification.php`
- Create: `app/src/Notifications/Domain/Event/SubjectNotification.php`
- Test: `app/tests/Unit/Notifications/Domain/Event/NotifiableEventContractsTest.php`

**Interfaces:**
- Consumes: `NotificationType` (Task 1), `App\Shared\Domain\Event\EventInterface`.
- Produces: `NotifiableEvent extends EventInterface { notificationType(): NotificationType; }`, `OwnedNotification { ownerUlid(): string; }`, `SubjectNotification { subjectProfileId(): string; }`. Событие-реализация даёт резолверу поля, диспетчеру — тип.

- [ ] **Step 1: Write the failing test** — проверяем, что контракты существуют и реализуемы фейковым событием.

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\Notifications\Domain\Event;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\SubjectNotification;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Domain\Event\EventInterface;
use PHPUnit\Framework\TestCase;

final class NotifiableEventContractsTest extends TestCase
{
    public function test_event_implements_contracts(): void
    {
        $e = new class implements NotifiableEvent, SubjectNotification {
            public function notificationType(): NotificationType { return NotificationType::ComplianceDueSoon; }
            public function subjectProfileId(): string { return 'p-1'; }
        };
        self::assertInstanceOf(EventInterface::class, $e);
        self::assertSame(NotificationType::ComplianceDueSoon, $e->notificationType());
        self::assertSame('p-1', $e->subjectProfileId());
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — `cd app && vendor/bin/phpunit tests/Unit/Notifications/Domain/Event/NotifiableEventContractsTest.php` → FAIL (interfaces not found).

- [ ] **Step 3: Write minimal implementation**

```php
// NotifiableEvent.php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Event;

use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Domain\Event\EventInterface;

/** Доменное событие, подлежащее доставке как уведомление. Отдаёт свой тип из каталога. */
interface NotifiableEvent extends EventInterface
{
    public function notificationType(): NotificationType;
}
```
```php
// OwnedNotification.php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Event;

/** Событие адресовано конкретному пользователю (резолвер Owner). */
interface OwnedNotification
{
    public function ownerUlid(): string;
}
```
```php
// SubjectNotification.php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Event;

/** Событие про сотрудника-субъекта (резолвер SubjectSupervisors: субъект + надзорные). */
interface SubjectNotification
{
    public function subjectProfileId(): string;
}
```

- [ ] **Step 4: Run test to verify it passes** — PASS.

- [ ] **Step 5: Commit** (по апруву)
```bash
git add app/src/Notifications/Domain/Event app/tests/Unit/Notifications/Domain/Event
git commit -m "Уведомления: контракты уведомляющих событий (NotifiableEvent + Owned/Subject)"
```

---

## Task 3: Подписка — агрегат, репозиторий, миграция

**Files:**
- Create: `app/src/Notifications/Domain/Entity/Subscription.php`
- Create: `app/src/Notifications/Domain/Repository/SubscriptionRepositoryInterface.php`
- Create: `app/src/Notifications/Infrastructure/Repository/SubscriptionRepository.php`
- Create: `app/src/Notifications/Infrastructure/Database/ORM/Notification.Subscription.orm.xml`
- Create: `app/src/Shared/Infrastructure/Database/Migrations/Version<ts>.php`
- Modify: `app/config/services.yaml` (алиас интерфейса→impl)
- Modify: `app/config/packages/doctrine.yaml` НЕ требуется (XML-маппинг контекста уже подхватывается; проверить mapping-путь Notifications)
- Test: `app/tests/Functional/Notifications/Infrastructure/Repository/SubscriptionRepositoryTest.php`

**Interfaces:**
- Consumes: `NotificationType`, `NotificationChannel` (Task 1).
- Produces: `Subscription` (`id: Uuid`, `userUlid: string`, `type: NotificationType`, `channel: NotificationChannel`, `enabled: bool`), геттеры + `setEnabled(bool)`. `SubscriptionRepositoryInterface`: `save(Subscription): void`, `findForUser(string $userUlid): list<Subscription>`, `isEnabled(string $userUlid, NotificationType $type, NotificationChannel $channel): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SubscriptionRepositoryTest extends KernelTestCase
{
    public function test_save_and_query_enabled(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(SubscriptionRepositoryInterface::class);
        $ulid = 'u-'.uniqid('', true);

        $repo->save(new Subscription(Uuid::v7(), $ulid, NotificationType::ComplianceDueSoon, NotificationChannel::Email, true));

        self::assertTrue($repo->isEnabled($ulid, NotificationType::ComplianceDueSoon, NotificationChannel::Email));
        self::assertFalse($repo->isEnabled($ulid, NotificationType::ComplianceDueSoon, NotificationChannel::WebPush));
        self::assertCount(1, $repo->findForUser($ulid));
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — в контейнере: `./run check` упадёт на functional, либо точечно `docker compose exec -T -e DATABASE_URL=... manager_php-fpm php vendor/bin/phpunit tests/Functional/Notifications/Infrastructure/Repository/SubscriptionRepositoryTest.php` → FAIL (нет класса/таблицы).

- [ ] **Step 3: Write minimal implementation**

`Subscription.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Entity;

use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use Symfony\Component\Uid\Uuid;

class Subscription
{
    public function __construct(
        private readonly Uuid $id,
        private readonly string $userUlid,
        private readonly NotificationType $type,
        private readonly NotificationChannel $channel,
        private bool $enabled,
    ) {
    }

    public function getId(): string { return (string) $this->id; }
    public function getUserUlid(): string { return $this->userUlid; }
    public function getType(): NotificationType { return $this->type; }
    public function getChannel(): NotificationChannel { return $this->channel; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; }
}
```

`SubscriptionRepositoryInterface.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Repository;

use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;

interface SubscriptionRepositoryInterface
{
    public function save(Subscription $subscription): void;

    /** @return list<Subscription> */
    public function findForUser(string $userUlid): array;

    public function isEnabled(string $userUlid, NotificationType $type, NotificationChannel $channel): bool;
}
```

`SubscriptionRepository.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Subscription> */
class SubscriptionRepository extends ServiceEntityRepository implements SubscriptionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function save(Subscription $subscription): void
    {
        $this->getEntityManager()->persist($subscription);
        $this->getEntityManager()->flush();
    }

    public function findForUser(string $userUlid): array
    {
        return array_values($this->findBy(['userUlid' => $userUlid]));
    }

    public function isEnabled(string $userUlid, NotificationType $type, NotificationChannel $channel): bool
    {
        $row = $this->findOneBy(['userUlid' => $userUlid, 'type' => $type->value, 'channel' => $channel->value]);

        return null !== $row && $row->isEnabled();
    }
}
```

`Notification.Subscription.orm.xml` (enum-поля как string через `enum-type`):
```xml
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="https://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
    <entity name="App\Notifications\Domain\Entity\Subscription" table="notification_subscription">
        <id name="id" type="uuid" column="id"><generator strategy="NONE"/></id>
        <field name="userUlid" type="string" length="64" column="user_ulid"/>
        <field name="type" type="string" length="64" column="type" enum-type="App\Notifications\Domain\Type\NotificationType"/>
        <field name="channel" type="string" length="16" column="channel" enum-type="App\Notifications\Domain\Type\NotificationChannel"/>
        <field name="enabled" type="boolean" column="enabled"/>
        <unique-constraints>
            <unique-constraint columns="user_ulid,type,channel" name="uniq_notif_sub"/>
        </unique-constraints>
    </entity>
</doctrine-mapping>
```

Миграция `Version<ts>.php` — `up()`:
```php
$this->addSql("CREATE TABLE IF NOT EXISTS notification_subscription (
    id UUID NOT NULL, user_ulid VARCHAR(64) NOT NULL, type VARCHAR(64) NOT NULL,
    channel VARCHAR(16) NOT NULL, enabled BOOLEAN NOT NULL, PRIMARY KEY(id))");
$this->addSql("CREATE UNIQUE INDEX IF NOT EXISTS uniq_notif_sub ON notification_subscription (user_ulid, type, channel)");
```

`services.yaml` — рядом с другими алиасами:
```yaml
App\Notifications\Domain\Repository\SubscriptionRepositoryInterface:
    alias: App\Notifications\Infrastructure\Repository\SubscriptionRepository
```

Проверить: путь XML-маппинга Notifications зарегистрирован в `doctrine.yaml` (если контекст уже маппится по каталогу `Notifications/Infrastructure/Database/ORM` — ничего не добавлять; иначе добавить mapping-запись по образцу существующих контекстов).

- [ ] **Step 4: Run test to verify it passes** — применить миграцию в тест-БД (`./run console doctrine:migrations:migrate -n` в контейнере тест-окружения) и прогнать тест → PASS. Дев-БД — накатить дельтой.

- [ ] **Step 5: Commit** (по апруву)
```bash
git add app/src/Notifications/Domain/Entity app/src/Notifications/Domain/Repository/SubscriptionRepositoryInterface.php \
  app/src/Notifications/Infrastructure/Repository/SubscriptionRepository.php \
  app/src/Notifications/Infrastructure/Database/ORM/Notification.Subscription.orm.xml \
  app/src/Shared/Infrastructure/Database/Migrations/Version*.php app/config/services.yaml \
  app/tests/Functional/Notifications/Infrastructure/Repository/SubscriptionRepositoryTest.php
git commit -m "Уведомления: подписки (агрегат Subscription + репозиторий + таблица notification_subscription)"
```

---

## Task 4: Порты аудитории и субъекта (кросс-контекст)

**Files:**
- Create: `app/src/Notifications/Domain/Service/NotificationAudienceProviderInterface.php`
- Create: `app/src/Notifications/Domain/Service/SubjectContextProviderInterface.php`
- Create: `app/src/Users/Infrastructure/Service/UsersAudienceProvider.php`
- Create: `app/src/Personnel/Infrastructure/Service/PersonnelSubjectContextProvider.php`
- Modify: `app/config/services.yaml` (алиасы портов)
- Test: `app/tests/Functional/Notifications/Infrastructure/Service/AudienceAndSubjectProvidersTest.php`

**Interfaces:**
- Consumes: `UserRepositoryInterface` (Users), `GetProfileQuery`/`ProfileDTO` (Personnel: `userUlid`, `departmentId`), `Department.headUserUlid` (Personnel).
- Produces:
  - `NotificationAudienceProviderInterface { adminUlids(): list<string>; allUserUlids(): list<string>; }`
  - `SubjectContextProviderInterface { userUlidOfProfile(string $profileId): ?string; departmentHeadUlidOfProfile(string $profileId): ?string; }`

- [ ] **Step 1: Write the failing test** — создаём 2 админов + 1 обычного и профиль; проверяем провайдеры.

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Notifications\Infrastructure\Service;

use App\Notifications\Domain\Service\NotificationAudienceProviderInterface;
use App\Notifications\Domain\Service\SubjectContextProviderInterface;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AudienceAndSubjectProvidersTest extends KernelTestCase
{
    use EnrollsComplianceTrait;

    public function test_admin_ulids_and_subject_resolve(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $admin = $this->makeUser($c, $em, ['ROLE_ADMIN']);
        $this->makeUser($c, $em, []); // обычный — не попадёт в adminUlids

        $audience = $c->get(NotificationAudienceProviderInterface::class);
        self::assertContains($admin->getUlid(), $audience->adminUlids());

        ['profileId' => $profileId] = $this->enrollCompliance();
        $subject = $c->get(SubjectContextProviderInterface::class);
        self::assertNotNull($subject->userUlidOfProfile($profileId), 'профиль→userUlid резолвится');
    }

    /** @param list<string> $roles */
    private function makeUser($c, EntityManagerInterface $em, array $roles): User
    {
        $u = new User(new Email('aud_'.uniqid('', true).'@example.com'));
        $u->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($u, 'isActive'))->setValue($u, true);
        if ([] !== $roles) { (new \ReflectionProperty($u, 'roles'))->setValue($u, $roles); }
        $em->persist($u); $em->flush();
        return $u;
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL (интерфейсы/сервисы не найдены).

- [ ] **Step 3: Write minimal implementation**

Порты (Notifications/Domain/Service):
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Service;

interface NotificationAudienceProviderInterface
{
    /** @return list<string> ULID пользователей с ROLE_ADMIN. */
    public function adminUlids(): array;

    /** @return list<string> ULID всех пользователей (для broadcast). */
    public function allUserUlids(): array;
}
```
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Service;

interface SubjectContextProviderInterface
{
    public function userUlidOfProfile(string $profileId): ?string;

    public function departmentHeadUlidOfProfile(string $profileId): ?string;
}
```

`UsersAudienceProvider.php` (Users/Infrastructure/Service) — реализует порт через `UserRepositoryInterface`. Если нет готового способа фильтра по роли — итерируем `findByFilter` с большим лимитом (внутренний инструмент, пользователей немного) и фильтруем по `ROLE_ADMIN`:
```php
<?php
declare(strict_types=1);
namespace App\Users\Infrastructure\Service;

use App\Notifications\Domain\Service\NotificationAudienceProviderInterface;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Domain\Repository\UsersFilter;
use App\Shared\Domain\Repository\Pager;

final readonly class UsersAudienceProvider implements NotificationAudienceProviderInterface
{
    public function __construct(private UserRepositoryInterface $users) {}

    public function adminUlids(): array
    {
        $ulids = [];
        foreach ($this->allUsers() as $u) {
            if (\in_array('ROLE_ADMIN', $u->getRoles(), true)) { $ulids[] = $u->getUlid(); }
        }
        return array_values($ulids);
    }

    public function allUserUlids(): array
    {
        return array_values(array_map(static fn ($u): string => $u->getUlid(), $this->allUsers()));
    }

    /** @return list<\App\Users\Domain\Entity\User> */
    private function allUsers(): array
    {
        // Внутренний инструмент: пользователей немного — одна страница с большим лимитом.
        return $this->users->findByFilter(new UsersFilter(pager: Pager::fromPage(1, 1000)))->items;
    }
}
```
*Примечание исполнителю:* сверить точную сигнатуру `UsersFilter` и `User::getRoles()`; если фильтр не принимает такие аргументы — использовать имеющийся конструктор `UsersFilter` и штатную пагинацию; суть — получить всех и отфильтровать по роли в PHP.

`PersonnelSubjectContextProvider.php` (Personnel/Infrastructure/Service) — через `QueryBus` (`GetProfileQuery` → `ProfileDTO.userUlid`, `.departmentId`) + резолв головы отдела (запрос/репозиторий Department по id → `headUserUlid`):
```php
<?php
declare(strict_types=1);
namespace App\Personnel\Infrastructure\Service;

use App\Notifications\Domain\Service\SubjectContextProviderInterface;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
// + зависимость для резолва головы отдела по departmentId (запрос/репозиторий Personnel)

final readonly class PersonnelSubjectContextProvider implements SubjectContextProviderInterface
{
    public function __construct(private QueryBusInterface $queryBus /*, DepartmentHead lookup */) {}

    public function userUlidOfProfile(string $profileId): ?string
    {
        /** @var GetProfileQueryResult $r */
        $r = $this->queryBus->execute(new GetProfileQuery($profileId));
        return $r->profile?->userUlid;
    }

    public function departmentHeadUlidOfProfile(string $profileId): ?string
    {
        /** @var GetProfileQueryResult $r */
        $r = $this->queryBus->execute(new GetProfileQuery($profileId));
        $departmentId = $r->profile?->departmentId;
        if (null === $departmentId) { return null; }
        // Резолв головы отдела: использовать существующий запрос/DTO отдела (headUserUlid).
        // Если готового запроса нет — добавить тонкий GetDepartmentHeadQuery(departmentId): ?string.
        return $this->resolveDepartmentHead($departmentId);
    }

    private function resolveDepartmentHead(string $departmentId): ?string
    {
        // Исполнителю: реализовать через имеющийся Department-запрос/репозиторий Personnel (headUserUlid).
        return null; // заменить реальным резолвом (см. Task-примечание)
    }
}
```
*Примечание исполнителю:* найти существующий способ получить `Department.headUserUlid` по `departmentId` (DTO/репозиторий Personnel). Если нет — добавить тонкий Application-query `GetDepartmentHeadQuery(departmentId): ?string` в Personnel и использовать его. Тест Task 8 (SubjectSupervisors) закрепит поведение; для данного теста достаточно `userUlidOfProfile`.

`services.yaml`:
```yaml
App\Notifications\Domain\Service\NotificationAudienceProviderInterface:
    alias: App\Users\Infrastructure\Service\UsersAudienceProvider
App\Notifications\Domain\Service\SubjectContextProviderInterface:
    alias: App\Personnel\Infrastructure\Service\PersonnelSubjectContextProvider
```

- [ ] **Step 4: Run test to verify it passes** — PASS.

- [ ] **Step 5: Commit** (по апруву)
```bash
git add app/src/Notifications/Domain/Service app/src/Users/Infrastructure/Service/UsersAudienceProvider.php \
  app/src/Personnel/Infrastructure/Service/PersonnelSubjectContextProvider.php app/config/services.yaml \
  app/tests/Functional/Notifications/Infrastructure/Service/AudienceAndSubjectProvidersTest.php
git commit -m "Уведомления: порты аудитории (админы/все) и субъекта (профиль→юзер, начальник отдела)"
```

---

## Task 5: Резолверы адресатов + реестр

**Files:**
- Create: `app/src/Notifications/Domain/Service/RecipientResolverInterface.php`
- Create: `app/src/Notifications/Application/Service/Resolver/OwnerResolver.php`
- Create: `app/src/Notifications/Application/Service/Resolver/SubjectSupervisorsResolver.php`
- Create: `app/src/Notifications/Application/Service/Resolver/BroadcastResolver.php`
- Create: `app/src/Notifications/Application/Service/Resolver/ResolverRegistry.php`
- Modify: `app/config/services.yaml` (tag резолверов для реестра, если нужно; иначе DI-инъекция по типу)
- Test: `app/tests/Functional/Notifications/Application/Service/ResolverRegistryTest.php`

**Interfaces:**
- Consumes: `NotifiableEvent`, `OwnedNotification`, `SubjectNotification` (Task 2); порты (Task 4); `ResolverKey` (Task 1).
- Produces: `RecipientResolverInterface { key(): ResolverKey; resolve(NotifiableEvent $e): list<string> }`. `ResolverRegistry { for(ResolverKey $k): RecipientResolverInterface; resolve(NotifiableEvent $e): list<string> }` (берёт ключ из `type->resolver()`).

- [ ] **Step 1: Write the failing test** — Owner отдаёт ownerUlid; SubjectSupervisors = субъект+админы (чужой не попадает); Broadcast — все.

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Notifications\Application\Service;

use App\Notifications\Application\Service\Resolver\ResolverRegistry;
use App\Notifications\Domain\Event\{NotifiableEvent, OwnedNotification, SubjectNotification};
use App\Notifications\Domain\Type\NotificationType;
use App\Tests\Support\EnrollsComplianceTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResolverRegistryTest extends KernelTestCase
{
    use EnrollsComplianceTrait;

    public function test_owner_resolver_targets_only_owner(): void
    {
        self::bootKernel();
        $reg = self::getContainer()->get(ResolverRegistry::class);
        $e = new class implements NotifiableEvent, OwnedNotification {
            public function notificationType(): NotificationType { return NotificationType::UserActivated; }
            public function ownerUlid(): string { return 'u-owner'; }
        };
        self::assertSame(['u-owner'], $reg->resolve($e));
    }

    public function test_subject_supervisors_includes_subject_not_strangers(): void
    {
        self::bootKernel();
        ['profileId' => $profileId] = $this->enrollCompliance(); // создаёт профиль (с userUlid)
        $reg = self::getContainer()->get(ResolverRegistry::class);
        $e = new class($profileId) implements NotifiableEvent, SubjectNotification {
            public function __construct(private string $p) {}
            public function notificationType(): NotificationType { return NotificationType::ComplianceDueSoon; }
            public function subjectProfileId(): string { return $this->p; }
        };
        $recipients = $reg->resolve($e);
        self::assertNotEmpty($recipients, 'есть хотя бы субъект/надзорные');
        self::assertNotContains('u-stranger', $recipients, 'чужой не попадает');
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL.

- [ ] **Step 3: Write minimal implementation**

`RecipientResolverInterface.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Service;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Type\ResolverKey;

interface RecipientResolverInterface
{
    public function key(): ResolverKey;

    /** @return list<string> userUlid адресатов. */
    public function resolve(NotifiableEvent $event): array;
}
```

`OwnerResolver.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Application\Service\Resolver;

use App\Notifications\Domain\Event\{NotifiableEvent, OwnedNotification};
use App\Notifications\Domain\Service\RecipientResolverInterface;
use App\Notifications\Domain\Type\ResolverKey;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class OwnerResolver implements RecipientResolverInterface
{
    public function key(): ResolverKey { return ResolverKey::Owner; }

    public function resolve(NotifiableEvent $event): array
    {
        if (!$event instanceof OwnedNotification) {
            throw new AppException(sprintf('Событие %s требует OwnedNotification для резолвера Owner.', $event->notificationType()->value));
        }
        return [$event->ownerUlid()];
    }
}
```

`SubjectSupervisorsResolver.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Application\Service\Resolver;

use App\Notifications\Domain\Event\{NotifiableEvent, SubjectNotification};
use App\Notifications\Domain\Service\{NotificationAudienceProviderInterface, RecipientResolverInterface, SubjectContextProviderInterface};
use App\Notifications\Domain\Type\ResolverKey;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class SubjectSupervisorsResolver implements RecipientResolverInterface
{
    public function __construct(
        private SubjectContextProviderInterface $subject,
        private NotificationAudienceProviderInterface $audience,
    ) {}

    public function key(): ResolverKey { return ResolverKey::SubjectSupervisors; }

    public function resolve(NotifiableEvent $event): array
    {
        if (!$event instanceof SubjectNotification) {
            throw new AppException(sprintf('Событие %s требует SubjectNotification.', $event->notificationType()->value));
        }
        $pid = $event->subjectProfileId();
        $recipients = [];
        if (null !== $self = $this->subject->userUlidOfProfile($pid)) { $recipients[] = $self; }
        if (null !== $head = $this->subject->departmentHeadUlidOfProfile($pid)) { $recipients[] = $head; }
        foreach ($this->audience->adminUlids() as $admin) { $recipients[] = $admin; }

        return array_values(array_unique($recipients));
    }
}
```

`BroadcastResolver.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Application\Service\Resolver;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\{NotificationAudienceProviderInterface, RecipientResolverInterface};
use App\Notifications\Domain\Type\ResolverKey;

final readonly class BroadcastResolver implements RecipientResolverInterface
{
    public function __construct(private NotificationAudienceProviderInterface $audience) {}

    public function key(): ResolverKey { return ResolverKey::Broadcast; }

    public function resolve(NotifiableEvent $event): array
    {
        return $this->audience->allUserUlids();
    }
}
```

`ResolverRegistry.php` (инъекция всех резолверов, индекс по ключу):
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Application\Service\Resolver;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\RecipientResolverInterface;
use App\Notifications\Domain\Type\ResolverKey;
use App\Shared\Infrastructure\Exception\AppException;

final class ResolverRegistry
{
    /** @var array<string, RecipientResolverInterface> */
    private array $byKey = [];

    /** @param iterable<RecipientResolverInterface> $resolvers */
    public function __construct(iterable $resolvers)
    {
        foreach ($resolvers as $r) { $this->byKey[$r->key()->value] = $r; }
    }

    public function for(ResolverKey $key): RecipientResolverInterface
    {
        return $this->byKey[$key->value] ?? throw new AppException('Нет резолвера: '.$key->value);
    }

    /** @return list<string> */
    public function resolve(NotifiableEvent $event): array
    {
        return $this->for($event->notificationType()->resolver())->resolve($event);
    }
}
```

`services.yaml` — тег + биндинг iterable:
```yaml
_instanceof:
    App\Notifications\Domain\Service\RecipientResolverInterface:
        tags: ['app.notification_resolver']
App\Notifications\Application\Service\Resolver\ResolverRegistry:
    arguments: [!tagged_iterator app.notification_resolver]
```

- [ ] **Step 4: Run test to verify it passes** — PASS.

- [ ] **Step 5: Commit** (по апруву)
```bash
git add app/src/Notifications/Domain/Service/RecipientResolverInterface.php \
  app/src/Notifications/Application/Service/Resolver app/config/services.yaml \
  app/tests/Functional/Notifications/Application/Service/ResolverRegistryTest.php
git commit -m "Уведомления: резолверы адресатов (Owner/Субъект+надзорные/Broadcast) + реестр"
```

---

## Task 6: ChannelGate — активные каналы адресата для типа

**Files:**
- Create: `app/src/Notifications/Application/Service/ChannelGate.php`
- Test: `app/tests/Functional/Notifications/Application/Service/ChannelGateTest.php`

**Interfaces:**
- Consumes: `SubscriptionRepositoryInterface` (Task 3), `NotificationType`/`NotificationChannel`/`NotificationKind` (Task 1).
- Produces: `ChannelGate::activeChannels(string $userUlid, NotificationType $type): list<NotificationChannel>` — для системного типа = все `type->channels()`; для настраиваемого = только каналы с включённой подпиской.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Notifications\Application\Service;

use App\Notifications\Application\Service\ChannelGate;
use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\{NotificationChannel, NotificationType};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ChannelGateTest extends KernelTestCase
{
    public function test_system_type_all_channels_regardless_of_subscription(): void
    {
        self::bootKernel();
        $gate = self::getContainer()->get(ChannelGate::class);
        $channels = $gate->activeChannels('u-any', NotificationType::UserActivated); // системный
        self::assertEqualsCanonicalizing(NotificationType::UserActivated->channels(), $channels);
    }

    public function test_configurable_type_only_subscribed_channels(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $repo = $c->get(SubscriptionRepositoryInterface::class);
        $gate = $c->get(ChannelGate::class);
        $ulid = 'u-'.uniqid('', true);
        $repo->save(new Subscription(Uuid::v7(), $ulid, NotificationType::ComplianceDueSoon, NotificationChannel::WebPush, true));

        $channels = $gate->activeChannels($ulid, NotificationType::ComplianceDueSoon);
        self::assertSame([NotificationChannel::WebPush], $channels, 'только подписанный push, без inbox/email');
    }

    public function test_configurable_type_no_subscription_empty(): void
    {
        self::bootKernel();
        $gate = self::getContainer()->get(ChannelGate::class);
        self::assertSame([], $gate->activeChannels('u-nobody', NotificationType::ComplianceDueSoon));
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);
namespace App\Notifications\Application\Service;

use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\{NotificationChannel, NotificationType};

/** Какие каналы активны для адресата по типу: системный → все разрешённые; настраиваемый → по подпискам. */
final readonly class ChannelGate
{
    public function __construct(private SubscriptionRepositoryInterface $subscriptions) {}

    /** @return list<NotificationChannel> */
    public function activeChannels(string $userUlid, NotificationType $type): array
    {
        if ($type->kind()->isSystem()) {
            return $type->channels();
        }
        $active = [];
        foreach ($type->channels() as $channel) {
            if ($this->subscriptions->isEnabled($userUlid, $type, $channel)) { $active[] = $channel; }
        }
        return $active;
    }
}
```

- [ ] **Step 4: Run test to verify it passes** — PASS.

- [ ] **Step 5: Commit** (по апруву)
```bash
git add app/src/Notifications/Application/Service/ChannelGate.php \
  app/tests/Functional/Notifications/Application/Service/ChannelGateTest.php
git commit -m "Уведомления: выбор каналов адресата (системные — все, настраиваемые — по подпискам)"
```

---

## Task 7: Рендер текста по типу события

**Files:**
- Create: `app/src/Notifications/Domain/Service/MessageRendererInterface.php`
- Create: `app/src/Notifications/Application/Service/Render/MessageRendererRegistry.php`
- Create: `app/src/Notifications/Application/Service/Render/ComplianceDueSoonRenderer.php`
- Create: `app/src/Notifications/Application/Service/Render/UserActivatedRenderer.php`
- Modify: `app/config/services.yaml` (tagged iterator)
- Test: `app/tests/Unit/Notifications/Application/Service/Render/MessageRendererRegistryTest.php`

**Interfaces:**
- Consumes: `NotifiableEvent`, `NotificationType`.
- Produces: `MessageRendererInterface { type(): NotificationType; render(NotifiableEvent $e): string }`. `MessageRendererRegistry { render(NotifiableEvent $e): string }`.
- Пилотные события (Task 11) должны отдавать поля для рендера. Для `ComplianceDueSoon` — контракт `ComplianceDueSoonData { employeeFio(): string; obligationLabel(): string; dueDate(): string }` (объявить в Task 11 вместе с событием; рендерер типизируется на него).

- [ ] **Step 1: Write the failing test** — рендерер отдаёт текст для фейкового события нужного типа.

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\Notifications\Application\Service\Render;

use App\Notifications\Application\Service\Render\{MessageRendererRegistry, UserActivatedRenderer};
use App\Notifications\Domain\Event\{NotifiableEvent, OwnedNotification};
use App\Notifications\Domain\Type\NotificationType;
use PHPUnit\Framework\TestCase;

final class MessageRendererRegistryTest extends TestCase
{
    public function test_registry_renders_by_type(): void
    {
        $reg = new MessageRendererRegistry([new UserActivatedRenderer()]);
        $e = new class implements NotifiableEvent, OwnedNotification {
            public function notificationType(): NotificationType { return NotificationType::UserActivated; }
            public function ownerUlid(): string { return 'u'; }
            public function newUserEmail(): string { return 'new@x.io'; }
        };
        self::assertStringContainsString('new@x.io', $reg->render($e));
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL.

- [ ] **Step 3: Write minimal implementation**

```php
// MessageRendererInterface.php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Service;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Type\NotificationType;

interface MessageRendererInterface
{
    public function type(): NotificationType;
    public function render(NotifiableEvent $event): string;
}
```
```php
// MessageRendererRegistry.php
<?php
declare(strict_types=1);
namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Shared\Infrastructure\Exception\AppException;

final class MessageRendererRegistry
{
    /** @var array<string, MessageRendererInterface> */
    private array $byType = [];

    /** @param iterable<MessageRendererInterface> $renderers */
    public function __construct(iterable $renderers)
    {
        foreach ($renderers as $r) { $this->byType[$r->type()->value] = $r; }
    }

    public function render(NotifiableEvent $event): string
    {
        $type = $event->notificationType();
        return ($this->byType[$type->value] ?? throw new AppException('Нет рендерера: '.$type->value))->render($event);
    }
}
```
```php
// UserActivatedRenderer.php — ожидает метод newUserEmail() на событии (Task 9/11 его реализует)
<?php
declare(strict_types=1);
namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class UserActivatedRenderer implements MessageRendererInterface
{
    public function type(): NotificationType { return NotificationType::UserActivated; }

    public function render(NotifiableEvent $event): string
    {
        if (!method_exists($event, 'newUserEmail')) {
            throw new AppException('Событие user.activated не отдаёт newUserEmail().');
        }
        return sprintf('Новый пользователь: %s', $event->newUserEmail());
    }
}
```
```php
// ComplianceDueSoonRenderer.php — ожидает employeeFio()/obligationLabel()/dueDate() (Task 11)
<?php
declare(strict_types=1);
namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class ComplianceDueSoonRenderer implements MessageRendererInterface
{
    public function type(): NotificationType { return NotificationType::ComplianceDueSoon; }

    public function render(NotifiableEvent $event): string
    {
        foreach (['employeeFio', 'obligationLabel', 'dueDate'] as $m) {
            if (!method_exists($event, $m)) { throw new AppException('Событие compliance.due_soon не отдаёт '.$m.'().'); }
        }
        return sprintf('У %s подходит срок выдачи «%s» — до %s', $event->employeeFio(), $event->obligationLabel(), $event->dueDate());
    }
}
```

`services.yaml`:
```yaml
_instanceof:
    App\Notifications\Domain\Service\MessageRendererInterface:
        tags: ['app.notification_renderer']
App\Notifications\Application\Service\Render\MessageRendererRegistry:
    arguments: [!tagged_iterator app.notification_renderer]
```

*Примечание:* `method_exists` здесь — мост к полям конкретного события; в Task 11 события реализуют типизированные контракты-данные (`ComplianceDueSoonData`), и рендереры будут типизированы на них — заменить `method_exists` на instanceof-проверку контракта.

- [ ] **Step 4: Run test to verify it passes** — PASS.

- [ ] **Step 5: Commit** (по апруву)
```bash
git add app/src/Notifications/Domain/Service/MessageRendererInterface.php \
  app/src/Notifications/Application/Service/Render app/config/services.yaml \
  app/tests/Unit/Notifications/Application/Service/Render/MessageRendererRegistryTest.php
git commit -m "Уведомления: рендер текста по типу события (реестр рендереров)"
```

---

## Task 8: NotificationDispatcher — единый фан-аут

**Files:**
- Create: `app/src/Notifications/Application/EventHandler/NotificationDispatcher.php`
- Modify: `app/config/packages/messenger.yaml` (роутинг `NotifiableEvent` реализующих событий на async — по конкретным классам Task 11; сам диспетчер ловит по интерфейсу)
- Test: `app/tests/Functional/Notifications/Application/EventHandler/NotificationDispatcherTest.php`

**Interfaces:**
- Consumes: `ResolverRegistry` (Task 5), `ChannelGate` (Task 6), `MessageRendererRegistry` (Task 7), `SendNotificationCommand` ИЛИ `NotificationRepositoryInterface` (для inbox), `ChannelRepositoryInterface` + `ChannelNotifierService` (push/email), `NotificationChannel::toUsersChannelType()`.
- Produces: `NotificationDispatcher implements EventHandlerInterface { __invoke(NotifiableEvent $event): void }`. Для каждого адресата: inbox → создать `Notification`; web_push/email → найти каналы адресата нужного типа (`findByOwnerAndType`) и `channelNotifierService->notify(channel, text)`.

- [ ] **Step 1: Write the failing test** — настраиваемый тип: адресат подписан только на inbox → создаётся Notification, push не вызывается; без подписки → ничего. (Push/email проверяем через наличие/отсутствие inbox-записи и каналов; доставку push в тесте не мокаем — достаточно проверить inbox + отсутствие записи при отсутствии подписки, и что чужой адресат не получает.)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Notifications\Application\EventHandler;

use App\Notifications\Application\EventHandler\NotificationDispatcher;
use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Event\{NotifiableEvent, OwnedNotification};
use App\Notifications\Domain\Repository\{NotificationRepositoryInterface, SubscriptionRepositoryInterface};
use App\Notifications\Domain\Repository\NotificationFilter;
use App\Notifications\Domain\Type\{NotificationChannel, NotificationType};
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class NotificationDispatcherTest extends KernelTestCase
{
    public function test_configurable_inbox_subscription_creates_inbox_row(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $subs = $c->get(SubscriptionRepositoryInterface::class);
        $notifs = $c->get(NotificationRepositoryInterface::class);
        $dispatcher = $c->get(NotificationDispatcher::class);
        $ulid = 'u-'.uniqid('', true);
        // Owner-событие настраиваемого типа — упростим на ComplianceDueSoon нельзя (Subject); используем системный путь ниже.
        // Для настраиваемого inbox берём Owner-тип? Нет — ComplianceDueSoon=Subject. Тест берёт системный UserActivated (Owner) для inbox-проверки:
        $e = new class($ulid) implements NotifiableEvent, OwnedNotification {
            public function __construct(private string $u) {}
            public function notificationType(): NotificationType { return NotificationType::UserActivated; } // системный → inbox всегда
            public function ownerUlid(): string { return $this->u; }
            public function newUserEmail(): string { return 'n@x.io'; }
        };
        $dispatcher->__invoke($e);

        $rows = $notifs->findByFilter(new NotificationFilter($ulid, null, Pager::fromPage(1, 10)))->items;
        self::assertCount(1, $rows, 'системный тип всегда создаёт inbox-запись');
    }

    public function test_configurable_without_subscription_delivers_nothing(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $notifs = $c->get(NotificationRepositoryInterface::class);
        $dispatcher = $c->get(NotificationDispatcher::class);
        $ulid = 'u-'.uniqid('', true);
        // ComplianceDueSoon (настраиваемый, Subject-резолвер). Без профиля/подписки адресатов-подписчиков нет.
        $e = new class('nonexistent-profile') implements NotifiableEvent, \App\Notifications\Domain\Event\SubjectNotification {
            public function __construct(private string $p) {}
            public function notificationType(): NotificationType { return NotificationType::ComplianceDueSoon; }
            public function subjectProfileId(): string { return $this->p; }
            public function employeeFio(): string { return 'Иванов И.И.'; }
            public function obligationLabel(): string { return 'Перчатки'; }
            public function dueDate(): string { return '05.12.2026'; }
        };
        $dispatcher->__invoke($e);
        $rows = $notifs->findByFilter(new NotificationFilter($ulid, null, Pager::fromPage(1, 10)))->items;
        self::assertCount(0, $rows);
    }
}
```

*Примечание:* сверить сигнатуру `NotificationFilter` (из обследования: `ownerUlid`, `?bool $isRead`, `Pager`).

- [ ] **Step 2: Run test to verify it fails** — FAIL.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);
namespace App\Notifications\Application\EventHandler;

use App\Notifications\Application\Service\ChannelGate;
use App\Notifications\Application\Service\Render\MessageRendererRegistry;
use App\Notifications\Application\Service\Resolver\ResolverRegistry;
use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Service\ChannelNotifierService;
use App\Users\Domain\Repository\ChannelRepositoryInterface;

/**
 * Единая точка доставки уведомляющих событий. Ловит любое событие, реализующее NotifiableEvent
 * (Messenger роутит по реализуемым интерфейсам). Резолвит адресатов → по каждому выбирает каналы
 * (системные — все, настраиваемые — по подпискам) → рендерит текст → доставляет:
 * inbox = запись Notification; web_push/email = канал Users через ChannelNotifierService.
 * Async: роутится на воркер (messenger.yaml); в тестах зовётся напрямую.
 */
readonly class NotificationDispatcher implements EventHandlerInterface
{
    public function __construct(
        private ResolverRegistry $resolvers,
        private ChannelGate $channelGate,
        private MessageRendererRegistry $renderer,
        private NotificationRepositoryInterface $notifications,
        private ChannelRepositoryInterface $channels,
        private ChannelNotifierService $channelNotifier,
    ) {}

    public function __invoke(NotifiableEvent $event): void
    {
        $type = $event->notificationType();
        $message = $this->renderer->render($event);

        foreach ($this->resolvers->resolve($event) as $userUlid) {
            foreach ($this->channelGate->activeChannels($userUlid, $type) as $channel) {
                $this->deliver($channel, $userUlid, $message);
            }
        }
    }

    private function deliver(NotificationChannel $channel, string $userUlid, string $message): void
    {
        if (NotificationChannel::Inbox === $channel) {
            $this->notifications->add(new Notification(UuidService::generateUuid(), $userUlid, $message, new \DateTimeImmutable()));
            return;
        }
        $usersType = $channel->toUsersChannelType();
        if (null === $usersType) { return; }
        foreach ($this->channels->findByOwnerAndType($userUlid, $usersType->value) as $userChannel) {
            $this->channelNotifier->notify($userChannel, $message);
        }
    }
}
```

*Важно (реконсиляция, Task 9):* пока `Notification::__construct` всё ещё авто-пушит через `NotificationCreatedEvent`, inbox-канал диспетчера вызовет двойной/лишний push. Поэтому Task 9 (расцепление) должен идти сразу за этим; до него функц.-тесты диспетчера проверяют только inbox-запись/отсутствие, не фактический push.

- [ ] **Step 4: Run test to verify it passes** — PASS.

- [ ] **Step 5: Commit** (по апруву)
```bash
git add app/src/Notifications/Application/EventHandler/NotificationDispatcher.php \
  app/tests/Functional/Notifications/Application/EventHandler/NotificationDispatcherTest.php
git commit -m "Уведомления: единый диспетчер фан-аута (резолв адресатов → каналы → доставка inbox/push/email)"
```

---

## Task 9: Расцепление авто-пуша + миграция легаси

**Files:**
- Modify: `app/src/Notifications/Domain/Entity/Notification.php` (убрать `raise(NotificationCreatedEvent)` из конструктора)
- Delete: `app/src/Notifications/Infrastructure/EventHandler/NotificationCreatedEventHandler.php`
- Delete: `app/src/Notifications/Domain/Event/NotificationCreatedEvent.php` (если больше не используется)
- Modify: `app/config/packages/messenger.yaml` (убрать роутинг `NotificationCreatedEvent`)
- Modify: `app/src/Users/Infrastructure/EventHandler/UserActivatedEventHandler.php` — вместо `SendNotificationCommand` публиковать уведомляющее событие `UserActivatedNotification` (Owned, тип `UserActivated`), несущее ownerUlid админа + email нового юзера
- Create: `app/src/Notifications/Domain/Event/UserActivatedNotification.php` (NotifiableEvent, OwnedNotification, + `newUserEmail(): string`)
- Modify: `app/config/packages/messenger.yaml` (роутинг `UserActivatedNotification` → async)
- Modify: `app/src/Users/Infrastructure/Console/SendTestWebPush.php` (`app:push:test`) — публиковать `UserActivatedNotification`-подобное тест-событие ИЛИ оставить как создание inbox+push через диспетчер (решение: публиковать системное тест-событие Owner на указанного юзера)
- Test: `app/tests/Functional/Notifications/NotificationAutoPushDecouplingTest.php`

**Interfaces:**
- Consumes: диспетчер (Task 8), `EventBusInterface`, `NotificationType::UserActivated`.
- Produces: `UserActivatedNotification` (Owned + `newUserEmail`). Прямое создание `Notification` больше НЕ рассылает push.

- [ ] **Step 1: Write the failing test** — создать `Notification` напрямую (репозиторий) → в очередь НЕ попадает `NotificationCreatedEvent` / web-push не триггерится; доставка только через диспетчер.

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Notifications;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Domain\Service\UuidService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotificationAutoPushDecouplingTest extends KernelTestCase
{
    public function test_creating_notification_does_not_autopublish_created_event(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(NotificationRepositoryInterface::class);
        // Класс события удалён — просто проверяем, что создание не падает и не требует NotificationCreatedEvent.
        $repo->add(new Notification(UuidService::generateUuid(), 'u-x', 'текст', new \DateTimeImmutable()));
        self::assertFalse(class_exists('App\\Notifications\\Domain\\Event\\NotificationCreatedEvent'), 'событие авто-пуша удалено');
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL (класс ещё существует / Notification раздаёт событие).

- [ ] **Step 3: Write minimal implementation** — убрать `raise()` из `Notification::__construct`; удалить `NotificationCreatedEvent` + `NotificationCreatedEventHandler` + его роутинг; мигрировать `UserActivatedEventHandler` на публикацию `UserActivatedNotification` через `EventBusInterface`; добавить роутинг `UserActivatedNotification` → async; поправить `app:push:test`.

`UserActivatedNotification.php`:
```php
<?php
declare(strict_types=1);
namespace App\Notifications\Domain\Event;

use App\Notifications\Domain\Type\NotificationType;

final readonly class UserActivatedNotification implements NotifiableEvent, OwnedNotification
{
    public function __construct(private string $adminUlid, private string $newUserEmail) {}
    public function notificationType(): NotificationType { return NotificationType::UserActivated; }
    public function ownerUlid(): string { return $this->adminUlid; }
    public function newUserEmail(): string { return $this->newUserEmail; }
}
```

`UserActivatedEventHandler` (замена тела отправки):
```php
// было: $this->commandBus->execute(new SendNotificationCommand($admin->getUlid(), sprintf(...)));
$this->eventBus->execute(new UserActivatedNotification($admin->getUlid(), $user->getEmail()->getValue()));
```
(инъектировать `EventBusInterface $eventBus` вместо/вместе с `CommandBusInterface`).

`messenger.yaml` routing: убрать `App\Notifications\Domain\Event\NotificationCreatedEvent`; добавить `App\Notifications\Domain\Event\UserActivatedNotification: async`.

- [ ] **Step 4: Run test to verify it passes** — PASS. Прогнать существующие тесты активации/инбокса — убедиться, что ничего не сломалось (инбокс-строка создаётся диспетчером; web-push идёт при наличии web_push-канала).

- [ ] **Step 5: Commit** (по апруву)
```bash
git add -A app/src/Notifications app/src/Users app/config/packages/messenger.yaml \
  app/tests/Functional/Notifications/NotificationAutoPushDecouplingTest.php
git commit -m "Уведомления: расцепили авто-пуш — доставка только через диспетчер; активация юзера на новом событии"
```

---

## Task 10: Экран настроек подписок

**Files:**
- Create: `app/src/Notifications/Application/Service/SubscriptionSettingsService.php`
- Create: `app/src/Notifications/Infrastructure/Controller/Settings/ShowSettingsAction.php`
- Create: `app/src/Notifications/Infrastructure/Controller/Settings/SaveSettingsAction.php`
- Create: `app/src/Shared/Infrastructure/Templates/cabinet/notifications/settings.html.twig`
- Test: `app/tests/Functional/Notifications/Infrastructure/Controller/SettingsControllerTest.php`

**Interfaces:**
- Consumes: `NotificationType::configurable()` + `visibleToAdminOnly()`, `SubscriptionRepositoryInterface`, `AuthUserFetcherInterface` (текущий userUlid), `AccessGuard`/`is_granted`.
- Produces: `SubscriptionSettingsService::configurableTypesForUser(string $userUlid, bool $isAdmin): array<типы с текущими галочками каналов>`; `toggle(string $userUlid, NotificationType $type, NotificationChannel $channel, bool $enabled): void` (кидает `AppException` для системного типа). Экран GET/POST.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Notifications\Infrastructure\Controller;

use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\{NotificationChannel, NotificationType};
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettingsControllerTest extends WebTestCase
{
    public function test_settings_page_hides_system_and_saves_configurable(): void
    {
        $client = static::createClient();
        $c = $client->getContainer();
        $admin = new User(new Email('set_'.uniqid('', true).'@example.com'));
        $admin->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($admin, 'isActive'))->setValue($admin, true);
        (new \ReflectionProperty($admin, 'roles'))->setValue($admin, ['ROLE_ADMIN']);
        $em = $c->get(EntityManagerInterface::class); $em->persist($admin); $em->flush();
        $client->loginUser($admin);

        $html = (string) $client->request('GET', '/cabinet/notifications/settings')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('СИЗ: подходит срок', $html, 'настраиваемый тип показан');
        self::assertStringNotContainsString('Новый пользователь', $html, 'системный тип скрыт');

        $client->request('POST', '/cabinet/notifications/settings', [
            '_csrf_token' => $c->get('security.csrf.token_manager')->getToken('authenticate')->getValue(),
            'subscriptions' => ['compliance.due_soon' => ['email' => '1']],
        ]);
        self::assertResponseRedirects();
        self::assertTrue($c->get(SubscriptionRepositoryInterface::class)
            ->isEnabled($admin->getUlid(), NotificationType::ComplianceDueSoon, NotificationChannel::Email));
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL.

- [ ] **Step 3: Write minimal implementation** — сервис + 2 тонких экшена + шаблон (копировать разметку ближайшего cabinet-аналога; чекбоксы каналов на тип, системные не выводятся; POST пишет подписки, toggle системного кидает `AppException`). Маршруты: `app_notifications_settings` (GET), `app_notifications_settings_save` (POST). Доступ — авторизованные (security.yaml); видимость типов — `visibleToAdminOnly()` ∧ `is_granted('ROLE_ADMIN')`.

- [ ] **Step 4: Run test to verify it passes** — PASS. `cd app && yarn dev` если менялись ассеты (вероятно нет — только Twig).

- [ ] **Step 5: Commit** (по апруву)
```bash
git add app/src/Notifications/Application/Service/SubscriptionSettingsService.php \
  app/src/Notifications/Infrastructure/Controller/Settings \
  app/src/Shared/Infrastructure/Templates/cabinet/notifications/settings.html.twig \
  app/tests/Functional/Notifications/Infrastructure/Controller/SettingsControllerTest.php
git commit -m "Уведомления: экран настроек подписок (настраиваемые типы + каналы; системные скрыты)"
```

---

## Task 11: Пилот end-to-end — compliance.due_soon

**Files:**
- Create: `app/src/Compliance/Domain/Event/ComplianceDueSoon.php` (реализует `NotifiableEvent`, `SubjectNotification` + поля рендера)
- Create: `app/src/Compliance/Infrastructure/Console/EmitComplianceDueSoonCommand.php` (`app:compliance:emit-due-soon <profileId>` — РУЧНОЙ триггер для Фазы 1; проходчик — Фаза 2)
- Modify: `app/config/packages/messenger.yaml` (`ComplianceDueSoon` → async)
- Modify: `app/src/Notifications/Application/Service/Render/ComplianceDueSoonRenderer.php` (типизировать на контракт данных события вместо `method_exists`)
- Test: `app/tests/Functional/Notifications/ComplianceDueSoonE2eTest.php`

**Interfaces:**
- Consumes: диспетчер + резолверы + подписки (Tasks 5-9).
- Produces: `ComplianceDueSoon implements NotifiableEvent, SubjectNotification { notificationType() = ComplianceDueSoon; subjectProfileId(); employeeFio(); obligationLabel(); dueDate() }`. Контракт данных для рендера.

- [ ] **Step 1: Write the failing test** — админ подписан на `compliance.due_soon` через inbox → при событии про профиль создаётся inbox-запись админу; обычный юзер (не админ, не субъект) — ничего.

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Notifications;

use App\Compliance\Domain\Event\ComplianceDueSoon;
use App\Notifications\Application\EventHandler\NotificationDispatcher;
use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Repository\{NotificationFilter, NotificationRepositoryInterface, SubscriptionRepositoryInterface};
use App\Notifications\Domain\Type\{NotificationChannel, NotificationType};
use App\Shared\Domain\Repository\Pager;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ComplianceDueSoonE2eTest extends KernelTestCase
{
    use EnrollsComplianceTrait;

    public function test_subscribed_admin_gets_inbox_due_soon(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $admin = new User(new Email('e2e_'.uniqid('', true).'@example.com'));
        $admin->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($admin, 'isActive'))->setValue($admin, true);
        (new \ReflectionProperty($admin, 'roles'))->setValue($admin, ['ROLE_ADMIN']);
        $em->persist($admin); $em->flush();

        $c->get(SubscriptionRepositoryInterface::class)->save(
            new Subscription(Uuid::v7(), $admin->getUlid(), NotificationType::ComplianceDueSoon, NotificationChannel::Inbox, true));

        ['profileId' => $profileId] = $this->enrollCompliance();
        $event = new ComplianceDueSoon($profileId, 'Иванов И.И.', 'Перчатки', '05.12.2026');
        $c->get(NotificationDispatcher::class)->__invoke($event); // в тестах зовём диспетчер напрямую

        $rows = $c->get(NotificationRepositoryInterface::class)
            ->findByFilter(new NotificationFilter($admin->getUlid(), null, Pager::fromPage(1, 10)))->items;
        self::assertCount(1, $rows);
        self::assertStringContainsString('Перчатки', $rows[0]->getMessage());
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — FAIL.

- [ ] **Step 3: Write minimal implementation**

`ComplianceDueSoon.php`:
```php
<?php
declare(strict_types=1);
namespace App\Compliance\Domain\Event;

use App\Notifications\Domain\Event\{NotifiableEvent, SubjectNotification};
use App\Notifications\Domain\Type\NotificationType;

final readonly class ComplianceDueSoon implements NotifiableEvent, SubjectNotification
{
    public function __construct(
        private string $profileId,
        private string $employeeFio,
        private string $obligationLabel,
        private string $dueDate,
    ) {}

    public function notificationType(): NotificationType { return NotificationType::ComplianceDueSoon; }
    public function subjectProfileId(): string { return $this->profileId; }
    public function employeeFio(): string { return $this->employeeFio; }
    public function obligationLabel(): string { return $this->obligationLabel; }
    public function dueDate(): string { return $this->dueDate; }
}
```

`EmitComplianceDueSoonCommand.php` — `#[AsCommand('app:compliance:emit-due-soon')]`, принимает `profileId`, резолвит ФИО/обязанность (или принимает аргументами для MVP), публикует `ComplianceDueSoon` через `EventBusInterface`. (Ручной триггер — проверить end-to-end через воркер в деве; проходчик в Фазе 2.)

Роутинг `messenger.yaml`: `App\Compliance\Domain\Event\ComplianceDueSoon: async`.

Рендерер — заменить `method_exists` на проверку наличия нужных методов через контракт: объявить в Notifications `interface DueSoonData { employeeFio(): string; obligationLabel(): string; dueDate(): string; }`, реализовать его на `ComplianceDueSoon`, в рендерере — `instanceof DueSoonData`.

- [ ] **Step 4: Run test to verify it passes** — PASS. Затем `./run check` целиком — зелёный.

- [ ] **Step 5: Commit** (по апруву)
```bash
git add app/src/Compliance/Domain/Event/ComplianceDueSoon.php \
  app/src/Compliance/Infrastructure/Console/EmitComplianceDueSoonCommand.php \
  app/src/Notifications/Application/Service/Render/ComplianceDueSoonRenderer.php \
  app/config/packages/messenger.yaml app/tests/Functional/Notifications/ComplianceDueSoonE2eTest.php
git commit -m "Уведомления: пилот compliance.due_soon end-to-end (событие + ручной триггер + рендер), гейт зелёный"
```

---

## Финал

- Прогнать `./run check` — всё зелёное (style/phpstan L6/unit/functional).
- Деплой-заметка: прогнать миграцию `notification_subscription` на проде; перезапустить `manager_supervisor` (новые хендлеры/роутинг). Проверить, что активация юзера по-прежнему шлёт уведомление админу (через новый путь).
- Фаза 2 (отдельный план): проходчик — lead-time на норме, scheduler/cron, daily-скан `nextDueAt` → `ComplianceDueSoon`/`ComplianceOverdue`, антифлуд.

## Self-review заметки (покрытие спеки)

- Каталог/kind/category/channels/resolver — Task 1. Контракты событий — Task 2. Подписки+таблица — Task 3. Кросс-контекст порты — Task 4. Резолверы 3 стратегии — Task 5. Семантика системные/настраиваемые (выбор каналов) — Task 6. Рендер — Task 7. Диспетчер фан-аут — Task 8. Расцепление авто-пуша + миграция легаси — Task 9. Экран настроек (системные скрыты, toggle-блок) — Task 10. Пилот e2e + системный тип (UserActivated в Task 9) — Task 11.
- Review Focus: адресность (Task 5/8/11), системные принудительны (Task 6/10), настраиваемые opt-in (Task 6/8), расцепление авто-пуша (Task 9), нет адреса канала (Task 8 deliver — тихий пропуск).
- Телеграм не трогаем: `NotificationChannel` без telegram; `NotifierFactory` телеграм не вызываем.
