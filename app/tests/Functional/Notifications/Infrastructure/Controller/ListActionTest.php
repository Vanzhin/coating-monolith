<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Infrastructure\Controller;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Страница /cabinet/notifications: список (новые выше), заход помечает прочитанным, аноним не пускается.
 */
final class ListActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->email = 'notif_list_'.bin2hex(random_bytes(4)).'@example.com';
        $this->user = new User(new Email($this->email));
        $this->user->setPassword('pass', $container->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty(User::class, 'isActive'))->setValue($this->user, true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->email]);
            if (null !== $user) {
                // notification держит FK на user_user.ulid — чистим до пользователя.
                $em->getConnection()->executeStatement('DELETE FROM notification WHERE owner_id = :o', ['o' => $user->getUlid()]);
                $em->remove($user);
                $em->flush();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_list_shows_notifications_newest_first_and_marks_read(): void
    {
        /** @var NotificationRepositoryInterface $repo */
        $repo = static::getContainer()->get(NotificationRepositoryInterface::class);
        $repo->add(new Notification(Uuid::v7(), $this->user->getUlid(), 'Старое уведомление', new \DateTimeImmutable('-2 hours')));
        $repo->add(new Notification(Uuid::v7(), $this->user->getUlid(), 'Новое уведомление', new \DateTimeImmutable('-5 minutes')));

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/cabinet/notifications');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Новое уведомление', $html);
        self::assertStringContainsString('Старое уведомление', $html);
        self::assertLessThan(
            strpos($html, 'Старое уведомление'),
            strpos($html, 'Новое уведомление'),
            'Новое уведомление должно быть выше старого (сортировка DESC).',
        );

        // Заход в раздел = «увидел»: непрочитанных не осталось.
        self::assertSame(0, $repo->countUnread($this->user->getUlid()));
    }

    public function test_requires_authentication(): void
    {
        $this->client->request('GET', '/cabinet/notifications');

        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401]);
    }
}
