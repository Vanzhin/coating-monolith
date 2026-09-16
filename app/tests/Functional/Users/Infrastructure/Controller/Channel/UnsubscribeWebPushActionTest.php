<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller\Channel;

use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Entity\ValueObject\PushSubscription;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Отписка от web push удаляет WEB_PUSH-канал ТОЛЬКО этого устройства (по endpoint из тела).
 * Подписки других устройств выживают. Идемпотентна; аноним не пускается. Чистку не пишем —
 * DAMADoctrineTestBundle откатывает транзакцию теста.
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

    public function test_unsubscribe_removes_only_this_device_by_endpoint(): void
    {
        $desktop = 'https://web.push.apple.com/desktop';
        $phone = 'https://web.push.apple.com/phone';
        $user = $this->persistActiveUserWithEndpoints([$desktop, $phone]);
        $this->client->loginUser($user);

        $this->client->request('POST', '/cabinet/push/unsubscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['endpoint' => $desktop]));

        self::assertResponseIsSuccessful();
        $left = $this->em->getRepository(Channel::class)->findBy(['owner' => $user, 'type' => ChannelType::WEB_PUSH]);
        self::assertCount(1, $left, 'снесён только endpoint этого устройства, канал другого выжил');
        self::assertSame($phone, PushSubscription::fromJson($left[0]->getValue())->endpoint);
    }

    public function test_unsubscribe_unknown_endpoint_is_noop(): void
    {
        $user = $this->persistActiveUserWithEndpoints(['https://web.push.apple.com/desktop']);
        $this->client->loginUser($user);

        $this->client->request('POST', '/cabinet/push/unsubscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['endpoint' => 'https://web.push.apple.com/unknown']));

        self::assertResponseIsSuccessful();
        $left = $this->em->getRepository(Channel::class)->findBy(['owner' => $user, 'type' => ChannelType::WEB_PUSH]);
        self::assertCount(1, $left, 'endpoint не совпал — ничего не удаляем');
    }

    public function test_unsubscribe_is_noop_without_channels(): void
    {
        $user = $this->persistActiveUserWithEndpoints([]);
        $this->client->loginUser($user);

        $this->client->request('POST', '/cabinet/push/unsubscribe', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['endpoint' => 'https://web.push.apple.com/desktop']));

        self::assertResponseIsSuccessful();
    }

    public function test_requires_authentication(): void
    {
        $this->client->request('POST', '/cabinet/push/unsubscribe', [], [], ['CONTENT_TYPE' => 'application/json']);

        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401]);
    }

    /** @param list<string> $endpoints */
    private function persistActiveUserWithEndpoints(array $endpoints): User
    {
        $user = new User(new Email('unsub_'.bin2hex(random_bytes(4)).'@example.com'));
        $user->setPassword('pass', $this->client->getContainer()->get(UserPasswordHasherInterface::class));
        // Активен: иначе гейт верификации увёл бы с /cabinet-маршрута (как в SubscribeWebPushActionTest).
        (new \ReflectionProperty(User::class, 'isActive'))->setValue($user, true);

        foreach ($endpoints as $i => $endpoint) {
            $value = json_encode([
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => 'p'.$i, 'auth' => 'a'.$i],
            ]);
            $user->addChannel(new Channel(Uuid::v7(), ChannelType::WEB_PUSH, $value, $user));
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
