<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Security;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end проверка ChannelVerificationGate в реальном Symfony стеке.
 * Используем существующий маршрут /cabinet/coating/coating/list как защищённый
 * (он требует IS_AUTHENTICATED, и subscriber делает остальное).
 */
final class ChannelVerificationGateTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $this->userEmail = 'gate_test_'.uniqid('', true).'@example.com';
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if (null !== $user) {
                $em->remove($user);
                $em->flush();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_anonymous_is_redirected_to_login_by_firewall(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/login', (string) $location, 'Anonymous должен попасть на /login, а не на verification');
    }

    public function test_inactive_user_is_redirected_to_verification(): void
    {
        $user = $this->createUser(active: false);
        $this->client->loginUser($user);

        $this->client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseRedirects('/user/channel/verification');
    }

    public function test_gate_does_not_redirect_away_from_verification_page(): void
    {
        // Цель теста — проверить именно ChannelVerificationGate (whitelist), а не
        // ChannelVerificationAction. Гейт ОБЯЗАН НЕ редиректить inactive юзера на
        // /user/channel/verification — иначе бесконечный цикл. Что отрисует контроллер
        // (форма / 500 на duplicate-channel) — тут не важно, у него свои тесты.
        $user = $this->createUser(active: false);
        $this->client->loginUser($user);

        $this->client->request('GET', '/user/channel/verification');

        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotSame(
            '/user/channel/verification',
            $location,
            'Gate must not redirect inactive user TO the verification page when already on it.'
        );
    }

    public function test_active_user_can_access_protected_route(): void
    {
        $user = $this->createUser(active: true);
        $this->client->loginUser($user);

        $this->client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseIsSuccessful();
    }

    private function createUser(bool $active): User
    {
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        if ($active) {
            $ref = new \ReflectionProperty($user, 'isActive');
            $ref->setAccessible(true);
            $ref->setValue($user, true);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
