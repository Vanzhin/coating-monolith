<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\EventListener\Request;

use App\Tests\Support\CsrfTestHelper;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Глобальный CSRF-гейт: небезопасный метод на cookie-firewall без валидного mutation-токена
 * должен отбиваться 403. С токеном — проходит (доходит до контроллера). GET — свободно.
 */
final class CsrfRequestSubscriberTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);

        $this->userEmail = 'csrf_'.uniqid('', true).'@example.com';
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setValue($user, true);
        $roles = new \ReflectionProperty($user, 'roles');
        $roles->setValue($user, ['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
        if (null !== $user) {
            $em->remove($user);
            $em->flush();
        }
        parent::tearDown();
    }

    public function test_mutating_post_without_token_is_forbidden(): void
    {
        // Токен НЕ проставляем.
        $this->client->request('POST', '/cabinet/certificate/issuer/create', ['title' => 'Без токена']);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_mutating_post_with_token_passes_csrf_gate(): void
    {
        CsrfTestHelper::enable($this->client);

        $this->client->request('POST', '/cabinet/certificate/issuer/create', ['title' => 'CSRF-OK-'.uniqid()]);

        // Гейт пройден: контроллер отработал и редиректит (не 403).
        self::assertResponseRedirects('/cabinet/certificate/issuer');
    }

    public function test_safe_get_needs_no_token(): void
    {
        $this->client->request('GET', '/cabinet/certificate/issuer');

        self::assertResponseIsSuccessful();
    }
}
