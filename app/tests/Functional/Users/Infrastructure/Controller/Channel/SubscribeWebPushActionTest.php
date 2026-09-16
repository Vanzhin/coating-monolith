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
 * Подписка на web push: авторизованный POST создаёт verified WEB_PUSH-канал; аноним не пускается.
 */
final class SubscribeWebPushActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $email;
    private User $user;

    private const SUBSCRIPTION = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-123',
        'keys' => ['p256dh' => 'BEl-testpublickey', 'auth' => 'authtesttoken'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->email = 'test_webpush_'.bin2hex(random_bytes(4)).'@example.com';
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User(new Email($this->email));
        $this->user->setPassword('pass', $hasher);
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
                foreach ($em->getRepository(Channel::class)->findBy(['owner' => $user]) as $channel) {
                    $em->remove($channel);
                }
                $em->remove($user);
                $em->flush();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_authenticated_subscribe_creates_verified_web_push_channel(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/cabinet/push/subscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(self::SUBSCRIPTION));

        self::assertResponseIsSuccessful();
        // ResponseListener оборачивает JSON в {result, status, data, message}.
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue(($body['data']['ok'] ?? $body['ok'] ?? null) === true);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->email]);
        $channels = $em->getRepository(Channel::class)->findBy(['owner' => $user, 'type' => ChannelType::WEB_PUSH]);
        self::assertCount(1, $channels);
        self::assertTrue($channels[0]->isVerified());
    }

    public function test_subscribe_keeps_channels_of_other_devices(): void
    {
        // Два канала других устройств (другие endpoint) — подписка нового устройства их НЕ трогает,
        // пуш должен идти на все устройства сразу.
        foreach (['https://web.push.apple.com/desktop', 'https://web.push.apple.com/phone'] as $ep) {
            $this->em->persist(new Channel(
                Uuid::v7(),
                ChannelType::WEB_PUSH,
                json_encode(['endpoint' => $ep, 'keys' => ['p256dh' => 'p', 'auth' => 'a']]),
                $this->user,
            ));
        }
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/cabinet/push/subscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(self::SUBSCRIPTION));

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->email]);
        $channels = $em->getRepository(Channel::class)->findBy(['owner' => $user, 'type' => ChannelType::WEB_PUSH]);
        self::assertCount(3, $channels, 'подписки других устройств выжили, добавилась ещё одна');
    }

    public function test_subscribe_same_endpoint_does_not_duplicate(): void
    {
        // Тот же endpoint, что и в подписке — переподписка того же устройства дубль не плодит.
        $this->em->persist(new Channel(
            Uuid::v7(),
            ChannelType::WEB_PUSH,
            json_encode(['endpoint' => self::SUBSCRIPTION['endpoint'], 'keys' => ['p256dh' => 'p', 'auth' => 'a']]),
            $this->user,
        ));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/cabinet/push/subscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(self::SUBSCRIPTION));

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->email]);
        $channels = $em->getRepository(Channel::class)->findBy(['owner' => $user, 'type' => ChannelType::WEB_PUSH]);
        self::assertCount(1, $channels, 'тот же endpoint — дубль не создаётся');
    }

    public function test_invalid_subscription_is_rejected(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/cabinet/push/subscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['endpoint' => 'x']));

        self::assertResponseStatusCodeSame(422);
    }

    public function test_requires_authentication(): void
    {
        $this->client->request('POST', '/cabinet/push/subscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(self::SUBSCRIPTION));

        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401]);
    }
}
