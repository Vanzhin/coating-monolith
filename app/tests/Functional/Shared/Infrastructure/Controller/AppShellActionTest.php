<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Стартовый экран PWA `/app`: публичный, НИКОГДА не редиректит на сервере (иначе SW не закэширует и
 * PWA не откроется офлайн). Форвард залогиненного в кабинет — клиентский, поэтому сервер и для
 * залогиненного отдаёт 200 с флагом authenticated=true.
 */
final class AppShellActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    public function test_anonymous_gets_public_hub_without_redirect(): void
    {
        $this->client->request('GET', '/app');

        self::assertResponseIsSuccessful(); // 200, не редирект
        self::assertSelectorExists('[data-app-shell-authenticated-value="false"]');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/tools/mix', $html); // плитки инструментов
        self::assertStringContainsString('/tools/consumption', $html);
        self::assertStringContainsString('/tools/film', $html);
        self::assertStringContainsString('Войти', $html); // аноним видит вход, не «Кабинет»
    }

    public function test_authenticated_still_gets_200_with_flag_no_server_redirect(): void
    {
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User(new Email('app_shell_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $hasher);
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/app');

        // Сервер НЕ редиректит — 200 с флагом; форвард в кабинет делает клиентский JS.
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-app-shell-authenticated-value="true"]');
        self::assertStringContainsString('/cabinet', (string) $this->client->getResponse()->getContent());
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
