<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Application\UseCase\Command;

use App\Notifications\Application\UseCase\Command\MarkNotificationsRead\MarkNotificationsReadCommand;
use App\Notifications\Application\UseCase\Command\SendNotification\SendNotificationCommand;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SendNotification создаёт непрочитанное уведомление и растит счётчик; MarkNotificationsRead сбрасывает.
 * Доставка (async-событие) в тесте не потребляется — проверяем только персист/счётчик.
 */
final class NotificationCommandsTest extends KernelTestCase
{
    private CommandBusInterface $commandBus;
    private NotificationRepositoryInterface $notifications;
    private EntityManagerInterface $em;
    private string $ownerUlid;
    private string $email;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->notifications = $container->get(NotificationRepositoryInterface::class);
        $this->em = $container->get(EntityManagerInterface::class);

        $this->email = 'notif_'.bin2hex(random_bytes(4)).'@example.com';
        $user = new User(new Email($this->email));
        $user->setPassword('pass', $container->get(UserPasswordHasherInterface::class));
        $this->em->persist($user);
        $this->em->flush();
        $this->ownerUlid = $user->getUlid();
    }

    // Чистку не пишем: DAMADoctrineTestBundle оборачивает каждый тест в транзакцию и откатывает.

    public function test_send_notification_creates_unread_and_increments_count(): void
    {
        self::assertSame(0, $this->notifications->countUnread($this->ownerUlid));

        $this->commandBus->execute(new SendNotificationCommand($this->ownerUlid, 'Первое'));
        self::assertSame(1, $this->notifications->countUnread($this->ownerUlid));

        $this->commandBus->execute(new SendNotificationCommand($this->ownerUlid, 'Второе'));
        self::assertSame(2, $this->notifications->countUnread($this->ownerUlid));
    }

    public function test_mark_all_read_resets_unread_count(): void
    {
        $this->commandBus->execute(new SendNotificationCommand($this->ownerUlid, 'x'));
        self::assertSame(1, $this->notifications->countUnread($this->ownerUlid));

        $this->commandBus->execute(new MarkNotificationsReadCommand($this->ownerUlid));

        self::assertSame(0, $this->notifications->countUnread($this->ownerUlid));
    }
}
