<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Application\EventHandler;

use App\Notifications\Application\EventHandler\NotificationDispatcher;
use App\Notifications\Domain\Event\ComplianceDueSoonData;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\OwnedNotification;
use App\Notifications\Domain\Event\SubjectNotification;
use App\Notifications\Domain\Event\UserActivatedData;
use App\Notifications\Domain\Repository\NotificationFilter;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Domain\Repository\Pager;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class NotificationDispatcherTest extends KernelTestCase
{
    public function test_system_owner_event_creates_inbox_row(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $notifs = $c->get(NotificationRepositoryInterface::class);
        $dispatcher = $c->get(NotificationDispatcher::class);
        // inbox-запись ссылается на пользователя (FK notification.owner_id) — адресат должен существовать.
        $em = $c->get(EntityManagerInterface::class);
        $user = new User(new Email('disp_'.uniqid('', true).'@example.com'));
        $user->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        $em->persist($user);
        $em->flush();
        $ulid = $user->getUlid();

        // Системный тип UserActivated (Owner) → inbox всегда (системные игнорируют подписки).
        $e = new class($ulid) implements NotifiableEvent, OwnedNotification, UserActivatedData {
            public function __construct(private string $u)
            {
            }

            public function notificationType(): NotificationType
            {
                return NotificationType::UserActivated;
            }

            public function ownerUlid(): string
            {
                return $this->u;
            }

            public function newUserEmail(): string
            {
                return 'n@x.io';
            }
        };
        $dispatcher->__invoke($e);

        $rows = $notifs->findByFilter(new NotificationFilter($ulid, null, Pager::fromPage(1, 10)))->items;
        self::assertCount(1, $rows, 'системный тип всегда создаёт inbox-запись');
    }

    public function test_inbox_skipped_for_non_existent_user(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $notifs = $c->get(NotificationRepositoryInterface::class);
        $dispatcher = $c->get(NotificationDispatcher::class);
        // Резолвер отдал ulid, которому не соответствует живой пользователь (например осиротевший
        // profile.user_ulid или начальник отдела). Системный тип дал бы inbox-вставку → FK-падение в воркере.
        $ulid = (string) \Symfony\Component\Uid\Ulid::generate();
        $e = new class($ulid) implements NotifiableEvent, OwnedNotification, UserActivatedData {
            public function __construct(private string $u)
            {
            }

            public function notificationType(): NotificationType
            {
                return NotificationType::UserActivated;
            }

            public function ownerUlid(): string
            {
                return $this->u;
            }

            public function newUserEmail(): string
            {
                return 'ghost@x.io';
            }
        };

        $dispatcher->__invoke($e); // не должно бросить FK-исключение

        $rows = $notifs->findByFilter(new NotificationFilter($ulid, null, Pager::fromPage(1, 10)))->items;
        self::assertCount(0, $rows, 'inbox-запись не создаётся для несуществующего пользователя');
    }

    public function test_configurable_without_subscription_delivers_nothing(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $notifs = $c->get(NotificationRepositoryInterface::class);
        $dispatcher = $c->get(NotificationDispatcher::class);
        $ulid = 'u-'.uniqid('', true);

        // ComplianceDueSoon (настраиваемый, Subject-резолвер), профиль отсутствует → адресат $ulid не подписан.
        $e = new class((string) Uuid::v7()) implements NotifiableEvent, SubjectNotification, ComplianceDueSoonData {
            public function __construct(private string $p)
            {
            }

            public function notificationType(): NotificationType
            {
                return NotificationType::ComplianceDueSoon;
            }

            public function subjectProfileId(): string
            {
                return $this->p;
            }

            public function employeeFio(): string
            {
                return 'Иванов И.И.';
            }

            /** @return list<\App\Notifications\Domain\Event\ComplianceDueItem> */
            public function items(): array
            {
                return [new \App\Notifications\Domain\Event\ComplianceDueItem('Перчатки', \App\Notifications\Domain\Event\DueKind::Soon, '05.12.2026')];
            }
        };
        $dispatcher->__invoke($e);

        $rows = $notifs->findByFilter(new NotificationFilter($ulid, null, Pager::fromPage(1, 10)))->items;
        self::assertCount(0, $rows);
    }
}
