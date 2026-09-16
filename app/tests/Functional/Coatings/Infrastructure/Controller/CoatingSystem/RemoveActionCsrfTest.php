<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * CSRF/methods-хардненинг удаления системы покрытий (Волна 2): роут только POST, POST без валидного
 * CSRF-токена отбивается. Закрывает state-changing GET (удаление по ссылке) и POST без токена.
 */
final class RemoveActionCsrfTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->loginUser($this->persistActiveUser());
    }

    public function test_get_is_method_not_allowed(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/'.Uuid::v4()->toRfc4122().'/remove');

        self::assertResponseStatusCodeSame(405);
    }

    public function test_post_without_csrf_token_is_forbidden(): void
    {
        $this->client->request('POST', '/cabinet/coating/coating-system/'.Uuid::v4()->toRfc4122().'/remove');

        self::assertResponseStatusCodeSame(403);
    }

    private function persistActiveUser(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(new Email('csrf_'.bin2hex(random_bytes(4)).'@example.com'));
        $user->setPassword('pass', static::getContainer()->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty(User::class, 'isActive'))->setValue($user, true);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
