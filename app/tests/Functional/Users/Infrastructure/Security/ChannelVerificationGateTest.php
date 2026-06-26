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
        $this->userEmail = 'gate_test_' . uniqid('', true) . '@example.com';
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if ($user !== null) {
                $em->remove($user);
                $em->flush();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "tearDown cleanup error: " . $e->getMessage() . "\n");
        }
        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLoginByFirewall(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/login', (string) $location, 'Anonymous должен попасть на /login, а не на verification');
    }

    public function testInactiveUserIsRedirectedToVerification(): void
    {
        $user = $this->createUser(active: false);
        $this->client->loginUser($user);

        $this->client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseRedirects('/user/channel/verification');
    }

    public function testInactiveUserCanAccessVerificationPage(): void
    {
        $user = $this->createUser(active: false);
        $this->client->loginUser($user);

        $this->client->request('GET', '/user/channel/verification');

        self::assertResponseIsSuccessful();
    }

    public function testActiveUserCanAccessProtectedRoute(): void
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
