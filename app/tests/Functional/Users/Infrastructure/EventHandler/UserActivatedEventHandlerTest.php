<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\EventHandler;

use App\Notifications\Domain\Event\UserActivatedNotification;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Domain\Event\EventInterface;
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
 * По активации пользователя админу (ADMIN_NOTIFY_EMAIL) публикуется уведомляющее событие
 * UserActivatedNotification (Owner=админ, email нового юзера). Доставку делает NotificationDispatcher —
 * здесь ловим публикацию через подменённую шину событий (handler конструируем вручную).
 */
final class UserActivatedEventHandlerTest extends KernelTestCase
{
    private UserRepositoryInterface $users;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->users = $container->get(UserRepositoryInterface::class);
        $this->em = $container->get(EntityManagerInterface::class);
    }

    // Чистку не пишем: DAMADoctrineTestBundle оборачивает каждый тест в транзакцию и откатывает.

    public function test_activation_publishes_notification_with_new_user_email(): void
    {
        $adminEmail = 'admin_'.bin2hex(random_bytes(4)).'@example.com';
        $admin = $this->persistUser($adminEmail);
        $newbieEmail = 'newbie_'.bin2hex(random_bytes(4)).'@example.com';
        $newbie = $this->persistActiveUser($newbieEmail);

        $bus = $this->capturingBus();
        (new UserActivatedEventHandler($this->users, $bus, $adminEmail))(new UserActivatedEvent($newbie->getUlid()));

        self::assertCount(1, $bus->published);
        $event = $bus->published[0];
        self::assertInstanceOf(UserActivatedNotification::class, $event);
        self::assertSame($admin->getUlid(), $event->ownerUlid());
        self::assertSame($newbieEmail, $event->newUserEmail());
    }

    public function test_admin_activation_does_not_self_notify(): void
    {
        $adminEmail = 'admin_'.bin2hex(random_bytes(4)).'@example.com';
        $admin = $this->persistActiveUser($adminEmail);

        $bus = $this->capturingBus();
        (new UserActivatedEventHandler($this->users, $bus, $adminEmail))(new UserActivatedEvent($admin->getUlid()));

        self::assertSame([], $bus->published);
    }

    public function test_throws_when_activation_not_yet_visible(): void
    {
        // Событие опубликовано до коммита активации: строка есть, но is_active ещё false → ретрай.
        $newbie = $this->persistUser('inactive_'.bin2hex(random_bytes(4)).'@example.com');

        $this->expectException(\RuntimeException::class);
        (new UserActivatedEventHandler($this->users, $this->capturingBus(), 'whoever@example.com'))(new UserActivatedEvent($newbie->getUlid()));
    }

    public function test_no_notification_when_admin_not_found(): void
    {
        $newbie = $this->persistActiveUser('newbie_'.bin2hex(random_bytes(4)).'@example.com');

        // Хендлер настроен на несуществующий admin-email → тихий выход, без публикации и исключения.
        $bus = $this->capturingBus();
        (new UserActivatedEventHandler($this->users, $bus, 'nobody_'.bin2hex(random_bytes(4)).'@example.com'))(new UserActivatedEvent($newbie->getUlid()));

        self::assertSame([], $bus->published);
    }

    /** @return EventBusInterface&object{published: list<EventInterface>} */
    private function capturingBus(): EventBusInterface
    {
        return new class implements EventBusInterface {
            /** @var list<EventInterface> */
            public array $published = [];

            public function execute(EventInterface ...$event): void
            {
                foreach ($event as $e) {
                    $this->published[] = $e;
                }
            }
        };
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
