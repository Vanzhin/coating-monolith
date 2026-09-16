<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller\Channel;

use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Отписка от web push удаляет ВСЕ WEB_PUSH-каналы юзера (на всех устройствах). Идемпотентна;
 * аноним не пускается. Чистку не пишем — DAMADoctrineTestBundle откатывает транзакцию теста.
 */
final class UnsubscribeWebPushActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    public function test_unsubscribe_removes_all_web_push_channels(): void
    {
        // Два WEB_PUSH-канала с разными endpoint (имитация накопившихся подписок одного юзера).
        $user = $this->persistActiveUser(2);
        $this->client->loginUser($user);

        $this->client->request('POST', '/cabinet/push/unsubscribe', [], [], ['CONTENT_TYPE' => 'application/json']);

        self::assertResponseIsSuccessful();
        $left = $this->em->getRepository(Channel::class)->findBy(['owner' => $user, 'type' => ChannelType::WEB_PUSH]);
        self::assertCount(0, $left, 'все WEB_PUSH-каналы должны быть удалены');
    }

    public function test_unsubscribe_is_noop_without_channels(): void
    {
        $user = $this->persistActiveUser(0);
        $this->client->loginUser($user);

        $this->client->request('POST', '/cabinet/push/unsubscribe', [], [], ['CONTENT_TYPE' => 'application/json']);

        self::assertResponseIsSuccessful();
    }

    public function test_requires_authentication(): void
    {
        $this->client->request('POST', '/cabinet/push/unsubscribe', [], [], ['CONTENT_TYPE' => 'application/json']);

        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401]);
    }

    private function persistActiveUser(int $webPushChannels): User
    {
        $user = new User(new Email('unsub_'.bin2hex(random_bytes(4)).'@example.com'));
        $user->setPassword('pass', $this->client->getContainer()->get(UserPasswordHasherInterface::class));
        // Активен: иначе гейт верификации увёл бы с /cabinet-маршрута (как в SubscribeWebPushActionTest).
        (new \ReflectionProperty(User::class, 'isActive'))->setValue($user, true);

        for ($i = 0; $i < $webPushChannels; ++$i) {
            $value = json_encode([
                'endpoint' => 'https://web.push.apple.com/dev-'.$i.'-'.bin2hex(random_bytes(4)),
                'keys' => ['p256dh' => 'p'.$i, 'auth' => 'a'.$i],
            ]);
            $user->addChannel(new Channel(Uuid::v7(), ChannelType::WEB_PUSH, $value, $user));
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
