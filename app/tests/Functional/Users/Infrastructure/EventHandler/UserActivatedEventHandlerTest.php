<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\EventHandler;

use App\Notifications\Domain\Repository\NotificationFilter;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Event\UserActivatedEvent;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Infrastructure\EventHandler\UserActivatedEventHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * По активации пользователя админу (ADMIN_NOTIFY_EMAIL) создаётся уведомление с email нового юзера.
 * Хендлер конструируем вручную с тестовым admin-email, чтобы не завязываться на значение из окружения.
 * Доставка (async NotificationCreatedEvent) в тесте не потребляется — проверяем персист уведомления.
 */
final class UserActivatedEventHandlerTest extends KernelTestCase
{
    private UserRepositoryInterface $users;
    private NotificationRepositoryInterface $notifications;
    private CommandBusInterface $commandBus;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->users = $container->get(UserRepositoryInterface::class);
        $this->notifications = $container->get(NotificationRepositoryInterface::class);
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->em = $container->get(EntityManagerInterface::class);
    }

    // Чистку не пишем: DAMADoctrineTestBundle оборачивает каждый тест в транзакцию и откатывает.

    public function test_activation_notifies_admin_with_new_user_email(): void
    {
        $adminEmail = 'admin_'.bin2hex(random_bytes(4)).'@example.com';
        $admin = $this->persistUser($adminEmail);
        $newbieEmail = 'newbie_'.bin2hex(random_bytes(4)).'@example.com';
        $newbie = $this->persistActiveUser($newbieEmail);

        ($this->handler($adminEmail))(new UserActivatedEvent($newbie->getUlid()));

        self::assertSame(1, $this->notifications->countUnread($admin->getUlid()));
        $items = $this->notifications->findByFilter(new NotificationFilter($admin->getUlid()))->items;
        self::assertStringContainsString($newbieEmail, $items[0]->getMessage());
    }

    public function test_admin_activation_does_not_self_notify(): void
    {
        $adminEmail = 'admin_'.bin2hex(random_bytes(4)).'@example.com';
        $admin = $this->persistActiveUser($adminEmail);

        // Активировался сам админ — уведомлять некого.
        ($this->handler($adminEmail))(new UserActivatedEvent($admin->getUlid()));

        self::assertSame(0, $this->notifications->countUnread($admin->getUlid()));
    }

    public function test_throws_when_activation_not_yet_visible(): void
    {
        // Событие опубликовано до коммита активации: строка есть, но is_active ещё false → ретрай.
        $newbie = $this->persistUser('inactive_'.bin2hex(random_bytes(4)).'@example.com');

        $this->expectException(\RuntimeException::class);
        ($this->handler('whoever@example.com'))(new UserActivatedEvent($newbie->getUlid()));
    }

    public function test_no_notification_when_admin_not_found(): void
    {
        $admin = $this->persistUser('admin_'.bin2hex(random_bytes(4)).'@example.com');
        $newbie = $this->persistActiveUser('newbie_'.bin2hex(random_bytes(4)).'@example.com');

        // Хендлер настроен на несуществующий admin-email → тихий выход, без уведомления и исключения.
        ($this->handler('nobody_'.bin2hex(random_bytes(4)).'@example.com'))(new UserActivatedEvent($newbie->getUlid()));

        self::assertSame(0, $this->notifications->countUnread($admin->getUlid()));
    }

    private function handler(string $adminEmail): UserActivatedEventHandler
    {
        return new UserActivatedEventHandler($this->users, $this->commandBus, $adminEmail);
    }

    private function persistUser(string $email): User
    {
        $user = new User(new Email($email));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function persistActiveUser(string $email): User
    {
        $user = new User(new Email($email));
        // WEB_PUSH рождается подтверждённым → пользователь становится активным.
        $user->addChannel(new Channel(Uuid::v7(), ChannelType::WEB_PUSH, '{"endpoint":"x"}', $user));
        $user->makeActiveInternally();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
